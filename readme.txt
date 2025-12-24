=== LLMS.txt and LLMS-Full.txt Generator ===
Contributors: gauravattrock
Tags: llm, ai, documentation, seo, text files, website analysis
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate llm.txt and llm-full.txt files for your website to help large language models understand your content structure.

== Description ==

LLMS.txt and LLMS-Full.txt Generator helps website owners generate structured text files that describe their website content in a format suitable for large language models (LLMs).

The plugin allows you to:
- Generate a summarized llm.txt file
- Generate a full llm-full.txt file
- Generate both files together
- Save the files directly to the website root
- Automatically create backups when files already exist
- View, download, and manage generation history

The generated files are placed in the WordPress root directory so they are publicly accessible, similar to robots.txt or sitemap.xml.

An external service is used to analyze the website URL and generate the content for these files.

== Features ==

* Generate llm.txt (summarized content)
* Generate llm-full.txt (full website content)
* Generate both files at once
* Automatic backup creation before overwriting files
* One-backup-per-file guarantee per request
* History tracking for generated files
* View, download, and delete history entries
* Secure AJAX handling with WordPress nonces
* Admin-only access for all operations

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the "Plugins" menu in WordPress
3. Go to **Admin → LLMS.txt Generator**
4. Enter a website URL and generate files

== Frequently Asked Questions ==

= Where are the files saved? =
The files are saved directly in the WordPress root directory:
- `llm.txt`
- `llm-full.txt`

= What happens if the file already exists? =
If a file already exists, the plugin creates a timestamped backup before overwriting it.

Example:
`llm.txt.backup.2025-01-01-12-30-45-123456`

= Does this plugin delete files on uninstall? =
No. The plugin does not delete files or database data on uninstall unless explicitly allowed via a filter.

= Does this plugin send personal data? =
No personal user data is sent. Only the website URL and output selection are sent to the external service to generate content.

== External Services ==

This plugin connects to an external service to generate content.

* Service URL: https://llm.attrock.com
* Purpose: Generate llm.txt and llm-full.txt content
* Data sent: Website URL and selected output type
* Data not sent: Personal data, authentication details, or user credentials
* Service required: Yes (core functionality depends on it)


== Changelog ==

= 1.0.0 =
* Initial release
* llm.txt and llm-full.txt generation
* Backup system with duplicate prevention
* History tracking and management
