<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_carga_excel.php';

acExigirPerfil(['Administrador']);

$titulo_header = 'Alertas Correos | Crear Alerta Masiva';
$error = null;
$preview = null;
$tokenPreview = '';
$flash = acFlashTomar();
$cargaConsultada = null;
$detallesCarga = [];

if (!isset($_SESSION['ac_alertas_excel_preview']) || !is_array($_SESSION['ac_alertas_excel_preview'])) {
    $_SESSION['ac_alertas_excel_preview'] = [];
}
foreach ($_SESSION['ac_alertas_excel_preview'] as $token => $item) {
    if (!is_array($item) || (int)($item['expires'] ?? 0) < time()) {
        unset($_SESSION['ac_alertas_excel_preview'][$token]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        acValidarCsrfPost();
        $accion = (string)($_POST['accion'] ?? 'analizar');

        if ($accion === 'analizar') {
            if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Seleccione el archivo XLSX que contiene las alertas que desea convertir en casos.');
            }

            $nombre = (string)$_FILES['archivo']['name'];
            $tmp = (string)$_FILES['archivo']['tmp_name'];
            $tamano = (int)$_FILES['archivo']['size'];
            $prioridadDefecto = strtoupper(trim((string)($_POST['prioridad_defecto'] ?? 'MEDIA')));

            if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('El archivo de alertas debe estar en formato XLSX.');
            }
            if ($tamano <= 0 || $tamano > 20 * 1024 * 1024) {
                throw new RuntimeException('El archivo debe tener contenido y no superar 20 MB.');
            }
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('El archivo recibido no es válido.');
            }
            if (!in_array($prioridadDefecto, ['CRITICA', 'ALTA', 'MEDIA', 'BAJA'], true)) {
                throw new RuntimeException('La prioridad por defecto seleccionada no es válida.');
            }

            $preview = acCargaAlertasPreparar($enlace_db, $tmp, $nombre, $prioridadDefecto);
            $tokenPreview = bin2hex(random_bytes(24));
            $_SESSION['ac_alertas_excel_preview'][$tokenPreview] = [
                'expires' => time() + 1800,
                'preview' => $preview,
            ];
        } elseif ($accion === 'confirmar') {
            $tokenPreview = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token_preview'] ?? '')) ?? '';
            $item = $_SESSION['ac_alertas_excel_preview'][$tokenPreview] ?? null;
            if (!$item || (int)($item['expires'] ?? 0) < time()) {
                throw new RuntimeException('La previsualización venció. Vuelva a analizar el archivo.');
            }

            $preview = $item['preview'];
            if ((int)($preview['resumen']['listas'] ?? 0) <= 0) {
                throw new RuntimeException('No hay filas nuevas listas para crear. Revise los duplicados o los errores reportados.');
            }

            $enlace_db->begin_transaction();
            try {
                $resultado = acCargaAlertasAplicar($enlace_db, $preview, acUsuarioActual());
                $enlace_db->commit();
            } catch (Throwable $e) {
                $enlace_db->rollback();
                throw $e;
            }

            unset($_SESSION['ac_alertas_excel_preview'][$tokenPreview]);
            $mensaje = 'Carga procesada. Se crearon ' . (int)$resultado['creadas'] . ' caso(s) pendiente(s) de revisión.';
            if ((int)$resultado['duplicadas'] > 0 || (int)$resultado['errores_territorio'] > 0 || (int)$resultado['errores_datos'] > 0) {
                $mensaje .= ' Las filas duplicadas o con error fueron omitidas y quedaron registradas en el detalle de la carga.';
            }
            acFlash('success', $mensaje);
            header('Location: alerta_correos_cargar_excel.php?carga=' . (int)$resultado['carga_id']);
            exit;
        } else {
            throw new RuntimeException('Acción no válida.');
        }
    } catch (Throwable $e) {
        error_log('Alertas Correos / Crear Alerta Masiva: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$cargaId = filter_input(INPUT_GET, 'carga', FILTER_VALIDATE_INT);
if ($cargaId) {
    try {
        $stmt = $enlace_db->prepare(
            "SELECT * FROM tb_alerta_correo_carga
             WHERE acg_id=? AND acg_tipo='CASOS_ALERTAS_EXCEL'
             LIMIT 1"
        );
        $stmt->bind_param('i', $cargaId);
        $stmt->execute();
        $cargaConsultada = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if ($cargaConsultada) {
            $stmt = $enlace_db->prepare(
                'SELECT d.*, c.acc_radicado
                 FROM tb_alerta_correo_carga_detalle d
                 LEFT JOIN tb_alerta_correo_caso c ON c.acc_id=d.acd_caso_id
                 WHERE d.acd_carga_id=?
                 ORDER BY d.acd_fila_excel ASC, d.acd_id ASC'
            );
            $stmt->bind_param('i', $cargaId);
            $stmt->execute();
            $detallesCarga = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Alertas Correos / consultar carga Excel: ' . $e->getMessage());
        if ($error === null) {
            $error = 'No fue posible consultar el detalle de la carga seleccionada.';
        }
    }
}

function acCargaAlertasBadge(string $estado): string
{
    $clase = acCargaAlertasEstadoClase($estado);
    $bootstrap = match ($clase) {
        'success' => 'badge-success',
        'warning' => 'badge-warning',
        'danger' => 'badge-danger',
        default => 'badge-secondary',
    };
    return $bootstrap;
}
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260910">
    <style>
        /* Ajustes exclusivos de Crear Alerta Masiva. */
        .ac-module .ac-mass-page-header {
            flex-wrap: nowrap;
            gap: 20px;
        }
        .ac-module .ac-mass-page-header .ac-page-header__main {
            flex: 1 1 auto;
            min-width: 0;
        }
        .ac-module .ac-mass-page-header .ac-page-header__actions {
            flex: 0 0 auto;
            margin-left: auto;
            margin-top: 0;
        }
        .ac-module .ac-mass-page-header .ac-page-header__actions .btn {
            white-space: nowrap;
        }
        .ac-module .ac-mass-template-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 16px;
            padding: 14px 16px;
            border: 1px solid #cfe5d1;
            border-radius: 8px;
            background: #f7fbf7;
        }
        .ac-module .ac-mass-template-card__main {
            display: flex;
            align-items: flex-start;
            min-width: 0;
            gap: 12px;
        }
        .ac-module .ac-mass-template-card__icon {
            flex: 0 0 40px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #e5f5e7;
            color: #32863a;
            font-size: 18px;
        }
        .ac-module .ac-mass-template-card__title {
            margin: 0 0 3px;
            font-size: 13px;
            font-weight: 700;
            color: #1d2a35;
        }
        .ac-module .ac-mass-template-card__text {
            margin: 0;
            color: #66717d;
            font-size: 11px;
            line-height: 1.5;
        }
        @media (max-width: 767.98px) {
            .ac-module .ac-mass-page-header {
                flex-direction: column;
                flex-wrap: nowrap;
                gap: 10px;
            }
            .ac-module .ac-mass-page-header .ac-page-header__main,
            .ac-module .ac-mass-page-header .ac-page-header__actions {
                width: 100%;
                margin: 0;
            }
            .ac-module .ac-mass-page-header .ac-page-header__actions .btn {
                width: 100%;
            }
            .ac-module .ac-mass-template-card {
                flex-direction: column;
                align-items: stretch;
                padding: 13px;
            }
            .ac-module .ac-mass-template-card .btn {
                width: 100%;
            }
        }
        @media (max-width: 575.98px) {
            .ac-module .ac-mass-page-header .ac-page-title {
                font-size: 18px;
                line-height: 1.2;
            }
            .ac-module .ac-mass-page-header .ac-page-subtitle {
                font-size: 11px;
                line-height: 1.48;
            }
        }
    </style>
</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>

<div class="contenido ac-module ac-module--footer-safe">
    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a><span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a><span class="ac-separator">/</span>
        <span>Crear Alerta Masiva</span>
    </nav>

    <header class="ac-page-header ac-mass-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title"><span class="fas fa-file-excel"></span> Crear Alerta Masiva</h1>
            <p class="ac-page-subtitle">Cada fila válida se convierte en un caso independiente en estado <strong>Pendiente de revisión</strong>. Esta carga <strong>no crea regionales ni puntos de atención</strong>. Los casos notificables podrán generar correo únicamente después de ser aprobados; las alertas de <strong>Tiempos de espera muy largos</strong> se crean como <strong>informativas y sin correo</strong>.</p>
        </div>
        <div class="ac-page-header__actions">
            <a href="alerta_correos.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver a la bandeja</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo acEscape((string)$flash['tipo']); ?>">
            <span class="fas fa-check-circle mr-1"></span><?php echo acEscape((string)$flash['mensaje']); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><span class="fas fa-exclamation-circle mr-1"></span><?php echo acEscape($error); ?></div>
    <?php endif; ?>

    <section class="ac-panel mb-3">
        <div class="ac-panel__header">
            <h2 class="ac-panel__title"><span class="fas fa-route"></span> Qué hace esta opción</h2>
            <span class="ac-panel__meta">Creación masiva de casos</span>
        </div>
        <div class="ac-panel__body">
            <div class="row">
                <div class="col-12 col-lg-4 mb-3 mb-lg-0">
                    <strong>1. Usa la maestra territorial</strong>
                    <p class="mb-0 text-muted">La Regional y el Punto de atención del Excel deben existir previamente en la base territorial.</p>
                </div>
                <div class="col-12 col-lg-4 mb-3 mb-lg-0">
                    <strong>2. Valida antes de guardar</strong>
                    <p class="mb-0 text-muted">Primero verá una previsualización con filas listas, duplicadas y filas que requieren corrección.</p>
                </div>
                <div class="col-12 col-lg-4">
                    <strong>3. Crea casos, no correos</strong>
                    <p class="mb-0 text-muted">Al confirmar se crea un caso por fila válida. Los casos notificables conservan el flujo normal de aprobación y correo. Los casos de <strong>Tiempos de espera muy largos</strong> quedan informativos y nunca generan correo.</p>
                </div>
            </div>
        </div>
    </section>

    <?php if ($cargaConsultada): ?>
        <section class="ac-panel mb-3">
            <div class="ac-panel__header">
                <h2 class="ac-panel__title"><span class="fas fa-check-circle"></span> Resultado de la carga #<?php echo (int)$cargaConsultada['acg_id']; ?></h2>
                <span class="ac-panel__meta"><?php echo acEscape((string)$cargaConsultada['acg_archivo_nombre']); ?></span>
            </div>
            <div class="ac-panel__body">
                <div class="ac-kpis-inline mb-3">
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Filas procesadas</span><span class="ac-kpi-inline__value"><?php echo (int)$cargaConsultada['acg_total_registros']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Casos creados</span><span class="ac-kpi-inline__value"><?php echo (int)$cargaConsultada['acg_registros_creados']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Duplicadas</span><span class="ac-kpi-inline__value"><?php echo (int)$cargaConsultada['acg_registros_duplicados']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Informativas sin correo</span><span class="ac-kpi-inline__value"><?php echo (int)($cargaConsultada['acg_registros_informativos'] ?? 0); ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Informativas sin rango</span><span class="ac-kpi-inline__value"><?php echo (int)($cargaConsultada['acg_registros_informativos_sin_rango'] ?? 0); ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Con error</span><span class="ac-kpi-inline__value"><?php echo (int)$cargaConsultada['acg_registros_invalidos']; ?></span></div>
                </div>
                <div class="ac-alert-box ac-alert-box--info mb-3">
                    Los casos creados ya están disponibles en la <a href="alerta_correos_gestion.php"><strong>bandeja de Revisión y aprobación</strong></a>. Los casos notificables todavía no han enviado correo. Los casos informativos de <strong>Tiempos de espera muy largos</strong> permanecerán sin correo incluso después de ser aprobados.
                </div>
                <?php if ($detallesCarga): ?>
                    <div class="ac-table-wrap">
                        <table class="table table-hover ac-table mb-0">
                            <thead><tr><th>Fila</th><th>SIM</th><th>Regional</th><th>Punto de atención</th><th>Gestión</th><th>Rango de espera</th><th>Resultado</th><th>Caso</th><th>Detalle</th></tr></thead>
                            <tbody>
                            <?php foreach ($detallesCarga as $d): ?>
                                <tr>
                                    <td><?php echo (int)$d['acd_fila_excel']; ?></td>
                                    <td><?php echo acEscape((string)$d['acd_sim']); ?></td>
                                    <td><?php echo acEscape((string)$d['acd_regional_origen']); ?></td>
                                    <td><?php echo acEscape((string)$d['acd_punto_origen']); ?></td>
                                    <td>
                                        <?php if (strtoupper((string)($d['acd_tipo_gestion'] ?? 'NOTIFICABLE')) === 'INFORMATIVA'): ?>
                                            <span class="badge badge-info">Informativa · sin correo</span>
                                        <?php else: ?>
                                            <span class="badge badge-primary">Notificable</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo strtoupper((string)($d['acd_tipo_gestion'] ?? '')) === 'INFORMATIVA' ? acEscape(acAlertaTiempoRangoLabel((string)($d['acd_tiempo_espera_rango'] ?? ''))) : '—'; ?></td>
                                    <td><span class="badge <?php echo acEscape(acCargaAlertasBadge((string)$d['acd_estado'])); ?>"><?php echo acEscape(acCargaAlertasEstadoEtiqueta((string)$d['acd_estado'])); ?></span></td>
                                    <td>
                                        <?php if (!empty($d['acd_caso_id']) && !empty($d['acc_radicado'])): ?>
                                            <a href="alerta_correos_ver.php?id=<?php echo (int)$d['acd_caso_id']; ?>"><?php echo acEscape((string)$d['acc_radicado']); ?></a>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td><small><?php echo acEscape((string)$d['acd_mensaje']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($preview): $r = $preview['resumen']; ?>
        <section class="ac-panel mb-3">
            <div class="ac-panel__header">
                <h2 class="ac-panel__title"><span class="fas fa-search"></span> Previsualización antes de crear casos</h2>
                <span class="ac-panel__meta"><?php echo acEscape((string)$preview['nombre_archivo']); ?> · Hoja: <?php echo acEscape((string)$preview['hoja']); ?></span>
            </div>
            <div class="ac-panel__body">
                <div class="ac-kpis-inline mb-3">
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Filas detectadas</span><span class="ac-kpi-inline__value"><?php echo (int)$r['total']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Listas para crear</span><span class="ac-kpi-inline__value"><?php echo (int)$r['listas']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Duplicadas</span><span class="ac-kpi-inline__value"><?php echo (int)$r['duplicadas']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Informativas sin correo</span><span class="ac-kpi-inline__value"><?php echo (int)($r['informativas'] ?? 0); ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Informativas sin rango</span><span class="ac-kpi-inline__value"><?php echo (int)($r['informativas_sin_rango'] ?? 0); ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Error territorial</span><span class="ac-kpi-inline__value"><?php echo (int)$r['errores_territorio']; ?></span></div>
                    <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Error de datos</span><span class="ac-kpi-inline__value"><?php echo (int)$r['errores_datos']; ?></span></div>
                </div>

                <div class="alert alert-warning">
                    <strong>Aún no se ha guardado ningún caso.</strong> Las filas con error o duplicadas no se crearán. Las filas marcadas como <strong>Lista para crear</strong> quedarán en <strong>Pendiente de revisión</strong>. Revise especialmente la columna <strong>Gestión</strong>: “Informativa · sin correo” significa que el caso podrá aprobarse, pero jamás será enviado por correo.
                </div>

                <div class="ac-table-wrap mb-3">
                    <table class="table table-hover ac-table mb-0">
                        <thead><tr><th>Fila</th><th>SIM</th><th>Regional del Excel</th><th>Punto del Excel</th><th>Territorio reconocido</th><th>Prioridad</th><th>Gestión</th><th>Rango de espera</th><th>Resultado</th><th>Detalle</th></tr></thead>
                        <tbody>
                        <?php foreach ($preview['filas'] as $fila): ?>
                            <tr>
                                <td><?php echo (int)$fila['fila_excel']; ?></td>
                                <td><strong><?php echo acEscape((string)$fila['sim']); ?></strong></td>
                                <td><?php echo acEscape((string)$fila['regional_archivo']); ?></td>
                                <td><?php echo acEscape((string)$fila['punto_archivo']); ?></td>
                                <td>
                                    <?php if ((int)$fila['regional_id'] > 0 && (int)$fila['punto_atencion_id'] > 0): ?>
                                        <strong><?php echo acEscape((string)$fila['regional_canonica']); ?></strong><br>
                                        <small><?php echo acEscape((string)$fila['punto_canonico']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Sin correspondencia completa</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo acEscape((string)$fila['tipo_alerta']); ?></td>
                                <td>
                                    <?php if (strtoupper((string)($fila['tipo_gestion'] ?? 'NOTIFICABLE')) === 'INFORMATIVA'): ?>
                                        <span class="badge badge-info">Informativa · sin correo</span>
                                    <?php else: ?>
                                        <span class="badge badge-primary">Notificable</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo strtoupper((string)($fila['tipo_gestion'] ?? '')) === 'INFORMATIVA' ? acEscape(acAlertaTiempoRangoLabel((string)($fila['tiempo_espera_rango'] ?? ''))) : '—'; ?></td>
                                <td><span class="badge <?php echo acEscape(acCargaAlertasBadge((string)$fila['estado'])); ?>"><?php echo acEscape(acCargaAlertasEstadoEtiqueta((string)$fila['estado'])); ?></span></td>
                                <td><small><?php echo acEscape((string)$fila['mensaje']); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ac-actions-bar ac-actions-bar--center">
                    <a href="alerta_correos_cargar_excel.php" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('¿Confirma la creación de <?php echo (int)$r['listas']; ?> caso(s)? Quedarán pendientes de revisión. Las alertas informativas de Tiempos de espera muy largos no enviarán correo, ni siquiera después de su aprobación.');">
                        <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                        <input type="hidden" name="accion" value="confirmar">
                        <input type="hidden" name="token_preview" value="<?php echo acEscape($tokenPreview); ?>">
                        <button class="btn ac-btn-green-outline" type="submit" <?php echo (int)$r['listas'] <= 0 ? 'disabled' : ''; ?>>
                            <span class="fas fa-check"></span> Crear <?php echo (int)$r['listas']; ?> caso(s)
                        </button>
                    </form>
                </div>
            </div>
        </section>
    <?php else: ?>
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <section class="ac-panel">
                    <div class="ac-panel__header">
                        <h2 class="ac-panel__title"><span class="fas fa-upload"></span> Seleccionar archivo de alertas</h2>
                        <span class="ac-panel__meta">Formato XLSX</span>
                    </div>
                    <div class="ac-panel__body">
                        <div class="ac-alert-box ac-alert-box--info mb-3">
                            <strong>Orden correcto del proceso:</strong> primero deben estar actualizados los <strong>Responsables territoriales</strong> y su catálogo de Regionales/Centros Zonales. Después se carga este archivo para crear los casos. Esta opción nunca agrega territorios nuevos automáticamente. Las filas con categoría <strong>Tiempos de espera muy largos</strong> serán identificadas como informativas y se clasificarán por rango de horas.
                        </div>

                        <div class="ac-mass-template-card">
                            <div class="ac-mass-template-card__main">
                                <div class="ac-mass-template-card__icon"><span class="fas fa-file-download"></span></div>
                                <div>
                                    <h3 class="ac-mass-template-card__title">Excel de ejemplo para la carga masiva</h3>
                                    <p class="ac-mass-template-card__text">Descargue esta plantilla y úsela como guía. Conserva los encabezados que reconoce el módulo e incluye un ejemplo notificable y uno informativo de <strong>Tiempos de espera muy largos</strong>.</p>
                                </div>
                            </div>
                            <div>
                                <a href="assets/plantillas/plantilla_alerta_masiva.xlsx" class="btn ac-btn-green-outline" download>
                                    <span class="fas fa-download"></span> Descargar Excel de ejemplo
                                </a>
                            </div>
                        </div>

                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                            <input type="hidden" name="accion" value="analizar">

                            <div class="form-group">
                                <label for="prioridad_defecto" class="ac-label">Prioridad para filas sin columna de prioridad</label>
                                <select id="prioridad_defecto" name="prioridad_defecto" class="form-control">
                                    <option value="MEDIA" selected>MEDIA (recomendada para el formato actual)</option>
                                    <option value="ALTA">ALTA</option>
                                    <option value="BAJA">BAJA</option>
                                    <option value="CRITICA">CRÍTICA</option>
                                </select>
                                <small class="form-text text-muted">El Excel recibido actualmente no incluye una columna de prioridad. Si en el futuro aparece una columna PRIORIDAD, TIPO DE ALERTA o SEVERIDAD, el valor de cada fila tendrá prioridad sobre esta selección.</small>
                            </div>

                            <div class="ac-upload-zone">
                                <div class="ac-upload-zone__icon"><span class="fas fa-file-excel"></span></div>
                                <div class="ac-upload-zone__title">Archivo oficial de alertas</div>
                                <div class="ac-upload-zone__text">Debe contener como mínimo: SIM asociado, Regional afectada, Punto de atención afectado y Descripción de la alerta.</div>
                                <label for="archivo" class="btn ac-btn-green-outline mb-0">Seleccionar archivo</label>
                                <input id="archivo" class="d-none" type="file" name="archivo" accept=".xlsx" required data-ac-file-input>
                                <div class="ac-upload-file-name" data-ac-file-name>Ningún archivo seleccionado</div>
                            </div>

                            <div class="ac-actions-bar ac-actions-bar--center">
                                <button class="btn ac-btn-green-outline" type="submit"><span class="fas fa-search"></span> Analizar antes de crear</button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
<script src="assets/alerta_correos.js?v=20260910"></script>
</body>
</html>
