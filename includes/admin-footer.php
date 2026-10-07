<!-- ============================================ -->
<!-- 🎓 ADMIN FOOTER - ULTIMATE UTILITY EDITION -->
<!-- Fungsi global yang bisa dipakai di semua halaman -->
<!-- ============================================ -->

<!-- ===== TOAST NOTIFICATION SYSTEM ===== -->
<div class="toast-container" id="toastContainer"></div>

<!-- ===== CONFIRMATION DIALOG ===== -->
<div class="confirm-dialog" id="confirmDialog">
    <div class="confirm-backdrop"></div>
    <div class="confirm-content">
        <div class="confirm-icon" id="confirmIcon">⚠️</div>
        <h3 id="confirmTitle">Konfirmasi</h3>
        <p id="confirmMessage">Apakah Anda yakin?</p>
        <div class="confirm-actions">
            <button class="confirm-btn cancel" id="confirmCancel">Batal</button>
            <button class="confirm-btn ok" id="confirmOk">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<!-- ===== SCROLL TO TOP (Enhanced) ===== -->
<button class="scroll-to-top" id="scrollToTopBtn" title="Kembali ke atas">
    <i class="fas fa-arrow-up"></i>
    <svg class="scroll-progress-ring" viewBox="0 0 46 46">
        <circle cx="23" cy="23" r="20" stroke-width="3" fill="none" 
                stroke="rgba(255,255,255,0.2)" />
        <circle cx="23" cy="23" r="20" stroke-width="3" fill="none" 
                stroke="#f39c12" stroke-dasharray="125.6" stroke-dashoffset="125.6"
                id="scrollProgressCircle" />
    </svg>
</button>

<!-- ===== AJAX LOADING OVERLAY ===== -->
<div class="ajax-loading" id="ajaxLoading">
    <div class="loading-spinner"></div>
    <p>Memproses...</p>
</div>

<!-- ===== PAGE PERFORMANCE STATS (dev mode only) ===== -->
<?php if (defined('DEBUG_MODE') && DEBUG_MODE): ?>
<div class="perf-stats" id="perfStats">
    <small>
        <i class="fas fa-tachometer-alt"></i>
        <span id="perfLoadTime">-</span>ms | 
        <span id="perfRenderTime">-</span>ms
    </small>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- 🎨 FOOTER UTILITY STYLES -->
<!-- ============================================ -->
<style>
/* ===== TOAST NOTIFICATION ===== */
.toast-container {
    position: fixed;
    bottom: 30px;
    right: 30px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    pointer-events: none;
    max-width: 380px;
}
.toast {
    background: white;
    border-radius: 12px;
    padding: 1rem 1.3rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    display: flex;
    align-items: flex-start;
    gap: 0.8rem;
    pointer-events: auto;
    border-left: 4px solid;
    animation: toastSlideIn 0.3s ease;
    min-width: 300px;
}
.toast.removing {
    animation: toastSlideOut 0.3s ease forwards;
}
.toast-success { border-left-color: #28a745; }
.toast-error { border-left-color: #dc3545; }
.toast-warning { border-left-color: #ffc107; }
.toast-info { border-left-color: #17a2b8; }

.toast-icon {
    font-size: 1.3rem;
    flex-shrink: 0;
    margin-top: 0.1rem;
}
.toast-success .toast-icon { color: #28a745; }
.toast-error .toast-icon { color: #dc3545; }
.toast-warning .toast-icon { color: #ffc107; }
.toast-info .toast-icon { color: #17a2b8; }

.toast-content {
    flex: 1;
    min-width: 0;
}
.toast-title {
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 0.2rem;
    font-size: 0.92rem;
}
.toast-message {
    color: #666;
    font-size: 0.85rem;
    line-height: 1.4;
    word-wrap: break-word;
}
.toast-close {
    background: transparent;
    border: none;
    color: #ccc;
    cursor: pointer;
    padding: 0.2rem;
    font-size: 0.9rem;
    transition: color 0.2s ease;
}
.toast-close:hover { color: #e74c3c; }
.toast-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    height: 3px;
    background: currentColor;
    border-radius: 0 0 0 12px;
    animation: toastProgress linear forwards;
}

@keyframes toastSlideIn {
    from { transform: translateX(400px); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes toastSlideOut {
    to { transform: translateX(400px); opacity: 0; }
}
@keyframes toastProgress {
    from { width: 100%; }
    to { width: 0; }
}

/* ===== CONFIRMATION DIALOG ===== */
.confirm-dialog {
    position: fixed;
    inset: 0;
    z-index: 10001;
    display: none;
    align-items: center;
    justify-content: center;
}
.confirm-dialog.show {
    display: flex;
    animation: modalFadeIn 0.2s ease;
}
.confirm-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
}
.confirm-content {
    position: relative;
    background: white;
    border-radius: 20px;
    padding: 2.5rem;
    max-width: 420px;
    width: 90%;
    text-align: center;
    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    animation: modalSlideDown 0.3s ease;
}
.confirm-icon {
    font-size: 3.5rem;
    margin-bottom: 1rem;
    display: block;
}
.confirm-content h3 {
    color: #1e3a5f;
    margin-bottom: 0.5rem;
    font-size: 1.4rem;
}
.confirm-content p {
    color: #666;
    margin-bottom: 2rem;
    line-height: 1.5;
}
.confirm-actions {
    display: flex;
    gap: 0.8rem;
    justify-content: center;
}
.confirm-btn {
    padding: 0.8rem 2rem;
    border-radius: 10px;
    border: none;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 110px;
}
.confirm-btn:hover { transform: translateY(-2px); }
.confirm-btn.cancel {
    background: #f0f0f0;
    color: #333;
}
.confirm-btn.cancel:hover { background: #e0e0e0; }
.confirm-btn.ok {
    background: linear-gradient(135deg, #f39c12, #e67e22);
    color: white;
    box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
}
.confirm-btn.ok:hover {
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.5);
}
.confirm-btn.danger {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: white;
}

/* ===== SCROLL TO TOP (Enhanced) ===== */
.scroll-to-top {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 46px;
    height: 46px;
    border-radius: 50%;
    border: none;
    background: linear-gradient(135deg, #1e3a5f, #2c5f8d);
    color: white;
    font-size: 1.1rem;
    cursor: pointer;
    display: none;
    align-items: center;
    justify-content: center;
    box-shadow: 0 5px 20px rgba(30, 58, 95, 0.4);
    z-index: 998;
    transition: all 0.3s ease;
    padding: 0;
}
.scroll-to-top.show {
    display: flex;
    animation: scrollBtnIn 0.3s ease;
}
.scroll-to-top:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(30, 58, 95, 0.6);
}
.scroll-to-top i {
    position: relative;
    z-index: 2;
}
.scroll-progress-ring {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
}
.scroll-progress-ring circle {
    transition: stroke-dashoffset 0.1s ease;
}

@keyframes scrollBtnIn {
    from { transform: scale(0); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

/* ===== AJAX LOADING OVERLAY ===== */
.ajax-loading {
    position: fixed;
    inset: 0;
    background: rgba(30, 58, 95, 0.85);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 1rem;
    z-index: 10002;
    color: white;
}
.ajax-loading.show { display: flex; }
.loading-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid rgba(255,255,255,0.2);
    border-top-color: #f39c12;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
.ajax-loading p {
    font-size: 1rem;
    font-weight: 500;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ===== PERFORMANCE STATS (dev mode) ===== */
.perf-stats {
    position: fixed;
    bottom: 10px;
    left: 10px;
    background: rgba(0,0,0,0.7);
    color: #0f0;
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 0.75rem;
    z-index: 999;
    pointer-events: none;
}

/* ===== DARK MODE ===== */
html[data-theme="dark"] .toast {
    background: #1e2638;
    color: #e5e8ec;
    box-shadow: 0 10px 30px rgba(0,0,0,0.4);
}
html[data-theme="dark"] .toast-title { color: #e5e8ec; }
html[data-theme="dark"] .toast-message { color: #a0a8b5; }
html[data-theme="dark"] .confirm-content {
    background: #1e2638;
    color: #e5e8ec;
}
html[data-theme="dark"] .confirm-content h3 { color: #e5e8ec; }
html[data-theme="dark"] .confirm-content p { color: #a0a8b5; }
html[data-theme="dark"] .confirm-btn.cancel {
    background: #2a3550;
    color: #e5e8ec;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 576px) {
    .toast-container {
        left: 15px;
        right: 15px;
        bottom: 15px;
        max-width: none;
    }
    .toast { min-width: auto; }
    .scroll-to-top {
        bottom: 20px;
        right: 20px;
        width: 42px;
        height: 42px;
    }
}
</style>

<!-- ============================================ -->
<!-- ⚡ UTILITY SCRIPTS (Global helpers) -->
<!-- ============================================ -->
<script>
(function() {
    'use strict';

    // ===== TOAST NOTIFICATION SYSTEM =====
    // Cara pakai: UI.toast('success', 'Judul', 'Pesan', 3000);
    window.UI = window.UI || {};
    
    UI.toast = function(type, title, message, duration) {
        type = type || 'info';
        duration = duration || 4000;
        
        var container = document.getElementById('toastContainer');
        if (!container) return;
        
        var icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.style.position = 'relative';
        toast.innerHTML = 
            '<div class="toast-icon"><i class="fas fa-' + icons[type] + '"></i></div>' +
            '<div class="toast-content">' +
                '<div class="toast-title">' + (title || '') + '</div>' +
                '<div class="toast-message">' + (message || '') + '</div>' +
            '</div>' +
            '<button class="toast-close"><i class="fas fa-times"></i></button>' +
            '<div class="toast-progress" style="animation-duration:' + duration + 'ms"></div>';
        
        container.appendChild(toast);
        
        var closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', function() {
            removeToast(toast);
        });
        
        setTimeout(function() {
            removeToast(toast);
        }, duration);
        
        function removeToast(el) {
            if (el.classList.contains('removing')) return;
            el.classList.add('removing');
            setTimeout(function() { el.remove(); }, 300);
        }
    };

    // Alias singkat
    UI.success = function(msg, title) { UI.toast('success', title || 'Berhasil', msg); };
    UI.error = function(msg, title) { UI.toast('error', title || 'Error', msg); };
    UI.warning = function(msg, title) { UI.toast('warning', title || 'Peringatan', msg); };
    UI.info = function(msg, title) { UI.toast('info', title || 'Info', msg); };

    // ===== CONFIRMATION DIALOG =====
    // Cara pakai: UI.confirm('Yakin hapus?', function() { /* aksi */ });
    UI.confirm = function(message, onConfirm, options) {
        options = options || {};
        var dialog = document.getElementById('confirmDialog');
        var iconEl = document.getElementById('confirmIcon');
        var titleEl = document.getElementById('confirmTitle');
        var msgEl = document.getElementById('confirmMessage');
        var okBtn = document.getElementById('confirmOk');
        var cancelBtn = document.getElementById('confirmCancel');
        
        iconEl.textContent = options.icon || '⚠️';
        titleEl.textContent = options.title || 'Konfirmasi';
        msgEl.textContent = message;
        okBtn.textContent = options.okText || 'Ya, Lanjutkan';
        okBtn.className = 'confirm-btn ok' + (options.danger ? ' danger' : '');
        
        dialog.classList.add('show');
        
        function cleanup() {
            dialog.classList.remove('show');
            okBtn.removeEventListener('click', handleOk);
            cancelBtn.removeEventListener('click', handleCancel);
        }
        function handleOk() {
            cleanup();
            if (onConfirm) onConfirm();
        }
        function handleCancel() {
            cleanup();
            if (options.onCancel) options.onCancel();
        }
        
        okBtn.addEventListener('click', handleOk);
        cancelBtn.addEventListener('click', handleCancel);
    };

    // ===== AJAX LOADING =====
    UI.showLoading = function(text) {
        var el = document.getElementById('ajaxLoading');
        if (el) {
            var p = el.querySelector('p');
            if (p && text) p.textContent = text;
            el.classList.add('show');
        }
    };
    UI.hideLoading = function() {
        var el = document.getElementById('ajaxLoading');
        if (el) el.classList.remove('show');
    };

    // ===== CLIPBOARD HELPER =====
    UI.copyToClipboard = function(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function() {
                UI.success('Berhasil disalin ke clipboard!');
            });
        }
        // Fallback
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            UI.success('Berhasil disalin ke clipboard!');
        } catch (e) {
            UI.error('Gagal menyalin');
        }
        document.body.removeChild(ta);
    };

    // ===== AJAX HELPER =====
    // Cara pakai: UI.ajax('POST', '/admin/delete.php', {id: 5}).then(data => ...)
    UI.ajax = function(method, url, data) {
        return new Promise(function(resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open(method, url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            
            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        resolve(JSON.parse(xhr.responseText));
                    } catch (e) {
                        resolve(xhr.responseText);
                    }
                } else {
                    reject(xhr);
                }
            };
            xhr.onerror = function() { reject(xhr); };
            
            if (data) {
                var params = [];
                for (var key in data) {
                    if (data.hasOwnProperty(key)) {
                        params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
                    }
                }
                xhr.send(params.join('&'));
            } else {
                xhr.send();
            }
        });
    };

    // ===== SCROLL TO TOP (Enhanced) =====
    var scrollBtn = document.getElementById('scrollToTopBtn');
    var progressCircle = document.getElementById('scrollProgressCircle');
    var circumference = 2 * Math.PI * 20; // r=20
    
    if (scrollBtn) {
        window.addEventListener('scroll', function() {
            var scrollTop = window.scrollY;
            var docHeight = document.documentElement.scrollHeight - window.innerHeight;
            var scrollPercent = docHeight > 0 ? scrollTop / docHeight : 0;
            
            if (scrollTop > 300) {
                scrollBtn.classList.add('show');
            } else {
                scrollBtn.classList.remove('show');
            }
            
            if (progressCircle) {
                var offset = circumference - (scrollPercent * circumference);
                progressCircle.style.strokeDashoffset = offset;
            }
        });
        
        scrollBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ===== IDLE DETECTION (Warning jika user tidak aktif 15 menit) =====
    var idleTimeout;
    var idleWarningShown = false;
    var IDLE_TIME = 15 * 60 * 1000; // 15 menit
    
    function resetIdleTimer() {
        if (idleWarningShown) {
            idleWarningShown = false;
        }
        clearTimeout(idleTimeout);
        idleTimeout = setTimeout(function() {
            if (!idleWarningShown) {
                idleWarningShown = true;
                UI.warning(
                    'Anda tidak aktif selama 15 menit. Demi keamanan, pertimbangkan untuk logout jika sudah selesai.',
                    'Sesi Idle'
                );
            }
        }, IDLE_TIME);
    }
    
    ['mousemove', 'keydown', 'scroll', 'click'].forEach(function(event) {
        document.addEventListener(event, resetIdleTimer);
    });
    resetIdleTimer();

    // ===== PERFORMANCE TRACKING =====
    <?php if (defined('DEBUG_MODE') && DEBUG_MODE): ?>
    window.addEventListener('load', function() {
        setTimeout(function() {
            var perf = performance.timing;
            var loadTime = perf.loadEventEnd - perf.navigationStart;
            var renderTime = perf.domContentLoadedEventEnd - perf.navigationStart;
            
            var loadEl = document.getElementById('perfLoadTime');
            var renderEl = document.getElementById('perfRenderTime');
            if (loadEl) loadEl.textContent = loadTime;
            if (renderEl) renderEl.textContent = renderTime;
        }, 100);
    });
    <?php endif; ?>

    // ===== FORM SUBMIT PROTECTION =====
    // Prevent double-submit
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (form.tagName !== 'FORM') return;
        if (form.dataset.submitting === 'true') {
            e.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn && !submitBtn.dataset.originalHtml) {
            submitBtn.dataset.originalHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
        }
        // Reset after 3s in case of AJAX submit
        setTimeout(function() {
            if (form.dataset.submitting === 'true') {
                form.dataset.submitting = 'false';
                if (submitBtn && submitBtn.dataset.originalHtml) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtn.dataset.originalHtml;
                }
            }
        }, 3000);
    });

    // ===== AUTO-RESIZE TEXTAREA =====
    document.addEventListener('input', function(e) {
        if (e.target.tagName === 'TEXTAREA' && e.target.classList.contains('auto-resize')) {
            e.target.style.height = 'auto';
            e.target.style.height = e.target.scrollHeight + 'px';
        }
    });

    // ===== CONFIRM DELETE LINKS =====
    document.addEventListener('click', function(e) {
        var link = e.target.closest('[data-confirm]');
        if (!link) return;
        
        e.preventDefault();
        var message = link.getAttribute('data-confirm');
        var isDanger = link.getAttribute('data-danger') !== null;
        
        UI.confirm(message, function() {
            if (link.tagName === 'A') {
                window.location.href = link.href;
            } else if (link.tagName === 'BUTTON' && link.form) {
                link.form.submit();
            }
        }, { danger: isDanger, icon: isDanger ? '🗑️' : '⚠️' });
    });

    // ===== KEYBOARD SHORTCUTS GLOBAL =====
    document.addEventListener('keydown', function(e) {
        // Skip kalau di input
        if (e.target.matches('input, textarea, select')) return;
        
        // Ctrl+/ : Focus search (sudah di handle header)
        // ? : Show help
        if (e.key === '?' && !e.ctrlKey && !e.metaKey) {
            UI.info(
                'Ctrl+K: Search | Ctrl+N: New Article | Ctrl+D: Dashboard | ?: Help',
                '⌨️ Keyboard Shortcuts'
            );
        }
    });

    // ===== UNSAVED CHANGES WARNING =====
    var hasUnsavedChanges = false;
    
    UI.markUnsaved = function() { hasUnsavedChanges = true; };
    UI.markSaved = function() { hasUnsavedChanges = false; };
    
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });
    
    // Listen form changes
    document.addEventListener('input', function(e) {
        if (e.target.matches('form input, form textarea, form select')) {
            hasUnsavedChanges = true;
        }
    });
    
    // Reset on form submit
    document.addEventListener('submit', function() {
        hasUnsavedChanges = false;
    });

    // ===== CONSOLE BRANDING =====
    console.log(
        '%c🎓 Admin Footer Ultimate Loaded!', 
        'font-size:14px;color:#1e3a5f;font-weight:bold;'
    );
    console.log(
        '%cGlobal helpers: UI.toast(), UI.confirm(), UI.ajax(), UI.copyToClipboard()', 
        'font-size:11px;color:#888;'
    );
    console.log(
        '%cType "?" di halaman untuk lihat keyboard shortcuts', 
        'font-size:11px;color:#888;'
    );

})();
</script>
</body>
</html>