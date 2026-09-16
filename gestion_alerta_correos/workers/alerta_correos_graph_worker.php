<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once dirname(__DIR__, 2) . '/config/conexion_db.php';
require_once dirname(__DIR__) . '/lib/alerta_correos_graph.php';

const AC_GRAPH_MAX_INTENTOS = 8;
const AC_GRAPH_LOTE = 20;

$lock = fopen('/tmp/icbf_alertas_correos_graph.lock', 'c');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    $stmt = $enlace_db->prepare(
        "SELECT
            acn_id,
            acn_caso_id,
            acn_to,
            acn_cc,
            acn_bcc,
            acn_asunto,
            acn_cuerpo,
            acn_intentos
         FROM tb_alerta_correo_notificacion
         WHERE acn_estado='PENDIENTE'
           AND acn_evento='APROBACION'
           AND acn_nc_id IS NULL
           AND acn_notificacion_central_id IS NULL
           AND acn_fecha_encolada IS NOT NULL
           AND acn_intentos < ?
         ORDER BY acn_id
         LIMIT " . AC_GRAPH_LOTE
    );

    $max = AC_GRAPH_MAX_INTENTOS;

    $stmt->bind_param('i', $max);
    $stmt->execute();

    $pendientes = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    foreach ($pendientes as $n) {
        $id = (int)$n['acn_id'];

        // Reserva simple para este worker.
        $update = $enlace_db->prepare(
            "UPDATE tb_alerta_correo_notificacion
             SET acn_estado='PROCESANDO'
             WHERE acn_id=?
               AND acn_estado='PENDIENTE'"
        );

        $update->bind_param('i', $id);
        $update->execute();

        $afectadas = $update->affected_rows;

        $update->close();

        if ($afectadas !== 1) {
            continue;
        }

        try {
            acGraphEnviarCorreo($n);

            $ok = $enlace_db->prepare(
                "UPDATE tb_alerta_correo_notificacion
                 SET acn_estado='ENVIADO',
                     acn_fecha_envio=NOW(),
                     acn_ultimo_error=NULL
                 WHERE acn_id=?"
            );

            $ok->bind_param('i', $id);
            $ok->execute();
            $ok->close();

            echo '[' . date('Y-m-d H:i:s') . "] "
                . "ENVIADO acn_id={$id}\n";

        } catch (Throwable $e) {
            $intentos = ((int)$n['acn_intentos']) + 1;

            $estado = $intentos >= AC_GRAPH_MAX_INTENTOS
                ? 'ERROR'
                : 'PENDIENTE';

            $error = mb_substr(
                $e->getMessage(),
                0,
                4000,
                'UTF-8'
            );

            $fail = $enlace_db->prepare(
                "UPDATE tb_alerta_correo_notificacion
                 SET acn_estado=?,
                     acn_intentos=?,
                     acn_ultimo_error=?
                 WHERE acn_id=?"
            );

            $fail->bind_param(
                'sisi',
                $estado,
                $intentos,
                $error,
                $id
            );

            $fail->execute();
            $fail->close();

            echo '[' . date('Y-m-d H:i:s') . "] "
                . "FALLO acn_id={$id} "
                . "intento={$intentos}: {$error}\n";
        }
    }

} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
