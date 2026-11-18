/**
 * Init Content Protector – Headless Browser Detection Guard
 *
 * Attempts to detect browser-automation tooling (Puppeteer, Playwright,
 * Selenium/WebDriver in general) before encrypted content is decrypted, so a
 * suspicious session never gets the real content injected into the DOM —
 * rather than hiding content after render, which automation ignores anyway
 * since it reads the DOM/network directly.
 *
 * Only meaningful when Content Protection Mode is set to Encrypt (see the
 * "Enable Headless Browser Detection" setting for details/limitations).
 * Scoring is cumulative across several weak signals rather than any single
 * flag, to keep false positives low.
 *
 * Exposes window.InitContentHeadlessCheck — a Promise resolving to true
 * (suspected automation) or false (looks fine). decrypt.js awaits this
 * before decrypting InitContentEncryptedPayload.
 *
 * 1.9:
 * - Search engine crawlers (Googlebot renders with headless Chromium) are no
 *   longer withheld. A session that scores as automation first asks the
 *   server, through the REST route in InitHeadlessDetectData.crawlerUrl,
 *   whether the request comes from a crawler verified by reverse + forward
 *   DNS. Any failure keeps the session suspected (decryption stays withheld).
 *
 * 1.7:
 * - WebGL check no longer flags "Mesa": that is the normal open-source GPU
 *   driver on Linux desktops (e.g. "Mesa Intel(R) UHD Graphics"), not a
 *   software rasterizer. The WebGL context is released after the check.
 * - window.chrome / zero-outer-size checks skip in-app browsers (Android
 *   WebView "; wv)", Facebook, Zalo, Instagram, LINE...), which lack
 *   window.chrome or report 0x0 outer size by design.
 * - New signals: "HeadlessChrome" user agent / client-hint brand, and
 *   Playwright / Selenium IDE leftovers. The "cdc_" ChromeDriver marker is
 *   now looked for on document as well as window.
 * - The heavier checks run just after this script instead of while it is
 *   being parsed; the Promise itself is still created synchronously.
 */

(function () {
    'use strict';

    if (typeof InitHeadlessDetectData === 'undefined') {
        window.InitContentHeadlessCheck = Promise.resolve(false);
        return;
    }

    const CONFIG = {
        // One strong signal (weight 2) alone must never be enough to trip
        // the threshold — always requires at least one extra signal too,
        // to keep the false-positive risk low.
        SCORE_TO_TRIGGER: 3,

        // Max wait for the async Permissions API check before forcing a
        // decision, so decryption can never hang indefinitely.
        PERMISSIONS_CHECK_TIMEOUT_MS: 250,
    };

    let score = 0;

    // Signal 1: navigator.webdriver — the standard WebDriver flag, set to
    // true by most automation frameworks unless stealth-patched. Trivial to
    // spoof, so light weight only.
    function checkWebdriverFlag() {
        return navigator.webdriver === true;
    }

    // Signal 2: empty plugins/languages — weak on its own, since some
    // privacy-hardened real browsers can also trigger this.
    function checkEmptyPluginsAndLanguages() {
        const noPlugins = navigator.plugins && navigator.plugins.length === 0;
        const noLanguages = !navigator.languages || navigator.languages.length === 0;
        return !!(noPlugins && noLanguages);
    }

    // Signal 3: leftover CDP/WebDriver artifacts on window/document —
    // checks both fixed names and the "cdc_" prefix pattern ChromeDriver
    // injects (suffix varies by build). Rarely false-positive when present,
    // but newer drivers/patchright clean it up, so still not enough alone.
    function checkAutomationArtifacts() {
        const knownKeys = [
            '__webdriver_evaluate', '__selenium_evaluate', '__webdriver_script_function',
            '__webdriver_script_func', '__webdriver_script_fn', '__fxdriver_evaluate',
            '__driver_unwrapped', '__webdriver_unwrapped', '__selenium_unwrapped',
            '__fxdriver_unwrapped',
        ];
        for (let i = 0; i < knownKeys.length; i++) {
            if (Object.prototype.hasOwnProperty.call(document, knownKeys[i])) return true;
        }

        if (window.callPhantom || window._phantom || window.__nightmare) return true;
        if (window.domAutomation || window.domAutomationController) return true;

        // Playwright / Selenium IDE leftovers.
        if (window.__pwInitScripts || window.__playwright__binding__ || window.__pwManual
            || window._Selenium_IDE_Recorder || window._selenium || window.calledSelenium) {
            return true;
        }

        // ChromeDriver's "cdc_..." marker (older builds prefix it with "$").
        const cdc = /^\$?cdc_/;
        try {
            for (const key in window) {
                if (cdc.test(key)) return true;
            }
            const docKeys = Object.keys(document);
            for (let i = 0; i < docKeys.length; i++) {
                if (cdc.test(docKeys[i])) return true;
            }
        } catch (e) {
            // Enumeration can throw on exotic hosts; treat as not found.
        }

        return false;
    }

    // In-app browsers (Android WebView, Facebook, Zalo, Instagram, LINE, ...)
    // legitimately lack window.chrome and may report a 0x0 outer size.
    const UA = String(navigator.userAgent || '');
    const IS_IN_APP_WEBVIEW = /; wv\)|FBAN|FBAV|FB_IAB|Zalo|Instagram|Line\/|MicroMessenger|GSA\//i.test(UA);

    // Signal 4: outer window dimensions both zero. Easy to patch, so light
    // weight only.
    function checkZeroOuterDimensions() {
        if (IS_IN_APP_WEBVIEW) return false;
        return window.outerWidth === 0 && window.outerHeight === 0;
    }

    // Signal 4b (heavier weight): headless Chrome announcing itself. Stock
    // headless Chrome and default Puppeteer/Playwright launches send
    // "HeadlessChrome" in the user agent and client-hint brands.
    function checkHeadlessUserAgent() {
        if (/HeadlessChrome/.test(UA)) return true;
        try {
            const brands = navigator.userAgentData && navigator.userAgentData.brands;
            if (brands && brands.length) {
                for (let i = 0; i < brands.length; i++) {
                    if (/HeadlessChrome/i.test(String(brands[i].brand))) return true;
                }
            }
        } catch (e) {
            // ignore
        }
        return false;
    }

    // Signal 5 (heavier weight): WebGL renderer reports a software
    // rasterizer. Spoofing this needs a real GPU or a deep driver-level
    // override — harder than the JS-level patches above.
    function checkSoftwareWebGLRenderer() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (!gl) return false;

            const ext = gl.getExtension('WEBGL_debug_renderer_info');
            if (!ext) return false;

            const renderer = String(gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) || '');

            // Free the context right away: browsers cap live WebGL contexts
            // and evict the oldest one (possibly the theme's) when exceeded.
            const lose = gl.getExtension('WEBGL_lose_context');
            if (lose) lose.loseContext();

            // "Mesa" alone is NOT matched: it is the regular GPU driver on
            // Linux desktops. llvmpipe/softpipe are Mesa's software paths.
            return /swiftshader|llvmpipe|softpipe|software rasterizer/i.test(renderer);
        } catch (e) {
            return false;
        }
    }

    // Signal 6 (heavier weight, async): Permissions API reports 'denied' for
    // notifications on a brand-new session, while Notification.permission —
    // a separate API that's normally in sync with it — still reports
    // 'default'. Rarely patched, since most stealth plugins focus on
    // navigator.* rather than the Permissions API.
    function checkPermissionsInconsistency() {
        return new Promise((resolve) => {
            if (!navigator.permissions || !navigator.permissions.query || typeof Notification === 'undefined') {
                resolve(false);
                return;
            }

            let settled = false;
            const finish = (result) => {
                if (settled) return;
                settled = true;
                resolve(result);
            };

            navigator.permissions.query({ name: 'notifications' })
                .then((status) => {
                    finish(status.state === 'denied' && Notification.permission === 'default');
                })
                .catch(() => finish(false));

            // Safety net: don't let a slow browser implementation block the
            // final decision — a timeout counts as "undetermined", not
            // suspicious.
            setTimeout(() => finish(false), CONFIG.PERMISSIONS_CHECK_TIMEOUT_MS);
        });
    }

    // Signal 7 (heavier weight): webdriver leak via a dynamically-created
    // iframe. Many stealth setups only patch navigator.webdriver on the
    // main frame; a freshly created iframe gets its own Navigator instance,
    // which leaks the unpatched value if the patch isn't reapplied per-frame.
    //
    // Limitation: a Puppeteer/Playwright setup using addInitScript /
    // Page.addScriptToEvaluateOnNewDocument (configured to run, not the
    // bare-minimum default) reapplies its patch to every new frame,
    // including dynamic iframes — this signal won't catch that. Still worth
    // having since it catches the more common "half-patched" setups.
    function checkIframeBypass() {
        try {
            const iframe = document.createElement('iframe');
            // No src/srcdoc: an about:blank iframe is same-origin and usable
            // synchronously, and is not subject to a page's frame-src CSP.
            iframe.style.display = 'none';
            iframe.setAttribute('aria-hidden', 'true');
            iframe.tabIndex = -1;
            document.documentElement.appendChild(iframe);

            const isHeadless = !!(iframe.contentWindow && iframe.contentWindow.navigator
                && iframe.contentWindow.navigator.webdriver === true);

            document.documentElement.removeChild(iframe);
            return isHeadless;
        } catch (e) {
            return false;
        }
    }

    // Signal 8 (heavier weight): naive patch artifacts. A real browser's
    // "webdriver" property is always an inherited Navigator.prototype
    // getter, never an own property on the navigator instance. Some
    // homebrew patch scripts (not the standard puppeteer-extra-plugin-
    // stealth approach) set it directly on the instance instead, leaving a
    // tell that a real browser never has.
    //
    // Limitation: this does NOT catch the standard stealth-plugin approach,
    // which deletes the prototype getter entirely rather than shadowing it
    // on the instance — only cruder, homebrew patches.
    //
    // False-positive risk: the PluginArray check below can also trigger on
    // privacy-hardened browsers (Brave, Firefox resistFingerprinting, Tor
    // Browser) that intentionally spoof/empty navigator.plugins — not
    // because they're automation.
    function checkPrototypeTampering() {
        try {
            const webdriverDesc = Object.getOwnPropertyDescriptor(navigator, 'webdriver');
            if (webdriverDesc !== undefined) return true;

            if (navigator.plugins && navigator.plugins.length > 0) {
                if (Object.prototype.toString.call(navigator.plugins) !== '[object PluginArray]') return true;
            }

            return false;
        } catch (e) {
            return false;
        }
    }

    // Signal 9 (light weight): missing window.chrome. Mostly outdated as a
    // signal — headless Chrome has shared the same codebase as headed
    // Chrome since v112 — but costs nothing extra to check.
    function checkChromeMissing() {
        if (IS_IN_APP_WEBVIEW) return false;
        const isChrome = /Chrome\//.test(UA) && !/CriOS|EdgiOS|FxiOS/.test(UA);
        return isChrome && !window.chrome;
    }

    // Cheap checks first, heavier ones (WebGL context, iframe) are
    // deferred by one task so they never block the scripts after this one.
    function runSyncChecks() {
        if (checkWebdriverFlag()) score += 1;
        if (checkEmptyPluginsAndLanguages()) score += 1;
        if (checkAutomationArtifacts()) score += 1;
        if (checkZeroOuterDimensions()) score += 1;
        if (checkHeadlessUserAgent()) score += 2;
        if (checkPrototypeTampering()) score += 2;
        if (checkChromeMissing()) score += 1;
    }

    function runDeferredChecks() {
        return new Promise((resolve) => {
            setTimeout(() => {
                if (checkSoftwareWebGLRenderer()) score += 2;
                if (checkIframeBypass()) score += 2;
                resolve();
            }, 0);
        });
    }

    // 1.9: Googlebot renders pages with headless Chromium, so it can score as
    // "automation" for real. Only for a session that already looks suspicious,
    // ask the server whether the request comes from a search engine crawler
    // verified by reverse DNS + forward DNS (never by User-Agent alone, which
    // anyone can fake). The server decides from the IP and User-Agent of this
    // very request and answers with no-store headers, so this also works behind
    // a full-page cache. Anything that goes wrong (REST blocked, timeout,
    // network error, bad JSON) counts as "not verified": the session stays
    // suspected and decryption stays withheld.
    const CRAWLER_CHECK_TIMEOUT_MS = 5000;

    function confirmVerifiedCrawler() {
        const url = InitHeadlessDetectData.crawlerUrl;
        if (!url || typeof fetch !== 'function') return Promise.resolve(false);

        return new Promise((resolve) => {
            let timer = null;
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const finish = (verified) => {
                if (timer !== null) clearTimeout(timer);
                resolve(verified === true);
            };

            timer = setTimeout(() => {
                if (controller) controller.abort();
                finish(false);
            }, CRAWLER_CHECK_TIMEOUT_MS);

            fetch(url, {
                method: 'GET',
                credentials: 'omit',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
                signal: controller ? controller.signal : undefined,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((json) => finish(!!json && json.verified === true))
                .catch(() => finish(false));
        });
    }

    // window.InitContentHeadlessCheck is created synchronously right here,
    // regardless of whether the checks inside it are still pending — so it's
    // guaranteed to exist by the time DOMContentLoaded fires, no matter the
    // relative enqueue order against decrypt.js.
    window.InitContentHeadlessCheck = Promise.all([
        checkPermissionsInconsistency(),
        runDeferredChecks(),
    ]).then((results) => {
        if (results[0]) score += 2;

        const suspected = score >= CONFIG.SCORE_TO_TRIGGER;
        if (!suspected) return false;

        return confirmVerifiedCrawler().then((verifiedCrawler) => {
            if (verifiedCrawler) {
                if (InitHeadlessDetectData.debug) {
                    console.info('[Init Content Protector] Looks like automation (score ' + score + '/' + CONFIG.SCORE_TO_TRIGGER + ') but the server verified a search engine crawler — decryption allowed.');
                }
                return false;
            }

            if (InitHeadlessDetectData.debug) {
                console.warn('[Init Content Protector] Headless/automation browser suspected (score ' + score + '/' + CONFIG.SCORE_TO_TRIGGER + ') — decryption will be withheld.');
            }
            return true;
        });
    }).catch(() => false);

    try {
        runSyncChecks();
    } catch (e) {
        // A throwing check must never block decryption.
    }

})();
