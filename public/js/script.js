document.addEventListener("DOMContentLoaded", () => {
  // Floating labels
  const groups = document.querySelectorAll(".input-group");

  function syncGroupState(input) {
    const group = input.closest(".input-group");
    if (!group) return;
    if (input.value && input.value.trim().length > 0) group.classList.add("has-value");
    else group.classList.remove("has-value");
  }

  groups.forEach(group => {
    const input = group.querySelector("input");
    if (!input) return;

    syncGroupState(input);
    input.addEventListener("input", () => syncGroupState(input));
    input.addEventListener("blur", () => syncGroupState(input));
  });

  // Tilt no card (desktop)
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

  // Micro feedback botão
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

  // Modal sucesso cadastro -> OK volta pro login
  const regSuccess = document.body && document.body.dataset ? document.body.dataset.regSuccess : "0";
  const modal = document.getElementById("modalSucesso");
  const btnOk = document.getElementById("btnOkCadastro");

  function goLogin() {
    window.location.href = "/bolao-da-copa/public/index.php";
  }

  function openModal() {
    if (!modal) return;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    if (btnOk) btnOk.focus();
  }

  if (regSuccess === "1") {
    openModal();

    // tira ?sucesso=1 da URL pra não repetir no F5
    try {
      const url = new URL(window.location.href);
      url.searchParams.delete("sucesso");
      window.history.replaceState({}, document.title, url.toString());
    } catch (e) {}

    if (btnOk) btnOk.addEventListener("click", goLogin);

    if (modal) {
      modal.addEventListener("click", (e) => {
        if (e.target === modal) goLogin();
      });
    }

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" || e.key === "Enter") goLogin();
    }, { once: true });
  }
});
