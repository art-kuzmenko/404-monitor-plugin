# 404 Error Log

WordPress plugin that logs 404 (Page Not Found) errors to help you find broken links in content, plugins, theme files, and functions.

## Features

- **Log 404 requests** — Every request that results in a 404 is recorded (URL, date/time, and optional extra data).
- **Configurable data** — Choose what to store: HTTP Referrer, Client IP Address, Client User Agent.
- **Filter noise** — Ignore visits from robots (Googlebot, Yandex, ChatGPT, etc.) and/or requests without an HTTP Referrer.
- **Log size limit** — Set a maximum number of entries (e.g. 500); when the limit is reached, oldest entries are removed.
- **Search & bulk delete** — Search the log and delete selected entries in bulk.
- **Cache-friendly** — Works correctly with W3 Total Cache and WP Super Cache.
- **Clean uninstall** — Removing the plugin deletes all options and the log table.

## Installation

1. Upload the `404-monitor` folder to `wp-content/plugins/`.
2. In the WordPress admin, go to **Plugins** → **Installed** and activate **404 Error Log**.
3. Open **Tools** → **404 Error Log**.

## Usage

### View 404 log

- **Tools** → **404 Error Log** (default tab).
- Table columns: Date, URL, User Agent, HTTP Referer, IP Address (columns depend on settings).
- Use **Search log** to filter by URL, referer, IP, or user agent.
- Select rows and use **Bulk Actions** → **Delete** → **Apply** to remove entries.
- Pagination: 20 items per page.

### Settings

- **Tools** → **404 Error Log** → **Manage plugin settings**.

| Setting | Description |
|--------|-------------|
| **Maximum log entries to keep** | When this limit is reached, oldest entries are replaced by new ones (e.g. 500). |
| **Additional data to record** | Checkboxes: HTTP Referrer, Client IP Address, Client User Agent. |
| **Other options** | *Ignore visits from robots* — do not log known bots. *Ignore visits which don't have an HTTP Referrer* — log only requests that have a referer. |

Click **Update settings** to save.

## Requirements

- WordPress 5.0 or later.
- PHP 7.4 or later.
