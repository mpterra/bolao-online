document.addEventListener("DOMContentLoaded", () => {
  "use strict";

  if (window.__BOLAO_APP_INIT__ === true) return;
  window.__BOLAO_APP_INIT__ = true;

  const appHasMatches = document.querySelector(".match-card, .group-rank-card");
  if (!appHasMatches) return;

  const cfgEl = document.getElementById("app-config");
  let APP_CFG = {};
  try {
    APP_CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
  } catch (_) {
    APP_CFG = {};
  }

  const ENDPOINT_SAVE_GAMES = (APP_CFG.endpoints && APP_CFG.endpoints.save_games)
    ? APP_CFG.endpoints.save_games
    : "/app.php?action=save";

  const ENDPOINT_SAVE_GROUP_RANK = (APP_CFG.endpoints && APP_CFG.endpoints.save_group_rank)
    ? APP_CFG.endpoints.save_group_rank
    : "/app.php?action=save_group_rank";

  const ENDPOINT_SAVE_TOP4 = (APP_CFG.endpoints && APP_CFG.endpoints.save_top4)
    ? APP_CFG.endpoints.save_top4
    : "/app.php?action=save_top4";

  const ENDPOINT_NOTIFY_CHANGES = (APP_CFG.endpoints && APP_CFG.endpoints.notify_changes)
    ? APP_CFG.endpoints.notify_changes
    : "/app.php?action=notify_changes";

  const ENDPOINT_RECEIPT = (APP_CFG.endpoints && APP_CFG.endpoints.receipt_url)
    ? APP_CFG.endpoints.receipt_url
    : null;

  const CSRF_TOKEN = APP_CFG.csrf_token || "";
  const GROUP_RANK_LOCKED = !!(APP_CFG.group_rank && APP_CFG.group_rank.locked);
  const GROUP_RANK_LOCKED_MESSAGE = (APP_CFG.group_rank && APP_CFG.group_rank.locked_message)
    ? String(APP_CFG.group_rank.locked_message)
    : "O prazo para alterar a classificação dos grupos encerrou.";
  const jsonHeaders = () => ({
    "Content-Type": "application/json; charset=utf-8",
    "X-CSRF-Token": CSRF_TOKEN
  });

  const toast = document.getElementById("toast");
  const listTop = document.getElementById("list-top");
  const modeButtons = Array.from(document.querySelectorAll(".js-view-mode[data-view-mode-target]"));
  const modePanels = Array.from(document.querySelectorAll(".menu-panel[data-view-mode-panel]"));
  const rowTimers = new WeakMap();
  const rankDebounceTimers = new Map();
  const top4DebounceTimers = new WeakMap();
  const savedRankSignatures = new Map();
  let savedTop4Signature = "";
  const USER_ID = Number(APP_CFG && APP_CFG.user && APP_CFG.user.id ? APP_CFG.user.id : 0) || 0;
  const MATCH_DRAFT_STORAGE_KEY = "bolao:app:match-drafts:v1:user:" + String(USER_ID || "guest");
  const GROUP_RANK_DRAFT_STORAGE_KEY = "bolao:app:group-rank-drafts:v1:user:" + String(USER_ID || "guest");
  const pendingMatchSaves = new Map();
  const pendingRankSaves = new Map();
  let matchFlushInProgress = false;
  let rankFlushInProgress = false;
  let matchRetryTimer = null;
  let rankRetryTimer = null;
  let matchRetryDelayMs = 0;
  let rankRetryDelayMs = 0;

  const selectionByMode = {
    group: null,
    day: null
  };

  const FINALIZE_IDLE_MS = 90000;
  let hasPendingFinalize = false;
  let finalizeTimer = null;
  let isFinalizing = false;
  let finalizeBtn = null;
  let exitFinalizeDispatched = false;

  function updateFinalizeBtn() {
    if (!finalizeBtn) return;
    finalizeBtn.textContent = hasPendingFinalize ? "Salvar modificações *" : "Salvar modificações";
    finalizeBtn.style.opacity = hasPendingFinalize ? "1" : "0.86";
    finalizeBtn.style.filter = hasPendingFinalize ? "none" : "saturate(0.72)";
    finalizeBtn.style.boxShadow = hasPendingFinalize
      ? "0 10px 24px rgba(0,0,0,.35)"
      : "0 8px 18px rgba(0,0,0,.18)";
    finalizeBtn.style.background = hasPendingFinalize
      ? "linear-gradient(90deg,#00c27a,#f7c948)"
      : "linear-gradient(90deg,rgba(0,194,122,.62),rgba(247,201,72,.62))";
  }

  function scheduleAutoFinalize() {
    if (finalizeTimer) clearTimeout(finalizeTimer);
    finalizeTimer = setTimeout(() => {
      flushChanges({ manual: false });
    }, FINALIZE_IDLE_MS);
  }

  function markPendingFinalize() {
    hasPendingFinalize = true;
    exitFinalizeDispatched = false;
    updateFinalizeBtn();
    scheduleAutoFinalize();
  }

  function dispatchExitFinalize(source) {
    if (!hasPendingFinalize || exitFinalizeDispatched) return;
    exitFinalizeDispatched = true;

    const payload = JSON.stringify({ force: true, source: source || "exit", csrf_token: CSRF_TOKEN });

    try {
      if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: "application/json" });
        if (navigator.sendBeacon(ENDPOINT_NOTIFY_CHANGES, blob)) {
          return;
        }
      }
    } catch (_) {}

    try {
      fetch(ENDPOINT_NOTIFY_CHANGES, {
        method: "POST",
        headers: jsonHeaders(),
        body: payload,
        keepalive: true,
        credentials: "same-origin"
      }).catch(() => {});
    } catch (_) {}
  }

  async function flushChanges({ manual = false } = {}) {
    captureCurrentMatchDrafts();
    captureCurrentRankDrafts();

    const matchesFlushed = await flushPendingMatchSaves({ silentToast: !manual }).catch(() => false);
    const ranksFlushed = await flushPendingRankSaves({ silentToast: !manual }).catch(() => false);
    if (!matchesFlushed || !ranksFlushed) {
      if (manual) {
        showToast("Ainda existem alteraÃ§Ãµes locais pendentes de salvamento.", true);
      }
      return;
    }

    if (!hasPendingFinalize || isFinalizing) return;
    isFinalizing = true;
    try {
      const res = await fetch(ENDPOINT_NOTIFY_CHANGES, {
        method: "POST",
        headers: jsonHeaders(),
        body: JSON.stringify({ force: true, source: manual ? "manual" : "auto", csrf_token: CSRF_TOKEN }),
        keepalive: true
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data && data.ok === true) {
        hasPendingFinalize = false;
        updateFinalizeBtn();
        if (manual) showToast(data.sent ? "Modificações enviadas para o admin." : "Sem modificações pendentes.");
      } else if (manual) {
        showToast("Falha ao finalizar alterações.", true);
      }
    } catch (_) {
      if (manual) showToast("Falha ao finalizar alterações.", true);
    } finally {
      isFinalizing = false;
    }
  }

  function installFinalizeButton() {
    const btn = document.createElement("button");
    btn.id = "btnFinalizeChangesGlobal";
    btn.type = "button";
    btn.textContent = "Salvar modificações";
    btn.style.position = "fixed";
    const isNarrowScreen = window.matchMedia("(max-width: 560px)").matches;
    btn.style.right = isNarrowScreen ? "12px" : "16px";
    btn.style.bottom = isNarrowScreen ? "calc(10px + env(safe-area-inset-bottom))" : "16px";
    btn.style.zIndex = "99999";
    btn.style.border = "0";
    btn.style.borderRadius = isNarrowScreen ? "10px" : "12px";
    btn.style.padding = isNarrowScreen ? "10px 12px" : "12px 14px";
    btn.style.fontWeight = "900";
    btn.style.cursor = "pointer";
    btn.style.background = "linear-gradient(90deg,#00c27a,#f7c948)";
    btn.style.color = "#062027";
    btn.style.boxShadow = "0 10px 24px rgba(0,0,0,.35)";
    btn.style.fontSize = isNarrowScreen ? "13px" : "15px";
    btn.style.maxWidth = isNarrowScreen ? "calc(100vw - 24px)" : "none";
    btn.addEventListener("click", () => flushChanges({ manual: true }));
    document.body.appendChild(btn);
    finalizeBtn = btn;
    updateFinalizeBtn();
  }

  function showToast(msg, isError = false) {
    if (!toast) return;

    toast.textContent = msg;
    toast.classList.add("is-open");
    toast.style.borderColor = isError ? "rgba(255,140,140,.35)" : "rgba(16,208,138,.30)";
    toast.style.background = isError ? "rgba(70,0,0,.45)" : "rgba(0,0,0,.55)";

    clearTimeout(toast.__t);
    toast.__t = setTimeout(() => {
      toast.classList.remove("is-open");
    }, 2400);
  }

  function storageAvailable() {
    try {
      const key = "__bolao_storage_test__";
      window.localStorage.setItem(key, "1");
      window.localStorage.removeItem(key);
      return true;
    } catch (_) {
      return false;
    }
  }

  const HAS_LOCAL_STORAGE = storageAvailable();

  function loadStoredMap(key) {
    if (!HAS_LOCAL_STORAGE) return {};
    try {
      const raw = window.localStorage.getItem(key);
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      return (parsed && typeof parsed === "object") ? parsed : {};
    } catch (_) {
      return {};
    }
  }

  function saveStoredMap(key, value) {
    if (!HAS_LOCAL_STORAGE) return;
    try {
      const entries = Object.keys(value || {});
      if (!entries.length) {
        window.localStorage.removeItem(key);
        return;
      }
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch (_) {}
  }

  function buildRequestError(res, data, fallbackMsg) {
    const err = new Error((data && data.message) ? data.message : (fallbackMsg || "Falha ao salvar."));
    err.status = res ? Number(res.status || 0) : 0;
    err.data = data || null;
    return err;
  }

  async function postJson(url, payload, { keepalive = false } = {}) {
    const res = await fetch(url, {
      method: "POST",
      headers: jsonHeaders(),
      body: JSON.stringify(payload || {}),
      credentials: "same-origin",
      keepalive: !!keepalive
    });

    let data = null;
    try { data = await res.json(); } catch (_) {}
    return { res, data };
  }

  function isRetriableSaveError(error) {
    const status = Number(error && error.status ? error.status : 0) || 0;
    if (status === 0) return true;
    return status >= 500 || status === 408 || status === 429;
  }

  function isSessionExpiredSaveError(error) {
    return (Number(error && error.status ? error.status : 0) || 0) === 419;
  }

  function fireAndForgetJson(url, payload) {
    const body = JSON.stringify(Object.assign({}, payload || {}, { csrf_token: CSRF_TOKEN }));

    try {
      if (navigator.sendBeacon) {
        const blob = new Blob([body], { type: "application/json" });
        if (navigator.sendBeacon(url, blob)) return true;
      }
    } catch (_) {}

    try {
      fetch(url, {
        method: "POST",
        headers: jsonHeaders(),
        body,
        credentials: "same-origin",
        keepalive: true
      }).catch(() => {});
      return true;
    } catch (_) {}

    return false;
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

  function normalizeScoreValue(raw) {
    const s = String(raw == null ? "" : raw).replace(/[^\d]/g, "");
    if (s === "") return "";
    const n = parseInt(s, 10);
    if (!Number.isFinite(n) || n < 0) return "";
    return String(n);
  }

  function sanitizeScoreInput(input) {
    if (!input) return;
    const normalized = normalizeScoreValue(input.value);
    if (input.value !== normalized) input.value = normalized;
  }

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
    input.classList.toggle("is-invalid", !!invalid);
  }

  function getModeForType(type) {
    return String(type || "") === "day" ? "day" : "group";
  }

  function getMenuSelectorForMode(mode) {
    if (mode === "day") {
      return '.menu-panel[data-view-mode-panel="day"] .menu-link[data-block-type="day"][data-block-key]';
    }

    return '.menu-panel[data-view-mode-panel="group"] .menu-link[data-block-type="group"][data-block-key]:not(.is-disabled)';
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
      '.group-block[data-block-type="' + String(type || "") + '"][data-block-key="' + String(key || "") + '"]'
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

    document.querySelectorAll(".group-block[data-block-type][data-block-key]").forEach((el) => {
      const et = String(el.getAttribute("data-block-type") || "");
      const ek = String(el.getAttribute("data-block-key") || "");
      el.classList.toggle("is-active-group", et === t && ek === k);
    });

    document.querySelectorAll(".menu-link[data-block-type][data-block-key]").forEach((el) => {
      const et = String(el.getAttribute("data-block-type") || "");
      const ek = String(el.getAttribute("data-block-key") || "");
      el.classList.toggle("is-active", et === t && ek === k);
    });

    try {
      const url = new URL(window.location.href);
      if (t === "group") {
        url.searchParams.set("view", "group");
        url.searchParams.set("grupo", k);
        url.searchParams.delete("dia");
      } else if (t === "day") {
        url.searchParams.set("view", "day");
        url.searchParams.set("dia", k);
        url.searchParams.delete("grupo");
      }
      window.history.replaceState({}, document.title, url.toString());
    } catch (_) {}
  }

  function setActiveMode(mode, { silentToast = false, scroll = false } = {}) {
    const nextMode = mode === "day" ? "day" : "group";

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

    if (scroll) scrollToListTop();

    if (!silentToast) {
      showToast(nextMode === "day" ? "Visualizando por dia" : "Visualizando por grupo");
    }
  }

  function getLinkedMatchCards(cardEl) {
    if (!cardEl) return [];
    const jogoId = String(cardEl.getAttribute("data-jogo-id") || "");
    if (!jogoId) return [];
    return Array.from(document.querySelectorAll('.match-card[data-jogo-id="' + jogoId + '"]'));
  }

  function getMatchCardsByGameId(jogoId) {
    const id = Number(jogoId || 0) || 0;
    if (id <= 0) return [];
    return Array.from(document.querySelectorAll('.match-card[data-jogo-id="' + String(id) + '"]'));
  }

  function getPrimaryMatchCard(jogoId) {
    return getMatchCardsByGameId(jogoId)[0] || null;
  }

  function setSavedSignatureForGame(jogoId, signature) {
    getMatchCardsByGameId(jogoId).forEach((cardEl) => {
      cardEl.dataset.savedPayloadSignature = signature || "";
    });
  }

  function setSavingState(cardEl, state, msg) {
    const st = cardEl.querySelector(".save-state");
    if (!st) return;

    const lockedText = st.querySelector(".lock-reason") ? st.querySelector(".lock-reason").textContent : "";
    st.classList.remove("ok", "err");

    if (state === "saving") {
      st.textContent = "Salvando...";
      return;
    }
    if (state === "ok") {
      st.classList.add("ok");
      st.textContent = msg || "Salvo!";
      return;
    }
    if (state === "err") {
      st.classList.add("err");
      st.textContent = msg || "Erro ao salvar.";
      return;
    }

    st.textContent = lockedText || "";
  }

  function setLinkedSavingState(cardEl, state, msg) {
    getLinkedMatchCards(cardEl).forEach((linked) => setSavingState(linked, state, msg));
  }

  function isKnockoutCard(cardEl) {
    return String(cardEl.getAttribute("data-is-knockout") || "") === "1";
  }

  function getScores(cardEl, { requireFilled = false } = {}) {
    const inHome = cardEl.querySelector(".score-home");
    const inAway = cardEl.querySelector(".score-away");

    if (inHome) sanitizeScoreInput(inHome);
    if (inAway) sanitizeScoreInput(inAway);

    const homeBlank = !inHome || inHome.value === "";
    const awayBlank = !inAway || inAway.value === "";
    const gc = clampScore(inHome ? inHome.value : null);
    const gf = clampScore(inAway ? inAway.value : null);

    markInvalid(inHome, !!(inHome && ((requireFilled && homeBlank) || (inHome.value !== "" && gc === null))));
    markInvalid(inAway, !!(inAway && ((requireFilled && awayBlank) || (inAway.value !== "" && gf === null))));

    return { gc, gf, inHome, inAway, homeBlank, awayBlank };
  }

  function isTieNeedPass(cardEl) {
    if (!isKnockoutCard(cardEl)) return false;
    const { gc, gf } = getScores(cardEl);
    return gc !== null && gf !== null && gc === gf;
  }

  function getSelectedPassTeamId(cardEl) {
    const raw = String(cardEl.getAttribute("data-pass-team-id") || "").trim();
    const val = Number(raw || 0) || 0;
    return val > 0 ? val : null;
  }

  function setSelectedPassTeamId(cardEl, teamId) {
    cardEl.setAttribute("data-pass-team-id", String(teamId || 0));

    const homeId = Number(cardEl.getAttribute("data-home-id") || 0) || 0;
    const awayId = Number(cardEl.getAttribute("data-away-id") || 0) || 0;

    const btns = Array.from(cardEl.querySelectorAll(".pass-choice"));
    btns.forEach((btn) => {
      const pass = String(btn.getAttribute("data-pass") || "");
      let btnTeamId = 0;
      if (pass === "home") btnTeamId = homeId;
      if (pass === "away") btnTeamId = awayId;
      btn.classList.toggle("is-active", btnTeamId > 0 && btnTeamId === Number(teamId || 0));
    });

    const btnPass = cardEl.querySelector(".btn-pass");
    if (btnPass) {
      if (Number(teamId || 0) === homeId) {
        btnPass.textContent = "Passa: " + (cardEl.getAttribute("data-home") || "Casa");
      } else if (Number(teamId || 0) === awayId) {
        btnPass.textContent = "Passa: " + (cardEl.getAttribute("data-away") || "Fora");
      } else {
        btnPass.textContent = "Quem passa?";
      }
    }
  }

  function refreshPassUi(cardEl) {
    if (!isKnockoutCard(cardEl)) return;

    const btnPass = cardEl.querySelector(".btn-pass");
    const chooser = cardEl.querySelector(".pass-chooser");
    if (!btnPass || !chooser) return;

    const tie = isTieNeedPass(cardEl);
    const selectedPass = getSelectedPassTeamId(cardEl);

    if (!tie) {
      btnPass.style.display = "none";
      chooser.style.display = "none";
      setSelectedPassTeamId(cardEl, 0);
      cardEl.classList.remove("is-choosing-pass");
      return;
    }

    btnPass.style.display = "";
    setSelectedPassTeamId(cardEl, selectedPass || 0);

    chooser.style.display = cardEl.classList.contains("is-choosing-pass") ? "flex" : "none";
  }

  function syncLinkedMatchState(sourceCardEl) {
    if (!sourceCardEl) return;

    const sourceHome = sourceCardEl.querySelector(".score-home");
    const sourceAway = sourceCardEl.querySelector(".score-away");
    const sourcePass = getSelectedPassTeamId(sourceCardEl) || 0;
    const choosingPass = sourceCardEl.classList.contains("is-choosing-pass");

    getLinkedMatchCards(sourceCardEl).forEach((cardEl) => {
      if (cardEl === sourceCardEl) return;

      const inHome = cardEl.querySelector(".score-home");
      const inAway = cardEl.querySelector(".score-away");

      if (inHome && sourceHome) {
        inHome.value = sourceHome.value;
        markInvalid(inHome, sourceHome.classList.contains("is-invalid"));
      }

      if (inAway && sourceAway) {
        inAway.value = sourceAway.value;
        markInvalid(inAway, sourceAway.classList.contains("is-invalid"));
      }

      setSelectedPassTeamId(cardEl, sourcePass);
      cardEl.classList.toggle("is-choosing-pass", choosingPass);
      refreshPassUi(cardEl);
    });
  }

  async function saveItems(items) {
    const { res, data } = await postJson(ENDPOINT_SAVE_GAMES, { items, csrf_token: CSRF_TOKEN });
    if (!res.ok || !data || data.ok !== true) {
      throw buildRequestError(res, data, "Falha ao salvar.");
    }
    markPendingFinalize();
    return data;
  }

  async function saveGroupRank(grupoId, picks) {
    const { res, data } = await postJson(ENDPOINT_SAVE_GROUP_RANK, { grupo_id: grupoId, picks, csrf_token: CSRF_TOKEN });

    if (!res.ok || !data || data.ok !== true) {
      const msg = (data && data.message) ? data.message : "Falha ao salvar classificação.";
      throw buildRequestError(res, data, "Falha ao salvar classificaÃ§Ã£o.");
    }

    markPendingFinalize();

    return data;
  }

  async function saveTop4(picks) {
    const { res, data } = await postJson(ENDPOINT_SAVE_TOP4, { picks, csrf_token: CSRF_TOKEN });

    if (!res.ok || !data || data.ok !== true) {
      const msg = (data && data.message) ? data.message : "Falha ao salvar Top 4.";
      throw buildRequestError(res, data, "Falha ao salvar Top 4.");
    }

    markPendingFinalize();

    return data;
  }

  function stableSignature(value) {
    return JSON.stringify(value || {});
  }

  function getCardPayload(cardEl, { requireFilled = false } = {}) {
    const jogoId = Number(cardEl.getAttribute("data-jogo-id") || 0) || 0;
    const { gc, gf, homeBlank, awayBlank } = getScores(cardEl, { requireFilled });

    if (jogoId <= 0) return null;
    if (requireFilled && (homeBlank || awayBlank)) {
      return { invalid: true, reason: "Preencha os dois placares." };
    }
    if (requireFilled && (gc === null || gf === null)) {
      return { invalid: true, reason: "Placares devem ficar entre 0 e 99." };
    }
    if (gc === null || gf === null) return null;

    const payload = {
      jogo_id: jogoId,
      gols_casa: gc,
      gols_fora: gf,
      passa_time_id: null
    };

    if (isKnockoutCard(cardEl) && gc === gf) {
      const passTeamId = getSelectedPassTeamId(cardEl);
      if (!passTeamId) {
        return { invalid: true, reason: "Empate: escolha quem passa." };
      }
      payload.passa_time_id = passTeamId;
    }

    return payload;
  }

  function normalizeMatchPayload(raw) {
    if (!raw || typeof raw !== "object") return null;
    const jogoId = Number(raw.jogo_id || 0) || 0;
    const gc = Number(raw.gols_casa);
    const gf = Number(raw.gols_fora);
    const passaRaw = (raw.passa_time_id === null || typeof raw.passa_time_id === "undefined") ? null : Number(raw.passa_time_id || 0) || 0;

    if (jogoId <= 0 || !Number.isFinite(gc) || !Number.isFinite(gf)) return null;
    if (gc < 0 || gc > 99 || gf < 0 || gf > 99) return null;

    return {
      jogo_id: jogoId,
      gols_casa: Math.trunc(gc),
      gols_fora: Math.trunc(gf),
      passa_time_id: (passaRaw && passaRaw > 0) ? Math.trunc(passaRaw) : null
    };
  }

  function normalizeRankDraft(raw) {
    if (!raw || typeof raw !== "object") return null;
    const picks = {
      "1": Number(raw["1"] || 0) || 0,
      "2": Number(raw["2"] || 0) || 0,
      "3": Number(raw["3"] || 0) || 0
    };
    return picks;
  }

  function persistMatchDraft(payload) {
    const normalized = normalizeMatchPayload(payload);
    if (!normalized) return;
    const stored = loadStoredMap(MATCH_DRAFT_STORAGE_KEY);
    stored[String(normalized.jogo_id)] = normalized;
    saveStoredMap(MATCH_DRAFT_STORAGE_KEY, stored);
  }

  function clearMatchDraft(jogoId, signature = "") {
    const key = String(Number(jogoId || 0) || 0);
    if (!key || key === "0") return;
    const stored = loadStoredMap(MATCH_DRAFT_STORAGE_KEY);
    const current = normalizeMatchPayload(stored[key]);
    if (!current) {
      delete stored[key];
      saveStoredMap(MATCH_DRAFT_STORAGE_KEY, stored);
      return;
    }
    if (signature && stableSignature(current) !== signature) return;
    delete stored[key];
    saveStoredMap(MATCH_DRAFT_STORAGE_KEY, stored);
  }

  function persistRankDraft(groupId, picks) {
    const gid = Number(groupId || 0) || 0;
    const normalized = normalizeRankDraft(picks);
    if (gid <= 0 || !normalized) return;
    const stored = loadStoredMap(GROUP_RANK_DRAFT_STORAGE_KEY);
    stored[String(gid)] = normalized;
    saveStoredMap(GROUP_RANK_DRAFT_STORAGE_KEY, stored);
  }

  function clearRankDraft(groupId, signature = "") {
    const key = String(Number(groupId || 0) || 0);
    if (!key || key === "0") return;
    const stored = loadStoredMap(GROUP_RANK_DRAFT_STORAGE_KEY);
    const current = normalizeRankDraft(stored[key]);
    if (!current) {
      delete stored[key];
      saveStoredMap(GROUP_RANK_DRAFT_STORAGE_KEY, stored);
      return;
    }
    if (signature && stableSignature(current) !== signature) return;
    delete stored[key];
    saveStoredMap(GROUP_RANK_DRAFT_STORAGE_KEY, stored);
  }

  function enqueueMatchSave(payload, { immediate = false } = {}) {
    const normalized = normalizeMatchPayload(payload);
    if (!normalized) return "";
    const signature = stableSignature(normalized);
    pendingMatchSaves.set(normalized.jogo_id, {
      jogoId: normalized.jogo_id,
      payload: normalized,
      signature,
      queuedAt: Date.now()
    });
    persistMatchDraft(normalized);

    const primaryCard = getPrimaryMatchCard(normalized.jogo_id);
    if (primaryCard) {
      setLinkedSavingState(primaryCard, "saving");
    }

    if (immediate) {
      flushPendingMatchSaves({ silentToast: true }).catch(() => {});
    }

    return signature;
  }

  function enqueueRankSave(groupId, picks, { immediate = false } = {}) {
    const gid = Number(groupId || 0) || 0;
    const normalized = normalizeRankDraft(picks);
    if (gid <= 0 || !normalized) return "";
    const signature = stableSignature(normalized);
    pendingRankSaves.set(gid, {
      groupId: gid,
      picks: normalized,
      signature,
      queuedAt: Date.now()
    });
    persistRankDraft(gid, normalized);
    setRankStateForGroup(gid, "saving");

    if (immediate) {
      flushPendingRankSaves({ silentToast: true }).catch(() => {});
    }

    return signature;
  }

  function scheduleMatchRetry() {
    if (matchRetryTimer) clearTimeout(matchRetryTimer);
    matchRetryDelayMs = matchRetryDelayMs > 0 ? Math.min(matchRetryDelayMs * 2, 12000) : 1500;
    matchRetryTimer = setTimeout(() => {
      matchRetryTimer = null;
      flushPendingMatchSaves({ silentToast: true }).catch(() => {});
    }, matchRetryDelayMs);
  }

  function scheduleRankRetry() {
    if (rankRetryTimer) clearTimeout(rankRetryTimer);
    rankRetryDelayMs = rankRetryDelayMs > 0 ? Math.min(rankRetryDelayMs * 2, 12000) : 1500;
    rankRetryTimer = setTimeout(() => {
      rankRetryTimer = null;
      flushPendingRankSaves({ silentToast: true }).catch(() => {});
    }, rankRetryDelayMs);
  }

  function applyMatchPayloadToCards(payload) {
    const normalized = normalizeMatchPayload(payload);
    if (!normalized) return false;
    const primaryCard = getPrimaryMatchCard(normalized.jogo_id);
    if (!primaryCard) return false;

    const inHome = primaryCard.querySelector(".score-home");
    const inAway = primaryCard.querySelector(".score-away");
    if (inHome) {
      inHome.value = String(normalized.gols_casa);
      markInvalid(inHome, false);
    }
    if (inAway) {
      inAway.value = String(normalized.gols_fora);
      markInvalid(inAway, false);
    }

    setSelectedPassTeamId(primaryCard, normalized.passa_time_id || 0);
    refreshPassUi(primaryCard);
    syncLinkedMatchState(primaryCard);
    return true;
  }

  function applyRankPicksToCards(groupId, picks) {
    const gid = Number(groupId || 0) || 0;
    const normalized = normalizeRankDraft(picks);
    if (gid <= 0 || !normalized) return false;

    getLinkedRankCards(gid).forEach((cardEl) => {
      Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]")).forEach((sel) => {
        const pos = String(sel.getAttribute("data-rank-pos") || "");
        if (pos === "1" || pos === "2" || pos === "3") {
          sel.value = String(normalized[pos] || 0);
          if (typeof sel.__BOLAO_SYNC_DISPLAY__ === "function") sel.__BOLAO_SYNC_DISPLAY__();
        }
      });
    });

    refreshRankOptionAvailability(gid);
    paintRankValidation(gid, normalized);
    return true;
  }

  function reconcileBlockedMatchEntries(snapshot, blockedRows) {
    if (!Array.isArray(blockedRows) || blockedRows.length === 0) return false;

    const blockedByGameId = new Map();
    blockedRows.forEach((row) => {
      const gameId = Number(row && row.jogo_id ? row.jogo_id : 0) || 0;
      if (gameId <= 0) return;
      blockedByGameId.set(gameId, String(row && row.reason ? row.reason : "Aposta bloqueada."));
    });

    snapshot.forEach((item) => {
      const reason = blockedByGameId.get(item.jogoId);
      if (!reason) return;

      const current = pendingMatchSaves.get(item.jogoId);
      if (current && current.signature === item.signature) {
        pendingMatchSaves.delete(item.jogoId);
        clearMatchDraft(item.jogoId, item.signature);
      }

      const primaryCard = getPrimaryMatchCard(item.jogoId);
      if (primaryCard) {
        setLinkedSavingState(primaryCard, "err", reason);
      }
    });

    return blockedByGameId.size > 0;
  }

  async function flushPendingMatchSaves({ silentToast = true } = {}) {
    if (matchFlushInProgress || pendingMatchSaves.size === 0) return pendingMatchSaves.size === 0;

    matchFlushInProgress = true;
    if (matchRetryTimer) {
      clearTimeout(matchRetryTimer);
      matchRetryTimer = null;
    }

    const snapshot = Array.from(pendingMatchSaves.values()).map((entry) => ({
      jogoId: entry.jogoId,
      payload: entry.payload,
      signature: entry.signature
    }));

    try {
      const data = await saveItems(snapshot.map((entry) => entry.payload));
      matchRetryDelayMs = 0;

      const savedIds = Array.isArray(data && data.saved_game_ids)
        ? data.saved_game_ids.map((id) => Number(id || 0) || 0)
        : snapshot.map((entry) => entry.jogoId);
      const savedSet = new Set(savedIds);
      const blockedRows = Array.isArray(data && data.blocked) ? data.blocked : [];
      const blockedHandled = reconcileBlockedMatchEntries(snapshot, blockedRows);

      snapshot.forEach((item) => {
        if (!savedSet.has(item.jogoId)) return;

        const current = pendingMatchSaves.get(item.jogoId);
        if (current && current.signature !== item.signature) {
          return;
        }

        pendingMatchSaves.delete(item.jogoId);
        clearMatchDraft(item.jogoId, item.signature);
        setSavedSignatureForGame(item.jogoId, item.signature);

        const primaryCard = getPrimaryMatchCard(item.jogoId);
        if (!primaryCard) return;

        if (isKnockoutCard(primaryCard) && item.payload.gols_casa === item.payload.gols_fora && item.payload.passa_time_id) {
          setLinkedSavingState(primaryCard, "ok", "Salvo com quem passa.");
        } else {
          setLinkedSavingState(primaryCard, "ok", "Salvo!");
        }
      });

      if (blockedHandled && !silentToast) {
        showToast((data && data.message) ? data.message : "Alguns palpites ficaram bloqueados.", true);
      }
    } catch (error) {
      const blockedHandled = reconcileBlockedMatchEntries(snapshot, error && error.data && Array.isArray(error.data.blocked) ? error.data.blocked : []);
      if (!blockedHandled) {
        const retriable = isRetriableSaveError(error);
        const message = isSessionExpiredSaveError(error)
          ? (error && error.message ? error.message : "SessÃ£o expirada. Recarregue a pÃ¡gina.")
          : (retriable ? "Falha temporÃ¡ria. Tentando novamente..." : (error && error.message ? error.message : "Erro ao salvar."));

        snapshot.forEach((item) => {
          const current = pendingMatchSaves.get(item.jogoId);
          if (!current || current.signature !== item.signature) return;
          const primaryCard = getPrimaryMatchCard(item.jogoId);
          if (primaryCard) {
            setLinkedSavingState(primaryCard, "err", message);
          }
        });

        if (retriable) {
          scheduleMatchRetry();
        } else if (!silentToast || isSessionExpiredSaveError(error)) {
          showToast(message, true);
        }
      } else if (!silentToast) {
        showToast(error && error.message ? error.message : "Alguns palpites ficaram bloqueados.", true);
      }
    } finally {
      matchFlushInProgress = false;
    }

    if (pendingMatchSaves.size > 0 && !matchRetryTimer) {
      setTimeout(() => {
        flushPendingMatchSaves({ silentToast: true }).catch(() => {});
      }, 60);
    }

    return pendingMatchSaves.size === 0;
  }

  async function flushPendingRankSaves({ silentToast = true } = {}) {
    if (rankFlushInProgress || pendingRankSaves.size === 0) return pendingRankSaves.size === 0;

    rankFlushInProgress = true;
    if (rankRetryTimer) {
      clearTimeout(rankRetryTimer);
      rankRetryTimer = null;
    }

    const snapshot = Array.from(pendingRankSaves.values())
      .sort((a, b) => a.groupId - b.groupId)
      .map((entry) => ({
        groupId: entry.groupId,
        picks: entry.picks,
        signature: entry.signature
      }));

    try {
      for (const item of snapshot) {
        const current = pendingRankSaves.get(item.groupId);
        if (!current || current.signature !== item.signature) continue;

        try {
          setRankStateForGroup(item.groupId, "saving");
          await saveGroupRank(item.groupId, item.picks);

          const afterSave = pendingRankSaves.get(item.groupId);
          if (afterSave && afterSave.signature !== item.signature) continue;

          pendingRankSaves.delete(item.groupId);
          clearRankDraft(item.groupId, item.signature);
          savedRankSignatures.set(item.groupId, item.signature);
          setRankStateForGroup(item.groupId, "ok", "Grupo salvo!");
        } catch (error) {
          const retriable = isRetriableSaveError(error);
          const message = isSessionExpiredSaveError(error)
            ? (error && error.message ? error.message : "SessÃ£o expirada. Recarregue a pÃ¡gina.")
            : (retriable ? "Falha temporÃ¡ria. Tentando novamente..." : (error && error.message ? error.message : "Erro ao salvar."));

          const latest = pendingRankSaves.get(item.groupId);
          if (latest && latest.signature === item.signature) {
            setRankStateForGroup(item.groupId, "err", message);
            if (!retriable && !isSessionExpiredSaveError(error)) {
              pendingRankSaves.delete(item.groupId);
              clearRankDraft(item.groupId, item.signature);
            }
          }

          if (retriable) {
            scheduleRankRetry();
            break;
          }

          if (!silentToast || isSessionExpiredSaveError(error)) {
            showToast(message, true);
          }
        }
      }
    } finally {
      rankFlushInProgress = false;
    }

    if (pendingRankSaves.size > 0 && !rankRetryTimer) {
      setTimeout(() => {
        flushPendingRankSaves({ silentToast: true }).catch(() => {});
      }, 60);
    }

    return pendingRankSaves.size === 0;
  }

  function captureCurrentMatchDrafts() {
    const seen = new Set();
    Array.from(document.querySelectorAll(".match-card")).forEach((cardEl) => {
      const jogoId = Number(cardEl.getAttribute("data-jogo-id") || 0) || 0;
      if (jogoId <= 0 || seen.has(jogoId)) return;
      seen.add(jogoId);

      const payload = getCardPayload(cardEl);
      if (!payload || payload.invalid) return;

      const signature = stableSignature(payload);
      if (cardEl.dataset.savedPayloadSignature === signature && !pendingMatchSaves.has(jogoId)) return;
      enqueueMatchSave(payload);
    });
  }

  function captureCurrentRankDrafts() {
    const seen = new Set();
    Array.from(document.querySelectorAll(".group-rank-card[data-grupo-rank]")).forEach((cardEl) => {
      const groupId = Number(cardEl.getAttribute("data-grupo-rank") || 0) || 0;
      if (groupId <= 0 || seen.has(groupId)) return;
      seen.add(groupId);

      const picks = readRankPicks(cardEl);
      if (!validateDistinctPicks(picks) || !allRankPicksFilled(picks)) return;

      const signature = stableSignature(picks);
      if (savedRankSignatures.get(groupId) === signature && !pendingRankSaves.has(groupId)) return;
      enqueueRankSave(groupId, picks);
    });
  }

  function restoreStoredMatchDrafts() {
    const stored = loadStoredMap(MATCH_DRAFT_STORAGE_KEY);

    Object.keys(stored).forEach((key) => {
      const payload = normalizeMatchPayload(stored[key]);
      if (!payload) {
        clearMatchDraft(key);
        return;
      }

      const primaryCard = getPrimaryMatchCard(payload.jogo_id);
      if (!primaryCard) {
        clearMatchDraft(payload.jogo_id);
        return;
      }

      const signature = stableSignature(payload);
      const currentPayload = getCardPayload(primaryCard);
      if (currentPayload && !currentPayload.invalid) {
        const currentSignature = stableSignature(currentPayload);
        if (currentSignature === signature && primaryCard.dataset.savedPayloadSignature === signature) {
          clearMatchDraft(payload.jogo_id, signature);
          return;
        }
      }

      if (applyMatchPayloadToCards(payload)) {
        enqueueMatchSave(payload);
      }
    });
  }

  function restoreStoredRankDrafts() {
    const stored = loadStoredMap(GROUP_RANK_DRAFT_STORAGE_KEY);

    Object.keys(stored).forEach((key) => {
      const groupId = Number(key || 0) || 0;
      const picks = normalizeRankDraft(stored[key]);
      if (groupId <= 0 || !picks) {
        clearRankDraft(key);
        return;
      }

      const rankCards = getLinkedRankCards(groupId);
      if (!rankCards.length) {
        clearRankDraft(groupId);
        return;
      }

      const signature = stableSignature(picks);
      const current = readRankPicks(rankCards[0]);
      if (allRankPicksFilled(current) && stableSignature(current) === signature && savedRankSignatures.get(groupId) === signature) {
        clearRankDraft(groupId, signature);
        return;
      }

      if (applyRankPicksToCards(groupId, picks)) {
        enqueueRankSave(groupId, picks);
      }
    });
  }

  function dispatchExitSaveFlush(source) {
    captureCurrentMatchDrafts();
    captureCurrentRankDrafts();

    if (pendingMatchSaves.size > 0) {
      fireAndForgetJson(ENDPOINT_SAVE_GAMES, {
        items: Array.from(pendingMatchSaves.values()).map((entry) => entry.payload),
        source: source || "exit"
      });
    }

    Array.from(pendingRankSaves.values()).forEach((entry) => {
      fireAndForgetJson(ENDPOINT_SAVE_GROUP_RANK, {
        grupo_id: entry.groupId,
        picks: entry.picks,
        source: source || "exit"
      });
    });
  }

  function applyGroupRankLockState() {
    if (!GROUP_RANK_LOCKED) return;

    document.querySelectorAll(".group-rank-card[data-grupo-rank]").forEach((cardEl) => {
      cardEl.classList.add("is-locked");
      cardEl.setAttribute("data-rank-locked", "1");

      cardEl.querySelectorAll(".rank-select[data-rank-pos]").forEach((sel) => {
        sel.disabled = true;
      });

      const btn = cardEl.querySelector(".btn-group-save");
      if (btn) btn.disabled = true;

      const state = cardEl.querySelector(".rank-state");
      if (state) {
        state.classList.add("err");
        if (!state.textContent.trim()) state.textContent = GROUP_RANK_LOCKED_MESSAGE;
      }
    });
  }

  applyGroupRankLockState();

  (function initCustomRankSelects() {
    const selects = Array.from(document.querySelectorAll(".group-rank-card select.rank-select"));
    if (!selects.length) return;

    if (!document.getElementById("bolao-custom-select-css")) {
      const style = document.createElement("style");
      style.id = "bolao-custom-select-css";
      style.textContent = `
        .bolao-select-wrap{ position:relative; width:100%; }
        .bolao-select-native{ position:absolute !important; inset:0 !important; width:100% !important; height:100% !important; opacity:0 !important; pointer-events:none !important; }
        .bolao-select-display{ width:100%; padding:12px 44px 12px 14px; border-radius:12px; border:1px solid rgba(255,255,255,.18); background:rgba(255,255,255,.10); color:var(--text); font-size:14px; outline:none; transition:220ms ease; cursor:pointer; user-select:none; display:flex; align-items:center; -webkit-tap-highlight-color:transparent; min-height:unset; line-height:normal; }
        .bolao-select-display:focus{ border-color:rgba(16,208,138,.55); box-shadow:0 0 0 4px rgba(16,208,138,.14), 0 10px 22px rgba(0,0,0,.25); background:rgba(255,255,255,.12); }
        .bolao-select-display.is-invalid{ border-color:rgba(255,140,140,.45); box-shadow:0 0 0 4px rgba(255,140,140,.10); }
        .bolao-select-display.is-disabled{ opacity:.58; cursor:not-allowed; filter:saturate(.7); }
        .bolao-select-caret{ position:absolute; right:14px; top:50%; transform:translateY(-50%); width:18px; height:18px; pointer-events:none; opacity:.9; }
        .bolao-select-portal{ position:fixed; z-index:999999; border-radius:14px; border:1px solid rgba(255,255,255,.16); background:rgba(0,0,0,.68); backdrop-filter:blur(12px); box-shadow:0 22px 60px rgba(0,0,0,.55); overflow:hidden; display:none; }
        .bolao-select-portal.is-open{ display:block; }
        .bolao-select-search{ padding:10px; border-bottom:1px solid rgba(255,255,255,.10); background:rgba(255,255,255,.06); }
        .bolao-select-search input{ width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background:rgba(0,0,0,.20); color:rgba(255,255,255,.92); font-weight:900; font-size:13px; outline:none; transition:180ms ease; }
        .bolao-select-search input:focus{ border-color:rgba(16,208,138,.55); box-shadow:0 0 0 4px rgba(16,208,138,.12); }
        .bolao-select-search input::placeholder{ color:rgba(255,255,255,.55); font-weight:800; }
        .bolao-select-list{ max-height:260px; overflow:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; }
        .bolao-select-opt{ padding:10px 12px; font-weight:900; font-size:13px; color:rgba(255,255,255,.92); display:flex; align-items:center; justify-content:space-between; cursor:pointer; border-bottom:1px solid rgba(255,255,255,.08); -webkit-tap-highlight-color:transparent; }
        .bolao-select-opt:last-child{ border-bottom:0; }
        .bolao-select-opt:hover{ background:rgba(255,255,255,.08); }
        .bolao-select-opt.is-active{ color:#062027; background:linear-gradient(90deg, var(--green), var(--gold)); }
        .bolao-select-opt.is-hidden{ display:none; }
      `;
      document.head.appendChild(style);
    }

    function syncInvalidState(sel, display) {
      if (!sel || !display) return;
      display.classList.toggle("is-invalid", sel.classList.contains("is-invalid"));
    }

    function syncDisabledState(sel, display) {
      if (!sel || !display) return;
      const disabled = !!sel.disabled;
      display.classList.toggle("is-disabled", disabled);
      display.setAttribute("aria-disabled", disabled ? "true" : "false");
      display.tabIndex = disabled ? -1 : 0;
    }

    function makeCustomSelect(sel, searchPlaceholder) {
      if (!sel || sel.__BOLAO_CUSTOM_SELECT__) return;
      sel.__BOLAO_CUSTOM_SELECT__ = true;

      const parent = sel.parentNode;
      if (!parent) return;

      const wrap = document.createElement("div");
      wrap.className = "bolao-select-wrap";

      const display = document.createElement("div");
      display.className = "bolao-select-display";
      display.tabIndex = 0;
      display.setAttribute("role", "combobox");
      display.setAttribute("aria-haspopup", "listbox");
      display.setAttribute("aria-expanded", "false");

      const caret = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      caret.setAttribute("class", "bolao-select-caret");
      caret.setAttribute("viewBox", "0 0 24 24");
      caret.innerHTML = '<path d="M7 10l5 5 5-5" fill="none" stroke="rgba(255,255,255,.9)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>';

      const portal = document.createElement("div");
      portal.className = "bolao-select-portal";
      portal.setAttribute("role", "listbox");

      const searchWrap = document.createElement("div");
      searchWrap.className = "bolao-select-search";

      const searchInput = document.createElement("input");
      searchInput.type = "text";
      searchInput.autocomplete = "off";
      searchInput.placeholder = searchPlaceholder || "Filtrar...";
      searchWrap.appendChild(searchInput);

      const list = document.createElement("div");
      list.className = "bolao-select-list";

      portal.appendChild(searchWrap);
      portal.appendChild(list);
      document.body.appendChild(portal);

      sel.classList.add("bolao-select-native");

      parent.insertBefore(wrap, sel);
      wrap.appendChild(display);
      wrap.appendChild(caret);
      wrap.appendChild(sel);

      const options = Array.from(sel.options).filter((o) => (o.value || "").trim() !== "");
      const isTouchLike = () => {
        try {
          return window.matchMedia("(pointer: coarse), (hover: none), (max-width: 760px)").matches;
        } catch (_) {
          return false;
        }
      };

      function currentValue() {
        return (sel.value || "").trim();
      }

      function selectedText() {
        const v = currentValue();
        const opt = options.find((o) => String(o.value) === String(v));
        return opt ? (opt.textContent || "").trim() : "";
      }

      function setDisplayText() {
        const txt = selectedText();
        display.textContent = txt ? txt : "\u00A0";
        wrap.appendChild(caret);
        syncInvalidState(sel, display);
        syncDisabledState(sel, display);
      }

      sel.__BOLAO_SYNC_DISPLAY__ = setDisplayText;

      function buildList(activeVal) {
        list.innerHTML = "";
        options.forEach((o) => {
          if (o.disabled && String(o.value) !== String(activeVal)) return;

          const item = document.createElement("div");
          item.className = "bolao-select-opt";
          item.dataset.value = String(o.value);
          item.dataset.label = (o.textContent || "").trim();
          item.textContent = (o.textContent || "").trim();

          if (String(o.value) === String(activeVal)) item.classList.add("is-active");

          item.addEventListener("click", (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            sel.value = o.value;
            try { sel.dispatchEvent(new Event("change", { bubbles: true })); } catch (_) {}
            closeMenu(true);
            setDisplayText();
          });

          list.appendChild(item);
        });
      }

      let open = false;

      function positionPortal() {
        const rect = display.getBoundingClientRect();
        const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
        const vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
        const width = rect.width;
        const left = Math.max(8, Math.min(rect.left, vw - width - 8));
        const desiredMaxH = Math.min(360, Math.floor(vh * 0.46));
        const spaceBelow = vh - rect.bottom - 10;
        const spaceAbove = rect.top - 10;

        portal.style.left = left + "px";
        portal.style.width = width + "px";

        let maxH = Math.max(180, Math.min(desiredMaxH, spaceBelow));

        if (spaceBelow < 200 && spaceAbove > spaceBelow) {
          maxH = Math.max(180, Math.min(desiredMaxH, spaceAbove));
          portal.style.transformOrigin = "bottom";
          const headerH = searchWrap.getBoundingClientRect().height || 52;
          list.style.maxHeight = Math.max(120, maxH - headerH) + "px";
          portal.classList.add("is-open");
          const portalH = portal.getBoundingClientRect().height || (headerH + maxH);
          portal.style.top = Math.max(8, rect.top - 8 - portalH) + "px";
          portal.style.transform = "translateZ(0)";
          return;
        }

        portal.style.transformOrigin = "top";
        portal.style.top = (rect.bottom + 8) + "px";
        list.style.maxHeight = Math.max(120, maxH - 52) + "px";
        portal.style.transform = "translateZ(0)";
      }

      function openMenu() {
        if (sel.disabled) return;
        if (open) return;
        open = true;

        portal.style.display = "";
        display.setAttribute("aria-expanded", "true");
        buildList(currentValue());
        searchInput.value = "";
        Array.from(list.children).forEach((el) => el.classList.remove("is-hidden"));
        portal.classList.add("is-open");
        positionPortal();

        if (!isTouchLike()) {
          setTimeout(() => {
            try { searchInput.focus(); } catch (_) {}
          }, 0);
        }
      }

      function closeMenu(keepFocus = false) {
        if (!open) return;
        open = false;
        display.setAttribute("aria-expanded", "false");
        portal.classList.remove("is-open");
        portal.style.left = "";
        portal.style.top = "";
        portal.style.width = "";
        portal.style.transformOrigin = "";
        portal.style.transform = "";
        portal.style.display = "";

        if (keepFocus) {
          try { display.focus(); } catch (_) {}
        }
      }

      function toggleMenu() {
        if (sel.disabled) return;
        if (open) closeMenu(true);
        else openMenu();
      }

      function applyFilter() {
        const q = (searchInput.value || "").trim().toUpperCase();
        const items = Array.from(list.querySelectorAll(".bolao-select-opt"));
        if (!q) {
          items.forEach((el) => el.classList.remove("is-hidden"));
          return;
        }
        items.forEach((el) => {
          const label = (el.dataset.label || "").toUpperCase();
          el.classList.toggle("is-hidden", !label.includes(q));
        });
      }

      searchInput.addEventListener("input", applyFilter);
      searchInput.addEventListener("keydown", (ev) => {
        if (ev.key === "Escape") {
          ev.preventDefault();
          closeMenu(true);
          return;
        }
        if (ev.key === "Enter") {
          ev.preventDefault();
          const first = list.querySelector(".bolao-select-opt:not(.is-hidden)");
          if (first) first.click();
        }
      });

      display.addEventListener("click", (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        toggleMenu();
      });

      display.addEventListener("keydown", (ev) => {
        if (ev.key === "Enter" || ev.key === " ") {
          ev.preventDefault();
          toggleMenu();
          return;
        }
        if (ev.key === "ArrowDown") {
          ev.preventDefault();
          if (!open) openMenu();
          else searchInput.focus();
          return;
        }
        if (ev.key === "Escape" && open) {
          ev.preventDefault();
          closeMenu(true);
        }
      });

      document.addEventListener("click", (ev) => {
        if (!open) return;
        if (wrap.contains(ev.target) || portal.contains(ev.target)) return;
        closeMenu(false);
      });

      document.addEventListener("pointerdown", (ev) => {
        if (!open) return;
        if (wrap.contains(ev.target) || portal.contains(ev.target)) return;
        closeMenu(false);
      }, { capture: true });

      window.addEventListener("resize", () => { if (open) positionPortal(); });
      window.addEventListener("scroll", () => { if (open) positionPortal(); }, true);
      window.addEventListener("orientationchange", () => { if (open) closeMenu(false); });

      sel.addEventListener("change", () => {
        setDisplayText();
        if (open) closeMenu(true);
      });

      const observer = new MutationObserver(() => {
        syncInvalidState(sel, display);
        syncDisabledState(sel, display);
      });
      observer.observe(sel, { attributes: true, attributeFilter: ["class", "disabled"] });

      setDisplayText();
    }

    selects.forEach((sel) => makeCustomSelect(sel, "Filtrar time..."));
  })();

  function getLinkedRankCards(groupId) {
    return Array.from(document.querySelectorAll('.group-rank-card[data-grupo-rank="' + String(groupId || 0) + '"]'));
  }

  function setRankStateForGroup(groupId, state, msg) {
    getLinkedRankCards(groupId).forEach((cardEl) => {
      const st = cardEl.querySelector(".rank-state");
      const btn = cardEl.querySelector(".btn-group-save");
      const locked = cardEl.getAttribute("data-rank-locked") === "1";
      if (btn) btn.disabled = locked || (state === "saving");
      if (!st) return;

      st.classList.remove("ok", "err");
      if (state === "saving") {
        st.textContent = "Salvando...";
        return;
      }
      if (state === "ok") {
        st.classList.add("ok");
        st.textContent = msg || "Grupo salvo!";
        return;
      }
      if (state === "err") {
        st.classList.add("err");
        st.textContent = msg || "Erro ao salvar.";
        return;
      }
      st.textContent = "";
    });
  }

  function markRankInvalid(selectEl, invalid) {
    if (!selectEl) return;
    selectEl.classList.toggle("is-invalid", !!invalid);
    const syncDisplay = typeof selectEl.__BOLAO_SYNC_DISPLAY__ === "function" ? selectEl.__BOLAO_SYNC_DISPLAY__ : null;
    if (syncDisplay) syncDisplay();
  }

  function syncRankCards(groupId, sourceCardEl) {
    const sourceSelects = Array.from(sourceCardEl.querySelectorAll(".rank-select[data-rank-pos]"));

    getLinkedRankCards(groupId).forEach((cardEl) => {
      if (cardEl === sourceCardEl) return;

      Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]"))
        .forEach((sel) => {
          const pos = String(sel.getAttribute("data-rank-pos") || "");
          const source = sourceSelects.find((item) => String(item.getAttribute("data-rank-pos") || "") === pos);
          if (!source) return;

          sel.value = source.value;
          sel.classList.toggle("is-invalid", source.classList.contains("is-invalid"));
          if (typeof sel.__BOLAO_SYNC_DISPLAY__ === "function") sel.__BOLAO_SYNC_DISPLAY__();
        });
    });
  }

  function readRankPicks(cardEl) {
    const picks = { "1": 0, "2": 0, "3": 0 };
    Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]"))
      .forEach((sel) => {
        const pos = String(sel.getAttribute("data-rank-pos") || "");
        const val = Number(sel.value || 0) || 0;
        if (pos === "1" || pos === "2" || pos === "3") picks[pos] = val;
      });
    return picks;
  }

  function refreshRankOptionAvailability(groupId) {
    getLinkedRankCards(groupId).forEach((cardEl) => {
      const picks = readRankPicks(cardEl);

      Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]"))
        .forEach((sel) => {
          const pos = String(sel.getAttribute("data-rank-pos") || "");

          Array.from(sel.options).forEach((opt) => {
            const val = Number(opt.value || 0) || 0;
            if (val <= 0) {
              opt.disabled = false;
              return;
            }

            opt.disabled = Object.keys(picks).some((other) => (
              other !== pos && Number(picks[other] || 0) === val
            ));
          });

          if (typeof sel.__BOLAO_SYNC_DISPLAY__ === "function") sel.__BOLAO_SYNC_DISPLAY__();
        });
    });
  }

  function validateDistinctPicks(picks) {
    const chosen = Object.keys(picks)
      .map((key) => Number(picks[key] || 0) || 0)
      .filter((value) => value > 0);
    return chosen.length === new Set(chosen).size;
  }

  function allRankPicksFilled(picks) {
    return (Number(picks["1"] || 0) > 0)
      && (Number(picks["2"] || 0) > 0)
      && (Number(picks["3"] || 0) > 0);
  }

  function paintRankValidation(groupId, picks) {
    const values = {
      "1": Number(picks["1"] || 0) || 0,
      "2": Number(picks["2"] || 0) || 0,
      "3": Number(picks["3"] || 0) || 0
    };

    getLinkedRankCards(groupId).forEach((cardEl) => {
      Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]"))
        .forEach((sel) => {
          const pos = String(sel.getAttribute("data-rank-pos") || "");
          const val = values[pos] || 0;
          const repeated = val > 0 && Object.keys(values).some((other) => other !== pos && values[other] > 0 && values[other] === val);
          markRankInvalid(sel, repeated);
        });
    });

    return validateDistinctPicks(picks);
  }

  async function saveRankByGroup(groupId, { silentToast = false } = {}) {
    if (GROUP_RANK_LOCKED) {
      setRankStateForGroup(groupId, "err", GROUP_RANK_LOCKED_MESSAGE);
      if (!silentToast) showToast(GROUP_RANK_LOCKED_MESSAGE, true);
      return;
    }

    const rankCards = getLinkedRankCards(groupId);
    const sourceCard = rankCards[0];
    if (!sourceCard) return;

    const picks = readRankPicks(sourceCard);
    const valid = paintRankValidation(groupId, picks);

    if (!valid) {
      setRankStateForGroup(groupId, "err", "Não repita times.");
      if (!silentToast) showToast("Não pode repetir o mesmo time em 1º/2º/3º.", true);
      return;
    }

    if (!allRankPicksFilled(picks)) {
      setRankStateForGroup(groupId, "err", "Complete 1º, 2º e 3º.");
      if (!silentToast) showToast("Complete 1º, 2º e 3º para salvar o grupo.", true);
      return;
    }

    const signature = stableSignature(picks);
    const pending = pendingRankSaves.get(groupId);
    if (savedRankSignatures.get(groupId) === signature && !pending) {
      setRankStateForGroup(groupId, "ok", "Grupo salvo!");
      return;
    }

    try {
      enqueueRankSave(groupId, picks);
      await flushPendingRankSaves({ silentToast });
      if (!pendingRankSaves.has(groupId)) {
      setRankStateForGroup(groupId, "ok", "Grupo salvo!");
      if (!silentToast) showToast("Classificação do grupo salva.");
      }
    } catch (e) {
      setRankStateForGroup(groupId, "err", e.message || "Erro ao salvar.");
      if (!silentToast) showToast(e.message || "Erro ao salvar grupo.", true);
    }
  }

  document.querySelectorAll(".menu-link[data-block-type][data-block-key]").forEach((link) => {
    if (link.classList.contains("is-disabled")) return;

    link.addEventListener("click", (ev) => {
      ev.preventDefault();
      const type = String(link.getAttribute("data-block-type") || "");
      const key = String(link.getAttribute("data-block-key") || "");
      if (!type || !key) return;

      setActiveMode(getModeForType(type), { silentToast: true });
      setActiveBlock(type, key);
      scrollToListTop();

      if (type === "group") showToast("Grupo selecionado");
      else showToast("Dia selecionado");
    });
  });

  modeButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const mode = String(btn.getAttribute("data-view-mode-target") || "group");
      setActiveMode(mode, { scroll: true });
    });
  });

  document.querySelectorAll(".btn-next-group[data-next-grupo]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const nextGrupo = String(btn.getAttribute("data-next-grupo") || "").trim();
      if (!nextGrupo) return;
      setActiveMode("group", { silentToast: true });
      setActiveBlock("group", nextGrupo);
      scrollToListTop();
    });
  });

  document.querySelectorAll(".btn-prev-group[data-prev-grupo]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const prevGrupo = String(btn.getAttribute("data-prev-grupo") || "").trim();
      if (!prevGrupo) return;
      setActiveMode("group", { silentToast: true });
      setActiveBlock("group", prevGrupo);
      scrollToListTop();
    });
  });

  if (APP_CFG && APP_CFG.active_type && APP_CFG.active_key !== undefined) {
    rememberSelection(String(APP_CFG.active_type), String(APP_CFG.active_key));
  }

  if (!selectionByMode.group) {
    const firstGroup = findFirstSelection("group");
    if (firstGroup) rememberSelection(firstGroup.type, firstGroup.key);
  }

  if (!selectionByMode.day) {
    const firstDay = findFirstSelection("day");
    if (firstDay) rememberSelection(firstDay.type, firstDay.key);
  }

  setActiveMode(
    APP_CFG && APP_CFG.active_mode ? String(APP_CFG.active_mode) : getModeForType(APP_CFG && APP_CFG.active_type ? APP_CFG.active_type : "group"),
    { silentToast: true }
  );

  Array.from(document.querySelectorAll(".match-card")).forEach((cardEl) => {
    const inHome = cardEl.querySelector(".score-home");
    const inAway = cardEl.querySelector(".score-away");
    const btnPass = cardEl.querySelector(".btn-pass");
    const chooser = cardEl.querySelector(".pass-chooser");
    const passChoices = Array.from(cardEl.querySelectorAll(".pass-choice"));

    const scheduleAutoSave = () => {
      if (inHome) sanitizeScoreInput(inHome);
      if (inAway) sanitizeScoreInput(inAway);
      refreshPassUi(cardEl);
      syncLinkedMatchState(cardEl);

      const draftPayload = getCardPayload(cardEl);
      if (draftPayload && !draftPayload.invalid) {
        enqueueMatchSave(draftPayload);
      }

      const current = rowTimers.get(cardEl);
      if (current) clearTimeout(current);

      const timer = setTimeout(async () => {
        const payload = getCardPayload(cardEl, { requireFilled: true });
        if (!payload) return;

        if (payload.invalid) {
          syncLinkedMatchState(cardEl);
          setLinkedSavingState(cardEl, "err", payload.reason || "Erro ao salvar.");
          return;
        }

        const signature = stableSignature(payload);
        const pending = pendingMatchSaves.get(payload.jogo_id);
        if (cardEl.dataset.savedPayloadSignature === signature && !pending) {
          setLinkedSavingState(cardEl, "ok", "Salvo!");
          return;
        }

        try {
          enqueueMatchSave(payload);
          await flushPendingMatchSaves({ silentToast: true });
        } catch (e) {
          setLinkedSavingState(cardEl, "err", e.message || "Erro ao salvar.");
        }
      }, 450);

      rowTimers.set(cardEl, timer);
    };

    if (inHome) {
      inHome.addEventListener("input", scheduleAutoSave);
      inHome.addEventListener("blur", () => {
        sanitizeScoreInput(inHome);
        refreshPassUi(cardEl);
        const payload = getCardPayload(cardEl, { requireFilled: true });
        if (payload && payload.invalid) {
          setLinkedSavingState(cardEl, "err", payload.reason);
          showToast(payload.reason, true);
        }
        syncLinkedMatchState(cardEl);
      });
      inHome.addEventListener("keydown", (ev) => {
        if (ev.key === "Enter") {
          ev.preventDefault();
          scheduleAutoSave();
        }
      });
    }

    if (inAway) {
      inAway.addEventListener("input", scheduleAutoSave);
      inAway.addEventListener("blur", () => {
        sanitizeScoreInput(inAway);
        refreshPassUi(cardEl);
        const payload = getCardPayload(cardEl, { requireFilled: true });
        if (payload && payload.invalid) {
          setLinkedSavingState(cardEl, "err", payload.reason);
          showToast(payload.reason, true);
        }
        syncLinkedMatchState(cardEl);
      });
      inAway.addEventListener("keydown", (ev) => {
        if (ev.key === "Enter") {
          ev.preventDefault();
          scheduleAutoSave();
        }
      });
    }

    if (btnPass && chooser) {
      btnPass.addEventListener("click", () => {
        if (btnPass.disabled) return;
        cardEl.classList.toggle("is-choosing-pass");
        refreshPassUi(cardEl);
        syncLinkedMatchState(cardEl);
      });
    }

    passChoices.forEach((btn) => {
      btn.addEventListener("click", async () => {
        const pass = String(btn.getAttribute("data-pass") || "");
        const homeId = Number(cardEl.getAttribute("data-home-id") || 0) || 0;
        const awayId = Number(cardEl.getAttribute("data-away-id") || 0) || 0;

        let teamId = 0;
        if (pass === "home") teamId = homeId;
        if (pass === "away") teamId = awayId;
        if (teamId <= 0) return;

        setSelectedPassTeamId(cardEl, teamId);
        cardEl.classList.remove("is-choosing-pass");
        refreshPassUi(cardEl);
        syncLinkedMatchState(cardEl);

        const payload = getCardPayload(cardEl);
        if (!payload || payload.invalid) {
          setLinkedSavingState(cardEl, "err", "Preencha o placar empatado antes de escolher quem passa.");
          return;
        }

        const signature = stableSignature(payload);
        const pending = pendingMatchSaves.get(payload.jogo_id);
        if (cardEl.dataset.savedPayloadSignature === signature && !pending) {
          setLinkedSavingState(cardEl, "ok", "Salvo com quem passa.");
          return;
        }

        try {
          enqueueMatchSave(payload);
          await flushPendingMatchSaves({ silentToast: true });
        } catch (e) {
          setLinkedSavingState(cardEl, "err", e.message || "Erro ao salvar.");
        }
      });
    });

    refreshPassUi(cardEl);
    const initialPayload = getCardPayload(cardEl);
    if (initialPayload && !initialPayload.invalid) {
      cardEl.dataset.savedPayloadSignature = stableSignature(initialPayload);
    }
  });

  Array.from(document.querySelectorAll(".group-rank-card[data-grupo-rank]")).forEach((cardEl) => {
    const grupoId = Number(cardEl.getAttribute("data-grupo-rank") || 0) || 0;
    if (grupoId <= 0) return;

    const selects = Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]"));
    const btnSave = cardEl.querySelector(".btn-group-save");

    selects.forEach((sel) => {
      sel.addEventListener("change", () => {
        syncRankCards(grupoId, cardEl);
        refreshRankOptionAvailability(grupoId);

        const picks = readRankPicks(cardEl);
        const valid = paintRankValidation(grupoId, picks);

        if (!valid) {
          setRankStateForGroup(grupoId, "err", "Não repita times.");
          showToast("Não pode repetir o mesmo time em 1º/2º/3º.", true);
          return;
        }

        if (!allRankPicksFilled(picks)) {
          setRankStateForGroup(grupoId, "", "");
          return;
        }

        enqueueRankSave(grupoId, picks);

        if (rankDebounceTimers.has(grupoId)) clearTimeout(rankDebounceTimers.get(grupoId));
        rankDebounceTimers.set(grupoId, setTimeout(() => {
          saveRankByGroup(grupoId, { silentToast: true });
        }, 350));
      });
    });

    if (btnSave) {
      btnSave.addEventListener("click", () => saveRankByGroup(grupoId, { silentToast: false }));
    }

    const initialPicks = readRankPicks(cardEl);
    if (allRankPicksFilled(initialPicks) && !savedRankSignatures.has(grupoId)) {
      savedRankSignatures.set(grupoId, stableSignature(initialPicks));
    }

    refreshRankOptionAvailability(grupoId);
  });

  const top4Card = document.querySelector('.group-rank-card[data-top4-card="1"]');
  const top4State = document.getElementById("top4State");

  if (top4Card) {
    const selects = Array.from(top4Card.querySelectorAll(".rank-select[data-top4-pos]"));

    function setTop4State(state, msg) {
      if (!top4State) return;
      top4State.classList.remove("ok", "err");

      if (state === "saving") {
        top4State.textContent = "Salvando...";
        return;
      }
      if (state === "ok") {
        top4State.classList.add("ok");
        top4State.textContent = msg || "Top 4 salvo!";
        return;
      }
      if (state === "err") {
        top4State.classList.add("err");
        top4State.textContent = msg || "Erro ao salvar.";
        return;
      }
      top4State.textContent = "";
    }

    function readTop4Picks() {
      const picks = { "1": 0, "2": 0, "3": 0, "4": 0 };
      selects.forEach((sel) => {
        const pos = String(sel.getAttribute("data-top4-pos") || "");
        const val = Number(sel.value || 0) || 0;
        if (pos === "1" || pos === "2" || pos === "3" || pos === "4") picks[pos] = val;
      });
      return picks;
    }

    function allTop4Filled(picks) {
      return ["1", "2", "3", "4"].every((key) => Number(picks[key] || 0) > 0);
    }

    function paintTop4Validation(picks) {
      const values = {
        "1": Number(picks["1"] || 0) || 0,
        "2": Number(picks["2"] || 0) || 0,
        "3": Number(picks["3"] || 0) || 0,
        "4": Number(picks["4"] || 0) || 0
      };

      selects.forEach((sel) => markRankInvalid(sel, false));

      selects.forEach((sel) => {
        const pos = String(sel.getAttribute("data-top4-pos") || "");
        const val = values[pos] || 0;
        if (val <= 0) return;
        const repeated = Object.keys(values).some((other) => other !== pos && values[other] > 0 && values[other] === val);
        markRankInvalid(sel, repeated);
      });

      const chosen = Object.keys(values)
        .map((key) => values[key])
        .filter((value) => value > 0);

      return chosen.length === new Set(chosen).size;
    }

    async function saveNow({ silentToast = false } = {}) {
      const picks = readTop4Picks();
      const valid = paintTop4Validation(picks);

      if (!valid) {
        setTop4State("err", "Não repita times.");
        if (!silentToast) showToast("Não pode repetir o mesmo time no Top 4.", true);
        return;
      }

      if (!allTop4Filled(picks)) {
        setTop4State("", "");
        return;
      }

      const signature = stableSignature(picks);
      if (savedTop4Signature === signature) {
        setTop4State("ok", "Top 4 salvo!");
        return;
      }

      try {
        setTop4State("saving");
        await saveTop4(picks);
        savedTop4Signature = signature;
        setTop4State("ok", "Top 4 salvo!");
        if (!silentToast) showToast("Top 4 salvo.");
      } catch (e) {
        setTop4State("err", e.message || "Erro ao salvar Top 4.");
        if (!silentToast) showToast(e.message || "Erro ao salvar Top 4.", true);
      }
    }

    selects.forEach((sel) => {
      sel.addEventListener("change", () => {
        const picks = readTop4Picks();
        const valid = paintTop4Validation(picks);

        if (!valid) {
          setTop4State("err", "Não repita times.");
          showToast("Não pode repetir o mesmo time no Top 4.", true);
          return;
        }

        if (!allTop4Filled(picks)) {
          setTop4State("", "");
          return;
        }

        const current = top4DebounceTimers.get(top4Card);
        if (current) clearTimeout(current);
        top4DebounceTimers.set(top4Card, setTimeout(() => saveNow({ silentToast: true }), 350));
      });
    });

    const initialTop4Picks = readTop4Picks();
    if (allTop4Filled(initialTop4Picks)) {
      savedTop4Signature = stableSignature(initialTop4Picks);
    }
  }

  restoreStoredMatchDrafts();
  restoreStoredRankDrafts();
  flushPendingMatchSaves({ silentToast: true }).catch(() => {});
  flushPendingRankSaves({ silentToast: true }).catch(() => {});

  installFinalizeButton();

  window.addEventListener("beforeunload", (ev) => {
    dispatchExitSaveFlush("beforeunload");
    dispatchExitFinalize("beforeunload");
    if (pendingMatchSaves.size > 0 || pendingRankSaves.size > 0) {
      ev.preventDefault();
      ev.returnValue = "";
    }
  });

  window.addEventListener("pagehide", () => {
    dispatchExitSaveFlush("pagehide");
    dispatchExitFinalize("pagehide");
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      dispatchExitSaveFlush("visibilitychange");
      dispatchExitFinalize("visibilitychange");
    }
  });

  window.addEventListener("online", () => {
    flushPendingMatchSaves({ silentToast: true }).catch(() => {});
    flushPendingRankSaves({ silentToast: true }).catch(() => {});
  });

  const btnRecibo = document.getElementById("btnRecibo");
  if (btnRecibo) {
    if (!ENDPOINT_RECEIPT) {
      btnRecibo.style.display = "none";
    } else {
      let reciboLock = false;

      function openReceiptPdf() {
        if (reciboLock) return;
        reciboLock = true;

        const winName = "BOLAO_RECIBO_APP";
        try {
          if (window.__BOLAO_RECIBO_APP_WIN__ && !window.__BOLAO_RECIBO_APP_WIN__.closed) {
            window.__BOLAO_RECIBO_APP_WIN__.focus();
            window.__BOLAO_RECIBO_APP_WIN__.location.href = ENDPOINT_RECEIPT;
          } else {
            window.__BOLAO_RECIBO_APP_WIN__ = window.open(ENDPOINT_RECEIPT, winName, "noopener,noreferrer");
            if (!window.__BOLAO_RECIBO_APP_WIN__) {
              showToast("Pop-up bloqueado. Permita pop-ups para abrir o recibo.", true);
            }
          }
        } finally {
          setTimeout(() => { reciboLock = false; }, 400);
        }
      }

      btnRecibo.addEventListener("click", (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        if (typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation();
        openReceiptPdf();
      }, { capture: true, passive: false });
    }
  }

  const btnGoChampion = document.querySelector(".btn-go-champion[data-champion-url]");
  if (btnGoChampion) {
    btnGoChampion.addEventListener("click", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      if (btnGoChampion.disabled) return;
      btnGoChampion.disabled = true;
      const url = (btnGoChampion.getAttribute("data-champion-url") || "").trim() || "/campeao.php";
      window.location.href = url;
    });
  }
});

async function carregarJogosDoDia() {
  try {
    const res = await fetch("/jogos_do_dia.php");
    const jogos = await res.json();

    if (!jogos || jogos.length === 0) return;

    const lista = document.getElementById("popupLista");
    const popup = document.getElementById("popupJogos");
    if (!lista || !popup) return;

    lista.innerHTML = "";

    jogos.forEach((j) => {
      const div = document.createElement("div");
      div.style.marginBottom = "10px";
      div.innerHTML = `
        <strong>Grupo ${j.grupo}</strong><br>
        ${j.casa} x ${j.fora}<br>
        ${new Date(j.data_hora).toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" })}
      `;
      lista.appendChild(div);
    });

    popup.style.display = "flex";
  } catch (e) {
    console.error(e);
  }
}

function fecharPopup() {
  const popup = document.getElementById("popupJogos");
  if (popup) popup.style.display = "none";
}

document.addEventListener("DOMContentLoaded", () => {
  setTimeout(carregarJogosDoDia, 800);
});
