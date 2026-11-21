"use strict";

/* =========================================================
   CITISENSE AUTH PAGES
   Login · Forgot password · Reset password
========================================================= */

(function () {

    /* ---------- Show / hide password ---------- */

    document.querySelectorAll(".password-toggle").forEach(function (toggle) {
        const input = toggle.parentElement.querySelector("input");
        const icon = toggle.querySelector("i");

        if (!input || !icon) return;

        toggle.addEventListener("click", function () {
            const show = input.type === "password";

            input.type = show ? "text" : "password";
            icon.className = show ? "bi bi-eye-slash" : "bi bi-eye";

            toggle.setAttribute("aria-pressed", show ? "true" : "false");
            toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");

            // Keep the cursor where it was
            const end = input.value.length;
            input.focus();
            input.setSelectionRange(end, end);
        });
    });

    /* ---------- Caps Lock hint ---------- */

    const capsHint = document.getElementById("capsHint");

    if (capsHint) {
        document.querySelectorAll("#password, #newPassword, #confirmPassword").forEach(function (input) {
            ["keydown", "keyup"].forEach(function (type) {
                input.addEventListener(type, function (event) {
                    if (typeof event.getModifierState === "function") {
                        capsHint.hidden = !event.getModifierState("CapsLock");
                    }
                });
            });

            input.addEventListener("blur", function () {
                capsHint.hidden = true;
            });
        });
    }

    /* ---------- Inline validation + loading state ---------- */

    function markInvalid(input, message) {
        const group = input.closest(".form-group");
        if (!group) return;

        group.classList.add("has-error");

        let hint = group.querySelector(".field-error");

        if (!hint) {
            hint = document.createElement("small");
            hint.className = "field-error";
            group.appendChild(hint);
        }

        hint.textContent = message;
    }

    function clearInvalid(input) {
        const group = input.closest(".form-group");
        if (!group) return;

        group.classList.remove("has-error");

        const hint = group.querySelector(".field-error");
        if (hint) hint.remove();
    }

    document.querySelectorAll(".auth-form").forEach(function (form) {

        form.querySelectorAll("input").forEach(function (input) {
            input.addEventListener("input", function () {
                clearInvalid(input);
            });
        });

        form.addEventListener("submit", function (event) {
            let valid = true;

            form.querySelectorAll("input[required]").forEach(function (input) {
                clearInvalid(input);

                if (input.value.trim() === "") {
                    markInvalid(input, "This field is required.");
                    valid = false;
                } else if (input.type === "email" && !input.checkValidity()) {
                    markInvalid(input, "Please enter a valid email address.");
                    valid = false;
                }
            });

            const newPassword = form.querySelector("#newPassword");
            const confirmPassword = form.querySelector("#confirmPassword");

            if (valid && newPassword && confirmPassword) {
                if (newPassword.value.length < 8) {
                    markInvalid(newPassword, "Use at least 8 characters.");
                    valid = false;
                } else if (newPassword.value !== confirmPassword.value) {
                    markInvalid(confirmPassword, "Passwords do not match.");
                    valid = false;
                }
            }

            if (!valid) {
                event.preventDefault();

                const firstError = form.querySelector(".has-error input");
                if (firstError) firstError.focus();
                return;
            }

            const button = form.querySelector(".auth-button");

            if (button) {
                button.classList.add("is-loading");
                button.disabled = true;
            }
        });
    });

    /* ---------- Password strength meter (reset page) ---------- */

    const newPassword = document.getElementById("newPassword");
    const meter = document.getElementById("strengthMeter");
    const meterLabel = document.getElementById("strengthLabel");

    if (newPassword && meter && meterLabel) {
        newPassword.addEventListener("input", function () {
            const value = newPassword.value;
            let score = 0;

            if (value.length >= 8) score++;
            if (value.length >= 12) score++;
            if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;

            const level = value === "" ? 0 : Math.max(1, Math.min(4, score));
            const labels = ["", "Weak", "Fair", "Good", "Strong"];

            meter.dataset.level = String(level);
            meterLabel.textContent = labels[level];
        });
    }

    /* ---------- Clear fields when returning with the back button ---------- */

    window.addEventListener("pageshow", function (event) {
        if (!event.persisted) return;

        document.querySelectorAll('input[type="password"]').forEach(function (input) {
            input.value = "";
        });

        document.querySelectorAll(".auth-button").forEach(function (button) {
            button.classList.remove("is-loading");
            button.disabled = false;
        });
    });

})();
