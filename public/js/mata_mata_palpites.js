document.addEventListener("DOMContentLoaded", () => {
  "use strict";

  if (window.__BOLAO_MM_PALPITES_INIT__ === true) return;
  window.__BOLAO_MM_PALPITES_INIT__ = true;

  const cfgEl = document.getElementById("mm-palpites-config");
  let CFG = {};
  try { CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {}; } catch (e) { CFG = {}; }

  const toastEl = document.getElementById("toast");

  function toast(msg, ok = true) {
    if (!toastEl) return;
    toastEl.textContent = String(msg || "");
    toastEl.classList.remove("is-ok", "is-bad", "is-show");
    toastEl.classList.add(ok ? "is-ok" : "is-bad", "is-show");
    clearTimeout(toastEl.__t);
    toastEl.__t = setTimeout(() => toastEl.classList.remove("is-show"), 2600);
  }

  // -------------------------
  // Fallback de endpoints (HostGator raiz)
  // -------------------------
  if (!CFG || typeof CFG !== "object") CFG = {};
  if (!CFG.endpoints || typeof CFG.endpoints !== "object") CFG.endpoints = {};
  if (!CFG.endpoints.save_games) CFG.endpoints.save_games = "/mata_mata_palpites.php?action=save";
  if (!CFG.endpoints.save_top4) CFG.endpoints.save_top4 = "/mata_mata_palpites.php?action=save_top4";
  if (!CFG.endpoints.receipt_url) CFG.endpoints.receipt_url = "/php/recibo_mata_mata.php?action=pdf";

  // -------------------------
  // Menu fases (filtra blocks)
  // -------------------------
  const menu = document.getElementById("menuFases");
  const blocks = Array.from(document.querySelectorAll("[data-fase-block]"));

  function setActivePhase(faseCode) {
    const fc = String(faseCode || "");

    blocks.forEach((b) => {
      const id = b.getAttribute("data-fase-block");
      if (id === fc) b.classList.add("is-active-group");
      else b.classList.remove("is-active-group");
    });

    if (menu) {
      const links = Array.from(menu.querySelectorAll(".menu-link"));
      links.forEach((a) => {
        const id = a.getAttribute("data-fase");
        if (id === fc) a.classList.add("is-active");
        else a.classList.remove("is-active");
      });
    }

    // scroll visual para o topo do conteúdo (sem “teleporte” seco)
    const head = document.getElementById("mmContentHead");
    if (head) head.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function firstEnabledPhase() {
    if (!menu) return null;
    const links = Array.from(menu.querySelectorAll(".menu-link")).filter(a => !a.classList.contains("is-disabled"));
    if (links.length <= 0) return null;
    return links[0].getAttribute("data-fase");
  }

  if (menu) {
    menu.addEventListener("click", (ev) => {
      const a = ev.target.closest(".menu-link");
      if (!a) return;
      ev.preventDefault();
      if (a.classList.contains("is-disabled")) return;
      const fase = a.getAttribute("data-fase");
      if (!fase) return;
      setActivePhase(fase);
    });
  }

  const initial = firstEnabledPhase();
  if (initial) setActivePhase(initial);

  // -------------------------
  // Helpers: ler card -> item
  // -------------------------
  function getCardData(card) {
    const jogoId = parseInt(card.getAttribute("data-jogo-id") || "0", 10);
    const locked = card.getAttribute("data-locked") === "1";
    const inHome = card.querySelector(".score-home");
    const inAway = card.querySelector(".score-away");

    const gc = inHome ? inHome.value : "";
    const gf = inAway ? inAway.value : "";

    const gcVal = (gc === "" ? null : parseInt(gc, 10));
    const gfVal = (gf === "" ? null : parseInt(gf, 10));

    return { jogoId, locked, gcVal, gfVal };
  }

  function setCardState(card, msg, ok) {
    const st = card.querySelector(".save-state");
    if (!st) return;
    st.textContent = String(msg || "");
    st.classList.remove("is-ok", "is-bad");
    st.classList.add(ok ? "is-ok" : "is-bad");
  }

  async function postJSON(url, payload) {
    const resp = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify(payload || {})
    });
    const data = await resp.json().catch(() => ({}));
    return { ok: resp.ok, status: resp.status, data };
  }

  // -------------------------
  // Salvar jogos (um / tudo)
  // -------------------------
  async function saveCards(cards) {
    const url = (CFG.endpoints && CFG.endpoints.save_games) ? CFG.endpoints.save_games : "";
    if (!url) { toast("Endpoint de salvar não configurado.", false); return; }

    const items = [];

    cards.forEach((card) => {
      const d = getCardData(card);
      if (d.locked) return;
      if (d.jogoId <= 0) return;
      if (d.gcVal === null || d.gfVal === null) return;
      if (Number.isNaN(d.gcVal) || Number.isNaN(d.gfVal)) return;
      if (d.gcVal < 0 || d.gcVal > 99 || d.gfVal < 0 || d.gfVal > 99) return;
      items.push({ jogo_id: d.jogoId, gols_casa: d.gcVal, gols_fora: d.gfVal });
    });

    if (items.length <= 0) {
      toast("Preencha os placares antes de salvar.", false);
      return;
    }

    const { ok, data } = await postJSON(url, { items });

    if (!ok || !data || data.ok !== true) {
      toast((data && data.message) ? data.message : "Falha ao salvar.", false);
      // marca cards como erro se vier bloqueado
      if (data && Array.isArray(data.blocked)) {
        data.blocked.forEach((b) => {
          const jid = parseInt(b.jogo_id || "0", 10);
          const card = document.querySelector(`.match-card[data-jogo-id="${jid}"]`);
          if (card) setCardState(card, b.reason || "Bloqueado.", false);
        });
      }
      return;
    }

    toast(data.message || "Salvo.");
    // marca OK nos cards enviados
    items.forEach((it) => {
      const card = document.querySelector(`.match-card[data-jogo-id="${it.jogo_id}"]`);
      if (card) setCardState(card, "Salvo ✓", true);
    });
  }

  // salvar 1 jogo
  document.addEventListener("click", (ev) => {
    const btn = ev.target.closest(".btn-save-one");
    if (!btn) return;
    const card = btn.closest(".match-card");
    if (!card) return;
    ev.preventDefault();
    saveCards([card]);
  });

  // salvar tudo
  const btnAll = document.getElementById("btnSalvarTudo");
  if (btnAll) {
    btnAll.addEventListener("click", (ev) => {
      ev.preventDefault();
      const cards = Array.from(document.querySelectorAll(".match-card"));
      saveCards(cards);
    });
  }

  // Ctrl+Enter salva tudo
  window.addEventListener("keydown", (ev) => {
    if (ev.ctrlKey && (ev.key === "Enter" || ev.keyCode === 13)) {
      const cards = Array.from(document.querySelectorAll(".match-card"));
      saveCards(cards);
    }
  });

  // -------------------------
  // Recibo (mata-mata)
  // -------------------------
  const btnRecibo = document.getElementById("btnRecibo");
  if (btnRecibo) {
    btnRecibo.addEventListener("click", (ev) => {
      ev.preventDefault();
      const url = (CFG.endpoints && CFG.endpoints.receipt_url) ? CFG.endpoints.receipt_url : "";
      if (!url) { toast("Recibo não configurado.", false); return; }
      window.open(url, "_blank", "noopener,noreferrer");
    });
  }

  // -------------------------
  // Top 4
  // -------------------------
  const btnTop4 = document.getElementById("btnTop4Save");
  const top4State = document.getElementById("top4State");

  function setTop4State(msg, ok) {
    if (!top4State) return;
    top4State.textContent = String(msg || "");
    top4State.classList.remove("is-ok", "is-bad");
    top4State.classList.add(ok ? "is-ok" : "is-bad");
  }

  function readTop4() {
    const sel = Array.from(document.querySelectorAll("[data-top4-pos]"));
    const picks = {};
    sel.forEach((s) => {
      const pos = s.getAttribute("data-top4-pos");
      const val = parseInt(s.value || "0", 10);
      picks[pos] = val;
    });
    return picks;
  }

  if (btnTop4) {
    btnTop4.addEventListener("click", async (ev) => {
      ev.preventDefault();

      if (!CFG.knockout || CFG.knockout.top4_enabled !== true) {
        setTop4State("Top 4 ainda bloqueado (semifinal não cadastrada).", false);
        return;
      }

      const picks = readTop4();
      const t1 = picks["1"] || 0, t2 = picks["2"] || 0, t3 = picks["3"] || 0, t4 = picks["4"] || 0;

      if (t1 <= 0 || t2 <= 0 || t3 <= 0 || t4 <= 0) {
        setTop4State("Selecione 1º, 2º, 3º e 4º antes de salvar.", false);
        return;
      }
      const arr = [t1, t2, t3, t4];
      const uniq = new Set(arr);
      if (uniq.size !== arr.length) {
        setTop4State("Não pode repetir o mesmo time no Top 4.", false);
        return;
      }

      const url = (CFG.endpoints && CFG.endpoints.save_top4) ? CFG.endpoints.save_top4 : "";
      if (!url) { setTop4State("Endpoint Top4 não configurado.", false); return; }

      const { ok, data } = await postJSON(url, { picks });

      if (!ok || !data || data.ok !== true) {
        setTop4State((data && data.message) ? data.message : "Falha ao salvar Top 4.", false);
        return;
      }

      setTop4State(data.message || "Top 4 salvo.", true);
      toast(data.message || "Top 4 salvo.");
    });
  }
});