<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';
require_once __DIR__ . '/lib/alerta_correos_email_oficial.php';

acExigirPerfil(['Gestor', 'Supervisor', 'Administrador']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit('Caso inválido.'); }
$caso = acObtenerCaso($enlace_db, (int)$id);
if (!$caso) { http_response_code(404); exit('Caso no encontrado.'); }

$error = '';
$plantilla = null;
$destinatarios = ['regional' => [], 'zonal' => []];
$esCorreoYaGenerado = false;
$estadoCentral = null;

try {
    $ncId = (int)($caso['acc_notificacion_central_id'] ?? 0);

    // Si ya fue aprobada y existe la fila de cola, mostrar EXACTAMENTE el correo
    // que quedó encolado, no reconstruirlo con datos actuales.
    if ($ncId > 0) {
        $stmt = $enlace_db->prepare(
            'SELECT nc_id, nc_address, nc_cc, nc_subject, nc_body, nc_estado_envio, nc_intentos, nc_fecha_envio, nc_fecha_registro
             FROM tb_notificaciones_central WHERE nc_id=? LIMIT 1'
        );
        $stmt->bind_param('i', $ncId);
        $stmt->execute();
        $central = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($central) {
            $destinatarios = acEmailObtenerSnapshot($enlace_db, (int)$id);
            $plantilla = [
                'version' => (string)($caso['acc_email_template_version'] ?? AC_EMAIL_TEMPLATE_VERSION),
                'subject' => (string)$central['nc_subject'],
                'body' => (string)$central['nc_body'],
                'to' => (string)$central['nc_address'],
                'cc' => (string)$central['nc_cc'],
                'to_personas' => $destinatarios['zonal'],
                'cc_personas' => $destinatarios['regional'],
            ];
            $estadoCentral = $central;
            $esCorreoYaGenerado = true;
        }
    }

    // Antes de aprobar (o si por alguna razón no existe la fila central), simular
    // la plantilla con los responsables vigentes. Esta acción NO inserta nada.
    if (!$plantilla) {
        $destinatarios = strtoupper((string)$caso['acc_estado']) === 'APROBADO'
            ? acEmailObtenerSnapshot($enlace_db, (int)$id)
            : acEmailResolverDestinatariosVigentes($enlace_db, $caso);

        $fechaPlantilla = (string)($caso['acc_fecha_aprobacion'] ?? '');
        if ($fechaPlantilla === '') $fechaPlantilla = date('Y-m-d H:i:s');
        $plantilla = acEmailConstruirPlantilla($caso, $destinatarios, $fechaPlantilla);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$titulo_header = 'Alertas Correos | Vista previa de correo';
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260909">
</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>
<div class="contenido ac-module">
    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a><span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a><span class="ac-separator">/</span>
        <a href="alerta_correos_ver.php?id=<?php echo (int)$id; ?>"><?php echo acEscape((string)$caso['acc_radicado']); ?></a><span class="ac-separator">/</span>
        <span>Correo oficial</span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title"><span class="fas fa-envelope-open-text"></span> <?php echo $esCorreoYaGenerado ? 'Correo oficial generado' : 'Vista previa del correo oficial'; ?></h1>
            <p class="ac-page-subtitle">
                <?php echo $esCorreoYaGenerado
                    ? 'Se muestra exactamente el asunto, destinatarios y cuerpo HTML que quedaron registrados en la cola central.'
                    : 'Esta pantalla es solo una simulación. No envía ni encola ningún correo.'; ?>
            </p>
        </div>
        <div class="ac-page-header__actions"><a href="alerta_correos_ver.php?id=<?php echo (int)$id; ?>" class="btn btn-light"><span class="fas fa-arrow-left"></span> Volver al caso</a></div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><span class="fas fa-exclamation-triangle mr-1"></span><?php echo acEscape($error); ?></div>
    <?php else: ?>
        <section class="ac-panel mb-3">
            <div class="ac-panel__header">
                <h2 class="ac-panel__title"><span class="fas fa-users"></span> Entrega <?php echo $esCorreoYaGenerado ? 'registrada' : 'prevista'; ?></h2>
                <span class="ac-panel__meta">Plantilla <?php echo acEscape((string)$plantilla['version']); ?></span>
            </div>
            <div class="ac-panel__body">
                <?php if ($esCorreoYaGenerado && $estadoCentral): ?>
                    <div class="ac-alert-box ac-alert-box--info mb-3">
                        <strong>Cola central #<?php echo (int)$estadoCentral['nc_id']; ?>:</strong>
                        estado <?php echo acEscape((string)$estadoCentral['nc_estado_envio']); ?>,
                        intentos <?php echo acEscape((string)$estadoCentral['nc_intentos']); ?>.
                    </div>
                <?php endif; ?>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <span class="ac-detail-label">PARA · Zonal</span>
                        <?php foreach ($destinatarios['zonal'] as $p): ?>
                            <div class="ac-person mt-2"><div class="ac-person__name"><?php echo acEscape((string)$p['nombre']); ?></div><div class="ac-person__mail"><span class="fas fa-envelope"></span><?php echo acEscape((string)$p['correo']); ?></div></div>
                        <?php endforeach; ?>
                        <?php if (!$destinatarios['zonal']): ?><div class="small text-muted mt-2"><?php echo acEscape((string)$plantilla['to']); ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6 mb-2">
                        <span class="ac-detail-label">CC · Regional</span>
                        <?php foreach ($destinatarios['regional'] as $p): ?>
                            <div class="ac-person mt-2"><div class="ac-person__name"><?php echo acEscape((string)$p['nombre']); ?></div><div class="ac-person__mail"><span class="fas fa-envelope"></span><?php echo acEscape((string)$p['correo']); ?></div></div>
                        <?php endforeach; ?>
                        <?php if ((string)$plantilla['cc'] === ''): ?><div class="small text-muted mt-2">Si el correo regional coincide con uno de PARA, se deduplica y no se agrega nuevamente en CC.</div><?php endif; ?>
                    </div>
                </div>
                <hr>
                <div><strong>PARA en cola:</strong> <?php echo acEscape((string)$plantilla['to']); ?></div>
                <div><strong>CC en cola:</strong> <?php echo acEscape((string)$plantilla['cc'] !== '' ? (string)$plantilla['cc'] : 'Sin CC adicional por deduplicación'); ?></div>
                <div class="mt-2"><strong>Asunto:</strong> <?php echo acEscape((string)$plantilla['subject']); ?></div>
            </div>
        </section>
        <section class="ac-panel">
            <div class="ac-panel__header"><h2 class="ac-panel__title"><span class="fas fa-eye"></span> Contenido HTML</h2><span class="ac-panel__meta"><?php echo $esCorreoYaGenerado ? 'Contenido encolado' : 'Solo vista previa'; ?></span></div>
            <div class="ac-panel__body" style="background:#eef2ef;padding:20px;overflow:auto;">
                <?php echo (string)$plantilla['body']; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
</body>
</html>
