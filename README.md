# WP Notion Sync

![WP Notion Sync](assets/wp%20notion%20sync.png)

Sync content from Notion Database into WordPress posts.  
- Supports blocks: headings, paragraphs, lists, quotes, code, todo, toggle, divider, image  
- Images are sideloaded into Media Library  
- Auto sync via WP-Cron (hourly) or `wp notion sync` (WP-CLI)
 - Tags mapping from Notion (multi-select / select / comma-separated text)
- Categories mapping from Notion (multi-select / select / comma-separated text)
- Slug mapping from Notion (title / rich_text / formula string / url)
- Featured image from Notion Files & media (first file)
 - Writeback WordPress URL to Notion

## Installation
1. Clone into `wp-content/plugins/wp-notion-sync`
2. Edit `notion-sync.php` → set `NOTION_API_TOKEN` and `NOTION_DATABASE_ID`
3. Activate the plugin in WordPress

## Usage
- Run manually:  
  ```bash
  wp notion sync
  ```

### Tags
- From Notion only: Add a property for tags (recommended: multi-select) and set "Tags Property Name" in plugin settings. The plugin reads multi-select, select, or comma-separated rich text and sets WordPress post tags accordingly.

### Categories (หมวดหมู่)
- From Notion only: Add a property for categories (e.g., "Categories") and set "Categories Property Name" in plugin settings. The plugin reads multi-select, select, or comma-separated rich text and sets WordPress categories accordingly.

### URL Slug
- Add a Notion property (e.g., "Slug") and set "Slug Property Name" in plugin settings.
- Supported property types: title, rich_text, formula (string), or url.
- If provided, the value is sanitized and applied to WordPress `post_name` (the URL slug). If empty or missing, WordPress keeps/generates the default slug.

### Featured Image (หน้าปก)
- Add a Notion property of type "Files & media" (e.g., "Cover").
- Set "Featured Image Property Name" in plugin settings to this property name.
- On sync, the plugin takes the first file in that property and sets it as the WordPress post featured image. If the property is present but empty, the current featured image is removed.

### Writeback WordPress URL → Notion
- Add a Notion property (recommended type: URL), e.g., "WP URL".
- Set "Writeback URL Property Name" in plugin settings to that property name.
- After a successful sync, the plugin updates that property on the Notion page with the post's full permalink. If the Notion property is rich_text or title, the URL is written as text.
