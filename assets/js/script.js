document.addEventListener("DOMContentLoaded", function () {
    const confirmationForms = document.querySelectorAll(
        "form[data-confirm]"
    );

    confirmationForms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const message =
                form.getAttribute("data-confirm") ||
                "Are you sure you want to continue?";

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    const dateForms = document.querySelectorAll(".booking-form");

    dateForms.forEach(function (form) {
        const startDate = form.querySelector(
            'input[name="start_date"]'
        );

        const endDate = form.querySelector(
            'input[name="end_date"]'
        );

        if (startDate && endDate) {
            startDate.addEventListener("change", function () {
                if (startDate.value) {
                    endDate.min = startDate.value;

                    if (
                        endDate.value &&
                        endDate.value <= startDate.value
                    ) {
                        endDate.value = "";
                    }
                }
            });
        }
    });
});