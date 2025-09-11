# WP Notion Sync

![WP Notion Sync](assets/wp%20notion%20sync.png)

Sync content from Notion Database into WordPress posts.  
- Supports blocks: headings, paragraphs, lists, quotes, code, todo, toggle, divider, image  
- Images are sideloaded into Media Library  
- Auto sync via WP-Cron (hourly) or `wp notion sync` (WP-CLI)

## Installation
1. Clone into `wp-content/plugins/wp-notion-sync`
2. Edit `notion-sync.php` → set `NOTION_API_TOKEN` and `NOTION_DATABASE_ID`
3. Activate the plugin in WordPress

## Usage
- Run manually:  
  ```bash
  wp notion sync
