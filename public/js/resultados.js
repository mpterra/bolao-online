document.addEventListener("DOMContentLoaded", () => {
  "use strict";

  if (window.__BOLAO_RESULTADOS_INIT__ === true) return;
  window.__BOLAO_RESULTADOS_INIT__ = true;

  const cfgEl = document.getElementById("resultados-config");
  let CFG = {};
  try { CFG = cfgEl ? JSON.parse(cfgEl.textContent || "{}") : {}; } catch (e) { CFG = {}; }

  const menu = document.getElementById("group-menu");
  const blocks = Array.from(document.querySelectorAll("[data-group-block]"));

  function setActiveGroup(groupId) {
    const gid = String(groupId || "");

    blocks.forEach((b) => {
      const id = b.getAttribute("data-group-block");
      if (id === gid) b.classList.add("is-active-group");
      else b.classList.remove("is-active-group");
    });

    if (menu) {
      const links = Array.from(menu.querySelectorAll(".menu-link"));
      links.forEach((a) => {
        const id = a.getAttribute("data-group-id");
        if (id === gid) a.classList.add("is-active");
        else a.classList.remove("is-active");
      });
    }
  }

  if (menu) {
    menu.addEventListener("click", (ev) => {
      const a = ev.target.closest("a.menu-link");
      if (!a) return;

      if (a.classList.contains("is-disabled") || a.getAttribute("aria-disabled") === "true") {
        ev.preventDefault();
        return;
      }

      ev.preventDefault();
      const gid = a.getAttribute("data-group-id");
      if (gid) setActiveGroup(gid);
    });
  }

  const initial = CFG && CFG.active_group_id ? String(CFG.active_group_id) : null;
  if (initial) setActiveGroup(initial);
  else if (blocks.length > 0) setActiveGroup(blocks[0].getAttribute("data-group-block"));
});