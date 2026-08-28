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

        for (const key in window) {
            if (key.indexOf('cdc_') === 0) return true;
        }

        return false;
    }

    // Signal 4: outer window dimensions both zero. Easy to patch, so light
    // weight only.
    function checkZeroOuterDimensions() {
        return window.outerWidth === 0 && window.outerHeight === 0;
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
            return /swiftshader|llvmpipe|software rasterizer|\bmesa\b/i.test(renderer);
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
            iframe.srcdoc = '<!--headless check-->';
            iframe.style.display = 'none';
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
        const isChrome = /Chrome/i.test(navigator.userAgent);
        return isChrome && !window.chrome;
    }

    if (checkWebdriverFlag()) score += 1;
    if (checkEmptyPluginsAndLanguages()) score += 1;
    if (checkAutomationArtifacts()) score += 1;
    if (checkZeroOuterDimensions()) score += 1;
    if (checkSoftwareWebGLRenderer()) score += 2;
    if (checkIframeBypass()) score += 2;
    if (checkPrototypeTampering()) score += 2;
    if (checkChromeMissing()) score += 1;

    // window.InitContentHeadlessCheck is created synchronously right here,
    // regardless of whether the async permissions check inside it is still
    // pending — so it's guaranteed to exist by the time DOMContentLoaded
    // fires, no matter the relative enqueue order against decrypt.js.
    window.InitContentHeadlessCheck = checkPermissionsInconsistency().then((permissionsFlagged) => {
        if (permissionsFlagged) score += 2;

        const suspected = score >= CONFIG.SCORE_TO_TRIGGER;
        if (suspected && InitHeadlessDetectData.debug) {
            console.warn('[Init Content Protector] Headless/automation browser suspected (score ' + score + '/' + CONFIG.SCORE_TO_TRIGGER + ') — decryption will be withheld.');
        }

        return suspected;
    });

})();
