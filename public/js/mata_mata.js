/* =========================================================
   mata_mata.js — COMPLETO (MATA_MATA.PHP)
   - Fases em menu (16 / oitavas / quartas / semi / 3º / final)
   - Lista jogos por fase
   - Modal: criar/editar/excluir (excluir só se não houver palpites)
   - Times via datalist (pesquisa por texto) + validação/normalização
   - Status NÃO existe (sempre AGENDADO no back)
   ========================================================= */

document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_MATA_MATA_INIT__ === true) return;
  window.__BOLAO_MATA_MATA_INIT__ = true;

  const cfgEl = document.getElementById("mm-config");
  let CFG = {};
  try {
    CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
  } catch (e) {
    CFG = {};
  }

  const ENDPOINTS = (CFG && CFG.endpoints) ? CFG.endpoints : {};
  let CSRF = (CFG && CFG.csrf_token) ? CFG.csrf_token : "";

  // =========================================================
  // FALLBACKS (HostGator/public_html - raiz)
  // =========================================================
  if (!ENDPOINTS.bootstrap) ENDPOINTS.bootstrap = "/mata_mata.php?action=bootstrap";
  if (!ENDPOINTS.list_games) ENDPOINTS.list_games = "/mata_mata.php?action=list_games";
  if (!ENDPOINTS.create) ENDPOINTS.create = "/mata_mata.php?action=create";
  if (!ENDPOINTS.update) ENDPOINTS.update = "/mata_mata.php?action=update";
  if (!ENDPOINTS.delete) ENDPOINTS.delete = "/mata_mata.php?action=delete";

  const $ = (sel) => document.querySelector(sel);

  const elEdicao = $("#edicao");
  const phaseBar = $("#phaseBar");

  const elList = $("#listArea");
  const elPillCount = $("#pillCount");
  const elPillPhase = $("#pillPhase");

  const btnReload = $("#btnReload");
  const btnNew = $("#btnNew");

  const modalBackdrop = $("#modalBackdrop");
  const modalTitle = $("#modalTitle");
  const modalPhaseLine = $("#modalPhaseLine");
  const btnClose = $("#btnClose");
  const btnSave = $("#btnSave");
  const btnDelete = $("#btnDelete");
  const modalInfo = $("#modalInfo");

  const mData = $("#m_data");
  const mCodigo = $("#m_codigo");

  const mCasaLabel = $("#m_casa_label");
  const mCasaId = $("#m_casa_id");
  const hintCasa = $("#hintCasa");

  const mForaLabel = $("#m_fora_label");
  const mForaId = $("#m_fora_id");
  const hintFora = $("#hintFora");

  const mZebra = $("#m_zebra");

  const timesList = $("#timesList");
  const toast = $("#toast");

  let TIMES = [];
  let TIMES_BY_ID = new Map();
  let TIMES_BY_LABEL = new Map(); // label exata -> id
  let TIMES_BY_SIGLA = new Map(); // "BRA" -> id
  let TIMES_BY_NAME = new Map();  // "BRASIL" -> id (uppercase)

  let EDICOES = [];
  let PHASES = [];
  let currentPhase = null;      // code
  let currentPhaseLabel = "—";
  let currentGames = [];

  let editingId = null;
  let editingHasPalpites = false;

  /* =========================
     Helpers
     ========================= */

  function escHtml(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function showToast(msg, ms = 2400) {
    if (!toast) return;
    toast.textContent = String(msg || "");
    toast.classList.add("is-open");
    window.clearTimeout(showToast._t);
    showToast._t = window.setTimeout(() => {
      toast.classList.remove("is-open");
    }, ms);
  }

  function qsEncode(obj) {
    const p = new URLSearchParams();
    Object.keys(obj || {}).forEach((k) => {
      const v = obj[k];
      if (v === undefined || v === null) return;
      p.set(k, String(v));
    });
    return p.toString();
  }

  async function httpGetJson(url) {
    const r = await fetch(url, { credentials: "same-origin" });
    const j = await r.json().catch(() => null);
    if (!r.ok) {
      const msg = (j && j.error) ? j.error : ("HTTP " + r.status);
      throw new Error(msg);
    }
    if (!j || j.ok !== true) throw new Error((j && j.error) ? j.error : "Resposta inválida");
    return j;
  }

  async function httpPostForm(url, dataObj) {
    const fd = new FormData();
    Object.keys(dataObj || {}).forEach((k) => {
      const v = dataObj[k];
      if (v === undefined || v === null) return;
      fd.append(k, String(v));
    });

    const r = await fetch(url, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
    });

    const j = await r.json().catch(() => null);
    if (!r.ok) {
      const msg = (j && j.error) ? j.error : ("HTTP " + r.status);
      throw new Error(msg);
    }
    if (!j || j.ok !== true) throw new Error((j && j.error) ? j.error : "Resposta inválida");
    return j;
  }

  function mysqlToDatetimeLocal(mysqlDt) {
    if (!mysqlDt) return "";
    const s = String(mysqlDt);
    const m = s.match(/^(\d{4}-\d{2}-\d{2})\s(\d{2}):(\d{2})/);
    if (!m) return "";
    return `${m[1]}T${m[2]}:${m[3]}`;
  }

  function normalizeStr(s) {
    return String(s ?? "").trim();
  }

  function normalizeUpper(s) {
    return normalizeStr(s).toUpperCase();
  }

  function clearInvalid(el) {
    if (!el) return;
    el.classList.remove("is-invalid");
  }

  function setInvalid(el) {
    if (!el) return;
    el.classList.add("is-invalid");
  }

  function setPhase(code) {
    const found = PHASES.find((p) => p.code === code);
    if (!found) return;
    currentPhase = found.code;
    currentPhaseLabel = found.label;

    // UI phase buttons
    const btns = phaseBar ? phaseBar.querySelectorAll(".mm-phasebtn") : [];
    btns.forEach((b) => {
      const c = b.getAttribute("data-phase");
      if (c === currentPhase) b.classList.add("is-active");
      else b.classList.remove("is-active");
    });

    if (elPillPhase) elPillPhase.textContent = "Fase: " + currentPhaseLabel;
  }

  function selectedEdicaoId() {
    const v = elEdicao ? elEdicao.value : "";
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : null;
  }

  function buildTimesIndex() {
    TIMES_BY_ID = new Map();
    TIMES_BY_LABEL = new Map();
    TIMES_BY_SIGLA = new Map();
    TIMES_BY_NAME = new Map();

    for (const t of TIMES) {
      TIMES_BY_ID.set(String(t.id), t);
      TIMES_BY_LABEL.set(String(t.label), String(t.id));
      TIMES_BY_SIGLA.set(String(t.sigla).toUpperCase(), String(t.id));
      TIMES_BY_NAME.set(String(t.nome).toUpperCase(), String(t.id));
    }
  }

  function fillTimesDatalist() {
    if (!timesList) return;
    const frag = document.createDocumentFragment();

    // limpa
    timesList.innerHTML = "";

    // options
    for (const t of TIMES) {
      const opt = document.createElement("option");
      opt.value = t.label; // mostra "Nome (SIG)"
      frag.appendChild(opt);
    }
    timesList.appendChild(frag);
  }

  function parseTimeInputToId(raw) {
    const s = normalizeStr(raw);
    if (!s) return null;

    // 1) label exata "Brasil (BRA)"
    const byLabel = TIMES_BY_LABEL.get(s);
    if (byLabel) return byLabel;

    // 2) tenta extrair (SIG)
    const m = s.match(/\(([A-Za-z]{3})\)\s*$/);
    if (m) {
      const sig = m[1].toUpperCase();
      const bySig = TIMES_BY_SIGLA.get(sig);
      if (bySig) return bySig;
    }

    // 3) nome exato (case insensitive)
    const byName = TIMES_BY_NAME.get(s.toUpperCase());
    if (byName) return byName;

    // 4) fallback: se usuário digitou "BRA"
    if (/^[A-Za-z]{3}$/.test(s)) {
      const bySig2 = TIMES_BY_SIGLA.get(s.toUpperCase());
      if (bySig2) return bySig2;
    }

    return null;
  }

  function timeLabelById(id) {
    const t = TIMES_BY_ID.get(String(id));
    return t ? t.label : "";
  }

  function openModal(mode, game) {
    editingId = null;
    editingHasPalpites = false;

    clearInvalid(mData);
    clearInvalid(mCodigo);
    clearInvalid(mCasaLabel);
    clearInvalid(mForaLabel);
    clearInvalid(mZebra);

    hintCasa.textContent = "";
    hintFora.textContent = "";
    modalInfo.textContent = "";

    btnDelete.style.display = "none";

    // fase fixa: vem do menu atual
    modalPhaseLine.innerHTML = `Fase selecionada: <b>${escHtml(currentPhaseLabel)}</b>`;

    if (mode === "new") {
      modalTitle.textContent = "Novo jogo";
      mData.value = "";
      mCodigo.value = "";
      mCasaLabel.value = "";
      mForaLabel.value = "";
      mCasaId.value = "";
      mForaId.value = "";
      mZebra.value = "NONE";
      btnSave.textContent = "Salvar";
      editingId = null;
      editingHasPalpites = false;
      modalInfo.textContent = "Preencha data/hora e selecione os dois times.";
    } else {
      // edit
      modalTitle.textContent = "Editar jogo";
      btnSave.textContent = "Salvar";
      editingId = String(game.id);
      editingHasPalpites = !!game.has_palpites;

      mData.value = mysqlToDatetimeLocal(game.data_hora);
      mCodigo.value = game.codigo_fifa ? String(game.codigo_fifa) : "";

      mCasaId.value = String(game.time_casa_id);
      mForaId.value = String(game.time_fora_id);

      mCasaLabel.value = timeLabelById(game.time_casa_id) || `${game.time_casa_nome} (${game.time_casa_sigla})`;
      mForaLabel.value = timeLabelById(game.time_fora_id) || `${game.time_fora_nome} (${game.time_fora_sigla})`;

      mZebra.value = (game.zebra === "CASA" || game.zebra === "FORA") ? game.zebra : "NONE";

      if (editingHasPalpites) {
        hintCasa.textContent = "Bloqueado: já existem palpites (não pode trocar).";
        hintFora.textContent = "Bloqueado: já existem palpites (não pode trocar).";
        mCasaLabel.disabled = true;
        mForaLabel.disabled = true;
      } else {
        mCasaLabel.disabled = false;
        mForaLabel.disabled = false;
      }

      btnDelete.style.display = editingHasPalpites ? "none" : "inline-flex";
      modalInfo.textContent = editingHasPalpites
        ? `Este jogo possui palpites. Você pode ajustar data/hora, código FIFA e zebra.`
        : `Sem palpites: pode ajustar tudo, inclusive times.`;
    }

    modalBackdrop.style.display = "flex";
    modalBackdrop.setAttribute("aria-hidden", "false");

    // foco inicial
    window.setTimeout(() => {
      try {
        mData.focus();
      } catch (_) {}
    }, 50);
  }

  function closeModal() {
    modalBackdrop.style.display = "none";
    modalBackdrop.setAttribute("aria-hidden", "true");
    editingId = null;
    editingHasPalpites = false;

    // reabilita sempre
    mCasaLabel.disabled = false;
    mForaLabel.disabled = false;
  }

  function renderList() {
    if (!elList) return;

    if (elPillCount) elPillCount.textContent = `${currentGames.length} jogo(s)`;

    if (currentGames.length === 0) {
      elList.innerHTML = `<div class="mm-empty">Nenhum jogo cadastrado nesta fase.</div>`;
      return;
    }

    const rows = currentGames.map((g) => {
      const when = mysqlToDatetimeLocal(g.data_hora).replace("T", " ");
      const fifa = g.codigo_fifa ? escHtml(g.codigo_fifa) : `<span style="opacity:.65;">—</span>`;
      const zebra = (g.zebra === "CASA") ? "Casa" : (g.zebra === "FORA") ? "Fora" : "Nenhuma";
      const lock = g.has_palpites ? `<span class="mm-badge-lock">🔒 ${g.total_palpites} palpite(s)</span>` : "";

      const casa = `${escHtml(g.time_casa_nome)} (${escHtml(g.time_casa_sigla)})`;
      const fora = `${escHtml(g.time_fora_nome)} (${escHtml(g.time_fora_sigla)})`;

      return `
        <tr>
          <td>${escHtml(when)}</td>
          <td>${casa}</td>
          <td>${fora}</td>
          <td>${fifa}</td>
          <td>${escHtml(zebra)}</td>
          <td style="text-align:right;">${lock}</td>
          <td style="text-align:right;">
            <div class="mm-row-actions">
              <button class="mm-btn" type="button" data-edit="${escHtml(String(g.id))}">Editar</button>
            </div>
          </td>
        </tr>
      `;
    }).join("");

    elList.innerHTML = `
      <div class="mm-table-wrap">
        <table class="mm-table">
          <thead>
            <tr>
              <th style="min-width:140px;">Data/Hora</th>
              <th>Casa</th>
              <th>Fora</th>
              <th style="min-width:120px;">Código FIFA</th>
              <th style="min-width:120px;">Zebra</th>
              <th style="min-width:160px;text-align:right;">Palpites</th>
              <th style="min-width:130px;text-align:right;">Ações</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;

    // bind editar
    const btns = elList.querySelectorAll("[data-edit]");
    btns.forEach((b) => {
      b.addEventListener("click", () => {
        const id = String(b.getAttribute("data-edit") || "");
        const g = currentGames.find((x) => String(x.id) === id);
        if (g) openModal("edit", g);
      });
    });
  }

  async function loadGames() {
    const edicaoId = selectedEdicaoId();
    if (!edicaoId) {
      currentGames = [];
      renderList();
      return;
    }
    if (!currentPhase) return;

    try {
      elList.innerHTML = `<div class="mm-empty">Carregando...</div>`;

      const url = ENDPOINTS.list_games + "&" + qsEncode({ edicao_id: edicaoId, fase: currentPhase });
      const j = await httpGetJson(url);

      currentGames = Array.isArray(j.games) ? j.games : [];
      renderList();
    } catch (e) {
      currentGames = [];
      renderList();
      showToast(e.message || "Erro ao carregar jogos.");
    }
  }

  function renderPhases() {
    if (!phaseBar) return;
    phaseBar.innerHTML = "";

    const frag = document.createDocumentFragment();
    for (const p of PHASES) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "mm-phasebtn";
      btn.setAttribute("data-phase", p.code);
      btn.textContent = p.label;
      btn.addEventListener("click", () => {
        setPhase(p.code);
        loadGames();
      });
      frag.appendChild(btn);
    }
    phaseBar.appendChild(frag);

    // ativa o current
    setPhase(currentPhase || (PHASES[0] ? PHASES[0].code : null));
  }

  function renderEdicoes(defaultId) {
    if (!elEdicao) return;

    elEdicao.innerHTML = "";
    const frag = document.createDocumentFragment();

    for (const e of EDICOES) {
      const opt = document.createElement("option");
      opt.value = String(e.id);
      opt.textContent = `${e.nome} (${e.ano})${e.ativo ? "" : " — inativa"}`;
      frag.appendChild(opt);
    }
    elEdicao.appendChild(frag);

    if (defaultId) elEdicao.value = String(defaultId);
    else if (EDICOES[0]) elEdicao.value = String(EDICOES[0].id);
  }

  function validateModal() {
    let ok = true;

    clearInvalid(mData);
    clearInvalid(mCasaLabel);
    clearInvalid(mForaLabel);

    const dt = normalizeStr(mData.value);
    if (!dt) {
      setInvalid(mData);
      ok = false;
    }

    // times
    if (!editingHasPalpites) {
      const casaId = parseTimeInputToId(mCasaLabel.value);
      const foraId = parseTimeInputToId(mForaLabel.value);

      if (!casaId) {
        setInvalid(mCasaLabel);
        hintCasa.textContent = "Selecione um time válido (use a lista).";
        ok = false;
      } else {
        hintCasa.textContent = "";
        mCasaId.value = String(casaId);
      }

      if (!foraId) {
        setInvalid(mForaLabel);
        hintFora.textContent = "Selecione um time válido (use a lista).";
        ok = false;
      } else {
        hintFora.textContent = "";
        mForaId.value = String(foraId);
      }

      if (casaId && foraId && String(casaId) === String(foraId)) {
        setInvalid(mCasaLabel);
        setInvalid(mForaLabel);
        hintFora.textContent = "Casa e fora não podem ser o mesmo time.";
        ok = false;
      }
    } else {
      // bloqueado: confia no hidden id vindo do jogo
      if (!normalizeStr(mCasaId.value) || !normalizeStr(mForaId.value)) {
        showToast("Erro: IDs dos times ausentes.");
        ok = false;
      }
    }

    // zebra: se casa/fora inválidos, deixa
    const zebra = normalizeStr(mZebra.value);
    if (!["NONE", "CASA", "FORA"].includes(zebra)) {
      setInvalid(mZebra);
      ok = false;
    }

    return ok;
  }

  async function saveModal() {
    const edicaoId = selectedEdicaoId();
    if (!edicaoId) {
      showToast("Selecione uma edição.");
      return;
    }
    if (!currentPhase) {
      showToast("Selecione uma fase.");
      return;
    }

    if (!validateModal()) {
      showToast("Corrija os campos marcados.");
      return;
    }

    const data = {
      csrf_token: CSRF,
      edicao_id: edicaoId,
      fase: currentPhase,
      data_hora: normalizeStr(mData.value),
      codigo_fifa: normalizeStr(mCodigo.value),
      zebra: normalizeStr(mZebra.value) || "NONE"
    };

    if (editingId) {
      data.id = editingId;

      // update: se não tem palpites, manda times
      if (!editingHasPalpites) {
        data.time_casa_id = normalizeStr(mCasaId.value);
        data.time_fora_id = normalizeStr(mForaId.value);
      }
    } else {
      // create: sempre manda times
      data.time_casa_id = normalizeStr(mCasaId.value);
      data.time_fora_id = normalizeStr(mForaId.value);
    }

    try {
      btnSave.disabled = true;
      btnSave.textContent = "Salvando...";

      if (editingId) {
        await httpPostForm(ENDPOINTS.update, data);
        showToast("Jogo atualizado.");
      } else {
        await httpPostForm(ENDPOINTS.create, data);
        showToast("Jogo criado.");
      }

      closeModal();
      await loadGames();
    } catch (e) {
      showToast(e.message || "Erro ao salvar.");
    } finally {
      btnSave.disabled = false;
      btnSave.textContent = "Salvar";
    }
  }

  async function deleteCurrent() {
    const edicaoId = selectedEdicaoId();
    if (!edicaoId || !editingId) return;

    if (!window.confirm("Excluir este jogo? (somente permitido se não houver palpites)")) return;

    try {
      btnDelete.disabled = true;
      btnDelete.textContent = "Excluindo...";
      await httpPostForm(ENDPOINTS.delete, { csrf_token: CSRF, edicao_id: edicaoId, id: editingId });
      showToast("Jogo excluído.");
      closeModal();
      await loadGames();
    } catch (e) {
      showToast(e.message || "Erro ao excluir.");
    } finally {
      btnDelete.disabled = false;
      btnDelete.textContent = "Excluir";
    }
  }

  function bindDatalistInputs() {
    // ao mudar o texto, tenta resolver id e preencher hidden
    function onCasaChange() {
      if (editingHasPalpites) return;
      clearInvalid(mCasaLabel);
      const id = parseTimeInputToId(mCasaLabel.value);
      if (id) {
        mCasaId.value = String(id);
        hintCasa.textContent = "";
      } else {
        mCasaId.value = "";
      }
    }

    function onForaChange() {
      if (editingHasPalpites) return;
      clearInvalid(mForaLabel);
      const id = parseTimeInputToId(mForaLabel.value);
      if (id) {
        mForaId.value = String(id);
        hintFora.textContent = "";
      } else {
        mForaId.value = "";
      }
    }

    mCasaLabel.addEventListener("input", onCasaChange);
    mCasaLabel.addEventListener("change", onCasaChange);

    mForaLabel.addEventListener("input", onForaChange);
    mForaLabel.addEventListener("change", onForaChange);

    // limpa hidden se apagar
    mCasaLabel.addEventListener("blur", () => {
      if (editingHasPalpites) return;
      if (!normalizeStr(mCasaLabel.value)) {
        mCasaId.value = "";
        hintCasa.textContent = "";
        clearInvalid(mCasaLabel);
      }
    });
    mForaLabel.addEventListener("blur", () => {
      if (editingHasPalpites) return;
      if (!normalizeStr(mForaLabel.value)) {
        mForaId.value = "";
        hintFora.textContent = "";
        clearInvalid(mForaLabel);
      }
    });
  }

  function bindUi() {
    btnReload.addEventListener("click", () => loadGames());

    btnNew.addEventListener("click", () => {
      if (!currentPhase) {
        showToast("Selecione uma fase primeiro.");
        return;
      }
      // sempre reabilita
      mCasaLabel.disabled = false;
      mForaLabel.disabled = false;
      openModal("new", null);
    });

    elEdicao.addEventListener("change", () => loadGames());

    btnClose.addEventListener("click", closeModal);
    modalBackdrop.addEventListener("click", (e) => {
      if (e.target === modalBackdrop) closeModal();
    });

    btnSave.addEventListener("click", saveModal);
    btnDelete.addEventListener("click", deleteCurrent);

    // ESC fecha modal
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && modalBackdrop.style.display === "flex") {
        closeModal();
      }
      if (e.key === "Enter" && (e.ctrlKey || e.metaKey) && modalBackdrop.style.display === "flex") {
        saveModal();
      }
    });

    bindDatalistInputs();
  }

  /* =========================
     Bootstrap (carrega edições/times/fases)
     ========================= */

  async function bootstrap() {
    try {
      elList.innerHTML = `<div class="mm-empty">Carregando...</div>`;

      const j = await httpGetJson(ENDPOINTS.bootstrap);

      if (j.csrf_token) CSRF = String(j.csrf_token);

      EDICOES = Array.isArray(j.edicoes) ? j.edicoes : [];
      TIMES = Array.isArray(j.times) ? j.times : [];
      PHASES = Array.isArray(j.phases) ? j.phases : [];

      buildTimesIndex();
      fillTimesDatalist();

      const defEd = j.edicao_default ? parseInt(j.edicao_default, 10) : null;
      renderEdicoes(defEd);

      // fase default: primeira da lista
      currentPhase = (PHASES[0] && PHASES[0].code) ? PHASES[0].code : null;
      currentPhaseLabel = (PHASES[0] && PHASES[0].label) ? PHASES[0].label : "—";
      renderPhases();

      await loadGames();
    } catch (e) {
      elList.innerHTML = `<div class="mm-empty">Erro ao carregar: ${escHtml(e.message || "falha")}</div>`;
      showToast(e.message || "Erro ao iniciar.");
    }
  }

  bindUi();
  bootstrap();
});