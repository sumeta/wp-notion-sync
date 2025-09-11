<?php
/**
 * Plugin Name: Notion Sync
* Description: Sync Notion Database → WordPress (Free). Supports blocks: heading_1/2/3, paragraph, bulleted_list_item, numbered_list_item, quote, code, to_do, toggle, divider, image. Images are stored in Media Library and content updates automatically.
 * Version:     0.1.0
 * Author:      Sumeta.P
 */

// ====== CONFIG ======
const NOTION_API_TOKEN    = 'secret_xxx_put_yours_here';
const NOTION_DATABASE_ID  = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; // 32-character ID from the URL
const NOTION_API_BASE     = 'https://api.notion.com/v1';
const NOTION_API_VERSION  = '2022-06-28';

// Property names in the Notion Database
const PROP_TITLE          = 'Name';          // type: title
const PROP_STATUS         = 'Status';        // type: status (filter by Ready public)
const STATUS_EQUALS       = 'Ready public';  // Only sync records with this value

// WordPress target
const TARGET_POST_TYPE    = 'post';
const DEFAULT_POST_STATUS = 'draft';

// Debug toggle
if (!defined('NOTION_SYNC_DEBUG')) define('NOTION_SYNC_DEBUG', true);
function ns_log($x){ if (NOTION_SYNC_DEBUG) error_log('[NOTION-SYNC] '.(is_string($x)?$x:wp_json_encode($x))); }

// ====== ACTIVATE / DEACTIVATE ======
// - Set new cron named notion_sync_hourly
// - Clear old cron from lean plugin (notion_lean_sync_hourly) if any
register_activation_hook(__FILE__, function () {
    // kill old cron from "notion-lean-sync"
    wp_clear_scheduled_hook('notion_lean_sync_hourly');
    if (!wp_next_scheduled('notion_sync_hourly')) {
        wp_schedule_event(time()+60, 'hourly', 'notion_sync_hourly');
    }
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('notion_sync_hourly');
});

add_action('notion_sync_hourly', function () { notion_sync_run(); });

// ====== WP-CLI command (เหมือนเดิม) ======
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('notion sync', function($args, $assoc_args){
        notion_sync_run(true);
        WP_CLI::success('Done');
    });
}

// ====== MAIN SYNC ======
function notion_sync_run($verbose=false){
    $pages = notion_query_database_all(NOTION_DATABASE_ID);
    if (!$pages) { ns_log('no pages'); return; }

    foreach ($pages as $page) {
        $pageId   = $page['id'];
        $props    = $page['properties'] ?? [];
        $editedAt = isset($page['last_edited_time']) ? strtotime($page['last_edited_time']) : time();

        $title = notion_read_title($props[PROP_TITLE] ?? null);
        if (!$title) { if($verbose) ns_log("skip $pageId (no title)"); continue; }

        // Post status: If it passed the filter, it's Ready public → publish
        $post_status = 'publish';

        // Find existing post
        $existing = notion_find_post_by_page_id($pageId);
        if ($existing) {
            $existingEdited = intval(get_post_meta($existing->ID, '_notion_last_edited', true));
            if ($existingEdited && $editedAt <= $existingEdited) {
                if($verbose) ns_log("skip unchanged $pageId");
                continue;
            }
        }

        // Render basic blocks
        $contentHtml = notion_render_page_to_html($pageId);

        $postarr = [
            'post_title'   => $title,
            'post_content' => $contentHtml,
            'post_status'  => $post_status,
            'post_type'    => TARGET_POST_TYPE,
        ];

        if ($existing) {
            $postarr['ID'] = $existing->ID;
            $post_id = wp_update_post($postarr, true);
        } else {
            $post_id = wp_insert_post($postarr, true);
            if (!is_wp_error($post_id)) {
                add_post_meta($post_id, '_notion_page_id', $pageId, true);
            }
        }

        if (is_wp_error($post_id)) { ns_log('wp error: '.$post_id->get_error_message()); continue; }

        update_post_meta($post_id, '_notion_last_edited', $editedAt);
        if($verbose) ns_log("synced $pageId -> post#$post_id");
    }
}

// ====== QUERY DATABASE (filter Status = Ready public) ======
function notion_query_database_all($dbId){
    $out = []; $cur = null;

    $payload = [
        'page_size' => 50,
        'filter' => [
            'property' => PROP_STATUS,
            'status'   => ['equals' => STATUS_EQUALS],
        ],
    ];

    do {
        if ($cur) $payload['start_cursor'] = $cur;
        $res = notion_request("databases/$dbId/query", 'POST', $payload);
        if (!$res) break;
        $out = array_merge($out, $res['results'] ?? []);
        $cur = $res['next_cursor'] ?? null;
    } while (!empty($res['has_more']));

    return $out;
}

// ====== RENDER (basic blocks) ======
function notion_render_page_to_html($pageId){
    $blocks = notion_list_children_all("blocks/$pageId/children");
    return notion_blocks_to_html($blocks);
}

function notion_blocks_to_html(array $blocks){
    $out = '';
    $listStack = []; // ul/ol stack

    // list open/close
    $openList = function($tag) use (&$listStack,&$out){
        if (empty($listStack) || end($listStack)!==$tag){
            $out .= "<$tag>";
            $listStack[] = $tag;
        }
    };
    $closeAllLists = function() use (&$listStack,&$out){
        while(!empty($listStack)){ $out .= '</'.array_pop($listStack).'>'; }
    };

    $render_children = function($block_id){
        $children = notion_list_children_all('blocks/'.$block_id.'/children');
        return notion_blocks_to_html($children);
    };

    foreach ($blocks as $block){
        $type = $block['type'] ?? '';
        $data = $block[$type] ?? [];
        $has_children = !empty($block['has_children']);

        // list open/close
        if (in_array($type, ['bulleted_list_item','numbered_list_item','to_do'])){
            $tag = ($type==='numbered_list_item') ? 'ol' : 'ul';
            $openList($tag);
        } else {
            $closeAllLists();
        }

        switch ($type){
            case 'heading_1':
            case 'heading_2':
            case 'heading_3': {
                $level = ($type==='heading_1')?1:(($type==='heading_2')?2:3);
                $out .= '<h'.$level.'>'.notion_rich_text_html($data['rich_text'] ?? []).'</h'.$level.'>';
                break;
            }

            case 'paragraph': {
                $txt = notion_rich_text_html($data['rich_text'] ?? []);
                if (trim($txt)!=='') $out .= '<p>'.$txt.'</p>';
                break;
            }

            case 'bulleted_list_item':
            case 'numbered_list_item': {
                $out .= '<li>'.notion_rich_text_html($data['rich_text'] ?? []);
                if ($has_children) $out .= $render_children($block['id']);
                $out .= '</li>';
                break;
            }

            case 'to_do': {
                $checked = !empty($data['checked']);
                $out .= '<li class="notion-todo"><label><input type="checkbox" disabled '.($checked?'checked':'').'> '.notion_rich_text_html($data['rich_text'] ?? []).'</label>';
                if ($has_children) $out .= $render_children($block['id']);
                $out .= '</li>';
                break;
            }

            case 'quote': {
                $out .= '<blockquote>'.notion_rich_text_html($data['rich_text'] ?? []).'</blockquote>';
                break;
            }

            case 'code': {
                $lang = esc_attr($data['language'] ?? 'plain');
                $code = '';
                foreach (($data['rich_text'] ?? []) as $rt) $code .= ($rt['plain_text'] ?? '');
                $out .= '<pre><code class="language-'.$lang.'">'.esc_html($code).'</code></pre>';
                break;
            }

            case 'toggle': {
                $summary = notion_rich_text_html($data['rich_text'] ?? []);
                $out .= '<details class="notion-toggle"><summary>'.$summary.'</summary>';
                if ($has_children) $out .= $render_children($block['id']);
                $out .= '</details>';
                break;
            }

            case 'divider': {
                $out .= '<hr>';
                break;
            }

            case 'image': {
                $imgUrl = '';
                if (($data['type'] ?? '')==='external') $imgUrl = $data['external']['url'] ?? '';
                else $imgUrl = $data['file']['url'] ?? '';
                $caption = '';
                foreach (($data['caption'] ?? []) as $rt) $caption .= ($rt['plain_text'] ?? '');
                $out .= notion_wp_image_html($imgUrl, $caption);
                break;
            }

            default:
                // Skip unsupported block types
                break;
        }

        // Additional children (except toggle already rendered)
        if ($has_children && !in_array($type, ['toggle'])){
            $out .= $render_children($block['id']);
        }
    }

    while(!empty($listStack)){ $out .= '</'.array_pop($listStack).'>'; }
    return $out;
}

// ====== Notion HTTP + pagination ======
function notion_list_children_all($path){
    $out=[]; $cur=null;
    do{
        $url = $path . ($cur?('?start_cursor='.rawurlencode($cur)):'');
        $res = notion_request($url,'GET');
        if(!$res || empty($res['results'])) break;
        $out = array_merge($out,$res['results']);
        $cur = $res['next_cursor'] ?? null;
    }while(!empty($res['has_more']));
    return $out;
}

function notion_request($path,$method='GET',$body=null){
    $args = [
        'method'=>$method,
        'headers'=>[
            'Authorization'=>'Bearer '.NOTION_API_TOKEN,
            'Notion-Version'=>NOTION_API_VERSION,
            'Content-Type'=>'application/json',
        ],
        'timeout'=>30,
    ];
    if($body!==null) $args['body']=wp_json_encode($body);

    $url = rtrim(NOTION_API_BASE,'/').'/'.ltrim($path,'/');

    // retry 429 responses with simple backoff
    for($i=0;$i<3;$i++){
        $res = wp_remote_request($url,$args);
        if (is_wp_error($res)) { ns_log('http error '.$res->get_error_message()); return null; }
        $code = wp_remote_retrieve_response_code($res);
        if ($code==429) { sleep(2*($i+1)); continue; }
        if ($code>=400) {
            ns_log("http $code ".substr(wp_remote_retrieve_body($res),0,800));
            return null;
        }
        return json_decode(wp_remote_retrieve_body($res), true);
    }
    return null;
}

// ====== Property helpers ======
function notion_read_title($titleProp){
    if (!$titleProp || ($titleProp['type']??'')!=='title') return '';
    return notion_rich_text_plain($titleProp['title'] ?? []);
}
function notion_rich_text_plain($arr){
    $s=''; foreach((array)$arr as $rt){ $s .= ($rt['plain_text'] ?? ''); } return trim($s);
}
function notion_rich_text_html($arr){
    $h='';
    foreach ((array)$arr as $rt){
        $t = esc_html($rt['plain_text'] ?? '');
        $ann = $rt['annotations'] ?? [];
        $href = $rt['href'] ?? null;

        if (!empty($ann['code']))   $t = '<code>'.$t.'</code>';
        if (!empty($ann['bold']))   $t = '<strong>'.$t.'</strong>';
        if (!empty($ann['italic'])) $t = '<em>'.$t.'</em>';
        if (!empty($ann['strikethrough'])) $t = '<s>'.$t.'</s>';
        if (!empty($ann['underline']))     $t = '<u>'.$t.'</u>';
        if ($href) $t = '<a href="'.esc_url($href).'" target="_blank" rel="noreferrer noopener">'.$t.'</a>';

        $h .= $t;
    }
    return $h;
}

// ====== WordPress helpers ======
function notion_find_post_by_page_id($pageId){
    $q = new WP_Query([
        'post_type'=>TARGET_POST_TYPE,
        'posts_per_page'=>1,
        'meta_key'=>'_notion_page_id',
        'meta_value'=>$pageId,
        'post_status'=>'any',
        'no_found_rows'=>true,
    ]);
    return $q->have_posts() ? $q->posts[0] : null;
}

function notion_wp_image_html($url,$alt=''){
    if(!$url) return '';
    $aid = notion_media_sideload_by_url($url, 0, $alt);
    if (is_wp_error($aid)) {
        ns_log('media fail: '.$aid->get_error_message());
        $altAttr = esc_attr($alt);
        return '<figure><img src="'.esc_url($url).'" alt="'.$altAttr.'">'.($alt?'<figcaption>'.esc_html($alt).'</figcaption>':'').'</figure>';
    }
    $img = wp_get_attachment_image($aid, 'large', false, ['alt'=>$alt]);
    return $alt ? '<figure>'.$img.'<figcaption>'.esc_html($alt).'</figcaption></figure>' : $img;
}

function notion_media_sideload_by_url($url,$post_id=0,$desc=''){
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
    }
    $tmp = download_url($url);
    if (is_wp_error($tmp)) return $tmp;

    $name = basename(parse_url($url, PHP_URL_PATH)) ?: 'notion-image.jpg';
    $file_array = ['name'=>sanitize_file_name($name), 'tmp_name'=>$tmp];

    $id = media_handle_sideload($file_array, $post_id, $desc);
    if (is_wp_error($id)) { @unlink($file_array['tmp_name']); return $id; }
    if ($desc) update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field($desc));
    return $id;
}
