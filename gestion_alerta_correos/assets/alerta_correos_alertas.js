(function () {
    'use strict';

    function swalDisponible() {
        return typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function';
    }

    function revelarFallback() {
        document.querySelectorAll('[data-ac-alert-fallback]').forEach(function (el) {
            el.classList.remove('d-none');
        });
    }


    function alertifyDisponible() {
        return typeof window.alertify !== 'undefined';
    }

    function fallbackMensaje(tipo, titulo, mensaje) {
        if (alertifyDisponible()) {
            var texto = titulo + ': ' + mensaje;
            if (tipo === 'error' && typeof window.alertify.error === 'function') {
                window.alertify.error(texto, 0);
                return;
            }
            if (tipo === 'success' && typeof window.alertify.success === 'function') {
                window.alertify.success(texto, 0);
                return;
            }
            if (typeof window.alertify.warning === 'function') {
                window.alertify.warning(texto, 0);
                return;
            }
        }
        revelarFallback();
    }

    function clases() {
        return {
            popup: 'ac-swal-popup',
            confirmButton: 'btn btn-success px-4 mx-1',
            cancelButton: 'btn btn-outline-danger px-4 mx-1'
        };
    }

    function escaparTexto(texto) {
        var span = document.createElement('span');
        span.textContent = texto == null ? '' : String(texto);
        return span.innerHTML;
    }

    function mostrarErrores(errores) {
        if (!Array.isArray(errores) || errores.length === 0) return;
        if (!swalDisponible()) {
            fallbackMensaje('error', 'Revise la información ingresada', errores.join(' | '));
            return;
        }

        var html = '<div>Corrija los siguientes datos antes de continuar:</div><ul class="ac-swal-errors">';
        errores.forEach(function (error) {
            html += '<li>' + escaparTexto(error) + '</li>';
        });
        html += '</ul>';

        window.Swal.fire({
            icon: 'error',
            title: 'Revise la información ingresada',
            html: html,
            confirmButtonText: 'Corregir',
            buttonsStyling: false,
            customClass: clases(),
            allowOutsideClick: false
        });
    }

    function mostrarFlash(flash) {
        if (!flash || !flash.mensaje) return;
        if (!swalDisponible()) {
            var tipoFallback = String(flash.tipo || 'info').toLowerCase();
            fallbackMensaje(tipoFallback === 'danger' ? 'error' : tipoFallback, 'Información', String(flash.mensaje));
            return;
        }

        var tipo = String(flash.tipo || 'info').toLowerCase();
        var icono = 'info';
        if (tipo === 'success' || tipo === 'exito') icono = 'success';
        if (tipo === 'danger' || tipo === 'error') icono = 'error';
        if (tipo === 'warning' || tipo === 'advertencia') icono = 'warning';

        window.Swal.fire({
            icon: icono,
            title: icono === 'success' ? 'Proceso realizado' : (icono === 'error' ? 'No fue posible completar el proceso' : 'Información'),
            text: String(flash.mensaje),
            confirmButtonText: 'Aceptar',
            buttonsStyling: false,
            customClass: clases()
        });
    }

    function primerInvalido(form) {
        var controles = form.querySelectorAll('input, select, textarea');
        for (var i = 0; i < controles.length; i++) {
            if (typeof controles[i].checkValidity === 'function' && !controles[i].checkValidity()) {
                return controles[i];
            }
        }
        return null;
    }

    function validarFormularioNuevaAlerta(form) {
        var simBusqueda = form.querySelector('#sim_busqueda');
        var simOculto = form.querySelector('#sim');
        if (simBusqueda) {
            var valor = String(simBusqueda.value || '').trim();
            var seleccionado = simOculto ? String(simOculto.value || '').trim() : '';
            // Los radicados SIM oficiales son numéricos. Si no es un SIM previamente
            // seleccionado, el registro manual exige de 3 a 50 dígitos.
            if (valor !== '' && seleccionado === '' && !/^\d{3,50}$/.test(valor)) {
                return {
                    valido: false,
                    campo: simBusqueda,
                    mensaje: 'El número SIM debe contener únicamente números (entre 3 y 50 dígitos). Si es un SIM existente, selecciónelo en los resultados de búsqueda.'
                };
            }
        }

        if (!form.checkValidity()) {
            return {
                valido: false,
                campo: primerInvalido(form),
                mensaje: 'Complete los campos obligatorios antes de registrar la alerta.'
            };
        }
        return { valido: true };
    }

    function resumenNuevaAlerta(form) {
        function textoSelect(selector) {
            var el = form.querySelector(selector);
            if (!el || el.selectedIndex < 0) return '';
            return String(el.options[el.selectedIndex].text || '').trim();
        }
        function valor(selector) {
            var el = form.querySelector(selector);
            return el ? String(el.value || '').trim() : '';
        }

        var sim = valor('#sim_busqueda') || 'Sin SIM';
        var regional = textoSelect('#regional_id') || 'Sin regional';
        var punto = textoSelect('#punto_atencion_id') || 'Sin punto';
        var tipo = textoSelect('#tipo_alerta') || '';

        return '<div class="ac-swal-summary">' +
            '<div><strong>SIM:</strong> ' + escaparTexto(sim) + '</div>' +
            '<div><strong>Regional:</strong> ' + escaparTexto(regional) + '</div>' +
            '<div><strong>Centro Zonal / Punto:</strong> ' + escaparTexto(punto) + '</div>' +
            '<div><strong>Tipo:</strong> ' + escaparTexto(tipo) + '</div>' +
            '</div>';
    }

    function confirmarFormulario(form, submitter) {
        if (!swalDisponible()) return Promise.resolve(true);

        var titulo = form.getAttribute('data-ac-swal-title') || '¿Confirma la acción?';
        var texto = form.getAttribute('data-ac-swal-text') || '';
        var confirmar = form.getAttribute('data-ac-swal-confirm-text') || 'Confirmar';
        var icono = form.getAttribute('data-ac-swal-icon') || 'question';
        var html = texto ? '<div>' + escaparTexto(texto) + '</div>' : '';

        if (form.id === 'ac-form-nueva-alerta') {
            html += resumenNuevaAlerta(form);
        }

        return window.Swal.fire({
            icon: icono,
            title: titulo,
            html: html,
            showCancelButton: true,
            confirmButtonText: confirmar,
            cancelButtonText: 'Cancelar',
            reverseButtons: true,
            focusCancel: true,
            buttonsStyling: false,
            customClass: clases(),
            allowOutsideClick: false
        }).then(function (resultado) {
            return !!resultado.isConfirmed;
        });
    }

    function instalarConfirmaciones() {
        document.querySelectorAll('form[data-ac-swal-confirm="1"]').forEach(function (form) {
            form.addEventListener('submit', function (evento) {
                if (form.dataset.acSwalConfirmed === '1') {
                    delete form.dataset.acSwalConfirmed;
                    return;
                }

                var validacion = { valido: true };
                if (form.id === 'ac-form-nueva-alerta') {
                    validacion = validarFormularioNuevaAlerta(form);
                } else if (!form.checkValidity()) {
                    validacion = {
                        valido: false,
                        campo: primerInvalido(form),
                        mensaje: 'Complete la información obligatoria antes de continuar.'
                    };
                }

                if (!validacion.valido) {
                    evento.preventDefault();
                    if (swalDisponible()) {
                        window.Swal.fire({
                            icon: 'error',
                            title: 'Información incompleta',
                            text: validacion.mensaje,
                            confirmButtonText: 'Corregir',
                            buttonsStyling: false,
                            customClass: clases()
                        }).then(function () {
                            if (validacion.campo && typeof validacion.campo.focus === 'function') {
                                validacion.campo.focus();
                            }
                        });
                    } else {
                        window.alert(validacion.mensaje);
                        if (validacion.campo && typeof validacion.campo.focus === 'function') validacion.campo.focus();
                    }
                    return;
                }

                evento.preventDefault();
                var submitter = evento.submitter || form.querySelector('[type="submit"]');
                confirmarFormulario(form, submitter).then(function (confirmado) {
                    if (!confirmado) return;
                    form.dataset.acSwalConfirmed = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submitter || undefined);
                    } else {
                        form.submit();
                    }
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.AC_ALERTAS_CONFIG || {};
        if (!swalDisponible() && !alertifyDisponible()) revelarFallback();
        mostrarErrores(cfg.errores || []);
        mostrarFlash(cfg.flash || null);
        instalarConfirmaciones();
    });
})();
