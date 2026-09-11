<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_helpers.php';
require_once __DIR__ . '/alerta_correos_datos.php';
require_once __DIR__ . '/alerta_correos_reglas.php';
require_once __DIR__ . '/alerta_correos_auditoria.php';
require_once __DIR__ . '/alerta_correos_notificaciones.php';
require_once __DIR__ . '/alerta_correos_informativas.php';

function acEjecutarTransicion(mysqli $db, int $casoId, string $accion, string $comentario = ''): void
{
    $aprobadores=['Cliente','Administrador'];
    if (in_array($accion,['APROBAR','RECHAZAR','SOLICITAR_SUBSANACION','REABRIR'],true) && !acTienePerfil($aprobadores)) {
        throw new RuntimeException('Su perfil no tiene permiso para esta acción.');
    }
    if ($accion==='SUBSANAR' && !acTienePerfil(['Usuario','Administrador'])) {
        throw new RuntimeException('Su perfil no tiene permiso para subsanar.');
    }
    if (in_array($accion,['RECHAZAR','SOLICITAR_SUBSANACION','SUBSANAR'],true) && trim($comentario)==='') {
        throw new InvalidArgumentException('Debe registrar una observación o motivo.');
    }

    $db->begin_transaction();
    try {
        $caso=acObtenerCaso($db,$casoId,true);
        if (!$caso) throw new RuntimeException('Caso no encontrado.');

        // Una subsanación vuelve al agente que creó/tramitó originalmente la alerta.
        // El Administrador conserva capacidad de contingencia para evitar bloqueos operativos.
        if ($accion==='SUBSANAR' && !acTienePerfil(['Administrador'])) {
            if ((string)($caso['acc_usuario_creador'] ?? '') !== acUsuarioActual()) {
                throw new RuntimeException('La subsanación está asignada al agente que registró esta alerta.');
            }
        }

        $nuevo=acTransicionPermitida((string)$caso['acc_estado'],$accion);
        if ($nuevo===null) throw new RuntimeException('La transición solicitada no es válida para el estado actual.');

        $usuario=acUsuarioActual();
        $stmt=$db->prepare('UPDATE tb_alerta_correo_caso SET acc_estado=?,acc_observacion=CASE WHEN ?<>\'\' THEN ? ELSE acc_observacion END,acc_usuario_ultima_actualizacion=?,acc_version=acc_version+1 WHERE acc_id=? AND acc_estado=?');
        $stmt->bind_param('ssssss',$nuevo,$comentario,$comentario,$usuario,$casoId,$caso['acc_estado']);
        $stmt->execute();
        if ($stmt->affected_rows!==1) throw new RuntimeException('El caso fue modificado por otro usuario. Recargue la pantalla.');
        $stmt->close();

        acRegistrarHistorial($db,$casoId,(string)$caso['acc_estado'],$nuevo,$accion,$comentario);
        if ($accion==='APROBAR' && !acAlertaEsInformativa($caso)) {
            acEncolarAprobacion($db,$caso);
        }
        if ($accion==='SOLICITAR_SUBSANACION') {
            // La notificación se dirige al usuario que creó la alerta; nunca a territorio.
            // Un fallo del correo no debe impedir la asignación de la subsanación, pero tampoco
            // debe dejar una notificación central huérfana: se aísla con SAVEPOINT.
            $db->query('SAVEPOINT ac_subsanacion_notify');
            try {
                acEncolarSubsanacionAgente($db,$caso,$comentario);
                $db->query('RELEASE SAVEPOINT ac_subsanacion_notify');
            } catch (Throwable $notificacionError) {
                $db->query('ROLLBACK TO SAVEPOINT ac_subsanacion_notify');
                $db->query('RELEASE SAVEPOINT ac_subsanacion_notify');
                error_log('Alertas Correos / subsanación / notificación agente: ' . $notificacionError->getMessage());
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

