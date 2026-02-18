document.addEventListener("DOMContentLoaded", () => {
  const cfgEl = document.getElementById("campeao-config");
  const grid = document.getElementById("champGrid");
  const btnSave = document.getElementById("btnSaveChamp");
  const hint = document.getElementById("champHint");
  const toast = document.getElementById("toast");

  if (!cfgEl || !grid || !btnSave || !toast) return;

  let cfg = {};
  try { cfg = JSON.parse(cfgEl.textContent || "{}"); } catch { cfg = {}; }

  const endpointSave = cfg?.endpoints?.save || "/bolao-da-copa/public/campeao.php?action=save";

  // ✅ novo: endpoint do recibo (se não vier no config, usa o padrão)
  const endpointRecibo = cfg?.endpoints?.recibo || "/bolao-da-copa/php/recibo.php";

  let selectedId = Number(cfg?.selected_time_id || 0);
  let pendingId = selectedId;

  // ✅ novo: evita corridas quando clica rápido em vários times
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

    // ✅ botão "Salvar" continua existindo, mas agora só serve p/ "Salvar + Recibo"
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

  // ✅ novo: função única de salvar (usada no clique do tile e no botão)
  async function saveChampion(timeId, { generateReceipt = false } = {}) {
    const tid = Number(timeId || 0);
    if (!(tid > 0)) return;

    // evita múltiplos fetch simultâneos
    lastRequestedId = tid;
    if (saving) return;

    saving = true;

    // UI: se for botão, mostra "Salvando..."
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

      // aplica o último solicitado (se houve cliques rápidos)
      selectedId = Number(data.time_id || tid);
      pendingId = selectedId;

      showToast(data.message || "Campeão salvo.", true);
      setSelected(selectedId);

      // ✅ novo: ao clicar no botão salvar => abre recibo
      if (generateReceipt) {
        window.open(endpointRecibo, "_blank", "noopener,noreferrer");
      }

    } catch (err) {
      showToast("Erro de rede ao salvar.", false);
    } finally {
      saving = false;

      // se houve outra solicitação enquanto salvava, salva de novo o último
      if (lastRequestedId && lastRequestedId !== selectedId) {
        const next = lastRequestedId;
        lastRequestedId = 0;
        // salva automaticamente o último clicado (sem recibo)
        await saveChampion(next, { generateReceipt: false });
      } else {
        lastRequestedId = 0;
      }

      // UI do botão volta ao normal
      if (generateReceipt) {
        btnSave.textContent = prevText || "Salvar campeão";
        btnSave.disabled = !(pendingId > 0);
      }
    }
  }

  // ✅ alterado: clicar no tile agora salva automaticamente
  grid.addEventListener("click", (e) => {
    const tile = e.target.closest(".team-tile");
    if (!tile) return;
    const tid = Number(tile.getAttribute("data-time-id") || 0);
    if (!(tid > 0)) return;

    setSelected(tid);

    // salva automático (mesmo que seja o mesmo, não precisa bater no banco)
    if (tid !== selectedId) {
      saveChampion(tid, { generateReceipt: false });
    } else {
      // se já era o mesmo, só ajusta hint (não salva)
      if (hint) hint.textContent = "Seu campeão já está salvo. Clique em “Salvar campeão” para gerar o recibo.";
    }
  });

  // ✅ alterado: botão agora salva (garante) e abre recibo
  btnSave.addEventListener("click", async () => {
    if (!(pendingId > 0)) return;

    await saveChampion(pendingId, { generateReceipt: true });
  });

  // Estado inicial
  setSelected(selectedId);
});
