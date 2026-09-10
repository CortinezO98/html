<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_territorio.php';
require_once __DIR__ . '/lib/alerta_correos_importacion_versionada.php';

acExigirPerfil(['Administrador']);

$titulo_header = 'Alertas Correos | Responsables territoriales';
$error = null;
$preview = null;
$resultado = null;
$tokenPreview = '';

if (!isset($_SESSION['ac_resp_sync']) || !is_array($_SESSION['ac_resp_sync'])) {
    $_SESSION['ac_resp_sync'] = [];
}

// Limpieza de previews vencidos.
foreach ($_SESSION['ac_resp_sync'] as $token => $item) {
    if (!is_array($item) || (int)($item['expires'] ?? 0) < time()) {
        unset($_SESSION['ac_resp_sync'][$token]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        acValidarCsrfPost();
        $accion = (string)($_POST['accion'] ?? 'analizar');

        if ($accion === 'analizar') {
            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Seleccione un archivo válido.');
            }

            $nombreArchivo = (string)$_FILES['archivo']['name'];
            $rutaTemporal = (string)$_FILES['archivo']['tmp_name'];
            $extension = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));

            if (!in_array($extension, ['csv', 'xlsx'], true)) {
                throw new RuntimeException('Solo se permiten archivos CSV o XLSX.');
            }
            if ((int)$_FILES['archivo']['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('El archivo supera el límite de 10 MB.');
            }
            if (!is_uploaded_file($rutaTemporal)) {
                throw new RuntimeException('El archivo recibido no es válido.');
            }

            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($rutaTemporal);
                $permitidos = [
                    'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip', 'application/octet-stream',
                ];
                if ($mime !== '' && !in_array($mime, $permitidos, true)) {
                    throw new RuntimeException('El tipo real del archivo no corresponde a CSV/XLSX.');
                }
            }

            $preview = acPrepararSincronizacionResponsables($enlace_db, $rutaTemporal, $nombreArchivo);
            $tokenPreview = bin2hex(random_bytes(24));
            $_SESSION['ac_resp_sync'][$tokenPreview] = [
                'expires' => time() + 1800,
                'preview' => $preview,
            ];
        } elseif ($accion === 'confirmar') {
            $tokenPreview = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token_preview'] ?? '')) ?? '';
            $sessionPreview = $_SESSION['ac_resp_sync'][$tokenPreview] ?? null;
            if (!$sessionPreview || (int)($sessionPreview['expires'] ?? 0) < time()) {
                throw new RuntimeException('La previsualización venció. Analice nuevamente el archivo antes de confirmar.');
            }

            $preview = $sessionPreview['preview'];
            if ((int)($preview['resumen']['invalidos'] ?? 0) > 0) {
                throw new RuntimeException('No se puede confirmar una carga que contiene filas inválidas. Corrija el archivo y vuelva a analizarlo.');
            }

            $enlace_db->begin_transaction();
            try {
                $resultado = acAplicarSincronizacionResponsables($enlace_db, $preview, acUsuarioActual());

                $tipoCarga = 'RESPONSABLES_VERSIONADOS';
                $estadoCarga = 'COMPLETADA';
                $nombre = (string)$preview['nombre_archivo'];
                $sha = (string)$preview['sha256'];
                $usuario = acUsuarioActual();
                $total = count($preview['registros']);
                $validos = $total;
                $invalidos = 0;
                $detalle = 'Nuevos=' . $resultado['nuevos']
                    . '; Actualizados=' . $resultado['actualizados']
                    . '; Sin cambios=' . $resultado['sin_cambios']
                    . '; Inactivados explícitamente=' . $resultado['inactivados']
                    . '; No encontrados (sin inactivar)=' . $resultado['no_encontrados'];

                $stmt = $enlace_db->prepare(
                    'INSERT INTO tb_alerta_correo_carga
                    (acg_tipo, acg_archivo_nombre, acg_archivo_sha256, acg_total_registros,
                     acg_registros_validos, acg_registros_invalidos, acg_estado, acg_detalle_error, acg_usuario)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $stmt->bind_param('sssiiisss', $tipoCarga, $nombre, $sha, $total, $validos, $invalidos, $estadoCarga, $detalle, $usuario);
                $stmt->execute();
                $stmt->close();

                $enlace_db->commit();
                unset($_SESSION['ac_resp_sync'][$tokenPreview]);
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
        try {
            if ($enlace_db->errno === 0) {
                // Nada que revertir.
            }
        } catch (Throwable $ignored) {
        }
        error_log('Alertas Correos / carga versionada: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

function acCargaEtiquetaFuente(string $fuente): string
{
    return match ($fuente) {
        'COORDINADORES_CZ' => 'Base Coordinadores CZ',
        'ENLACES_SIM' => 'Base Enlaces SIM',
        default => 'Plantilla del módulo',
    };
}
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260909">
    <style>
        /* Ajuste exclusivo de esta vista: retorno en la franja superior, junto al breadcrumb. */
        .ac-responsables-cargar-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 1rem;
            margin-bottom: .65rem;
        }
        .ac-responsables-cargar-topbar .ac-breadcrumb {
            margin-bottom: 0;
            min-width: 0;
        }
        .ac-responsables-cargar-topbar__actions {
            flex: 0 0 auto;
            margin-left: auto;
        }
        .ac-responsables-cargar-topbar__actions .btn {
            white-space: nowrap;
        }
        @media (max-width: 767.98px) {
            .ac-responsables-cargar-topbar {
                flex-direction: column;
                align-items: stretch;
            }
            .ac-responsables-cargar-topbar__actions {
                width: 100%;
                margin-left: 0;
            }
            .ac-responsables-cargar-topbar__actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<?php
include '../menu_principal.php';
include '../menu_header.php';
?>

<div class="contenido ac-module ac-module--footer-safe">
    <div class="ac-responsables-cargar-topbar">
        <nav class="ac-breadcrumb" aria-label="breadcrumb">
            <a href="../contenido.php">Inicio</a>
            <span class="ac-separator">/</span>
            <a href="alerta_correos.php">Alertas Correos</a>
            <span class="ac-separator">/</span>
            <a href="alerta_correos_responsables.php">Responsables territoriales</a>
            <span class="ac-separator">/</span>
            <span>Actualizar responsables</span>
        </nav>
        <div class="ac-responsables-cargar-topbar__actions">
            <a href="alerta_correos_responsables.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver al directorio</a>
        </div>
    </div>

    <header class="ac-page-header">
        <h1 class="ac-page-title"><span class="fas fa-file-upload"></span> Actualizar responsables territoriales</h1>
        <p class="ac-page-subtitle">Actualiza quién debe recibir una alerta aprobada en cada Regional y Centro Zonal. <strong>Esta opción no crea casos de alerta ni envía correos.</strong> La carga es versionada y conserva el historial.</p>
    </header>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><span class="fas fa-exclamation-circle mr-1"></span><?php echo acEscape($error); ?></div>
    <?php endif; ?>

    <?php if ($resultado): ?>
        <div class="alert alert-success" role="status">
            <div class="font-weight-bold mb-2"><span class="fas fa-check-circle mr-1"></span> Sincronización aplicada correctamente</div>
            <div class="row">
                <div class="col-6 col-md-3"><strong>Nuevos:</strong> <?php echo (int)$resultado['nuevos']; ?></div>
                <div class="col-6 col-md-3"><strong>Actualizados:</strong> <?php echo (int)$resultado['actualizados']; ?></div>
                <div class="col-6 col-md-3"><strong>Sin cambios:</strong> <?php echo (int)$resultado['sin_cambios']; ?></div>
                <div class="col-6 col-md-3"><strong>No encontrados:</strong> <?php echo (int)$resultado['no_encontrados']; ?></div>
            </div>
            <small class="d-block mt-2">Los registros no encontrados en el archivo permanecen activos hasta que exista una inactivación explícita.</small>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center mb-3">
        <div class="col-12 col-xl-10">
            <section class="ac-panel">
                <div class="ac-panel__header">
                    <h2 class="ac-panel__title"><span class="fas fa-file-excel"></span> Plantilla oficial</h2>
                    <span class="ac-panel__meta">XLSX · ejemplos e instrucciones</span>
                </div>
                <div class="ac-panel__body d-flex align-items-center justify-content-between flex-wrap">
                    <div class="pr-md-3 mb-2 mb-md-0">
                        <strong>¿Es la primera vez que realiza la carga?</strong>
                        <div class="text-muted small mt-1">Descargue la plantilla, reemplace los ejemplos por datos reales y cargue el mismo archivo.</div>
                    </div>
                    <a href="plantillas/Plantilla_Carga_Responsables_Alertas_Correos.xlsx" download class="btn ac-btn-green-outline">
                        <span class="fas fa-download"></span> Descargar plantilla Excel
                    </a>
                </div>
            </section>
        </div>
    </div>

    <?php if ($preview): ?>
        <?php $r = $preview['resumen']; ?>
        <div class="row justify-content-center mb-3">
            <div class="col-12 col-xl-10">
                <section class="ac-panel">
                    <div class="ac-panel__header">
                        <h2 class="ac-panel__title"><span class="fas fa-search"></span> Previsualización de sincronización</h2>
                        <span class="ac-panel__meta"><?php echo acEscape(acCargaEtiquetaFuente((string)$preview['fuente'])); ?></span>
                    </div>
                    <div class="ac-panel__body">
                        <div class="ac-kpis-inline mb-3">
                            <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Nuevos</span><span class="ac-kpi-inline__value"><?php echo (int)$r['nuevos']; ?></span></div>
                            <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Cambios</span><span class="ac-kpi-inline__value"><?php echo (int)$r['actualizados']; ?></span></div>
                            <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Sin cambios</span><span class="ac-kpi-inline__value"><?php echo (int)$r['sin_cambios']; ?></span></div>
                            <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">No encontrados</span><span class="ac-kpi-inline__value"><?php echo (int)$r['no_encontrados']; ?></span></div>
                            <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Inválidos</span><span class="ac-kpi-inline__value"><?php echo (int)$r['invalidos']; ?></span></div>
                        </div>

                        <div class="ac-alert-box ac-alert-box--warning mb-3">
                            <strong><span class="fas fa-shield-alt mr-1"></span> Regla de seguridad de datos:</strong>
                            los responsables activos que no estén presentes en el archivo se muestran como <em>No encontrados</em>, pero <strong>no se eliminan ni se inactivan</strong> al confirmar.
                        </div>

                        <?php if ($preview['errores']): ?>
                            <div class="alert alert-danger">
                                <strong>El archivo contiene filas inválidas y no puede confirmarse.</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach (array_slice($preview['errores'], 0, 30) as $msg): ?><li><?php echo acEscape($msg); ?></li><?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($preview['diferencias']): ?>
                            <details class="mb-3">
                                <summary class="font-weight-bold" style="cursor:pointer;">Ver diferencias detectadas (<?php echo count($preview['diferencias']); ?>)</summary>
                                <div class="ac-table-wrap mt-2">
                                    <table class="table table-sm table-hover ac-table">
                                        <thead><tr><th>Acción</th><th>Regional</th><th>Punto</th><th>Responsable actual</th><th>Responsable archivo</th></tr></thead>
                                        <tbody>
                                        <?php foreach (array_slice($preview['diferencias'], 0, 150) as $dif):
                                            $nuevo = $dif['nuevo'] ?? null;
                                            $actual = $dif['actual'] ?? null;
                                        ?>
                                            <tr>
                                                <td><?php echo acEscape((string)$dif['tipo']); ?></td>
                                                <td><?php echo acEscape((string)($nuevo['regional'] ?? $actual['regional'] ?? '')); ?></td>
                                                <td><?php echo acEscape((string)($nuevo['centro_zonal'] ?? $actual['centro_zonal'] ?? '')); ?></td>
                                                <td><?php echo acEscape((string)($actual['nombre'] ?? '—')); ?><br><small><?php echo acEscape((string)($actual['correo'] ?? '')); ?></small></td>
                                                <td><?php echo acEscape((string)($nuevo['nombre'] ?? '—')); ?><br><small><?php echo acEscape((string)($nuevo['correo'] ?? '')); ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        <?php endif; ?>

                        <div class="ac-actions-bar ac-actions-bar--center">
                            <a href="alerta_correos_responsables_cargar.php" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a>
                            <form method="post" class="d-inline" data-ac-lock-submit="1" onsubmit="return confirm('¿Confirma la sincronización versionada de responsables?');">
                                <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                                <input type="hidden" name="accion" value="confirmar">
                                <input type="hidden" name="token_preview" value="<?php echo acEscape($tokenPreview); ?>">
                                <button type="submit" class="btn ac-btn-green-outline" <?php echo (int)$r['invalidos'] > 0 ? 'disabled' : ''; ?>>
                                    <span class="fas fa-check"></span> Confirmar actualización
                                </button>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    <?php else: ?>
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <section class="ac-panel">
                    <div class="ac-panel__header">
                        <h2 class="ac-panel__title"><span class="fas fa-cloud-upload-alt"></span> Seleccione el archivo</h2>
                        <span class="ac-panel__meta">Coordinadores CZ, Enlaces SIM o plantilla · máximo 10 MB</span>
                    </div>
                    <div class="ac-panel__body">
                        <form method="post" enctype="multipart/form-data" data-ac-lock-submit="1">
                            <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                            <input type="hidden" name="accion" value="analizar">

                            <div class="ac-upload-zone">
                                <div class="ac-upload-zone__icon"><span class="fas fa-file-excel"></span></div>
                                <div class="ac-upload-zone__title">Analizar antes de actualizar</div>
                                <div class="ac-upload-zone__text">El sistema primero compara el archivo contra los responsables vigentes. Ningún cambio se aplica hasta que usted confirme la previsualización.</div>
                                <label for="archivo" class="btn ac-btn-green-outline mb-0"><span class="fas fa-folder-open"></span> Seleccionar archivo</label>
                                <input id="archivo" type="file" name="archivo" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="d-none" data-ac-file-input>
                                <div class="ac-upload-file-name" data-ac-file-name>Ningún archivo seleccionado</div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-12 col-md-6 mb-3 mb-md-0">
                                    <div class="ac-alert-box ac-alert-box--info h-100">
                                        <strong><span class="fas fa-database mr-1"></span> Catálogo territorial</strong><br>
                                        Las Regionales y los Centros Zonales se crean o actualizan como entidades permanentes, separadas de las personas responsables.
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <div class="ac-alert-box ac-alert-box--warning h-100">
                                        <strong><span class="fas fa-history mr-1"></span> Histórico protegido</strong><br>
                                        Cuando cambia una persona, la versión anterior queda inactiva con fecha de vigencia hasta; nunca se sobreescribe ni se elimina.
                                    </div>
                                </div>
                            </div>

                            <div class="ac-actions-bar ac-actions-bar--center">
                                <a href="alerta_correos_responsables.php" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a>
                                <button type="submit" class="btn ac-btn-green-outline"><span class="fas fa-search"></span> Analizar archivo</button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
include '../footer.php';
include '../config/configuracion_js.php';
?>
<script src="assets/alerta_correos.js?v=20260909"></script>
</body>
</html>

