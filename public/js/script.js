document.addEventListener("DOMContentLoaded", () => {
  // =========================================================
  // GUARDA GLOBAL: impede inicialização dupla do script inteiro
  // =========================================================
  if (window.__BOLAO_SCRIPT_INIT__ === true) return;
  window.__BOLAO_SCRIPT_INIT__ = true;

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
  // CUSTOM SELECT (UF) — PORTAL NO BODY + SCROLL REAL + LOOK PERFEITO
  // - Mantém o <select> real (submit normal)
  // - Renderiza um "display" no lugar com o mesmo look do input
  // - Dropdown é criado no BODY (não corta no card com overflow hidden)
  // =========================================================
  (function initCustomUfSelect() {
    const form = document.querySelector(".login-form");
    if (!form) return;

    const sel = form.querySelector('select[name="estado"]');
    if (!sel) return;

    if (sel.__BOLAO_CUSTOM_SELECT__) return;
    sel.__BOLAO_CUSTOM_SELECT__ = true;

    // CSS (uma vez)
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

        /* Display com o MESMO visual do input (altura igual) */
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

          /* ✅ NÃO força altura maior que input */
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

        /* Dropdown PORTAL (no body) */
        .bolao-select-portal{
          position: fixed;
          z-index: 999999; /* acima de tudo */
          border-radius: 14px;
          border: 1px solid rgba(255,255,255,.16);
          background: rgba(0,0,0,.68);
          backdrop-filter: blur(12px);
          box-shadow: 0 22px 60px rgba(0,0,0,.55);
          overflow: hidden;

          /* ✅ fechado por padrão (evita bug de inline display) */
          display:none;
        }
        .bolao-select-portal.is-open{ display:block; }

        /* Cabeçalho de busca */
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

        /* Lista rolável REAL */
        .bolao-select-list{
          max-height: 260px;
          overflow: auto;
          overscroll-behavior: contain;
          -webkit-overflow-scrolling: touch;
        }

        /* Scrollbar (Chrome) */
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

    // Wrapper no lugar do select
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

    // Portal no body
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

    // Esconde select nativo mas mantém para submit
    sel.classList.add("bolao-select-native");

    // Monta DOM no lugar
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(display);
    wrap.appendChild(caret);
    wrap.appendChild(sel);

    // Captura options (todas as UF)
    const options = Array.from(sel.options).filter(o => (o.value || "").trim() !== "");

    function currentValue() {
      return (sel.value || "").trim();
    }

    // ✅ SEM placeholder "Selecione..." (fica vazio; label já orienta)
    function setDisplayText() {
      const val = currentValue();
      const opt = options.find(o => o.value === val);

      display.textContent = "";
      if (opt) {
        display.textContent = opt.value;
      } else {
        // mantém layout sem texto
        display.textContent = "\u00A0";
      }

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

      // tenta abrir para baixo; se não couber, abre para cima
      const desiredMaxH = Math.min(320, Math.floor(vh * 0.42));
      const spaceBelow = vh - rect.bottom - 10;
      const spaceAbove = rect.top - 10;

      let top = rect.bottom + 8;
      let maxH = Math.max(180, Math.min(desiredMaxH, spaceBelow));

      if (spaceBelow < 200 && spaceAbove > spaceBelow) {
        // abre pra cima
        maxH = Math.max(180, Math.min(desiredMaxH, spaceAbove));
        portal.style.transformOrigin = "bottom";

        // ✅ NÃO setar display inline (isso quebrava o close)
        portal.style.left = `${left}px`;
        portal.style.width = `${width}px`;

        const headerH = searchWrap.getBoundingClientRect().height || 52;
        list.style.maxHeight = `${Math.max(120, maxH - headerH)}px`;

        // mede altura total do portal e reposiciona acima
        const portalH = portal.getBoundingClientRect().height || (headerH + maxH);
        const newTop = Math.max(8, rect.top - 8 - portalH);
        portal.style.top = `${newTop}px`;
        portal.style.transform = "translateZ(0)";
        return;
      }

      // abre pra baixo
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

      display.setAttribute("aria-expanded", "true");
      buildList(currentValue());

      // limpa filtro
      searchInput.value = "";
      // mostra tudo
      Array.from(list.children).forEach(el => el.classList.remove("is-hidden"));

      portal.classList.add("is-open");
      positionPortal();

      // foco no filtro
      setTimeout(() => {
        try { searchInput.focus(); } catch (e) {}
      }, 0);
    }

    function closeMenu(keepFocus = false) {
      if (!open) return;
      open = false;

      display.setAttribute("aria-expanded", "false");
      portal.classList.remove("is-open");

      // ✅ garante fechar mesmo se algum inline style tentou "forçar" visibilidade
      portal.style.display = "none";

      // limpa posicionamento para reabrir limpo
      portal.style.left = "";
      portal.style.top = "";
      portal.style.width = "";
      portal.style.transformOrigin = "";
      portal.style.transform = "";

      if (keepFocus) {
        try { display.focus(); } catch (e) {}
      }
    }

    function toggleMenu() {
      if (open) closeMenu(true);
      else openMenu();
    }

    // filtro ao vivo
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

    // enter no filtro seleciona o primeiro visível
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
      if (ev.key === "ArrowDown") {
        ev.preventDefault();
        const first = list.querySelector(".bolao-select-opt:not(.is-hidden)");
        if (first) first.focus?.();
      }
    });

    // abre com clique no display
    display.addEventListener("click", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      toggleMenu();
    });

    // teclado no display
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

    // clique fora fecha
    document.addEventListener("click", (ev) => {
      if (!open) return;
      if (wrap.contains(ev.target)) return;
      if (portal.contains(ev.target)) return;
      closeMenu(false);
    });

    // ✅ pointerdown fora (fecha antes de “roubar” o clique)
    document.addEventListener("pointerdown", (ev) => {
      if (!open) return;
      if (wrap.contains(ev.target)) return;
      if (portal.contains(ev.target)) return;
      closeMenu(false);
    }, { capture: true });

    // resize/scroll reposiciona
    window.addEventListener("resize", () => { if (open) positionPortal(); });
    window.addEventListener("scroll", () => { if (open) positionPortal(); }, true);
    window.addEventListener("orientationchange", () => { if (open) closeMenu(false); });

    // se o select mudar, reflete e fecha (segurança)
    sel.addEventListener("change", () => {
      setDisplayText();
      syncGroupState(sel);
      if (open) closeMenu(true);
    });

    // estado inicial
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

    // tira ?sucesso=1 da URL pra não repetir no F5
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
    const res = await fetch("/bolao-da-copa/public/app.php?action=save", {
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
    } finally {
      if (btnSalvarTudo) btnSalvarTudo.disabled = false;
    }
  }

  if (btnSalvarTudo) {
    btnSalvarTudo.addEventListener("click", saveAll);
  }

  document.addEventListener("keydown", (ev) => {
    if ((ev.ctrlKey || ev.metaKey) && ev.key === "Enter") {
      ev.preventDefault();
      saveAll();
    }
  });

  // =========================================================
  // Botões de navegação de grupos (Próximo / Anterior)  ✅
  // =========================================================
  const nextButtons = Array.from(document.querySelectorAll(".btn-next-group[data-next-grupo]"));
  if (nextButtons.length) {
    nextButtons.forEach(btnNext => {
      btnNext.addEventListener("click", () => {
        const nextGrupo = (btnNext.getAttribute("data-next-grupo") || "").trim();
        if (!nextGrupo) return;
        showGroup(nextGrupo);
      });
    });
  }

  const prevButtons = Array.from(document.querySelectorAll(".btn-prev-group[data-prev-grupo]"));
  if (prevButtons.length) {
    prevButtons.forEach(btnPrev => {
      btnPrev.addEventListener("click", () => {
        const prevGrupo = (btnPrev.getAttribute("data-prev-grupo") || "").trim();
        if (!prevGrupo) return;
        showGroup(prevGrupo);
      });
    });
  }

  // =========================================================
  // "Recibo" - abre PDF em nova aba e NÃO muda a aba atual
  // =========================================================
  const btnRecibo = document.getElementById("btnRecibo");
  if (!btnRecibo) return;

  try { btnRecibo.setAttribute("type", "button"); } catch (e) {}

  const parentForm = btnRecibo.closest("form");
  if (parentForm && !parentForm.__BOLAO_RECIBO_FORM_GUARD__) {
    parentForm.__BOLAO_RECIBO_FORM_GUARD__ = true;

    parentForm.addEventListener("submit", (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      if (typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation();
      return false;
    }, { capture: true });

    parentForm.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") {
        ev.preventDefault();
        ev.stopPropagation();
        if (typeof ev.stopImmediatePropagation === "function") ev.stopImmediatePropagation();
        return false;
      }
    }, { capture: true });
  }

  let reciboLock = false;

  function openReceiptPdf() {
    if (reciboLock) return;
    reciboLock = true;

    const url = "/bolao-da-copa/php/recibo.php?action=pdf";
    const winName = "BOLAO_RECIBO";

    try {
      if (window.__BOLAO_RECIBO_WIN__ && !window.__BOLAO_RECIBO_WIN__.closed) {
        window.__BOLAO_RECIBO_WIN__.focus();
        window.__BOLAO_RECIBO_WIN__.location.href = url;
      } else {
        window.__BOLAO_RECIBO_WIN__ = window.open(url, winName, "noopener,noreferrer");
        if (!window.__BOLAO_RECIBO_WIN__) {
          window.location.href = url;
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

    try { openReceiptPdf(); }
    catch (e) {
      try { console.error(e); } catch (_) {}
    }
  }, { capture: true, passive: false });
});
