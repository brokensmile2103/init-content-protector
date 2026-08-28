/**
 * Init AntiSnap — Anti-Screenshot & Content Protection Library
 * A lightweight, standalone script by Init HTML (https://inithtml.com)
 * No dependencies. Works on any website.
 *
 * @version 5.3.0
 * @author Init HTML
 * @license MIT
 *
 * Usage: Include this script on any page.
 * Customize via window.InitAntiSnapConfig before DOMContentLoaded, e.g.:
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
 */

(function () {
    'use strict';

    // --- SYSTEM CONFIGURATION ---
    const CONFIG = Object.assign({
        TRUST_WINDOW_MS: 2000,
        SCORE_TO_TRIGGER: 5,
        JUMP_THRESHOLD_RATIO: 0.3,
        ENABLE_ALERT: true,
        ALERT_MESSAGE: '⚠️ Automated screenshot or scraping tool detected. Action blocked!',
        onDetect: function () {} // (reason: 'scroll' | 'devtools') => void — optional, site-provided
    }, window.InitAntiSnapConfig || {});

    // --- STATE VARIABLES ---
    let humanTrustExpires = 0;
    let suspiciousScore = 0;
    let lastScrollY = window.scrollY || window.pageYOffset;
    let watchdogTimeout = null;
    let isDefending = false; // Khóa chống lặp khi đang trong chu kỳ phòng thủ

    const isDesktop = !/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    const physicalScreenHeight = window.screen.height || 1080;
    const passiveOpts = { passive: true };

    // Mốc so sánh cho God Mode Detector — xem inspectDevToolsCapture() bên dưới
    let baselineScrollbarWidth = null;

    // Dùng chung giữa phần cấp trust bàn phím và God Mode Detector — xem cả 2
    // nơi sử dụng bên dưới.
    let lastSafeKeyAt = 0;

    // --- GRANT "TRUST TOKEN" FOR REAL USERS ---
    function grantHumanTrust(duration) {
        humanTrustExpires = Math.max(humanTrustExpires, Date.now() + duration);
        suspiciousScore = 0;
    }

    const humanEvents = ['wheel', 'touchstart', 'touchmove', 'mousedown', 'click'];
    humanEvents.forEach(evt => {
        window.addEventListener(evt, (e) => {
            if (e.isTrusted) {
                const duration = (evt === 'wheel' || evt.includes('touch')) ? CONFIG.TRUST_WINDOW_MS : CONFIG.TRUST_WINDOW_MS * 2;
                grantHumanTrust(duration);
            }
        }, passiveOpts);
    });

    // --- Trusted keyboard scroll shortcuts (v5.3.0: false-positive fix) ---
    // Kiểm tra CẢ e.code lẫn e.key — khớp 1 trong 2 là đủ. e.code là mã phím
    // VẬT LÝ (có thể khác nhau tuỳ bàn phím/numpad/NumLock cho cùng 1 chức
    // năng); e.key là mã phím LOGIC, luôn phản ánh đúng chức năng thực hiện
    // bất kể phần cứng nào tạo ra nó. Dùng cả 2 để không bỏ sót trường hợp nào.
    const safeKeyCodes = ['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Space', 'Home', 'End'];
    const safeKeyValues = ['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', ' ', 'Spacebar', 'Home', 'End'];

    window.addEventListener('keydown', (e) => {
        if (!e.isTrusted) return;
        if (safeKeyCodes.includes(e.code) || safeKeyValues.includes(e.key)) {
            // Trust kéo dài 5 giây (2.5x) thay vì 1.5x trước đây — an toàn tuyệt
            // đối vì chỉ cấp cho input isTrusted thật, không script nào giả được;
            // nới rộng để loại bỏ mọi rủi ro timing giữa lúc bấm phím và lúc sự
            // kiện scroll thực sự xảy ra trên các trang rất dài.
            grantHumanTrust(CONFIG.TRUST_WINDOW_MS * 2.5);
            lastSafeKeyAt = Date.now();
        }
    }, passiveOpts);

    // --- 1. SCROLL ANALYZER (Anti-Extensions) ---
    window.addEventListener('scroll', function () {
        if (isDefending) return;

        const now = Date.now();
        const currentY = window.scrollY || window.pageYOffset;
        const deltaY = Math.abs(currentY - lastScrollY);
        lastScrollY = currentY;

        // "Nhảy" tới đúng đầu trang HOẶC đúng cuối trang đều là hành vi điều
        // hướng hợp lệ rất phổ biến (phím Home/End, hoặc kéo thanh cuộn tới
        // cùng) — coi là an toàn vô điều kiện, không phụ thuộc trust window có
        // kịp cấp hay không.
        const maxScrollY = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
        const isAtTop = currentY === 0;
        const isAtBottom = Math.abs(currentY - maxScrollY) < 4; // dung sai làm tròn nhỏ

        if (deltaY === 0 || isAtTop || isAtBottom) {
            if (isAtTop || isAtBottom) suspiciousScore = 0;
            return;
        }

        if (now < humanTrustExpires) return;

        const viewPortHeight = window.innerHeight;
        const isBigJump = deltaY > (viewPortHeight * CONFIG.JUMP_THRESHOLD_RATIO);

        if (isBigJump) {
            const isViewportJump = Math.abs(deltaY - viewPortHeight) < 80;
            let penalty = isViewportJump ? 3 : 1;
            suspiciousScore += penalty;

            if (suspiciousScore >= CONFIG.SCORE_TO_TRIGGER) {
                triggerUltimateDefense('scroll');
                suspiciousScore = 0;
            }
        } else {
            suspiciousScore = Math.max(0, suspiciousScore - 0.5);
        }
    }, passiveOpts);

    // --- 2. DEVTOOLS "GOD MODE" DETECTOR (PC ONLY) ---
    // Phát hiện Chrome DevTools Command Menu → "Capture full size screenshot":
    // tính năng này tạm thời resize CHÍNH viewport của trang để cao bằng toàn
    // bộ nội dung (kể cả phần ngoài màn hình), chụp 1 lần rồi khôi phục — hoàn
    // toàn không đi qua sự kiện 'scroll' nào nên Scroll Analyzer ở trên không
    // bắt được. Theo dõi qua cả 'resize' event lẫn ResizeObserver.
    function inspectDevToolsCapture() {
        if (!isDesktop || isDefending) return;

        // Vừa dùng phím điều hướng (Home/End/PageUp/PageDown...) trong 1.5 giây
        // gần đây — tạm bỏ qua. Nhảy nhanh tới cuối 1 trang dài có thể khiến
        // nội dung lazy-load hàng loạt, gây layout đổi dồn dập đúng lúc đó,
        // không liên quan gì tới DevTools.
        if (Date.now() - lastSafeKeyAt < 1500) return;

        const docEl = document.documentElement;
        const hasVerticalOverflow = docEl.scrollHeight > (window.innerHeight + 100);
        const scrollbarWidth = window.innerWidth - docEl.clientWidth;

        if (baselineScrollbarWidth === null) {
            // Lần đo đầu tiên — chỉ dùng để LÀM MỐC, không đánh giá trigger ở lần
            // này. Trên macOS/mobile/Linux dùng overlay scrollbar, scrollbarWidth
            // vốn dĩ đã là 0 ngay từ đầu -> mốc sẽ là 0, và điều kiện "chuyển từ
            // có sang mất" bên dưới sẽ không bao giờ đúng cho nhóm này -> an toàn.
            baselineScrollbarWidth = scrollbarWidth;
            return;
        }

        // Anomaly 1: trang có nội dung tràn chiều dọc NHƯNG scrollbar (từng thật
        // sự có độ rộng >= 8px, tức scrollbar cổ điển kiểu Windows/Linux) đột
        // ngột biến mất về 0 — CHỈ coi đây là dấu hiệu đáng ngờ nếu nó từng thật
        // sự tồn tại rồi mất đi, không phải trạng thái tĩnh vốn dĩ bình thường
        // trên nhiều nền tảng (macOS, mobile, Linux overlay scrollbar).
        const hadRealScrollbar = baselineScrollbarWidth >= 8;
        const isScrollbarHiddenByDevTools = hadRealScrollbar && hasVerticalOverflow && scrollbarWidth === 0;

        // Anomaly 2: viewport lớn hơn hẳn màn hình vật lý thật — vì đã bị nới ra
        // để chứa toàn bộ trang. Đây là tín hiệu đáng tin cậy nhất, không có vấn
        // đề false-positive như Anomaly 1.
        const currentInnerHeight = window.innerHeight;
        const isUnnaturallyTall = currentInnerHeight > (physicalScreenHeight * 1.2);

        if (isScrollbarHiddenByDevTools || isUnnaturallyTall) {
            triggerUltimateDefense('devtools');
        }
    }

    window.addEventListener('resize', inspectDevToolsCapture, passiveOpts);

    if ('ResizeObserver' in window) {
        const resizeObserver = new ResizeObserver(() => {
            inspectDevToolsCapture();
        });
        resizeObserver.observe(document.documentElement);
    }

    // --- KHÔI PHỤC (idempotent — gọi bao nhiêu lần cũng an toàn) ---
    function restoreView() {
        clearTimeout(watchdogTimeout);
        watchdogTimeout = null;

        document.body.style.transition = 'filter 0.5s ease';
        document.body.style.filter = 'none';
        isDefending = false;
    }

    // Rời tab (đổi tab, minimize...) trong lúc đang mờ rồi quay lại -> khôi
    // phục ngay lập tức thay vì bắt người dùng chờ hết giờ.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && isDefending) {
            restoreView();
        }
    });

    // --- ULTIMATE DEFENSE MECHANISM ---
    function triggerUltimateDefense(reason) {
        if (isDefending) return;
        isDefending = true;

        // Hook báo cáo tuỳ chọn cho site tích hợp (logging/backend riêng) — chạy
        // best-effort, không bao giờ được để lỗi ở đây làm hỏng phần phòng thủ
        // hình ảnh bên dưới.
        try {
            CONFIG.onDetect(reason);
        } catch (err) {
            /* nuốt lỗi từ callback do site tích hợp cung cấp — không phải trách
               nhiệm của thư viện này nếu code phía họ có bug */
        }

        // 1. Instant Visual Destruction
        document.body.style.transition = 'filter 0s';
        document.body.style.filter = 'blur(20px) grayscale(100%)';

        // 2. Coordinate Disruption
        window.scrollTo(0, Math.max(0, window.scrollY - 25));

        // 3. SAFETY-NET WATCHDOG: lưới an toàn cuối cùng, ĐỘC LẬP với alert() —
        // chỉ thật sự có ý nghĩa khi ENABLE_ALERT=false, hoặc các tình huống bất
        // thường khác ngoài dự liệu. Khi alert() đang bật, dòng khôi phục NGAY
        // SAU alert() bên dưới luôn thắng watchdog này.
        watchdogTimeout = setTimeout(restoreView, 8000);

        if (CONFIG.ENABLE_ALERT) {
            // Đợi tối thiểu 1 khung hình để trình duyệt kịp VẼ (paint) trạng thái
            // mờ TRƯỚC KHI alert() đóng băng cả tab.
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    // alert() ĐƯỢC GIỮ LẠI CÓ CHỦ ĐÍCH: đây không đơn thuần là hiện
                    // cảnh báo, mà CHÍNH LÀ cơ chế ngắt — nó chặn TOÀN BỘ luồng JS của
                    // tab, kể cả code của các extension chụp-cuộn-ghép (GoFullPage,
                    // FireShot...) đang chạy song song. Đã kiểm chứng thực tế: banner
                    // không chặn thì các extension này bỏ qua hoàn toàn.
                    alert(CONFIG.ALERT_MESSAGE);

                    // Chạy NGAY khi alert() được dismiss — bất kể bằng cách nào. Đặt
                    // tuần tự NGAY SAU alert() (thay vì trong 1 setTimeout độc lập
                    // chạy song song, đua với thời điểm alert thực sự được dismiss)
                    // loại bỏ hoàn toàn race condition từng gây ra bug "mờ vĩnh viễn".
                    restoreView();
                });
            });
        }
    }

})();
