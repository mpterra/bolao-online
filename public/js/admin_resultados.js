document.addEventListener("DOMContentLoaded", () => {
  "use strict";

  if (window.__BOLAO_ADMIN_RESULTADOS_INIT__ === true) return;
  window.__BOLAO_ADMIN_RESULTADOS_INIT__ = true;

  const cfgEl = document.getElementById("admin-resultados-config");
  let CFG = {};
  try {
    CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
  } catch (_) {
    CFG = {};
  }

  const ENDPOINT_SAVE = (CFG && CFG.endpoints && CFG.endpoints.save)
    ? String(CFG.endpoints.save)
    : "/admin_resultados.php?action=save";

  const toast = document.getElementById("toast");
  const listTop = document.getElementById("list-top");
  const modeButtons = Array.from(document.querySelectorAll(".js-view-mode[data-view-mode-target]"));
  const modePanels = Array.from(document.querySelectorAll(".menu-panel[data-view-mode-panel]"));
  const rowTimers = new WeakMap();

  const selectionByMode = {
    group: null,
    day: null
  };

  let currentMode = "group";

  function showToast(msg, isError = false) {
    if (!toast) return;

    toast.textContent = msg;
    toast.classList.add("is-open");

    toast.style.borderColor = isError ? "rgba(255,140,140,.35)" : "rgba(16,208,138,.30)";
    toast.style.background = isError ? "rgba(70,0,0,.45)" : "rgba(0,0,0,.55)";

    clearTimeout(toast.__t);
    toast.__t = setTimeout(() => {
      toast.classList.remove("is-open");
    }, 1900);
  }

  function setRowSaving(rowEl, state, text) {
    const msg = rowEl ? rowEl.querySelector(".js-row-msg") : null;
    if (!msg) return;

    clearTimeout(msg.__t);
    msg.classList.remove("ok", "err");

    if (state === "saving") {
      msg.style.display = "inline";
      msg.textContent = text || "Salvando...";
      return;
    }

    if (state === "ok") {
      msg.style.display = "inline";
      msg.textContent = text || "Salvo.";
      msg.classList.add("ok");
      msg.__t = setTimeout(() => {
        msg.style.display = "none";
        msg.textContent = "";
        msg.classList.remove("ok", "err");
      }, 1400);
      return;
    }

    if (state === "err") {
      msg.style.display = "inline";
      msg.textContent = text || "Erro ao salvar.";
      msg.classList.add("err");
      msg.__t = setTimeout(() => {
        msg.style.display = "none";
        msg.textContent = "";
        msg.classList.remove("ok", "err");
      }, 1800);
      return;
    }

    msg.style.display = "none";
    msg.textContent = "";
  }

  function setBlockSaving(blockEl, state, text) {
    const btn = blockEl ? blockEl.querySelector(".js-save-block") : null;
    const msg = blockEl ? blockEl.querySelector(".js-block-msg") : null;

    if (btn) btn.disabled = (state === "saving");
    if (!msg) return;

    clearTimeout(msg.__t);
    msg.classList.remove("ok", "err");

    if (state === "saving") {
      msg.style.display = "inline";
      msg.textContent = text || "Salvando...";
      return;
    }

    if (state === "ok") {
      msg.style.display = "inline";
      msg.textContent = text || "Salvo.";
      msg.classList.add("ok");
      msg.__t = setTimeout(() => {
        msg.style.display = "none";
        msg.textContent = "";
        msg.classList.remove("ok", "err");
      }, 1800);
      return;
    }

    if (state === "err") {
      msg.style.display = "inline";
      msg.textContent = text || "Erro ao salvar.";
      msg.classList.add("err");
      msg.__t = setTimeout(() => {
        msg.style.display = "none";
        msg.textContent = "";
        msg.classList.remove("ok", "err");
      }, 2200);
      return;
    }

    msg.style.display = "none";
    msg.textContent = "";
  }

  function normalizeScoreValue(raw) {
    const s = String(raw ?? "").replace(/[^\d]/g, "");
    if (s === "") return "";
    const n = parseInt(s, 10);
    if (!Number.isFinite(n) || n < 0) return "";
    return String(n);
  }

  function sanitizeScoreInput(input) {
    if (!input) return;
    const normalized = normalizeScoreValue(input.value);
    if (input.value !== normalized) {
      input.value = normalized;
    }
  }

  function clampInt(v, min, max) {
    if (v === "" || v === null || v === undefined) return null;
    const n = Number(v);
    if (!Number.isFinite(n)) return null;
    const i = Math.trunc(n);
    if (i < min || i > max) return null;
    return i;
  }

  function markInvalid(input, invalid) {
    if (!input) return;
    input.classList.toggle("is-invalid", !!invalid);
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
    listTop.__pulse = setTimeout(() => {
      listTop.classList.remove("is-scroll-pulse");
    }, 450);
  }

  function getModeForType(type) {
    return String(type || "") === "day" ? "day" : "group";
  }

  function getMenuSelectorForMode(mode) {
    if (mode === "day") {
      return '.menu-panel[data-view-mode-panel="day"] .menu-link[data-block-type="day"][data-block-key]';
    }

    return '.menu-panel[data-view-mode-panel="group"] .menu-link[data-block-type][data-block-key]';
  }

  function findFirstSelection(mode) {
    const first = document.querySelector(getMenuSelectorForMode(mode));
    if (!first) return null;

    return {
      type: String(first.getAttribute("data-block-type") || ""),
      key: String(first.getAttribute("data-block-key") || "")
    };
  }

  function hasBlock(type, key) {
    return !!document.querySelector(
      '.block[data-block-type="' + String(type || "") + '"][data-block-key="' + String(key || "") + '"]'
    );
  }

  function rememberSelection(type, key) {
    const mode = getModeForType(type);
    selectionByMode[mode] = {
      type: String(type || ""),
      key: String(key || "")
    };
  }

  function setActiveBlock(type, key) {
    const t = String(type || "");
    const k = String(key || "");

    rememberSelection(t, k);

    document.querySelectorAll(".block[data-block-type][data-block-key]").forEach((el) => {
      const et = String(el.getAttribute("data-block-type") || "");
      const ek = String(el.getAttribute("data-block-key") || "");
      el.classList.toggle("is-active-block", et === t && ek === k);
    });

    document.querySelectorAll(".menu-link[data-block-type][data-block-key]").forEach((el) => {
      const et = String(el.getAttribute("data-block-type") || "");
      const ek = String(el.getAttribute("data-block-key") || "");
      el.classList.toggle("is-active", et === t && ek === k);
    });
  }

  function setActiveMode(mode, { silentToast = false, scroll = false } = {}) {
    const nextMode = mode === "day" ? "day" : "group";
    currentMode = nextMode;

    document.body.setAttribute("data-view-mode", nextMode);

    modeButtons.forEach((btn) => {
      const btnMode = String(btn.getAttribute("data-view-mode-target") || "");
      btn.classList.toggle("is-active", btnMode === nextMode);
      btn.setAttribute("aria-pressed", btnMode === nextMode ? "true" : "false");
    });

    modePanels.forEach((panel) => {
      const panelMode = String(panel.getAttribute("data-view-mode-panel") || "");
      panel.classList.toggle("is-active", panelMode === nextMode);
    });

    let target = selectionByMode[nextMode];
    if (!target || !hasBlock(target.type, target.key)) {
      target = findFirstSelection(nextMode);
      if (target) rememberSelection(target.type, target.key);
    }

    if (target) {
      setActiveBlock(target.type, target.key);
    }

    if (scroll) {
      scrollToListTop();
    }

    if (!silentToast) {
      showToast(nextMode === "day" ? "Visualizando por dia" : "Visualizando por grupo");
    }
  }

  function getLinkedRows(rowEl) {
    if (!rowEl) return [];

    const jogoId = String(rowEl.getAttribute("data-jogo-row") || "");
    if (jogoId === "") return [];

    return Array.from(document.querySelectorAll('.match-card[data-jogo-row="' + jogoId + '"]'));
  }

  function syncLinkedRowValues(sourceRowEl) {
    if (!sourceRowEl) return;

    const sourceCasa = sourceRowEl.querySelector('input[data-field="gols_casa"]');
    const sourceFora = sourceRowEl.querySelector('input[data-field="gols_fora"]');
    if (!sourceCasa || !sourceFora) return;

    const casaInvalid = sourceCasa.classList.contains("is-invalid");
    const foraInvalid = sourceFora.classList.contains("is-invalid");

    getLinkedRows(sourceRowEl).forEach((rowEl) => {
      if (rowEl === sourceRowEl) return;

      const inCasa = rowEl.querySelector('input[data-field="gols_casa"]');
      const inFora = rowEl.querySelector('input[data-field="gols_fora"]');
      if (!inCasa || !inFora) return;

      inCasa.value = sourceCasa.value;
      inFora.value = sourceFora.value;
      markInvalid(inCasa, casaInvalid);
      markInvalid(inFora, foraInvalid);
    });
  }

  document.querySelectorAll(".menu-link[data-block-type][data-block-key]").forEach((a) => {
    a.addEventListener("click", (e) => {
      e.preventDefault();

      const type = a.getAttribute("data-block-type");
      const key = a.getAttribute("data-block-key");
      if (!type || !key) return;

      setActiveMode(getModeForType(type), { silentToast: true });
      setActiveBlock(type, key);
      scrollToListTop();

      if (type === "group") showToast("Grupo selecionado");
      else if (type === "phase") showToast("Fase selecionada");
      else showToast("Dia selecionado");
    });
  });

  modeButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const mode = String(btn.getAttribute("data-view-mode-target") || "group");
      setActiveMode(mode, { silentToast: false, scroll: true });
    });
  });

  if (CFG && CFG.active_type && CFG.active_key !== undefined) {
    rememberSelection(String(CFG.active_type), String(CFG.active_key));
  }

  if (!selectionByMode.group) {
    const firstGroupSelection = findFirstSelection("group");
    if (firstGroupSelection) {
      rememberSelection(firstGroupSelection.type, firstGroupSelection.key);
    }
  }

  if (!selectionByMode.day) {
    const firstDaySelection = findFirstSelection("day");
    if (firstDaySelection) {
      rememberSelection(firstDaySelection.type, firstDaySelection.key);
    }
  }

  setActiveMode(
    CFG && CFG.active_mode ? String(CFG.active_mode) : getModeForType(CFG && CFG.active_type ? CFG.active_type : "group"),
    { silentToast: true }
  );

  async function savePayload(payload) {
    const resp = await fetch(ENDPOINT_SAVE, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify(payload)
    });

    let json = null;
    try {
      json = await resp.json();
    } catch (_) {
      json = null;
    }

    if (!resp.ok || !json || json.ok !== true) {
      const err = (json && json.error) ? json.error : "Falha ao salvar.";
      throw new Error(err);
    }

    return json;
  }

  function applySavedStatus(rowEl, status) {
    const statusEl = rowEl ? rowEl.querySelector(".match-status") : null;
    if (!statusEl || !status) return;

    statusEl.textContent = String(status);
    statusEl.className = "match-status status-" + String(status);
  }

  function applySavedResult(jogoId, golsCasa, golsFora, status) {
    const rows = Array.from(document.querySelectorAll('.match-card[data-jogo-row="' + String(jogoId) + '"]'));

    rows.forEach((rowEl) => {
      const inCasa = rowEl.querySelector('input[data-field="gols_casa"]');
      const inFora = rowEl.querySelector('input[data-field="gols_fora"]');

      if (inCasa) {
        inCasa.value = golsCasa === null || golsCasa === undefined ? "" : String(golsCasa);
        markInvalid(inCasa, false);
      }

      if (inFora) {
        inFora.value = golsFora === null || golsFora === undefined ? "" : String(golsFora);
        markInvalid(inFora, false);
      }

      applySavedStatus(rowEl, status);
    });
  }

  function getRowPayload(rowEl) {
    if (!rowEl) return { ok: false, message: "Linha inválida." };

    const jogoId = Number(rowEl.getAttribute("data-jogo-row") || "0");
    if (!jogoId) {
      return { ok: false, message: "Jogo inválido." };
    }

    const inCasa = rowEl.querySelector('input[data-field="gols_casa"]');
    const inFora = rowEl.querySelector('input[data-field="gols_fora"]');

    if (inCasa) sanitizeScoreInput(inCasa);
    if (inFora) sanitizeScoreInput(inFora);

    const rawCasa = inCasa ? (inCasa.value || "").trim() : "";
    const rawFora = inFora ? (inFora.value || "").trim() : "";

    const golsCasa = clampInt(rawCasa, 0, 30);
    const golsFora = clampInt(rawFora, 0, 30);

    const invalidCasa = (rawCasa !== "" && golsCasa === null);
    const invalidFora = (rawFora !== "" && golsFora === null);

    markInvalid(inCasa, invalidCasa);
    markInvalid(inFora, invalidFora);

    if (invalidCasa || invalidFora) {
      return { ok: false, message: "Placar inválido (0..30)." };
    }

    if ((golsCasa === null) !== (golsFora === null)) {
      return { ok: false, message: "Informe casa e fora (ou deixe ambos vazios)." };
    }

    return {
      ok: true,
      payload: {
        jogo_id: jogoId,
        gols_casa: golsCasa,
        gols_fora: golsFora
      }
    };
  }

  async function saveRow(rowEl, { silentToast = false } = {}) {
    const parsed = getRowPayload(rowEl);
    if (!parsed.ok) {
      setRowSaving(rowEl, "err", parsed.message || "Erro.");
      if (!silentToast) showToast(parsed.message || "Erro ao salvar.", true);
      throw new Error(parsed.message || "Erro ao salvar.");
    }

    try {
      setRowSaving(rowEl, "saving", "Salvando...");
      const json = await savePayload(parsed.payload);
      applySavedResult(parsed.payload.jogo_id, json.gols_casa, json.gols_fora, json.status || "");
      setRowSaving(rowEl, "ok", "Salvo.");
      if (!silentToast) showToast("Resultado salvo");
      return json;
    } catch (e) {
      const msg = e && e.message ? e.message : "Erro ao salvar.";
      setRowSaving(rowEl, "err", msg);
      if (!silentToast) showToast(msg, true);
      throw e;
    }
  }

  function collectBlockRows(blockEl) {
    return Array.from(blockEl.querySelectorAll(".match-card[data-jogo-row]"));
  }

  function collectBlockPayloads(blockEl) {
    const rows = collectBlockRows(blockEl);
    const validRows = [];
    let invalidCount = 0;

    rows.forEach((rowEl) => {
      const parsed = getRowPayload(rowEl);
      if (!parsed.ok) {
        const inCasa = rowEl.querySelector('input[data-field="gols_casa"]');
        const inFora = rowEl.querySelector('input[data-field="gols_fora"]');
        const rawCasa = inCasa ? (inCasa.value || "").trim() : "";
        const rawFora = inFora ? (inFora.value || "").trim() : "";

        if (rawCasa !== "" || rawFora !== "") {
          invalidCount++;
          setRowSaving(rowEl, "err", parsed.message || "Erro.");
        }
        return;
      }

      validRows.push({ rowEl, payload: parsed.payload });
    });

    return { validRows, invalidCount };
  }

  async function saveBlock(blockEl, { silentToast = false } = {}) {
    if (!blockEl) return;

    const { validRows, invalidCount } = collectBlockPayloads(blockEl);

    if (invalidCount > 0) {
      setBlockSaving(blockEl, "err", "Há placares inválidos.");
      if (!silentToast) showToast("Há placares inválidos neste bloco.", true);
      return;
    }

    if (validRows.length === 0) {
      setBlockSaving(blockEl, "ok", "Nada para salvar.");
      if (!silentToast) showToast("Nada para salvar.");
      return;
    }

    try {
      setBlockSaving(blockEl, "saving", "Salvando...");

      for (const item of validRows) {
        const json = await savePayload(item.payload);
        applySavedResult(item.payload.jogo_id, json.gols_casa, json.gols_fora, json.status || "");
        setRowSaving(item.rowEl, "ok", "Salvo.");
      }

      setBlockSaving(blockEl, "ok", "Bloco salvo.");
      if (!silentToast) showToast("Bloco salvo.");
    } catch (e) {
      const msg = e && e.message ? e.message : "Erro ao salvar bloco.";
      setBlockSaving(blockEl, "err", msg);
      if (!silentToast) showToast(msg, true);
    }
  }

  document.querySelectorAll(".match-card[data-jogo-row]").forEach((rowEl) => {
    const inputs = Array.from(rowEl.querySelectorAll(".js-score"));

    inputs.forEach((inp) => {
      inp.addEventListener("input", () => {
        sanitizeScoreInput(inp);
        syncLinkedRowValues(rowEl);

        const current = rowTimers.get(rowEl);
        if (current) clearTimeout(current);

        const timer = setTimeout(() => {
          saveRow(rowEl, { silentToast: true }).catch(() => {});
        }, 450);

        rowTimers.set(rowEl, timer);
      });

      inp.addEventListener("blur", () => {
        sanitizeScoreInput(inp);
        syncLinkedRowValues(rowEl);
      });

      inp.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          const current = rowTimers.get(rowEl);
          if (current) clearTimeout(current);
          syncLinkedRowValues(rowEl);
          saveRow(rowEl, { silentToast: false }).catch(() => {});
        }
      });
    });
  });

  document.querySelectorAll(".js-save-block").forEach((btn) => {
    btn.addEventListener("click", () => {
      const blockEl = btn.closest(".block[data-block-type][data-block-key]");
      if (!blockEl) return;
      saveBlock(blockEl, { silentToast: false });
    });
  });
});