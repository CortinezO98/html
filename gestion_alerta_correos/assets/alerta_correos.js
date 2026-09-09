(function () {
    'use strict';

    function initCharCounters() {
        document.querySelectorAll('[data-ac-maxlength]').forEach(function (field) {
            var max = parseInt(field.getAttribute('data-ac-maxlength'), 10);
            var targetId = field.getAttribute('data-ac-counter');
            var target = targetId ? document.getElementById(targetId) : null;
            if (!target || !max) return;

            var paint = function () {
                var used = (field.value || '').length;
                target.textContent = used + ' / ' + max;
            };
            field.addEventListener('input', paint);
            paint();
        });
    }

    function initFilePicker() {
        var input = document.querySelector('[data-ac-file-input]');
        var label = document.querySelector('[data-ac-file-name]');
        if (!input || !label) return;
        input.addEventListener('change', function () {
            label.textContent = input.files && input.files.length
                ? input.files[0].name
                : 'Ningún archivo seleccionado';
        });
    }

    function initSubmitLock() {
        document.querySelectorAll('form[data-ac-lock-submit="1"]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"], input[type="submit"]');
                if (!button || button.disabled) return;
                button.disabled = true;
                if (button.tagName === 'BUTTON') {
                    button.setAttribute('data-original-html', button.innerHTML);
                    button.innerHTML = '<span class="fas fa-spinner fa-spin"></span> Procesando...';
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCharCounters();
        initFilePicker();
        initSubmitLock();
    });
})();
