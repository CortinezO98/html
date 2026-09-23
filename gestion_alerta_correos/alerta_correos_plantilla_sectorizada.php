<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
acExigirPerfil(['Usuario', 'Administrador']);

$tipo = strtolower(trim((string)($_GET['tipo'] ?? '')));
$nombre = '';
$filas = [];

if ($tipo === 'territorios') {
    $nombre = 'Plantilla_Territorios_Alertas_Correos.csv';
    $filas = [
        ['NIVEL','REGIONAL','CENTRO_ZONAL','CODIGO_CENTRO','ESTADO'],
        ['REGIONAL','BOGOTA','','1100','ACTIVO'],
        ['ZONAL','BOGOTA','C.Z. BOSA','1108','ACTIVO'],
    ];
} elseif ($tipo === 'coordinadores') {
    $nombre = 'Plantilla_Coordinadores_Alertas_Correos.csv';
    $filas = [
        ['CODIGO_CENTRO','DOCUMENTO','NOMBRE','CORREO','EXTENSION_IP','ESTADO'],
        ['1108','1000000001','Nombre Coordinador','coordinador@icbf.gov.co','1234','ACTIVO'],
    ];
} elseif ($tipo === 'responsables') {
    $nombre = 'Plantilla_Responsables_Alertas_Correos.csv';
    $filas = [
        ['CODIGO_CENTRO','DOCUMENTO','NOMBRE','CORREO','EXTENSION_IP','ESTADO'],
        ['1100','1000000002','Nombre Enlace Regional','enlace.regional@icbf.gov.co','1234','ACTIVO'],
    ];
} else {
    http_response_code(400);
    exit('Tipo de plantilla no válido.');
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
foreach ($filas as $fila) {
    fputcsv($out, $fila, ';');
}
fclose($out);
exit;
