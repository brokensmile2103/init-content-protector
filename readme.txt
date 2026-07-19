=== Init Content Protector – Anti-Copy, Anti-Scrape, Encrypt-All ===
Contributors: brokensmile.2103
Tags: content protection, anti-copy, copy protection, encryption, anti-scraping
Requires at least: 5.7
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect your content from copying, scraping, and inspection using JS blocking, keyword cloaking, noise injection, and optional full encryption.

== Description ==

**Init Content Protector** is a powerful yet lightweight plugin that safeguards your post content from unauthorized copying, scraping tools, and inspection via browser developer tools.

This plugin is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of minimalist, fast, and developer-focused tools for WordPress.

GitHub repository: [https://github.com/brokensmile2103/init-content-protector](https://github.com/brokensmile2103/init-content-protector)

**Features:**
- JavaScript-based copy protection (blocks selection, right-click, print, DevTools access)
- Full content encryption with client-side decryption using CryptoJS
- Keyword cloaking using CSS pseudo-elements
- Invisible noise injection to confuse crawlers
- Per-post type configuration
- Custom encryption key per site
- Custom content selector support

Use this plugin to harden your site's content visibility while maintaining a smooth reading experience for real users.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/init-content-protector` directory, or install via the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings → Init Content Protector** and configure your preferred options.

== Frequently Asked Questions ==

= Will this affect SEO? =
If you enable full content encryption, search engines will not be able to see the content. Only use this option if SEO visibility is not required.

= Does this plugin support custom post types? =
Yes. You can choose which post types are protected in the settings page.

= Can I use my own encryption key? =
Yes. You can set a custom key per site for added security.

== Screenshots ==

1. **Settings Page** – Configure protection methods, encryption, keyword cloaking, and per-post type options.

== Changelog ==

= 1.4 – July 20, 2026 =
- Fixed a critical bug in noise injection: the previous logic split raw HTML on whitespace, so noise spans could be inserted in the middle of tag attributes (e.g. between `<img` and `src="..."`), corrupting markup and breaking layout. Noise is now only ever injected into plain text runs between tags, never inside a tag itself.
- Noise injection now skips the entire contents of `<script>`, `<style>`, `<pre>`, `<textarea>`, `<code>`, `<select>`, `<option>`, `<title>`, and `<svg>` elements, since injecting spans there is invalid markup (breaks dropdowns, SVG rendering) or would corrupt whitespace-sensitive/non-visual content.
- Added `aria-hidden="true"` to noise spans as defense-in-depth (display:none already hides them from screen readers, this guards against the CSS failing to load).
- Added a configurable "Noise Injection Rate" setting (1–50%, default 7%, matching the previous hardcoded rate) instead of a fixed value in code.
- Added an optional "Enhanced" decryption key delivery mode (`includes/rest-api.php`): fetches the key via a REST API endpoint after page load instead of embedding it directly in page HTML. Keeps the key out of cached/static HTML and plays nicely with full-page cache plugins. Default "Inline" mode is unchanged for backward compatibility. Deliberately not rate-limited via per-IP transients — that pattern creates one wp_options row per unique visitor/bot IP with no active garbage collection, which would bloat the database far worse than the scraping it aims to prevent. Use server/CDN/WAF-level rate limiting if needed.
- Added transient caching for encrypted content, keyed to post ID + last-modified time + encryption key, avoiding redundant OpenSSL/PBKDF2 work on every single page view. Noise injection intentionally remains uncached since its per-request randomness is part of what makes it effective against scrapers.
- Added graceful fallback when a host is missing OpenSSL or PBKDF2 support: previously encryption calls could fatal-error or silently show "Encryption failed" to every visitor; now the plugin fails open (shows real content to visitors, with an admin-only notice) instead of breaking the page.
- Fixed a logic bug where a failed encryption result was still treated as truthy due to `wp_json_encode(false)` producing a non-empty string.
- Reduced PBKDF2 salt size from 256 bytes to 32 bytes (standard, sufficient size) to cut unnecessary CPU cost server- and client-side.
- Added AMP endpoint detection: protection (JS injection, encryption, noise CSS) is now skipped automatically on AMP pages instead of producing invalid AMP markup.
- Added "Auto-detect" button next to Content Selector in settings: tests common theme/builder content-wrapper selectors against your most recent published post and fills in the field automatically.
- Added console warnings (visible to administrators only) when the configured content selector isn't found on a page, to make misconfiguration easier to diagnose instead of failing silently.
- Fixed a translation-escaping bug where `<code>` tags in two settings descriptions were rendered as literal text instead of formatted code.
- Removed unused `devtools` variable in `content-protector.js`.
- Added/updated `.pot` and Vietnamese `.po`/`.mo` translations for all new strings introduced in this release.

= 1.3 – November 15, 2025 =
- Fully decoupled **encryption** and **JS content protection** into separate script modules (`decrypt.js` and `content-protector.js`)
- Split script loading into **two independent wp_enqueue_scripts hooks**, eliminating unwanted cross-dependencies
- Renamed localized JS object for encryption to **InitContentDecryptData** for clearer separation of responsibilities
- Ensured JS protection (block copy, right-click, print, DevTools) works independently even when encryption is disabled
- Improved maintainability by isolating crypto loading (`crypto-js.min.js`) strictly to encrypt mode
- Refined initialization order to guarantee consistent behavior across all themes and page builders

= 1.2 – November 14, 2025 =
- Added option to **exclude specific user roles** from all protection layers (encryption, JS protection, noise injection, keyword cloaking)
- Implemented role-based bypass at both **filter level** and **asset enqueue level** for consistent behavior across frontend
- Refactored protection flow so encryption, JS protection, and noise injection operate **independently**, preventing unwanted coupling
- Improved script enqueue logic to load CryptoJS **only when encryption is enabled**
- Optimized hook processing to avoid unnecessary filtering for excluded roles and unsupported post types
- Ensured clean fallback behavior when mixed protection settings are enabled

= 1.1 – August 16, 2025 =
- Changed minimum WordPress requirement to 5.7 to leverage wp_get_inline_script_tag for safer inline script output
- Replaced wp_add_inline_script with direct inline <script> injection for guaranteed execution across all single post pages
- Added inline script nonce/type support via wp_get_inline_script_tag for enhanced security
- Ensured encrypted payload is always available early in content, even if certain script handles are missing
- Added CustomEvent trigger (init-content-payload-ready) to allow frontend scripts to react when encrypted content is ready
- Prevented duplicate inline script injection when content filters run multiple times

= 1.0 – July 23, 2025 =
- Initial release
- JavaScript-based content protection (block copy, right-click, print, DevTools)
- Full AES-256 content encryption with CryptoJS decryption
- Invisible keyword cloaking via ::before and randomized CSS class
- Random noise injection (hidden spans) to confuse crawlers
- Supports multiple post types (customizable)
- Custom encryption key per site
- Custom content selector for JS targeting
- Fallback styling compatible with light/dark themes
- Modular settings page with sanitize and validation

== Source Code ==

This plugin uses [CryptoJS](https://github.com/brix/crypto-js) for encryption.  
- Minified version: `assets/js/crypto-js.min.js`  
- Source version: [GitHub Repo](https://github.com/brix/crypto-js)

== License ==

This plugin is licensed under the GPLv2 or later.  
You are free to use, modify, and distribute it under the same license.
