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

  function syncGroupState(input) {
    const group = input.closest(".input-group");
    if (!group) return;
    if (input.value && input.value.trim().length > 0) group.classList.add("has-value");
    else group.classList.remove("has-value");
  }

  groups.forEach(group => {
    const input = group.querySelector("input");
    if (!input) return;

    syncGroupState(input);
    input.addEventListener("input", () => syncGroupState(input));
    input.addEventListener("blur", () => syncGroupState(input));
  });

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

  // Garante que nunca seja submit (mesmo se HTML estiver errado)
  try { btnRecibo.setAttribute("type", "button"); } catch (e) {}

  // Se estiver dentro de um form, bloqueia submit/Enter
  const parentForm = btnRecibo.closest("form");
  if (parentForm && !parentForm.__BOLAO_RECIBO_FORM_GUARD__) {
    parentForm.__BOLAO_RECIBO_FORM_GUARD__ = true;

    parentForm.addEventListener("submit", (ev) => {
      // se o submit foi disparado por Enter ou clique, bloqueia
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
          // fallback: se bloqueou pop-up, aí sim navega na mesma aba
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
    catch (e) { showToast("Falha ao gerar recibo em PDF.", true); }
  }, { capture: true, passive: false });
});
