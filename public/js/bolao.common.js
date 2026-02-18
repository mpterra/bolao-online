(function () {
  "use strict";

  // =========================================================
  // GUARDA GLOBAL: impede inicialização dupla DO COMMON
  // =========================================================
  if (window.__BOLAO_COMMON_INIT__ === true) return;
  window.__BOLAO_COMMON_INIT__ = true;

  // =========================================================
  // CONFIG (SEM JS INLINE) — lê <script type="application/json" id="app-config">
  // =========================================================
  function readAppConfig() {
    const cfgEl = document.getElementById("app-config");
    let APP_CFG = null;
    try {
      APP_CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
    } catch (e) {
      APP_CFG = {};
    }
    return APP_CFG || {};
  }

  const APP_CFG = readAppConfig();

  window.__APP_USER__ = (APP_CFG && APP_CFG.user) ? APP_CFG.user : null;
  window.__LOCK_INFO__ = (APP_CFG && APP_CFG.lock) ? APP_CFG.lock : null;

  const ENDPOINTS = {
    save_games: (APP_CFG && APP_CFG.endpoints && APP_CFG.endpoints.save_games) ? APP_CFG.endpoints.save_games : "/bolao-da-copa/public/app.php?action=save",
    save_group_rank: (APP_CFG && APP_CFG.endpoints && APP_CFG.endpoints.save_group_rank) ? APP_CFG.endpoints.save_group_rank : "/bolao-da-copa/public/app.php?action=save_group_rank",
    receipt_url: (APP_CFG && APP_CFG.endpoints && APP_CFG.endpoints.receipt_url) ? APP_CFG.endpoints.receipt_url : "/bolao-da-copa/php/recibo.php?action=pdf"
  };

  // =========================================================
  // TOAST
  // =========================================================
  let toastTimer = null;

  function showToast(msg, isError) {
    const toast = document.getElementById("toast");
    if (!toast) return;

    toast.textContent = String(msg || "");
    toast.classList.add("is-open");

    toast.style.borderColor = isError ? "rgba(255,140,140,.35)" : "rgba(16,208,138,.30)";
    toast.style.background = isError ? "rgba(70,0,0,.45)" : "rgba(0,0,0,.55)";

    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("is-open"), 2200);
  }

  // =========================================================
  // HELPERS
  // =========================================================
  function clampScore(v) {
    if (v === "" || v === null || typeof v === "undefined") return null;
    const n = Number(v);
    if (!Number.isFinite(n)) return null;
    const i = Math.trunc(n);
    if (i < 0 || i > 99) return null;
    return i;
  }

  function markInvalid(input, invalid) {
    if (!input) return;
    if (invalid) input.classList.add("is-invalid");
    else input.classList.remove("is-invalid");
  }

  function fetchJson(url, options) {
    return fetch(url, options).then(async (res) => {
      let data = null;
      try { data = await res.json(); } catch (e) {}

      if (!res.ok) {
        const msg = (data && data.message) ? data.message : ("HTTP " + res.status);
        const err = new Error(msg);
        err.status = res.status;
        err.data = data;
        throw err;
      }
      return data;
    });
  }

  // =========================================================
  // FLOATING LABELS (LOGIN/CAD)
  // =========================================================
  function initFloatingLabels() {
    const groups = document.querySelectorAll(".input-group");
    if (!groups.length) return;

    function syncGroupState(field) {
      const group = field.closest(".input-group");
      if (!group) return;

      const val = (field && typeof field.value === "string") ? field.value : "";
      if (val.trim().length > 0) group.classList.add("has-value");
      else group.classList.remove("has-value");
    }

    groups.forEach(group => {
      const field = group.querySelector("input, select, textarea");
      if (!field) return;

      syncGroupState(field);
      field.addEventListener("input", () => syncGroupState(field));
      field.addEventListener("change", () => syncGroupState(field));
      field.addEventListener("blur", () => syncGroupState(field));
    });
  }

  // =========================================================
  // EXPORTA “API” GLOBAL SIMPLES
  // =========================================================
  window.BOLAO = window.BOLAO || {};
  window.BOLAO.cfg = APP_CFG;
  window.BOLAO.endpoints = ENDPOINTS;
  window.BOLAO.toast = showToast;
  window.BOLAO.clampScore = clampScore;
  window.BOLAO.markInvalid = markInvalid;
  window.BOLAO.fetchJson = fetchJson;
  window.BOLAO.initFloatingLabels = initFloatingLabels;
})();
