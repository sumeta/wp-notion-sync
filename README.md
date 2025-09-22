# WP Notion Sync

![WP Notion Sync](assets/wp%20notion%20sync.png)

Sync content from Notion Database into WordPress posts.  
- Supports blocks: headings, paragraphs, lists, quotes, code, todo, toggle, divider, image  
- Images are sideloaded into Media Library  
- Auto sync via WP-Cron (hourly) or `wp notion sync` (WP-CLI)
 - Tags mapping from Notion (multi-select / select / comma-separated text)
 - Categories mapping from Notion (multi-select / select / comma-separated text)

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
