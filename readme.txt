=== Init Content Protector – Anti-Copy, Anti-Scrape, Encrypt-All ===
Contributors: brokensmile.2103
Tags: content protection, anti-copy, copy protection, encryption, anti-scraping
Requires at least: 5.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect your content from copying, scraping, and inspection using JS blocking, keyword cloaking, noise injection, and optional full encryption.

== Description ==

**Init Content Protector** is a powerful yet lightweight plugin that safeguards your post content from unauthorized copying, scraping tools, and inspection via browser developer tools.

This plugin is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) — a collection of minimalist, fast, and developer-focused tools for WordPress.

GitHub repository: [https://github.com/brokensmile2103/init-content-protector](https://github.com/brokensmile2103/init-content-protector)

**Features:**
- JavaScript-based copy protection (blocks selection, right-click, print, DevTools access)
- Advanced DevTools blocking (optional, powered by the third-party `disable-devtool` library) — detects DevTools being opened through multiple methods, not just keyboard shortcuts, and closes or redirects away from the tab
- Anti-screenshot / anti-scraping-tool detection (optional, powered by "Init AntiSnap 6.0") — recognizes the evenly spaced, step-by-step scrolling of scroll-and-stitch capture tools and DevTools full-size captures, and briefly blurs the page with a warning
- Full AES-256 content encryption with client-side decryption via the browser's built-in Web Crypto API (CryptoJS is only loaded on sites not served over HTTPS, or on demand as a fallback)
  - Inline delivery (default, simple) or Enhanced delivery (fetches the key via a REST API endpoint after page load — keeps it out of cached/static HTML, cache-plugin friendly)
  - Encryption is done fresh on every request (about a third of a millisecond), so members-only or per-user content can never be served to the wrong visitor from a cache
  - Fails open gracefully on hosts missing OpenSSL/PBKDF2 support, instead of breaking the page
  - Optional Headless Browser Detection: withholds decryption from sessions that look like Puppeteer/Playwright/Selenium, scored across several client-side automation signals so no single false-positive blocks a real visitor
- Keyword cloaking using CSS pseudo-elements (whole words only, Unicode aware, never inside HTML attributes or code blocks)
- Invisible noise injection to confuse crawlers — only ever injected into plain text, never inside HTML tags or inside `<script>`, `<style>`, `<pre>`, `<select>`, `<svg>`, and similar elements; injection rate is configurable (1–50%)
- Automatic AMP detection — protection is skipped on AMP endpoints instead of producing invalid markup
- Per-post type configuration
- Excluded user roles (protection never applies to selected roles)
- Custom encryption key per site
- Custom content selector support, with an Auto-detect button in settings

Use this plugin to harden your site's content visibility while maintaining a smooth reading experience for real users. Its goal is to deter casual bots and crawlers — not to stop a determined human, since anyone who can see content can always screenshot or retype it.

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

= What's the difference between "Inline" and "Enhanced" key delivery? =
Inline embeds the decryption key directly in page HTML — simple and works everywhere, but readable via view-source. Enhanced fetches the key from a REST API endpoint after page load instead, keeping it out of cached/static HTML and working cleanly with full-page cache plugins. Neither mode makes content truly secret from a visitor running the page's own JavaScript — both are meant to raise the bar for casual scrapers, not stop a determined human.

= Is the "Enhanced" REST API endpoint rate-limited? =
No, intentionally. Rate-limiting by visitor IP would mean storing one database row per unique IP with no automatic cleanup — on a busy or bot-scanned site that bloats the database worse than the scraping it aims to prevent. If you need rate limiting, apply it at your server, CDN, or WAF layer.

= Does this work with AMP? =
The plugin automatically detects AMP endpoints and skips all protection there (JS injection, encryption, noise), since AMP doesn't allow the custom scripts these features rely on.

= Can I control how much noise is injected? =
Yes, via the "Noise Injection Rate" setting (1–50% per word, default 7%). Noise is only ever inserted into plain text — never inside HTML tags or inside elements like `<script>`, `<style>`, `<select>`, or `<svg>` — so it can't corrupt markup.

= What's the difference between "Enable JavaScript Content Protection" and the new "Advanced DevTools Blocking" / "Anti-Screenshot Protection" options? =
"Enable JavaScript Content Protection" is the plugin's original, lightweight protection: it blocks common keyboard shortcuts, right-click, text selection, and printing. "Advanced DevTools Blocking" adds a dedicated third-party detection library (`disable-devtool`) that recognizes DevTools being opened through several different methods beyond keyboard shortcuts, and reacts by closing or redirecting the tab. "Anti-Screenshot Protection" (Init AntiSnap) is a separate heuristic that watches for scroll-jump and layout-shift patterns typical of automated screenshot/scraping tools and briefly blurs the page instead. All three are independent — enable any combination that fits your site.

= Will Advanced DevTools Blocking or Anti-Screenshot Protection ever affect real visitors? =
They're designed not to, but both are heuristic and best-effort like every other protection layer in this plugin. Advanced DevTools Blocking only reacts when it detects an open DevTools panel; Anti-Screenshot Protection only reacts to repeated, evenly spaced scroll steps made without any real input from the visitor, or DevTools-style viewport changes, and its blur effect clears itself automatically after a few seconds or as soon as the tab regains focus. Neither is enabled by default.

= What does Headless Browser Detection do, and when should I use it? =
It's aimed at automated scraping tools built on Puppeteer, Playwright, or Selenium — the kind that run a real browser engine and read the DOM after your page renders, which the plugin's other protections (encryption, noise, keyword cloaking) can't stop on their own once the page is decrypted for them too. When enabled, it scores several client-side automation signals together (no single signal decides on its own) and, if the session looks automated, simply never triggers decryption — the content stays on its loading skeleton instead of appearing in the DOM. It only takes effect when Content Protection Mode is set to Encrypt, and it's off by default. Like every automation signal, well-configured "stealth" tooling can evade individual checks — this raises the cost of scraping, it doesn't guarantee blocking it.

= Does Encrypt mode still need CryptoJS? =
Not on HTTPS sites. Since 1.7 decryption uses the browser's built-in Web Crypto API, which is much faster and adds no extra download. The bundled CryptoJS file (about 60 KB) is only loaded on sites not served over HTTPS (where browsers disable Web Crypto), or on demand in the rare browser without Web Crypto support. Developers can force it either way with the `init_plugin_suite_content_protector_load_cryptojs` filter.

= My theme or reader app scrolls the page by itself. Will Anti-Screenshot Protection react? =
Init AntiSnap 6.0 only reacts to repeated, equally sized scroll steps made by script while the visitor is not touching the page. If your own code does that (an automatic page-turn feature, for example), call `InitAntiSnap.trust(ms)` before scrolling, or `InitAntiSnap.pause()` / `InitAntiSnap.resume()` around it.

== Screenshots ==

1. **Settings Page** – Configure protection methods, encryption, keyword cloaking, and per-post type options.

== Changelog ==

= 1.7 – September 26, 2026 =
- Encrypt mode now decrypts with the browser's built-in Web Crypto API by default — about 78x faster than CryptoJS on a long article. CryptoJS is only loaded on sites not served over HTTPS, or on demand when Web Crypto is unavailable or fails. New filter: `init_plugin_suite_content_protector_load_cryptojs`.
- Removed the per-post transient cache of encrypted content. It could serve members-only or per-user content to the wrong visitor, went stale after changing settings, and cost two database queries per view. Encryption is now done per request (about 0.3 ms thanks to a memoized key). Leftover transients are removed on uninstall.
- Fixed: if a theme or plugin called `the_content` early (SEO meta, excerpts), the encrypted payload could be printed there and never reach the real content area.
- Fixed: Enhanced key delivery failed on fully cached pages once the cached REST nonce expired. Same-origin requests are now accepted as well.
- Encrypted output now has a noscript notice and can hold several protected blocks on one page. New JavaScript event `init-content-decrypted`. New filter: `init_plugin_suite_content_protector_decrypt_delay`.
- Keyword cloaking and noise injection rewritten around one shared HTML tokenizer: keywords no longer break `alt`, `href` or other attributes, a keyword such as "class" can no longer corrupt the generated markup, keywords differing only in letter case get separate classes, the longest keyword wins, and noise never lands inside SVG text. About 1.7x faster.
- Noise CSS and decryption scripts are only loaded on protected single views instead of every page.
- JavaScript Content Protection: fixed a leak that added a new stylesheet and two listeners on every window focus/blur; form fields, buttons and media controls inside the content keep working; added macOS shortcuts; copy is also blocked when a selection reaches into the content from outside; content added later (infinite scroll) is protected too.
- Advanced DevTools Blocking: a detection on the homepage itself now hides the page instead of redirecting to it, so a false detection can no longer cause an endless reload loop.
- Headless Browser Detection: no longer flags Linux desktops using Mesa GPU drivers or in-app browsers (Android WebView, Facebook, Zalo, Instagram...); detects the HeadlessChrome user agent and Playwright / Selenium IDE leftovers; heavier checks no longer block page scripts.
- Init AntiSnap 6.0: detects the rhythm of scroll-and-stitch capture tools (equal steps, same direction, repeated) instead of single jumps. No longer triggered by find-in-page, anchor links, dragging or clicking the scrollbar, scroll restoration, zooming, or pop-ups that lock page scrolling — while now also catching tools that scroll smoothly and tools that resume after the warning. The blur no longer overwrites inline styles on `<body>`. All 5.x config keys are unchanged; new `window.InitAntiSnap` API (`trust`, `pause`, `resume`, `isDefending`, `destroy`). Theme-provided `window.InitAntiSnapConfig` values now take precedence over the plugin's defaults.
- Full WordPress Coding Standards pass, escaped settings link, stricter settings sanitizing, prefers-reduced-motion support for the loading skeleton.
- Updated Vietnamese translation.

= 1.6 – August 28, 2026 =
- Added an optional "Enable Headless Browser Detection" setting for Encrypt mode. Runs a first-party client-side check for automation tooling (Puppeteer, Playwright, Selenium/WebDriver) — cumulative signals such as `navigator.webdriver`, leftover CDP/WebDriver artifacts, a software WebGL renderer, a Permissions API inconsistency, a webdriver leak through a dynamically-created iframe, and naive `navigator.webdriver` patch artifacts — and, if the session looks automated, simply withholds decryption instead of ever decrypting real content into the DOM for that session.
- Scoring requires at least two signals together before triggering (no single flag, however strong, decides on its own), specifically to keep false positives low for real visitors on unusual or privacy-hardened browsers.
- Only takes effect when Content Protection Mode is set to Encrypt — has no effect in "No Protection" mode, since the real content is already present in the initial HTML response before this (or any) JavaScript can run. Off by default.
- Like Advanced DevTools Blocking and Anti-Screenshot Protection, this raises the cost of automated scraping rather than guaranteeing to block it: well-configured "stealth" automation setups can evade individual signals.
- Added `.pot`/`.po` translations for the new setting. `.mo` intentionally not rebuilt as part of this change.

= 1.5 – August 28, 2026 =
- Added an optional "Enable Advanced DevTools Blocking" setting, powered by the third-party `disable-devtool` library (MIT licensed, vendored in `assets/js/disable-devtool.min.js`). Unlike "Enable JavaScript Content Protection", which only blocks a fixed set of keyboard shortcuts, this actively detects DevTools being opened through several different methods and reacts by closing or redirecting away from the tab. The library's own default fallback URL is the literal string "localhost" (a dead link on a live site), so the plugin overrides it to redirect to the site's homepage instead. Fully independent of "Enable JavaScript Content Protection" — either can be used alone or together. Off by default.
- Added an optional "Enable Anti-Screenshot Protection" setting, powered by "Init AntiSnap" (MIT licensed, vendored as its full, human-readable source in `assets/js/init-antisnap.js`). Watches for scroll-jump and DevTools-triggered viewport/resize patterns typical of automated screenshot and scraping tools, and briefly blurs the page with a translatable warning message when detected; the blur clears itself automatically after a few seconds or as soon as the tab regains focus. Off by default, independent of every other protection layer.
- Both new options are grouped under a new "Advanced Browser Protection (Experimental)" section on the settings page, respect the existing "Exclude User Roles" setting, and are skipped on the admin area and on AMP endpoints — consistent with every other protection layer in the plugin.
- Moved "Exclude User Roles" into its own new "Global Exceptions" section and reworded its description to explicitly list every feature it governs (Content Protection Mode, JavaScript Content Protection, Advanced DevTools Blocking, Anti-Screenshot Protection, Inject Noise, Sensitive Keywords), instead of the old wording that only mentioned "content protection" and could be misread as scoped to a single feature.
- Fixed the keyword-cloaking CSS output (`::before` rules that visually reconstruct hidden keywords) to also respect "Exclude User Roles". Previously it was the only protection layer that ignored this setting, so an excluded role's page still received the keyword-reconstruction CSS even though their content was otherwise left unprotected.
- Fixed a bug where that same CSS output function opened a PHP output buffer (`ob_start()`) before its early-return checks, leaving it unclosed on any front-end request with no keywords configured or no current post (i.e. most requests on most sites — archives, the homepage). Buffering is now only started once there's actually CSS to build.
- Fixed two accessibility issues on the settings page where a `<label for="...">` didn't match any element `id` ("Enable JavaScript Content Protection" and "Inject Noise" checkboxes), which could stop browsers from autofilling correctly and broke the label/control association for assistive tech.
- Added/updated `.pot` and Vietnamese `.po` translations for all strings introduced in this release. `.mo` intentionally not rebuilt as part of this change.

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

This plugin decrypts content with the browser's built-in Web Crypto API. [CryptoJS](https://github.com/brix/crypto-js) is bundled only as a fallback for sites not served over HTTPS or browsers without Web Crypto.  
- Minified version: `assets/js/crypto-js.min.js`  
- Source version: [GitHub Repo](https://github.com/brix/crypto-js)

This plugin optionally uses [disable-devtool](https://github.com/theajack/disable-devtool) (MIT licensed) for the "Enable Advanced DevTools Blocking" setting.
- Minified version: `assets/js/disable-devtool.min.js`
- Source version: [GitHub Repo](https://github.com/theajack/disable-devtool)

This plugin optionally uses "Init AntiSnap 6.0" by Init HTML (MIT licensed) for the "Enable Anti-Screenshot Protection" setting.
- Vendored as its full, human-readable source (not minified): `assets/js/init-antisnap.js`

== License ==

This plugin is licensed under the GPLv2 or later.  
You are free to use, modify, and distribute it under the same license.
