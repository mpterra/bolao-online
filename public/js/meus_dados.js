(function () {
  const modal = document.querySelector("[data-delete-account-modal]");
  const openButton = document.querySelector("[data-open-delete-account]");

  if (!modal || !openButton) return;

  const confirmStep = modal.querySelector('[data-delete-step="confirm"]');
  const passwordStep = modal.querySelector('[data-delete-step="password"]');
  const passwordInput = modal.querySelector("#senha_atual_descadastro");
  const inlineError = modal.querySelector("[data-delete-account-error]");
  const confirmButton = modal.querySelector("[data-confirm-delete-account]");
  const backButton = modal.querySelector("[data-back-delete-account]");
  const closeButtons = Array.from(modal.querySelectorAll("[data-close-delete-account]"));
  let lastFocus = null;

  function showConfirmStep() {
    if (confirmStep) confirmStep.hidden = false;
    if (passwordStep) passwordStep.hidden = true;
    if (inlineError) inlineError.hidden = true;
    if (passwordInput) passwordInput.value = "";
  }

  function showPasswordStep() {
    if (confirmStep) confirmStep.hidden = true;
    if (passwordStep) passwordStep.hidden = false;
    if (inlineError) inlineError.hidden = true;
    window.setTimeout(function () {
      if (passwordInput) passwordInput.focus();
    }, 0);
  }

  function openModal() {
    lastFocus = document.activeElement;
    showConfirmStep();
    modal.hidden = false;
    document.body.classList.add("is-delete-account-modal-open");
    window.setTimeout(function () {
      if (confirmButton) confirmButton.focus();
    }, 0);
  }

  function closeModal() {
    modal.hidden = true;
    document.body.classList.remove("is-delete-account-modal-open");
    showConfirmStep();

    if (lastFocus && typeof lastFocus.focus === "function") {
      lastFocus.focus();
    }
  }

  openButton.addEventListener("click", openModal);

  if (confirmButton) {
    confirmButton.addEventListener("click", showPasswordStep);
  }

  if (backButton) {
    backButton.addEventListener("click", showConfirmStep);
  }

  closeButtons.forEach(function (button) {
    button.addEventListener("click", closeModal);
  });

  if (passwordStep) {
    passwordStep.addEventListener("submit", function (event) {
      if (!passwordInput || passwordInput.value.trim() !== "") {
        return;
      }

      event.preventDefault();
      if (inlineError) inlineError.hidden = false;
      passwordInput.focus();
    });
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !modal.hidden) {
      closeModal();
    }
  });
})();
