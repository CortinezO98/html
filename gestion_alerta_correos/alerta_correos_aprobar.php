<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';
require_once __DIR__ . '/lib/alerta_correos_email_oficial.php';
require_once __DIR__ . '/lib/alerta_correos_informativas.php';

acExigirPerfil(['Gestor', 'Supervisor', 'Administrador']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

acValidarCsrfPost();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    acFlash('danger', 'No fue posible identificar el caso a aprobar.');
    header('Location: alerta_correos.php');
    exit;
}

$usuario = (string)acUsuarioActual();
$fechaAprobacion = date('Y-m-d H:i:s');

try {
    $enlace_db->begin_transaction();

    $stmt = $enlace_db->prepare('SELECT * FROM tb_alerta_correo_caso WHERE acc_id=? FOR UPDATE');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $caso = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$caso) throw new RuntimeException('El caso no existe.');

    $estadoAnterior = strtoupper(trim((string)$caso['acc_estado']));
    if (!in_array($estadoAnterior, ['PENDIENTE_REVISION', 'PENDIENTE_REVISION_SUBSANACION'], true)) {
        throw new RuntimeException('El caso ya no se encuentra en un estado que permita aprobación. Estado actual: ' . $estadoAnterior . '.');
    }

    // Regla especial: los casos creados por Carga Masiva con categoría
    // “Tiempos de espera muy largos” son informativos. Se aprueban y se auditan,
    // pero no requieren responsables y nunca generan/enfilan un correo.
    if (acAlertaEsInformativa($caso)) {
        $stmt = $enlace_db->prepare(
            "UPDATE tb_alerta_correo_caso
             SET acc_estado='APROBADO',
                 acc_tipo_gestion='INFORMATIVA',
                 acc_envia_correo=0,
                 acc_notificacion_central_id=NULL,
                 acc_usuario_ultima_actualizacion=?,
                 acc_version=acc_version+1,
                 acc_fecha_aprobacion=?,
                 acc_usuario_aprobador=?
             WHERE acc_id=?"
        );
        $stmt->bind_param('sssi', $usuario, $fechaAprobacion, $usuario, $id);
        $stmt->execute();
        $stmt->close();

        acRegistrarHistorial(
            $enlace_db,
            (int)$id,
            $estadoAnterior,
            'APROBADO',
            'APROBAR_INFORMATIVA',
            'Alerta informativa aprobada. Por regla de negocio no se generó ni se encoló correo electrónico.',
            [
                'categoria' => (string)($caso['acc_categoria'] ?? ''),
                'rango_espera' => (string)($caso['acc_tiempo_espera_rango'] ?? ''),
                'minutos_espera' => $caso['acc_tiempo_espera_minutos'] ?? null,
                'envia_correo' => 0,
            ]
        );

        $enlace_db->commit();

        if (function_exists('registro_log')) {
            try {
                registro_log($enlace_db, $modulo_plataforma, 'aprobar_informativa', 'Caso ' . ($caso['acc_radicado'] ?? $id) . ' aprobado sin correo.');
            } catch (Throwable $logError) {
                error_log('Alertas Correos / log aprobación informativa: ' . $logError->getMessage());
            }
        }

        acFlash('success', 'Caso informativo aprobado correctamente. No se generó ni se encoló correo electrónico.');
        header('Location: alerta_correos_ver.php?id=' . (int)$id);
        exit;
    }

    // Preflight: antes de tocar el estado, comprobar que existen ambos roles con correo válido.
    $vigentes = acEmailResolverDestinatariosVigentes($enlace_db, $caso);
    if (!$vigentes['regional']) {
        throw new RuntimeException('No es posible aprobar: la Regional seleccionada no tiene un responsable vigente con correo válido.');
    }
    if (!$vigentes['zonal']) {
        throw new RuntimeException('No es posible aprobar: el Centro Zonal / Punto seleccionado no tiene un responsable vigente con correo válido.');
    }

    // Cambio de estado. El trigger territorial, si existe, genera el snapshot automáticamente.
    $stmt = $enlace_db->prepare(
        "UPDATE tb_alerta_correo_caso
         SET acc_estado='APROBADO',
             acc_usuario_ultima_actualizacion=?,
             acc_version=acc_version+1,
             acc_fecha_aprobacion=?,
             acc_usuario_aprobador=?
         WHERE acc_id=?"
    );
    $stmt->bind_param('sssi', $usuario, $fechaAprobacion, $usuario, $id);
    $stmt->execute();
    $stmt->close();

    // Usar SIEMPRE el snapshot inmutable como fuente de la plantilla. Si no hay trigger,
    // se crea manualmente con los responsables validados justo antes de aprobar.
    $snapshot = acEmailAsegurarSnapshot($enlace_db, (int)$id, $vigentes);
    $plantilla = acEmailConstruirPlantilla($caso, $snapshot, $fechaAprobacion);

    // Encola en el motor central ya existente del portal.
    $ncId = acEmailEncolarCentral($enlace_db, $caso, $plantilla, $usuario);

    // Enlaza el caso con la cola real para consultar el estado de entrega posteriormente.
    $version = (string)$plantilla['version'];
    $stmt = $enlace_db->prepare(
        'UPDATE tb_alerta_correo_caso
         SET acc_notificacion_central_id=?, acc_email_template_version=?
         WHERE acc_id=?'
    );
    $stmt->bind_param('isi', $ncId, $version, $id);
    $stmt->execute();
    $stmt->close();

    acEmailRegistrarAuditoriaLocal($enlace_db, (int)$id, $ncId, $plantilla, $usuario, $fechaAprobacion);
    acEmailRegistrarHistorialAprobacion($enlace_db, (int)$id, $estadoAnterior, $ncId, $plantilla, $usuario);

    $enlace_db->commit();

    // El log general es complementario: si falla, no debe convertir una aprobación ya confirmada
    // en un falso error para el usuario. La trazabilidad propia del módulo ya quedó dentro de la transacción.
    if (function_exists('registro_log')) {
        try {
            registro_log($enlace_db, $modulo_plataforma, 'aprobar_notificar', 'Caso ' . ($caso['acc_radicado'] ?? $id) . ' / NC #' . $ncId);
        } catch (Throwable $logError) {
            error_log('Alertas Correos / log aprobación: ' . $logError->getMessage());
        }
    }

    acFlash('success', 'Caso aprobado correctamente. La notificación oficial quedó encolada para envío con los responsables regional y zonal del snapshot de aprobación.');
} catch (Throwable $e) {
    try { $enlace_db->rollback(); } catch (Throwable $ignored) {}
    error_log('Alertas Correos / aprobar: ' . $e->getMessage());
    acFlash('danger', $e->getMessage());
}

header('Location: alerta_correos_ver.php?id=' . (int)$id);
exit;

