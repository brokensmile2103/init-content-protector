// DISABLE DEVTOOL INIT
// Thin init wrapper around the vendored disable-devtool library
// (disable-devtool.min.js, MIT licensed, https://github.com/theajack/disable-devtool).
// Kept separate from the vendor file so our config lives in plugin code and
// survives a future library update untouched.
//
// 1.7: custom ondevtoolopen handler. The library's own handler simply sets
// location.href to the configured URL (the site's homepage). When the
// visitor is already ON the homepage that becomes a reload loop: every
// reload re-runs the detectors, a false positive (docked side panels,
// some zoom levels, certain extensions) fires again, and the page reloads
// forever. On the target page itself we now hide the page instead, and
// show it again if the library later reports DevTools as closed.
(function () {
    'use strict';

    if (typeof DisableDevtool !== 'function') return;
    if (typeof InitDisableDevtoolData === 'undefined') return;

    var target = InitDisableDevtoolData.url || '/';
    var redirected = false;
    var HIDE_ID = 'icp-devtool-hide';

    // Compare origin + path only (query string and hash ignored), with a
    // trailing slash treated as equal, so "/?utm=x" and "/#top" count as the
    // homepage too.
    var normalize = function (href) {
        try {
            var u = new URL(href, window.location.href);
            return u.origin + (u.pathname.replace(/\/+$/, '') || '/');
        } catch (err) {
            return String(href);
        }
    };

    var isOnTarget = function () {
        return normalize(window.location.href) === normalize(target);
    };

    var hidePage = function () {
        if (document.getElementById(HIDE_ID)) return;
        var style = document.createElement('style');
        style.id = HIDE_ID;
        style.textContent = 'html body { visibility: hidden !important; }';
        (document.head || document.documentElement).appendChild(style);
    };

    var showPage = function () {
        var style = document.getElementById(HIDE_ID);
        if (style && style.parentNode) style.parentNode.removeChild(style);
    };

    DisableDevtool({
        // The library's own default fallback is the literal string
        // "localhost". PHP overrides it with the site's homepage — see
        // InitDisableDevtoolData.url. Also kept here so the library's
        // internals that read `url` behave exactly as before.
        url: target,

        ondevtoolopen: function () {
            if (isOnTarget()) {
                hidePage();
                return;
            }

            // The detector polls repeatedly; navigate only once.
            if (redirected) return;
            redirected = true;
            window.location.href = target;
        },

        ondevtoolclose: function () {
            if (isOnTarget()) showPage();
        }

        // Everything else is intentionally left at the library's defaults:
        // - disableMenu (right-click blocking, default true) overlaps with
        //   content-protector.js when that option is also on; harmless.
        // - disableSelect / disableCopy / disableCut / disablePaste stay off:
        //   that is content-protector.js's job.
        // - detectors / interval / clearIntervalWhenDevOpenTrigger keep the
        //   library's defaults.
    });
})();
