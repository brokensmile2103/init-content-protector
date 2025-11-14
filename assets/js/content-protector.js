// CONTENT PROTECTOR
(function() {
    if (!InitContentProtectorData.jsContentProtectionEnabled) return;

    const container = InitContentProtectorData.content_selector || '.entry-content';
    
    // Chặn print từ nhiều nguồn khác nhau
    const blockPrint = () => {
        // Override window.print
        window.print = function() {
            return false;
        };
        
        // Chặn beforeprint event
        window.addEventListener('beforeprint', (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }, { passive: false, capture: true });
        
        // Chặn afterprint event (backup)
        window.addEventListener('afterprint', (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }, { passive: false, capture: true });
        
        // Chặn media query print
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                body { display: none !important; }
                * { display: none !important; }
            }
        `;
        document.head.appendChild(style);
    };
    
    const protectElements = (selector, isImage = false) => {
        const elements = document.querySelectorAll(selector);
        if (elements.length === 0) return;
        
        const preventDefault = (e) => {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            return false;
        };
        
        elements.forEach(element => {
            if (element.dataset.protected === 'true') return;
            element.dataset.protected = 'true';
            
            // Cài đặt thuộc tính bảo vệ
            element.draggable = false;
            element.style.userSelect = 'none';
            element.style.webkitUserSelect = 'none';
            element.style.mozUserSelect = 'none';
            element.style.msUserSelect = 'none';
            element.style.webkitTouchCallout = 'none';
            element.style.webkitUserDrag = 'none';
            element.style.pointerEvents = 'auto'; // Giữ để có thể tương tác
            
            // Danh sách sự kiện cần chặn
            const events = [
                'selectstart', 'dragstart', 'contextmenu',
                'mousedown', 'mouseup', 'mousemove',
                'copy', 'cut', 'paste'
            ];
            
            events.forEach(event => {
                element.addEventListener(event, preventDefault, {
                    passive: false,
                    capture: true
                });
            });
            
            // Chặn các phím tắt
            element.addEventListener('keydown', (e) => {
                // Ctrl combinations
                if (e.ctrlKey && ['a', 'c', 'x', 'v', 's', 'p', 'u'].includes(e.key.toLowerCase())) {
                    preventDefault(e);
                }
                
                // Cmd+P trên macOS
                if (e.metaKey && e.key.toLowerCase() === 'p') {
                    preventDefault(e);
                }
                
                // Developer tools
                if (e.key === 'F12' ||
                    (e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key.toUpperCase())) ||
                    (e.ctrlKey && e.key.toLowerCase() === 'u')) {
                    preventDefault(e);
                }
                
                // F5 refresh
                if (e.key === 'F5' || (e.ctrlKey && e.key.toLowerCase() === 'r')) {
                    preventDefault(e);
                }
            }, { passive: false, capture: true });
            
            // Chặn right-click
            element.addEventListener('mouseup', (e) => {
                if (e.button === 2) preventDefault(e);
            }, { passive: false, capture: true });
            
            // Clear selection khi focus
            if (!isImage) {
                element.addEventListener('focus', () => {
                    if (window.getSelection) {
                        window.getSelection().removeAllRanges();
                    }
                });
            }
        });
    };
    
    // Chặn print ở document level
    document.addEventListener('keydown', (e) => {
        // Chặn Ctrl+P và Cmd+P ở mọi nơi
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
        
        // Chặn F12 và dev tools
        if (e.key === 'F12' ||
            (e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key.toUpperCase())) ||
            (e.ctrlKey && e.key.toLowerCase() === 'u')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
    }, { passive: false, capture: true });
    
    // Monitor cho việc mở dev tools
    let devtools = {
        open: false,
        orientation: null
    };

    
    // Khởi tạo bảo vệ
    blockPrint();
    protectElements(container, false);
    
    // Chặn Ctrl+Shift+I qua window
    window.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.shiftKey && e.key.toUpperCase() === 'I') {
            e.preventDefault();
            return false;
        }
    }, { passive: false, capture: true });
    
    // Disable right-click toàn trang
    document.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        return false;
    }, { passive: false, capture: true });
    
    // Chặn print qua menu browser
    window.addEventListener('focus', blockPrint);
    window.addEventListener('blur', blockPrint);
})();
