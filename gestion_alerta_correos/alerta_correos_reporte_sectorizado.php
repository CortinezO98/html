<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';

acExigirPerfil(['Usuario', 'Administrador']);

$tipo = strtolower(trim((string)($_GET['tipo'] ?? '')));
$fecha = date('Ymd_His');
$nombre = '';
$filas = [];

if ($tipo === 'territorios') {
    $nombre = 'Reporte_Actual_Territorios_Alertas_Correos_' . $fecha . '.csv';
    $filas[] = ['NIVEL', 'REGIONAL', 'CENTRO_ZONAL', 'CODIGO_CENTRO', 'ESTADO'];

    $sql = "SELECT acp_tipo, acp_regional, acp_nombre, acp_codigo
            FROM tb_alerta_correo_punto_atencion
            WHERE acp_activo=1
            ORDER BY acp_regional ASC,
                     CASE WHEN acp_tipo='REGIONAL' THEN 0 ELSE 1 END,
                     acp_nombre ASC";

    $rs = $enlace_db->query($sql);
    while ($row = $rs->fetch_assoc()) {
        $esRegional = strtoupper(trim((string)$row['acp_tipo'])) === 'REGIONAL';
        $filas[] = [
            $esRegional ? 'REGIONAL' : 'ZONAL',
            (string)$row['acp_regional'],
            $esRegional ? '' : (string)$row['acp_nombre'],
            (string)($row['acp_codigo'] ?? ''),
            'ACTIVO',
        ];
    }
} elseif (in_array($tipo, ['coordinadores', 'responsables'], true)) {
    $esCoordinador = $tipo === 'coordinadores';
    $tipoResponsable = $esCoordinador ? 'COORDINADOR' : 'ENLACE_RELACION_CIUDADANO';
    $tipoTerritorio = $esCoordinador ? 'CENTRO_ZONAL' : 'REGIONAL';
    $nombre = ($esCoordinador ? 'Reporte_Actual_Coordinadores_Zonales_Alertas_Correos_' : 'Reporte_Actual_Enlaces_Regionales_Alertas_Correos_') . $fecha . '.csv';
    $filas[] = ['CODIGO_CENTRO', 'DOCUMENTO', 'NOMBRE', 'CORREO', 'EXTENSION_IP', 'ESTADO'];

    $stmt = $enlace_db->prepare(
        "SELECT
            COALESCE(NULLIF(TRIM(p.acp_codigo),''), NULLIF(TRIM(r.acr_codigo_centro),''), '') AS codigo_centro,
            COALESCE(r.acr_documento,'') AS documento,
            r.acr_nombre,
            r.acr_correo,
            COALESCE(r.acr_extension_ip,'') AS extension_ip
         FROM tb_alerta_correo_responsable r
         LEFT JOIN tb_alerta_correo_punto_atencion p
           ON p.acp_id=r.acr_punto_atencion_id
         WHERE r.acr_activo=1
           AND UPPER(TRIM(COALESCE(r.acr_tipo_responsable,'')))=?
           AND UPPER(TRIM(COALESCE(p.acp_tipo,'')))=?
         ORDER BY COALESCE(p.acp_regional, r.acr_regional) ASC,
                  COALESCE(p.acp_nombre, r.acr_centro_zonal) ASC,
                  r.acr_nombre ASC"
    );
    $stmt->bind_param('ss', $tipoResponsable, $tipoTerritorio);
    $stmt->execute();
    $rs = $stmt->get_result();

    while ($row = $rs->fetch_assoc()) {
        $filas[] = [
            (string)$row['codigo_centro'],
            (string)$row['documento'],
            (string)$row['acr_nombre'],
            (string)$row['acr_correo'],
            (string)$row['extension_ip'],
            'ACTIVO',
        ];
    }
    $stmt->close();
} else {
    http_response_code(400);
    exit('Tipo de reporte no válido.');
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit('No fue posible generar el reporte.');
}

fwrite($out, "\xEF\xBB\xBF");
foreach ($filas as $fila) {
    fputcsv($out, $fila, ';');
}
fclose($out);
exit;
