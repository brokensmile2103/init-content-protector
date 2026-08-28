// DISABLE DEVTOOL INIT
// Thin init wrapper around the vendored disable-devtool library
// (disable-devtool.min.js, MIT licensed, https://github.com/theajack/disable-devtool).
// Kept separate from the vendor file so our config lives in plugin code and
// survives a future library update untouched.
(function () {
    if (typeof DisableDevtool !== 'function') return;
    if (typeof InitDisableDevtoolData === 'undefined') return;

    DisableDevtool({
        // The library's own default fallback is the literal string
        // "localhost" for the "close tab, else redirect" behavior (used
        // when the browser blocks window.close() on a tab it didn't open
        // itself, which is the common case). That default is harmless in
        // local dev but a dead link on a live site, so PHP overrides it
        // with the site's homepage — see InitDisableDevtoolData.url.
        url: InitDisableDevtoolData.url,

        // Everything else below is intentionally left at the library's own
        // defaults rather than duplicated/overridden here:
        // - disableMenu (right-click blocking, default true) already has a
        //   redundant document-level handler from content-protector.js
        //   when "Enable JavaScript Content Protection" is also on; two
        //   listeners doing the same thing is harmless.
        // - disableSelect / disableInputSelect / disableCopy / disableCut /
        //   disablePaste stay at their default (off) here for the same
        //   reason — that's content-protector.js's job, not this library's.
        //   Turning them on here too would just add a second layer of the
        //   same restriction with no extra benefit.
        // - detectors / interval / clearIntervalWhenDevOpenTrigger: the
        //   library ships sensible defaults (all detectors, 200ms poll,
        //   keep monitoring after a trigger) that don't need second-guessing.
    });
})();
