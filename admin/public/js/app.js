/**
 * PC28 Admin — Global JavaScript
 * Vanilla JS with clean reactive patterns (no framework needed)
 */

// ── Toast ─────────────────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
    toast.innerHTML = `<span>${icon}</span><span>${escHtml(message)}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ── Modal ─────────────────────────────────────────────────────────────
function openModal(id) {
    const overlay = document.getElementById('modal-' + id);
    if (overlay) overlay.classList.add('show');
}
function closeModal(id) {
    const overlay = document.getElementById('modal-' + id);
    if (overlay) overlay.classList.remove('show');
}

// Close modal on overlay click
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
    }
});

// ── Auto-show toast from PHP ─────────────────────────────────────────
(function() {
    const el = document.getElementById('toast-success');
    if (el) showToast(el.dataset.msg || '操作成功', 'success');
    const err = document.getElementById('toast-error');
    if (err) showToast(err.dataset.msg || '发生错误', 'error');
})();

// ── Format numbers ────────────────────────────────────────────────────
function formatMoney(amount) {
    const n = parseFloat(amount);
    if (isNaN(n)) return '0.00';
    return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatNumber(n) {
    if (isNaN(n)) return '0';
    return parseInt(n).toLocaleString('zh-CN');
}

function formatTime(ts) {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleString('zh-CN', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
}

function timeAgo(ts) {
    const diff = Date.now() - ts * 1000;
    if (diff < 60000) return '刚刚';
    if (diff < 3600000) return Math.floor(diff / 60000) + '分钟前';
    if (diff < 86400000) return Math.floor(diff / 3600000) + '小时前';
    return Math.floor(diff / 86400000) + '天前';
}

// ── Form helpers ──────────────────────────────────────────────────────
function apiFetch(url, options = {}) {
    return fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
        ...options,
    }).then(r => {
        if (r.redirected && r.url.includes('/login')) {
            location.href = '/login';
            return Promise.reject(new Error('Unauthorized'));
        }
        return r.json().catch(() => r.text().then(t => ({ _raw: t })));
    });
}

function apiPost(url, data = {}) {
    const form = new FormData();
    for (const [k, v] of Object.entries(data)) form.append(k, v);
    return apiFetch(url, { method: 'POST', body: form });
}

function apiDelete(url) {
    return apiFetch(url, { method: 'DELETE' });
}

// ── Pagination ────────────────────────────────────────────────────────
function renderPagination(container, currentPage, totalPages, onPage) {
    if (totalPages <= 1) return;
    let html = `
        <button class="pg-btn" onclick="${onPage}(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>
            ‹
        </button>`;

    const range = [];
    const delta = 2;
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) {
            range.push(i);
        }
    }
    let last = 0;
    for (const i of range) {
        if (last && i - last > 1) html += '<span class="pg-btn" style="pointer-events:none">…</span>';
        html += `<button class="pg-btn ${i === currentPage ? 'active' : ''}" onclick="${onPage}(${i})">${i}</button>`;
        last = i;
    }

    html += `<button class="pg-btn" onclick="${onPage}(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>›</button>`;
    container.innerHTML = html;
}

// ── Search with debounce ─────────────────────────────────────────────
function debounce(fn, ms = 350) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), ms);
    };
}

// ── Confirm dialog ───────────────────────────────────────────────────
function confirmAction(msg, onConfirm) {
    if (confirm(msg)) onConfirm();
}

// ── Loading state ────────────────────────────────────────────────────
function setLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
    btn.textContent = loading ? '处理中…' : btn.dataset.originalText;
}

// ── Simple inline edit ───────────────────────────────────────────────
function inlineEdit(inputEl, saveUrl, field) {
    const val = inputEl.textContent.trim();
    const original = val;
    inputEl.contentEditable = 'true';
    inputEl.focus();
    inputEl.style.outline = 'none';
    inputEl.style.borderBottom = '2px solid var(--accent)';

    const finish = (save) => {
        inputEl.contentEditable = 'false';
        inputEl.style.borderBottom = '';
        if (!save) { inputEl.textContent = original; return; }
        const newVal = inputEl.textContent.trim();
        if (newVal === original) return;
        setLoading(inputEl, true);
        apiPost(saveUrl, { [field]: newVal }).then(() => {
            showToast('已更新');
        }).catch(() => {
            inputEl.textContent = original;
            showToast('更新失败', 'error');
        }).finally(() => setLoading(inputEl, false));
    };

    inputEl.addEventListener('blur', () => finish(true));
    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); inputEl.blur(); }
        if (e.key === 'Escape') { inputEl.textContent = original; inputEl.blur(); }
    });
}

// ── Table sort ───────────────────────────────────────────────────────
function sortableTable(th, column) {
    const table = th.closest('table');
    const rows = Array.from(table.tBodies[0].rows);
    const asc = th.dataset.sortDir !== 'asc';
    th.dataset.sortDir = asc ? 'asc' : 'desc';
    th.querySelector('span.sort-icon').textContent = asc ? '↑' : '↓';
    rows.sort((a, b) => {
        const aVal = a.cells[th.cellIndex].textContent.trim();
        const bVal = b.cells[th.cellIndex].textContent.trim();
        const aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
        const bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));
        if (!isNaN(aNum) && !isNaN(bNum)) return asc ? aNum - bNum : bNum - aNum;
        return asc ? aVal.localeCompare(bVal, 'zh-CN') : bVal.localeCompare(aVal, 'zh-CN');
    });
    rows.forEach(r => table.tBodies[0].appendChild(r));
}

// ── Mini sparkline chart (CSS-only via SVG) ──────────────────────────
function renderSparkline(container, data, color = 'var(--accent)') {
    if (!data || data.length < 2) return;
    const w = container.offsetWidth || 120;
    const h = 40;
    const min = Math.min(...data);
    const max = Math.max(...data);
    const range = max - min || 1;
    const pts = data.map((v, i) => {
        const x = (i / (data.length - 1)) * w;
        const y = h - ((v - min) / range) * (h - 4) - 2;
        return `${x},${y}`;
    });
    container.innerHTML = `<svg width="${w}" height="${h}" style="display:block">
        <defs>
            <linearGradient id="sg-${color.replace(/[^a-z]/g,'')}" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="${color}" stop-opacity="0.3"/>
                <stop offset="100%" stop-color="${color}" stop-opacity="0"/>
            </linearGradient>
        </defs>
        <polygon points="${pts.join(' ')} ${w},${h} 0,${h}" fill="url(#sg-${color.replace(/[^a-z]/g,'')})"/>
        <polyline points="${pts.join(' ')}" fill="none" stroke="${color}" stroke-width="1.5" stroke-linejoin="round"/>
    </svg>`;
}
