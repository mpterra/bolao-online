document.addEventListener("DOMContentLoaded", () => {
  "use strict";

  if (window.__PALPITES_DIA_INIT__ === true) return;
  window.__PALPITES_DIA_INIT__ = true;

  const appHasMatches = document.querySelector(".match-card, .group-rank-card");
  if (!appHasMatches) return;

  const cfgEl = document.getElementById("palpites-dia-config");
  let APP_CFG = {};
  try {
    APP_CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
  } catch (e) {
    APP_CFG = {};
  }

  const ENDPOINT_SAVE_GAMES = (APP_CFG.endpoints && APP_CFG.endpoints.save_games)
    ? APP_CFG.endpoints.save_games
    : "/palpites_por_dia.php?action=save";

  const ENDPOINT_SAVE_GROUP_RANK = (APP_CFG.endpoints && APP_CFG.endpoints.save_group_rank)
    ? APP_CFG.endpoints.save_group_rank
    : "/palpites_por_dia.php?action=save_group_rank";

  const ENDPOINT_SAVE_TOP4 = (APP_CFG.endpoints && APP_CFG.endpoints.save_top4)
    ? APP_CFG.endpoints.save_top4
    : "/palpites_por_dia.php?action=save_top4";

  const ENDPOINT_RECEIPT = (APP_CFG.endpoints && APP_CFG.endpoints.receipt_url)
    ? APP_CFG.endpoints.receipt_url
    : null;

  const toast = document.getElementById("toast");
  let toastTimer = null;

  function showToast(msg, isError = false) {
    if (!toast) return;

    toast.textContent = msg;
    toast.classList.add("is-open");
    toast.style.borderColor = isError ? "rgba(255,140,140,.35)" : "rgba(16,208,138,.30)";
    toast.style.background = isError ? "rgba(70,0,0,.45)" : "rgba(0,0,0,.55)";

    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.classList.remove("is-open");
    }, 2400);
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

  function isKnockoutCard(cardEl) {
    return String(cardEl.getAttribute("data-is-knockout") || "") === "1";
  }

  function getScores(cardEl) {
    const inHome = cardEl.querySelector(".score-home");
    const inAway = cardEl.querySelector(".score-away");

    if (inHome) sanitizeScoreInput(inHome);
    if (inAway) sanitizeScoreInput(inAway);

    const gc = clampScore(inHome ? inHome.value : null);
    const gf = clampScore(inAway ? inAway.value : null);

    markInvalid(inHome, !!(inHome && inHome.value !== "" && gc === null));
    markInvalid(inAway, !!(inAway && inAway.value !== "" && gf === null));

    return { gc, gf, inHome, inAway };
  }

  function isTieNeedPass(cardEl) {
    if (!isKnockoutCard(cardEl)) return false;
    const { gc, gf } = getScores(cardEl);
    return (gc !== null && gf !== null && gc === gf);
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
    btns.forEach(btn => {
      const pass = String(btn.getAttribute("data-pass") || "");
      let btnTeamId = 0;
      if (pass === "home") btnTeamId = homeId;
      if (pass === "away") btnTeamId = awayId;
      btn.classList.toggle("is-active", btnTeamId > 0 && btnTeamId === Number(teamId || 0));
    });

    const btnPass = cardEl.querySelector(".btn-pass");
    if (btnPass) {
      if (Number(teamId || 0) === homeId) {
        btnPass.textContent = `Passa: ${cardEl.getAttribute("data-home") || "Casa"}`;
      } else if (Number(teamId || 0) === awayId) {
        btnPass.textContent = `Passa: ${cardEl.getAttribute("data-away") || "Fora"}`;
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
    if (selectedPass) {
      setSelectedPassTeamId(cardEl, selectedPass);
    } else {
      setSelectedPassTeamId(cardEl, 0);
    }

    if (cardEl.classList.contains("is-choosing-pass")) {
      chooser.style.display = "flex";
    } else {
      chooser.style.display = "none";
    }
  }

  async function saveItems(items) {
    const res = await fetch(ENDPOINT_SAVE_GAMES, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify({ items })
    });

    let data = null;
    try { data = await res.json(); } catch (e) {}

    if (!res.ok || !data || data.ok !== true) {
      const msg = (data && data.message) ? data.message : "Falha ao salvar.";
      throw new Error(msg);
    }
    return data;
  }

  async function saveGroupRank(grupoId, picks) {
    const res = await fetch(ENDPOINT_SAVE_GROUP_RANK, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify({ grupo_id: grupoId, picks })
    });

    let data = null;
    try { data = await res.json(); } catch (e) {}

    if (!res.ok || !data || data.ok !== true) {
      const msg = (data && data.message) ? data.message : "Falha ao salvar classificação.";
      throw new Error(msg);
    }

    return data;
  }

  async function saveTop4(picks) {
    const res = await fetch(ENDPOINT_SAVE_TOP4, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify({ picks })
    });

    let data = null;
    try { data = await res.json(); } catch (e) {}

    if (!res.ok || !data || data.ok !== true) {
      const msg = (data && data.message) ? data.message : "Falha ao salvar Top 4.";
      throw new Error(msg);
    }

    return data;
  }

  function setSavingState(cardEl, state, msg) {
    const st = cardEl.querySelector(".save-state");
    if (!st) return;

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

    st.textContent = "";
  }

  function getCardPayload(cardEl) {
    const jogoId = Number(cardEl.getAttribute("data-jogo-id")) || 0;
    const { gc, gf } = getScores(cardEl);

    if (jogoId <= 0) return null;
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

  /* =========================================================
     CUSTOM SELECT
     ========================================================= */
  (function initCustomRankSelects() {
    const selects = Array.from(document.querySelectorAll(".group-rank-card select.rank-select"));
    if (!selects.length) return;

    if (!document.getElementById("bolao-custom-select-css")) {
      const style = document.createElement("style");
      style.id = "bolao-custom-select-css";
      style.textContent = `
        .bolao-select-wrap{ position:relative; width:100%; }
        .bolao-select-native{
          position:absolute !important;
          inset:0 !important;
          width:100% !important;
          height:100% !important;
          opacity:0 !important;
          pointer-events:none !important;
        }
        .bolao-select-display{
          width:100%;
          padding: 12px 44px 12px 14px;
          border-radius: 12px;
          border: 1px solid rgba(255,255,255,.18);
          background: rgba(255,255,255,.10);
          color: var(--text);
          font-size: 14px;
          outline:none;
          transition: 220ms ease;
          cursor:pointer;
          user-select:none;
          display:flex;
          align-items:center;
          -webkit-tap-highlight-color: transparent;
          min-height: unset;
          line-height: normal;
        }
        .bolao-select-display:focus{
          border-color: rgba(16,208,138,.55);
          box-shadow: 0 0 0 4px rgba(16,208,138,.14), 0 10px 22px rgba(0,0,0,.25);
          background: rgba(255,255,255,.12);
        }
        .bolao-select-display.is-invalid{
          border-color: rgba(255,140,140,.45);
          box-shadow: 0 0 0 4px rgba(255,140,140,.10);
        }
        .bolao-select-caret{
          position:absolute;
          right: 14px;
          top: 50%;
          transform: translateY(-50%);
          width: 18px;
          height: 18px;
          pointer-events:none;
          opacity:.9;
        }
        .bolao-select-portal{
          position: fixed;
          z-index: 999999;
          border-radius: 14px;
          border: 1px solid rgba(255,255,255,.16);
          background: rgba(0,0,0,.68);
          backdrop-filter: blur(12px);
          box-shadow: 0 22px 60px rgba(0,0,0,.55);
          overflow: hidden;
          display:none;
        }
        .bolao-select-portal.is-open{ display:block; }
        .bolao-select-search{
          padding: 10px;
          border-bottom: 1px solid rgba(255,255,255,.10);
          background: rgba(255,255,255,.06);
        }
        .bolao-select-search input{
          width:100%;
          padding: 10px 12px;
          border-radius: 12px;
          border: 1px solid rgba(255,255,255,.14);
          background: rgba(0,0,0,.20);
          color: rgba(255,255,255,.92);
          font-weight: 900;
          font-size: 13px;
          outline:none;
          transition: 180ms ease;
        }
        .bolao-select-search input:focus{
          border-color: rgba(16,208,138,.55);
          box-shadow: 0 0 0 4px rgba(16,208,138,.12);
        }
        .bolao-select-search input::placeholder{
          color: rgba(255,255,255,.55);
          font-weight: 800;
        }
        .bolao-select-list{
          max-height: 260px;
          overflow: auto;
          overscroll-behavior: contain;
          -webkit-overflow-scrolling: touch;
        }
        .bolao-select-opt{
          padding: 10px 12px;
          font-weight: 900;
          font-size: 13px;
          color: rgba(255,255,255,.92);
          display:flex;
          align-items:center;
          justify-content:space-between;
          cursor:pointer;
          border-bottom: 1px solid rgba(255,255,255,.08);
          -webkit-tap-highlight-color: transparent;
        }
        .bolao-select-opt:last-child{ border-bottom:0; }
        .bolao-select-opt:hover{ background: rgba(255,255,255,.08); }
        .bolao-select-opt.is-active{
          color: #062027;
          background: linear-gradient(90deg, var(--green), var(--gold));
        }
        .bolao-select-opt.is-hidden{ display:none; }
      `;
      document.head.appendChild(style);
    }

    function syncInvalidState(sel, display) {
      if (!sel || !display) return;
      display.classList.toggle("is-invalid", sel.classList.contains("is-invalid"));
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
      caret.innerHTML = `<path d="M7 10l5 5 5-5" fill="none" stroke="rgba(255,255,255,.9)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"></path>`;

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

      const options = Array.from(sel.options).filter(o => (o.value || "").trim() !== "");

      function currentValue() {
        return (sel.value || "").trim();
      }

      function selectedText() {
        const v = currentValue();
        const opt = options.find(o => String(o.value) === String(v));
        return opt ? (opt.textContent || "").trim() : "";
      }

      function setDisplayText() {
        const txt = selectedText();
        display.textContent = txt ? txt : "\u00A0";
        wrap.appendChild(caret);
        syncInvalidState(sel, display);
      }

      function buildList(activeVal) {
        list.innerHTML = "";
        options.forEach((o) => {
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
            try { sel.dispatchEvent(new Event("change", { bubbles: true })); } catch (e) {}

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

        portal.style.left = `${left}px`;
        portal.style.width = `${width}px`;

        let maxH = Math.max(180, Math.min(desiredMaxH, spaceBelow));

        if (spaceBelow < 200 && spaceAbove > spaceBelow) {
          maxH = Math.max(180, Math.min(desiredMaxH, spaceAbove));
          portal.style.transformOrigin = "bottom";

          const headerH = searchWrap.getBoundingClientRect().height || 52;
          list.style.maxHeight = `${Math.max(120, maxH - headerH)}px`;

          portal.classList.add("is-open");
          const portalH = portal.getBoundingClientRect().height || (headerH + maxH);
          const newTop = Math.max(8, rect.top - 8 - portalH);
          portal.style.top = `${newTop}px`;
          portal.style.transform = "translateZ(0)";
          return;
        }

        portal.style.transformOrigin = "top";
        portal.style.top = `${rect.bottom + 8}px`;

        const headerH = 52;
        list.style.maxHeight = `${Math.max(120, maxH - headerH)}px`;
        portal.style.transform = "translateZ(0)";
      }

      function openMenu() {
        if (open) return;
        open = true;

        portal.style.display = "";
        display.setAttribute("aria-expanded", "true");
        buildList(currentValue());

        searchInput.value = "";
        Array.from(list.children).forEach(el => el.classList.remove("is-hidden"));

        portal.classList.add("is-open");
        positionPortal();

        setTimeout(() => {
          try { searchInput.focus(); } catch (e) {}
        }, 0);
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
          try { display.focus(); } catch (e) {}
        }
      }

      function toggleMenu() {
        if (open) closeMenu(true);
        else openMenu();
      }

      function applyFilter() {
        const q = (searchInput.value || "").trim().toUpperCase();
        const items = Array.from(list.querySelectorAll(".bolao-select-opt"));
        if (!q) {
          items.forEach(el => el.classList.remove("is-hidden"));
          return;
        }
        items.forEach(el => {
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
        const k = ev.key;
        if (k === "Enter" || k === " ") {
          ev.preventDefault();
          toggleMenu();
          return;
        }
        if (k === "ArrowDown") {
          ev.preventDefault();
          if (!open) openMenu();
          else searchInput.focus();
          return;
        }
        if (k === "Escape" && open) {
          ev.preventDefault();
          closeMenu(true);
        }
      });

      document.addEventListener("click", (ev) => {
        if (!open) return;
        if (wrap.contains(ev.target)) return;
        if (portal.contains(ev.target)) return;
        closeMenu(false);
      });

      document.addEventListener("pointerdown", (ev) => {
        if (!open) return;
        if (wrap.contains(ev.target)) return;
        if (portal.contains(ev.target)) return;
        closeMenu(false);
      }, { capture: true });

      window.addEventListener("resize", () => { if (open) positionPortal(); });
      window.addEventListener("scroll", () => { if (open) positionPortal(); }, true);
      window.addEventListener("orientationchange", () => { if (open) closeMenu(false); });

      sel.addEventListener("change", () => {
        setDisplayText();
        if (open) closeMenu(true);
      });

      const observer = new MutationObserver(() => syncInvalidState(sel, display));
      observer.observe(sel, { attributes: true, attributeFilter: ["class"] });

      setDisplayText();
    }

    selects.forEach(sel => makeCustomSelect(sel, "Filtrar time..."));
  })();

  /* =========================================================
     MENU POR DIA
     ========================================================= */
  const menuRoot = document.getElementById("menuDias");
  const menuLinks = menuRoot
    ? Array.from(menuRoot.querySelectorAll(".menu-link[data-day]"))
    : [];

  const sections = Array.from(document.querySelectorAll(".day-block[data-day-block]"));

  function setActiveMenu(day) {
    menuLinks.forEach(a => {
      const d = a.getAttribute("data-day");
      a.classList.toggle("is-active", d === day);
    });
  }

  function setActiveDay(day) {
    sections.forEach(sec => {
      const d = sec.getAttribute("data-day-block");
      sec.classList.toggle("is-active-group", d === day);
    });
  }

  function firstEnabledDay() {
    const first = menuLinks[0];
    return first ? (first.getAttribute("data-day") || "") : "";
  }

  function getDayFromUrl() {
    try {
      const url = new URL(window.location.href);
      const d = (url.searchParams.get("dia") || "").trim();
      return d;
    } catch (e) {
      return "";
    }
  }

  function setDayInUrl(day) {
    try {
      const url = new URL(window.location.href);
      url.searchParams.set("dia", day);
      window.history.replaceState({}, document.title, url.toString());
    } catch (e) {}
  }

  function showDay(day, { silent = false } = {}) {
    if (!day) return;
    setActiveMenu(day);
    setActiveDay(day);
    setDayInUrl(day);

    if (!silent) {
      const content = document.querySelector(".app-content");
      if (content) content.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  if (menuLinks.length && sections.length) {
    menuLinks.forEach(a => {
      a.addEventListener("click", (ev) => {
        ev.preventDefault();
        const day = (a.getAttribute("data-day") || "").trim();
        if (!day) return;
        showDay(day);
      });
    });

    const initial = getDayFromUrl() || firstEnabledDay();
    if (initial) showDay(initial, { silent: true });
  }

  /* =========================================================
     MATCH CARDS
     ========================================================= */
  const cards = Array.from(document.querySelectorAll(".match-card"));

  cards.forEach(cardEl => {
    const inHome = cardEl.querySelector(".score-home");
    const inAway = cardEl.querySelector(".score-away");
    const btnPass = cardEl.querySelector(".btn-pass");
    const chooser = cardEl.querySelector(".pass-chooser");
    const passChoices = Array.from(cardEl.querySelectorAll(".pass-choice"));

    let autoTimer = null;

    async function autoSave() {
      if (autoTimer) clearTimeout(autoTimer);

      autoTimer = setTimeout(async () => {
        refreshPassUi(cardEl);

        const payload = getCardPayload(cardEl);
        if (!payload) return;

        if (payload.invalid) {
          setSavingState(cardEl, "err", payload.reason || "Erro ao salvar.");
          return;
        }

        try {
          setSavingState(cardEl, "saving");
          await saveItems([payload]);

          if (isKnockoutCard(cardEl) && payload.gols_casa === payload.gols_fora && payload.passa_time_id) {
            setSavingState(cardEl, "ok", "Salvo com quem passa.");
          } else {
            setSavingState(cardEl, "ok", "Salvo!");
          }
        } catch (e) {
          setSavingState(cardEl, "err", e.message || "Erro ao salvar.");
        }
      }, 450);
    }

    if (inHome) {
      inHome.addEventListener("input", autoSave);
      inHome.addEventListener("blur", () => {
        sanitizeScoreInput(inHome);
        refreshPassUi(cardEl);
      });
    }

    if (inAway) {
      inAway.addEventListener("input", autoSave);
      inAway.addEventListener("blur", () => {
        sanitizeScoreInput(inAway);
        refreshPassUi(cardEl);
      });
    }

    if (btnPass && chooser) {
      btnPass.addEventListener("click", () => {
        if (btnPass.disabled) return;
        cardEl.classList.toggle("is-choosing-pass");
        chooser.style.display = cardEl.classList.contains("is-choosing-pass") ? "flex" : "none";
      });
    }

    passChoices.forEach(btn => {
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
        if (chooser) chooser.style.display = "none";

        const payload = getCardPayload(cardEl);
        if (!payload || payload.invalid) {
          setSavingState(cardEl, "err", "Preencha o placar empatado antes de escolher quem passa.");
          return;
        }

        try {
          setSavingState(cardEl, "saving");
          await saveItems([payload]);
          setSavingState(cardEl, "ok", "Salvo com quem passa.");
        } catch (e) {
          setSavingState(cardEl, "err", e.message || "Erro ao salvar.");
        }
      });
    });

    refreshPassUi(cardEl);
  });

  /* =========================================================
     RECIBO
     ========================================================= */
  const btnRecibo = document.getElementById("btnRecibo");
  if (btnRecibo) {
    if (!ENDPOINT_RECEIPT) {
      btnRecibo.style.display = "none";
    } else {
      let reciboLock = false;

      function openReceiptPdf() {
        if (reciboLock) return;
        reciboLock = true;

        const url = ENDPOINT_RECEIPT;
        const winName = "BOLAO_RECIBO_DIA";

        try {
          if (window.__BOLAO_RECIBO_DIA_WIN__ && !window.__BOLAO_RECIBO_DIA_WIN__.closed) {
            window.__BOLAO_RECIBO_DIA_WIN__.focus();
            window.__BOLAO_RECIBO_DIA_WIN__.location.href = url;
          } else {
            window.__BOLAO_RECIBO_DIA_WIN__ = window.open(url, winName, "noopener,noreferrer");
            if (!window.__BOLAO_RECIBO_DIA_WIN__) {
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

  /* =========================================================
     CLASSIFICAÇÃO DOS GRUPOS
     ========================================================= */
  function setRankState(cardEl, state, msg) {
    const st = cardEl.querySelector(".rank-state");
    const btn = cardEl.querySelector(".btn-group-save");
    if (btn) btn.disabled = (state === "saving");

    if (!st) return;
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

    st.textContent = "";
  }

  function markRankInvalid(selectEl, invalid) {
    if (!selectEl) return;
    selectEl.classList.toggle("is-invalid", !!invalid);
    const wrap = selectEl.parentElement ? selectEl.parentElement.querySelector(".bolao-select-display") : null;
    if (wrap) wrap.classList.toggle("is-invalid", !!invalid);
  }

  const rankCards = Array.from(document.querySelectorAll(".group-rank-card[data-grupo-rank]"));

  rankCards.forEach(cardEl => {
    const grupoId = Number(cardEl.getAttribute("data-grupo-rank")) || 0;
    if (grupoId <= 0) return;

    const selects = Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]"));
    const btnSave = cardEl.querySelector(".btn-group-save");

    function readPicks() {
      const picks = { "1": 0, "2": 0, "3": 0 };
      selects.forEach(sel => {
        const pos = String(sel.getAttribute("data-rank-pos") || "");
        const val = Number(sel.value || 0) || 0;
        if (pos === "1" || pos === "2" || pos === "3") picks[pos] = val;
      });
      return picks;
    }

    function allPicksFilled(picks) {
      return (Number(picks["1"] || 0) > 0)
        && (Number(picks["2"] || 0) > 0)
        && (Number(picks["3"] || 0) > 0);
    }

    function validatePicks(picks) {
      const chosen = [];
      Object.keys(picks).forEach(k => {
        const v = Number(picks[k] || 0) || 0;
        if (v > 0) chosen.push(v);
      });
      return (chosen.length === new Set(chosen).size);
    }

    function paintValidation(picks) {
      const vals = {
        "1": Number(picks["1"] || 0) || 0,
        "2": Number(picks["2"] || 0) || 0,
        "3": Number(picks["3"] || 0) || 0
      };

      const dup = (a, b) => a > 0 && b > 0 && a === b;

      const bad1 = dup(vals["1"], vals["2"]) || dup(vals["1"], vals["3"]);
      const bad2 = dup(vals["2"], vals["1"]) || dup(vals["2"], vals["3"]);
      const bad3 = dup(vals["3"], vals["1"]) || dup(vals["3"], vals["2"]);

      selects.forEach(sel => {
        const pos = String(sel.getAttribute("data-rank-pos") || "");
        if (pos === "1") markRankInvalid(sel, bad1);
        if (pos === "2") markRankInvalid(sel, bad2);
        if (pos === "3") markRankInvalid(sel, bad3);
      });

      return !(bad1 || bad2 || bad3);
    }

    let debounce = null;

    async function saveRank({ silentToast = false } = {}) {
      const picks = readPicks();
      const valid = validatePicks(picks);
      paintValidation(picks);

      if (!valid) {
        setRankState(cardEl, "err", "Não repita times.");
        if (!silentToast) showToast("Não pode repetir o mesmo time em 1º/2º/3º.", true);
        return;
      }

      if (!allPicksFilled(picks)) {
        setRankState(cardEl, "err", "Complete 1º, 2º e 3º.");
        if (!silentToast) showToast("Complete 1º, 2º e 3º para salvar o grupo.", true);
        return;
      }

      try {
        setRankState(cardEl, "saving");
        await saveGroupRank(grupoId, picks);
        setRankState(cardEl, "ok", "Grupo salvo!");
        if (!silentToast) showToast("Classificação do grupo salva.");
      } catch (e) {
        setRankState(cardEl, "err", e.message || "Erro ao salvar.");
        if (!silentToast) showToast(e.message || "Erro ao salvar grupo.", true);
      }
    }

    selects.forEach(sel => {
      sel.addEventListener("change", () => {
        const picks = readPicks();
        const ok = paintValidation(picks);

        if (!ok) {
          setRankState(cardEl, "err", "Não repita times.");
          showToast("Não pode repetir o mesmo time em 1º/2º/3º.", true);
          return;
        }

        if (!allPicksFilled(picks)) {
          setRankState(cardEl, "", "");
          return;
        }

        if (debounce) clearTimeout(debounce);
        debounce = setTimeout(() => saveRank({ silentToast: true }), 350);
      });
    });

    if (btnSave) {
      btnSave.addEventListener("click", () => saveRank({ silentToast: false }));
    }
  });

  /* =========================================================
     TOP 4
     ========================================================= */
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

    function readPicks() {
      const picks = { "1": 0, "2": 0, "3": 0, "4": 0 };
      selects.forEach(sel => {
        const pos = String(sel.getAttribute("data-top4-pos") || "");
        const val = Number(sel.value || 0) || 0;
        if (pos === "1" || pos === "2" || pos === "3" || pos === "4") picks[pos] = val;
      });
      return picks;
    }

    function allFilled(picks) {
      return ["1", "2", "3", "4"].every(k => Number(picks[k] || 0) > 0);
    }

    function validateDistinct(picks) {
      const vals = ["1", "2", "3", "4"]
        .map(k => Number(picks[k] || 0) || 0)
        .filter(v => v > 0);

      return vals.length === new Set(vals).size;
    }

    function paintValidation(picks) {
      const values = {
        "1": Number(picks["1"] || 0) || 0,
        "2": Number(picks["2"] || 0) || 0,
        "3": Number(picks["3"] || 0) || 0,
        "4": Number(picks["4"] || 0) || 0
      };

      selects.forEach(sel => markRankInvalid(sel, false));

      selects.forEach(sel => {
        const pos = String(sel.getAttribute("data-top4-pos") || "");
        const val = values[pos] || 0;
        if (val <= 0) return;

        const repeated = Object.keys(values).some(other => other !== pos && values[other] > 0 && values[other] === val);
        markRankInvalid(sel, repeated);
      });

      return validateDistinct(picks);
    }

    let debounce = null;

    async function saveNow({ silentToast = false } = {}) {
      const picks = readPicks();
      const valid = paintValidation(picks);

      if (!valid) {
        setTop4State("err", "Não repita times.");
        if (!silentToast) showToast("Não pode repetir o mesmo time no Top 4.", true);
        return;
      }

      if (!allFilled(picks)) {
        setTop4State("", "");
        return;
      }

      try {
        setTop4State("saving");
        await saveTop4(picks);
        setTop4State("ok", "Top 4 salvo!");
        if (!silentToast) showToast("Top 4 salvo.");
      } catch (e) {
        setTop4State("err", e.message || "Erro ao salvar Top 4.");
        if (!silentToast) showToast(e.message || "Erro ao salvar Top 4.", true);
      }
    }

    selects.forEach(sel => {
      sel.addEventListener("change", () => {
        const picks = readPicks();
        const valid = paintValidation(picks);

        if (!valid) {
          setTop4State("err", "Não repita times.");
          showToast("Não pode repetir o mesmo time no Top 4.", true);
          return;
        }

        if (!allFilled(picks)) {
          setTop4State("", "");
          return;
        }

        if (debounce) clearTimeout(debounce);
        debounce = setTimeout(() => saveNow({ silentToast: true }), 350);
      });
    });
  }
});