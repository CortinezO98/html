<?php
//Validación de permisos del usuario para el módulo
$modulo_plataforma="Calidad-Monitoreos";
require_once("../config/validaciones_seguridad.php");
require_once("../config/conexion_db.php");
require_once('../PHPOffice/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

$array_meses=[1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril", 5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto", 9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"];

//array columnas
$array_columnas[]="A";  $array_columnas[]="B";  $array_columnas[]="C";  $array_columnas[]="D";  $array_columnas[]="E";
$array_columnas[]="F";  $array_columnas[]="G";  $array_columnas[]="H";  $array_columnas[]="I";  $array_columnas[]="J";
$array_columnas[]="K";  $array_columnas[]="L";  $array_columnas[]="M";  $array_columnas[]="N";  $array_columnas[]="O";
$array_columnas[]="P";  $array_columnas[]="Q";  $array_columnas[]="R";  $array_columnas[]="S";  $array_columnas[]="T";
$array_columnas[]="U";  $array_columnas[]="V";  $array_columnas[]="W";  $array_columnas[]="X";  $array_columnas[]="Y";
$array_columnas[]="Z";  $array_columnas[]="AA"; $array_columnas[]="AB"; $array_columnas[]="AC"; $array_columnas[]="AD";
$array_columnas[]="AE"; $array_columnas[]="AF"; $array_columnas[]="AG"; $array_columnas[]="AH"; $array_columnas[]="AI";
$array_columnas[]="AJ"; $array_columnas[]="AK"; $array_columnas[]="AL"; $array_columnas[]="AM"; $array_columnas[]="AN";
$array_columnas[]="AO"; $array_columnas[]="AP"; $array_columnas[]="AQ"; $array_columnas[]="AR"; $array_columnas[]="AS";
$array_columnas[]="AT"; $array_columnas[]="AU"; $array_columnas[]="AV"; $array_columnas[]="AW"; $array_columnas[]="AX";
$array_columnas[]="AY"; $array_columnas[]="AZ"; $array_columnas[]="BA"; $array_columnas[]="BB"; $array_columnas[]="BC";
$array_columnas[]="BD"; $array_columnas[]="BE"; $array_columnas[]="BF"; $array_columnas[]="BG"; $array_columnas[]="BH";
$array_columnas[]="BI"; $array_columnas[]="BJ"; $array_columnas[]="BK"; $array_columnas[]="BL"; $array_columnas[]="BM";
$array_columnas[]="BN"; $array_columnas[]="BO"; $array_columnas[]="BP"; $array_columnas[]="BQ"; $array_columnas[]="BR";
$array_columnas[]="BS"; $array_columnas[]="BT"; $array_columnas[]="BU"; $array_columnas[]="BV"; $array_columnas[]="BW";
$array_columnas[]="BX"; $array_columnas[]="BY"; $array_columnas[]="BZ"; $array_columnas[]="CA"; $array_columnas[]="CB";
$array_columnas[]="CC"; $array_columnas[]="CD"; $array_columnas[]="CE"; $array_columnas[]="CF"; $array_columnas[]="CG";
$array_columnas[]="CH"; $array_columnas[]="CI"; $array_columnas[]="CJ"; $array_columnas[]="CK"; $array_columnas[]="CL";
$array_columnas[]="CM"; $array_columnas[]="CN"; $array_columnas[]="CO"; $array_columnas[]="CP"; $array_columnas[]="CQ";
$array_columnas[]="CR"; $array_columnas[]="CS"; $array_columnas[]="CT"; $array_columnas[]="CU"; $array_columnas[]="CV";
$array_columnas[]="CW"; $array_columnas[]="CX"; $array_columnas[]="CY"; $array_columnas[]="CZ";
//array columnas



if (!function_exists('zip_log')) {
    function zip_log(string $msg): void {
        error_log('[ZIP] ' . $msg);
    }
}

if (!function_exists('resolver_ruta_soporte')) {
    function resolver_ruta_soporte(string $ruta_fichero): string
    {
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
    function enviar_zip(string $zipPath, string $downloadName, callable $cleanup = null): void
    {
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

        // Evita ZIP corrupto por salida previa
        limpiar_buffers();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        readfile($zipPath);

        if ($cleanup) {
            $cleanup();
        }
    }
}


if(isset($_POST["exportar_reporte"])){

    $fecha_inicio=validar_input($_POST['fecha_inicio']);
    $fecha_fin=validar_input($_POST['fecha_fin']).' 23:59:59';
    $tipo_reporte=validar_input($_POST['tipo_reporte']);
    $id_matriz=validar_input($_POST['id_matriz']);
    $titulo_reporte="Gestión Calidad-Monitoreos ".date('Y-m-d H_i_s').".xlsx";

    if ($tipo_reporte=='Monitoreos') {

        $data_consulta=array();
        array_push($data_consulta, $id_matriz);
        array_push($data_consulta, $fecha_inicio);
        array_push($data_consulta, $fecha_fin);

        if ($perfil_modulo=='Cliente') {
            $filtro_perfil=" AND TMC.`gcm_aplica_indicador`='No-Cliente'";
        } else {
            $filtro_perfil="";
        }

        $consulta_string="SELECT TMC.`gcm_id`, TM.`gcm_nombre_matriz`, TUA.`usu_nombres_apellidos`, TMC.`gcm_fecha_hora_gestion`, TMC.`gcm_fecha_hora_cierre`, TMC.`gcm_tipo_monitoreo`, TMC.`gcm_skill_interaccion`, TMC.`gcm_tipo_gestion`, TMC.`gcm_segmento`, TMC.`gcm_id_sim`, TMC.`gcm_id_ani`, TMC.`gcm_observaciones_monitoreo`, TMC.`gcm_nota_enc`, TMC.`gcm_nota_ecn`, TMC.`gcm_nota_ecuf`, TMC.`gcm_estado`, TUR.`usu_nombres_apellidos`, TMC.`gcm_registro_fecha`, TP.`ap_nombre_piloto`, TS.`usu_nombres_apellidos`, `gcm_nota_general`, `gcm_nota_enc_estado`, `gcm_nota_ecn_estado`, `gcm_nota_ecuf_estado`, TMC.`gcm_analista`, `gcm_aplica_indicador`, TMC.`gcm_fecha_monitoreo`, TM.`gcm_observaciones`, TUA.`usu_fecha_incorporacion`, TUA.`usu_usuario_red`, TSA.`usu_nombres_apellidos`, TMC.`gcm_encuesta`
        FROM `tb_gestion_calidad_monitoreo` AS TMC
        LEFT JOIN `tb_gestion_calidad_matriz` AS TM ON TMC.`gcm_matriz`=TM.`gcm_id`
        LEFT JOIN `tb_administrador_usuario` AS TUR ON TMC.`gcm_registro_usuario`=TUR.`usu_id`
        LEFT JOIN `tb_administrador_usuario` AS TUA ON TMC.`gcm_analista`=TUA.`usu_id`
        LEFT JOIN `tb_administrador_piloto` AS TP ON TUA.`usu_piloto`=TP.`ap_id`
        LEFT JOIN `tb_administrador_usuario` AS TS ON TMC.`gcm_responsable`=TS.`usu_id`
        LEFT JOIN `tb_administrador_usuario` AS TSA ON TUA.`usu_supervisor`=TSA.`usu_id`
        WHERE TMC.`gcm_matriz`=? AND TMC.`gcm_fecha_monitoreo`>=? AND TMC.`gcm_fecha_monitoreo`<=? ".$filtro_perfil."
        ORDER BY TMC.`gcm_id`";

        $consulta_registros = $enlace_db->prepare($consulta_string);
        if (count($data_consulta)>0) {
            $consulta_registros->bind_param(str_repeat("s", count($data_consulta)), ...$data_consulta);
        }
        $consulta_registros->execute();
        $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

        $consulta_string_matriz="SELECT `gcmi_id`, `gcmi_matriz`, `gcmi_item_tipo`, `gcmi_item_consecutivo`, `gcmi_item_orden`, `gcmi_descripcion`, `gcmi_peso`, `gcmi_calificable`, `gcmi_grupo_peso`, `gcmi_visible`
        FROM `tb_gestion_calidad_matriz_item`
        WHERE `gcmi_matriz`=?
        ORDER BY CAST(`gcmi_item_consecutivo` AS UNSIGNED), `gcmi_item_consecutivo` ASC, CONVERT(`gcmi_item_orden`, SIGNED INTEGER)";

        $consulta_registros_matriz = $enlace_db->prepare($consulta_string_matriz);
        $consulta_registros_matriz->bind_param('s', $id_matriz);
        $consulta_registros_matriz->execute();
        $resultado_registros_matriz = $consulta_registros_matriz->get_result()->fetch_all(MYSQLI_NUM);

        // IMPORTANTE: evita notices
        $array_items_matriz = ['nombre'=>[], 'id'=>[], 'consecutivo'=>[], 'peso'=>[]];

        for ($i=0; $i < count($resultado_registros_matriz); $i++) {
            if ($resultado_registros_matriz[$i][7]=="Si") {
                $array_items_matriz['nombre'][]=$resultado_registros_matriz[$i][5];
                $array_items_matriz['nombre'][]="Comentario";
                $array_items_matriz['id'][]=$resultado_registros_matriz[$i][0];
                $array_items_matriz['consecutivo'][]=$resultado_registros_matriz[$i][3];
                $array_items_matriz['consecutivo'][]="";
                $array_items_matriz['peso'][]=$resultado_registros_matriz[$i][6]."%";
                $array_items_matriz['peso'][]="";
            }
        }

        $consulta_string_matriz_nombre="SELECT `gcm_id`, `gcm_nombre_matriz`, `gcm_estado`, `gcm_observaciones`, `gcm_registro_usuario`, `gcm_registro_fecha`
        FROM `tb_gestion_calidad_matriz` WHERE `gcm_id`=?";

        $consulta_registros_matriz_nombre = $enlace_db->prepare($consulta_string_matriz_nombre);
        $consulta_registros_matriz_nombre->bind_param("s", $id_matriz);
        $consulta_registros_matriz_nombre->execute();
        $resultado_registros_matriz_nombre = $consulta_registros_matriz_nombre->get_result()->fetch_all(MYSQLI_NUM);

        $spreadsheet = new Spreadsheet();

        $spreadsheet->getProperties()
        ->setCreator("IQ-ICBF Gestión Integrada de Servicios")
        ->setLastModifiedBy($_SESSION['usu_nombre_completo'])
        ->setTitle("IQ-ICBF Gestión Integrada de Servicios")
        ->setSubject("IQ-ICBF Gestión Integrada de Servicios")
        ->setDescription("IQ-ICBF Gestión Integrada de Servicios")
        ->setKeywords("IQ-ICBF Gestión Integrada de Servicios")
        ->setCategory("Reporte");

        $spreadsheet->getDefaultStyle()->getfont()->setName("Calibri");
        $spreadsheet->getDefaultStyle()->getFont()->setSize(10);

        $styleArrayTitulos = [
            'font' => [
                'bold' => true,
                'size' =>'8',
                'name' =>'Arial',
                'color' => array('rgb' => '2E2E2E')
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
                'color' => [
                    'argb' => '72BF44',
                ]
            ],
        ];

        $sheet = $spreadsheet->getActiveSheet(0);
        $spreadsheet->getActiveSheet()->setTitle('Reporte Gestión Calidad');

        $spreadsheet->getActiveSheet()->getRowDimension('3')->setRowHeight(80);
        foreach (range('A','Z') as $col) {
            $spreadsheet->getActiveSheet()->getColumnDimension($col)->setWidth(20);
        }
        foreach (['AA','AB','AC','AD','AE','AF'] as $col) {
            $spreadsheet->getActiveSheet()->getColumnDimension($col)->setWidth(20);
        }

        $spreadsheet->getActiveSheet()->getStyle('A3:'.$array_columnas[count($array_items_matriz['nombre'])+31].'3')->applyFromArray($styleArrayTitulos);
        $spreadsheet->getActiveSheet()->setAutoFilter('A3:'.$array_columnas[count($array_items_matriz['nombre'])+31].'3');
        $spreadsheet->getActiveSheet()->getStyle('3')->getAlignment()->setWrapText(true);

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

        $spreadsheet->getActiveSheet()->setCellValue('A1','Matriz: '.$resultado_registros_matriz_nombre[0][1].' ['.$resultado_registros_matriz_nombre[0][3].']');
        $spreadsheet->getActiveSheet()->setCellValue('A2','Fecha filtro: '.$fecha_inicio.' A '.$fecha_fin);

        for ($i=32; $i < count($array_items_matriz['nombre'])+32; $i++) {
            $nombre_final=$array_items_matriz['consecutivo'][$i-32]." ".$array_items_matriz['nombre'][$i-32];
            $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$i].'2',$array_items_matriz['peso'][$i-32]);
            $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$i].'3',$nombre_final);
            $spreadsheet->getActiveSheet()->getColumnDimension($array_columnas[$i])->setWidth(20);
        }

        for ($i=4; $i < count($resultado_registros)+4; $i++) {

            $consulta_string_historial="SELECT `gcmh_id`, `gcmh_monitoreo`, `gcmh_tipo_cambio`, `gcmh_comentarios`, `gcmh_registro_usuario`, `gcmh_registro_fecha`
            FROM `tb_gestion_calidad_monitoreo_historial` WHERE `gcmh_monitoreo`=?";

            $consulta_registros_historial = $enlace_db->prepare($consulta_string_historial);
            $consulta_registros_historial->bind_param('s', $resultado_registros[$i-4][0]);
            $consulta_registros_historial->execute();
            $resultado_registros_historial = $consulta_registros_historial->get_result()->fetch_all(MYSQLI_NUM);

            $array_estado_historial = [
                'Refutar' => ['valor'=>'','fecha'=>''],
                'Aceptar' => ['valor'=>'','fecha'=>''],
                'Refutar-Rechazado' => ['valor'=>'','fecha'=>''],
                'Refutar-Aceptado' => ['valor'=>'','fecha'=>''],
            ];

            for ($j=0; $j < count($resultado_registros_historial); $j++) {
                $tipo = $resultado_registros_historial[$j][2];
                if (!isset($array_estado_historial[$tipo])) {
                    $array_estado_historial[$tipo] = ['valor'=>'','fecha'=>''];
                }
                $array_estado_historial[$tipo]['valor']=$resultado_registros_historial[$j][3];
                $array_estado_historial[$tipo]['fecha']=$resultado_registros_historial[$j][5];
            }

            $consulta_string_respuesta="SELECT `gcmc_id`, `gcmc_monitoreo`, `gcmc_pregunta`, `gcmc_respuesta`, `gcmc_afectaciones`, `gcmc_comentarios`,
                TIM.`gcmi_matriz`, TIM.`gcmi_item_tipo`, TIM.`gcmi_item_consecutivo`, TIM.`gcmi_item_orden`, TIM.`gcmi_descripcion`, TIM.`gcmi_peso`, TIM.`gcmi_calificable`
            FROM `tb_gestion_calidad_monitoreo_calificaciones`
            LEFT JOIN `tb_gestion_calidad_matriz_item` AS TIM ON `tb_gestion_calidad_monitoreo_calificaciones`.`gcmc_pregunta`=TIM.`gcmi_id`
            WHERE `gcmc_monitoreo`=?  ORDER BY TIM.`gcmi_item_consecutivo` ASC";

            $consulta_registros_respuesta = $enlace_db->prepare($consulta_string_respuesta);
            $consulta_registros_respuesta->bind_param('s', $resultado_registros[$i-4][0]);
            $consulta_registros_respuesta->execute();
            $resultado_registros_respuesta = $consulta_registros_respuesta->get_result()->fetch_all(MYSQLI_NUM);

            $array_respuestas = [];

            for ($j=0; $j < count($resultado_registros_respuesta); $j++) {
                if ($resultado_registros_respuesta[$j][12]=="Si") {
                    $qid = $resultado_registros_respuesta[$j][2];
                    $array_respuestas[$qid]['respuesta']=$resultado_registros_respuesta[$j][3];
                    $array_respuestas[$qid]['comentarios']=$resultado_registros_respuesta[$j][5];
                }
            }

            $spreadsheet->getActiveSheet()->setCellValue('A'.$i,$resultado_registros[$i-4][0]);
            $spreadsheet->getActiveSheet()->setCellValue('B'.$i,$resultado_registros[$i-4][24]);
            $spreadsheet->getActiveSheet()->setCellValue('C'.$i,$resultado_registros[$i-4][2]);
            $spreadsheet->getActiveSheet()->setCellValue('D'.$i,$resultado_registros[$i-4][30]);
            $spreadsheet->getActiveSheet()->setCellValue('E'.$i,$resultado_registros[$i-4][1].' ['.$resultado_registros[$i-4][27].']');
            $spreadsheet->getActiveSheet()->setCellValue('F'.$i,$resultado_registros[$i-4][5]);
            $spreadsheet->getActiveSheet()->setCellValue('G'.$i,$resultado_registros[$i-4][6]);
            $spreadsheet->getActiveSheet()->setCellValue('H'.$i,$resultado_registros[$i-4][7]);
            $spreadsheet->getActiveSheet()->setCellValue('I'.$i,$resultado_registros[$i-4][8]);
            $spreadsheet->getActiveSheet()->setCellValue('J'.$i,$resultado_registros[$i-4][9]);
            $spreadsheet->getActiveSheet()->setCellValue('K'.$i,$resultado_registros[$i-4][10]);
            $spreadsheet->getActiveSheet()->setCellValue('L'.$i,date('d/m/Y', strtotime($resultado_registros[$i-4][3])));
            $spreadsheet->getActiveSheet()->setCellValue('M'.$i,date('d/m/Y', strtotime($resultado_registros[$i-4][26])));
            $spreadsheet->getActiveSheet()->setCellValue('N'.$i,$resultado_registros[$i-4][14]);
            $spreadsheet->getActiveSheet()->setCellValue('O'.$i,$resultado_registros[$i-4][13]);
            $spreadsheet->getActiveSheet()->setCellValue('P'.$i,$resultado_registros[$i-4][12]);
            $spreadsheet->getActiveSheet()->setCellValue('Q'.$i,$resultado_registros[$i-4][20]);
            $spreadsheet->getActiveSheet()->setCellValue('R'.$i,$resultado_registros[$i-4][25]);
            $spreadsheet->getActiveSheet()->setCellValue('S'.$i,$resultado_registros[$i-4][15]);
            $spreadsheet->getActiveSheet()->setCellValue('T'.$i,$resultado_registros[$i-4][11]);
            $spreadsheet->getActiveSheet()->setCellValue('U'.$i,$resultado_registros[$i-4][16]);
            $spreadsheet->getActiveSheet()->setCellValue('V'.$i,date('d/m/Y H:i:s', strtotime($resultado_registros[$i-4][17])));
            $spreadsheet->getActiveSheet()->setCellValue('W'.$i,$array_estado_historial['Refutar']['valor']);
            $spreadsheet->getActiveSheet()->setCellValue('X'.$i,$array_estado_historial['Refutar']['fecha']);
            $spreadsheet->getActiveSheet()->setCellValue('Y'.$i,$array_estado_historial['Aceptar']['valor']);
            $spreadsheet->getActiveSheet()->setCellValue('Z'.$i,$array_estado_historial['Aceptar']['fecha']);
            $spreadsheet->getActiveSheet()->setCellValue('AA'.$i,$array_estado_historial['Refutar-Rechazado']['valor'].$array_estado_historial['Refutar-Aceptado']['valor']);
            $spreadsheet->getActiveSheet()->setCellValue('AB'.$i,$array_estado_historial['Refutar-Rechazado']['fecha'].$array_estado_historial['Refutar-Aceptado']['fecha']);
            $spreadsheet->getActiveSheet()->setCellValue('AC'.$i,$resultado_registros[$i-4][28]);
            $spreadsheet->getActiveSheet()->setCellValue('AD'.$i,$resultado_registros[$i-4][29]);
            $spreadsheet->getActiveSheet()->setCellValue('AE'.$i,$resultado_registros[$i-4][4]);
            $spreadsheet->getActiveSheet()->setCellValue('AF'.$i,$resultado_registros[$i-4][31]);

            $columna_respuesta=32;
            $columna_comentario=33;
            for ($j=0; $j < count($array_items_matriz['id']); $j++) {
                $qid = $array_items_matriz['id'][$j];
                $resp = $array_respuestas[$qid]['respuesta'] ?? '';
                $com  = $array_respuestas[$qid]['comentarios'] ?? '';
                $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$columna_respuesta].$i, $resp);
                $spreadsheet->getActiveSheet()->setCellValue($array_columnas[$columna_comentario].$i, $com);
                $columna_respuesta+=2;
                $columna_comentario+=2;
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="'.$titulo_reporte.'"');
        header('Cache-Control: max-age=0');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;

    } elseif ($tipo_reporte=='Backup' || $tipo_reporte=='Eliminar backup') {


        $dir_backups = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir_backups)) {
            @mkdir($dir_backups, 0775, true);
        }

        $zip_tmp_name = 'backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
        $ruta_archivo_zip = $dir_backups . DIRECTORY_SEPARATOR . $zip_tmp_name;

        $nombre_archivo_descarga = 'backup_retroalimentacion_'.$fecha_inicio.' a '.$fecha_fin.'.zip';

        $data_consulta_soportes=array();
        array_push($data_consulta_soportes, $fecha_inicio);
        array_push($data_consulta_soportes, $fecha_fin);

        $consulta_string_soportes="SELECT `gcmhs_id`, `gcmhs_monitoreo`, `gcmhs_historial`, `gcmhs_nombre`, `gcmhs_ruta`, `gcmhs_extension`, `gcmhs_registro_usuario`, `gcmhs_registro_fecha`
            FROM `tb_gestion_calidad_monitoreo_historial_soportes`
            WHERE `gcmhs_registro_fecha`>=? AND `gcmhs_registro_fecha`<=?
            ORDER BY `gcmhs_id` ASC";

        $consulta_registros_soportes = $enlace_db->prepare($consulta_string_soportes);
        $consulta_registros_soportes->bind_param(str_repeat("s", count($data_consulta_soportes)), ...$data_consulta_soportes);
        $consulta_registros_soportes->execute();
        $resultado_registros_soportes = $consulta_registros_soportes->get_result()->fetch_all(MYSQLI_NUM);

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
                        $archivos_abs_para_eliminar[] = $ruta_real; // ABS real
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

            if (is_file($ruta_archivo_zip)) {
                @unlink($ruta_archivo_zip);
            }

            if ($tipo_reporte === 'Eliminar backup') {
                foreach ($archivos_abs_para_eliminar as $abs) {
                    if ($abs !== '' && is_file($abs)) {
                        @unlink($abs);
                    }
                }
            }
        };

        enviar_zip($ruta_archivo_zip, $nombre_archivo_descarga, $cleanup);
        exit;
    }
}
?>
