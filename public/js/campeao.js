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

  let selectedId = Number(cfg?.selected_time_id || 0);
  let pendingId = selectedId;

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

    btnSave.disabled = !(pendingId > 0) || (pendingId === selectedId);

    if (hint) {
      if (!(pendingId > 0)) {
        hint.textContent = "Selecione um time para habilitar o botão.";
      } else if (pendingId === selectedId && selectedId > 0) {
        hint.textContent = "Seu campeão já está salvo. Selecione outro para trocar.";
      } else {
        const tile = grid.querySelector(`.team-tile[data-time-id="${pendingId}"]`);
        const nm = tile ? (tile.getAttribute("data-time-name") || "time") : "time";
        hint.textContent = `Selecionado: ${nm}. Clique em “Salvar campeão”.`;
      }
    }
  }

  grid.addEventListener("click", (e) => {
    const tile = e.target.closest(".team-tile");
    if (!tile) return;
    const tid = Number(tile.getAttribute("data-time-id") || 0);
    if (!(tid > 0)) return;
    setSelected(tid);
  });

  btnSave.addEventListener("click", async () => {
    if (!(pendingId > 0) || pendingId === selectedId) return;

    btnSave.disabled = true;
    btnSave.textContent = "Salvando...";

    try {
      const resp = await fetch(endpointSave, {
        method: "POST",
        headers: { "Content-Type": "application/json; charset=utf-8" },
        body: JSON.stringify({ time_id: pendingId })
      });

      const data = await resp.json().catch(() => ({}));
      if (!resp.ok || !data || data.ok !== true) {
        const msg = (data && data.message) ? data.message : "Falha ao salvar.";
        showToast(msg, false);
        btnSave.textContent = "Salvar campeão";
        btnSave.disabled = false;
        return;
      }

      selectedId = pendingId;
      showToast(data.message || "Campeão salvo.", true);

      btnSave.textContent = "Salvar campeão";
      setSelected(selectedId);

    } catch (err) {
      showToast("Erro de rede ao salvar.", false);
      btnSave.textContent = "Salvar campeão";
      btnSave.disabled = false;
    }
  });

  // Estado inicial
  setSelected(selectedId);
});
