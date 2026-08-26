/**
 * PC28 Admin — 全局 JS 工具
 */

// ==================== Toast ====================

let _toastTimer = null;
function toast(msg, type = 'default', duration = 2500) {
    let el = document.getElementById('toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'toast';
        el.className = 'toast';
        document.body.appendChild(el);
    }
    el.textContent = msg;
    el.className = 'toast show' + (type === 'success' ? ' success' : type === 'error' ? ' error' : '');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => { el.classList.remove('show'); }, duration);
}

// ==================== Modal ====================

function modalOpen(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('show');
}

function modalClose(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
}

function modalCloseAll() {
    document.querySelectorAll('.modal-overlay.show').forEach(el => el.classList.remove('show'));
}

document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
    if (e.target.dataset.modalClose) {
        modalClose(e.target.dataset.modalClose);
    }
});

// ==================== API 封装 ====================

async function api(url, data = {}, method = 'POST') {
    const body = method === 'GET' ? '' : JSON.stringify(data);
    const headers = {
        'Content-Type': 'application/json',
        ...(window.__csrf ? { 'X-CSRF-Token': window.__csrf } : {}),
    };

    const resp = await fetch(url, { method, headers, body: method === 'GET' ? undefined : body });
    const json = await resp.json();
    if (json.code !== 0) {
        throw new Error(json.msg || '请求失败');
    }
    return json;
}

// ==================== 分页 ====================

function buildPagination(current, total, onPage) {
    if (total <= 1) return '';
    const pages = [];
    for (let i = 1; i <= total; i++) {
        if (i === 1 || i === total || (i >= current - 2 && i <= current + 2)) {
            pages.push(i);
        } else if (pages[pages.length - 1] !== '...') {
            pages.push('...');
        }
    }
    return pages.map(p => {
        if (p === '...') return `<span class="disabled">…</span>`;
        if (p === current) return `<span class="current">${p}</span>`;
        return `<a href="javascript:;" data-page="${p}">${p}</a>`;
    }).join('');
}

// ==================== 金额 ====================

function fmtMoney(v) {
    return Number(v).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ==================== 时间 ====================

function fmtDate(v) {
    if (!v) return '—';
    const d = new Date(v);
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// ==================== 搜索防抖 ====================

function debounce(fn, delay = 400) {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), delay);
    };
}

// ==================== 确认对话框 ====================

async function confirm(msg) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay show';
        overlay.innerHTML = `
            <div class="modal" style="max-width:360px">
                <div class="modal-header">
                    <span class="modal-title">确认操作</span>
                    <button class="modal-close" data-modal-close="__confirm__">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="font-size:15px;color:var(--text-primary)">${msg}</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" id="__cfm-cancel__">取消</button>
                    <button class="btn btn-danger" id="__cfm-ok__">确认</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        overlay.addEventListener('click', e => {
            if (e.target === overlay || e.target.dataset.modalClose === '__confirm__') {
                document.body.removeChild(overlay);
                resolve(false);
            }
        });
        document.getElementById('__cfm-cancel__').onclick = () => {
            document.body.removeChild(overlay); resolve(false);
        };
        document.getElementById('__cfm-ok__').onclick = () => {
            document.body.removeChild(overlay); resolve(true);
        };
    });
}

// ==================== 数字动画 ====================

function countUp(el, target, duration = 600) {
    const start = 0;
    const startTime = performance.now();
    function step(now) {
        const progress = Math.min((now - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = fmtMoney(start + (target - start) * eased);
        if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}
