<?php
// gestion_calidad_monitoreo_reporte_excel.php

// Validación de permisos del usuario para el módulo
$modulo_plataforma = "Calidad-Monitoreos";
require_once("../config/validaciones_seguridad.php");
require_once("../config/conexion_db.php");
require_once('../PHPOffice/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

// ============================
// Hardening + performance
// ============================
@ini_set('max_execution_time', '0'); // evita corte PHP
@set_time_limit(0);
@ini_set('memory_limit', '1024M');   // ajusta si el server es pequeño

// Cache a disco para bajar consumo RAM (muy útil en reportes grandes)
try {
    $tmpDir = sys_get_temp_dir();
    if (is_dir($tmpDir) && is_writable($tmpDir)) {
        \PhpOffice\PhpSpreadsheet\Settings::setCacheStorageMethod(
            \PhpOffice\PhpSpreadsheet\CachedObjectStorageFactory::cache_to_discISAM,
            ['dir' => $tmpDir]
        );
    }
} catch (\Throwable $e) {
    // Si falla el caching, no rompemos funcionalidad
}

$array_meses = [1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril", 5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto", 9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"];

// array columnas (tal cual)
$array_columnas = [];
foreach (range('A','Z') as $c) { $array_columnas[] = $c; }
foreach (range('A','Z') as $c) { $array_columnas[] = 'A'.$c; }
foreach (range('A','Z') as $c) { $array_columnas[] = 'B'.$c; }
foreach (range('A','Z') as $c) { $array_columnas[] = 'C'.$c; }

// Helpers ZIP (los dejas porque los usas en Backup)
if (!function_exists('zip_log')) {
    function zip_log(string $msg): void { error_log('[ZIP] ' . $msg); }
}
if (!function_exists('resolver_ruta_soporte')) {
    function resolver_ruta_soporte(string $ruta_fichero): string {
        $ruta_fichero = trim($ruta_fichero);
        if ($ruta_fichero === '') return '';
        if (strpos($ruta_fichero, '/') === 0) return $ruta_fichero;
        if (preg_match('~^[A-Za-z]:\\\\~', $ruta_fichero)) return $ruta_fichero;

        $ruta_limpia = ltrim($ruta_fichero, "/\\");
        $candidatas = [];
        $candidatas[] = __DIR__ . DIRECTORY_SEPARATOR . $ruta_limpia;
        $candidatas[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . $ruta_limpia;

        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $candidatas[] = rtrim($_SERVER['DOCUMENT_ROOT'], "/\\") . DIRECTORY_SEPARATOR . $ruta_limpia;
        }
        foreach ($candidatas as $c) {
            if (is_file($c)) return $c;
        }
        return $ruta_fichero;
    }
}
if (!function_exists('limpiar_buffers')) {
    function limpiar_buffers(): void {
        while (ob_get_level() > 0) { @ob_end_clean(); }
    }
}
if (!function_exists('enviar_zip')) {
    function enviar_zip(string $zipPath, string $downloadName, callable $cleanup = null): void {
        clearstatcache(true, $zipPath);

        if (!is_file($zipPath)) {
            header("HTTP/1.1 500 Internal Server Error");
            echo "No se pudo generar el archivo ZIP.";
            return;
        }
        $size = filesize($zipPath);
        if ($size === false || $size <= 0) {
            header("HTTP/1.1 500 Internal Server Error");
            echo "No se pudo generar el archivo ZIP.";
            return;
        }

        limpiar_buffers();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        readfile($zipPath);

        if ($cleanup) { $cleanup(); }
    }
}

// ============================
// Helpers DB performance
// ============================
/**
 * Ejecuta query IN por lotes (evita query enorme)
 * Retorna arreglo de filas MYSQLI_NUM
 */
if (!function_exists('fetch_in_batches')) {
    function fetch_in_batches(mysqli $db, string $baseSqlWithIn, array $ids, int $batchSize = 500, string $typesForIds = ''): array {
        $all = [];
        $total = count($ids);
        if ($total === 0) return $all;

        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $slice = array_slice($ids, $offset, $batchSize);
            $placeholders = implode(',', array_fill(0, count($slice), '?'));
            $sql = str_replace('{IN}', $placeholders, $baseSqlWithIn);

            $stmt = $db->prepare($sql);
            if (!$stmt) { continue; }

            $types = $typesForIds !== '' ? $typesForIds : str_repeat('s', count($slice));
            $stmt->bind_param($types, ...$slice);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
            $stmt->close();

            foreach ($rows as $r) { $all[] = $r; }
        }
        return $all;
    }
}

if (isset($_POST["exportar_reporte"])) {

    $fecha_inicio = validar_input($_POST['fecha_inicio'] ?? '');
    $fecha_fin    = validar_input($_POST['fecha_fin'] ?? '') . ' 23:59:59';
    $tipo_reporte = validar_input($_POST['tipo_reporte'] ?? '');
    $id_matriz    = validar_input($_POST['id_matriz'] ?? '');

    $titulo_reporte = "Gestión Calidad-Monitoreos " . date('Y-m-d H_i_s') . ".xlsx";

    if ($tipo_reporte === 'Monitoreos') {

        $data_consulta = [];
        $data_consulta[] = $id_matriz;
        $data_consulta[] = $fecha_inicio;
        $data_consulta[] = $fecha_fin;

        if ($perfil_modulo === 'Cliente') {
            $filtro_perfil = " AND TMC.`gcm_aplica_indicador`='No-Cliente'";
        } else {
            $filtro_perfil = "";
        }

        // 1) Query principal (1 sola vez)
        $consulta_string = "
            SELECT
                TMC.`gcm_id`,
                TM.`gcm_nombre_matriz`,
                TUA.`usu_nombres_apellidos`,
                TMC.`gcm_fecha_hora_gestion`,
                TMC.`gcm_fecha_hora_cierre`,
                TMC.`gcm_tipo_monitoreo`,
                TMC.`gcm_skill_interaccion`,
                TMC.`gcm_tipo_gestion`,
                TMC.`gcm_segmento`,
                TMC.`gcm_id_sim`,
                TMC.`gcm_id_ani`,
                TMC.`gcm_observaciones_monitoreo`,
                TMC.`gcm_nota_enc`,
                TMC.`gcm_nota_ecn`,
                TMC.`gcm_nota_ecuf`,
                TMC.`gcm_estado`,
                TUR.`usu_nombres_apellidos`,
                TMC.`gcm_registro_fecha`,
                TP.`ap_nombre_piloto`,
                TS.`usu_nombres_apellidos`,
                `gcm_nota_general`,
                `gcm_nota_enc_estado`,
                `gcm_nota_ecn_estado`,
                `gcm_nota_ecuf_estado`,
                TMC.`gcm_analista`,
                `gcm_aplica_indicador`,
                TMC.`gcm_fecha_monitoreo`,
                TM.`gcm_observaciones`,
                TUA.`usu_fecha_incorporacion`,
                TUA.`usu_usuario_red`,
                TSA.`usu_nombres_apellidos`,
                TMC.`gcm_encuesta`
            FROM `tb_gestion_calidad_monitoreo` AS TMC
            LEFT JOIN `tb_gestion_calidad_matriz` AS TM ON TMC.`gcm_matriz`=TM.`gcm_id`
            LEFT JOIN `tb_administrador_usuario` AS TUR ON TMC.`gcm_registro_usuario`=TUR.`usu_id`
            LEFT JOIN `tb_administrador_usuario` AS TUA ON TMC.`gcm_analista`=TUA.`usu_id`
            LEFT JOIN `tb_administrador_piloto` AS TP ON TUA.`usu_piloto`=TP.`ap_id`
            LEFT JOIN `tb_administrador_usuario` AS TS ON TMC.`gcm_responsable`=TS.`usu_id`
            LEFT JOIN `tb_administrador_usuario` AS TSA ON TUA.`usu_supervisor`=TSA.`usu_id`
            WHERE TMC.`gcm_matriz`=? AND TMC.`gcm_fecha_monitoreo`>=? AND TMC.`gcm_fecha_monitoreo`<=? {$filtro_perfil}
            ORDER BY TMC.`gcm_id`
        ";

        $consulta_registros = $enlace_db->prepare($consulta_string);
        $consulta_registros->bind_param(str_repeat("s", count($data_consulta)), ...$data_consulta);
        $consulta_registros->execute();
        $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);
        $consulta_registros->close();

        // Si no hay data, devolvemos mensaje claro (evita esperar y timeout)
        if (count($resultado_registros) === 0) {
            header("HTTP/1.1 204 No Content");
            echo "No hay monitoreos en el rango seleccionado.";
            exit;
        }

        // 2) Matriz items
        $consulta_string_matriz = "
            SELECT `gcmi_id`, `gcmi_matriz`, `gcmi_item_tipo`, `gcmi_item_consecutivo`, `gcmi_item_orden`,
                   `gcmi_descripcion`, `gcmi_peso`, `gcmi_calificable`, `gcmi_grupo_peso`, `gcmi_visible`
            FROM `tb_gestion_calidad_matriz_item`
            WHERE `gcmi_matriz`=?
            ORDER BY CAST(`gcmi_item_consecutivo` AS UNSIGNED), `gcmi_item_consecutivo` ASC, CONVERT(`gcmi_item_orden`, SIGNED INTEGER)
        ";
        $consulta_registros_matriz = $enlace_db->prepare($consulta_string_matriz);
        $consulta_registros_matriz->bind_param('s', $id_matriz);
        $consulta_registros_matriz->execute();
        $resultado_registros_matriz = $consulta_registros_matriz->get_result()->fetch_all(MYSQLI_NUM);
        $consulta_registros_matriz->close();

        // IMPORTANTE: evita notices
        $array_items_matriz = ['nombre'=>[], 'id'=>[], 'consecutivo'=>[], 'peso'=>[]];

        for ($i=0; $i < count($resultado_registros_matriz); $i++) {
            if (($resultado_registros_matriz[$i][7] ?? '') === "Si") {
                $array_items_matriz['nombre'][] = $resultado_registros_matriz[$i][5];
                $array_items_matriz['nombre'][] = "Comentario";
                $array_items_matriz['id'][] = $resultado_registros_matriz[$i][0];
                $array_items_matriz['consecutivo'][] = $resultado_registros_matriz[$i][3];
                $array_items_matriz['consecutivo'][] = "";
                $array_items_matriz['peso'][] = ($resultado_registros_matriz[$i][6] ?? '') . "%";
                $array_items_matriz['peso'][] = "";
            }
        }

        // 3) Nombre matriz
        $consulta_string_matriz_nombre = "
            SELECT `gcm_id`, `gcm_nombre_matriz`, `gcm_estado`, `gcm_observaciones`, `gcm_registro_usuario`, `gcm_registro_fecha`
            FROM `tb_gestion_calidad_matriz`
            WHERE `gcm_id`=?
        ";
        $consulta_registros_matriz_nombre = $enlace_db->prepare($consulta_string_matriz_nombre);
        $consulta_registros_matriz_nombre->bind_param("s", $id_matriz);
        $consulta_registros_matriz_nombre->execute();
        $resultado_registros_matriz_nombre = $consulta_registros_matriz_nombre->get_result()->fetch_all(MYSQLI_NUM);
        $consulta_registros_matriz_nombre->close();

        // ============================
        // OPTIMIZACIÓN CLAVE:
        // Pre-cargar HISTORIAL, RESPUESTAS y ADJUNTOS en queries globales
        // ============================
        $idsMonitoreo = [];
        foreach ($resultado_registros as $row) { $idsMonitoreo[] = (string)$row[0]; }

        // A) Historial por monitoreo y tipo
        // cols: monitoreo, tipo_cambio, comentarios, registro_fecha
        $histSql = "
            SELECT `gcmh_monitoreo`, `gcmh_tipo_cambio`, `gcmh_comentarios`, `gcmh_registro_fecha`
            FROM `tb_gestion_calidad_monitoreo_historial`
            WHERE `gcmh_monitoreo` IN ({IN})
        ";
        $histRows = fetch_in_batches($enlace_db, $histSql, $idsMonitoreo, 600);

        $histMap = []; // [monitoreo][tipo] => ['valor'=>..., 'fecha'=>...]
        foreach ($histRows as $r) {
            $mid  = (string)$r[0];
            $tipo = (string)$r[1];
            if (!isset($histMap[$mid])) $histMap[$mid] = [];
            $histMap[$mid][$tipo] = [
                'valor' => (string)($r[2] ?? ''),
                'fecha' => (string)($r[3] ?? ''),
            ];
        }

        // B) Respuestas por monitoreo y pregunta (solo calificable=Si)
        // cols: monitoreo, pregunta, respuesta, comentarios, calificable
        $respSql = "
            SELECT
                C.`gcmc_monitoreo`,
                C.`gcmc_pregunta`,
                C.`gcmc_respuesta`,
                C.`gcmc_comentarios`,
                TIM.`gcmi_calificable`
            FROM `tb_gestion_calidad_monitoreo_calificaciones` C
            LEFT JOIN `tb_gestion_calidad_matriz_item` TIM ON C.`gcmc_pregunta` = TIM.`gcmi_id`
            WHERE C.`gcmc_monitoreo` IN ({IN})
            ORDER BY TIM.`gcmi_item_consecutivo` ASC
        ";
        $respRows = fetch_in_batches($enlace_db, $respSql, $idsMonitoreo, 400);

        $respMap = []; // [monitoreo][pregunta] => ['respuesta'=>.., 'comentarios'=>..]
        foreach ($respRows as $r) {
            $mid = (string)$r[0];
            $qid = (string)$r[1];
            $calif = (string)($r[4] ?? '');
            if ($calif !== 'Si') continue;
            if (!isset($respMap[$mid])) $respMap[$mid] = [];
            $respMap[$mid][$qid] = [
                'respuesta'   => (string)($r[2] ?? ''),
                'comentarios' => (string)($r[3] ?? ''),
            ];
        }

        // C) Adjuntos directos del monitoreo (tb_gestion_calidad_monitoreo_soportes)
        // Construye un set con los IDs que tienen al menos 1 archivo subido directamente al monitoreo
        $adjMonitoreoSql = "
            SELECT DISTINCT `gcms_monitoreo`
            FROM `tb_gestion_calidad_monitoreo_soportes`
            WHERE `gcms_monitoreo` IN ({IN})
        ";
        $adjMonitoreoRows = fetch_in_batches($enlace_db, $adjMonitoreoSql, $idsMonitoreo, 600);

        $adjMonitoreoSet = []; // [gcm_id] => true
        foreach ($adjMonitoreoRows as $r) {
            $adjMonitoreoSet[(string)$r[0]] = true;
        }

        // D) Adjuntos de historial/retroalimentación (tb_gestion_calidad_monitoreo_historial_soportes)
        // Construye un set con los IDs que tienen al menos 1 archivo en el proceso de refutación
        $adjRetroSql = "
            SELECT DISTINCT `gcmhs_monitoreo`
            FROM `tb_gestion_calidad_monitoreo_historial_soportes`
            WHERE `gcmhs_monitoreo` IN ({IN})
        ";
        $adjRetroRows = fetch_in_batches($enlace_db, $adjRetroSql, $idsMonitoreo, 600);

        $adjRetroSet = []; // [gcm_id] => true
        foreach ($adjRetroRows as $r) {
            $adjRetroSet[(string)$r[0]] = true;
        }

        // ============================
        // Crear Excel
        // ============================
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator("IQ-ICBF Gestión Integrada de Servicios")
            ->setLastModifiedBy($_SESSION['usu_nombre_completo'] ?? 'Sistema')
            ->setTitle("IQ-ICBF Gestión Integrada de Servicios")
            ->setSubject("IQ-ICBF Gestión Integrada de Servicios")
            ->setDescription("IQ-ICBF Gestión Integrada de Servicios")
            ->setKeywords("IQ-ICBF Gestión Integrada de Servicios")
            ->setCategory("Reporte");

        $spreadsheet->getDefaultStyle()->getFont()->setName("Calibri");
        $spreadsheet->getDefaultStyle()->getFont()->setSize(10);

        $styleArrayTitulos = [
            'font' => [
                'bold' => true,
                'size' =>'8',
                'name' =>'Arial',
                'color' => ['rgb' => '2E2E2E']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrap'=>true,
            ],
            'borders' => [
                'top' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                ],
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'color' => ['argb' => '72BF44']
            ],
        ];

        $sheet = $spreadsheet->getActiveSheet(0);
        $spreadsheet->getActiveSheet()->setTitle('Reporte Gestión Calidad');

        $spreadsheet->getActiveSheet()->getRowDimension('3')->setRowHeight(80);
        foreach (range('A','Z') as $col) {
            $spreadsheet->getActiveSheet()->getColumnDimension($col)->setWidth(20);
        }
        foreach (['AA','AB','AC','AD','AE','AF','AG','AH'] as $col) {
            $spreadsheet->getActiveSheet()->getColumnDimension($col)->setWidth(20);
        }

        // Rango de encabezados dinámico
        // +33 = 31 columnas fijas originales (A..AF) + 2 nuevas (AG, AH) = índice 33
        $lastHeaderColIndex = count($array_items_matriz['nombre']) + 33;
        $lastHeaderCol = $array_columnas[$lastHeaderColIndex] ?? 'AH';

        $spreadsheet->getActiveSheet()->getStyle('A3:'.$lastHeaderCol.'3')->applyFromArray($styleArrayTitulos);
        $spreadsheet->getActiveSheet()->setAutoFilter('A3:'.$lastHeaderCol.'3');
        $spreadsheet->getActiveSheet()->getStyle('3')->getAlignment()->setWrapText(true);

        // Encabezados fijos originales (A..AF — sin cambios)
        $spreadsheet->getActiveSheet()->setCellValue('A3','Consecutivo');
        $spreadsheet->getActiveSheet()->setCellValue('B3','Doc. Analista');
        $spreadsheet->getActiveSheet()->setCellValue('C3','Analista');
        $spreadsheet->getActiveSheet()->setCellValue('D3','Responsable');
        $spreadsheet->getActiveSheet()->setCellValue('E3','Matriz');
        $spreadsheet->getActiveSheet()->setCellValue('F3','Tipo Monitoreo');
        $spreadsheet->getActiveSheet()->setCellValue('G3','Skill Interacción');
        $spreadsheet->getActiveSheet()->setCellValue('H3','Tipo Gestión');
        $spreadsheet->getActiveSheet()->setCellValue('I3','Segmento');
        $spreadsheet->getActiveSheet()->setCellValue('J3','Id SIM');
        $spreadsheet->getActiveSheet()->setCellValue('K3','Id/ANI');
        $spreadsheet->getActiveSheet()->setCellValue('L3','Fecha Gestión');
        $spreadsheet->getActiveSheet()->setCellValue('M3','Fecha Monitoreo');
        $spreadsheet->getActiveSheet()->setCellValue('N3','Nota ECUF');
        $spreadsheet->getActiveSheet()->setCellValue('O3','Nota ECN');
        $spreadsheet->getActiveSheet()->setCellValue('P3','Nota ENC');
        $spreadsheet->getActiveSheet()->setCellValue('Q3','Nota General');
        $spreadsheet->getActiveSheet()->setCellValue('R3','Indicador');
        $spreadsheet->getActiveSheet()->setCellValue('S3','Estado');
        $spreadsheet->getActiveSheet()->setCellValue('T3','Observaciones');
        $spreadsheet->getActiveSheet()->setCellValue('U3','Usuario Registro');
        $spreadsheet->getActiveSheet()->setCellValue('V3','Fecha-Hora Registro');
        $spreadsheet->getActiveSheet()->setCellValue('W3','Observaciones para refutar');
        $spreadsheet->getActiveSheet()->setCellValue('X3','Fecha-Hora');
        $spreadsheet->getActiveSheet()->setCellValue('Y3','Compromiso de mejora');
        $spreadsheet->getActiveSheet()->setCellValue('Z3','Fecha-Hora');
        $spreadsheet->getActiveSheet()->setCellValue('AA3','Revisión refutado');
        $spreadsheet->getActiveSheet()->setCellValue('AB3','Fecha-Hora');
        $spreadsheet->getActiveSheet()->setCellValue('AC3','Fecha Incorporación');
        $spreadsheet->getActiveSheet()->setCellValue('AD3','Usuario de Red');
        $spreadsheet->getActiveSheet()->setCellValue('AE3','Duración');
        $spreadsheet->getActiveSheet()->setCellValue('AF3','Encuesta');
        // ---- Nuevas columnas de adjuntos ----
        $spreadsheet->getActiveSheet()->setCellValue('AG3','Adj. Monitoreo');
        $spreadsheet->getActiveSheet()->setCellValue('AH3','Adj. Retroalimentación');

        $matNombre = $resultado_registros_matriz_nombre[0][1] ?? '';
        $matObs    = $resultado_registros_matriz_nombre[0][3] ?? '';
        $spreadsheet->getActiveSheet()->setCellValue('A1','Matriz: '.$matNombre.' ['.$matObs.']');
        $spreadsheet->getActiveSheet()->setCellValue('A2','Fecha filtro: '.$fecha_inicio.' A '.$fecha_fin);

        // Encabezados dinámicos (items matriz) — empiezan en índice 34 (columna AI en adelante)
        for ($i=34; $i < count($array_items_matriz['nombre'])+34; $i++) {
            $nombre_final = ($array_items_matriz['consecutivo'][$i-34] ?? '') . " " . ($array_items_matriz['nombre'][$i-34] ?? '');
            $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$i].'2', $array_items_matriz['peso'][$i-34] ?? '');
            $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$i].'3', $nombre_final);
            $spreadsheet->getActiveSheet()->getColumnDimension($array_columnas[$i])->setWidth(20);
        }

        // Filas de datos
        for ($i=4; $i < count($resultado_registros)+4; $i++) {

            $mid = (string)$resultado_registros[$i-4][0];

            // Historial por defecto
            $array_estado_historial = [
                'Refutar'           => ['valor'=>'','fecha'=>''],
                'Aceptar'           => ['valor'=>'','fecha'=>''],
                'Refutar-Rechazado' => ['valor'=>'','fecha'=>''],
                'Refutar-Aceptado'  => ['valor'=>'','fecha'=>''],
            ];

            if (isset($histMap[$mid])) {
                foreach ($histMap[$mid] as $tipo => $info) {
                    if (!isset($array_estado_historial[$tipo])) {
                        $array_estado_historial[$tipo] = ['valor'=>'','fecha'=>''];
                    }
                    $array_estado_historial[$tipo]['valor'] = $info['valor'] ?? '';
                    $array_estado_historial[$tipo]['fecha'] = $info['fecha'] ?? '';
                }
            }

            // Respuestas
            $array_respuestas = $respMap[$mid] ?? [];

            // Columnas fijas originales (sin cambios)
            $spreadsheet->getActiveSheet()->setCellValue('A'.$i, $resultado_registros[$i-4][0]);
            $spreadsheet->getActiveSheet()->setCellValue('B'.$i, $resultado_registros[$i-4][24]);
            $spreadsheet->getActiveSheet()->setCellValue('C'.$i, $resultado_registros[$i-4][2]);
            $spreadsheet->getActiveSheet()->setCellValue('D'.$i, $resultado_registros[$i-4][30]);
            $spreadsheet->getActiveSheet()->setCellValue('E'.$i, $resultado_registros[$i-4][1].' ['.$resultado_registros[$i-4][27].']');
            $spreadsheet->getActiveSheet()->setCellValue('F'.$i, $resultado_registros[$i-4][5]);
            $spreadsheet->getActiveSheet()->setCellValue('G'.$i, $resultado_registros[$i-4][6]);
            $spreadsheet->getActiveSheet()->setCellValue('H'.$i, $resultado_registros[$i-4][7]);
            $spreadsheet->getActiveSheet()->setCellValue('I'.$i, $resultado_registros[$i-4][8]);
            $spreadsheet->getActiveSheet()->setCellValue('J'.$i, $resultado_registros[$i-4][9]);
            $spreadsheet->getActiveSheet()->setCellValue('K'.$i, $resultado_registros[$i-4][10]);
            $spreadsheet->getActiveSheet()->setCellValue('L'.$i, date('d/m/Y', strtotime((string)$resultado_registros[$i-4][3])));
            $spreadsheet->getActiveSheet()->setCellValue('M'.$i, date('d/m/Y', strtotime((string)$resultado_registros[$i-4][26])));
            $spreadsheet->getActiveSheet()->setCellValue('N'.$i, $resultado_registros[$i-4][14]);
            $spreadsheet->getActiveSheet()->setCellValue('O'.$i, $resultado_registros[$i-4][13]);
            $spreadsheet->getActiveSheet()->setCellValue('P'.$i, $resultado_registros[$i-4][12]);
            $spreadsheet->getActiveSheet()->setCellValue('Q'.$i, $resultado_registros[$i-4][20]);
            $spreadsheet->getActiveSheet()->setCellValue('R'.$i, $resultado_registros[$i-4][25]);
            $spreadsheet->getActiveSheet()->setCellValue('S'.$i, $resultado_registros[$i-4][15]);
            $spreadsheet->getActiveSheet()->setCellValue('T'.$i, $resultado_registros[$i-4][11]);
            $spreadsheet->getActiveSheet()->setCellValue('U'.$i, $resultado_registros[$i-4][16]);
            $spreadsheet->getActiveSheet()->setCellValue('V'.$i, date('d/m/Y H:i:s', strtotime((string)$resultado_registros[$i-4][17])));
            $spreadsheet->getActiveSheet()->setCellValue('W'.$i, $array_estado_historial['Refutar']['valor']);
            $spreadsheet->getActiveSheet()->setCellValue('X'.$i, $array_estado_historial['Refutar']['fecha']);
            $spreadsheet->getActiveSheet()->setCellValue('Y'.$i, $array_estado_historial['Aceptar']['valor']);
            $spreadsheet->getActiveSheet()->setCellValue('Z'.$i, $array_estado_historial['Aceptar']['fecha']);
            $spreadsheet->getActiveSheet()->setCellValue('AA'.$i, ($array_estado_historial['Refutar-Rechazado']['valor'] ?? '').($array_estado_historial['Refutar-Aceptado']['valor'] ?? ''));
            $spreadsheet->getActiveSheet()->setCellValue('AB'.$i, ($array_estado_historial['Refutar-Rechazado']['fecha'] ?? '').($array_estado_historial['Refutar-Aceptado']['fecha'] ?? ''));
            $spreadsheet->getActiveSheet()->setCellValue('AC'.$i, $resultado_registros[$i-4][28]);
            $spreadsheet->getActiveSheet()->setCellValue('AD'.$i, $resultado_registros[$i-4][29]);
            $spreadsheet->getActiveSheet()->setCellValue('AE'.$i, $resultado_registros[$i-4][4]);
            $spreadsheet->getActiveSheet()->setCellValue('AF'.$i, $resultado_registros[$i-4][31]);
            // ---- Nuevas columnas de adjuntos ----
            // AG: Si el monitoreo tiene archivos en tb_gestion_calidad_monitoreo_soportes
            $spreadsheet->getActiveSheet()->setCellValue('AG'.$i, isset($adjMonitoreoSet[$mid]) ? 'Si' : 'No');
            // AH: Si el monitoreo tiene archivos en tb_gestion_calidad_monitoreo_historial_soportes
            $spreadsheet->getActiveSheet()->setCellValue('AH'.$i, isset($adjRetroSet[$mid])     ? 'Si' : 'No');

            // Dinámicas (pregunta + comentario) — ahora arrancan en índice 34 (columna AI)
            $columna_respuesta = 34;
            $columna_comentario = 35;

            for ($j=0; $j < count($array_items_matriz['id']); $j++) {
                $qid = (string)$array_items_matriz['id'][$j];
                $resp = $array_respuestas[$qid]['respuesta'] ?? '';
                $com  = $array_respuestas[$qid]['comentarios'] ?? '';

                $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$columna_respuesta].$i, $resp);
                $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$columna_comentario].$i, $com);

                $columna_respuesta += 2;
                $columna_comentario += 2;
            }
        }

        // Output (evita XLSX corrupto por salida previa)
        limpiar_buffers();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="'.$titulo_reporte.'"');
        header('Cache-Control: max-age=0');
        header('Pragma: no-cache');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        // mejora tiempo en algunos casos
        if (method_exists($writer, 'setPreCalculateFormulas')) {
            $writer->setPreCalculateFormulas(false);
        }
        $writer->save('php://output');
        exit;

    } elseif ($tipo_reporte === 'Backup' || $tipo_reporte === 'Eliminar backup') {

        $dir_backups = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir_backups)) { @mkdir($dir_backups, 0775, true); }

        $zip_tmp_name = 'backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
        $ruta_archivo_zip = $dir_backups . DIRECTORY_SEPARATOR . $zip_tmp_name;

        $nombre_archivo_descarga = 'backup_retroalimentacion_'.$fecha_inicio.' a '.$fecha_fin.'.zip';

        $data_consulta_soportes = [$fecha_inicio, $fecha_fin];

        $consulta_string_soportes = "
            SELECT `gcmhs_id`, `gcmhs_monitoreo`, `gcmhs_historial`, `gcmhs_nombre`, `gcmhs_ruta`, `gcmhs_extension`, `gcmhs_registro_usuario`, `gcmhs_registro_fecha`
            FROM `tb_gestion_calidad_monitoreo_historial_soportes`
            WHERE `gcmhs_registro_fecha`>=? AND `gcmhs_registro_fecha`<=?
            ORDER BY `gcmhs_id` ASC
        ";

        $consulta_registros_soportes = $enlace_db->prepare($consulta_string_soportes);
        $consulta_registros_soportes->bind_param(str_repeat("s", count($data_consulta_soportes)), ...$data_consulta_soportes);
        $consulta_registros_soportes->execute();
        $resultado_registros_soportes = $consulta_registros_soportes->get_result()->fetch_all(MYSQLI_NUM);
        $consulta_registros_soportes->close();

        if (count($resultado_registros_soportes) <= 0) {
            header("HTTP/1.1 204 No Content");
            echo "No hay soportes en el rango seleccionado.";
            exit;
        }

        zip_log("dir_backups=".$dir_backups." is_dir=".(is_dir($dir_backups)?"1":"0")." is_writable=".(is_writable($dir_backups)?"1":"0"));
        zip_log("zip_path=".$ruta_archivo_zip);

        $zip = new ZipArchive();
        $openResult = $zip->open($ruta_archivo_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== TRUE) {
            zip_log("ZipArchive::open ERROR code=".(string)$openResult);
            header("HTTP/1.1 500 Internal Server Error");
            echo "No se pudo crear el archivo ZIP.";
            exit;
        }

        $added = 0;
        $missing = [];
        $archivos_abs_para_eliminar = [];

        for ($i=0; $i < count($resultado_registros_soportes); $i++) {

            $monitoreo = (string)$resultado_registros_soportes[$i][1];
            $ruta_bd   = (string)$resultado_registros_soportes[$i][4];

            $ruta_real = resolver_ruta_soporte($ruta_bd);

            if ($ruta_real !== '' && is_file($ruta_real)) {

                $base = basename($ruta_real);
                $mon_safe = preg_replace('~[^A-Za-z0-9_\-]~', '_', $monitoreo);
                $zipName = $mon_safe . '_' . $base;

                if ($zip->addFile($ruta_real, $zipName)) {
                    $added++;
                    if ($tipo_reporte === 'Eliminar backup') {
                        $archivos_abs_para_eliminar[] = $ruta_real;
                    }
                } else {
                    $missing[] = "NO SE PUDO AGREGAR: {$ruta_real} (BD: {$ruta_bd})";
                }

            } else {
                $missing[] = "NO EXISTE: {$ruta_real} (BD: {$ruta_bd})";
            }
        }

        if ($added === 0) {
            $txt  = "Backup retroalimentación - SIN ARCHIVOS AGREGADOS\n";
            $txt .= "Rango: {$fecha_inicio} a {$fecha_fin}\n";
            $txt .= "Total registros BD: ".count($resultado_registros_soportes)."\n\n";
            $txt .= "Rutas no agregadas (primeras 400):\n\n";
            $txt .= implode("\n", array_slice($missing, 0, 400));
            $zip->addFromString("README_MISSING_FILES.txt", $txt);
        }

        $zip->close();
        clearstatcache(true, $ruta_archivo_zip);

        zip_log("zip_created exists=".(is_file($ruta_archivo_zip)?"1":"0")." size=".(is_file($ruta_archivo_zip)?(string)filesize($ruta_archivo_zip):"NA")." added=".$added);

        $cleanup = function() use ($ruta_archivo_zip, $tipo_reporte, $archivos_abs_para_eliminar) {

            if (is_file($ruta_archivo_zip)) { @unlink($ruta_archivo_zip); }

            if ($tipo_reporte === 'Eliminar backup') {
                foreach ($archivos_abs_para_eliminar as $abs) {
                    if ($abs !== '' && is_file($abs)) { @unlink($abs); }
                }
            }
        };

        enviar_zip($ruta_archivo_zip, $nombre_archivo_descarga, $cleanup);
        exit;
    }
}
?>