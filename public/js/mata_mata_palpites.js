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

  if (!CFG || typeof CFG !== "object") CFG = {};
  if (!CFG.endpoints || typeof CFG.endpoints !== "object") CFG.endpoints = {};
  if (!CFG.endpoints.save_games)  CFG.endpoints.save_games  = "/mata_mata_palpites.php?action=save";
  if (!CFG.endpoints.save_top4)   CFG.endpoints.save_top4   = "/mata_mata_palpites.php?action=save_top4";
  if (!CFG.endpoints.receipt_url) CFG.endpoints.receipt_url = "/php/recibo_mata_mata.php?action=pdf";

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

  function markInvalid(input, invalid) {
    if (!input) return;
    input.classList.toggle("is-invalid", !!invalid);
  }

  function getCardData(card) {
    const jogoId = parseInt(card.getAttribute("data-jogo-id") || "0", 10);
    const locked = card.getAttribute("data-locked") === "1";

    const inHome = card.querySelector(".score-home");
    const inAway = card.querySelector(".score-away");

    if (inHome) sanitizeScoreInput(inHome);
    if (inAway) sanitizeScoreInput(inAway);

    const gcRaw = inHome ? inHome.value : "";
    const gfRaw = inAway ? inAway.value : "";

    const gcVal = (gcRaw === "" ? null : parseInt(gcRaw, 10));
    const gfVal = (gfRaw === "" ? null : parseInt(gfRaw, 10));

    const homeId = parseInt(card.getAttribute("data-home-id") || "0", 10);
    const awayId = parseInt(card.getAttribute("data-away-id") || "0", 10);
    const passTeamId = parseInt(card.getAttribute("data-pass-team-id") || "0", 10);

    return {
      jogoId,
      locked,
      gcRaw,
      gfRaw,
      gcVal,
      gfVal,
      homeId,
      awayId,
      passTeamId,
      inHome,
      inAway
    };
  }

  function setCardState(card, msg, ok) {
    const st = card.querySelector(".save-state");
    if (!st) return;
    st.textContent = String(msg || "");
    st.classList.remove("is-ok", "is-bad");
    if (msg) st.classList.add(ok ? "is-ok" : "is-bad");
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

  function updatePassUI(card) {
    const d = getCardData(card);
    const btn = card.querySelector(".btn-pass");
    const chooser = card.querySelector(".pass-chooser");
    if (!btn || !chooser) return;

    if (d.locked) {
      btn.style.display = "none";
      chooser.style.display = "none";
      return;
    }

    const filled = (d.gcVal !== null && d.gfVal !== null && !Number.isNaN(d.gcVal) && !Number.isNaN(d.gfVal));
    const empate = (filled && d.gcVal === d.gfVal);

    if (!empate) {
      btn.style.display = "none";
      chooser.style.display = "none";
      card.setAttribute("data-pass-team-id", "0");
      btn.textContent = "Quem passa?";
      return;
    }

    btn.style.display = "";
    if (d.passTeamId > 0) {
      const homeName = card.getAttribute("data-home") || "Casa";
      const awayName = card.getAttribute("data-away") || "Fora";
      const chosen = (d.passTeamId === d.homeId) ? homeName : ((d.passTeamId === d.awayId) ? awayName : "—");
      btn.textContent = "Passa: " + chosen;
    } else {
      btn.textContent = "Quem passa?";
    }
  }

  function validateCard(card, { silent = false } = {}) {
    const d = getCardData(card);

    if (d.locked) return { ok: false, reason: "Jogo bloqueado." };

    const homeBlank = d.gcRaw === "";
    const awayBlank = d.gfRaw === "";

    markInvalid(d.inHome, homeBlank);
    markInvalid(d.inAway, awayBlank);

    if (homeBlank || awayBlank) {
      setCardState(card, "Preencha os dois placares.", false);
      if (!silent) toast("Preencha os dois placares.", false);
      return { ok: false, reason: "Preencha os dois placares." };
    }

    if (Number.isNaN(d.gcVal) || Number.isNaN(d.gfVal) || d.gcVal === null || d.gfVal === null) {
      setCardState(card, "Placares inválidos.", false);
      if (!silent) toast("Placares inválidos.", false);
      return { ok: false, reason: "Placares inválidos." };
    }

    if (d.gcVal < 0 || d.gcVal > 99 || d.gfVal < 0 || d.gfVal > 99) {
      setCardState(card, "Placares devem ficar entre 0 e 99.", false);
      if (!silent) toast("Placares devem ficar entre 0 e 99.", false);
      return { ok: false, reason: "Placares devem ficar entre 0 e 99." };
    }

    const empate = (d.gcVal === d.gfVal);
    if (empate) {
      if (d.passTeamId <= 0) {
        setCardState(card, "Empate: escolha quem passa.", false);
        if (!silent) toast("Empate: escolha quem passa.", false);
        return { ok: false, reason: "Empate: escolha quem passa." };
      }
      if (d.passTeamId !== d.homeId && d.passTeamId !== d.awayId) {
        setCardState(card, "Empate: escolha um dos dois times.", false);
        if (!silent) toast("Empate: escolha um dos dois times.", false);
        return { ok: false, reason: "Empate: escolha um dos dois times." };
      }
    }

    markInvalid(d.inHome, false);
    markInvalid(d.inAway, false);

    return {
      ok: true,
      item: {
        jogo_id: d.jogoId,
        gols_casa: d.gcVal,
        gols_fora: d.gfVal,
        passa_time_id: empate ? d.passTeamId : null
      }
    };
  }

  function validateCardScoresOnly(card, { silent = false } = {}) {
    const d = getCardData(card);

    if (d.locked) return { ok: false, reason: "Jogo bloqueado." };

    const homeBlank = d.gcRaw === "";
    const awayBlank = d.gfRaw === "";

    markInvalid(d.inHome, homeBlank);
    markInvalid(d.inAway, awayBlank);

    if (homeBlank || awayBlank) {
      setCardState(card, "Preencha os dois placares.", false);
      if (!silent) toast("Preencha os dois placares.", false);
      return { ok: false, reason: "Preencha os dois placares." };
    }

    if (Number.isNaN(d.gcVal) || Number.isNaN(d.gfVal) || d.gcVal === null || d.gfVal === null) {
      setCardState(card, "Placares inválidos.", false);
      if (!silent) toast("Placares inválidos.", false);
      return { ok: false, reason: "Placares inválidos." };
    }

    if (d.gcVal < 0 || d.gcVal > 99 || d.gfVal < 0 || d.gfVal > 99) {
      setCardState(card, "Placares devem ficar entre 0 e 99.", false);
      if (!silent) toast("Placares devem ficar entre 0 e 99.", false);
      return { ok: false, reason: "Placares devem ficar entre 0 e 99." };
    }

    markInvalid(d.inHome, false);
    markInvalid(d.inAway, false);

    return { ok: true };
  }

  async function saveSingleCard(card, { silentInvalid = true, silentSuccess = true } = {}) {
    const url = (CFG.endpoints && CFG.endpoints.save_games) ? CFG.endpoints.save_games : "";
    if (!url) {
      if (!silentInvalid) toast("Endpoint de salvar não configurado.", false);
      return false;
    }

    const validation = validateCard(card, { silent: silentInvalid });
    if (!validation.ok) return false;

    setCardState(card, "Salvando...", true);

    const { ok, data } = await postJSON(url, { items: [validation.item] });

    if (!ok || !data || data.ok !== true) {
      const msg = (data && data.message) ? data.message : "Falha ao salvar.";
      setCardState(card, msg, false);
      if (!silentInvalid) toast(msg, false);
      return false;
    }

    setCardState(card, "Salvo ✓", true);
    if (!silentSuccess) toast(data.message || "Palpite salvo.");
    return true;
  }

  function initPassUI() {
    const cards = Array.from(document.querySelectorAll(".match-card"));
    cards.forEach((card) => {
      updatePassUI(card);

      const inHome = card.querySelector(".score-home");
      const inAway = card.querySelector(".score-away");

      const onChange = () => {
        updatePassUI(card);

        const d = getCardData(card);
        if (d.gcRaw === "" || d.gfRaw === "") {
          setCardState(card, "", true);
          return;
        }

        if (d.gcVal === d.gfVal && d.passTeamId <= 0) {
          setCardState(card, "Empate: escolha quem passa.", false);
          return;
        }

        setCardState(card, "", true);
      };

      if (inHome) inHome.addEventListener("input", onChange);
      if (inAway) inAway.addEventListener("input", onChange);
    });
  }

  initPassUI();

  const saveTimers = new Map();

  function scheduleCardAutoSave(card) {
    const existing = saveTimers.get(card);
    if (existing) clearTimeout(existing);

    const t = setTimeout(() => {
      saveSingleCard(card, { silentInvalid: true, silentSuccess: true });
    }, 450);

    saveTimers.set(card, t);
  }

  function validateCardOnBlur(card) {
    const d = getCardData(card);
    if (d.gcRaw === "" || d.gfRaw === "") {
      validateCardScoresOnly(card, { silent: false });
    }
  }

  const cards = Array.from(document.querySelectorAll(".match-card"));
  cards.forEach((card) => {
    const inHome = card.querySelector(".score-home");
    const inAway = card.querySelector(".score-away");

    if (inHome) {
      inHome.addEventListener("input", () => {
        sanitizeScoreInput(inHome);
        updatePassUI(card);
        scheduleCardAutoSave(card);
      });
      inHome.addEventListener("blur", () => {
        sanitizeScoreInput(inHome);
        updatePassUI(card);
        validateCardOnBlur(card);
      });
    }

    if (inAway) {
      inAway.addEventListener("input", () => {
        sanitizeScoreInput(inAway);
        updatePassUI(card);
        scheduleCardAutoSave(card);
      });
      inAway.addEventListener("blur", () => {
        sanitizeScoreInput(inAway);
        updatePassUI(card);
        validateCardOnBlur(card);
      });
    }
  });

  document.addEventListener("click", (ev) => {
    const btn = ev.target.closest(".btn-pass");
    if (!btn) return;
    const card = btn.closest(".match-card");
    if (!card) return;
    ev.preventDefault();

    const validation = validateCardScoresOnly(card, { silent: false });
    if (!validation.ok) return;

    const d = getCardData(card);
    if (d.gcVal !== d.gfVal) {
      setCardState(card, "O botão só aparece para empate.", false);
      return;
    }

    const chooser = card.querySelector(".pass-chooser");
    if (!chooser) return;

    chooser.style.display = (chooser.style.display === "none" || chooser.style.display === "") ? "flex" : "none";
  });

  document.addEventListener("click", async (ev) => {
    const choice = ev.target.closest(".pass-choice");
    if (!choice) return;
    const card = choice.closest(".match-card");
    if (!card) return;
    ev.preventDefault();

    const d = getCardData(card);
    const kind = choice.getAttribute("data-pass") || "";
    let passTeamId = 0;

    if (d.gcRaw === "" || d.gfRaw === "") {
      validateCardScoresOnly(card, { silent: false });
      return;
    }

    if (kind === "home") passTeamId = d.homeId;
    else if (kind === "away") passTeamId = d.awayId;

    card.setAttribute("data-pass-team-id", String(passTeamId || 0));

    const chooser = card.querySelector(".pass-chooser");
    if (chooser) chooser.style.display = "none";

    updatePassUI(card);
    await saveSingleCard(card, { silentInvalid: false, silentSuccess: false });
  });

  const btnRecibo = document.getElementById("btnRecibo");
  if (btnRecibo) {
    btnRecibo.addEventListener("click", (ev) => {
      ev.preventDefault();
      const url = (CFG.endpoints && CFG.endpoints.receipt_url) ? CFG.endpoints.receipt_url : "";
      if (!url) { toast("Recibo não configurado.", false); return; }
      window.open(url, "_blank", "noopener,noreferrer");
    });
  }

  const top4State = document.getElementById("top4State");

  function setTop4State(msg, ok) {
    if (!top4State) return;
    top4State.textContent = String(msg || "");
    top4State.classList.remove("is-ok", "is-bad");
    if (msg) top4State.classList.add(ok ? "is-ok" : "is-bad");
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

  function validateTop4({ silent = false } = {}) {
    if (!CFG.knockout || CFG.knockout.top4_enabled !== true) {
      setTop4State("Top 4 ainda bloqueado (semifinal não cadastrada).", false);
      if (!silent) toast("Top 4 ainda bloqueado (semifinal não cadastrada).", false);
      return { ok: false };
    }

    const picks = readTop4();
    const t1 = picks["1"] || 0;
    const t2 = picks["2"] || 0;
    const t3 = picks["3"] || 0;
    const t4 = picks["4"] || 0;

    if (t1 <= 0 || t2 <= 0 || t3 <= 0 || t4 <= 0) {
      setTop4State("Selecione 1º, 2º, 3º e 4º antes de salvar.", false);
      if (!silent) toast("Selecione 1º, 2º, 3º e 4º antes de salvar.", false);
      return { ok: false };
    }

    const arr = [t1, t2, t3, t4];
    const uniq = new Set(arr);
    if (uniq.size !== arr.length) {
      setTop4State("Não pode repetir o mesmo time no Top 4.", false);
      if (!silent) toast("Não pode repetir o mesmo time no Top 4.", false);
      return { ok: false };
    }

    return { ok: true, picks };
  }

  let top4Timer = null;

  async function saveTop4({ silentInvalid = true, silentSuccess = true } = {}) {
    const url = (CFG.endpoints && CFG.endpoints.save_top4) ? CFG.endpoints.save_top4 : "";
    if (!url) {
      if (!silentInvalid) toast("Endpoint Top4 não configurado.", false);
      setTop4State("Endpoint Top4 não configurado.", false);
      return false;
    }

    const validation = validateTop4({ silent: silentInvalid });
    if (!validation.ok) return false;

    setTop4State("Salvando...", true);

    const { ok, data } = await postJSON(url, { picks: validation.picks });

    if (!ok || !data || data.ok !== true) {
      const msg = (data && data.message) ? data.message : "Falha ao salvar Top 4.";
      setTop4State(msg, false);
      if (!silentInvalid) toast(msg, false);
      return false;
    }

    setTop4State(data.message || "Top 4 salvo.", true);
    if (!silentSuccess) toast(data.message || "Top 4 salvo.");
    return true;
  }

  const top4Selects = Array.from(document.querySelectorAll("[data-top4-pos]"));
  top4Selects.forEach((sel) => {
    sel.addEventListener("change", () => {
      if (top4Timer) clearTimeout(top4Timer);

      const validation = validateTop4({ silent: true });
      if (!validation.ok) return;

      top4Timer = setTimeout(() => {
        saveTop4({ silentInvalid: true, silentSuccess: true });
      }, 350);
    });

    sel.addEventListener("blur", () => {
      const picks = readTop4();
      const values = [picks["1"] || 0, picks["2"] || 0, picks["3"] || 0, picks["4"] || 0];
      const hasAny = values.some(v => v > 0);
      if (hasAny && values.some(v => v <= 0)) {
        validateTop4({ silent: false });
      }
    });
  });
});