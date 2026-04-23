document.addEventListener("DOMContentLoaded", () => {
  "use strict";
  if (window.__BOLAO_REDEFINIR_INIT__ === true) return;
  window.__BOLAO_REDEFINIR_INIT__ = true;

  // Floating labels
  if (window.BOLAO && typeof window.BOLAO.initFloatingLabels === "function") {
    window.BOLAO.initFloatingLabels();
  }

  // Toast (flash message)
  (function initToast() {
    const body = document.body;
    if (!body) return;

    const type = (body.getAttribute("data-flash-type") || "").trim();
    const msg  = (body.getAttribute("data-flash-msg")  || "").trim();
    if (!type || !msg) return;

    const host = document.querySelector(".toast-host");
    if (!host) return;

    const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    const titleMap = {
      error: "Erro",
      warn:  "Atenção",
      info:  "Informação",
      ok:    "Tudo certo"
    };

    const iconSvgMap = {
      error: `<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm1 14h-2v-2h2v2Zm0-4h-2V6h2v6Z"/></svg>`,
      warn:  `<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M1 21h22L12 2 1 21Zm12-3h-2v-2h2v2Zm0-4h-2v-4h2v4Z"/></svg>`,
      info:  `<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm1 15h-2v-6h2v6Zm0-8h-2V7h2v2Z"/></svg>`,
      ok:    `<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2Zm-1 14-4-4 1.4-1.4L11 12.2l4.6-4.6L17 9l-6 7Z"/></svg>`
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

    if (!reduceMotion) {
      requestAnimationFrame(() => toast.classList.add("show"));
    } else {
      toast.classList.add("show");
    }

    const DURATION = 6000;
    const bar = toast.querySelector(".toast__bar i");

    if (bar && !reduceMotion) {
      bar.style.transition = `width ${DURATION}ms linear`;
      requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.width = "0%"; }));
    }

    const timer = setTimeout(() => close(), DURATION);

    function close() {
      clearTimeout(timer);
      toast.classList.remove("show");
      toast.addEventListener("transitionend", () => toast.remove(), { once: true });
    }

    toast.querySelector(".toast__close")?.addEventListener("click", close);
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") close(); }, { once: true });
  })();

  // Validação client-side: confirmar que as senhas coincidem
  const form = document.querySelector("form.login-form");
  if (form) {
    form.addEventListener("submit", (e) => {
      const nova     = /** @type {HTMLInputElement|null} */ (form.querySelector("#nova_senha"));
      const confirma = /** @type {HTMLInputElement|null} */ (form.querySelector("#confirmar_senha"));
      if (!nova || !confirma) return;

      if (nova.value !== confirma.value) {
        e.preventDefault();
        confirma.setCustomValidity("As senhas não coincidem.");
        confirma.reportValidity();
      } else {
        confirma.setCustomValidity("");
      }
    });

    const confirmaInput = /** @type {HTMLInputElement|null} */ (form.querySelector("#confirmar_senha"));
    if (confirmaInput) {
      confirmaInput.addEventListener("input", () => confirmaInput.setCustomValidity(""));
    }
  }
});
