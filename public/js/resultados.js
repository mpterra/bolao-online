document.addEventListener("DOMContentLoaded", () => {
  "use strict";

  if (window.__BOLAO_RESULTADOS_INIT__ === true) return;
  window.__BOLAO_RESULTADOS_INIT__ = true;

  const cfgEl = document.getElementById("resultados-config");
  let CFG = {};
  try {
    CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {};
  } catch (e) {
    CFG = {};
  }

  const modeButtons = Array.from(document.querySelectorAll(".menu-view-btn[data-view-mode]"));
  const menuPanels = Array.from(document.querySelectorAll(".menu-panel[data-menu-panel]"));
  const modeHeads = Array.from(document.querySelectorAll(".content-head[data-content-mode]"));
  const menuLinks = Array.from(document.querySelectorAll(".app-menu .menu-link[data-mode][data-block-type][data-block-key]"));
  const blocks = Array.from(document.querySelectorAll(".group-block[data-view-mode][data-block-type][data-block-key]"));

  const state = {
    mode: CFG && CFG.active_mode ? String(CFG.active_mode) : "group",
    selected: {
      group: {
        type: CFG && CFG.active_mode === "group" ? String(CFG.active_type || "") : "",
        key: CFG && CFG.active_mode === "group" ? String(CFG.active_key || "") : "",
      },
      day: {
        type: CFG && CFG.active_mode === "day" ? String(CFG.active_type || "day") : "day",
        key: CFG && CFG.active_mode === "day" ? String(CFG.active_key || "") : "",
      },
    },
  };

  if (state.mode !== "group" && state.mode !== "day") {
    state.mode = "group";
  }

  function normalize(str) {
    return String(str || "");
  }

  function firstEnabledLinkForMode(mode) {
    return menuLinks.find((a) => {
      if (normalize(a.dataset.mode) !== normalize(mode)) return false;
      if (a.classList.contains("is-disabled")) return false;
      if (a.getAttribute("aria-disabled") === "true") return false;
      return true;
    }) || null;
  }

  function showMode(mode) {
    state.mode = mode;

    modeButtons.forEach((btn) => {
      btn.classList.toggle("is-active", normalize(btn.dataset.viewMode) === mode);
    });

    menuPanels.forEach((panel) => {
      panel.classList.toggle("is-active", normalize(panel.dataset.menuPanel) === mode);
    });

    modeHeads.forEach((head) => {
      head.classList.toggle("is-active", normalize(head.dataset.contentMode) === mode);
    });
  }

  function setActiveBlock(mode, type, key) {
    const m = normalize(mode);
    const t = normalize(type);
    const k = normalize(key);

    blocks.forEach((block) => {
      const match =
        normalize(block.dataset.viewMode) === m &&
        normalize(block.dataset.blockType) === t &&
        normalize(block.dataset.blockKey) === k;
      block.classList.toggle("is-active-group", match);
    });

    menuLinks.forEach((a) => {
      const match =
        normalize(a.dataset.mode) === m &&
        normalize(a.dataset.blockType) === t &&
        normalize(a.dataset.blockKey) === k;
      a.classList.toggle("is-active", match);
    });

    state.selected[m] = { type: t, key: k };
  }

  function ensureSelectionForMode(mode) {
    const m = normalize(mode);
    const saved = state.selected[m] || { type: "", key: "" };

    const hasSaved = blocks.some((block) => {
      return (
        normalize(block.dataset.viewMode) === m &&
        normalize(block.dataset.blockType) === normalize(saved.type) &&
        normalize(block.dataset.blockKey) === normalize(saved.key)
      );
    });

    if (hasSaved && saved.type && saved.key) {
      setActiveBlock(m, saved.type, saved.key);
      return;
    }

    const first = firstEnabledLinkForMode(m);
    if (first) {
      setActiveBlock(m, first.dataset.blockType, first.dataset.blockKey);
    }
  }

  modeButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const mode = normalize(btn.dataset.viewMode);
      if (!mode || mode === state.mode) return;
      showMode(mode);
      ensureSelectionForMode(mode);
    });
  });

  menuLinks.forEach((a) => {
    a.addEventListener("click", (ev) => {
      if (a.classList.contains("is-disabled") || a.getAttribute("aria-disabled") === "true") {
        ev.preventDefault();
        return;
      }

      ev.preventDefault();
      const mode = normalize(a.dataset.mode);
      const type = normalize(a.dataset.blockType);
      const key = normalize(a.dataset.blockKey);
      if (!mode || !type || !key) return;

      if (state.mode !== mode) {
        showMode(mode);
      }
      setActiveBlock(mode, type, key);
    });
  });

  showMode(state.mode);
  ensureSelectionForMode(state.mode);
});