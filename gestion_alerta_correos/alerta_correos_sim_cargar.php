<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_territorio.php';
require_once __DIR__ . '/lib/alerta_correos_importacion_versionada.php';
require_once __DIR__ . '/lib/alerta_correos_sim_fuente.php';

acExigirPerfil(['Usuario', 'Supervisor', 'Administrador']);

$titulo_header = 'Alertas Correos | Actualizar radicados SIM de referencia';
$error = null;
$preview = null;
$resultado = null;
$tokenPreview = '';

if (!isset($_SESSION['ac_sim_source_sync']) || !is_array($_SESSION['ac_sim_source_sync'])) {
    $_SESSION['ac_sim_source_sync'] = [];
}
foreach ($_SESSION['ac_sim_source_sync'] as $token => $item) {
    if (!is_array($item) || (int)($item['expires'] ?? 0) < time()) {
        unset($_SESSION['ac_sim_source_sync'][$token]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        acValidarCsrfPost();
        $accion = (string)($_POST['accion'] ?? 'analizar');

        if ($accion === 'analizar') {
            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Seleccione el archivo de alertas SIM para notificar a territorio.');
            }
            $nombre = (string)$_FILES['archivo']['name'];
            $tmp = (string)$_FILES['archivo']['tmp_name'];
            if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('El archivo de radicados SIM de referencia debe estar en formato XLSX.');
            }
            if ((int)$_FILES['archivo']['size'] > 20 * 1024 * 1024) {
                throw new RuntimeException('El archivo supera el límite de 20 MB.');
            }
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('El archivo recibido no es válido.');
            }

            $preview = acSimFuentePreparar($enlace_db, $tmp, $nombre);
            $tokenPreview = bin2hex(random_bytes(24));
            $_SESSION['ac_sim_source_sync'][$tokenPreview] = [
                'expires' => time() + 1800,
                'preview' => $preview,
            ];
        } elseif ($accion === 'confirmar') {
            $tokenPreview = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token_preview'] ?? '')) ?? '';
            $item = $_SESSION['ac_sim_source_sync'][$tokenPreview] ?? null;
            if (!$item || (int)($item['expires'] ?? 0) < time()) {
                throw new RuntimeException('La previsualización venció. Vuelva a analizar el archivo.');
            }
            $preview = $item['preview'];
            if ((int)($preview['resumen']['invalidos'] ?? 0) > 0) {
                throw new RuntimeException('La carga contiene filas inválidas. Corrija el archivo antes de confirmar.');
            }

            $enlace_db->begin_transaction();
            try {
                $resultado = acSimFuenteAplicar($enlace_db, $preview, acUsuarioActual());

                $tipo = 'RADICADOS_SIM_ALERTAS_CORREOS';
                $estado = 'COMPLETADA';
                $nombre = (string)$preview['nombre_archivo'];
                $sha = (string)$preview['sha256'];
                $total = count($preview['registros']);
                $validos = $total;
                $invalidos = 0;
                $usuario = acUsuarioActual();
                $detalle = 'Nuevos=' . $resultado['nuevos']
                    . '; Actualizados=' . $resultado['actualizados']
                    . '; Sin cambios=' . $resultado['sin_cambios']
                    . '; No en archivo=' . $resultado['no_en_archivo']
                    . '; Mapeados=' . (int)$preview['resumen']['mapeados']
                    . '; Regional solamente=' . (int)$preview['resumen']['regional_solamente']
                    . '; No mapeados=' . (int)$preview['resumen']['no_mapeados'];

                $stmt = $enlace_db->prepare(
                    'INSERT INTO tb_alerta_correo_carga
                    (acg_tipo, acg_archivo_nombre, acg_archivo_sha256, acg_total_registros,
                     acg_registros_validos, acg_registros_invalidos, acg_estado, acg_detalle_error, acg_usuario)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $stmt->bind_param('sssiiisss', $tipo, $nombre, $sha, $total, $validos, $invalidos, $estado, $detalle, $usuario);
                $stmt->execute();
                $stmt->close();

                $enlace_db->commit();
                unset($_SESSION['ac_sim_source_sync'][$tokenPreview]);
                $preview = null;
                $tokenPreview = '';
            } catch (Throwable $e) {
                $enlace_db->rollback();
                throw $e;
            }
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (Throwable $e) {
        error_log('Alertas Correos / fuente SIM: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260909">
</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>

<div class="contenido ac-module ac-module--footer-safe">
    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a><span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a><span class="ac-separator">/</span>
        <span>Radicados SIM de referencia</span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title"><span class="fas fa-database"></span> Actualizar radicados SIM de referencia</h1>
            <p class="ac-page-subtitle">Sincroniza el catálogo que sirve como referencia en la captura manual. <strong>No crea casos de alerta y no envía correos.</strong> La información se conserva separada del módulo de Encuestas.</p>
        </div>
        <div class="ac-page-header__actions">
            <a href="alerta_correos_sim.php" class="btn ac-btn-green-outline"><span class="fas fa-list"></span> Ver radicados SIM</a>
            <a href="alerta_correos.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver</a>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="alert alert-danger"><span class="fas fa-exclamation-circle mr-1"></span><?php echo acEscape($error); ?></div>
    <?php endif; ?>

    <?php if ($resultado): ?>
        <div class="alert alert-success">
            <strong><span class="fas fa-check-circle mr-1"></span> Radicados SIM de referencia actualizados correctamente.</strong>
            <div class="row mt-2">
                <div class="col-6 col-md-3"><strong>Nuevos:</strong> <?php echo (int)$resultado['nuevos']; ?></div>
                <div class="col-6 col-md-3"><strong>Actualizados:</strong> <?php echo (int)$resultado['actualizados']; ?></div>
                <div class="col-6 col-md-3"><strong>Sin cambios:</strong> <?php echo (int)$resultado['sin_cambios']; ?></div>
                <div class="col-6 col-md-3"><strong>No encontrados:</strong> <?php echo (int)$resultado['no_en_archivo']; ?></div>
            </div>
            <small class="d-block mt-2">Los SIM ausentes en una carga posterior no se eliminan ni se inactivan automáticamente.</small>
        </div>
    <?php endif; ?>

    <?php if ($preview): $r = $preview['resumen']; ?>
        <section class="ac-panel mb-3">
            <div class="ac-panel__header">
                <h2 class="ac-panel__title"><span class="fas fa-search"></span> Previsualización</h2>
                <span class="ac-panel__meta"><?php echo acEscape((string)$preview['nombre_archivo']); ?></span>
            </div>
            <div class="ac-panel__body">
                <div class="ac-kpis-inline mb-3">
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Nuevos</span><span class="ac-kpi-inline__value"><?php echo (int)$r['nuevos']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Cambios</span><span class="ac-kpi-inline__value"><?php echo (int)$r['actualizados']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Sin cambios</span><span class="ac-kpi-inline__value"><?php echo (int)$r['sin_cambios']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Mapeados a CZ</span><span class="ac-kpi-inline__value"><?php echo (int)$r['mapeados']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Solo Regional</span><span class="ac-kpi-inline__value"><?php echo (int)$r['regional_solamente']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Sin mapear</span><span class="ac-kpi-inline__value"><?php echo (int)$r['no_mapeados']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Inválidos</span><span class="ac-kpi-inline__value"><?php echo (int)$r['invalidos']; ?></span></div>
                </div>

                <div class="ac-alert-box ac-alert-box--info mb-3">
                    <strong>Importante:</strong> un SIM sin mapeo territorial también se conserva. En Nueva alerta el sistema lo mostrará con advertencia y permitirá seleccionar la ubicación manualmente.
                </div>

                <div class="alert alert-warning mb-3">
                    <span class="fas fa-eye mr-1"></span>
                    <strong>Vista previa únicamente.</strong> En este momento no se ha insertado ni actualizado ningún registro en la base de datos. Revise la información y utilice <strong>Confirmar carga</strong> solamente cuando esté conforme.
                </div>

                <details class="mb-3" open>
                    <summary class="font-weight-bold" style="cursor:pointer;">Vista previa de registros del archivo</summary>
                    <div class="ac-table-wrap mt-2">
                        <table class="table table-sm table-hover ac-table">
                            <thead>
                                <tr>
                                    <th>SIM</th>
                                    <th>Regional</th>
                                    <th>Punto de atención</th>
                                    <th>Categoría</th>
                                    <th>Fecha alerta</th>
                                    <th>Mapeo</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_slice($preview['registros'], 0, 100) as $row): ?>
                                <tr>
                                    <td><?php echo acEscape((string)$row['sim']); ?></td>
                                    <td><?php echo acEscape((string)$row['regional']); ?></td>
                                    <td><?php echo acEscape((string)$row['punto_atencion']); ?></td>
                                    <td><?php echo acEscape((string)$row['categoria']); ?></td>
                                    <td><?php echo acEscape((string)($row['fecha_alerta'] ?? '')); ?></td>
                                    <td><?php echo acEscape((string)$row['mapeo_estado']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($preview['registros']) > 100): ?>
                        <small class="text-muted">Se muestran los primeros 100 de <?php echo count($preview['registros']); ?> registros. El resumen superior corresponde al archivo completo.</small>
                    <?php endif; ?>
                </details>

                <?php if ($preview['errores']): ?>
                    <div class="alert alert-danger">
                        <strong>Filas inválidas:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach (array_slice($preview['errores'], 0, 30) as $e): ?><li><?php echo acEscape($e); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ((int)$r['regional_solamente'] > 0 || (int)$r['no_mapeados'] > 0): ?>
                    <details class="mb-3">
                        <summary class="font-weight-bold" style="cursor:pointer;">Ver SIM pendientes de Centro Zonal</summary>
                        <div class="ac-table-wrap mt-2">
                            <table class="table table-sm table-hover ac-table">
                                <thead><tr><th>SIM</th><th>Regional</th><th>Punto fuente</th><th>Estado mapeo</th></tr></thead>
                                <tbody>
                                <?php $shown = 0; foreach ($preview['registros'] as $row): if ($row['mapeo_estado'] === 'MAPEADO') continue; if ($shown++ >= 100) break; ?>
                                    <tr>
                                        <td><?php echo acEscape((string)$row['sim']); ?></td>
                                        <td><?php echo acEscape((string)$row['regional']); ?></td>
                                        <td><?php echo acEscape((string)$row['punto_atencion']); ?></td>
                                        <td><?php echo acEscape((string)$row['mapeo_estado']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>

                <div class="ac-actions-bar ac-actions-bar--center">
                    <a href="alerta_correos_sim.php" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a>
                    <form method="post" class="d-inline" data-ac-lock-submit="1" onsubmit="return confirm('¿Confirma la sincronización de la fuente de radicados SIM?');">
                        <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                        <input type="hidden" name="accion" value="confirmar">
                        <input type="hidden" name="token_preview" value="<?php echo acEscape($tokenPreview); ?>">
                        <button class="btn ac-btn-green-outline" type="submit" <?php echo (int)$r['invalidos'] > 0 ? 'disabled' : ''; ?>><span class="fas fa-check"></span> Confirmar carga</button>
                    </form>
                </div>
            </div>
        </section>
    <?php else: ?>
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <section class="ac-panel">
                    <div class="ac-panel__header">
                        <h2 class="ac-panel__title"><span class="fas fa-file-excel"></span> Seleccione la base de alertas</h2>
                        <span class="ac-panel__meta">XLSX · máximo 20 MB</span>
                    </div>
                    <div class="ac-panel__body">
                        <div class="ac-alert-box ac-alert-box--info mb-3">
                            <div class="row align-items-center">
                                <div class="col-12 col-md">
                                    <strong>Archivo que debe cargar:</strong> <code>BBDD encuestas para notificar a territorio.xlsx</code><br>
                                    <small>Debe contener la hoja <strong>Base de alertas</strong> y, como mínimo, las columnas SIM, REGIONAL y PUNTO DE ATENCIÓN.</small>
                                </div>
                                <div class="col-12 col-md-auto mt-2 mt-md-0">
                                    <a href="plantillas/Plantilla_Ejemplo_Fuente_SIM.xlsx" class="btn ac-btn-blue-outline" download>
                                        <span class="fas fa-download"></span> Descargar archivo de ejemplo
                                    </a>
                                </div>
                            </div>
                        </div>
                        <form method="post" enctype="multipart/form-data" data-ac-lock-submit="1">
                            <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                            <input type="hidden" name="accion" value="analizar">
                            <div class="ac-upload-zone">
                                <div class="ac-upload-zone__icon"><span class="fas fa-file-excel"></span></div>
                                <div class="ac-upload-zone__title">BBDD encuestas para notificar a territorio.xlsx</div>
                                <div class="ac-upload-zone__text">Se importan los SIM, fechas, Regional, punto, categoría, descripción, línea técnica, justificación y subcategoría.</div>
                                <label for="archivo" class="btn ac-btn-green-outline mb-0"><span class="fas fa-folder-open"></span> Seleccionar archivo</label>
                                <input id="archivo" type="file" name="archivo" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="d-none" data-ac-file-input>
                                <div class="ac-upload-file-name" data-ac-file-name>Ningún archivo seleccionado</div>
                            </div>
                            <div class="ac-actions-bar ac-actions-bar--center">
                                <a href="alerta_correos_sim.php" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a>
                                <button class="btn ac-btn-green-outline" type="submit"><span class="fas fa-search"></span> Analizar archivo</button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
<script src="assets/alerta_correos.js?v=20260909"></script>
</body>
</html>

