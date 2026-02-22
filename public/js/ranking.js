document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_RANKING_INIT__ === true) return;
  window.__BOLAO_RANKING_INIT__ = true;

  const cfgEl = document.getElementById("app-config");
  let CFG = {};
  try {
    CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
  } catch (e) {
    CFG = {};
  }

  const input = document.getElementById("rkSearch");
  const btnClear = document.getElementById("rkClear");
  const countEl = document.getElementById("rkCount");
  const table = document.getElementById("rkTable");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tbody tr"));
  const total = rows.length;

  function norm(s) {
    return (s || "")
      .toString()
      .trim()
      .toLowerCase();
  }

  function applyFilter() {
    const q = norm(input ? input.value : "");
    let visible = 0;

    for (const tr of rows) {
      const name = norm(tr.getAttribute("data-name") || "");
      const ok = (q === "" || name.includes(q));
      tr.style.display = ok ? "" : "none";
      if (ok) visible++;
    }

    if (countEl) countEl.textContent = String(visible);
  }

  function clearFilter() {
    if (input) input.value = "";
    applyFilter();
    if (input) input.focus();
  }

  if (input) {
    input.addEventListener("input", applyFilter, { passive: true });
  }
  if (btnClear) {
    btnClear.addEventListener("click", clearFilter);
  }

  // Inicial
  if (countEl) countEl.textContent = String(total);
});