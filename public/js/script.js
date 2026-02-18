document.addEventListener("DOMContentLoaded", () => {
  // =========================================================
  // GUARDA GLOBAL: impede inicialização dupla do script inteiro
  // =========================================================
  if (window.__BOLAO_SCRIPT_INIT__ === true) return;
  window.__BOLAO_SCRIPT_INIT__ = true;

  // =========================================================
  // CONFIG (SEM JS INLINE) — lê <script type="application/json" id="app-config">
  // =========================================================
  const cfgEl = document.getElementById("app-config");
  let APP_CFG = null;
  try {
    APP_CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
  } catch (e) {
    APP_CFG = {};
  }

  window.__APP_USER__ = (APP_CFG && APP_CFG.user) ? APP_CFG.user : null;
  window.__LOCK_INFO__ = (APP_CFG && APP_CFG.lock) ? APP_CFG.lock : null;

  const ENDPOINT_SAVE_GAMES = (APP_CFG && APP_CFG.endpoints && APP_CFG.endpoints.save_games) ? APP_CFG.endpoints.save_games : "/bolao-da-copa/public/app.php?action=save";
  const ENDPOINT_SAVE_GROUP_RANK = (APP_CFG && APP_CFG.endpoints && APP_CFG.endpoints.save_group_rank) ? APP_CFG.endpoints.save_group_rank : "/bolao-da-copa/public/app.php?action=save_group_rank";
  const ENDPOINT_RECEIPT = (APP_CFG && APP_CFG.endpoints && APP_CFG.endpoints.receipt_url) ? APP_CFG.endpoints.receipt_url : "/bolao-da-copa/php/recibo.php?action=pdf";

  // =========================================================
  // Base: comportamentos globais do site (login/cadastro) + APP
  // =========================================================

  // -----------------------------
  // Floating labels (LOGIN/CAD)
  // -----------------------------
  const groups = document.querySelectorAll(".input-group");

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

  // =========================================================
  // CUSTOM SELECT (UF) — (mantido)
  // =========================================================
  (function initCustomUfSelect() {
    const form = document.querySelector(".login-form");
    if (!form) return;

    const sel = form.querySelector('select[name="estado"]');
    if (!sel) return;

    if (sel.__BOLAO_CUSTOM_SELECT__) return;
    sel.__BOLAO_CUSTOM_SELECT__ = true;

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
        .bolao-select-list::-webkit-scrollbar{ width: 10px; }
        .bolao-select-list::-webkit-scrollbar-track{ background: rgba(255,255,255,.05); }
        .bolao-select-list::-webkit-scrollbar-thumb{
          background: rgba(255,255,255,.18);
          border-radius: 999px;
          border: 2px solid rgba(0,0,0,.35);
        }
        .bolao-select-list::-webkit-scrollbar-thumb:hover{ background: rgba(255,255,255,.26); }
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

    const group = sel.closest(".input-group");
    if (!group) return;

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
    searchInput.placeholder = "Filtrar UF...";
    searchWrap.appendChild(searchInput);

    const list = document.createElement("div");
    list.className = "bolao-select-list";

    portal.appendChild(searchWrap);
    portal.appendChild(list);
    document.body.appendChild(portal);

    sel.classList.add("bolao-select-native");

    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(display);
    wrap.appendChild(caret);
    wrap.appendChild(sel);

    const options = Array.from(sel.options).filter(o => (o.value || "").trim() !== "");

    function currentValue() {
      return (sel.value || "").trim();
    }

    function setDisplayText() {
      const val = currentValue();
      const opt = options.find(o => o.value === val);

      display.textContent = "";
      if (opt) display.textContent = opt.value;
      else display.textContent = "\u00A0";

      wrap.appendChild(caret);
      syncGroupState(sel);
    }

    function buildList(activeVal) {
      list.innerHTML = "";

      options.forEach((o) => {
        const item = document.createElement("div");
        item.className = "bolao-select-opt";
        item.dataset.value = o.value;
        item.textContent = o.value;

        if (o.value === activeVal) item.classList.add("is-active");

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

      const desiredMaxH = Math.min(320, Math.floor(vh * 0.42));
      const spaceBelow = vh - rect.bottom - 10;
      const spaceAbove = rect.top - 10;

      let top = rect.bottom + 8;
      let maxH = Math.max(180, Math.min(desiredMaxH, spaceBelow));

      if (spaceBelow < 200 && spaceAbove > spaceBelow) {
        maxH = Math.max(180, Math.min(desiredMaxH, spaceAbove));
        portal.style.transformOrigin = "bottom";

        portal.style.left = `${left}px`;
        portal.style.width = `${width}px`;

        const headerH = searchWrap.getBoundingClientRect().height || 52;
        list.style.maxHeight = `${Math.max(120, maxH - headerH)}px`;

        const portalH = portal.getBoundingClientRect().height || (headerH + maxH);
        const newTop = Math.max(8, rect.top - 8 - portalH);
        portal.style.top = `${newTop}px`;
        portal.style.transform = "translateZ(0)";
        return;
      }

      portal.style.transformOrigin = "top";
      portal.style.left = `${left}px`;
      portal.style.top = `${top}px`;
      portal.style.width = `${width}px`;

      const headerH = 52;
      list.style.maxHeight = `${Math.max(120, maxH - headerH)}px`;
      portal.style.transform = "translateZ(0)";
    }

    function openMenu() {
      if (open) return;
      open = true;

      // ✅ FIX: garante que nenhum inline display:none “mate” o portal
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
        const v = (el.dataset.value || "").toUpperCase();
        el.classList.toggle("is-hidden", !v.includes(q));
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
        return;
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
      if (k === "Escape") {
        if (open) {
          ev.preventDefault();
          closeMenu(true);
        }
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
      syncGroupState(sel);
      if (open) closeMenu(true);
    });

    setDisplayText();
  })();

  // -----------------------------
  // Tilt no card (LOGIN)
  // -----------------------------
  const card = document.querySelector(".login-card");
  if (card) {
    const maxTilt = 6;

    function onMove(e) {
      const rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width;
      const y = (e.clientY - rect.top) / rect.height;

      const rotY = (x - 0.5) * (maxTilt * 2);
      const rotX = (0.5 - y) * (maxTilt * 2);

      card.style.transform = `perspective(900px) rotateX(${rotX}deg) rotateY(${rotY}deg)`;
    }

    function reset() {
      card.style.transform = "perspective(900px) rotateX(0deg) rotateY(0deg)";
    }

    const finePointer = window.matchMedia && window.matchMedia("(pointer: fine)").matches;
    const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (finePointer && !reduceMotion) {
      card.addEventListener("mousemove", onMove);
      card.addEventListener("mouseleave", reset);
    }
  }

  // -----------------------------
  // Micro feedback botão (LOGIN)
  // -----------------------------
  const btn = document.querySelector(".btn-login");
  if (btn) {
    btn.addEventListener("click", () => {
      btn.animate(
        [
          { transform: "translateY(-1px) scale(1)" },
          { transform: "translateY(0px) scale(0.985)" },
          { transform: "translateY(-1px) scale(1)" }
        ],
        { duration: 220, easing: "ease-out" }
      );
    });
  }

  // -----------------------------
  // Modal sucesso cadastro
  // -----------------------------
  const regSuccess = document.body && document.body.dataset ? document.body.dataset.regSuccess : "0";
  const modal = document.getElementById("modalSucesso");
  const btnOk = document.getElementById("btnOkCadastro");

  function goLogin() {
    window.location.href = "/bolao-da-copa/public/index.php";
  }

  function openModal() {
    if (!modal) return;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    if (btnOk) btnOk.focus();
  }

  if (regSuccess === "1") {
    openModal();

    try {
      const url = new URL(window.location.href);
      url.searchParams.delete("sucesso");
      window.history.replaceState({}, document.title, url.toString());
    } catch (e) {}

    if (btnOk) btnOk.addEventListener("click", goLogin);

    if (modal) {
      modal.addEventListener("click", (e) => {
        if (e.target === modal) goLogin();
      });
    }

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" || e.key === "Enter") goLogin();
    }, { once: true });
  }

  // =========================================================
  // APP.PHP - PALPITES (só ativa se existir .match-card)
  // =========================================================
  const appHasMatches = document.querySelector(".match-card");
  if (!appHasMatches) return;

  // =========================================================
  // ✅ CUSTOM SELECT (APP) — aplica o MESMO layout do UF
  // para os combos 1º/2º/3º (rank-select)
  // =========================================================
  (function initCustomRankSelects() {
    const selects = Array.from(document.querySelectorAll(".group-rank-card select.rank-select"));
    if (!selects.length) return;

    // CSS já foi injetado pelo UF (ou injeta se não existir)
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
        .bolao-select-list::-webkit-scrollbar{ width: 10px; }
        .bolao-select-list::-webkit-scrollbar-track{ background: rgba(255,255,255,.05); }
        .bolao-select-list::-webkit-scrollbar-thumb{
          background: rgba(255,255,255,.18);
          border-radius: 999px;
          border: 2px solid rgba(0,0,0,.35);
        }
        .bolao-select-list::-webkit-scrollbar-thumb:hover{ background: rgba(255,255,255,.26); }
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
        display.textContent = "";
        display.textContent = txt ? txt : "\u00A0";
        wrap.appendChild(caret);
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

        let top = rect.bottom + 8;
        let maxH = Math.max(180, Math.min(desiredMaxH, spaceBelow));

        portal.style.left = `${left}px`;
        portal.style.width = `${width}px`;

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
        portal.style.top = `${top}px`;

        const headerH = 52;
        list.style.maxHeight = `${Math.max(120, maxH - headerH)}px`;
        portal.style.transform = "translateZ(0)";
      }

      function openMenu() {
        if (open) return;
        open = true;

        // ✅ FIX: remove qualquer inline display:none herdado
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
          return;
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
        if (k === "Escape") {
          if (open) {
            ev.preventDefault();
            closeMenu(true);
          }
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

      setDisplayText();
    }

    // aplica para todos os selects de rank (1º/2º/3º)
    selects.forEach(sel => makeCustomSelect(sel, "Filtrar time..."));
  })();

  const toast = document.getElementById("toast");
  let toastTimer = null;

  function showToast(msg, isError = false) {
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add("is-open");

    toast.style.borderColor = isError ? "rgba(255,140,140,.35)" : "rgba(16,208,138,.30)";
    toast.style.background = isError ? "rgba(70,0,0,.45)" : "rgba(0,0,0,.55)";

    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("is-open"), 2200);
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
    if (invalid) input.classList.add("is-invalid");
    else input.classList.remove("is-invalid");
  }

  function setSavingState(cardEl, state, msg) {
    const st = cardEl.querySelector(".save-state");
    const btnOne = cardEl.querySelector(".btn-save-one");

    if (btnOne) btnOne.disabled = (state === "saving");

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

  function getCardPayload(cardEl) {
    const jogoId = Number(cardEl.getAttribute("data-jogo-id")) || 0;
    const inHome = cardEl.querySelector(".score-home");
    const inAway = cardEl.querySelector(".score-away");

    const gc = clampScore(inHome ? inHome.value : null);
    const gf = clampScore(inAway ? inAway.value : null);

    const invalidHome = (inHome && inHome.value !== "" && gc === null);
    const invalidAway = (inAway && inAway.value !== "" && gf === null);

    markInvalid(inHome, invalidHome);
    markInvalid(inAway, invalidAway);

    if (jogoId <= 0) return null;
    if (gc === null || gf === null) return null;

    return { jogo_id: jogoId, gols_casa: gc, gols_fora: gf };
  }

  // -----------------------------
  // FILTRO DE GRUPOS
  // -----------------------------
  const menuRoot = document.getElementById("menuGrupos");
  const menuLinks = menuRoot
    ? Array.from(menuRoot.querySelectorAll(".menu-link[data-grupo]"))
      .filter(a => !a.classList.contains("is-disabled"))
    : [];

  const sections = Array.from(document.querySelectorAll(".group-block[data-grupo]"));

  function setActiveMenu(grupo) {
    menuLinks.forEach(a => {
      const g = a.getAttribute("data-grupo");
      a.classList.toggle("is-active", g === grupo);
    });
  }

  function setActiveGroup(grupo) {
    sections.forEach(sec => {
      const g = sec.getAttribute("data-grupo");
      sec.classList.toggle("is-active-group", g === grupo);
    });
  }

  function firstEnabledGroup() {
    const first = menuLinks[0];
    return first ? (first.getAttribute("data-grupo") || "") : "";
  }

  function getGroupFromUrl() {
    try {
      const url = new URL(window.location.href);
      const g = (url.searchParams.get("grupo") || "").trim();
      return g ? g.toUpperCase() : "";
    } catch (e) {
      return "";
    }
  }

  function setGroupInUrl(grupo) {
    try {
      const url = new URL(window.location.href);
      url.searchParams.set("grupo", grupo);
      window.history.replaceState({}, document.title, url.toString());
    } catch (e) {}
  }

  function showGroup(grupo, { silent = false } = {}) {
    if (!grupo) return;

    setActiveMenu(grupo);
    setActiveGroup(grupo);
    setGroupInUrl(grupo);

    if (!silent) {
      const content = document.querySelector(".app-content");
      if (content) content.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  if (menuLinks.length && sections.length) {
    menuLinks.forEach(a => {
      a.addEventListener("click", (ev) => {
        ev.preventDefault();
        const grupo = (a.getAttribute("data-grupo") || "").trim();
        if (!grupo) return;
        showGroup(grupo);
      });
    });

    const initial = getGroupFromUrl() || firstEnabledGroup();
    if (initial) showGroup(initial, { silent: true });
  }

  // -----------------------------
  // Auto-save por jogo (debounce)
  // -----------------------------
  const cards = Array.from(document.querySelectorAll(".match-card"));

  cards.forEach(cardEl => {
    const btnOne = cardEl.querySelector(".btn-save-one");
    const inHome = cardEl.querySelector(".score-home");
    const inAway = cardEl.querySelector(".score-away");

    let autoTimer = null;

    const autoSave = () => {
      if (autoTimer) clearTimeout(autoTimer);
      autoTimer = setTimeout(async () => {
        const payload = getCardPayload(cardEl);
        if (!payload) return;

        try {
          setSavingState(cardEl, "saving");
          await saveItems([payload]);
          setSavingState(cardEl, "ok", "Salvo!");
        } catch (e) {
          setSavingState(cardEl, "err", e.message || "Erro ao salvar.");
        }
      }, 450);
    };

    if (inHome) inHome.addEventListener("input", autoSave);
    if (inAway) inAway.addEventListener("input", autoSave);

    if (btnOne) {
      btnOne.addEventListener("click", async () => {
        const payload = getCardPayload(cardEl);
        if (!payload) {
          setSavingState(cardEl, "err", "Preencha os dois placares.");
          showToast("Preencha os dois placares antes de salvar.", true);
          return;
        }

        try {
          setSavingState(cardEl, "saving");
          await saveItems([payload]);
          setSavingState(cardEl, "ok", "Salvo!");
          showToast("Palpite salvo.");
        } catch (e) {
          setSavingState(cardEl, "err", e.message || "Erro ao salvar.");
          showToast(e.message || "Erro ao salvar.", true);
        }
      });
    }
  });

  // -----------------------------
  // Salvar tudo (Ctrl+Enter)
  // -----------------------------
  const btnSalvarTudo = document.getElementById("btnSalvarTudo");

  async function saveAll() {
    const items = [];
    let invalidCount = 0;

    const activeGroupEl = document.querySelector(".group-block.is-active-group");
    const cardsScope = activeGroupEl
      ? Array.from(activeGroupEl.querySelectorAll(".match-card"))
      : cards;

    cardsScope.forEach(cardEl => {
      const inHome = cardEl.querySelector(".score-home");
      const inAway = cardEl.querySelector(".score-away");

      const gc = clampScore(inHome ? inHome.value : null);
      const gf = clampScore(inAway ? inAway.value : null);

      markInvalid(inHome, (inHome && inHome.value !== "" && gc === null));
      markInvalid(inAway, (inAway && inAway.value !== "" && gf === null));

      if (gc === null || gf === null) {
        if ((inHome && inHome.value !== "") || (inAway && inAway.value !== "")) invalidCount++;
        return;
      }

      const payload = getCardPayload(cardEl);
      if (payload) items.push(payload);
    });

    if (items.length === 0) {
      showToast(invalidCount > 0 ? "Há placares inválidos. Corrija e tente novamente." : "Nada para salvar.");
      return;
    }

    if (btnSalvarTudo) btnSalvarTudo.disabled = true;

    try {
      showToast("Salvando...");
      const data = await saveItems(items);
      showToast(`Salvo! (${data.saved} palpites)`);
    } catch (e) {
      showToast(e.message || "Erro ao salvar.", true);
      throw e;
    } finally {
      if (btnSalvarTudo) btnSalvarTudo.disabled = false;
    }
  }

  if (btnSalvarTudo) btnSalvarTudo.addEventListener("click", saveAll);

  document.addEventListener("keydown", (ev) => {
    if ((ev.ctrlKey || ev.metaKey) && ev.key === "Enter") {
      ev.preventDefault();
      saveAll();
    }
  });

  // =========================================================
  // Botões de navegação de grupos (Próximo / Anterior)
  // =========================================================
  const nextButtons = Array.from(document.querySelectorAll(".btn-next-group[data-next-grupo]"));
  nextButtons.forEach(btnNext => {
    btnNext.addEventListener("click", () => {
      const nextGrupo = (btnNext.getAttribute("data-next-grupo") || "").trim();
      if (!nextGrupo) return;
      showGroup(nextGrupo);
    });
  });

  const prevButtons = Array.from(document.querySelectorAll(".btn-prev-group[data-prev-grupo]"));
  prevButtons.forEach(btnPrev => {
    btnPrev.addEventListener("click", () => {
      const prevGrupo = (btnPrev.getAttribute("data-prev-grupo") || "").trim();
      if (!prevGrupo) return;
      showGroup(prevGrupo);
    });
  });

  // =========================================================
  // ✅ ÚLTIMO GRUPO — botão "Quem será o campeão"
  // - salva antes de ir
  // =========================================================
  const btnGoChampion = document.querySelector(".btn-go-champion[data-champion-url]");
  if (btnGoChampion) {
    btnGoChampion.addEventListener("click", async (ev) => {
      ev.preventDefault();
      ev.stopPropagation();

      if (btnGoChampion.disabled) return;
      btnGoChampion.disabled = true;

      const url = (btnGoChampion.getAttribute("data-champion-url") || "").trim() || "/bolao-da-copa/public/campeao.php";

      try {
        await saveAll();
      } catch (e) {
        // se falhar, não navega (e libera botão)
        btnGoChampion.disabled = false;
        return;
      }

      window.location.href = url;
    });
  }

  // =========================================================
  // "Recibo" - abre PDF em nova aba e NÃO muda a aba atual
  // =========================================================
  const btnRecibo = document.getElementById("btnRecibo");
  if (btnRecibo) {
    try { btnRecibo.setAttribute("type", "button"); } catch (e) {}

    let reciboLock = false;
    function openReceiptPdf() {
      if (reciboLock) return;
      reciboLock = true;

      const url = ENDPOINT_RECEIPT;
      const winName = "BOLAO_RECIBO";

      try {
        if (window.__BOLAO_RECIBO_WIN__ && !window.__BOLAO_RECIBO_WIN__.closed) {
          window.__BOLAO_RECIBO_WIN__.focus();
          window.__BOLAO_RECIBO_WIN__.location.href = url;
        } else {
          window.__BOLAO_RECIBO_WIN__ = window.open(url, winName, "noopener,noreferrer");
          if (!window.__BOLAO_RECIBO_WIN__) window.location.href = url;
        }
      } finally {
        setTimeout(() => { reciboLock = false; }, 400);
      }
    }

    btnRecibo.addEventListener("click", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation();
      try { openReceiptPdf(); } catch (e) {}
    }, { capture: true, passive: false });
  }

  // =========================================================
  // ✅ NOVO — CLASSIFICAÇÃO DO GRUPO (1º/2º/3º)
  // =========================================================
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

  function setRankState(cardEl, state, msg) {
    const st = cardEl.querySelector(".rank-state");
    const btn = cardEl.querySelector(".btn-rank-save");
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
  }

  const rankCards = Array.from(document.querySelectorAll(".group-rank-card[data-grupo-rank]"));

  rankCards.forEach(cardEl => {
    const grupoId = Number(cardEl.getAttribute("data-grupo-rank")) || 0;
    if (grupoId <= 0) return;

    const selects = Array.from(cardEl.querySelectorAll(".rank-select[data-rank-pos]"));
    if (!selects.length) return;

    const btnSave = cardEl.querySelector(".btn-rank-save");

    function readPicks() {
      const picks = { "1": 0, "2": 0, "3": 0 };
      selects.forEach(sel => {
        const pos = String(sel.getAttribute("data-rank-pos") || "");
        const val = Number(sel.value || 0) || 0;
        if (pos === "1" || pos === "2" || pos === "3") picks[pos] = val;
      });
      return picks;
    }

    function validatePicks(picks) {
      const chosen = [];
      Object.keys(picks).forEach(k => {
        const v = Number(picks[k] || 0) || 0;
        if (v > 0) chosen.push(v);
      });
      const ok = (chosen.length === new Set(chosen).size);
      return ok;
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

    async function doSave({ silentToast = false } = {}) {
      const picks = readPicks();
      const valid = validatePicks(picks);
      paintValidation(picks);

      if (!valid) {
        setRankState(cardEl, "err", "Não repita times.");
        if (!silentToast) showToast("Não pode repetir o mesmo time em 1º/2º/3º.", true);
        return;
      }

      try {
        setRankState(cardEl, "saving");
        await saveGroupRank(grupoId, picks);
        setRankState(cardEl, "ok", "Classificação salva!");
        if (!silentToast) showToast("Classificação do grupo salva.");
      } catch (e) {
        setRankState(cardEl, "err", e.message || "Erro ao salvar.");
        if (!silentToast) showToast(e.message || "Erro ao salvar classificação.", true);
      }
    }

    // autosave debounce em change
    selects.forEach(sel => {
      sel.addEventListener("change", () => {
        const picks = readPicks();
        const ok = paintValidation(picks);
        if (!ok) {
          setRankState(cardEl, "err", "Não repita times.");
          showToast("Não pode repetir o mesmo time em 1º/2º/3º.", true);
          return;
        }

        if (debounce) clearTimeout(debounce);
        debounce = setTimeout(() => doSave({ silentToast: true }), 350);
      });
    });

    if (btnSave) {
      btnSave.addEventListener("click", () => doSave({ silentToast: false }));
    }
  });
});
