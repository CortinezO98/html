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


function acCargaSectorizadaTerritorioVista(?array $nuevo, ?array $actual): array
{
    $nuevo = is_array($nuevo) ? $nuevo : [];
    $actual = is_array($actual) ? $actual : [];

    $centroNuevo = trim((string)($nuevo['centro_zonal'] ?? ''));
    $regionalNuevo = trim((string)($nuevo['regional'] ?? ''));

    if ($centroNuevo !== '') {
        return ['tipo' => 'CENTRO ZONAL', 'nombre' => $centroNuevo];
    }

    if ($regionalNuevo !== '') {
        $nivelNuevo = strtoupper(trim((string)($nuevo['nivel'] ?? '')));
        $tipo = in_array($nivelNuevo, ['ZONAL', 'CENTRO_ZONAL'], true)
            ? 'CENTRO ZONAL'
            : 'REGIONAL';

        return ['tipo' => $tipo, 'nombre' => $regionalNuevo];
    }

    $tipoActual = strtoupper(trim((string)($actual['acp_tipo'] ?? '')));
    $centroActual = trim((string)($actual['acr_centro_zonal'] ?? ''));
    $regionalActual = trim((string)($actual['acr_regional'] ?? $actual['acp_regional'] ?? ''));
    $nombrePuntoActual = trim((string)($actual['acp_nombre'] ?? ''));

    if ($tipoActual === 'CENTRO_ZONAL' || $centroActual !== '') {
        $nombre = $centroActual !== '' ? $centroActual : $nombrePuntoActual;
        return ['tipo' => 'CENTRO ZONAL', 'nombre' => $nombre];
    }

    if ($regionalActual !== '' || $nombrePuntoActual !== '') {
        return [
            'tipo' => 'REGIONAL',
            'nombre' => $regionalActual !== '' ? $regionalActual : $nombrePuntoActual,
        ];
    }

    return ['tipo' => '', 'nombre' => '—'];
}

$instruccionesCarga = match ($acCargaTipo) {
    'TERRITORIOS' => [
        'titulo' => 'Instrucciones para Regionales y Centros Zonales',
        'pasos' => [
            'Descargue la plantilla y no modifique los nombres de las columnas.',
            'Use NIVEL = REGIONAL para Regionales y NIVEL = ZONAL para Centros Zonales.',
            'Diligencie REGIONAL, CENTRO_ZONAL cuando aplique, CODIGO_CENTRO y ESTADO.',
            'CODIGO_CENTRO debe ser único para cada territorio.',
            'Suba el archivo y revise la previsualización. Si existen errores, corríjalos antes de confirmar.',
        ],
        'nota' => 'Esta carga es el maestro territorial. Las cargas de Coordinadores y Responsables dependen de estos códigos.',
    ],
    'COORDINADORES' => [
        'titulo' => 'Instrucciones para Coordinadores',
        'pasos' => [
            'Antes de cargar, asegúrese de que el territorio ya exista en el maestro de Regionales y Centros Zonales.',
            'Diligencie CODIGO_CENTRO, DOCUMENTO, NOMBRE, CORREO, EXTENSION_IP y ESTADO.',
            'No es necesario incluir Regional ni Centro Zonal: el sistema los obtiene automáticamente usando CODIGO_CENTRO.',
            'Si ya existe un coordinador para ese territorio y cambia la persona, se conserva el histórico y se registra la nueva vigencia.',
            'Revise los registros Nuevos, Actualizados, Sin cambios y Errores antes de confirmar.',
        ],
        'nota' => 'Si CODIGO_CENTRO no existe en el maestro territorial, la fila será rechazada y no se creará un territorio automáticamente.',
    ],
    'RESPONSABLES' => [
        'titulo' => 'Instrucciones para Responsables / Enlaces',
        'pasos' => [
            'Verifique primero que la Regional o Centro Zonal exista en el maestro territorial.',
            'Diligencie CODIGO_CENTRO, DOCUMENTO, NOMBRE, CORREO, EXTENSION_IP y ESTADO.',
            'Regional y Centro Zonal se resuelven automáticamente mediante CODIGO_CENTRO.',
            'Si cambia el responsable o enlace, la asignación anterior se cierra y permanece en el histórico.',
            'Analice el archivo y confirme únicamente cuando la previsualización no tenga errores.',
        ],
        'nota' => 'Esta carga afecta únicamente al Responsable / Enlace del territorio; no modifica al Coordinador.',
    ],
    default => ['titulo' => 'Instrucciones', 'pasos' => [], 'nota' => ''],
};
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
        .ac-sector-instructions{display:none;margin-top:1rem;padding:1rem 1.1rem;background:#f8fbf9;border:1px solid #dce9df;border-radius:10px;color:#455a64}
        .ac-sector-instructions.is-open{display:block}
        .ac-sector-instructions h3{font-size:1rem;font-weight:800;color:#2f5f3a;margin:0 0 .7rem}
        .ac-sector-instructions ol{padding-left:1.2rem;margin-bottom:.7rem}
        .ac-sector-instructions li{margin-bottom:.38rem}
        .ac-sector-instructions__note{padding:.65rem .75rem;background:#fff;border-left:3px solid #4caf50;border-radius:5px}
        .ac-territory-preview{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap}
        .ac-territory-preview__type{display:inline-flex;align-items:center;padding:.18rem .48rem;border-radius:999px;background:#e8f5e9;color:#2e7d32;font-size:.68rem;font-weight:800;letter-spacing:.02em;white-space:nowrap}
        .ac-territory-preview__name{font-weight:600;color:#37474f}
        @media(max-width:991.98px){.ac-sector-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:767.98px){.ac-sector-page{padding-top:4.5rem}.ac-sector-top{flex-direction:column;align-items:stretch}.ac-sector-summary{grid-template-columns:1fr}}
    </style>

<style>
    .ac-carga-sectorizada-page{
        padding-bottom: 130px !important;
    }

    .ac-carga-sectorizada-page .ac-panel:last-child{
        margin-bottom: 40px !important;
    }

    .ac-carga-sectorizada-page .ac-upload-box,
    .ac-carga-sectorizada-page .ac-dropzone,
    .ac-carga-sectorizada-page .ac-upload-empty,
    .ac-carga-sectorizada-page .ac-upload-actions{
        margin-bottom: 20px !important;
    }

    @media (max-width: 768px){
        .ac-carga-sectorizada-page{
            padding-bottom: 150px !important;
        }
    }
</style>

</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>

<div class="contenido ac-module ac-module--footer-safe ac-sector-page ac-carga-sectorizada-page">
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
                    <div class="d-flex flex-wrap" style="gap:.5rem">
                        <a class="btn ac-btn-green-outline" href="alerta_correos_plantilla_sectorizada.php?tipo=<?php echo urlencode($plantillaTipo); ?>">
                            <span class="fas fa-file-download"></span> Descargar plantilla
                        </a>
                        <button type="button" class="btn btn-outline-info" id="ac-toggle-instructions" aria-expanded="false">
                            <span class="fas fa-info-circle"></span> Instrucciones
                        </button>
                    </div>
                </div>
                <div id="ac-sector-instructions" class="ac-sector-instructions">
                    <h3><span class="fas fa-info-circle mr-1"></span> <?php echo acEscape($instruccionesCarga['titulo']); ?></h3>
                    <ol>
                        <?php foreach ($instruccionesCarga['pasos'] as $paso): ?>
                            <li><?php echo acEscape($paso); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <div class="ac-sector-instructions__note">
                        <strong>Importante:</strong> <?php echo acEscape($instruccionesCarga['nota']); ?>
                    </div>
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
                                            $codigo = trim((string)($nuevo['codigo_centro'] ?? $actual['acp_codigo'] ?? $actual['acr_codigo_centro'] ?? ''));
                                            $territorioVista = acCargaSectorizadaTerritorioVista($nuevo, $actual);
                                        ?>
                                            <tr>
                                                <td><?php echo acEscape((string)$dif['tipo']); ?></td>
                                                <td><?php echo acEscape($codigo !== '' ? $codigo : '—'); ?></td>
                                                <td>
                                                    <div class="ac-territory-preview">
                                                        <?php if ($territorioVista['tipo'] !== ''): ?>
                                                            <span class="ac-territory-preview__type"><?php echo acEscape($territorioVista['tipo']); ?></span>
                                                        <?php endif; ?>
                                                        <span class="ac-territory-preview__name"><?php echo acEscape($territorioVista['nombre']); ?></span>
                                                    </div>
                                                </td>
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

var instructionButton = document.getElementById('ac-toggle-instructions');
var instructionPanel = document.getElementById('ac-sector-instructions');
if (instructionButton && instructionPanel) {
    instructionButton.addEventListener('click', function() {
        var open = instructionPanel.classList.toggle('is-open');
        instructionButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        instructionButton.innerHTML = open
            ? '<span class="fas fa-times-circle"></span> Cerrar instrucciones'
            : '<span class="fas fa-info-circle"></span> Instrucciones';
    });
}
</script>
</body>
</html>
