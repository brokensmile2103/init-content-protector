// CONTENT PROTECTOR
// Basic JavaScript content protection: blocks selection, copy/cut/paste,
// drag, right-click, printing and the common DevTools / view-source / save
// shortcuts for the configured content container.
//
// 1.7:
// - Safe when InitContentProtectorData is missing or the selector is invalid.
// - Print blocking is set up once (1.6 appended a new <style> and two new
//   listeners on every window focus/blur, growing without limit).
// - One injected stylesheet instead of per-element inline styles, so it also
//   covers containers added later (infinite scroll, decrypted content).
// - Form fields, buttons and media controls inside the container keep
//   working (comment forms, embedded players, spoiler buttons...).
// - Copy/cut is also caught at document level when a selection reaches into
//   the container from outside it.
// - macOS shortcuts (Cmd+Option+I/J/C/U, Cmd+S/U/P) are covered.
(function () {
    'use strict';

    if (typeof window.InitContentProtectorData === 'undefined'
        || !window.InitContentProtectorData.jsContentProtectionEnabled) {
        return;
    }

    if (window.__initContentProtectorLoaded) return;
    window.__initContentProtectorLoaded = true;

    const data = window.InitContentProtectorData;
    const containerSelector = (data.content_selector || '.entry-content').trim() || '.entry-content';

    // Elements that must stay usable inside protected content.
    const INTERACTIVE = 'input, textarea, select, option, button, video, audio, '
        + '[contenteditable=""], [contenteditable="true"], [data-icp-allow], [data-icp-allow] *';
    const TEXT_FIELD = 'input, textarea, select, [contenteditable=""], [contenteditable="true"], '
        + '[data-icp-allow], [data-icp-allow] *';

    const safeMatches = (el, selector) => {
        if (!el || el.nodeType !== 1) return false;
        try {
            return el.matches(selector);
        } catch (err) {
            return false;
        }
    };

    const safeQueryAll = (selector) => {
        try {
            return document.querySelectorAll(selector);
        } catch (err) {
            return [];
        }
    };

    const targetElement = (e) => {
        const t = e.target;
        if (!t) return null;
        return t.nodeType === 1 ? t : t.parentElement;
    };

    const isInteractive = (e) => safeMatches(targetElement(e), INTERACTIVE);
    const isTextField = (e) => safeMatches(targetElement(e), TEXT_FIELD);

    const block = (e) => {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        return false;
    };

    const keyOf = (e) => (typeof e.key === 'string' ? e.key.toLowerCase() : '');

    // e.code is layout independent: Cmd+Option+I on macOS reports e.key "ˆ".
    const codeLetter = (e) => {
        if (typeof e.code === 'string' && /^Key[A-Z]$/.test(e.code)) {
            return e.code.charAt(3).toLowerCase();
        }
        return keyOf(e);
    };

    const isDevtoolsShortcut = (e) => {
        const letter = codeLetter(e);
        if (keyOf(e) === 'f12') return true;
        // Windows / Linux: Ctrl+Shift+I/J/C, Ctrl+U.
        if (e.ctrlKey && e.shiftKey && ['i', 'j', 'c'].indexOf(letter) !== -1) return true;
        if (e.ctrlKey && !e.shiftKey && !e.altKey && letter === 'u') return true;
        // macOS: Cmd+Option+I/J/C/U, Cmd+U.
        if (e.metaKey && e.altKey && ['i', 'j', 'c', 'u'].indexOf(letter) !== -1) return true;
        if (e.metaKey && !e.altKey && !e.shiftKey && letter === 'u') return true;
        return false;
    };

    const isPrintShortcut = (e) => (e.ctrlKey || e.metaKey) && !e.altKey && codeLetter(e) === 'p';
    const isSaveShortcut = (e) => (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && codeLetter(e) === 's';

    // ------------------------------------------------------------------
    // Styles: selection off for the container, back on for form fields,
    // and nothing printable.
    // ------------------------------------------------------------------
    const injectStyle = () => {
        if (document.getElementById('icp-content-protector-style')) return;

        const style = document.createElement('style');
        style.id = 'icp-content-protector-style';

        let rules = '@media print { html, body, body * { display: none !important; } }';

        // Validate the selector before building rules from it.
        let validSelector = true;
        try {
            document.querySelector(containerSelector);
        } catch (err) {
            validSelector = false;
        }

        if (validSelector && containerSelector.indexOf('{') === -1 && containerSelector.indexOf('}') === -1) {
            rules += '\n' + containerSelector + ' { -webkit-user-select: none !important; -moz-user-select: none !important;'
                + ' -ms-user-select: none !important; user-select: none !important; -webkit-touch-callout: none !important; }'
                + '\n:is(' + containerSelector + ') img { -webkit-user-drag: none; user-drag: none; }'
                + '\n:is(' + containerSelector + ') :is(input, textarea, select, [contenteditable=""], [contenteditable="true"], [data-icp-allow], [data-icp-allow] *)'
                + ' { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; }';
        }

        style.textContent = rules;
        (document.head || document.documentElement).appendChild(style);
    };

    // ------------------------------------------------------------------
    // Print blocking. Listeners are registered once; window.print is
    // re-asserted on focus/blur in case another script restored it.
    // ------------------------------------------------------------------
    const noPrint = function () {
        return false;
    };

    const assertPrintOverride = () => {
        if (window.print !== noPrint) {
            try {
                window.print = noPrint;
            } catch (err) {
                // Non-writable in some sandboxes; CSS still blanks the output.
            }
        }
    };

    const blockPrint = () => {
        assertPrintOverride();

        ['beforeprint', 'afterprint'].forEach((type) => {
            window.addEventListener(type, block, { passive: false, capture: true });
        });

        window.addEventListener('focus', assertPrintOverride);
        window.addEventListener('blur', assertPrintOverride);
    };

    // ------------------------------------------------------------------
    // Per-container protection.
    // ------------------------------------------------------------------
    const onGuardedEvent = (e) => {
        if (isInteractive(e)) return true;
        return block(e);
    };

    // mousedown/mouseup: prevent text selection and the right-button menu,
    // but let the event continue so sliders, lightboxes and similar widgets
    // inside the content keep working.
    const onPointerButton = (e) => {
        if (isInteractive(e)) return true;
        if (e.button === 2) return block(e);
        e.preventDefault();
        return true;
    };

    const onContainerKeydown = (e) => {
        if (isTextField(e)) {
            // Only the DevTools / print shortcuts stay blocked inside fields.
            if (isDevtoolsShortcut(e) || isPrintShortcut(e)) block(e);
            return;
        }

        const letter = codeLetter(e);

        if ((e.ctrlKey || e.metaKey) && ['a', 'c', 'x', 'v', 's', 'p', 'u'].indexOf(letter) !== -1) {
            block(e);
            return;
        }

        if (isDevtoolsShortcut(e)) {
            block(e);
            return;
        }

        if (keyOf(e) === 'f5' || (e.ctrlKey && letter === 'r')) {
            block(e);
        }
    };

    const clearSelection = () => {
        const sel = window.getSelection ? window.getSelection() : null;
        if (sel && sel.removeAllRanges) sel.removeAllRanges();
    };

    const protectElement = (element) => {
        if (!element || element.dataset.protected === 'true') return;
        element.dataset.protected = 'true';

        element.draggable = false;

        ['selectstart', 'dragstart', 'copy', 'cut', 'paste'].forEach((type) => {
            element.addEventListener(type, onGuardedEvent, { passive: false, capture: true });
        });

        element.addEventListener('contextmenu', (e) => {
            if (isTextField(e)) return true;
            return block(e);
        }, { passive: false, capture: true });

        element.addEventListener('mousedown', onPointerButton, { passive: false, capture: true });
        element.addEventListener('mouseup', onPointerButton, { passive: false, capture: true });
        element.addEventListener('keydown', onContainerKeydown, { passive: false, capture: true });
        element.addEventListener('focus', clearSelection, true);
    };

    const scan = () => {
        const list = safeQueryAll(containerSelector);
        for (let i = 0; i < list.length; i++) {
            protectElement(list[i]);
        }
    };

    // Does the current selection touch any protected container?
    const selectionTouchesContainer = () => {
        const sel = window.getSelection ? window.getSelection() : null;
        if (!sel || sel.isCollapsed || !sel.rangeCount) return false;

        const containers = safeQueryAll(containerSelector);
        for (let r = 0; r < sel.rangeCount; r++) {
            const range = sel.getRangeAt(r);
            for (let i = 0; i < containers.length; i++) {
                try {
                    if (range.intersectsNode(containers[i])) return true;
                } catch (err) {
                    // Detached node; ignore.
                }
            }
        }
        return false;
    };

    // ------------------------------------------------------------------
    // Document-level guards.
    // ------------------------------------------------------------------
    document.addEventListener('keydown', (e) => {
        if (isPrintShortcut(e) || isDevtoolsShortcut(e)) {
            block(e);
            return;
        }

        if (isSaveShortcut(e) && !isTextField(e)) {
            block(e);
        }
    }, { passive: false, capture: true });

    // Selection started outside the container but extends into it.
    ['copy', 'cut'].forEach((type) => {
        document.addEventListener(type, (e) => {
            if (isTextField(e)) return true;
            if (selectionTouchesContainer()) {
                clearSelection();
                return block(e);
            }
            return true;
        }, { passive: false, capture: true });
    });

    // Right-click is disabled site-wide, as in earlier versions, except in
    // text fields so spell-check suggestions and paste still work there.
    document.addEventListener('contextmenu', (e) => {
        if (isTextField(e)) return true;
        e.preventDefault();
        return false;
    }, { passive: false, capture: true });

    // ------------------------------------------------------------------
    // Init + watch for containers that appear later.
    // ------------------------------------------------------------------
    injectStyle();
    blockPrint();
    scan();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    }

    window.addEventListener('init-content-decrypted', scan);

    if (typeof window.MutationObserver === 'function' && document.body) {
        let pending = false;
        const schedule = window.requestAnimationFrame
            ? window.requestAnimationFrame.bind(window)
            : (fn) => window.setTimeout(fn, 16);

        const observer = new MutationObserver((mutations) => {
            if (pending) return;
            for (let i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
                    pending = true;
                    schedule(() => {
                        pending = false;
                        scan();
                    });
                    return;
                }
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
