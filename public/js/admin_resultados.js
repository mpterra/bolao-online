document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_ADMIN_RESULTADOS_INIT__ === true) return;
  window.__BOLAO_ADMIN_RESULTADOS_INIT__ = true;

  const cfgEl = document.getElementById("admin-resultados-config");
  let CFG = {};
  try { CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {}; } catch (_) { CFG = {}; }

  const toast = document.getElementById("toast");
  const topAnchor = document.getElementById("resultsTop");

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

  function smoothScrollToTop() {
    if (!topAnchor) return;
    topAnchor.scrollIntoView({ behavior: "smooth", block: "start" });

    // “efeito visual” discreto (pra perceber que levou pra cima)
    document.body.classList.add("did-scroll-top");
    clearTimeout(document.body.__sst);
    document.body.__sst = setTimeout(() => document.body.classList.remove("did-scroll-top"), 550);
  }

  // =========================
  // Navegação: GRUPO ou FASE
  // =========================
  function setActiveSection(type, id) {
    const typeS = String(type || "");
    const idS = String(id || "");

    // mostra apenas o section correto
    document.querySelectorAll("[data-section-type][data-section-id]").forEach((sec) => {
      const t = String(sec.getAttribute("data-section-type") || "");
      const i = String(sec.getAttribute("data-section-id") || "");
      sec.classList.toggle("is-active-group", t === typeS && i === idS);
    });

    // marca item ativo no menu
    document.querySelectorAll("[data-nav-type][data-nav-id]").forEach((a) => {
      const t = String(a.getAttribute("data-nav-type") || "");
      const i = String(a.getAttribute("data-nav-id") || "");
      a.classList.toggle("is-active", t === typeS && i === idS);
    });
  }

  document.querySelectorAll("[data-nav-type][data-nav-id]").forEach((a) => {
    a.addEventListener("click", (e) => {
      e.preventDefault();
      const t = a.getAttribute("data-nav-type");
      const i = a.getAttribute("data-nav-id");
      if (!t || !i) return;

      setActiveSection(t, i);
      smoothScrollToTop();
      showToast(t === "PHASE" ? "Fase selecionada" : "Grupo selecionado");
    });
  });

  // inicial
  if (CFG && CFG.active && CFG.active.type && CFG.active.id !== undefined && CFG.active.id !== null) {
    setActiveSection(String(CFG.active.type), String(CFG.active.id));
  } else {
    // fallback: tenta primeiro section visível
    const first = document.querySelector("[data-section-type][data-section-id]");
    if (first) setActiveSection(first.getAttribute("data-section-type"), first.getAttribute("data-section-id"));
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
      // ✅ HostGator: página na raiz do domínio
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

      // Atualiza status no card
      const statusEl = rowEl.querySelector(".match-status");
      if (statusEl && json.status) {
        statusEl.textContent = String(json.status);
        statusEl.className = "match-status status-" + String(json.status);
      }

      rowMsg(rowEl, "Salvo.", true);
      showToast("Resultado salvo");
    } catch (e) {
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