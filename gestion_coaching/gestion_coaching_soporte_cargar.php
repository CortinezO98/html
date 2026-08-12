<?php
    $modulo_plataforma = 'Coaching';
    require_once('../config/validaciones_seguridad.php');
    require_once('../config/conexion_db.php');
    require_once('lib/coaching_seguridad.php');
    require_once('lib/coaching_complementos.php');

    $perfil = coachingPerfilUsuarioActual();
    $gcp_id = validar_input(base64_decode($_GET['reg'] ?? ''));

    if (!$perfil || !usuarioPuedeVerPaquete($enlace_db, $_SESSION['usu_id'], $perfil, $gcp_id)) {
        header('Location:../permiso_denegado.php');
        exit;
    }

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    $mensaje = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['_csrf_token']) || !hash_equals($_SESSION['_csrf_token'], $_POST['_csrf_token'])) {
            $mensaje = "<div class='coaching_aviso_error'><span class='fas fa-exclamation-circle'></span> Solicitud inválida (CSRF). Recargue e intente de nuevo.</div>";
        } else {
            try {
                $resultado = guardarSoportesMultiples($enlace_db, $gcp_id, $_FILES['soporte'] ?? [], trim($_POST['tipo_documental'] ?? 'Evidencia'), $_SESSION['usu_id']);

                if (count($resultado['fallidos']) === 0) {
                    // Todo salió bien: mismo comportamiento de siempre, redirige al detalle.
                    header('Location:gestion_coaching_ver.php?reg=' . base64_encode($gcp_id) . '&sop_subido=' . $resultado['exitosos']);
                    exit;
                }

                // Éxito parcial o total fallo: se queda en esta pantalla mostrando
                // exactamente cuáles archivos sí quedaron y cuáles no (y por qué),
                // para que el usuario solo tenga que reintentar los que fallaron.
                $partes_mensaje = [];
                if ($resultado['exitosos'] > 0) {
                    $partes_mensaje[] = "<div class='coaching_aviso_ok'><span class='fas fa-check-circle'></span> " . $resultado['exitosos'] . " archivo(s) cargado(s) correctamente.</div>";
                }
                $lista_fallidos = '<ul style="margin:6px 0 0 18px; padding:0;">';
                foreach ($resultado['fallidos'] as $f) {
                    $lista_fallidos .= '<li>' . coachingEsc($f['nombre']) . ': ' . coachingEsc($f['motivo']) . '</li>';
                }
                $lista_fallidos .= '</ul>';
                $partes_mensaje[] = "<div class='coaching_aviso_error'><span class='fas fa-exclamation-circle'></span> " . count($resultado['fallidos']) . " archivo(s) no se pudieron cargar:" . $lista_fallidos . "</div>";
                $mensaje = implode('', $partes_mensaje);
            } catch (Throwable $e) {
                $mensaje = "<div class='coaching_aviso_error'><span class='fas fa-exclamation-circle'></span> " . coachingEsc($e->getMessage()) . "</div>";
            }
        }
    }

    $titulo_header = 'Coaching | Adjuntar soporte';
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include('../config/configuracion_estilos.php'); ?>
    <style>
        .coaching_breadcrumb { font-size: 11px; color: #6E6E6E; margin-bottom: 10px; }
        .coaching_breadcrumb a { color: #4CAF50; }
        .coaching_aviso_error { background: #FDEDED; border: 1px solid #FF0000; color: #FF0000; border-radius: 5px; padding: 10px 12px; font-size: 12px; margin-bottom: 14px; }
        .coaching_aviso_ok { background: #EAF7EF; border: 1px solid #4CAF50; color: #1A7A3C; border-radius: 5px; padding: 10px 12px; font-size: 12px; margin-bottom: 14px; }
        label.coaching_label { font-weight: bold; font-size: 12px; margin-bottom: 6px; display: block; color: #1A1A1A; }
        label.coaching_label .opcional { font-weight: normal; color: #6E6E6E; font-size: 10px; }

        .coaching_dropzone {
            border: 2px dashed #F2F2F2; border-radius: 8px; padding: 30px 20px; text-align: center;
            cursor: pointer; transition: border-color .15s, background .15s; background: #FFFFFF;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
        }
        .coaching_dropzone:hover, .coaching_dropzone.arrastrando { border-color: #4CAF50; background: #F1F8F2; }
        .coaching_dropzone .icono { font-size: 34px; color: #B0B4B8; margin-bottom: 10px; }
        .coaching_dropzone.arrastrando .icono, .coaching_dropzone:hover .icono { color: #4CAF50; }
        .coaching_dropzone .texto_principal { font-size: 13px; color: #1A1A1A; font-weight: bold; }
        .coaching_dropzone .texto_secundario { font-size: 11px; color: #6E6E6E; margin-top: 4px; }
        .coaching_dropzone input[type="file"] { display: none; }

        .coaching_lista_archivos { margin-top: 12px; display: flex; flex-direction: column; gap: 8px; }
        .coaching_archivo_elegido {
            display: flex; align-items: center; gap: 10px; background: #F1F8F2; border: 1px solid #4CAF50;
            border-radius: 6px; padding: 10px 12px;
        }
        .coaching_archivo_elegido .icono { font-size: 20px; color: #4CAF50; }
        .coaching_archivo_elegido .info { flex: 1; min-width: 0; }
        .coaching_archivo_elegido .nombre { font-size: 12px; font-weight: bold; color: #1A1A1A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .coaching_archivo_elegido .tamano { font-size: 10px; color: #6E6E6E; }
        .coaching_archivo_elegido .quitar { background: none; border: none; color: #6E6E6E; cursor: pointer; font-size: 14px; }
        .coaching_archivo_elegido .quitar:hover { color: #FF0000; }

        .coaching_contador_archivos { font-size: 10px; color: #6E6E6E; margin-top: 6px; text-align: right; }
        .coaching_contador_archivos.limite { color: #FF0000; font-weight: bold; }

        #btn_cargar[disabled] { opacity: .7; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php include('../menu_principal.php'); include('../menu_header.php'); ?>
    <div class="contenido">
        <nav class="coaching_breadcrumb">
            <a href="gestion_coaching.php?pagina=1&id=null&est=Pendientes">Coaching</a>
            <span class="mx-1">/</span>
            <a href="gestion_coaching_ver.php?reg=<?php echo base64_encode($gcp_id); ?>"><?php echo htmlspecialchars($gcp_id); ?></a>
            <span class="mx-1">/</span>
            <span>Adjuntar soporte</span>
        </nav>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="text-center mb-3">
                    <h4 class="titulo_seccion mb-0">Adjuntar soporte</h4>
                    <span class="descripcion-seccion-conocimiento">Paquete <?php echo htmlspecialchars($gcp_id); ?></span>
                </div>

                <div class="cuadro_dash">
                    <div class="cuadro_dash_titulo p-2"><span class="fas fa-paperclip"></span> Adjuntar soporte</div>
                    <div class="p-3">
                        <?php echo $mensaje; ?>
                        <form method="post" enctype="multipart/form-data" id="form_soporte">
                            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token']); ?>">

                            <label class="coaching_label" for="tipo_documental">Tipo documental</label>
                            <select class="form-control mb-3" name="tipo_documental" id="tipo_documental">
                                <option>Evidencia</option>
                                <option>Taller</option>
                                <option>Compromiso</option>
                                <option>Seguimiento</option>
                                <option>Reconocimiento</option>
                            </select>

                            <label class="coaching_label">Archivos <span class="opcional">(máximo 10 MB c/u, hasta 10 archivos — pdf, doc, docx, xls, xlsx, jpg, png)</span></label>

                            <label class="coaching_dropzone" id="dropzone" for="soporte">
                                <div class="icono"><span class="fas fa-cloud-upload-alt"></span></div>
                                <div class="texto_principal">Arrastre sus archivos aquí, o haga clic para seleccionar</div>
                                <div class="texto_secundario">Puede elegir varios a la vez — PDF, Word, Excel o imagen</div>
                                <input type="file" name="soporte[]" id="soporte" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" multiple required>
                            </label>

                            <div class="coaching_lista_archivos" id="lista_archivos"></div>
                            <div class="coaching_contador_archivos" id="contador_archivos"></div>

                            <div class="mt-4" style="display:flex; justify-content:center; align-items:center; gap:10px;">
                                <button type="submit" id="btn_cargar" class="btn-corp px-4 py-2" style="border-radius:5px; border:0;">
                                    <span class="fas fa-upload"></span> Cargar
                                </button>
                                <a href="gestion_coaching_ver.php?reg=<?php echo base64_encode($gcp_id); ?>" class="btn-corp-2 px-4 py-2 d-inline-block" style="border-radius:5px;">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var LIMITE_ARCHIVOS = <?php echo (int) COACHING_SOPORTES_MAX_POR_CARGA; ?>;

            var dropzone = document.getElementById('dropzone');
            var inputArchivo = document.getElementById('soporte');
            var listaArchivos = document.getElementById('lista_archivos');
            var contadorArchivos = document.getElementById('contador_archivos');
            var textoPrincipal = dropzone.querySelector('.texto_principal');
            var textoSecundario = dropzone.querySelector('.texto_secundario');

            var ICONOS = {
                pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word',
                xls: 'fa-file-excel', xlsx: 'fa-file-excel',
                jpg: 'fa-file-image', jpeg: 'fa-file-image', png: 'fa-file-image'
            };

            // Los <input type="file"> nativos no permiten quitar UN solo
            // archivo de la selección — se mantiene la lista real aquí (en
            // JS) y se reconstruye el input completo con DataTransfer cada
            // vez que cambia, en vez de depender de la FileList original
            // (que es de solo lectura).
            var archivosElegidos = [];

            function formatearTamano(bytes) {
                if (bytes < 1024) { return bytes + ' B'; }
                if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(0) + ' KB'; }
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }

            function sincronizarInput() {
                var dt = new DataTransfer();
                archivosElegidos.forEach(function (archivo) { dt.items.add(archivo); });
                inputArchivo.files = dt.files;
            }

            function renderizarLista() {
                listaArchivos.innerHTML = '';
                archivosElegidos.forEach(function (archivo, indice) {
                    var ext = archivo.name.split('.').pop().toLowerCase();
                    var fila = document.createElement('div');
                    fila.className = 'coaching_archivo_elegido';
                    fila.innerHTML =
                        '<span class="icono fas ' + (ICONOS[ext] || 'fa-file') + '"></span>' +
                        '<div class="info">' +
                            '<div class="nombre"></div>' +
                            '<div class="tamano">' + formatearTamano(archivo.size) + '</div>' +
                        '</div>' +
                        '<button type="button" class="quitar" title="Quitar"><span class="fas fa-times-circle"></span></button>';
                    fila.querySelector('.nombre').textContent = archivo.name;
                    fila.querySelector('.quitar').addEventListener('click', function () {
                        archivosElegidos.splice(indice, 1);
                        sincronizarInput();
                        renderizarLista();
                    });
                    listaArchivos.appendChild(fila);
                });

                if (archivosElegidos.length > 0) {
                    textoPrincipal.textContent = 'Archivos listos — haga clic para agregar más';
                    textoSecundario.textContent = archivosElegidos.length + ' archivo(s) seleccionado(s)';
                    contadorArchivos.textContent = archivosElegidos.length + ' / ' + LIMITE_ARCHIVOS + ' archivo(s)';
                    contadorArchivos.classList.toggle('limite', archivosElegidos.length >= LIMITE_ARCHIVOS);
                } else {
                    textoPrincipal.textContent = 'Arrastre sus archivos aquí, o haga clic para seleccionar';
                    textoSecundario.textContent = 'Puede elegir varios a la vez — PDF, Word, Excel o imagen';
                    contadorArchivos.textContent = '';
                    contadorArchivos.classList.remove('limite');
                }
            }

            function agregarArchivos(nuevaLista) {
                for (var i = 0; i < nuevaLista.length; i++) {
                    if (archivosElegidos.length >= LIMITE_ARCHIVOS) {
                        if (window.alertify) { alertify.warning('Máximo ' + LIMITE_ARCHIVOS + ' archivos por carga.', 0); }
                        break;
                    }
                    // Evita duplicar el mismo archivo (mismo nombre+tamaño) si
                    // el usuario abre el selector varias veces por error.
                    var archivo = nuevaLista[i];
                    var yaExiste = archivosElegidos.some(function (a) {
                        return a.name === archivo.name && a.size === archivo.size;
                    });
                    if (!yaExiste) { archivosElegidos.push(archivo); }
                }
                sincronizarInput();
                renderizarLista();
            }

            inputArchivo.addEventListener('change', function () {
                agregarArchivos(inputArchivo.files);
            });

            ['dragenter', 'dragover'].forEach(function (evento) {
                dropzone.addEventListener(evento, function (e) {
                    e.preventDefault(); e.stopPropagation();
                    dropzone.classList.add('arrastrando');
                });
            });
            ['dragleave', 'drop'].forEach(function (evento) {
                dropzone.addEventListener(evento, function (e) {
                    e.preventDefault(); e.stopPropagation();
                    dropzone.classList.remove('arrastrando');
                });
            });
            dropzone.addEventListener('drop', function (e) {
                if (e.dataTransfer.files.length > 0) { agregarArchivos(e.dataTransfer.files); }
            });

            var form = document.getElementById('form_soporte');
            var boton = document.getElementById('btn_cargar');
            form.addEventListener('submit', function (e) {
                if (archivosElegidos.length === 0) {
                    e.preventDefault();
                    if (window.alertify) { alertify.warning('Seleccione al menos un archivo.', 0); }
                    return;
                }
                setTimeout(function () {
                    boton.disabled = true;
                    boton.innerHTML = '<span class="fas fa-spinner fa-spin"></span> Cargando...';
                }, 0);
            });
        })();
        </script>
    </div>
    <?php include('../footer.php'); ?>
</body>
</html>
