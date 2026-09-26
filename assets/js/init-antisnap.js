/**
 * Init AntiSnap — Anti-Screenshot & Content Protection Library
 * A lightweight, standalone script by Init HTML (https://inithtml.com)
 * No dependencies. Works on any website.
 *
 * @version 6.0.0
 * @author Init HTML
 * @license MIT
 *
 * Usage: Include this script on any page.
 * Customize via window.InitAntiSnapConfig BEFORE this script runs, e.g.:
 *
 *   window.InitAntiSnapConfig = {
 *     ALERT_MESSAGE: "Your custom warning here",
 *     ENABLE_ALERT: true,
 *     onDetect: function (reason) {
 *       // reason: 'scroll' | 'devtools'
 *       // Optional hook for your own logging/reporting backend.
 *       // Called best-effort, wrapped internally — an error here will
 *       // never break the defense mechanism itself.
 *     }
 *   };
 *
 * All 5.x config keys (TRUST_WINDOW_MS, SCORE_TO_TRIGGER,
 * JUMP_THRESHOLD_RATIO, ENABLE_ALERT, ALERT_MESSAGE, onDetect) keep their
 * meaning and defaults.
 *
 * Public API (new in 6.0), for sites whose own code scrolls the page in
 * steps (auto page-turn readers, guided tours...):
 *
 *   InitAntiSnap.version        // "6.0.0"
 *   InitAntiSnap.trust(ms)      // treat the next `ms` milliseconds as human
 *   InitAntiSnap.pause()        // stop analysing until resume()
 *   InitAntiSnap.resume()
 *   InitAntiSnap.isDefending()  // true while the page is blurred
 *   InitAntiSnap.destroy()      // remove every listener
 *
 * What changed from 5.3 and why
 * -----------------------------
 * Scroll-and-stitch capture tools (GoFullPage, FireShot, ...) scroll the
 * page by script in EQUAL steps (usually one viewport), in ONE direction,
 * pausing between steps to grab each frame. 5.3 scored every large jump
 * that happened outside a short "trust window", so ordinary things with no
 * such rhythm were punished too: find-in-page (Ctrl+F) jumps, links to
 * #anchors, dragging or clicking the scrollbar (the page receives no mouse
 * event for that), scroll restoration after reload, zooming, and pop-ups
 * that lock page scrolling (hiding the scrollbar looked like a capture tool
 * hiding it).
 *
 * 6.0 looks for the rhythm instead of single jumps:
 * - Scroll events are grouped into "moves" (a burst of events separated by
 *   a short idle gap), so instant and smooth programmatic scrolls are both
 *   measured by their real total distance.
 * - A move only scores when it is large, untrusted, and repeats the
 *   previous move (same direction, same size within a few pixels, within a
 *   few seconds). An unrelated jump starts a new sequence instead of adding
 *   up. Two viewport-sized steps still trigger, exactly as in 5.3.
 * - Moves made while the pointer rests on the scrollbar are ignored.
 * - A vanished scrollbar no longer triggers on its own when the page is
 *   scroll-locked (modals, lightboxes, menus). It doubles the score of the
 *   next jumps instead, and triggers immediately only when the scrollbar is
 *   hidden while the page is still scrollable — the capture-tool signature.
 * - The DevTools full-size-capture check compares PHYSICAL viewport height
 *   (CSS px × devicePixelRatio), so zooming out no longer looks like a
 *   suddenly giant viewport.
 * - The blur is applied through a class + stylesheet on <html>, so a
 *   site's own inline styles on <body> are never overwritten.
 */

(function () {
    'use strict';

    // Loaded twice (theme + plugin)? Keep the first instance.
    if (window.InitAntiSnap && window.InitAntiSnap.version) return;

    const VERSION = '6.0.0';

    // --- SYSTEM CONFIGURATION (same keys and defaults as 5.x) ---
    const CONFIG = Object.assign({
        TRUST_WINDOW_MS: 2000,
        SCORE_TO_TRIGGER: 5,
        JUMP_THRESHOLD_RATIO: 0.3,
        ENABLE_ALERT: true,
        ALERT_MESSAGE: '⚠️ Automated screenshot or scraping tool detected. Action blocked!',
        onDetect: function () {} // (reason: 'scroll' | 'devtools') => void — optional, site-provided
    }, window.InitAntiSnapConfig || {});

    // Internal tuning (not part of the public config).
    const MOVE_IDLE_MS = 90;          // gap that separates two scroll "moves"
    const PATTERN_WINDOW_MS = 5000;   // max time between two steps of one pattern
    const SCROLLBAR_ZONE_PX = 40;     // pointer this close to the scrollbar = using it
    const RESTORE_DELAY_MS = 8000;    // safety-net watchdog, as in 5.x
    const HELD_POINTER_MAX_MS = 30000;

    const root = document.documentElement;
    const passiveOpts = { passive: true };
    const listeners = [];

    const on = (target, type, fn, opts) => {
        target.addEventListener(type, fn, opts || passiveOpts);
        listeners.push([target, type, fn, opts || passiveOpts]);
    };

    const now = () => Date.now();
    const scrollY = () => window.scrollY || window.pageYOffset || 0;

    // iPadOS reports a desktop "Macintosh" user agent; touch points give it away.
    const isDesktop = !/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
        && !(/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

    // --- STATE ---
    let paused = false;
    let destroyed = false;
    let isDefending = false; // lock while a defense cycle is running
    let watchdogTimeout = null;
    let restoreClassTimeout = null;

    let humanTrustExpires = 0;
    let pointerDownSince = 0;       // > 0 while a real mouse button is held
    let lastSafeKeyAt = 0;
    let lastZoomInputAt = 0;

    let pointerX = -1;
    let pointerLeftViaTop = true;   // unknown position counts as "not on the scrollbar"

    let suspiciousScore = 0;
    let lastPattern = null;         // { dir, size, at }
    let move = null;                // current scroll move
    let moveTimer = null;
    let lastY = scrollY();

    let baselineScrollbarWidth = 0; // widest classic scrollbar seen so far
    let lastInnerHeight = window.innerHeight;
    let lastDpr = window.devicePixelRatio || 1;
    let normalScreenHeight = (window.screen && window.screen.height) || 1080;

    // --- TRUST ---
    function grantHumanTrust(duration) {
        humanTrustExpires = Math.max(humanTrustExpires, now() + duration);
        suspiciousScore = 0;
        lastPattern = null;
    }

    function isHumanNow() {
        if (now() < humanTrustExpires) return true;
        if (pointerDownSince && now() - pointerDownSince < HELD_POINTER_MAX_MS) return true;
        return false;
    }

    // Wheel (including Ctrl+wheel / trackpad pinch zoom) and touch.
    on(window, 'wheel', (e) => {
        if (!e.isTrusted) return;
        if (e.ctrlKey || e.metaKey) lastZoomInputAt = now();
        grantHumanTrust(CONFIG.TRUST_WINDOW_MS);
    });

    ['touchstart', 'touchmove'].forEach((type) => {
        on(window, type, (e) => {
            if (e.isTrusted) grantHumanTrust(CONFIG.TRUST_WINDOW_MS);
        });
    });

    // Momentum scrolling keeps going after the finger lifts.
    on(window, 'touchend', (e) => {
        if (e.isTrusted) grantHumanTrust(CONFIG.TRUST_WINDOW_MS * 2);
    });

    // Safari trackpad pinch.
    on(window, 'gesturestart', () => {
        lastZoomInputAt = now();
    });

    // Mouse buttons: trusted for as long as a button is held (middle-click
    // autoscroll, text-selection drag, custom scrollbars) and a while after.
    on(window, 'mousedown', (e) => {
        if (!e.isTrusted) return;
        pointerDownSince = now();
        grantHumanTrust(CONFIG.TRUST_WINDOW_MS * 2);
    });

    const releasePointer = (e) => {
        if (e && e.isTrusted === false) return;
        if (pointerDownSince) {
            pointerDownSince = 0;
            grantHumanTrust(CONFIG.TRUST_WINDOW_MS * 2);
        }
    };
    on(window, 'mouseup', releasePointer);
    on(window, 'blur', () => {
        pointerDownSince = 0;
    });

    on(window, 'click', (e) => {
        if (e.isTrusted) grantHumanTrust(CONFIG.TRUST_WINDOW_MS * 2);
    });

    // Keyboard. Navigation keys (5.3 list) get the long window; any other
    // real key (Ctrl+F before find-in-page, typing...) a normal one. Bare
    // modifier presses are NOT trusted: extension shortcuts such as
    // Alt+Shift+P deliver only their modifiers to the page.
    const safeKeyCodes = ['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Space', 'Home', 'End'];
    const safeKeyValues = ['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', ' ', 'Spacebar', 'Home', 'End'];
    const modifierKeys = ['Alt', 'AltGraph', 'Control', 'Shift', 'Meta', 'OS', 'Hyper', 'Super', 'Fn', 'CapsLock'];

    on(window, 'keydown', (e) => {
        if (!e.isTrusted) return;
        const key = typeof e.key === 'string' ? e.key : '';
        const code = typeof e.code === 'string' ? e.code : '';

        if (modifierKeys.indexOf(key) !== -1) return;

        if ((e.ctrlKey || e.metaKey) && ['+', '-', '=', '0', '_'].indexOf(key) !== -1) {
            lastZoomInputAt = now();
        }

        if (safeKeyCodes.indexOf(code) !== -1 || safeKeyValues.indexOf(key) !== -1) {
            grantHumanTrust(CONFIG.TRUST_WINDOW_MS * 2.5);
            lastSafeKeyAt = now();
            return;
        }

        grantHumanTrust(CONFIG.TRUST_WINDOW_MS);
    });

    // --- POINTER NEAR THE SCROLLBAR ---
    // Browsers send the page no mouse events for clicks or drags on the
    // page's own scrollbar, so the last known pointer position is used: if
    // it was right next to the scrollbar and the pointer did not leave
    // through the top edge (towards the toolbar / extension buttons), the
    // visitor is using the scrollbar.
    on(document, 'mousemove', (e) => {
        if (!e.isTrusted) return;
        pointerX = e.clientX;
        pointerLeftViaTop = false;
    });

    on(document, 'mouseout', (e) => {
        if (!e.isTrusted || e.relatedTarget) return;
        if (e.clientY <= 8) pointerLeftViaTop = true;
    });

    function scrollbarWidth() {
        return Math.max(0, window.innerWidth - root.clientWidth);
    }

    function hasVerticalOverflow() {
        return root.scrollHeight > window.innerHeight + 100;
    }

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement);
    }

    // A classic scrollbar that existed has vanished while the page still
    // overflows: a capture tool hid it, or a pop-up locked scrolling.
    function isScrollbarGone() {
        if (isFullscreen()) return false;
        return baselineScrollbarWidth >= 8 && scrollbarWidth() === 0 && hasVerticalOverflow();
    }

    function isPointerOnScrollbar() {
        if (pointerLeftViaTop || pointerX < 0) return false;
        if (isScrollbarGone()) return false;

        let rtl = false;
        try {
            rtl = window.getComputedStyle(root).direction === 'rtl';
        } catch (err) {
            rtl = false;
        }

        const sbw = scrollbarWidth();
        if (rtl) {
            return pointerX <= sbw + SCROLLBAR_ZONE_PX;
        }
        return pointerX >= root.clientWidth - SCROLLBAR_ZONE_PX;
    }

    // --- 1. SCROLL ANALYZER (Anti-Extensions) ---
    function scoreMove(m) {
        const vh = window.innerHeight || 1;
        const distance = m.endY - m.startY;
        const size = Math.abs(distance);
        const maxScrollY = Math.max(0, root.scrollHeight - vh);

        if (m.trusted) {
            suspiciousScore = 0;
            lastPattern = null;
            return 0;
        }

        // Back at the very top: a fresh start, as in 5.x.
        if (m.endY <= 0) {
            suspiciousScore = 0;
            lastPattern = null;
            return 0;
        }

        if (size === 0 || m.onScrollbar) return 0;

        // Landing exactly on the bottom (End key, dragging to the end, a
        // capture tool's clamped last step) carries no signal either way.
        if (Math.abs(m.endY - maxScrollY) < 4) return 0;

        if (size < vh * CONFIG.JUMP_THRESHOLD_RATIO) {
            suspiciousScore = Math.max(0, suspiciousScore - 0.5);
            return 0;
        }

        const isViewportJump = Math.abs(size - vh) < Math.max(80, vh * 0.12);
        let penalty = isViewportJump ? 3 : 2;
        if (isScrollbarGone()) penalty *= 2;

        const dir = distance > 0 ? 1 : -1;
        const tolerance = Math.max(8, size * 0.04);
        const repeats = !!lastPattern
            && lastPattern.dir === dir
            && Math.abs(lastPattern.size - size) <= tolerance
            && m.startAt - lastPattern.at <= PATTERN_WINDOW_MS;

        suspiciousScore = repeats ? suspiciousScore + penalty : penalty;
        lastPattern = { dir: dir, size: size, at: m.lastAt };

        return suspiciousScore;
    }

    function finishMove() {
        clearTimeout(moveTimer);
        moveTimer = null;
        if (!move) return;

        const m = move;
        move = null;

        if (m.scored) return;

        if (scoreMove(m) >= CONFIG.SCORE_TO_TRIGGER) {
            triggerUltimateDefense('scroll');
        }
    }

    on(window, 'scroll', () => {
        const t = now();
        const y = scrollY();

        if (isDefending || paused) {
            lastY = y;
            move = null;
            return;
        }

        if (move && t - move.lastAt > MOVE_IDLE_MS) finishMove();

        if (!move) {
            move = {
                startY: lastY,
                endY: y,
                startAt: t,
                lastAt: t,
                events: 0,
                trusted: false,
                onScrollbar: false,
                scored: false
            };
        }

        move.events++;
        move.endY = y;
        move.lastAt = t;
        if (isHumanNow()) move.trusted = true;
        if (!move.onScrollbar && isPointerOnScrollbar()) move.onScrollbar = true;

        lastY = y;

        // An instant jump arrives in a single event: judge it right away so
        // the blur lands before the tool can grab the frame. Smooth moves
        // are judged once they come to rest.
        if (move.events === 1 && !move.trusted && !move.onScrollbar) {
            const vh = window.innerHeight || 1;
            if (Math.abs(move.endY - move.startY) >= vh * CONFIG.JUMP_THRESHOLD_RATIO) {
                const scoreBefore = suspiciousScore;
                const patternBefore = lastPattern;
                const score = scoreMove(move);

                if (score >= CONFIG.SCORE_TO_TRIGGER) {
                    move.scored = true;
                    triggerUltimateDefense('scroll');
                    return;
                }

                // Not decisive yet: roll back and score the whole move later.
                suspiciousScore = scoreBefore;
                lastPattern = patternBefore;
            }
        }

        clearTimeout(moveTimer);
        moveTimer = setTimeout(finishMove, MOVE_IDLE_MS);
    });

    // --- 2. DEVTOOLS / HIDDEN-SCROLLBAR DETECTOR (PC ONLY) ---
    // Chrome DevTools "Capture full size screenshot" temporarily resizes
    // the page's own viewport to the full content height, takes one shot
    // and restores it — without any scroll event.
    function inspectViewport() {
        if (!isDesktop || isDefending || paused) return;

        const t = now();
        const innerHeight = window.innerHeight;
        const dpr = window.devicePixelRatio || 1;
        const sbw = scrollbarWidth();
        const screenHeight = (window.screen && window.screen.height) || normalScreenHeight;

        const prevInner = lastInnerHeight || innerHeight;
        const prevDpr = lastDpr || dpr;
        lastInnerHeight = innerHeight;
        lastDpr = dpr;

        if (hasVerticalOverflow() && sbw > baselineScrollbarWidth) {
            baselineScrollbarWidth = sbw;
        }

        if (innerHeight <= screenHeight) {
            normalScreenHeight = screenHeight;
        }

        // Navigation keys can mass-load lazy content; zoom input resizes the
        // viewport. Neither says anything about DevTools.
        if (t - lastSafeKeyAt < 1500 || t - lastZoomInputAt < 1500) return;
        if (isFullscreen()) return;

        // Anomaly 1: scrollbar hidden while the page is STILL scrollable.
        // Scroll locks (modals, drawers) set overflow hidden and are left
        // alone here; they only raise the weight of later jumps.
        if (isScrollbarGone() && !isHumanNow() && isRootScrollable()) {
            triggerUltimateDefense('devtools');
            return;
        }

        // Anomaly 2: the viewport became far taller than the screen in
        // PHYSICAL pixels (zoom keeps CSS px × DPR constant) and now holds
        // the whole page.
        const physicalGrowth = (innerHeight * dpr) / (prevInner * prevDpr);
        const isUnnaturallyTall = innerHeight > normalScreenHeight * 1.2;
        const holdsWholePage = root.scrollHeight <= innerHeight + 2 || innerHeight > normalScreenHeight * 2;

        if (isUnnaturallyTall && physicalGrowth >= 1.5 && holdsWholePage) {
            triggerUltimateDefense('devtools');
        }
    }

    function isRootScrollable() {
        try {
            const blocked = ['hidden', 'clip'];
            const htmlStyle = window.getComputedStyle(root);
            const body = document.body;
            const bodyStyle = body ? window.getComputedStyle(body) : null;

            if (blocked.indexOf(htmlStyle.overflowY) !== -1) return false;
            if (bodyStyle && blocked.indexOf(bodyStyle.overflowY) !== -1) return false;
            if (bodyStyle && bodyStyle.position === 'fixed') return false;
            return true;
        } catch (err) {
            return false;
        }
    }

    let inspectQueued = false;
    const queueInspect = () => {
        if (inspectQueued) return;
        inspectQueued = true;
        const run = () => {
            inspectQueued = false;
            inspectViewport();
        };
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(run);
        } else {
            setTimeout(run, 16);
        }
    };

    on(window, 'resize', queueInspect);

    let resizeObserver = null;
    if ('ResizeObserver' in window) {
        resizeObserver = new ResizeObserver(queueInspect);
        resizeObserver.observe(root);
    }

    // First measurement once layout is ready: records the baseline only.
    const recordBaseline = () => {
        if (hasVerticalOverflow()) baselineScrollbarWidth = Math.max(baselineScrollbarWidth, scrollbarWidth());
        lastInnerHeight = window.innerHeight;
        lastDpr = window.devicePixelRatio || 1;
    };
    if (document.readyState === 'loading') {
        on(document, 'DOMContentLoaded', recordBaseline);
    } else {
        recordBaseline();
    }
    on(window, 'load', recordBaseline);

    // --- VISUAL DEFENSE (class based, never touches body inline styles) ---
    const STYLE_ID = 'init-antisnap-style';
    const ACTIVE_CLASS = 'init-antisnap-active';
    const RESTORING_CLASS = 'init-antisnap-restoring';

    function ensureStyle() {
        if (document.getElementById(STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = 'html.' + ACTIVE_CLASS + ' body{filter:blur(20px) grayscale(100%) !important;transition:filter 0s !important;}'
            + 'html.' + RESTORING_CLASS + ' body{transition:filter 0.5s ease !important;}';
        (document.head || root).appendChild(style);
    }

    // --- RESTORE (idempotent) ---
    function restoreView() {
        clearTimeout(watchdogTimeout);
        watchdogTimeout = null;

        if (!isDefending) return;

        root.classList.add(RESTORING_CLASS);
        root.classList.remove(ACTIVE_CLASS);
        clearTimeout(restoreClassTimeout);
        restoreClassTimeout = setTimeout(() => {
            root.classList.remove(RESTORING_CLASS);
        }, 600);

        isDefending = false;
        lastY = scrollY();
        move = null;

        // Stay armed: if the same untrusted rhythm continues right after the
        // alert is dismissed, the very next matching step triggers again.
        // Any real input (wheel, key, touch, click) clears this.
        if (lastPattern) {
            lastPattern.at = now();
            suspiciousScore = Math.max(suspiciousScore, CONFIG.SCORE_TO_TRIGGER - 1);
        }
    }

    // Leaving the tab while blurred and coming back restores immediately.
    on(document, 'visibilitychange', () => {
        if (document.visibilityState === 'visible' && isDefending) {
            restoreView();
        }
    });

    // --- ULTIMATE DEFENSE MECHANISM ---
    function triggerUltimateDefense(reason) {
        if (isDefending || destroyed) return;
        isDefending = true;

        // Optional reporting hook — best effort, never allowed to break the
        // visual defense below.
        try {
            if (typeof CONFIG.onDetect === 'function') CONFIG.onDetect(reason);
        } catch (err) {
            /* swallow errors from site-provided callback */
        }

        // 1. Instant visual destruction.
        ensureStyle();
        clearTimeout(restoreClassTimeout);
        root.classList.remove(RESTORING_CLASS);
        root.classList.add(ACTIVE_CLASS);

        // 2. Coordinate disruption.
        window.scrollTo(0, Math.max(0, scrollY() - 25));

        // 3. Safety-net watchdog, independent of alert() — matters when
        // ENABLE_ALERT is false. With the alert on, the restore right after
        // alert() always wins.
        watchdogTimeout = setTimeout(restoreView, RESTORE_DELAY_MS);

        if (CONFIG.ENABLE_ALERT) {
            // Wait for the blur to be PAINTED before alert() freezes the tab.
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    // alert() is kept ON PURPOSE: it blocks the tab's whole JS
                    // thread, including the scroll-and-stitch extension's
                    // content script. A non-blocking banner is ignored by them.
                    alert(CONFIG.ALERT_MESSAGE);

                    // Runs as soon as the alert is dismissed, however that
                    // happens — no race with a parallel timer.
                    restoreView();
                });
            });
        }
    }

    // --- PUBLIC API ---
    window.InitAntiSnap = {
        version: VERSION,
        trust: function (ms) {
            grantHumanTrust(Math.max(0, Number(ms) || CONFIG.TRUST_WINDOW_MS));
        },
        pause: function () {
            paused = true;
            move = null;
        },
        resume: function () {
            paused = false;
            lastY = scrollY();
            move = null;
        },
        isDefending: function () {
            return isDefending;
        },
        destroy: function () {
            destroyed = true;
            paused = true;
            restoreView();
            clearTimeout(moveTimer);
            listeners.forEach((l) => {
                l[0].removeEventListener(l[1], l[2], l[3]);
            });
            listeners.length = 0;
            if (resizeObserver) resizeObserver.disconnect();
        }
    };
})();
