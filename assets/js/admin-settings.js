// ADMIN SETTINGS — Auto-detect content selector
// Loads a live sample post (same-origin, so a plain fetch works without CORS
// issues) and tests a list of common theme/builder content-wrapper selectors
// against it, picking whichever matches the most text. This only runs on
// the plugin's own settings screen, on button click.
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('icp-autodetect-selector');
        var input = document.getElementById('content_selector');

        if (!btn || !input || typeof InitContentProtectorAdmin === 'undefined') {
            return;
        }

        var i18n = InitContentProtectorAdmin.i18n || {};

        btn.addEventListener('click', function () {
            var originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = i18n.detecting || 'Detecting…';

            fetch(InitContentProtectorAdmin.sample_url, { credentials: 'omit' })
                .then(function (res) {
                    if (!res.ok) throw new Error('fetch-failed');
                    return res.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var best = null;
                    var bestLength = 0;

                    (InitContentProtectorAdmin.candidates || []).forEach(function (sel) {
                        var el;
                        try {
                            el = doc.querySelector(sel);
                        } catch (e) {
                            return; // invalid selector, skip
                        }
                        if (el) {
                            var len = (el.textContent || '').trim().length;
                            if (len > bestLength) {
                                bestLength = len;
                                best = sel;
                            }
                        }
                    });

                    if (best) {
                        input.value = best;
                        input.style.borderColor = '#00a32a';
                        setTimeout(function () { input.style.borderColor = ''; }, 3000);
                    } else {
                        alert(i18n.notFound || 'No matching selector found. Please enter it manually.');
                    }
                })
                .catch(function () {
                    alert(i18n.fetchFail || 'Could not load the sample page. Please enter the selector manually.');
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = originalText;
                });
        });
    });
})();
