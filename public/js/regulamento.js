document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_REGULAMENTO_INIT__ === true) return;
  window.__BOLAO_REGULAMENTO_INIT__ = true;

  const nav = document.querySelector(".rules-section-nav");
  const links = Array.from(document.querySelectorAll("[data-section-link]"));
  const sections = links
    .map((link) => {
      const id = link.getAttribute("data-section-link") || "";
      const section = document.getElementById(id);
      return section ? { id, link, section } : null;
    })
    .filter(Boolean);

  if (sections.length === 0) return;

  let activeId = "";
  let rafId = 0;

  function setActive(id) {
    if (!id || id === activeId) return;
    activeId = id;

    for (const item of sections) {
      const active = item.id === id;
      item.link.classList.toggle("is-active", active);
      if (active) {
        item.link.setAttribute("aria-current", "true");
        item.link.scrollIntoView({ block: "nearest", inline: "center" });
      } else {
        item.link.removeAttribute("aria-current");
      }
    }
  }

  function activationLine() {
    const navBottom = nav ? nav.getBoundingClientRect().bottom : 0;
    return Math.max(navBottom + 16, window.innerHeight * 0.38);
  }

  function detectActiveSection() {
    const line = activationLine();
    let current = sections[0].id;

    for (const item of sections) {
      const top = item.section.getBoundingClientRect().top;
      if (top <= line) {
        current = item.id;
      } else {
        break;
      }
    }

    const viewportBottom = window.innerHeight + window.scrollY;
    const pageBottom = document.documentElement.scrollHeight - 4;
    if (viewportBottom >= pageBottom) {
      current = sections[sections.length - 1].id;
    }

    setActive(current);
  }

  function queueDetectActiveSection() {
    if (rafId) return;
    rafId = window.requestAnimationFrame(() => {
      rafId = 0;
      detectActiveSection();
    });
  }

  for (const item of sections) {
    item.link.addEventListener("click", () => {
      setActive(item.id);
      window.setTimeout(queueDetectActiveSection, 120);
    });
  }

  window.addEventListener("scroll", queueDetectActiveSection, { passive: true });
  window.addEventListener("resize", queueDetectActiveSection, { passive: true });
  window.addEventListener("hashchange", queueDetectActiveSection);

  queueDetectActiveSection();
  window.setTimeout(queueDetectActiveSection, 0);
  window.setTimeout(queueDetectActiveSection, 180);
});