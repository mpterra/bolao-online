document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_INDEX_INIT__ === true) return;
  window.__BOLAO_INDEX_INIT__ = true;

  // Floating labels
  if (window.BOLAO && typeof window.BOLAO.initFloatingLabels === "function") {
    window.BOLAO.initFloatingLabels();
  }

  // =========================================================
  // TOAST (flash message do login) — animado e profissional
  // - Lê data-flash-type / data-flash-msg do <body>
  // - Auto-close com barra de progresso
  // - Fecha no ESC e no botão
  // =========================================================
  (function initLoginToast() {
    const body = document.body;
    if (!body) return;

    const type = (body.getAttribute("data-flash-type") || "").trim();
    const msg = (body.getAttribute("data-flash-msg") || "").trim();

    if (!type || !msg) return;

    const host = document.querySelector(".toast-host");
    if (!host) return;

    const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    const titleMap = {
      error: "Não foi possível entrar",
      warn: "Atenção",
      info: "Informação",
      ok: "Tudo certo"
    };

    const iconSvgMap = {
      error: `
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm1 14h-2v-2h2v2Zm0-4h-2V6h2v6Z"/>
        </svg>`,
      warn: `
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M1 21h22L12 2 1 21Zm12-3h-2v-2h2v2Zm0-4h-2v-4h2v4Z"/>
        </svg>`,
      info: `
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm1 15h-2v-6h2v6Zm0-8h-2V7h2v2Z"/>
        </svg>`,
      ok: `
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm-1 14-4-4 1.4-1.4L11 12.2l4.6-4.6L17 9l-6 7Z"/>
        </svg>`
    };

    const toast = document.createElement("div");
    toast.className = `toast toast--${type}`;
    toast.setAttribute("role", "status");

    const title = titleMap[type] || "Aviso";
    toast.innerHTML = `
      <div class="toast__icon" aria-hidden="true">${iconSvgMap[type] || ""}</div>
      <div class="toast__body">
        <div class="toast__title">${title}</div>
        <p class="toast__msg"></p>
        <div class="toast__bar" aria-hidden="true"><i></i></div>
      </div>
      <button type="button" class="toast__close" aria-label="Fechar">×</button>
    `;

    const msgEl = toast.querySelector(".toast__msg");
    if (msgEl) msgEl.textContent = msg;

    host.appendChild(toast);

    // show (com animação via CSS)
    if (!reduceMotion) {
      requestAnimationFrame(() => toast.classList.add("show"));
    } else {
      toast.classList.add("show");
    }

    const DURATION = 5200; // ms
    const bar = toast.querySelector(".toast__bar > i");

    // barra de progresso
    let barAnim = null;
    if (bar) {
      if (!reduceMotion && bar.animate) {
        barAnim = bar.animate(
          [{ transform: "scaleX(1)" }, { transform: "scaleX(0)" }],
          { duration: DURATION, easing: "linear", fill: "forwards" }
        );
      } else {
        // fallback simples
        bar.style.transformOrigin = "left";
        bar.style.transform = "scaleX(1)";
        const start = Date.now();
        const tick = () => {
          const t = Date.now() - start;
          const p = Math.max(0, 1 - (t / DURATION));
          bar.style.transform = `scaleX(${p})`;
          if (p > 0) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
      }
    }

    let closed = false;

    function closeToast() {
      if (closed) return;
      closed = true;

      try { if (barAnim) barAnim.cancel(); } catch (_) {}

      const remove = () => {
        if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
      };

      if (!reduceMotion) {
        toast.classList.remove("show");
        setTimeout(remove, 220);
      } else {
        remove();
      }
    }

    // autoclose
    const t = setTimeout(closeToast, DURATION);

    // close btn
    const btnClose = toast.querySelector(".toast__close");
    if (btnClose) {
      btnClose.addEventListener("click", () => {
        clearTimeout(t);
        closeToast();
      });
    }

    // ESC fecha
    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        clearTimeout(t);
        closeToast();
      }
    }, { once: true });
  })();

  // Tilt no card (LOGIN)
  const card = document.querySelector(".login-card");
  if (card) {
    const maxTilt = 6;

    function onMove(e) {
      const rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width;
      const y = (e.clientY - rect.top) / rect.height;

      const rotY = (x - 0.5) * (maxTilt * 2);
      const rotX = (0.5 - y) * (maxTilt * 2);

      card.style.transform = `perspective(900px) rotateX(${rotX}deg) rotateY(${rotY}deg)`;
    }

    function reset() {
      card.style.transform = "perspective(900px) rotateX(0deg) rotateY(0deg)";
    }

    const finePointer = window.matchMedia && window.matchMedia("(pointer: fine)").matches;
    const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (finePointer && !reduceMotion) {
      card.addEventListener("mousemove", onMove);
      card.addEventListener("mouseleave", reset);
    }
  }

  // Micro feedback botão (LOGIN)
  const btn = document.querySelector(".btn-login");
  if (btn) {
    btn.addEventListener("click", () => {
      btn.animate(
        [
          { transform: "translateY(-1px) scale(1)" },
          { transform: "translateY(0px) scale(0.985)" },
          { transform: "translateY(-1px) scale(1)" }
        ],
        { duration: 220, easing: "ease-out" }
      );
    });
  }
});
