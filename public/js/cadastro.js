document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_CADASTRO_INIT__ === true) return;
  window.__BOLAO_CADASTRO_INIT__ = true;

  // Floating labels
  if (window.BOLAO && typeof window.BOLAO.initFloatingLabels === "function") {
    window.BOLAO.initFloatingLabels();
  }

  // =========================================================
  // CUSTOM SELECT (UF)
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
      display.textContent = opt ? opt.value : "\u00A0";

      wrap.appendChild(caret);

      // mantém floating label coerente
      if (window.BOLAO && typeof window.BOLAO.initFloatingLabels === "function") {
        // nada; labels já são por listeners. aqui só força classe se existir
      }
      const inputGroup = sel.closest(".input-group");
      if (inputGroup) {
        if (val) inputGroup.classList.add("has-value");
        else inputGroup.classList.remove("has-value");
      }
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

    function closeMenu(keepFocus) {
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
  })();

  // =========================================================
  // MODAL SUCESSO CADASTRO
  // =========================================================
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
});
