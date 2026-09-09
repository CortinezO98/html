(function () {
    'use strict';

    var input = document.getElementById('sim_busqueda');
    var hidden = document.getElementById('sim');
    var resultados = document.getElementById('ac-sim-resultados');
    var seleccionado = document.getElementById('ac-sim-seleccionado');
    var seleccionadoTitulo = document.getElementById('ac-sim-seleccionado-titulo');
    var seleccionadoMeta = document.getElementById('ac-sim-seleccionado-meta');
    var limpiar = document.getElementById('ac-sim-limpiar');
    var simNuevo = document.getElementById('ac-sim-nuevo');
    var simNuevoNumero = document.getElementById('ac-sim-nuevo-numero');

    if (!input || !hidden || !resultados) {
        return;
    }

    var endpoint = input.getAttribute('data-endpoint') || 'ajax/radicados_sim.php';
    var timer = null;
    var controller = null;
    var items = [];
    var indiceActivo = -1;

    var camposAutofill = {
        fecha_alerta: document.getElementById('fecha_alerta'),
        fecha_atencion: document.getElementById('fecha_atencion'),
        categoria: document.getElementById('categoria'),
        subcategoria: document.getElementById('subcategoria'),
        afecta_linea_tecnica: document.getElementById('afecta_linea_tecnica'),
        descripcion: document.getElementById('descripcion'),
        justificacion: document.getElementById('justificacion')
    };

    function ocultarResultados() {
        resultados.classList.add('d-none');
        resultados.innerHTML = '';
        items = [];
        indiceActivo = -1;
    }

    function mostrarMensaje(html) {
        resultados.innerHTML = '<div class="ac-sim-picker__empty">' + html + '</div>';
        resultados.classList.remove('d-none');
        items = [];
        indiceActivo = -1;
    }

    function escapar(texto) {
        var div = document.createElement('div');
        div.textContent = texto == null ? '' : String(texto);
        return div.innerHTML;
    }

    function esSimNuevoValido(valor) {
        return /^\d{3,50}$/.test(String(valor || '').trim());
    }

    function ocultarSimNuevo() {
        if (simNuevo) simNuevo.classList.add('d-none');
        if (simNuevoNumero) simNuevoNumero.textContent = '';
    }

    function mostrarSimNuevo(valor) {
        valor = String(valor || '').trim();
        if (!esSimNuevoValido(valor)) {
            ocultarSimNuevo();
            return;
        }
        if (simNuevoNumero) simNuevoNumero.textContent = 'SIM ' + valor;
        if (simNuevo) simNuevo.classList.remove('d-none');
    }

    function fechaInput(valor, conHora) {
        valor = String(valor || '').trim();
        if (!valor) return '';
        if (!conHora) return valor.substring(0, 10);
        // DATETIME MySQL -> datetime-local del navegador.
        var normalizada = valor.replace(' ', 'T');
        return normalizada.length >= 16 ? normalizada.substring(0, 16) : normalizada;
    }

    function normalizarAfecta(valor) {
        var v = String(valor || '').trim().toUpperCase();
        if (v === 'PTE' || v === 'PENDIENTE' || v === 'PENDIENTE POR VALIDAR') return 'PTE';
        if (v === 'SI' || v === 'SÍ' || v === 'YES' || v === '1') return 'SI';
        if (v === 'NO' || v === '0') return 'NO';
        return '';
    }

    function notificarCambio(campo) {
        if (!campo) return;
        try {
            campo.dispatchEvent(new Event('input', {bubbles: true}));
            campo.dispatchEvent(new Event('change', {bubbles: true}));
        } catch (e) {
            // Compatibilidad con navegadores antiguos del entorno corporativo.
            var evt = document.createEvent('Event');
            evt.initEvent('input', true, true);
            campo.dispatchEvent(evt);
        }
    }

    function aplicarAutofill(campo, valor) {
        if (!campo) return;
        valor = valor == null ? '' : String(valor);
        var anteriorAuto = campo.getAttribute('data-ac-sim-autofill');
        var puedeSobrescribir = campo.value === '' || anteriorAuto !== null && campo.value === anteriorAuto;

        if (!puedeSobrescribir || valor === '') {
            return;
        }

        campo.value = valor;
        campo.setAttribute('data-ac-sim-autofill', valor);
        notificarCambio(campo);
    }

    function autocompletarDatos(item) {
        aplicarAutofill(camposAutofill.fecha_alerta, fechaInput(item.fecha_alerta, false));
        aplicarAutofill(camposAutofill.fecha_atencion, fechaInput(item.fecha_atencion || item.fecha_peticion, true));
        aplicarAutofill(camposAutofill.categoria, item.categoria || '');
        aplicarAutofill(camposAutofill.subcategoria, item.subcategoria || '');
        aplicarAutofill(camposAutofill.afecta_linea_tecnica, normalizarAfecta(item.afecta_linea_tecnica));
        aplicarAutofill(camposAutofill.descripcion, item.descripcion || '');
        aplicarAutofill(camposAutofill.justificacion, item.justificacion || '');
    }

    function limpiarDatosAutofill() {
        Object.keys(camposAutofill).forEach(function (key) {
            var campo = camposAutofill[key];
            if (!campo) return;
            var auto = campo.getAttribute('data-ac-sim-autofill');
            if (auto !== null && campo.value === auto) {
                campo.value = '';
                notificarCambio(campo);
            }
            campo.removeAttribute('data-ac-sim-autofill');
        });
    }

    function renderSeleccion(item) {
        if (!seleccionado || !seleccionadoTitulo || !seleccionadoMeta) return;

        seleccionadoTitulo.textContent = 'SIM ' + item.radicado;
        var partes = [];
        if (item.regional_catalogo || item.regional) partes.push(item.regional_catalogo || item.regional);
        if (item.centro_zonal_catalogo || item.centro_zonal) partes.push(item.centro_zonal_catalogo || item.centro_zonal);
        if (item.fecha_atencion || item.fecha_peticion) partes.push('Atención: ' + (item.fecha_atencion || item.fecha_peticion));
        if (item.categoria) partes.push(item.categoria);
        seleccionadoMeta.textContent = partes.join(' · ');
        seleccionado.classList.remove('d-none');
    }

    async function seleccionarItem(item) {
        hidden.value = item.radicado;
        input.value = item.radicado;
        ocultarSimNuevo();
        renderSeleccion(item);
        ocultarResultados();
        autocompletarDatos(item);

        var feedback = document.getElementById('ac-territorio-feedback');
        if (item.territorio_mapeado && window.AlertaCorreosTerritorio && typeof window.AlertaCorreosTerritorio.seleccionar === 'function') {
            await window.AlertaCorreosTerritorio.seleccionar(item.regional_id, item.punto_atencion_id);
        } else {
            // Si al menos la Regional está identificada, la seleccionamos y dejamos que
            // el usuario complete solamente el Centro Zonal cuando la fuente no lo mapea.
            if (item.regional_id > 0 && window.AlertaCorreosTerritorio && typeof window.AlertaCorreosTerritorio.seleccionar === 'function') {
                await window.AlertaCorreosTerritorio.seleccionar(item.regional_id, 0);
            }
            if (feedback) {
                feedback.className = 'small mt-1 text-warning';
                feedback.innerHTML = '<span class="fas fa-exclamation-triangle mr-1"></span>' +
                    escapar(item.territorio_mensaje || 'El SIM no tiene un Centro Zonal mapeado.') +
                    ' Seleccione el punto de atención manualmente.';
            }
        }
    }

    function renderResultados(data) {
        items = data || [];
        indiceActivo = -1;

        if (!items.length) {
            var q = input.value.trim();
            if (esSimNuevoValido(q)) {
                mostrarSimNuevo(q);
                mostrarMensaje('<strong>SIM no registrado.</strong> Puede continuar llenando este formulario. Al guardar la alerta, el SIM se agregará automáticamente a Fuente SIM con la Regional, Centro Zonal y demás datos diligenciados.');
            } else {
                ocultarSimNuevo();
                mostrarMensaje('No se encontraron radicados SIM con ese criterio.');
            }
            return;
        }

        ocultarSimNuevo();

        resultados.innerHTML = '';
        items.forEach(function (item, index) {
            var boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'ac-sim-picker__result';
            boton.setAttribute('data-index', String(index));
            boton.setAttribute('role', 'option');

            var territorio = [];
            if (item.regional_catalogo || item.regional) territorio.push(item.regional_catalogo || item.regional);
            if (item.centro_zonal_catalogo || item.centro_zonal) territorio.push(item.centro_zonal_catalogo || item.centro_zonal);
            if (item.fecha_alerta) territorio.push('Alerta: ' + item.fecha_alerta);
            if (item.categoria) territorio.push(item.categoria);

            boton.innerHTML = '<span class="ac-sim-picker__number">' + escapar(item.radicado) + '</span>' +
                '<span class="ac-sim-picker__meta">' + escapar(territorio.join(' · ')) + '</span>';
            boton.addEventListener('click', function () {
                seleccionarItem(item);
            });
            resultados.appendChild(boton);
        });
        resultados.classList.remove('d-none');
    }

    async function buscar() {
        var q = input.value.trim();
        if (q.length < 3) {
            ocultarResultados();
            ocultarSimNuevo();
            return;
        }

        if (controller) controller.abort();
        controller = new AbortController();
        mostrarMensaje('<span class="fas fa-spinner fa-spin mr-1"></span>Consultando radicados SIM...');

        try {
            var response = await fetch(endpoint + '?q=' + encodeURIComponent(q), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                signal: controller.signal
            });
            var payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.mensaje || 'No fue posible consultar los radicados SIM.');
            }
            renderResultados(payload.data || []);
        } catch (error) {
            if (error.name === 'AbortError') return;
            mostrarMensaje(escapar(error.message));
        }
    }

    function actualizarActivo(nuevoIndice) {
        var botones = resultados.querySelectorAll('.ac-sim-picker__result');
        if (!botones.length) return;
        indiceActivo = Math.max(0, Math.min(nuevoIndice, botones.length - 1));
        botones.forEach(function (boton, i) {
            boton.classList.toggle('is-active', i === indiceActivo);
        });
        botones[indiceActivo].scrollIntoView({block: 'nearest'});
    }

    input.addEventListener('input', function () {
        // Si el usuario cambia el número luego de seleccionar, ya no mantenemos
        // silenciosamente un SIM anterior en el hidden.
        if (hidden.value && input.value.trim() !== hidden.value) {
            hidden.value = '';
            if (seleccionado) seleccionado.classList.add('d-none');
        }
        ocultarSimNuevo();
        window.clearTimeout(timer);
        timer = window.setTimeout(buscar, 250);
    });

    input.addEventListener('keydown', function (event) {
        if (resultados.classList.contains('d-none')) return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            actualizarActivo(indiceActivo + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            actualizarActivo(indiceActivo <= 0 ? 0 : indiceActivo - 1);
        } else if (event.key === 'Enter' && indiceActivo >= 0 && items[indiceActivo]) {
            event.preventDefault();
            seleccionarItem(items[indiceActivo]);
        } else if (event.key === 'Escape') {
            ocultarResultados();
        }
    });

    input.addEventListener('focus', function () {
        if (input.value.trim().length >= 3 && !hidden.value) {
            buscar();
        }
    });

    document.addEventListener('click', function (event) {
        if (!resultados.contains(event.target) && event.target !== input) {
            ocultarResultados();
        }
    });

    if (limpiar) {
        limpiar.addEventListener('click', function () {
            hidden.value = '';
            input.value = '';
            if (seleccionado) seleccionado.classList.add('d-none');
            ocultarResultados();
            ocultarSimNuevo();
            limpiarDatosAutofill();
            input.focus();
        });
    }

    // Si el servidor re-renderiza el formulario después de una validación,
    // conserva el número seleccionado sin forzar una nueva selección.
    if (hidden.value) {
        input.value = hidden.value;
    }
}());
