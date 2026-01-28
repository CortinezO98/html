<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Gestión Turnos";

    require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");
    require_once("../config/validar_festivos.php");
    require_once('../PHPOffice/vendor/autoload.php');

    use PhpOffice\PhpSpreadsheet\IOFactory;

    /* =========================
       Robustez (NO afecta UI)
    ========================== */
    // Permite try/catch con mysqli y evita que se corte por tiempo corto de PHP
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    @set_time_limit(0);
    @ignore_user_abort(true);

    /* =========================
       Helpers (NO afectan diseño)
    ========================== */
    function obtenerDiasSemanaISO($semanaISO) {
        // Espera: YYYY-W## (ej: 2024-W32)
        if (!is_string($semanaISO)) return [];
        $semanaISO = trim($semanaISO);

        if (!preg_match('/^(\d{4})-W(\d{2})$/', $semanaISO, $m)) {
            return [];
        }

        $anio  = (int)$m[1];
        $sem   = (int)$m[2];

        $dt = new DateTime();
        // 1 = lunes
        $dt->setISODate($anio, $sem, 1);

        $dias = [];
        for ($i = 0; $i < 7; $i++) {
            $dias[] = $dt->format('Y-m-d');
            $dt->modify('+1 day');
        }
        return $dias;
    }

    function normalizarTextoCelda($v) {
        $s = trim((string)$v);
        $s = str_replace(["–", "—", "−"], "-", $s);
        $s = preg_replace('/\s*-\s*/', ' - ', $s);
        return trim($s);
    }

    function esHorarioValido($texto) {
        return (bool)preg_match('/^\d{1,2}:\d{2}\s-\s\d{1,2}:\d{2}$/', $texto);
    }

    /*DEFINICIÓN DE VARIABLES*/
    $titulo_header = "Gestión Turnos | Malla de Turnos - Cargar Malla";

    $actualizar_duplicados = (isset($_GET['actdup'])) ? base64_decode($_GET['actdup']) : '';

    $array_columna = ["B","C","D","E","F","G","H"];

    $control_insert=0;
    $control_fail=0;
    $string_fail="";
    $control_duplicado=0;
    $string_duplicado="";
    $nombre_temporal_control="";     // ruta REL (para el link)
    $nombre_temporal_control_fs="";  // ruta ABS (para escribir)

    if (!isset($_SESSION['registro_cargue_base'])) {
        $_SESSION['registro_cargue_base'] = 0;
    }

    if(isset($_POST["cargar_base"])){

        $semana = validar_input($_POST['semana']);

        $dias_semana = obtenerDiasSemanaISO($semana);
        if (count($dias_semana) !== 7) {
            $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Semana inválida. Selecciona una semana válida (YYYY-W##).</p>";
        } else {

            if ($_SESSION['registro_cargue_base']!=1) {

                if (!isset($_FILES['documento']) || $_FILES['documento']["error"] > 0) {
                    $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Problemas al cargar el documento, por favor intente más tarde!</p>";
                } else {

                    $extension = strtolower(pathinfo($_FILES['documento']['name'], PATHINFO_EXTENSION));
                    if ($extension !== 'xlsx') {
                        $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Formato inválido. Solo se permite .xlsx</p>";
                    } else {

                        /* ==========================================================
                           Guardado temporal (RUTA ABSOLUTA)
                        ========================================================== */
                        $nombre_directorio_rel = "storage_temporal/";
                        $nombre_directorio_fs  = __DIR__ . DIRECTORY_SEPARATOR . "storage_temporal" . DIRECTORY_SEPARATOR;

                        if (!is_dir($nombre_directorio_fs)) {
                            if (!mkdir($nombre_directorio_fs, 0755, true)) {
                                $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>No se pudo crear el directorio temporal.</p>";
                            }
                        }

                        if (empty($respuesta_accion) && !is_writable($nombre_directorio_fs)) {
                            $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>El directorio temporal no tiene permisos de escritura.</p>";
                        }

                        $nombre_archivo_seguro = "MALLA_".date('YmdHis')."_".bin2hex(random_bytes(4)).".xlsx";
                        $ruta_temporal_fs  = $nombre_directorio_fs  . $nombre_archivo_seguro;
                        $ruta_temporal_rel = $nombre_directorio_rel . $nombre_archivo_seguro;

                        $upload_ok = empty($respuesta_accion);

                        if ($upload_ok && $_FILES['documento']['error'] !== UPLOAD_ERR_OK) {
                            $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Error de carga PHP: ".$_FILES['documento']['error']."</p>";
                            $upload_ok = false;
                        } elseif ($upload_ok && !is_uploaded_file($_FILES['documento']['tmp_name'])) {
                            $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>El archivo temporal no es válido (no es uploaded file).</p>";
                            $upload_ok = false;
                        }

                        if ($upload_ok && !move_uploaded_file($_FILES['documento']['tmp_name'], $ruta_temporal_fs)) {
                            $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>¡Problemas al mover el archivo! Ruta destino: ".$ruta_temporal_fs."</p>";
                            error_log("move_uploaded_file FAIL. tmp=".$_FILES['documento']['tmp_name']." dest=".$ruta_temporal_fs);
                            $upload_ok = false;
                        }

                        if ($upload_ok) {
                            try {
                                if (!file_exists($ruta_temporal_fs)) {
                                    throw new Exception("No se encontró el archivo temporal tras moverlo.");
                                }

                                clearstatcache();

                                $documento = IOFactory::load($ruta_temporal_fs);
                                $hojaActual = $documento->getSheet(0);
                                $numeroMayorDeFila = $hojaActual->getHighestRow();

                                $array_data_base = [];
                                $control_item = 0;

                                for ($indicefila = 2; $indicefila <= $numeroMayorDeFila; $indicefila++) {

                                    $columna_a = $hojaActual->getCellByColumnAndRow(1, $indicefila)->getValue();

                                    $columna_b = $hojaActual->getCellByColumnAndRow(2, $indicefila)->getFormattedValue();
                                    $columna_c = $hojaActual->getCellByColumnAndRow(3, $indicefila)->getFormattedValue();
                                    $columna_d = $hojaActual->getCellByColumnAndRow(4, $indicefila)->getFormattedValue();
                                    $columna_e = $hojaActual->getCellByColumnAndRow(5, $indicefila)->getFormattedValue();
                                    $columna_f = $hojaActual->getCellByColumnAndRow(6, $indicefila)->getFormattedValue();
                                    $columna_g = $hojaActual->getCellByColumnAndRow(7, $indicefila)->getFormattedValue();
                                    $columna_h = $hojaActual->getCellByColumnAndRow(8, $indicefila)->getFormattedValue();
                                    $columna_i = $hojaActual->getCellByColumnAndRow(9, $indicefila)->getFormattedValue();

                                    if ($columna_a !== "" && $columna_a !== null) {
                                        $array_data_base[$control_item]['A'] = validar_input(trim((string)$columna_a));
                                        $array_data_base[$control_item]['B'] = validar_input(normalizarTextoCelda($columna_b));
                                        $array_data_base[$control_item]['C'] = validar_input(normalizarTextoCelda($columna_c));
                                        $array_data_base[$control_item]['D'] = validar_input(normalizarTextoCelda($columna_d));
                                        $array_data_base[$control_item]['E'] = validar_input(normalizarTextoCelda($columna_e));
                                        $array_data_base[$control_item]['F'] = validar_input(normalizarTextoCelda($columna_f));
                                        $array_data_base[$control_item]['G'] = validar_input(normalizarTextoCelda($columna_g));
                                        $array_data_base[$control_item]['H'] = validar_input(normalizarTextoCelda($columna_h));
                                        $array_data_base[$control_item]['I'] = validar_input(normalizarTextoCelda($columna_i)); // BREAK no se procesa
                                        $control_item++;
                                    }
                                }

                                // Si NO existe array_convenciones en includes, intenta leerla de hoja "CONVENCIONES"
                                if (!isset($array_convenciones) || !is_array($array_convenciones)) {
                                    $array_convenciones = [];
                                    $sheetConv = null;
                                    foreach ($documento->getAllSheets() as $sh) {
                                        if (strtoupper(trim($sh->getTitle())) === 'CONVENCIONES') {
                                            $sheetConv = $sh;
                                            break;
                                        }
                                    }
                                    if ($sheetConv) {
                                        $maxRowConv = $sheetConv->getHighestRow();
                                        for ($r=2; $r <= $maxRowConv; $r++) {
                                            $code = trim((string)$sheetConv->getCellByColumnAndRow(1, $r)->getValue());
                                            if ($code !== '') {
                                                $array_convenciones[$code] = true;
                                            }
                                        }
                                    }
                                }

                                // Log errores (REL para link, FS para escribir)
                                $nombre_temporal_control    = $nombre_directorio_rel."CARGAR_FAIL".date('YmdHis').".txt";
                                $nombre_temporal_control_fs = $nombre_directorio_fs ."CARGAR_FAIL".date('YmdHis').".txt";

                                /* ==========================================================
                                   DUPLICADOS OPTIMIZADO (1 query por usuario)
                                ========================================================== */
                                $inicioSemana = $dias_semana[0]." 00:00:00";
                                $finSemana    = $dias_semana[6]." 23:59:59";

                                $stmtDup = $enlace_db->prepare(
                                    "SELECT COUNT(*) AS cnt
                                     FROM tb_control_turno_malla
                                     WHERE cotm_usuario=?
                                       AND cotm_inicio BETWEEN ? AND ?
                                       AND (cotm_estado='' OR cotm_estado IS NULL)"
                                );
                                $stmtDup->bind_param("sss", $id_usuario_dup, $inicioSemana, $finSemana);

                                for ($i=0; $i < count($array_data_base); $i++) {
                                    $id_usuario_dup = trim($array_data_base[$i]['A']);
                                    if ($id_usuario_dup === "") continue;

                                    $stmtDup->execute();
                                    $row = $stmtDup->get_result()->fetch_assoc();
                                    $cnt = (int)($row['cnt'] ?? 0);

                                    if ($cnt > 0) {
                                        $control_duplicado += $cnt;
                                        $string_duplicado .= $id_usuario_dup."\r\n";
                                    }
                                }

                                if ($control_duplicado > 0) {
                                    $_SESSION['malla_temporal'] = $array_data_base;
                                    $respuesta_accion = "<p class='alert alert-danger p-1 mb-2'>Se encontraron {$control_duplicado} turnos ya programados. Confirma si deseas reemplazarlos.</p>";
                                } else {

                                    /* ==========================================================
                                       INSERCIÓN “TODO O NADA” (TRANSACCIÓN)
                                    ========================================================== */
                                    $enlace_db->begin_transaction();
                                    try {
                                        // Variables para bind (por referencia)
                                        $id_turno_validar = '';
                                        $id_usuario = '';
                                        $tipo_turno = '';
                                        $turno_inicio = '';
                                        $turno_fin = '';
                                        $turno_duracion = '';
                                        $tipo_jornada = '';

                                        $sentencia_insert_data = $enlace_db->prepare(
                                            "INSERT INTO `tb_control_turno_malla`
                                            (`cotm_id`, `cotm_usuario`, `cotm_tipo`, `cotm_inicio`, `cotm_fin`, `cotm_duracion`, `cotm_jornada`, `cotm_observaciones_inicio`, `cotm_observaciones_fin`, `cotm_estado`)
                                            VALUES (?,?,?,?,?,?,?,'','','')"
                                        );
                                        $sentencia_insert_data->bind_param(
                                            'sssssss',
                                            $id_turno_validar, $id_usuario, $tipo_turno, $turno_inicio, $turno_fin, $turno_duracion, $tipo_jornada
                                        );

                                        for ($i=0; $i < count($array_data_base); $i++) {

                                            $id_usuario = trim($array_data_base[$i]['A']);
                                            if ($id_usuario === "") continue;

                                            for ($j=0; $j < count($array_columna); $j++) {

                                                $dia_semana = $dias_semana[$j];
                                                $id_turno_validar = $id_usuario.$dia_semana;

                                                $id_columna = $array_columna[$j];
                                                $valor_celda = normalizarTextoCelda($array_data_base[$i][$id_columna] ?? '');

                                                // Defaults
                                                $turno_inicio   = $dia_semana.' 00:00';
                                                $turno_fin      = $dia_semana.' 00:00';
                                                $turno_duracion = '';
                                                $tipo_jornada   = 'na';
                                                $tipo_turno     = 'na';

                                                if ($valor_celda === "") {
                                                    // na
                                                } elseif (isset($array_convenciones[$valor_celda])) {

                                                    $tipo_turno   = $valor_celda;
                                                    $tipo_jornada = 'Diurno';
                                                    $turno_inicio = $dia_semana.' 08:00';
                                                    $turno_fin    = $dia_semana.' 17:00';
                                                    $turno_duracion = conversorSegundosHoras_sn(dateDiff($turno_inicio, $turno_fin));

                                                } else {

                                                    if (!esHorarioValido($valor_celda)) {
                                                        $control_fail++;
                                                        $string_fail .= "FORMATO INVALIDO | Id turno: {$id_turno_validar} | Usuario: {$id_usuario} | Valor: {$valor_celda}\r\n";
                                                        continue;
                                                    }

                                                    $tipo_turno   = 'turno';
                                                    $tipo_jornada = 'Diurno';

                                                    $horario_explode = array_map('trim', explode('-', $valor_celda));
                                                    $turno_inicio = $dia_semana.' '.$horario_explode[0];
                                                    $turno_fin    = $dia_semana.' '.$horario_explode[1];

                                                    if ($turno_inicio > $turno_fin) {
                                                        $turno_fin = date("Y-m-d H:i", strtotime("+ 1 day", strtotime($turno_fin)));
                                                        $tipo_jornada = 'Nocturno';
                                                    }

                                                    $turno_duracion = conversorSegundosHoras_sn(dateDiff($turno_inicio, $turno_fin));
                                                }

                                                if ($sentencia_insert_data->execute()) {
                                                    $control_insert++;
                                                } else {
                                                    $control_fail++;
                                                    $string_fail .= "INSERT FAIL | Id turno: {$id_turno_validar} | Usuario: {$id_usuario} | Tipo: {$tipo_turno} | {$turno_inicio} A {$turno_fin}\r\n";
                                                }
                                            }
                                        }

                                        // Log si hay fallas
                                        if ($control_fail > 0 && $nombre_temporal_control_fs !== '') {
                                            $archivo_fail = fopen($nombre_temporal_control_fs,'a');
                                            if ($archivo_fail) {
                                                fputs($archivo_fail,$string_fail);
                                                fclose($archivo_fail);
                                            }
                                        }

                                        // Éxito (7 celdas por usuario)
                                        $esperado = (count($array_data_base) * 7);
                                        if (($control_insert + $control_fail) === $esperado) {
                                            $enlace_db->commit();
                                            $respuesta_accion = "<p class='alert alert-success p-1 mb-2'>Base cargada exitosamente | Cargado: {$control_insert} | Error: {$control_fail}</p>";
                                            $_SESSION['registro_cargue_base'] = 1;
                                        } else {
                                            // si no coincide, rollback para evitar carga parcial
                                            $enlace_db->rollback();
                                            $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>¡Problemas al cargar base, por favor intente nuevamente! (Se revirtió para evitar carga parcial)</p>";
                                        }

                                    } catch (Throwable $e) {
                                        $enlace_db->rollback();
                                        error_log("ERROR TRANSACCION CARGA MALLA: ".$e->getMessage());
                                        $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Error al cargar malla. Se realizó rollback para evitar carga parcial. Revise logs.</p>";
                                    }
                                }

                            } catch (Throwable $e) {
                                error_log("ERROR LECTURA/PROCESO EXCEL MALLA: ".$e->getMessage());
                                $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Error procesando el archivo. Revise el formato y logs.</p>";
                            } finally {
                                // Limpia temporal
                                @unlink($ruta_temporal_fs);
                            }
                        }
                    }
                }

            } else {
                $respuesta_accion = "<p class='alert alert-success p-1 mb-2'>¡La base ya ha sido cargada, haga clic en Finalizar para salir!</p>";
            }
        }
    }

    /* ==========================================================
       Confirmación de reemplazo (duplicados) - UPSERT con TRANSACCIÓN
    ========================================================== */
    if ($actualizar_duplicados=='actualizar' && isset($_SESSION['malla_temporal']) && is_array($_SESSION['malla_temporal']) && count($_SESSION['malla_temporal'])>0) {

        $semana = isset($_GET['semana']) ? validar_input($_GET['semana']) : '';
        $dias_semana = obtenerDiasSemanaISO($semana);

        if (count($dias_semana) !== 7) {
            $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Semana inválida. Selecciona una semana válida (YYYY-W##).</p>";
        } else {

            if ($_SESSION['registro_cargue_base']!=1) {

                $nombre_directorio_rel = "storage_temporal/";
                $nombre_directorio_fs  = __DIR__ . DIRECTORY_SEPARATOR . "storage_temporal" . DIRECTORY_SEPARATOR;

                if (!is_dir($nombre_directorio_fs)) {
                    @mkdir($nombre_directorio_fs, 0755, true);
                }

                $nombre_temporal_control    = $nombre_directorio_rel."CARGAR_FAIL".date('YmdHis').".txt";
                $nombre_temporal_control_fs = $nombre_directorio_fs ."CARGAR_FAIL".date('YmdHis').".txt";

                try {
                    $enlace_db->begin_transaction();

                    // Variables para bind
                    $id_turno_validar = '';
                    $id_usuario = '';
                    $tipo_turno = '';
                    $turno_inicio = '';
                    $turno_fin = '';
                    $turno_duracion = '';
                    $tipo_jornada = '';

                    $sentencia_insert_data = $enlace_db->prepare(
                        "INSERT INTO `tb_control_turno_malla`
                        (`cotm_id`, `cotm_usuario`, `cotm_tipo`, `cotm_inicio`, `cotm_fin`, `cotm_duracion`, `cotm_jornada`, `cotm_observaciones_inicio`, `cotm_observaciones_fin`, `cotm_estado`)
                        VALUES (?,?,?,?,?,?,?,'','','')
                        ON DUPLICATE KEY UPDATE `cotm_tipo`=?, `cotm_inicio`=?, `cotm_fin`=?, `cotm_duracion`=?, `cotm_jornada`=?"
                    );

                    $sentencia_insert_data->bind_param(
                        'ssssssssssss',
                        $id_turno_validar, $id_usuario, $tipo_turno, $turno_inicio, $turno_fin, $turno_duracion, $tipo_jornada,
                        $tipo_turno, $turno_inicio, $turno_fin, $turno_duracion, $tipo_jornada
                    );

                    for ($i=0; $i < count($_SESSION['malla_temporal']); $i++) {

                        $id_usuario = trim($_SESSION['malla_temporal'][$i]['A']);
                        if ($id_usuario === "") continue;

                        for ($j=0; $j < count($array_columna); $j++) {

                            $dia_semana = $dias_semana[$j];
                            $id_turno_validar = $id_usuario.$dia_semana;

                            $id_columna = $array_columna[$j];
                            $valor_celda = normalizarTextoCelda($_SESSION['malla_temporal'][$i][$id_columna] ?? '');

                            // Defaults
                            $turno_inicio   = $dia_semana.' 00:00';
                            $turno_fin      = $dia_semana.' 00:00';
                            $turno_duracion = '';
                            $tipo_jornada   = 'na';
                            $tipo_turno     = 'na';

                            if ($valor_celda === "") {
                                // na
                            } elseif (isset($array_convenciones[$valor_celda])) {

                                $tipo_turno   = $valor_celda;
                                $tipo_jornada = 'Diurno';
                                $turno_inicio = $dia_semana.' 08:00';
                                $turno_fin    = $dia_semana.' 17:00';
                                $turno_duracion = conversorSegundosHoras_sn(dateDiff($turno_inicio, $turno_fin));

                            } else {

                                if (!esHorarioValido($valor_celda)) {
                                    $control_fail++;
                                    $string_fail .= "FORMATO INVALIDO | Id turno: {$id_turno_validar} | Usuario: {$id_usuario} | Valor: {$valor_celda}\r\n";
                                    continue;
                                }

                                $tipo_turno   = 'turno';
                                $tipo_jornada = 'Diurno';

                                $horario_explode = array_map('trim', explode('-', $valor_celda));
                                $turno_inicio = $dia_semana.' '.$horario_explode[0];
                                $turno_fin    = $dia_semana.' '.$horario_explode[1];

                                if ($turno_inicio > $turno_fin) {
                                    $turno_fin = date("Y-m-d H:i", strtotime("+ 1 day", strtotime($turno_fin)));
                                    $tipo_jornada = 'Nocturno';
                                }

                                $turno_duracion = conversorSegundosHoras_sn(dateDiff($turno_inicio, $turno_fin));
                            }

                            if ($sentencia_insert_data->execute()) {
                                $control_insert++;
                            } else {
                                $control_fail++;
                                $string_fail .= "UPSERT FAIL | Id turno: {$id_turno_validar} | Usuario: {$id_usuario} | Tipo: {$tipo_turno} | {$turno_inicio} A {$turno_fin}\r\n";
                            }
                        }
                    }

                    if ($control_fail > 0) {
                        $archivo_fail = fopen($nombre_temporal_control_fs,'a');
                        if ($archivo_fail) {
                            fputs($archivo_fail,$string_fail);
                            fclose($archivo_fail);
                        }
                    }

                    $esperado = (count($_SESSION['malla_temporal']) * 7);
                    if (($control_insert + $control_fail) === $esperado) {
                        $enlace_db->commit();
                        $respuesta_accion = "<p class='alert alert-success p-1 mb-2'>Base cargada exitosamente | Cargado: {$control_insert} | Error: {$control_fail}</p>";
                        $_SESSION['registro_cargue_base']=1;
                        unset($_SESSION['malla_temporal']);
                    } else {
                        $enlace_db->rollback();
                        $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>¡Problemas al cargar base, por favor intente nuevamente! (Se revirtió para evitar carga parcial)</p>";
                    }

                } catch (Throwable $e) {
                    $enlace_db->rollback();
                    error_log("ERROR TRANSACCION UPSERT MALLA: ".$e->getMessage());
                    $respuesta_accion = "<p class='alert alert-warning p-1 mb-2'>Error al reemplazar malla. Se realizó rollback para evitar carga parcial. Revise logs.</p>";
                }

            } else {
                $respuesta_accion = "<p class='alert alert-success p-1 mb-2'>¡La base ya ha sido cargada, haga clic en Finalizar para salir!</p>";
            }
        }
    }

    $ruta_cancelar_finalizar="malla_turnos.php?fechainicio=".base64_encode(date('Y')."-W".date('W'))."&operacion=".base64_encode('Todas')."&id=null";
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include("../config/configuracion_estilos.php"); ?>
</head>
<body>
    <?php
        include("../menu_principal.php");
        include("../menu_header.php");
    ?>
    <div class="contenido">
        <div class="row">
            <div class="col-md-3 py-2"></div>
            <div class="col-md-9 conten-justify-center py-2"></div>
        </div>
        <form name="cargar_base" action="" method="POST" enctype="multipart/form-data">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <?php if (!empty($respuesta_accion)) {echo $respuesta_accion;} ?>
                    <?php if($control_duplicado>0): ?>
                        <p class="alert alert-danger p-1">
                            Se encontraron <?php echo $control_duplicado; ?> turnos programados que se reemplazarán.
                            Por favor confirme si desea reemplazarlos
                            <a href="malla_turnos_cargar.php?actdup=<?php echo base64_encode('actualizar'); ?>&semana=<?php echo $semana; ?>">
                                <span class="fas fa-retweet"></span> Si, reemplazar todo
                            </a>
                        </p>
                    <?php endif; ?>
                    <?php if($control_fail>0 && !empty($nombre_temporal_control)): ?>
                        <p class="alert alert-danger p-1">
                            Descargar log de radicados con error:
                            <a href="gestion_encuestas_base_descargar_error.php?ruta=<?php echo $nombre_temporal_control; ?>" target="_blank">
                                <span class="fas fa-download"></span> Descargar
                            </a>
                        </p>
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                              <label for="semana" class="m-0">Semana</label>
                              <input type="week" class="form-control form-control-sm" name="semana" id="semana"
                                     value="<?php if(isset($_POST["cargar_base"]) OR isset($_GET['semana'])){ echo $semana; } ?>"
                                     <?php if($_SESSION['registro_cargue_base']==1) { echo 'disabled'; } ?>
                                     required>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="inputGroupFile01">Formato malla</label>
                                <div class="custom-file">
                                    <input type="file" name="documento" class="custom-file-input" id="inputGroupFile01"
                                           aria-describedby="inputGroupFileAddon01"
                                           <?php if($_SESSION['registro_cargue_base']==1) { echo 'disabled'; } ?>
                                           accept=".xlsx, .XLSX" required>
                                    <label class="custom-file-label" for="inputGroupFile01" id="inputGroupFile01label">Seleccione un archivo</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <?php if($_SESSION['registro_cargue_base']==1): ?>
                                    <a href="<?php echo $ruta_cancelar_finalizar; ?>" class="btn btn-dark float-right">Finalizar</a>
                                <?php else: ?>
                                    <button class="btn btn-success float-right ml-1" type="submit" name="cargar_base">Cargar malla</button>
                                    <button class="btn btn-danger float-right" type="button" onclick="guardar_cancelar();">Cancelar</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <?php
        include("../footer.php");
        include("../config/configuracion_js.php");
    ?>
    <script type="text/javascript">
        $("#inputGroupFile01").change(function(){
            var valor_opcion = document.getElementById("inputGroupFile01").files[0].name;
            if (valor_opcion!="") {
                document.getElementById('inputGroupFile01label').innerHTML=valor_opcion.substring(0, 25)+"...";
                $("#inputGroupFile01label").addClass("color-verde");
            }
        });
    </script>
</body>
</html>
