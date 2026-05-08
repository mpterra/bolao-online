document.addEventListener("DOMContentLoaded", () => {
  "use strict";

  const toast = document.getElementById("copyToast");
  let toastTimer = null;

  function showToast(message) {
    if (!toast) return;

    toast.textContent = message;
    toast.classList.add("is-visible");

    if (toastTimer !== null) {
      window.clearTimeout(toastTimer);
    }

    toastTimer = window.setTimeout(() => {
      toast.classList.remove("is-visible");
    }, 2200);
  }

  async function copyText(value) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
      await navigator.clipboard.writeText(value);
      return;
    }

    const helper = document.createElement("textarea");
    helper.value = value;
    helper.setAttribute("readonly", "readonly");
    helper.style.position = "fixed";
    helper.style.opacity = "0";
    document.body.appendChild(helper);
    helper.select();
    document.execCommand("copy");
    document.body.removeChild(helper);
  }

  document.querySelectorAll("[data-copy-target]").forEach((button) => {
    button.addEventListener("click", async () => {
      const targetId = button.getAttribute("data-copy-target");
      const label = button.getAttribute("data-copy-label") || "conteúdo";
      const field = targetId ? document.getElementById(targetId) : null;
      const value = field && "value" in field ? field.value.trim() : "";

      if (!value) {
        showToast("Nada para copiar agora.");
        return;
      }

      try {
        await copyText(value);
        showToast(`${label} copiado com sucesso.`);
      } catch (error) {
        showToast("Não foi possível copiar automaticamente.");
      }
    });
  });
});