<?php
declare(strict_types=1);

if (!isset($acCargaTipo)) {
    http_response_code(400);
    exit('Tipo de carga no definido.');
}

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_cargas_sectorizadas.php';

acExigirPerfil(['Usuario', 'Administrador']);

$configCarga = acCargaSectorizadaConfiguracion($acCargaTipo);
$titulo_header = 'Alertas Correos | ' . $configCarga['titulo'];
$error = null;
$preview = null;
$resultado = null;
$tokenPreview = '';

if (!isset($_SESSION['ac_cargas_sectorizadas']) || !is_array($_SESSION['ac_cargas_sectorizadas'])) {
    $_SESSION['ac_cargas_sectorizadas'] = [];
}
foreach ($_SESSION['ac_cargas_sectorizadas'] as $token => $item) {
    if (!is_array($item) || (int)($item['expires'] ?? 0) < time()) {
        unset($_SESSION['ac_cargas_sectorizadas'][$token]);
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

            $preview = acCargaSectorizadaPreparar($enlace_db, $rutaTemporal, $nombreArchivo, $acCargaTipo);
            $tokenPreview = bin2hex(random_bytes(24));
            $_SESSION['ac_cargas_sectorizadas'][$tokenPreview] = [
                'expires' => time() + 1800,
                'tipo' => $acCargaTipo,
                'preview' => $preview,
            ];
        } elseif ($accion === 'confirmar') {
            $tokenPreview = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token_preview'] ?? '')) ?? '';
            $sessionPreview = $_SESSION['ac_cargas_sectorizadas'][$tokenPreview] ?? null;
            if (!$sessionPreview || (int)($sessionPreview['expires'] ?? 0) < time()) {
                throw new RuntimeException('La previsualización venció. Analice nuevamente el archivo.');
            }
            if (($sessionPreview['tipo'] ?? '') !== $acCargaTipo) {
                throw new RuntimeException('La previsualización no corresponde a este tipo de carga.');
            }

            $preview = $sessionPreview['preview'];
            if ((int)($preview['resumen']['invalidos'] ?? 0) > 0) {
                throw new RuntimeException('No se puede confirmar una carga con filas inválidas.');
            }

            $enlace_db->begin_transaction();
            try {
                $usuario = acUsuarioActual();
                $resultado = acCargaSectorizadaAplicar($enlace_db, $preview, $usuario);

                $tipoCarga = (string)$configCarga['tipo_carga'];
                $estadoCarga = 'COMPLETADA';
                $nombre = (string)$preview['nombre_archivo'];
                $sha = (string)$preview['sha256'];
                $total = count($preview['registros']);
                $validos = $total;
                $invalidos = 0;
                $detalle = 'Nuevos=' . ($resultado['nuevos'] ?? 0)
                    . '; Actualizados=' . ($resultado['actualizados'] ?? 0)
                    . '; Sin cambios=' . ($resultado['sin_cambios'] ?? 0)
                    . '; Inactivados=' . ($resultado['inactivados'] ?? 0);

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
                unset($_SESSION['ac_cargas_sectorizadas'][$tokenPreview]);
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
        error_log('Alertas Correos / carga sectorizada ' . $acCargaTipo . ': ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$plantillaTipo = strtolower($acCargaTipo);
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260917">
    <style>
        .ac-sector-page{padding-top:3.25rem}
        .ac-sector-top{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
        .ac-sector-top .ac-breadcrumb{margin:0}
        .ac-sector-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.75rem}
        .ac-sector-stat{border:1px solid #e1e7e3;border-radius:10px;padding:.85rem;background:#fff;text-align:center}
        .ac-sector-stat strong{display:block;font-size:1.35rem;color:#2e7d32}
        .ac-upload-zone{border:2px dashed #b8c8bd;border-radius:12px;padding:2rem;text-align:center;background:#fbfdfb}
        .ac-upload-zone__icon{font-size:2rem;color:#4caf50;margin-bottom:.5rem}
        @media(max-width:991.98px){.ac-sector-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:767.98px){.ac-sector-page{padding-top:4.5rem}.ac-sector-top{flex-direction:column;align-items:stretch}.ac-sector-summary{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>

<div class="contenido ac-module ac-module--footer-safe ac-sector-page">
    <div class="ac-sector-top">
        <nav class="ac-breadcrumb" aria-label="breadcrumb">
            <a href="../contenido.php">Inicio</a>
            <span class="ac-separator">/</span>
            <a href="alerta_correos.php">Alertas Correos</a>
            <span class="ac-separator">/</span>
            <a href="alerta_correos_responsables_cargar.php">Cargas territoriales</a>
            <span class="ac-separator">/</span>
            <span><?php echo acEscape($configCarga['titulo']); ?></span>
        </nav>
        <a href="alerta_correos_responsables_cargar.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver</a>
    </div>

    <header class="ac-page-header">
        <h1 class="ac-page-title"><span class="fas fa-file-upload"></span> <?php echo acEscape($configCarga['titulo']); ?></h1>
        <p class="ac-page-subtitle"><?php echo acEscape($configCarga['subtitulo']); ?></p>
    </header>

    <?php if ($error): ?>
        <div class="alert alert-danger"><span class="fas fa-exclamation-circle mr-1"></span><?php echo acEscape($error); ?></div>
    <?php endif; ?>

    <?php if ($resultado): ?>
        <div class="alert alert-success">
            <strong><span class="fas fa-check-circle mr-1"></span> Carga aplicada correctamente.</strong>
            <div class="mt-2">
                Nuevos: <strong><?php echo (int)($resultado['nuevos'] ?? 0); ?></strong> ·
                Actualizados: <strong><?php echo (int)($resultado['actualizados'] ?? 0); ?></strong> ·
                Sin cambios: <strong><?php echo (int)($resultado['sin_cambios'] ?? 0); ?></strong> ·
                Inactivados: <strong><?php echo (int)($resultado['inactivados'] ?? 0); ?></strong>
            </div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center mb-3">
        <div class="col-12 col-xl-10">
            <section class="ac-panel">
                <div class="ac-panel__header">
                    <h2 class="ac-panel__title"><span class="fas fa-download"></span> Plantilla de ejemplo</h2>
                    <span class="ac-panel__meta">CSV compatible con Excel</span>
                </div>
                <div class="ac-panel__body d-flex justify-content-between align-items-center flex-wrap">
                    <div class="pr-md-3 mb-2 mb-md-0">
                        <?php if ($acCargaTipo === 'TERRITORIOS'): ?>
                            <strong>Primero cargue el maestro territorial.</strong>
                            <div class="small text-muted">Regional y Centro Zonal se administran únicamente desde esta carga.</div>
                        <?php else: ?>
                            <strong>Solo necesita el código del territorio.</strong>
                            <div class="small text-muted">Regional y Centro Zonal se obtienen automáticamente desde el maestro territorial.</div>
                        <?php endif; ?>
                    </div>
                    <a class="btn ac-btn-green-outline" href="alerta_correos_plantilla_sectorizada.php?tipo=<?php echo urlencode($plantillaTipo); ?>">
                        <span class="fas fa-file-download"></span> Descargar plantilla
                    </a>
                </div>
            </section>
        </div>
    </div>

    <?php if ($preview): $r = $preview['resumen']; ?>
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <section class="ac-panel">
                    <div class="ac-panel__header">
                        <h2 class="ac-panel__title"><span class="fas fa-search"></span> Previsualización</h2>
                        <span class="ac-panel__meta"><?php echo acEscape((string)$preview['nombre_archivo']); ?></span>
                    </div>
                    <div class="ac-panel__body">
                        <div class="ac-sector-summary mb-3">
                            <div class="ac-sector-stat"><strong><?php echo (int)($r['nuevos'] ?? 0); ?></strong><span>Nuevos</span></div>
                            <div class="ac-sector-stat"><strong><?php echo (int)($r['actualizados'] ?? 0); ?></strong><span>Actualizados</span></div>
                            <div class="ac-sector-stat"><strong><?php echo (int)($r['sin_cambios'] ?? 0); ?></strong><span>Sin cambios</span></div>
                            <div class="ac-sector-stat"><strong><?php echo (int)($r['inactivaciones_explicitas'] ?? 0); ?></strong><span>Inactivar</span></div>
                            <div class="ac-sector-stat"><strong><?php echo (int)($r['invalidos'] ?? 0); ?></strong><span>Errores</span></div>
                        </div>

                        <?php if ($preview['errores']): ?>
                            <div class="alert alert-danger">
                                <strong>Corrija estas filas antes de confirmar:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach (array_slice($preview['errores'], 0, 40) as $msg): ?>
                                        <li><?php echo acEscape($msg); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($preview['diferencias']): ?>
                            <details open class="mb-3">
                                <summary class="font-weight-bold" style="cursor:pointer">Ver cambios detectados (<?php echo count($preview['diferencias']); ?>)</summary>
                                <div class="ac-table-wrap mt-2">
                                    <table class="table table-sm table-hover ac-table">
                                        <thead>
                                            <tr><th>Acción</th><th>Código</th><th>Territorio</th><th>Actual</th><th>Nuevo</th></tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach (array_slice($preview['diferencias'], 0, 200) as $dif):
                                            $nuevo = $dif['nuevo'] ?? null;
                                            $actual = $dif['actual'] ?? null;
                                            $codigo = (string)($nuevo['codigo_centro'] ?? $actual['acp_codigo'] ?? $actual['acr_codigo_centro'] ?? '');
                                            $territorio = (string)($nuevo['centro_zonal'] ?? $nuevo['regional'] ?? $actual['acp_nombre'] ?? $actual['acr_centro_zonal'] ?? $actual['acr_regional'] ?? '');
                                        ?>
                                            <tr>
                                                <td><?php echo acEscape((string)$dif['tipo']); ?></td>
                                                <td><?php echo acEscape($codigo); ?></td>
                                                <td><?php echo acEscape($territorio); ?></td>
                                                <td><?php echo acEscape((string)($actual['acr_nombre'] ?? $actual['acp_nombre'] ?? '—')); ?><br><small><?php echo acEscape((string)($actual['acr_correo'] ?? '')); ?></small></td>
                                                <td><?php echo acEscape((string)($nuevo['nombre'] ?? $nuevo['centro_zonal'] ?? $nuevo['regional'] ?? '—')); ?><br><small><?php echo acEscape((string)($nuevo['correo'] ?? '')); ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        <?php endif; ?>

                        <div class="ac-actions-bar ac-actions-bar--center">
                            <a href="<?php echo acEscape(basename($_SERVER['PHP_SELF'])); ?>" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a>
                            <form method="post" class="d-inline" data-ac-lock-submit="1" onsubmit="return confirm('¿Confirma la aplicación de esta carga?');">
                                <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                                <input type="hidden" name="accion" value="confirmar">
                                <input type="hidden" name="token_preview" value="<?php echo acEscape($tokenPreview); ?>">
                                <button class="btn ac-btn-green-outline" type="submit" <?php echo (int)($r['invalidos'] ?? 0) > 0 ? 'disabled' : ''; ?>>
                                    <span class="fas fa-check"></span> Confirmar carga
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
                        <span class="ac-panel__meta">CSV o XLSX · máximo 10 MB</span>
                    </div>
                    <div class="ac-panel__body">
                        <form method="post" enctype="multipart/form-data" data-ac-lock-submit="1">
                            <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                            <input type="hidden" name="accion" value="analizar">
                            <div class="ac-upload-zone">
                                <div class="ac-upload-zone__icon"><span class="fas fa-file-excel"></span></div>
                                <h3 class="h5">Analizar antes de aplicar</h3>
                                <p class="text-muted">El sistema valida el archivo y muestra los cambios antes de modificar información.</p>
                                <label for="archivo" class="btn ac-btn-green-outline mb-0"><span class="fas fa-folder-open"></span> Seleccionar archivo</label>
                                <input id="archivo" type="file" name="archivo" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="d-none" data-ac-file-input>
                                <div class="small mt-2" data-ac-file-name>Ningún archivo seleccionado</div>
                            </div>
                            <div class="text-center mt-3">
                                <button type="submit" class="btn ac-btn-green-outline"><span class="fas fa-search"></span> Analizar archivo</button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
<script>
document.querySelectorAll('[data-ac-file-input]').forEach(function(input){
    input.addEventListener('change', function(){
        var target = document.querySelector('[data-ac-file-name]');
        if (target) target.textContent = input.files && input.files[0] ? input.files[0].name : 'Ningún archivo seleccionado';
    });
});
</script>
</body>
</html>
