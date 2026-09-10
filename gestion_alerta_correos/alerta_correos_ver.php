<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';
require_once __DIR__ . '/lib/alerta_correos_notificaciones.php';
require_once __DIR__ . '/lib/alerta_correos_territorio.php';
require_once __DIR__ . '/lib/alerta_correos_informativas.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit('Caso inválido.');
}

$caso = acObtenerCaso($enlace_db, (int)$id);
if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado.');
}

$historial = acHistorialCaso($enlace_db, (int)$id);
$esInformativa = acAlertaEsInformativa($caso);
$responsables = $esInformativa
    ? ['regional' => [], 'zonal' => [], '_origen' => 'NO_APLICA', '_fecha' => null]
    : acTerritorioDestinatariosParaVista($enlace_db, $caso);
$flash = acFlashTomar();
$titulo_header = 'Alertas Correos | ' . $caso['acc_radicado'];
$origenLabel = match ((string)($caso['acc_origen'] ?? '')) {
    'MANUAL' => 'Registro manual',
    'CARGA_EXCEL' => 'Carga Masiva',
    default => (string)($caso['acc_origen'] ?? ''),
};
$cargaOrigen = null;
if ((int)($caso['acc_carga_id'] ?? 0) > 0) {
    try {
        $stmtCarga = $enlace_db->prepare('SELECT acg_id, acg_archivo_nombre, acg_fecha FROM tb_alerta_correo_carga WHERE acg_id=? LIMIT 1');
        $cargaIdOrigen = (int)$caso['acc_carga_id'];
        $stmtCarga->bind_param('i', $cargaIdOrigen);
        $stmtCarga->execute();
        $cargaOrigen = $stmtCarga->get_result()->fetch_assoc() ?: null;
        $stmtCarga->close();
    } catch (Throwable $e) {
        error_log('Alertas Correos / ver origen carga: ' . $e->getMessage());
    }
}

$puedeResolver = acTienePerfil(['Gestor', 'Supervisor', 'Administrador'])
    && in_array($caso['acc_estado'], ['PENDIENTE_REVISION', 'PENDIENTE_REVISION_SUBSANACION'], true);
$puedeSubsanar = $caso['acc_estado'] === 'PENDIENTE_SUBSANACION'
    && acTienePerfil(['Operador', 'Gestor', 'Supervisor', 'Administrador']);
$puedeReabrir = $caso['acc_estado'] === 'RECHAZADO'
    && acTienePerfil(['Gestor', 'Supervisor', 'Administrador']);

$tieneRegional = count($responsables['regional']) > 0;
$tieneZonal = count($responsables['zonal']) > 0;
$esSnapshot = ($responsables['_origen'] ?? '') === 'SNAPSHOT';
$puedeVerCorreo = !$esInformativa && acTienePerfil(['Gestor', 'Supervisor', 'Administrador']);

// Estado de la notificación real en el motor central (si el caso ya fue aprobado).
$notificacionCentral = null;
$ncIdCaso = (int)($caso['acc_notificacion_central_id'] ?? 0);
if ($ncIdCaso > 0) {
    try {
        $stmtNc = $enlace_db->prepare(
            'SELECT nc_id, nc_address, nc_cc, nc_subject, nc_estado_envio, nc_intentos, nc_fecha_envio, nc_fecha_registro
             FROM tb_notificaciones_central WHERE nc_id=? LIMIT 1'
        );
        if ($stmtNc) {
            $stmtNc->bind_param('i', $ncIdCaso);
            $stmtNc->execute();
            $notificacionCentral = $stmtNc->get_result()->fetch_assoc() ?: null;
            $stmtNc->close();
        }
    } catch (Throwable $e) {
        error_log('Alertas Correos / ver notificacion central: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260909">
    <link rel="stylesheet" href="assets/alerta_correos_alertas.css?v=20260909-1">
    <style>
        /* Ajustes exclusivos de esta vista: no alteran el resto del módulo. */
        .ac-case-detail-page {
            padding-bottom: 92px;
        }

        .ac-case-trace-panel {
            margin-bottom: 26px;
        }

        .ac-recipient-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            flex: 0 0 auto;
            min-height: 30px;
            padding: 5px 11px;
            border: 1px solid #4caf50;
            border-radius: 999px;
            background: #4caf50;
            color: #ffffff !important;
            font-size: .82rem;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .10);
            user-select: none;
        }

        .ac-recipient-status .fas {
            color: #ffffff !important;
            font-size: .78rem;
        }

        .ac-recipient-status--history {
            background: #4caf50;
            border-color: #4caf50;
            color: #ffffff !important;
        }

        .ac-recipient-status--na {
            background: #6c757d;
            border-color: #6c757d;
            color: #ffffff !important;
        }

        /* Botón principal de aprobación: mismo verde institucional de los encabezados. */
        .ac-btn-approve {
            background: #4caf50 !important;
            border-color: #4caf50 !important;
            color: #ffffff !important;
            font-weight: 700;
            transition: transform .16s ease, box-shadow .16s ease, background-color .16s ease, border-color .16s ease;
        }

        .ac-btn-approve:hover,
        .ac-btn-approve:focus {
            background: #43a047 !important;
            border-color: #43a047 !important;
            color: #ffffff !important;
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(76, 175, 80, .28);
        }

        .ac-btn-approve:active {
            background: #388e3c !important;
            border-color: #388e3c !important;
            transform: translateY(0) scale(.985);
            box-shadow: 0 2px 5px rgba(76, 175, 80, .24);
        }

        .ac-btn-approve:disabled {
            transform: none;
            box-shadow: none;
        }

        /* Botón Volver: borde/texto rojo y relleno rojo al interactuar. */
        .ac-btn-back {
            background: #ffffff !important;
            border: 1px solid #dc3545 !important;
            color: #dc3545 !important;
            font-weight: 700;
            transition: transform .16s ease, box-shadow .16s ease, background-color .16s ease, border-color .16s ease, color .16s ease;
        }

        .ac-btn-back .fas {
            color: inherit !important;
        }

        .ac-btn-back:hover,
        .ac-btn-back:focus {
            background: #dc3545 !important;
            border-color: #dc3545 !important;
            color: #ffffff !important;
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(220, 53, 69, .22);
        }

        .ac-btn-back:active {
            background: #bd2130 !important;
            border-color: #bd2130 !important;
            color: #ffffff !important;
            transform: translateY(0) scale(.985);
            box-shadow: 0 2px 5px rgba(220, 53, 69, .20);
        }

        @media (max-width: 767.98px) {
            .ac-case-detail-page {
                padding-bottom: 112px;
            }

            .ac-case-trace-panel {
                margin-bottom: 22px;
            }

            .ac-panel__header {
                gap: 8px;
            }

            .ac-recipient-status {
                min-height: 28px;
                padding: 5px 9px;
                font-size: .76rem;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php
include '../menu_principal.php';
include '../menu_header.php';
?>

<div class="contenido ac-module ac-case-detail-page">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo acEscape($flash['tipo']); ?> d-none" role="alert" data-ac-alert-fallback>
            <span class="fas fa-info-circle mr-1"></span><?php echo acEscape($flash['mensaje']); ?>
        </div>
        <noscript>
            <div class="alert alert-<?php echo acEscape($flash['tipo']); ?>" role="alert">
                <span class="fas fa-info-circle mr-1"></span><?php echo acEscape($flash['mensaje']); ?>
            </div>
        </noscript>
    <?php endif; ?>

    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a><span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a><span class="ac-separator">/</span>
        <span><?php echo acEscape($caso['acc_radicado']); ?></span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title"><span class="fas fa-file-alt"></span><?php echo acEscape($caso['acc_radicado']); ?></h1>
            <div class="d-flex align-items-center flex-wrap">
                <span class="ac-badge ac-badge--<?php echo acEscape(acEstadoClase($caso['acc_estado'])); ?> mr-2 mb-1"><?php echo acEscape(acEstadoLabel($caso['acc_estado'])); ?></span>
                <?php if (!empty($caso['acc_sim'])): ?><span class="ac-page-subtitle mb-1">SIM: <strong><?php echo acEscape($caso['acc_sim']); ?></strong></span><?php endif; ?>
            </div>
        </div>
        <div class="ac-page-header__actions"><a href="alerta_correos.php" class="btn ac-btn-back"><span class="fas fa-arrow-left"></span> Volver</a></div>
    </header>

    <?php if ($esInformativa): ?>
        <div class="ac-alert-box ac-alert-box--info mb-3">
            <strong><span class="fas fa-info-circle mr-1"></span>Alerta informativa · sin correo.</strong>
            Este caso proviene de <strong>Carga Masiva</strong> y su categoría es <strong>Tiempos de espera muy largos</strong>. Puede continuar por revisión, subsanación, rechazo o aprobación, pero <strong>el sistema no generará ni encolará correo electrónico</strong>.
            Rango de espera: <strong><?php echo acEscape(acAlertaTiempoRangoLabel((string)($caso['acc_tiempo_espera_rango'] ?? ''))); ?></strong>.
        </div>
    <?php endif; ?>

    <div class="row">
        <main class="col-12 col-xl-8 mb-3 mb-xl-0">
            <section class="ac-panel">
                <div class="ac-panel__header">
                    <h2 class="ac-panel__title"><span class="fas fa-info-circle"></span> Información del caso</h2>
                    <span class="ac-panel__meta">Creado: <?php echo acEscape((string)$caso['acc_fecha_creacion']); ?></span>
                </div>
                <div class="ac-panel__body">
                    <div class="row ac-detail-grid">
                        <div class="col-6 col-md-4 ac-detail-item"><span class="ac-detail-label">Prioridad</span><span class="ac-detail-value"><span class="ac-badge ac-badge--<?php echo acEscape(acTipoClase($caso['acc_tipo_alerta'])); ?>"><?php echo acEscape($caso['acc_tipo_alerta']); ?></span></span></div>
                        <div class="col-6 col-md-4 ac-detail-item"><span class="ac-detail-label">Fecha de alerta</span><span class="ac-detail-value"><?php echo acEscape((string)$caso['acc_fecha_alerta']); ?></span></div>
                        <div class="col-6 col-md-4 ac-detail-item"><span class="ac-detail-label">Fecha de atención</span><span class="ac-detail-value"><?php echo acEscape((string)$caso['acc_fecha_atencion']); ?></span></div>
                        <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Regional</span><span class="ac-detail-value"><?php echo acEscape($caso['acc_regional']); ?></span></div>
                        <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Centro Zonal / Punto</span><span class="ac-detail-value"><?php echo acEscape($caso['acc_centro_zonal']); ?></span></div>
                        <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Categoría</span><span class="ac-detail-value"><?php echo acEscape($caso['acc_categoria']); ?></span></div>
                        <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Subcategoría</span><span class="ac-detail-value"><?php echo acEscape($caso['acc_subcategoria']); ?></span></div>
                        <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Afecta línea técnica</span><span class="ac-detail-value"><?php echo acEscape((string)$caso['acc_afecta_linea_tecnica']); ?></span></div>
                        <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Origen</span><span class="ac-detail-value"><?php echo acEscape($origenLabel); ?></span></div>
                        <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Tipo de gestión</span><span class="ac-detail-value"><?php echo $esInformativa ? '<span class="badge badge-info">Informativa · sin correo</span>' : '<span class="badge badge-primary">Notificable</span>'; ?></span></div>
                        <?php if ($esInformativa): ?>
                            <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Rango de tiempo de espera</span><span class="ac-detail-value"><?php echo acEscape(acAlertaTiempoRangoLabel((string)($caso['acc_tiempo_espera_rango'] ?? ''))); ?></span></div>
                            <?php if (!empty($caso['acc_tiempo_espera_minutos'])): ?>
                                <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Tiempo identificado</span><span class="ac-detail-value"><?php echo (int)$caso['acc_tiempo_espera_minutos']; ?> minutos</span></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="mt-2"><label class="ac-label">Descripción de la alerta</label><div class="ac-long-text"><?php echo acEscape((string)$caso['acc_descripcion']); ?></div></div>
                    <div class="mt-3"><label class="ac-label">Justificación / concepto técnico</label><div class="ac-long-text"><?php echo acEscape((string)$caso['acc_justificacion']); ?></div></div>
                    <?php if (!empty($caso['acc_observacion'])): ?><div class="mt-3"><label class="ac-label">Observación adicional</label><div class="ac-long-text"><?php echo acEscape((string)$caso['acc_observacion']); ?></div></div><?php endif; ?>

                    <?php if ((string)($caso['acc_origen'] ?? '') === 'CARGA_EXCEL'): ?>
                        <hr class="my-4">
                        <h3 class="h6 font-weight-bold mb-3"><span class="fas fa-file-excel mr-1"></span> Datos de origen de la carga</h3>
                        <div class="row ac-detail-grid">
                            <?php if ($cargaOrigen): ?>
                                <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Archivo</span><span class="ac-detail-value"><?php echo acEscape((string)$cargaOrigen['acg_archivo_nombre']); ?></span></div>
                                <div class="col-6 col-md-3 ac-detail-item"><span class="ac-detail-label">Carga</span><span class="ac-detail-value">#<?php echo (int)$cargaOrigen['acg_id']; ?></span></div>
                            <?php endif; ?>
                            <div class="col-6 col-md-3 ac-detail-item"><span class="ac-detail-label">Fila Excel</span><span class="ac-detail-value"><?php echo (int)($caso['acc_fila_origen'] ?? 0); ?></span></div>
                            <div class="col-12 col-md-6 ac-detail-item"><span class="ac-detail-label">Agente que registra</span><span class="ac-detail-value"><?php echo acEscape((string)($caso['acc_agente_registra'] ?? '')); ?></span></div>
                            <div class="col-6 col-md-3 ac-detail-item"><span class="ac-detail-label">Fecha marcación</span><span class="ac-detail-value"><?php echo acEscape((string)($caso['acc_fecha_marcacion'] ?? '')); ?></span></div>
                            <div class="col-6 col-md-3 ac-detail-item"><span class="ac-detail-label">Remisión a DSYA</span><span class="ac-detail-value"><?php echo acEscape((string)($caso['acc_fecha_remision_dsya'] ?? '')); ?></span></div>
                        </div>
                        <?php if (!empty($caso['acc_descripcion_inicial'])): ?>
                            <div class="mt-3"><label class="ac-label">Descripción de la alerta inicial</label><div class="ac-long-text"><?php echo acEscape((string)$caso['acc_descripcion_inicial']); ?></div></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="ac-panel ac-case-trace-panel">
                <div class="ac-panel__header"><h2 class="ac-panel__title"><span class="fas fa-history"></span> Trazabilidad del caso</h2><span class="ac-panel__meta"><?php echo count($historial); ?> evento(s)</span></div>
                <div class="ac-panel__body">
                    <?php if (!$historial): ?>
                        <div class="ac-empty py-3"><div class="ac-empty__icon"><span class="fas fa-history"></span></div><p class="ac-empty__title">Sin eventos registrados</p></div>
                    <?php else: ?>
                        <div class="ac-timeline">
                            <?php foreach ($historial as $evento): ?>
                                <article class="ac-timeline__item">
                                    <span class="ac-timeline__dot"></span>
                                    <h3 class="ac-timeline__title"><?php echo acEscape((string)$evento['ach_accion']); ?> · <?php echo acEscape(acEstadoLabel((string)$evento['ach_estado_nuevo'])); ?></h3>
                                    <p class="ac-timeline__meta"><?php echo acEscape((string)$evento['ach_fecha']); ?> · <?php echo acEscape((string)($evento['usu_nombres_apellidos'] ?? $evento['ach_usuario'])); ?></p>
                                    <?php if (!empty($evento['ach_comentario'])): ?><p class="ac-timeline__comment"><?php echo acEscape((string)$evento['ach_comentario']); ?></p><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>

        <aside class="col-12 col-xl-4">
            <section class="ac-panel mb-3">
                <div class="ac-panel__header">
                    <h2 class="ac-panel__title"><span class="fas fa-address-book"></span> Destinatarios</h2>
                    <?php if ($esInformativa): ?>
                        <span class="ac-recipient-status ac-recipient-status--na"><span class="fas fa-ban" aria-hidden="true"></span>No aplica</span>
                    <?php elseif ($esSnapshot): ?>
                        <span class="ac-recipient-status ac-recipient-status--history"><span class="fas fa-history" aria-hidden="true"></span>Histórico de aprobación</span>
                    <?php else: ?>
                        <span class="ac-recipient-status"><span class="fas fa-check-circle" aria-hidden="true"></span>Vigentes ahora</span>
                    <?php endif; ?>
                </div>
                <div class="ac-panel__body">
                    <?php if ($esInformativa): ?>
                        <div class="ac-alert-box ac-alert-box--info mb-0">
                            <span class="fas fa-ban mr-1"></span>
                            <strong>No requiere destinatarios.</strong> Esta alerta es informativa y, por regla de negocio, no genera correo al ser aprobada.
                        </div>
                    <?php else: ?>
                    <?php if ($esSnapshot): ?>
                        <div class="ac-alert-box ac-alert-box--info mb-3">
                            <span class="fas fa-lock mr-1"></span>
                            Esta información es el <strong>snapshot inmutable</strong> de los destinatarios utilizados al aprobar el caso<?php echo !empty($responsables['_fecha']) ? ' el ' . acEscape((string)$responsables['_fecha']) : ''; ?>.
                        </div>
                    <?php else: ?>
                        <div class="ac-alert-box ac-alert-box--info mb-3">
                            <span class="fas fa-sync-alt mr-1"></span>
                            Estos son los responsables vigentes que recibirán la notificación si el caso es aprobado en este momento.
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <span class="ac-detail-label">Responsable regional</span>
                        <?php if (!$tieneRegional): ?><div class="ac-alert-box ac-alert-box--danger mt-2"><span class="fas fa-exclamation-triangle mr-1"></span>No hay responsable regional disponible.</div><?php endif; ?>
                        <?php foreach ($responsables['regional'] as $responsable): ?>
                            <div class="ac-person"><div class="ac-person__name"><?php echo acEscape($responsable['acr_nombre']); ?></div><div class="ac-person__mail"><span class="fas fa-envelope"></span><?php echo acEscape($responsable['acr_correo']); ?></div></div>
                        <?php endforeach; ?>
                    </div>

                    <div>
                        <span class="ac-detail-label">Responsable zonal</span>
                        <?php if (!$tieneZonal): ?><div class="ac-alert-box ac-alert-box--warning mt-2"><span class="fas fa-exclamation-triangle mr-1"></span>No hay responsable zonal disponible para este punto.</div><?php endif; ?>
                        <?php foreach ($responsables['zonal'] as $responsable): ?>
                            <div class="ac-person"><div class="ac-person__name"><?php echo acEscape($responsable['acr_nombre']); ?></div><div class="ac-person__mail"><span class="fas fa-envelope"></span><?php echo acEscape($responsable['acr_correo']); ?></div></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($notificacionCentral): ?>
                <?php
                    $estadoNc = (string)($notificacionCentral['nc_estado_envio'] ?? 'Pendiente');
                    $estadoNcUpper = strtoupper($estadoNc);
                    $claseNc = str_contains($estadoNcUpper, 'ENVI') ? 'success' : (str_contains($estadoNcUpper, 'ERROR') ? 'danger' : 'info');
                ?>
                <section class="ac-panel mb-3">
                    <div class="ac-panel__header">
                        <h2 class="ac-panel__title"><span class="fas fa-paper-plane"></span> Notificación de correo</h2>
                        <span class="ac-badge ac-badge--<?php echo acEscape($claseNc); ?>"><?php echo acEscape($estadoNc); ?></span>
                    </div>
                    <div class="ac-panel__body">
                        <div class="ac-detail-item mb-2"><span class="ac-detail-label">Notificación central</span><span class="ac-detail-value">#<?php echo (int)$notificacionCentral['nc_id']; ?></span></div>
                        <div class="ac-detail-item mb-2"><span class="ac-detail-label">Asunto</span><span class="ac-detail-value"><?php echo acEscape((string)$notificacionCentral['nc_subject']); ?></span></div>
                        <div class="ac-detail-item mb-2"><span class="ac-detail-label">Intentos</span><span class="ac-detail-value"><?php echo acEscape((string)$notificacionCentral['nc_intentos']); ?></span></div>
                        <?php if (!empty($notificacionCentral['nc_fecha_envio'])): ?>
                            <div class="ac-detail-item"><span class="ac-detail-label">Fecha de envío</span><span class="ac-detail-value"><?php echo acEscape((string)$notificacionCentral['nc_fecha_envio']); ?></span></div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="ac-action-card">
                <div class="ac-action-card__header"><span class="fas fa-tasks mr-1"></span> Acciones disponibles</div>
                <div class="ac-action-card__body">
                    <?php if ($puedeVerCorreo): ?>
                        <a href="alerta_correos_correo_preview.php?id=<?php echo (int)$id; ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-block mb-3">
                            <span class="fas fa-envelope-open-text"></span>
                            <?php echo strtoupper((string)$caso['acc_estado']) === 'APROBADO' ? 'Ver correo generado' : 'Vista previa del correo'; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($puedeResolver): ?>
                        <?php if ($esInformativa): ?>
                            <div class="ac-alert-box ac-alert-box--info mb-3"><strong>Aprobación informativa:</strong> este caso se marcará como aprobado y se conservará en trazabilidad, pero no se generará correo.</div>
                            <div class="ac-action-group">
                                <form method="post" action="alerta_correos_aprobar.php" data-ac-lock-submit="1" data-ac-swal-confirm="1" data-ac-swal-title="¿Aprobar alerta informativa?" data-ac-swal-text="El caso quedará aprobado y NO se generará ni encolará correo electrónico." data-ac-swal-confirm-text="Sí, aprobar" data-ac-swal-icon="info">
                                    <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                                    <button class="btn btn-success btn-block" type="submit"><span class="fas fa-check"></span> Aprobar como informativa</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <?php if (!$tieneRegional || !$tieneZonal): ?><div class="ac-alert-box ac-alert-box--warning mb-3"><strong>Antes de aprobar:</strong> configure los destinatarios faltantes.</div><?php endif; ?>
                            <div class="ac-action-group">
                                <form method="post" action="alerta_correos_aprobar.php" data-ac-lock-submit="1" data-ac-swal-confirm="1" data-ac-swal-title="¿Aprobar y notificar?" data-ac-swal-text="Se resolverán los responsables vigentes y la notificación quedará encolada para envío." data-ac-swal-confirm-text="Sí, aprobar" data-ac-swal-icon="warning">
                                    <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                                    <button class="btn btn-success btn-block ac-btn-approve" type="submit" <?php echo (!$tieneRegional || !$tieneZonal) ? 'disabled' : ''; ?>><span class="fas fa-check"></span> Aprobar y notificar</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <div class="ac-action-group">
                            <form method="post" action="alerta_correos_solicitar_subsanacion.php" data-ac-lock-submit="1" data-ac-swal-confirm="1" data-ac-swal-title="¿Solicitar subsanación?" data-ac-swal-text="El caso regresará para corrección y no se enviará notificación territorial." data-ac-swal-confirm-text="Sí, solicitar" data-ac-swal-icon="question">
                                <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                                <textarea name="comentario" class="form-control mb-2" rows="3" required maxlength="20000" placeholder="Indique qué debe corregirse y por qué."></textarea>
                                <button class="btn btn-warning btn-block" type="submit"><span class="fas fa-tools"></span> Solicitar subsanación</button>
                            </form>
                        </div>
                        <div class="ac-action-group">
                            <form method="post" action="alerta_correos_rechazar.php" data-ac-lock-submit="1" data-ac-swal-confirm="1" data-ac-swal-title="¿Rechazar este caso?" data-ac-swal-text="El caso quedará rechazado y no se enviará correo a los responsables territoriales." data-ac-swal-confirm-text="Sí, rechazar" data-ac-swal-icon="warning">
                                <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                                <textarea name="comentario" class="form-control mb-2" rows="3" required maxlength="20000" placeholder="Registre el motivo obligatorio del rechazo."></textarea>
                                <button class="btn btn-danger btn-block" type="submit"><span class="fas fa-times"></span> Rechazar</button>
                            </form>
                        </div>
                    <?php elseif ($puedeSubsanar): ?>
                        <form method="post" action="alerta_correos_subsanar.php" data-ac-lock-submit="1" data-ac-swal-confirm="1" data-ac-swal-title="¿Enviar a nueva revisión?" data-ac-swal-text="La subsanación quedará registrada y el caso volverá a la bandeja de revisión." data-ac-swal-confirm-text="Sí, enviar" data-ac-swal-icon="question">
                            <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                            <label class="ac-label">Detalle de la subsanación <span class="ac-required">*</span></label>
                            <textarea name="comentario" class="form-control mb-2" rows="4" required maxlength="20000"></textarea>
                            <button class="btn btn-primary btn-block" type="submit"><span class="fas fa-paper-plane"></span> Enviar a nueva revisión</button>
                        </form>
                    <?php elseif ($puedeReabrir): ?>
                        <form method="post" action="alerta_correos_reabrir.php" data-ac-lock-submit="1" data-ac-swal-confirm="1" data-ac-swal-title="¿Reabrir este caso?" data-ac-swal-text="El caso volverá al ciclo de revisión." data-ac-swal-confirm-text="Sí, reabrir" data-ac-swal-icon="question">
                            <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                            <button class="btn btn-outline-primary btn-block" type="submit"><span class="fas fa-redo"></span> Reabrir caso</button>
                        </form>
                    <?php else: ?>
                        <div class="ac-empty py-2"><div class="ac-empty__icon"><span class="fas fa-lock"></span></div><p class="ac-empty__title">Sin acciones pendientes</p></div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php
include '../footer.php';
include '../config/configuracion_js.php';
?>
<script>
window.AC_ALERTAS_CONFIG = <?php echo json_encode([
    'errores' => [],
    'flash' => $flash ? [
        'tipo' => (string)($flash['tipo'] ?? 'info'),
        'mensaje' => (string)($flash['mensaje'] ?? ''),
    ] : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/alerta_correos.js?v=20260909"></script>
<script src="assets/alerta_correos_alertas.js?v=20260909-2"></script>
</body>
</html>

