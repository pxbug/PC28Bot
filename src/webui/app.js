/* 辣椒聊 Bot 前端 — 已精简为骨架。

仅保留：登录/看板框架、消息流、设置。群管理（成员/关键词/管理员/白名单/黑名单/监测）面板已移除。
新业务面板可直接在此添加。
*/
(function () {
  "use strict";

  var api = null;

  function init() {
    api = window.pywebview && window.pywebview.api;
    if (!api) return false;
    boot();
    return true;
  }

  if (init()) {
  } else {
    window.addEventListener("pywebviewready", function () { if (!api) init(); });
    var tries = 0;
    var timer = setInterval(function () {
      tries += 1;
      if (init()) { clearInterval(timer); return; }
      if (tries > 50) {
        clearInterval(timer);
        document.body.innerHTML = "<div style='padding:40px;text-align:center'>界面初始化失败，请重启客户端</div>";
      }
    }, 100);
  }

  function boot() {

  var state = {
    page: "dashboard",
    groups: [],
    loggedIn: false,
  };

  var $ = function (id) { return document.getElementById(id); };

  // ---------- 工具 ----------
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  // ---------- 页面切换 ----------
  function showPage(name) {
    state.page = name;
    ["dashboard", "monitor", "settings"].forEach(function (k) {
      var id = "page-" + k;
      var el = $(id);
      if (el) el.classList.toggle("hidden", k !== name);
    });
    document.querySelectorAll(".nav-item").forEach(function (b) {
      b.classList.toggle("active", b.dataset.page === name);
    });
    if (name === "dashboard") loadDashboard();
    if (name === "settings") loadSettings();
  }

  // 兼容旧页面标识（manage 已移除）
  state.page = "dashboard";

  // ---------- 登录 ----------
  function appendLoginLog(text) {
    var el = $("loginLog");
    if (!el) return;
    el.textContent += text + "\n";
    el.scrollTop = el.scrollHeight;
  }

  function setLoginStatus(text, cls) {
    var el = $("loginStatus");
    if (!el) return;
    el.textContent = text;
    el.className = "login-status " + cls;
  }

  function doLogin() {
    var btn = $("loginBtn");
    if (btn && btn.dataset.busy === "1") return;
    var phone = $("phoneInput").value.trim();
    var pwd = $("pwdInput").value;
    if (!phone || !pwd) { setLoginStatus("请输入账号和密码", "err"); return; }
    if (btn) {
      btn.dataset.busy = "1";
      btn.disabled = true;
      btn.textContent = "登录中...";
    }
    setLoginStatus("正在登录...", "");
    api.login(phone, pwd).then(function (r) {
      if (btn) {
        btn.dataset.busy = "";
        btn.disabled = false;
        btn.textContent = "登 录";
      }
      if (r.ok) {
        setLoginStatus("登录成功 userID=" + r.user_id, "ok");
        appendLoginLog("✓ 登录成功!");
        var lm = $("loginModal");
        if (lm) lm.classList.add("hidden");
        state.loggedIn = true;
        showPage("dashboard");
      } else if (r.captcha) {
        setLoginStatus("需要安全验证（请通过登录模块内的弹窗完成）", "err");
        appendLoginLog("✗ 需要安全验证");
      } else {
        setLoginStatus("登录失败", "err");
        appendLoginLog("✗ " + (r.error || "未知错误"));
      }
    }).catch(function (e) {
      if (btn) { btn.dataset.busy = ""; btn.disabled = false; btn.textContent = "登 录"; }
      setLoginStatus("登录异常", "err");
      appendLoginLog("✗ " + String(e));
    });
  }

  var loginBtn = $("loginBtn");
  if (loginBtn) loginBtn.addEventListener("click", doLogin);
  var pwdInput = $("pwdInput");
  if (pwdInput) pwdInput.addEventListener("keydown", function (e) { if (e.key === "Enter") doLogin(); });

  // ---------- 仪表盘 ----------
  function loadDashboard() {
    api.dashboard_stats().then(function (s) {
      if (!s) return;
      state.loggedIn = !!s.logged_in;
      if (!s.logged_in) {
        var m = $("loginModal");
        if (m) m.classList.remove("hidden");
      } else {
        var m2 = $("loginModal");
        if (m2) m2.classList.add("hidden");
      }
    }).catch(function () {});
  }

  // ---------- 设置 ----------
  function loadSettings() {
    api.dashboard_stats().then(function (s) {
      if (!s) return;
      var el = $("setAccount");
      if (el) {
        el.textContent = s.logged_in ? "已登录" : "未登录";
      }
    });
  }

  // ---------- 导航 ----------
  document.querySelectorAll(".nav-item").forEach(function (btn) {
    btn.addEventListener("click", function () { showPage(btn.dataset.page); });
  });

  showPage("dashboard");
  }

})();
