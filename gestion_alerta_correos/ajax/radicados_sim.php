<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$modulo_plataforma = 'Alertas Correos';
require_once '../../config/validaciones_seguridad.php';
require_once '../../config/conexion_db.php';
require_once __DIR__ . '/../lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/../lib/alerta_correos_territorio.php';
require_once __DIR__ . '/../lib/alerta_correos_sim.php';

try {
    acExigirPerfil(['Operador', 'Gestor', 'Supervisor', 'Administrador']);

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        echo json_encode(['ok' => true, 'data' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (mb_strlen($q, 'UTF-8') < 3) {
        echo json_encode([
            'ok' => true,
            'data' => [],
            'mensaje' => 'Digite al menos 3 caracteres del SIM, regional, centro zonal o categoría.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $rows = acSimBuscarRadicados($enlace_db, $q, 20);

    $data = array_map(static function (array $row): array {
        return [
            'radicado' => (string)$row['gera_radicado'],
            'fecha_peticion' => (string)($row['gera_fecha_peticion'] ?? ''),
            'peticionario' => (string)($row['gera_peticionario_nombre'] ?? ''),
            'estado' => (string)($row['gera_estado_gestion'] ?? ''),
            'regional' => (string)($row['regional_nombre'] ?? ''),
            'centro_zonal' => (string)($row['centro_zonal_nombre'] ?? ''),
            'regional_id' => (int)($row['regional_id'] ?? 0),
            'punto_atencion_id' => (int)($row['punto_atencion_id'] ?? 0),
            'regional_catalogo' => (string)($row['regional_catalogo'] ?? ''),
            'centro_zonal_catalogo' => (string)($row['centro_zonal_catalogo'] ?? ''),
            'territorio_mapeado' => (bool)($row['territorio_mapeado'] ?? false),
            'territorio_mensaje' => (string)($row['territorio_mensaje'] ?? ''),
            'mapeo_estado' => (string)($row['mapeo_estado'] ?? ''),
            'fuente' => (string)($row['fuente'] ?? ''),
            'fecha_alerta' => (string)($row['fecha_alerta'] ?? ''),
            'fecha_atencion' => (string)($row['fecha_atencion'] ?? ''),
            'categoria' => (string)($row['categoria'] ?? ''),
            'descripcion' => (string)($row['descripcion'] ?? ''),
            'afecta_linea_tecnica' => (string)($row['afecta_linea_tecnica'] ?? ''),
            'fecha_remision_agente' => (string)($row['fecha_remision_agente'] ?? ''),
            'justificacion' => (string)($row['justificacion'] ?? ''),
            'fecha_notificacion_regional' => (string)($row['fecha_notificacion_regional'] ?? ''),
            'subcategoria' => (string)($row['subcategoria'] ?? ''),
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Alertas Correos / selector SIM: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No fue posible consultar la fuente de radicados SIM.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
