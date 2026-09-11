<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../../config/validaciones_seguridad.php';
require_once '../../config/conexion_db.php';
require_once dirname(__DIR__) . '/lib/alerta_correos_seguridad.php';
require_once dirname(__DIR__) . '/lib/alerta_correos_territorio.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    acExigirPerfil(['Usuario', 'Supervisor', 'Administrador']);

    $regionalId = filter_input(INPUT_GET, 'regional_id', FILTER_VALIDATE_INT);
    if (!$regionalId || $regionalId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensaje' => 'Regional inválida.', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $regional = acTerritorioObtenerRegional($enlace_db, (int)$regionalId);
    if (!$regional || (int)$regional['acp_activo'] !== 1) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensaje' => 'Regional no disponible.', 'data' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $puntos = acTerritorioListarPuntosRegional($enlace_db, (int)$regionalId);
    echo json_encode(['ok' => true, 'data' => $puntos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Alertas Correos / centros_zonales_por_regional: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'No fue posible consultar los puntos de atención.', 'data' => []], JSON_UNESCAPED_UNICODE);
}
