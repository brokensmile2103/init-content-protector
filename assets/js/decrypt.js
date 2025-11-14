// DECRYPT CONTENT
document.addEventListener('DOMContentLoaded', function () {
    if (typeof InitContentEncryptedPayload === 'undefined') return;
    if (typeof InitContentDecryptData === 'undefined' || !InitContentDecryptData.decryption_key) return;

    const container = document.querySelector(InitContentDecryptData.content_selector || '.entry-content');
    if (!container) return;

    setTimeout(function() {
        const decodedPassphrase = base64DecodeUnicode(InitContentDecryptData.decryption_key);
        container.innerHTML = CryptoJSAesDecrypt(decodedPassphrase, InitContentEncryptedPayload);
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
