<?php
/**
 * Plugin Name: Notion Sync
* Description: Sync Notion Database → WordPress (Free). Supports blocks: heading_1/2/3, paragraph, bulleted_list_item, numbered_list_item, quote, code, to_do, toggle, divider, image. Images are stored in Media Library and content updates automatically.
 * Version:     0.3.2
 * Author:      Sumeta.P
 */

// ====== CONFIG ======
const NOTION_API_TOKEN    = '';
const NOTION_DATABASE_ID  = ''; // 32-character ID from the URL
const NOTION_API_BASE     = 'https://api.notion.com/v1';
const NOTION_API_VERSION  = '2022-06-28';

// Property names in the Notion Database
const PROP_TITLE          = '';          // type: title
const PROP_STATUS         = '';        // type: status (filter by Ready public)
const PROP_TAGS           = '';        // type: multi_select or select (tags)
const PROP_CATEGORIES     = '';        // type: multi_select or select (categories)
const STATUS_EQUALS       = '';  // Only sync records with this value
const STATUS_TO_CHANGE    = '';  // Set to this status after successful sync

// Runtime configuration getters (UI options override defaults when constants are empty)
function ns_get_option($suffix, $default = ''){
    $name = 'notion_sync_' . $suffix;
    $val = get_option($name, null);
    if ($val === null || $val === '') return $default;
    return is_string($val) ? trim($val) : $val;
}
function ns_api_token(){
    if (defined('NOTION_API_TOKEN') && NOTION_API_TOKEN) return NOTION_API_TOKEN;
    return ns_get_option('api_token', '');
}
function ns_database_id(){
    if (defined('NOTION_DATABASE_ID') && NOTION_DATABASE_ID) return NOTION_DATABASE_ID;
    return ns_get_option('database_id', '');
}
function ns_prop_title(){
    if (defined('PROP_TITLE') && PROP_TITLE) return PROP_TITLE;
    return ns_get_option('prop_title', 'Name');
}
function ns_prop_status(){
    if (defined('PROP_STATUS') && PROP_STATUS) return PROP_STATUS;
    return ns_get_option('prop_status', 'Status');
}
function ns_prop_tags(){
    if (defined('PROP_TAGS') && PROP_TAGS) return PROP_TAGS;
    return ns_get_option('prop_tags', 'Tags');
}
function ns_prop_categories(){
    if (defined('PROP_CATEGORIES') && PROP_CATEGORIES) return PROP_CATEGORIES;
    return ns_get_option('prop_categories', 'Categories');
}
function ns_status_equals(){
    if (defined('STATUS_EQUALS') && STATUS_EQUALS) return STATUS_EQUALS;
    return ns_get_option('status_equals', 'Ready public');
}
function ns_status_to_change(){
    if (defined('STATUS_TO_CHANGE') && STATUS_TO_CHANGE) return STATUS_TO_CHANGE;
    return ns_get_option('status_to_change', 'แชร์บนเว็บ');
}
function ns_schedule_recurrence(){
    $rec = ns_get_option('schedule', 'hourly');
    $all = wp_get_schedules();
    return isset($all[$rec]) ? $rec : 'hourly';
}

// WordPress target
const TARGET_POST_TYPE    = 'post';
const DEFAULT_POST_STATUS = 'publish';

// Selected post status (UI overrides default)
function ns_post_status(){
    $allowed = ['publish','draft','pending','private'];
    $val = ns_get_option('post_status', DEFAULT_POST_STATUS);
    $val = is_string($val) ? strtolower(trim($val)) : DEFAULT_POST_STATUS;
    return in_array($val, $allowed, true) ? $val : DEFAULT_POST_STATUS;
}

// Debug toggle
if (!defined('NOTION_SYNC_DEBUG')) define('NOTION_SYNC_DEBUG', true);
function ns_log($x){
	if (!NOTION_SYNC_DEBUG) return;
	$msg = '[NOTION-SYNC] '.(is_string($x)?$x:wp_json_encode($x));
	// Always write to error log
	error_log($msg);
	// Also buffer in-memory for this request if enabled
	global $notion_sync_request_logs;
	if (is_array($notion_sync_request_logs)) { $notion_sync_request_logs[] = $msg; }
}

// ====== ACTIVATE / DEACTIVATE ======
// - Set new cron named notion_sync_hourly
// - Clear old cron from lean plugin (notion_lean_sync_hourly) if any
// Custom additional schedules
add_filter('cron_schedules', function($s){
    if (!isset($s['every_5_minutes'])){
        $s['every_5_minutes'] = ['interval'=>5*60, 'display'=>__('Every 5 Minutes')];
    }
    if (!isset($s['every_15_minutes'])){
        $s['every_15_minutes'] = ['interval'=>15*60, 'display'=>__('Every 15 Minutes')];
    }
    if (!isset($s['every_30_minutes'])){
        $s['every_30_minutes'] = ['interval'=>30*60, 'display'=>__('Every 30 Minutes')];
    }
    return $s;
});

register_activation_hook(__FILE__, function () {
    // kill old cron from "notion-lean-sync"
    wp_clear_scheduled_hook('notion_lean_sync_hourly');
    $rec = ns_schedule_recurrence();
    if (!wp_next_scheduled('notion_sync_hourly')) {
        wp_schedule_event(time()+60, $rec, 'notion_sync_hourly');
    }
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('notion_sync_hourly');
});

add_action('notion_sync_hourly', function () { notion_sync_run(); });

// ====== WP-CLI command ======
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('notion sync', function($args, $assoc_args){
        notion_sync_run(true);
        WP_CLI::success('Done');
    });
}

// ====== MAIN SYNC ======
function notion_sync_run($verbose=false){
    $pages = notion_query_database_all(ns_database_id());
    if (!$pages) { ns_log('no pages'); return; }

    foreach ($pages as $page) {
        $pageId   = $page['id'];
        $props    = $page['properties'] ?? [];
        $editedAt = isset($page['last_edited_time']) ? strtotime($page['last_edited_time']) : time();

        $title = notion_read_title($props[ ns_prop_title() ] ?? null);
        if (!$title) { if($verbose) ns_log("skip $pageId (no title)"); continue; }

        // Post status: from settings (fallback to default)
        $post_status = ns_post_status();

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

        // Apply tags from Notion property only
        $tagPropName = ns_prop_tags();
        if ($tagPropName) {
            if (!array_key_exists($tagPropName, (array)$props)) {
                ns_log('tags prop not found: ' . $tagPropName . ' | available: ' . implode(', ', array_keys((array)$props)));
            }
            $tags = notion_read_tags($props[$tagPropName] ?? null);
            if ($tags !== null) { // null means property missing; [] means explicitly empty
                ns_log('parsed tags: '.(empty($tags)?'(empty)':implode(', ', $tags)));
                $res = wp_set_post_tags($post_id, $tags, false);
                if (is_wp_error($res)) {
                    ns_log('wp_set_post_tags error: '.$res->get_error_message());
                } else {
                    ns_log('wp_set_post_tags ids: '.implode(', ', array_map('strval', (array)$res)));
                }
                // Log current terms state
                $cur = get_the_terms($post_id, 'post_tag');
                if (is_wp_error($cur)) ns_log('get_the_terms(post_tag) error: '.$cur->get_error_message());
                else ns_log('current tags now: '.(empty($cur)?'(none)':implode(', ', array_map(function($t){ return $t->name; }, (array)$cur))));
            }
        }

        // Apply categories from Notion property only
        $catPropName = ns_prop_categories();
        if ($catPropName) {
            if (!array_key_exists($catPropName, (array)$props)) {
                ns_log('category prop not found: ' . $catPropName . ' | available: ' . implode(', ', array_keys((array)$props)));
            }
            $cats = notion_read_categories($props[$catPropName] ?? null);
            if ($cats !== null) {
                ns_log('parsed categories: '.(empty($cats)?'(empty)':implode(', ', $cats)));
                // Ensure category terms exist and assign by IDs
                $cat_ids = ns_ensure_terms($cats, 'category');
                ns_log('ensure category ids: '.(empty($cat_ids)?'(none)':implode(', ', array_map('strval', (array)$cat_ids))));
                $res2 = wp_set_post_terms($post_id, $cat_ids, 'category', false);
                if (is_wp_error($res2)) {
                    ns_log('wp_set_post_terms(category) error: '.$res2->get_error_message());
                } else {
                    ns_log('wp_set_post_terms(category) ids: '.implode(', ', array_map('strval', (array)$res2)));
                }
                $cur2 = get_the_terms($post_id, 'category');
                if (is_wp_error($cur2)) ns_log('get_the_terms(category) error: '.$cur2->get_error_message());
                else ns_log('current categories now: '.(empty($cur2)?'(none)':implode(', ', array_map(function($t){ return $t->name; }, (array)$cur2))));
            }
        }

        // After successful sync, update Notion Status to configured value
        notion_update_page_status($pageId, ns_status_to_change());
    }
}

// ====== QUERY DATABASE (filter Status = Ready public) ======
function notion_query_database_all($dbId){
    $out = []; $cur = null;

    $payload = [
        'page_size' => 50,
        'filter' => [
            'property' => ns_prop_status(),
            'status'   => ['equals' => ns_status_equals()],
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
            'Authorization'=>'Bearer '.ns_api_token(),
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
    if (!$titleProp) return '';
    $type = $titleProp['type'] ?? '';
    if ($type === 'title') {
        return notion_rich_text_plain($titleProp['title'] ?? []);
    }
    if ($type === 'rich_text') {
        return notion_rich_text_plain($titleProp['rich_text'] ?? []);
    }
    return '';
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

// Read Notion tags property into array of strings
// Returns null if property missing; [] if present but empty; or [names]
function notion_read_tags($prop){
    if ($prop === null) return null;
    $type = $prop['type'] ?? '';
    $out = [];
    if ($type === 'multi_select'){
        foreach ((array)($prop['multi_select'] ?? []) as $it){
            $name = trim((string)($it['name'] ?? ''));
            if ($name !== '') $out[] = $name;
        }
        return $out;
    }
    if ($type === 'select'){
        $name = trim((string)($prop['select']['name'] ?? ''));
        return $name!=='' ? [$name] : [];
    }
    if ($type === 'rich_text'){
        // Support comma-separated in rich text
        $txt = notion_rich_text_plain($prop['rich_text'] ?? []);
        if ($txt === '') return [];
        $parts = array_map('trim', preg_split('/[,;]+/', $txt));
        $parts = array_values(array_filter($parts, function($s){ return $s !== ''; }));
        return $parts;
    }
    return [];
}

// Read Notion categories property into array of strings
// Returns null if property missing; [] if present but empty; or [names]
function notion_read_categories($prop){
    if ($prop === null) return null;
    $type = $prop['type'] ?? '';
    $out = [];
    if ($type === 'multi_select'){
        foreach ((array)($prop['multi_select'] ?? []) as $it){
            $name = trim((string)($it['name'] ?? ''));
            if ($name !== '') $out[] = $name;
        }
        return $out;
    }
    if ($type === 'select'){
        $name = trim((string)($prop['select']['name'] ?? ''));
        return $name!=='' ? [$name] : [];
    }
    if ($type === 'rich_text'){
        $txt = notion_rich_text_plain($prop['rich_text'] ?? []);
        if ($txt === '') return [];
        $parts = array_map('trim', preg_split('/[,;]+/', $txt));
        $parts = array_values(array_filter($parts, function($s){ return $s !== ''; }));
        return $parts;
    }
    return [];
}

// Ensure terms exist for a given taxonomy and return their IDs
function ns_ensure_terms(array $names, $taxonomy){
    $ids = [];
    foreach ($names as $name){
        $name = trim((string)$name);
        if ($name === '') continue;
        $exists = term_exists($name, $taxonomy);
        if ($exists && !is_wp_error($exists)){
            $tid = is_array($exists) ? intval($exists['term_id'] ?? 0) : intval($exists);
            if ($tid) { $ids[] = $tid; continue; }
        }
        $created = wp_insert_term($name, $taxonomy);
        if (is_wp_error($created)){
            ns_log("wp_insert_term($taxonomy) error for '$name': ".$created->get_error_message());
            continue;
        }
        $tid = intval($created['term_id'] ?? 0);
        if ($tid) $ids[] = $tid;
    }
    return $ids;
}

// Update a Notion page Status property to a specific status name
function notion_update_page_status($pageId, $statusName){
    $propName = ns_prop_status();
    if (!$propName){ ns_log('skip status update: empty status property name'); return; }
    if (!$statusName){ ns_log('skip status update: empty target status'); return; }

    $payload = [
        'properties' => [
            $propName => [ 'status' => [ 'name' => $statusName ] ],
        ],
    ];

    $res = notion_request('pages/'.$pageId, 'PATCH', $payload);
    if (!$res){
        ns_log('failed to update status for '.$pageId.' -> '.$statusName);
    } else {
        ns_log('updated status for '.$pageId.' -> '.$statusName);
    }
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

// ====== Admin Settings (UI) ======
add_action('admin_menu', function(){
    add_options_page(
        'Notion Sync',
        'Notion Sync',
        'manage_options',
        'notion-sync',
        'notion_sync_render_settings_page'
    );
});

add_action('admin_post_notion_sync_save', 'notion_sync_handle_settings_save');
add_action('admin_post_notion_sync_now', 'notion_sync_handle_sync_now');

function notion_sync_is_constant_locked($key){
    $map = [
        'api_token'  => 'NOTION_API_TOKEN',
        'database_id'=> 'NOTION_DATABASE_ID',
        'prop_title' => 'PROP_TITLE',
        'prop_status'=> 'PROP_STATUS',
        'prop_tags'  => 'PROP_TAGS',
        'prop_categories' => 'PROP_CATEGORIES',
        'status_equals' => 'STATUS_EQUALS',
        'status_to_change' => 'STATUS_TO_CHANGE',
    ];
    $c = $map[$key] ?? '';
    return $c && defined($c) && constant($c);
}

function notion_sync_handle_settings_save(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('notion_sync_save', '_wpnonce_notion_sync');

    $fields = ['api_token','database_id','prop_title','prop_status','prop_tags','prop_categories','status_equals','status_to_change','schedule','post_status'];
    foreach ($fields as $f){
        if (notion_sync_is_constant_locked($f)) continue; // constant overrides
        $val = isset($_POST[$f]) ? sanitize_text_field(wp_unslash($_POST[$f])) : '';
        if ($f === 'schedule'){
            // validate schedule key
            $schedules = wp_get_schedules();
            if (!isset($schedules[$val])) { $val = 'hourly'; }
        } elseif ($f === 'post_status'){
            $allowed = ['publish','draft','pending','private'];
            $val = strtolower($val);
            if (!in_array($val, $allowed, true)) { $val = DEFAULT_POST_STATUS; }
        }
        update_option('notion_sync_'.$f, $val);
    }

    // reschedule cron with new recurrence
    $rec = ns_schedule_recurrence();
    wp_clear_scheduled_hook('notion_sync_hourly');
    wp_schedule_event(time()+60, $rec, 'notion_sync_hourly');

    wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer() ?: admin_url('options-general.php?page=notion-sync')));
    exit;
}

function notion_sync_handle_sync_now(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('notion_sync_now', '_wpnonce_notion_sync_now');

    // Run sync immediately (verbose logs enabled)
    global $notion_sync_request_logs; $notion_sync_request_logs = [];
    ns_log('Manual sync requested by user #'.get_current_user_id());
    notion_sync_run(true);
    // Persist logs temporarily for display after redirect
    if (is_array($notion_sync_request_logs) && !empty($notion_sync_request_logs)){
        set_transient('notion_sync_last_logs_'.get_current_user_id(), $notion_sync_request_logs, 5 * MINUTE_IN_SECONDS);
    }

    wp_safe_redirect(add_query_arg('synced', '1', wp_get_referer() ?: admin_url('options-general.php?page=notion-sync')));
    exit;
}

function notion_sync_render_settings_page(){
    if (!current_user_can('manage_options')) return;

    $vals = [
        'api_token'   => ns_api_token(),
        'database_id' => ns_database_id(),
        'prop_title'  => ns_prop_title(),
        'prop_status' => ns_prop_status(),
        'prop_tags'   => ns_prop_tags(),
        'prop_categories' => ns_prop_categories(),
        'status_equals' => ns_status_equals(),
        'status_to_change' => ns_status_to_change(),
        'post_status' => ns_post_status(),
        'schedule'    => ns_schedule_recurrence(),
    ];
    $locked = [
        'api_token'   => notion_sync_is_constant_locked('api_token'),
        'database_id' => notion_sync_is_constant_locked('database_id'),
        'prop_title'  => notion_sync_is_constant_locked('prop_title'),
        'prop_status' => notion_sync_is_constant_locked('prop_status'),
        'prop_tags'   => notion_sync_is_constant_locked('prop_tags'),
        'prop_categories' => notion_sync_is_constant_locked('prop_categories'),
        'status_equals' => notion_sync_is_constant_locked('status_equals'),
        'status_to_change' => notion_sync_is_constant_locked('status_to_change'),
        'post_status' => false,
    ];
    $schedules = wp_get_schedules();
    $preferred = ['every_5_minutes','every_15_minutes','every_30_minutes','hourly','twicedaily','daily'];
    $ordered = array_values(array_unique(array_merge($preferred, array_keys($schedules))));
    ?>
    <div class="wrap">
        <h1>Notion Sync Settings</h1>
        <?php if (!empty($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['synced'])): ?>
            <div class="notice notice-success is-dismissible"><p>Sync completed.</p></div>
        <?php endif; ?>
        <?php
        $log_key = 'notion_sync_last_logs_'.get_current_user_id();
        $logs = get_transient($log_key);
        if ($logs && is_array($logs)){
            delete_transient($log_key);
            $joined = esc_html(implode("\n", $logs));
            echo '<div class="notice notice-info"><p><strong>Sync Logs</strong></p><pre style="white-space:pre-wrap;max-height:320px;overflow:auto;margin:0">'.$joined.'</pre></div>';
        }
        ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('notion_sync_save','_wpnonce_notion_sync'); ?>
            <input type="hidden" name="action" value="notion_sync_save">

            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row"><label for="api_token">Notion API Token</label></th>
                    <td>
                        <input name="api_token" id="api_token" type="password" class="regular-text" value="<?php echo esc_attr($vals['api_token']); ?>" <?php disabled($locked['api_token']); ?> placeholder="secret_xxx">
                        <p class="description">Bearer token from Notion Integrations. <?php if ($locked['api_token']) echo 'Defined via NOTION_API_TOKEN constant.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="post_status">WordPress Post Status</label></th>
                    <td>
                        <select name="post_status" id="post_status" <?php disabled($locked['post_status']); ?>>
                            <option value="publish" <?php selected($vals['post_status'], 'publish'); ?>>Publish</option>
                            <option value="draft" <?php selected($vals['post_status'], 'draft'); ?>>Draft</option>
                            <option value="pending" <?php selected($vals['post_status'], 'pending'); ?>>Pending Review</option>
                            <option value="private" <?php selected($vals['post_status'], 'private'); ?>>Private</option>
                        </select>
                        <p class="description">Status applied to posts created or updated by sync.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="schedule">Schedule Frequency</label></th>
                    <td>
                        <select name="schedule" id="schedule">
                            <?php foreach ($ordered as $key): if (!isset($schedules[$key])) continue; ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($vals['schedule'], $key); ?>>
                                    <?php echo esc_html($schedules[$key]['display'] . ' ('. $key .')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="database_id">Notion Database ID</label></th>
                    <td>
                        <label for="database_id">Notion Database ID</label><br>
                        <input name="database_id" id="database_id" type="text" class="regular-text" value="<?php echo esc_attr($vals['database_id']); ?>" <?php disabled($locked['database_id']); ?> placeholder="32-char ID">
                        <p class="description">Found in the database URL. <?php if ($locked['database_id']) echo 'Defined via NOTION_DATABASE_ID constant.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="prop_title">Title Property Name</label></th>
                    <td>
                        <input name="prop_title" id="prop_title" type="text" class="regular-text" value="<?php echo esc_attr($vals['prop_title']); ?>" <?php disabled($locked['prop_title']); ?> placeholder="Name">
                        <p class="description">Notion property used as post title (type: title). <?php if ($locked['prop_title']) echo 'Defined via PROP_TITLE constant.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="prop_status">Status Property Name</label></th>
                    <td>
                        <input name="prop_status" id="prop_status" type="text" class="regular-text" value="<?php echo esc_attr($vals['prop_status']); ?>" <?php disabled($locked['prop_status']); ?> placeholder="Status">
                        <p class="description">Notion status property used to filter synced items. <?php if ($locked['prop_status']) echo 'Defined via PROP_STATUS constant.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="prop_tags">Tags Property Name</label></th>
                    <td>
                        <input name="prop_tags" id="prop_tags" type="text" class="regular-text" value="<?php echo esc_attr($vals['prop_tags']); ?>" <?php disabled($locked['prop_tags']); ?> placeholder="Tags">
                        <p class="description">Notion property used as WordPress tags. Supports multi-select, select, or comma-separated rich text. <?php if ($locked['prop_tags']) echo 'Defined via PROP_TAGS constant.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="prop_categories">Categories Property Name</label></th>
                    <td>
                        <input name="prop_categories" id="prop_categories" type="text" class="regular-text" value="<?php echo esc_attr($vals['prop_categories']); ?>" <?php disabled($locked['prop_categories']); ?> placeholder="Categories">
                        <p class="description">Notion property used as WordPress categories. Supports multi-select, select, or comma-separated rich text. <?php if ($locked['prop_categories']) echo 'Defined via PROP_CATEGORIES constant.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="status_equals">Current Status</label></th>
                    <td>
                        <input name="status_equals" id="status_equals" type="text" class="regular-text" value="<?php echo esc_attr($vals['status_equals']); ?>" <?php disabled($locked['status_equals']); ?> placeholder="Ready public">
                        <p class="description">Only records where the status equals this value will sync. <?php if ($locked['status_equals']) echo 'Defined via STATUS_EQUALS constant.'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="status_to_change">Status to Change</label></th>
                    <td>
                        <input name="status_to_change" id="status_to_change" type="text" class="regular-text" value="<?php echo esc_attr($vals['status_to_change']); ?>" <?php disabled($locked['status_to_change']); ?> placeholder="แชร์บนเว็บ">
                        <p class="description">After a successful sync, set the Notion status to this value. <?php if ($locked['status_to_change']) echo 'Defined via STATUS_TO_CHANGE constant.'; ?></p>
                    </td>
                </tr>
                </tbody>
            </table>

            <?php submit_button('Save Changes'); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
            <?php wp_nonce_field('notion_sync_now','_wpnonce_notion_sync_now'); ?>
            <input type="hidden" name="action" value="notion_sync_now">
            <?php submit_button('Sync Now', 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

// Settings link on Plugins screen
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
    $url = admin_url('options-general.php?page=notion-sync');
    $links[] = '<a href="'.esc_url($url).'">'.esc_html__('Settings').'</a>';
    return $links;
});
