// DECRYPT CONTENT
document.addEventListener('DOMContentLoaded', function () {
    if (typeof InitContentEncryptedPayload === 'undefined') return;
    if (typeof InitContentDecryptData === 'undefined') return;

    const container = document.querySelector(InitContentDecryptData.content_selector || '.entry-content');

    if (!container) {
        // Only warn for admins (InitContentDecryptData.debug), so real
        // visitors never see console noise if a theme's selector mismatches.
        if (InitContentDecryptData.debug) {
            console.warn(
                '[Init Content Protector] Content selector "' +
                (InitContentDecryptData.content_selector || '.entry-content') +
                '" was not found on this page. Decryption was skipped, so this ' +
                'content will stay stuck on the loading skeleton for visitors. ' +
                'Check Settings → Init Content Protector → Content Selector.'
            );
        }
        return;
    }

    function renderDecrypted(passphrase) {
        try {
            container.innerHTML = CryptoJSAesDecrypt(passphrase, InitContentEncryptedPayload);
        } catch (e) {
            if (InitContentDecryptData.debug) {
                console.error('[Init Content Protector] Decryption failed:', e);
            }
        }
    }

    setTimeout(function () {
        if (InitContentDecryptData.decryption_key) {
            // Inline mode (default): key was base64'd directly into page HTML.
            renderDecrypted(base64DecodeUnicode(InitContentDecryptData.decryption_key));
            return;
        }

        if (InitContentDecryptData.rest_url && InitContentDecryptData.post_id) {
            // REST API mode (opt-in "Enhanced" delivery): fetch the key from the
            // REST API endpoint instead of embedding it in page HTML.
            fetch(InitContentDecryptData.rest_url + '/' + InitContentDecryptData.post_id, {
                headers: { 'X-WP-Nonce': InitContentDecryptData.nonce || '' }
            })
                .then(function (res) {
                    return res.ok ? res.json() : Promise.reject(res.status);
                })
                .then(function (data) {
                    if (data && data.k) {
                        renderDecrypted(base64DecodeUnicode(data.k));
                    }
                })
                .catch(function (err) {
                    if (InitContentDecryptData.debug) {
                        console.error('[Init Content Protector] Failed to fetch decryption key:', err);
                    }
                });
        }
    }, 1000);

    function base64DecodeUnicode(str) {
        return decodeURIComponent(atob(str).split('').map(function (c) {
            return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
    }

    function CryptoJSAesDecrypt(passphrase, encrypted_json_string) {
        var obj_json = typeof encrypted_json_string === 'string' ? JSON.parse(encrypted_json_string) : encrypted_json_string;

        var encrypted = obj_json.ciphertext;
        var salt = CryptoJS.enc.Hex.parse(obj_json.salt);
        var iv = CryptoJS.enc.Hex.parse(obj_json.iv);

        var key = CryptoJS.PBKDF2(passphrase, salt, { hasher: CryptoJS.algo.SHA512, keySize: 64/8, iterations: 999 });

        var decrypted = CryptoJS.AES.decrypt(encrypted, key, { iv: iv });
        return decrypted.toString(CryptoJS.enc.Utf8);
    }
});
