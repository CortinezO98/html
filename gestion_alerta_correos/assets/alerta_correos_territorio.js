(function () {
    'use strict';

    var regional = document.getElementById('regional_id');
    var punto = document.getElementById('punto_atencion_id');
    var feedback = document.getElementById('ac-territorio-feedback');

    if (!regional || !punto) {
        return;
    }

    var endpoint = punto.getAttribute('data-endpoint') || 'ajax/centros_zonales_por_regional.php';
    var valorInicial = punto.getAttribute('data-selected') || '';

    function setFeedback(texto, tipo) {
        if (!feedback) return;
        feedback.className = 'small mt-1 ' + (tipo === 'ok' ? 'text-success' : (tipo === 'error' ? 'text-danger' : (tipo === 'warning' ? 'text-warning' : 'text-muted')));
        feedback.innerHTML = texto;
    }

    function resetPunto(texto) {
        punto.innerHTML = '';
        var option = document.createElement('option');
        option.value = '';
        option.textContent = texto;
        punto.appendChild(option);
        punto.disabled = true;
    }

    async function cargarPuntos(seleccionarId) {
        var regionalId = regional.value;
        var idObjetivo = seleccionarId != null && String(seleccionarId) !== '' ? String(seleccionarId) : String(valorInicial || '');

        if (!regionalId) {
            resetPunto('Seleccione primero una regional');
            setFeedback('Seleccione una regional para consultar sus puntos de atención.', 'muted');
            return false;
        }

        resetPunto('Consultando puntos de atención...');
        setFeedback('<span class="fas fa-spinner fa-spin mr-1"></span>Consultando catálogo territorial...', 'muted');

        try {
            var response = await fetch(endpoint + '?regional_id=' + encodeURIComponent(regionalId), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'}
            });
            var payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.mensaje || 'No fue posible consultar los puntos.');
            }

            punto.innerHTML = '';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = payload.data.length ? 'Seleccione un Centro Zonal / Punto de atención' : 'No hay puntos activos para esta regional';
            punto.appendChild(placeholder);

            var encontrado = false;
            payload.data.forEach(function (item) {
                var option = document.createElement('option');
                option.value = String(item.acp_id);
                option.textContent = item.acp_codigo ? item.acp_nombre + ' · ' + item.acp_codigo : item.acp_nombre;
                if (idObjetivo && String(item.acp_id) === idObjetivo) {
                    option.selected = true;
                    encontrado = true;
                }
                punto.appendChild(option);
            });

            punto.disabled = payload.data.length === 0;
            valorInicial = '';

            if (payload.data.length) {
                if (idObjetivo && encontrado) {
                    setFeedback('<span class="fas fa-check-circle mr-1"></span>Ubicación territorial cargada automáticamente desde el radicado SIM.', 'ok');
                } else {
                    setFeedback('<span class="fas fa-check-circle mr-1"></span>' + payload.data.length + ' punto(s) de atención disponible(s).', 'ok');
                }
            } else {
                setFeedback('La regional seleccionada no tiene puntos activos en el catálogo.', 'error');
            }
            return encontrado || !idObjetivo;
        } catch (error) {
            resetPunto('No fue posible cargar los puntos');
            setFeedback('<span class="fas fa-exclamation-circle mr-1"></span>' + error.message, 'error');
            return false;
        }
    }

    regional.addEventListener('change', function () {
        valorInicial = '';
        cargarPuntos('');
    });

    punto.addEventListener('change', function () {
        if (punto.value) {
            setFeedback('<span class="fas fa-check-circle mr-1"></span>Punto de atención seleccionado y validado contra la regional.', 'ok');
        }
    });

    window.AlertaCorreosTerritorio = {
        seleccionar: async function (regionalId, puntoId) {
            regional.value = String(regionalId || '');
            valorInicial = String(puntoId || '');
            return cargarPuntos(valorInicial);
        },
        cargarPuntos: cargarPuntos,
        limpiar: function () {
            regional.value = '';
            valorInicial = '';
            resetPunto('Seleccione primero una regional');
            setFeedback('Seleccione una regional para consultar sus puntos de atención.', 'muted');
        }
    };

    if (regional.value) {
        cargarPuntos(valorInicial);
    } else {
        resetPunto('Seleccione primero una regional');
    }
}());
