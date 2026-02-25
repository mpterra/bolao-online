// /public/js/admin_resultados.js
document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_ADMIN_RESULTADOS_INIT__ === true) return;
  window.__BOLAO_ADMIN_RESULTADOS_INIT__ = true;

  const cfgEl = document.getElementById("admin-resultados-config");
  let CFG = {};
  try { CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {}; } catch (_) { CFG = {}; }

  const toast = document.getElementById("toast");
  const listTop = document.getElementById("list-top");

  function showToast(msg) {
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add("is-open");
    clearTimeout(toast.__t);
    toast.__t = setTimeout(() => toast.classList.remove("is-open"), 1700);
  }

  function rowMsg(rowEl, text, ok) {
    const msg = rowEl.querySelector(".js-row-msg");
    if (!msg) return;

    msg.style.display = "inline";
    msg.textContent = text;
    msg.classList.remove("ok", "err");
    msg.classList.add(ok ? "ok" : "err");

    clearTimeout(msg.__t);
    msg.__t = setTimeout(() => {
      msg.style.display = "none";
      msg.textContent = "";
      msg.classList.remove("ok", "err");
    }, 1600);
  }

  function clampInt(v, min, max) {
    if (v === "" || v === null || v === undefined) return null;
    const n = Number(v);
    if (!Number.isFinite(n)) return null;
    const i = Math.trunc(n);
    if (i < min || i > max) return null;
    return i;
  }

  function scrollToListTop() {
    if (!listTop) return;
    try {
      listTop.scrollIntoView({ behavior: "smooth", block: "start" });
    } catch (_) {
      window.scrollTo(0, 0);
    }
    listTop.classList.add("is-scroll-pulse");
    clearTimeout(listTop.__pulse);
    listTop.__pulse = setTimeout(() => listTop.classList.remove("is-scroll-pulse"), 450);
  }

  // =========================
  // Menu: grupos + fases (mata-mata)
  // Filtra sem reload; após clique, sobe suavemente ao topo da listagem
  // =========================
  function setActiveBlock(type, key) {
    const t = String(type || "");
    const k = String(key || "");

    document.querySelectorAll("[data-block-type][data-block-key]").forEach((el) => {
      const et = String(el.getAttribute("data-block-type") || "");
      const ek = String(el.getAttribute("data-block-key") || "");
      el.classList.toggle("is-active-block", et === t && ek === k);
    });

    document.querySelectorAll("[data-block-type][data-block-key].menu-link").forEach((el) => {
      const et = String(el.getAttribute("data-block-type") || "");
      const ek = String(el.getAttribute("data-block-key") || "");
      el.classList.toggle("is-active", et === t && ek === k);
    });
  }

  document.querySelectorAll(".menu-link[data-block-type][data-block-key]").forEach((a) => {
    a.addEventListener("click", (e) => {
      e.preventDefault();
      const type = a.getAttribute("data-block-type");
      const key = a.getAttribute("data-block-key");
      if (!type || !key) return;

      setActiveBlock(type, key);
      scrollToListTop();

      if (type === "group") showToast("Grupo selecionado");
      else showToast("Fase selecionada");
    });
  });

  if (CFG && CFG.active_type && CFG.active_key !== undefined) {
    setActiveBlock(String(CFG.active_type), String(CFG.active_key));
  } else {
    // fallback: primeiro bloco visível
    const first = document.querySelector("[data-block-type][data-block-key]");
    if (first) {
      setActiveBlock(first.getAttribute("data-block-type"), first.getAttribute("data-block-key"));
    }
  }

  // =========================
  // Salvar resultado real
  // =========================
  async function saveRow(btn) {
    const jogoId = Number(btn.getAttribute("data-jogo-id") || "0");
    const rowEl = btn.closest("[data-jogo-row]");
    if (!rowEl || !jogoId) return;

    const inCasa = rowEl.querySelector('input[data-field="gols_casa"]');
    const inFora = rowEl.querySelector('input[data-field="gols_fora"]');

    const rawCasa = inCasa ? (inCasa.value || "").trim() : "";
    const rawFora = inFora ? (inFora.value || "").trim() : "";

    const golsCasa = clampInt(rawCasa, 0, 30);
    const golsFora = clampInt(rawFora, 0, 30);

    if ((rawCasa !== "" && golsCasa === null) || (rawFora !== "" && golsFora === null)) {
      rowMsg(rowEl, "Placar inválido (0..30).", false);
      return;
    }
    if ((golsCasa === null) !== (golsFora === null)) {
      rowMsg(rowEl, "Informe casa e fora (ou deixe ambos vazios).", false);
      return;
    }

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = "Salvando...";

    try {
      const resp = await fetch("/admin_resultados.php?action=save", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          jogo_id: jogoId,
          gols_casa: golsCasa,
          gols_fora: golsFora
        })
      });

      const json = await resp.json().catch(() => null);
      if (!resp.ok || !json || json.ok !== true) {
        const err = (json && json.error) ? json.error : "Falha ao salvar.";
        rowMsg(rowEl, err, false);
        showToast(err);
        return;
      }

      const statusEl = rowEl.querySelector(".match-status");
      if (statusEl && json.status) {
        statusEl.textContent = String(json.status);
        statusEl.className = "match-status status-" + String(json.status);
      }

      rowMsg(rowEl, "Salvo.", true);
      showToast("Resultado salvo");
    } catch (_) {
      rowMsg(rowEl, "Erro de rede ao salvar.", false);
      showToast("Erro de rede");
    } finally {
      btn.disabled = false;
      btn.textContent = oldText;
    }
  }

  document.querySelectorAll(".js-save-real").forEach((btn) => {
    btn.addEventListener("click", () => saveRow(btn));
  });

  // Enter em qualquer input salva o jogo
  document.querySelectorAll(".js-score").forEach((inp) => {
    inp.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        const rowEl = inp.closest("[data-jogo-row]");
        const btn = rowEl ? rowEl.querySelector(".js-save-real") : null;
        if (btn) saveRow(btn);
      }
    });
  });
});