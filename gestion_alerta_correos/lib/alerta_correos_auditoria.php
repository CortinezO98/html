<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_helpers.php';

function acRegistrarHistorial(
    mysqli $db,
    int $casoId,
    ?string $estadoAnterior,
    string $estadoNuevo,
    string $accion,
    ?string $comentario,
    array $metadatos = []
): void {
    $usuario = acUsuarioActual();
    $ip = acClienteIp();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $json = $metadatos ? json_encode($metadatos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_historial
        (ach_caso_id, ach_estado_anterior, ach_estado_nuevo, ach_accion, ach_comentario, ach_metadatos, ach_usuario, ach_ip, ach_user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssssss', $casoId, $estadoAnterior, $estadoNuevo, $accion, $comentario, $json, $usuario, $ip, $ua);
    $stmt->execute();
    $stmt->close();

    try {
        $modulo = 'Alertas Correos';
        $tipo = 'GESTION';
        $detalle = substr(json_encode([
            'caso' => $casoId,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'accion' => $accion,
        ], JSON_UNESCAPED_UNICODE) ?: '', 0, 1000);
        $accionLog = substr($accion, 0, 200);
        $usuarioLog = substr($usuario, 0, 10);
        $log = $db->prepare('INSERT INTO tb_administrador_log (clog_log_modulo, clog_log_tipo, clog_log_accion, clog_log_detalle, clog_registro_usuario) VALUES (?,?,?,?,?)');
        $log->bind_param('sssss', $modulo, $tipo, $accionLog, $detalle, $usuarioLog);
        $log->execute();
        $log->close();
    } catch (Throwable $e) {
        error_log('Alertas Correos: no fue posible registrar log legacy: ' . $e->getMessage());
    }
}
