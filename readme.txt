=== Init Content Protector – Anti-Copy, Anti-Scrape, Encrypt-All ===
Contributors: brokensmile.2103
Tags: content protection, anti-copy, copy protection, encryption, anti-scraping
Requires at least: 5.7
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect content from copying, scraping and inspection with JS blocking, keyword cloaking, noise injection, optional encryption or lazy loading.

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
  - Optional Headless Browser Detection: withholds decryption from sessions that look like Puppeteer/Playwright/Selenium, scored across several client-side automation signals so no single false-positive blocks a real visitor; verified search engine crawlers (Googlebot, Bingbot...) are let through by reverse DNS check
- The WordPress REST API (`/wp-json/wp/v2/…`) and the RSS/Atom feeds no longer hand out the content of protected posts while Encrypt or Lazy Load is on: a short message replaces it
- Lazy Load mode (lighter than encryption): the page is sent without the article, and a script fetches it through the REST API only after the visitor's browser has been verified — with a Retry button if the check fails
  - Stateless verification (short-lived signed token with a minimum wait, nothing stored in the database) that works behind full-page caches
  - The browser-side verification module (`window.InitContentVerify`) and its PHP counterpart are reusable by other code
- Keyword cloaking using CSS pseudo-elements (whole words only, Unicode aware, never inside HTML attributes or code blocks)
- Invisible noise injection to confuse crawlers — class names are generated for each post and rotate daily, the hiding rules are printed in the page itself, and look-alike decoy elements wrap some real words, so noise cannot be stripped with a fixed list; only ever injected into plain text, never inside HTML tags or inside `<script>`, `<style>`, `<pre>`, `<select>`, `<svg>`, and similar elements; injection rate is configurable (1–50%)
- Automatic AMP detection — protection is skipped on AMP endpoints instead of producing invalid markup
- Per-post type configuration, protecting either every post of those types or only the posts you switch on (editor box and bulk actions)
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
If you enable full content encryption, search engines that do not run JavaScript will not be able to see the content, and even those that do (such as Google) may not index it reliably. Only use this option if SEO visibility is not required, and test your pages (for example with the URL Inspection tool in Google Search Console) before deploying.

= Does this plugin support custom post types? =
Yes. You can choose which post types are protected in the settings page.

= Can I protect only some posts instead of every post of a post type? =
Yes. Since 1.10, set **Protection Scope** to **Only posts where protection is turned on**. Nothing is protected until you switch a post on: tick **Protect this post** in the **Content Protection** box of the post editor (classic and block editor), or select several posts on the post list and use the **Turn content protection on** bulk action. A lock column on the post list shows which posts are protected. Only the post types chosen under **Apply Protection to Post Types** get the box.

In this mode every layer follows the switch: encryption, noise and keyword cloaking apply to switched-on posts only, and JavaScript Content Protection, Advanced DevTools Blocking and Anti-Screenshot Protection only load on those posts instead of on every page of the site. The switches are kept when you change the scope back, so you can move between both modes freely.

Developers can decide per post with the `init_plugin_suite_content_protector_is_protected_post` filter.

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

= How does noise injection work since 1.10? =
Up to 1.9 every site used the same fixed list of noise class names, so a scraper could remove the noise once with that public list. Since 1.10:

* Each protected post gets its own generated class names, which look like ordinary theme classes and change every day. They are derived from your site's secret keys, so they cannot be predicted.
* The rules that hide them are printed inline in the page itself, written in mixed selector forms and shuffled together with harmless rules.
* Some real words are wrapped in visible look-alike elements with the same kind of class names, and noise elements carry those classes too. Removing every element with an unknown class removes real text as well; the only way to tell noise from text is to evaluate the page's CSS.

Readers see exactly the same text as before. Developers can change how long class names stay the same with the `init_plugin_suite_content_protector_noise_rotation` filter (seconds, default one day, `0` = new names on every request; a fixed period keeps page caches and CSS optimizers stable).

= Why does the post excerpt or meta description show the real text? =
WordPress builds automatic excerpts by running the post content through the same filter that applies protection, and SEO plugins use that excerpt (or run the content inside `<head>`) for the meta description and social cards. Up to 1.9 those got the "Please enable JavaScript" notice in Encrypt mode, or the hidden noise words and missing keywords. Since 1.10 protection is only applied to the article body itself. To protect those calls too, return `false` from the `init_plugin_suite_content_protector_skip_secondary_content` filter.

= What's the difference between "Enable JavaScript Content Protection" and the new "Advanced DevTools Blocking" / "Anti-Screenshot Protection" options? =
"Enable JavaScript Content Protection" is the plugin's original, lightweight protection: it blocks common keyboard shortcuts, right-click, text selection, and printing. "Advanced DevTools Blocking" adds a dedicated third-party detection library (`disable-devtool`) that recognizes DevTools being opened through several different methods beyond keyboard shortcuts, and reacts by closing or redirecting the tab. "Anti-Screenshot Protection" (Init AntiSnap) is a separate heuristic that watches for scroll-jump and layout-shift patterns typical of automated screenshot/scraping tools and briefly blurs the page instead. All three are independent — enable any combination that fits your site.

= Will Advanced DevTools Blocking or Anti-Screenshot Protection ever affect real visitors? =
They're designed not to, but both are heuristic and best-effort like every other protection layer in this plugin. Advanced DevTools Blocking only reacts when it detects an open DevTools panel; Anti-Screenshot Protection only reacts to repeated, evenly spaced scroll steps made without any real input from the visitor, or DevTools-style viewport changes, and its blur effect clears itself automatically after a few seconds or as soon as the tab regains focus. Neither is enabled by default.

= What does Headless Browser Detection do, and when should I use it? =
It's aimed at automated scraping tools built on Puppeteer, Playwright, or Selenium — the kind that run a real browser engine and read the DOM after your page renders, which the plugin's other protections (encryption, noise, keyword cloaking) can't stop on their own once the page is decrypted for them too. When enabled, it scores several client-side automation signals together (no single signal decides on its own) and, if the session looks automated, simply never triggers decryption — the content stays on its loading skeleton instead of appearing in the DOM. It only takes effect when Content Protection Mode is set to Encrypt, and it's off by default. Like every automation signal, well-configured "stealth" tooling can evade individual checks — this raises the cost of scraping, it doesn't guarantee blocking it.

= Does it also protect the WordPress REST API and the feeds? =
Yes, since 1.11. The same goes for the listings (home page, archives, search results) when your theme prints the full content there; they are protected like a single post. Theme excerpts and SEO descriptions are left as they are on purpose (a short teaser, used by search engines and social cards; the `init_plugin_suite_content_protector_skip_secondary_content` filter changes that). Not covered: AMP pages (AMP does not allow the scripts this plugin needs, so the protection is switched off there), and endpoints that other plugins register themselves. With Encrypt Content or Lazy Load on, every REST endpoint that renders post content (`/wp-json/wp/v2/posts`, pages, custom post types, revisions and autosaves) returns a short message instead of `content.rendered`, and instead of an automatic excerpt (a manual excerpt is left as is). Users who can edit the post and the excluded roles still get the real content, and password-protected posts are not changed. Apps, headless front ends and plugins that read the article through the REST API will get the message; use the `init_plugin_suite_content_protector_rest_hide_content` filter to let a request through, and `init_plugin_suite_content_protector_rest_content_message` to change the text. The RSS/Atom feeds follow the same rule, and their excerpt (description) is replaced as well, a manual excerpt included.

= What is Lazy Load mode, and how is it different from Encrypt? =
Lazy Load (new in 1.11) is the lighter way to keep your article out of the page. The HTML is sent with a loading skeleton only. After the page has loaded, a script verifies the browser and fetches the article through the REST API, then puts it in place. Nothing is encrypted, so there is no decryption key and no Web Crypto or CryptoJS involved, and the page itself is the same for every visitor, so it caches well.

It stops anything that only reads the HTML, such as wget, curl or a basic scraper, and crawlers that do not run JavaScript. If the check fails (a network error, for example) the visitor sees a message with a **Retry** button instead of an endless skeleton. Keyword cloaking and noise injection still apply: their CSS is delivered together with the content, so it always matches. Like Encrypt mode it hides the content from search engines that do not run JavaScript; see the SEO question above, and test with Search Console.

Scripts inside the article are not executed (as in Encrypt mode). Plugins that need the real text can listen for the `init-content-decrypted` event, which Lazy Load fires too (`event.detail.mode` is `lazy`).

= What does "verified" mean in Lazy Load mode? =
Before the article is sent, the request must pass these checks: it comes from a page of your site (the page's REST nonce, or the browser's `Sec-Fetch-Site: same-origin` header, which is also what makes pages served from a full-page cache work after the nonce has expired, or a search engine crawler confirmed by reverse DNS); the Headless Browser Detection option, if enabled, did not flag the session; and the browser holds a short-lived token that was issued at least a second earlier. The token is signed with your site's secret keys, bound to one post and valid for 5 minutes, so nothing is stored in your database.

This is a deterrent, not a captcha: a script written specifically against your site can still do the same steps. Like the rest of the plugin it raises the cost of scraping and cannot stop a determined visitor. The route is not rate-limited by the plugin, for the same reason as the Enhanced key route.

= Can I reuse the verification module in my own code? =
Yes. In the browser, `window.InitContentVerify` offers `request( path, options )` (a same-site REST call that sends the nonce and retries once without it when the nonce has expired in a cached page), `getToken( postId )`, `gate()` (the Headless Browser Detection result), `showNotice( element, kind, onRetry )` (the message with the Retry button), `render( element, html, css )` and more. Enhanced key delivery and Lazy Load both use it. In PHP, `init_plugin_suite_content_protector_verify_issue_token( $scope, $subject )` and `init_plugin_suite_content_protector_verify_check_token( $token, $scope, $subject )` issue and check the signed tokens, and `init_plugin_suite_content_protector_verify_request( $request, $settings )` runs the same-site check on a REST request. Developers can change the minimum wait and the lifetime of a token with the `init_plugin_suite_content_protector_verify_min_age` (default 1 second) and `init_plugin_suite_content_protector_verify_ttl` (default 300 seconds) filters.

= Does Encrypt mode still need CryptoJS? =
Not on HTTPS sites. Since 1.7 decryption uses the browser's built-in Web Crypto API, which is much faster and adds no extra download. The bundled CryptoJS file (about 60 KB) is only loaded on sites not served over HTTPS (where browsers disable Web Crypto), or on demand in the rare browser without Web Crypto support. Developers can force it either way with the `init_plugin_suite_content_protector_load_cryptojs` filter.

= Does Headless Browser Detection block Googlebot? =
It did before 1.9: Googlebot renders pages with a headless Chromium, so it can score as automation and was then only shown the loading skeleton. Since 1.9 the option **Allow Verified Search Engine Crawlers** (on by default) lets real search engine crawlers through. A session that looks like automation first asks your server whether the request comes from a verified crawler. The server only answers yes after a reverse DNS lookup of the request IP lands on the operator's domain (for example `googlebot.com` or `search.msn.com`) and a forward lookup of that name returns the same IP, the method Google and Bing document. The User-Agent alone is never trusted, so scrapers pretending to be Googlebot are still blocked. Supported: Google, Bing, Apple, Yandex and Baidu.

Things to check if a crawler is still blocked:

* The plugin must see the real visitor IP, not the address of your CDN or proxy. Under **Visitor IP Detection** the default, Automatic, reads `CF-Connecting-IP` only when the connection really comes from Cloudflare's published IP ranges, and `X-Forwarded-For` / `X-Real-IP` only when it comes from a proxy or load balancer on a private network, so a visitor cannot forge it. The line **IP address the plugin sees for you** shows what is detected and should be your own public IP. If it shows a CDN or proxy address instead, pick the matching option (Cloudflare, or a proxy header such as `X-Forwarded-For`). The proxy header options trust that header completely: only use one when your server accepts traffic from that proxy alone.

* The REST route `/wp-json/init-content-protector/v1/crawler` must be reachable for crawlers: not blocked in robots.txt, and not restricted to logged-in users by a security plugin. If the check cannot be completed, decryption stays withheld.
* Verification needs DNS lookups on your server. They only run when the User-Agent claims to be a known crawler, and a verified IP is remembered for a day.

Developers can adjust it with the `init_plugin_suite_content_protector_verified_crawlers` (crawlers and their reverse DNS domains), `init_plugin_suite_content_protector_pre_crawler_dns_check` (for example to compare against published IP ranges) and `init_plugin_suite_content_protector_is_verified_crawler` filters. Verification only matters when Headless Browser Detection is enabled in Encrypt mode.

= Does the loading skeleton support dark mode? =
Yes, since 1.10. Under **Loading Skeleton Color Scheme** choose **Light** (default), **Dark**, or **Automatic**, which follows the visitor's system light/dark setting (only choose it if your theme does the same). The scheme also applies to the "enable JavaScript" notice.

If your theme has its own dark mode switch, every color is a CSS custom property you can set on the `.icp-protected` wrapper, for example:

    html.dark .icp-protected {
        --icp-skeleton-bg: #2b2f36;
        --icp-skeleton-shine: rgba(255, 255, 255, 0.06);
    }

Available properties: `--icp-skeleton-bg`, `--icp-skeleton-shine`, `--icp-skeleton-radius`, `--icp-skeleton-height`, `--icp-skeleton-gap`, `--icp-noscript-bg`, `--icp-noscript-border`, `--icp-noscript-color`. Your rules always win over the built-in schemes. Developers can also pick the scheme per request or per post in PHP with the `init_plugin_suite_content_protector_skeleton_scheme` filter (return `light`, `dark` or `auto`), for example from the theme's dark mode cookie:

    add_filter( 'init_plugin_suite_content_protector_skeleton_scheme', function ( $scheme ) {
        return ( isset( $_COOKIE['theme_mode'] ) && 'dark' === $_COOKIE['theme_mode'] ) ? 'dark' : $scheme;
    } );

Note: when the page is served from a full-page cache, a scheme picked per visitor in PHP is cached too; the CSS approach works with any cache.

= Is there a JavaScript event for when encrypted content has been decrypted? =
Yes. In Encrypt mode the article first shows a loading skeleton, so scripts that scan the page on load (search term highlighting, table of contents, reading progress, lightboxes) run before the real text exists. After a block has been successfully decrypted and inserted into the page, the plugin fires an `init-content-decrypted` event. It works the same for Inline and Enhanced (REST API) key delivery, and it is never fired when decryption fails or is withheld (for example by Headless Browser Detection).

The event is dispatched on the element that now holds the content and bubbles, so you can listen on `document` or `window`. `event.detail.element` is the element holding the decrypted content and `event.detail.postId` is the post ID. It fires once per protected block, so register your listener early (when your script loads) rather than after the page has finished.

    document.addEventListener( 'init-content-decrypted', function ( event ) {
        var container = event.detail.element;
        // Re-run your feature on container here.
    } );

For example, the Highlight Search Terms plugin can be told to run again on this event with its own `hlst_events` filter:

    add_filter( 'hlst_events', function ( $events ) {
        $events[] = 'init-content-decrypted';
        return $events;
    } );

There is also an earlier `init-content-payload-ready` event (on `window`) that fires when the encrypted payload has been found, before decryption.

= My theme or reader app scrolls the page by itself. Will Anti-Screenshot Protection react? =
Init AntiSnap 6.0 only reacts to repeated, equally sized scroll steps made by script while the visitor is not touching the page. If your own code does that (an automatic page-turn feature, for example), call `InitAntiSnap.trust(ms)` before scrolling, or `InitAntiSnap.pause()` / `InitAntiSnap.resume()` around it.

== Screenshots ==

1. **Content Protection** – Post types, protection scope (all posts or only switched-on posts), encryption or lazy load mode, loading skeleton color scheme, encryption key, key delivery, headless browser detection, verified search engine crawlers, visitor IP detection, content selector with auto-detect, and JavaScript content protection.
2. **Advanced Browser Protection (Experimental)** – Advanced DevTools blocking, anti-screenshot protection (Init AntiSnap), noise injection and its rate, and sensitive keywords to obscure.
3. **Global Exceptions** – User roles excluded from every protection layer, and important notes on using the plugin.

== Changelog ==

= 1.11 – November 25, 2026 =
- New: **Lazy Load** option for Content Protection Mode, a lighter alternative to Encrypt Content. The page is sent without the article, and a script fetches it through the REST API once the visitor's browser has been verified. Nothing is encrypted and no key is involved. Works with Headless Browser Detection and verified search engine crawlers, keyword cloaking and noise injection (their CSS is delivered together with the content, so the rotating noise class names can never mismatch a cached page), multi-page posts and the `<!--more-->` tag. Password-protected posts keep their password form.
- Fixed: while Encrypt Content or Lazy Load is on, the WordPress REST API returned the full article (`content.rendered`) of protected posts through `/wp-json/wp/v2/posts/<id>` and its siblings, bypassing every other layer. Every REST endpoint that renders post content (posts, pages, custom post types, revisions, autosaves) now returns a short message instead of the content, and of an automatic excerpt (a manual excerpt is left alone). Users who can edit the post, and the excluded roles, still get the real content; password-protected posts are unchanged. A warning about it was added to Content Protection Mode in the settings.
- Fixed: RSS and Atom feeds published the full text and the excerpt (`<description>` / Atom `<summary>`, which the "Summary" feed setting prints and which holds the whole article of a short post) of protected posts. Both now carry a short message instead, a manual excerpt included.
- Fixed: a theme that prints the full content in a listing (home page, archives, search results) showed protected posts unprotected, because only single views were protected. Listings are now protected the same way (the visible placeholder is filled in by the same script). Theme excerpts are unchanged on purpose.
- New filters: `init_plugin_suite_content_protector_rest_hide_content` (opt out per post or request) and `init_plugin_suite_content_protector_rest_content_message`.
- New: browser verification module. A stateless, HMAC-signed token (bound to one post, valid for 5 minutes, usable after a short wait) is issued by the new REST route `/wp-json/init-content-protector/v1/verify` and required by `/wp-json/init-content-protector/v1/content/<id>`. Nothing is stored, so there are no new transients or options rows, and it works behind full-page caches. The browser side is `window.InitContentVerify` (`assets/js/verify.js`) and the server side is `includes/verify.php`; both can be reused by other code.
- New: when the content cannot be loaded, the loading skeleton is replaced by a message with a **Retry** button (also for Enhanced key delivery in Encrypt mode). A session withheld by Headless Browser Detection gets a message without a button in Lazy Load mode.
- New: the `init-content-decrypted` event is also fired by Lazy Load, and `event.detail.mode` tells `encrypt` or `lazy`. `init-content-payload-ready` is fired for Lazy Load placeholders too.
- Fixed: Enhanced key delivery could leave a page served from a full-page cache on the loading skeleton once its REST nonce had expired. WordPress rejects an expired nonce before the plugin sees the request, and the retry in 1.7–1.10 still sent it. The shared module now retries without the nonce, and the same-site check accepts the request.
- Headless Browser Detection and Allow Verified Search Engine Crawlers now also cover Lazy Load mode.
- The skeleton stylesheet is also loaded in Lazy Load mode, and stops the shimmer when loading has failed.
- New filters: `init_plugin_suite_content_protector_verify_min_age`, `init_plugin_suite_content_protector_verify_ttl`.
- Updated Vietnamese translation.

= 1.10 – November 20, 2026 =
- New: **Protection Scope** setting. Choose **Only posts where protection is turned on** to protect nothing by default and switch protection on per post, with a **Content Protection** box in the post editor (classic and block editor) for the selected post types, **Turn content protection on / off** bulk actions and a lock column on the post list. In this mode JavaScript Content Protection, Advanced DevTools Blocking and Anti-Screenshot Protection also only load on protected posts. The default scope, all posts of the selected post types, works exactly as before.
- New: noise injection no longer uses a fixed list of class names. Every protected post gets its own generated class names that rotate daily and look like ordinary theme classes, and the rules that hide them are printed inline in the page (mixed selector forms, shuffled with harmless rules). Visible look-alike elements now wrap some real words, and noise elements carry the same decoy classes, so scrapers can no longer strip noise with a fixed list or by removing unknown elements. More element types are used for noise, and the `aria-hidden` attribute that made noise easy to spot was removed (noise is `display: none`, which already hides it from screen readers). Readers see exactly the same text as before.
- New: dark mode for the loading skeleton and the "enable JavaScript" notice. New setting **Loading Skeleton Color Scheme** (Light, Dark, or Automatic following the visitor's system setting). Every color is now a CSS custom property (`--icp-skeleton-bg`, `--icp-skeleton-shine`, `--icp-noscript-bg`...) that themes can set on `.icp-protected` for their own dark mode switch, and the new `init_plugin_suite_content_protector_skeleton_scheme` filter picks the scheme in PHP.
- The stylesheet `style.css` is now only loaded in Encrypt mode (loading skeleton), and the old fixed noise class rules were removed from it (about 100 selectors). Purge your page/CDN cache after updating so no page built by 1.9 is still served.
- Fixed: automatic excerpts and SEO meta descriptions built from the post content showed the "Please enable JavaScript" notice in Encrypt mode, or contained the hidden noise words and lost cloaked keywords. Protection is now skipped when the content is run for an excerpt or inside `<head>`; the article body is protected as before. New filter: `init_plugin_suite_content_protector_skip_secondary_content`.
- Fixed (1.9): Enhanced key delivery could refuse a verified search engine crawler that rendered a cached page with an expired nonce, leaving it on the loading skeleton. A crawler verified by reverse DNS is now accepted too.
- Fixed (1.9): without a persistent object cache, failed crawler checks were not remembered, so a bot sending a fake Googlebot User-Agent made the server repeat slow DNS lookups on every request. Failures are now remembered for 10 minutes in a single size-capped transient (one database row at most).
- Fixed (1.9): an invalid IP range added through the `init_plugin_suite_content_protector_cloudflare_ranges` filter printed PHP warnings on every request.
- Fixed: saving the settings removed backslashes from the encryption key, keywords and content selector (the values were unslashed twice).
- Fixed (1.9): several files had mixed line endings; every file now uses CRLF.
- Performance: settings, keyword lists and keyword matchers are memoized per request instead of being rebuilt for every enqueue callback and every content call.
- New filters: `init_plugin_suite_content_protector_is_protected_post`, `init_plugin_suite_content_protector_noise_rotation`, `init_plugin_suite_content_protector_skip_secondary_content`, `init_plugin_suite_content_protector_skeleton_scheme`.
- Uninstall also removes the per-post switches and the failed crawler check list.
- Updated Vietnamese translation.

= 1.9 – November 18, 2026 =
- Fixed: Headless Browser Detection blocked Googlebot. Googlebot renders pages with a headless Chromium, so it scored as automation, never got the decrypted content and only saw the loading skeleton (visible in Google Search Console and the Rich Results Test). New option **Allow Verified Search Engine Crawlers** (on by default, also for existing sites) lets real crawlers through.
- Crawlers are verified with a forward-confirmed reverse DNS lookup, the method Google and Bing document, never by User-Agent alone, so scrapers pretending to be Googlebot are still blocked. Supported: Google, Bing, Apple, Yandex, Baidu. `googleusercontent.com` (rentable Google Cloud addresses) is deliberately not accepted.
- Only a session that already scores as automation asks the server, through the new REST route `/wp-json/init-content-protector/v1/crawler`. The answer is never cached, so it works behind full-page caches and CDNs. Any failure (REST blocked, timeout, invalid answer) keeps decryption withheld.
- DNS lookups only run when the User-Agent claims to be a known crawler. A verified IP is remembered for a day; failed checks are kept in the object cache only, so fake crawlers from many IPs cannot bloat the options table.
- New setting **Visitor IP Detection** for sites behind Cloudflare or another proxy, where the server would otherwise only see the proxy's address. Automatic (default) reads `CF-Connecting-IP` only when the connection comes from Cloudflare's published IP ranges, and `X-Forwarded-For` / `X-Real-IP` only when it comes from a proxy on a private network, so a visitor cannot forge them. You can also pick Direct connection, Cloudflare, or a proxy header (`X-Forwarded-For`, `X-Real-IP`, `True-Client-IP`, `Fastly-Client-IP`). The settings page shows the IP address the plugin currently sees for you.
- New filters: `init_plugin_suite_content_protector_verified_crawlers`, `init_plugin_suite_content_protector_client_ip`, `init_plugin_suite_content_protector_cloudflare_ranges`, `init_plugin_suite_content_protector_pre_crawler_dns_check`, `init_plugin_suite_content_protector_is_verified_crawler`.
- Fixed: saving the settings without a Content Protection Mode or Key Delivery value raised PHP "undefined array key" notices.
- The FAQ about SEO now says that crawlers which run JavaScript may still not index encrypted content reliably, and to test with Search Console.
- Uninstall also removes the verified crawler transients.

= 1.8 – November 17, 2026 =
- The `init-content-decrypted` JavaScript event is now documented (see the FAQ) and easier to listen for. It is dispatched on the element that now holds the content and bubbles up, so listeners on that element, on `document` (including `jQuery( document ).on()` and plugins that watch DOM events on the document) and on `window` all receive it. In 1.7 it was only dispatched on `window`, which `document` listeners never saw. Works the same for Inline and Enhanced (REST API) key delivery.
- The event now carries `event.detail.element` (the element holding the decrypted content) and the new `event.detail.postId`.
- The event is only fired after decryption succeeded and the content is in the page. If the placeholder was removed from the page before the content arrived, nothing is inserted and no event is fired (1.7 fired it with an empty element).
- Compatibility: other plugins and scripts that need to run on the article text (search term highlighting, table of contents, reading progress, lightboxes) can now re-run once the real content exists, without a MutationObserver.
- Removed the `Tested up to` line from the plugin file header (the value is read from this readme).

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
