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

  const groupMenu = document.getElementById("group-menu");
  const groupBlocks = Array.from(document.querySelectorAll("[data-group-block]"));

  const knockoutLinks = Array.from(
    document.querySelectorAll('.app-menu a.menu-link[href^="#fase-"]')
  );
  const knockoutBlocks = Array.from(document.querySelectorAll('[id^="fase-"]'));

  function setActiveGroup(groupId) {
    const gid = String(groupId || "");

    groupBlocks.forEach((b) => {
      const id = b.getAttribute("data-group-block");
      if (id === gid) b.classList.add("is-active-group");
      else b.classList.remove("is-active-group");
    });

    if (groupMenu) {
      const links = Array.from(groupMenu.querySelectorAll(".menu-link"));
      links.forEach((a) => {
        const id = a.getAttribute("data-group-id");
        if (id === gid) a.classList.add("is-active");
        else a.classList.remove("is-active");
      });
    }
  }

  function setActiveKnockout(targetId) {
    const tid = String(targetId || "").replace(/^#/, "");
    if (!tid) return;

    knockoutBlocks.forEach((block) => {
      if (block.id === tid) {
        block.classList.add("is-active-group");
        block.scrollIntoView({ behavior: "smooth", block: "start" });
      } else {
        block.classList.remove("is-active-group");
      }
    });

    knockoutLinks.forEach((a) => {
      const href = (a.getAttribute("href") || "").replace(/^#/, "");
      if (href === tid) a.classList.add("is-active");
      else a.classList.remove("is-active");
    });
  }

  if (groupMenu) {
    groupMenu.addEventListener("click", (ev) => {
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

  knockoutLinks.forEach((a) => {
    a.addEventListener("click", (ev) => {
      if (a.classList.contains("is-disabled") || a.getAttribute("aria-disabled") === "true") {
        ev.preventDefault();
        return;
      }

      ev.preventDefault();

      const href = a.getAttribute("href") || "";
      if (!href || href === "#") return;

      setActiveKnockout(href);
    });
  });

  const initial = CFG && CFG.active_group_id ? String(CFG.active_group_id) : null;
  if (initial) {
    setActiveGroup(initial);
  } else if (groupBlocks.length > 0) {
    setActiveGroup(groupBlocks[0].getAttribute("data-group-block"));
  }
});