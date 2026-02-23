document.addEventListener("DOMContentLoaded", () => {
  const cfgEl = document.getElementById("campeao-config");
  const grid = document.getElementById("champGrid");
  const btnSave = document.getElementById("btnSaveChamp");
  const hint = document.getElementById("champHint");
  const toast = document.getElementById("toast");

  // ✅ novo: busca
  const searchInput = document.getElementById("champSearch");
  const noResults = document.getElementById("champNoResults");

  if (!cfgEl || !grid || !btnSave || !toast) return;

  let cfg = {};
  try { cfg = JSON.parse(cfgEl.textContent || "{}"); } catch { cfg = {}; }

  const endpointSave = cfg?.endpoints?.save || "/bolao-da-copa/public/campeao.php?action=save";

  // ✅ endpoint do recibo (se não vier no config, usa o padrão)
  const endpointRecibo = cfg?.endpoints?.recibo || "/bolao-da-copa/php/recibo.php";

  let selectedId = Number(cfg?.selected_time_id || 0);
  let pendingId = selectedId;

  // ✅ evita corridas quando clica rápido
  let saving = false;
  let lastRequestedId = 0;

  function showToast(msg, ok = true) {
    toast.textContent = String(msg || "");
    toast.classList.add("is-open");
    toast.classList.remove("ok", "err");
    toast.classList.add(ok ? "ok" : "err");
    window.clearTimeout(showToast.__t);
    showToast.__t = window.setTimeout(() => toast.classList.remove("is-open"), 2200);
  }

  function setSelected(newId) {
    pendingId = Number(newId || 0);

    const tiles = grid.querySelectorAll(".team-tile");
    tiles.forEach(t => {
      const tid = Number(t.getAttribute("data-time-id") || 0);
      const sel = (tid === pendingId);
      t.classList.toggle("is-selected", sel);
      t.setAttribute("aria-pressed", sel ? "true" : "false");
    });

    btnSave.disabled = !(pendingId > 0);

    if (hint) {
      if (!(pendingId > 0)) {
        hint.textContent = "Selecione um time.";
      } else if (pendingId === selectedId && selectedId > 0) {
        hint.textContent = "Seu campeão já está salvo. Clique em “Salvar campeão” para gerar o recibo.";
      } else {
        const tile = grid.querySelector(`.team-tile[data-time-id="${pendingId}"]`);
        const nm = tile ? (tile.getAttribute("data-time-name") || "time") : "time";
        hint.textContent = `Selecionado: ${nm}. Salvando automaticamente...`;
      }
    }
  }

  async function saveChampion(timeId, { generateReceipt = false } = {}) {
    const tid = Number(timeId || 0);
    if (!(tid > 0)) return;

    lastRequestedId = tid;
    if (saving) return;

    saving = true;

    const prevText = btnSave.textContent;
    if (generateReceipt) {
      btnSave.disabled = true;
      btnSave.textContent = "Salvando e gerando recibo...";
    }

    try {
      const resp = await fetch(endpointSave, {
        method: "POST",
        headers: { "Content-Type": "application/json; charset=utf-8" },
        body: JSON.stringify({ time_id: tid })
      });

      const data = await resp.json().catch(() => ({}));
      if (!resp.ok || !data || data.ok !== true) {
        const msg = (data && data.message) ? data.message : "Falha ao salvar.";
        showToast(msg, false);
        return;
      }

      selectedId = Number(data.time_id || tid);
      pendingId = selectedId;

      showToast(data.message || "Campeão salvo.", true);
      setSelected(selectedId);

      if (generateReceipt) {
        window.open(endpointRecibo, "_blank", "noopener,noreferrer");
      }

    } catch (err) {
      showToast("Erro de rede ao salvar.", false);
    } finally {
      saving = false;

      if (lastRequestedId && lastRequestedId !== selectedId) {
        const next = lastRequestedId;
        lastRequestedId = 0;
        await saveChampion(next, { generateReceipt: false });
      } else {
        lastRequestedId = 0;
      }

      if (generateReceipt) {
        btnSave.textContent = prevText || "Salvar campeão";
        btnSave.disabled = !(pendingId > 0);
      }
    }
  }

  // ============================
  // ✅ BUSCA (filtro por nome/sigla)
  // ============================
  function norm(s) {
    return String(s || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim();
  }

  function applyFilter(queryRaw) {
    const q = norm(queryRaw);
    const tiles = grid.querySelectorAll(".team-tile");
    let visible = 0;

    tiles.forEach(t => {
      const name = norm(t.getAttribute("data-time-name"));
      const sigla = norm(t.getAttribute("data-time-sigla"));
      const match = (q === "") || name.includes(q) || sigla.includes(q);

      t.classList.toggle("is-hidden", !match);
      t.setAttribute("aria-hidden", match ? "false" : "true");
      if (match) visible++;
    });

    if (noResults) {
      const show = (tiles.length > 0 && visible === 0);
      noResults.classList.toggle("is-open", show);
    }
  }

  // Debounce simples (sem lixo global)
  function debounce(fn, ms) {
    let t = 0;
    return (...args) => {
      window.clearTimeout(t);
      t = window.setTimeout(() => fn(...args), ms);
    };
  }

  const onSearch = debounce(() => {
    applyFilter(searchInput ? searchInput.value : "");
  }, 120);

  if (searchInput) {
    searchInput.addEventListener("input", onSearch);

    searchInput.addEventListener("keydown", (e) => {
      // ESC limpa
      if (e.key === "Escape") {
        searchInput.value = "";
        applyFilter("");
        searchInput.blur();
      }
    });
  }

  // ============================
  // Clique no tile: seleciona + salva automático se mudou
  // ============================
  grid.addEventListener("click", (e) => {
    const tile = e.target.closest(".team-tile");
    if (!tile || tile.classList.contains("is-hidden")) return;

    const tid = Number(tile.getAttribute("data-time-id") || 0);
    if (!(tid > 0)) return;

    setSelected(tid);

    if (tid !== selectedId) {
      saveChampion(tid, { generateReceipt: false });
    } else {
      if (hint) hint.textContent = "Seu campeão já está salvo. Clique em “Salvar campeão” para gerar o recibo.";
    }
  });

  // Botão: salva (garante) e abre recibo
  btnSave.addEventListener("click", async () => {
    if (!(pendingId > 0)) return;
    await saveChampion(pendingId, { generateReceipt: true });
  });

  // Estado inicial
  setSelected(selectedId);
  applyFilter(searchInput ? searchInput.value : "");
});