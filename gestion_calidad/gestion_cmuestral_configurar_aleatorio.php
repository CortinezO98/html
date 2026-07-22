<?php
//Validación de permisos del usuario para el módulo
$modulo_plataforma="Calidad-Calculadora Muestral";

require_once("../config/validaciones_seguridad.php");
require_once("../config/conexion_db.php");

/*DEFINICIÓN DE VARIABLES*/
// error_reporting(E_ALL);
// ini_set('display_errors', '1');

$titulo_header = "Calculadora Muestral | Aleatorio - Generar";

/* =========================
   REMEDIACIÓN: SESIÓN + ENTRADAS
   ========================= */
if (!isset($_SESSION['registro_creado_random'])) {
    $_SESSION['registro_creado_random'] = 0;
}

// Lectura robusta de GET (sin alterar el flujo: solo evita valores inválidos)
$reg_b64  = $_GET['reg']  ?? '';
$seg_b64  = $_GET['seg']  ?? '';
$date_in  = $_GET['date'] ?? '';

$reg_dec = base64_decode($reg_b64, true);
$seg_dec = base64_decode($seg_b64, true);

$id_registro = validar_input($reg_dec !== false ? $reg_dec : '');
$id_segmento = validar_input($seg_dec !== false ? $seg_dec : '');
$fecha_calculadora = validar_input($date_in);

// Variables para evitar warnings por "undefined"
$respuesta_accion = $respuesta_accion ?? '';
$turno_seg_usuario = $turno_seg_usuario ?? [];
$turno_mostrar_datos = $turno_mostrar_datos ?? [];
$turno_mostrar = $turno_mostrar ?? [];
$array_muestra = $array_muestra ?? [];
$cantidad_muestra_limite_diario_array = $cantidad_muestra_limite_diario_array ?? [];

// Por si en algún ambiente no están definidos:
$array_convenciones = $array_convenciones ?? [];
$array_convenciones_color = $array_convenciones_color ?? [];

/* =========================
   FUNCIONES
   ========================= */
function generar_horario ($dia, $horario) {
    $array_horario = []; // evita variable indefinida

    $horario_explode=explode('-', $horario);
    $hora_1=date('H:i', strtotime(trim($horario_explode[0])));
    $hora_2=date('H:i', strtotime(trim($horario_explode[1])));
    $ultima_hora = $hora_inicio = $hora_1;
    $hora_fin=$hora_2;

    if ($hora_1>$hora_2) {
        $hora_media=date('H:i', strtotime('23:00'));
        $hora_nuevo=date('H:i', strtotime('00:00'));

        while ($ultima_hora < $hora_media) {
            $array_horario[]=$dia.'-'.$ultima_hora;
            $ultima_hora=date('H:i', strtotime('+3 minutes', strtotime($ultima_hora)));
        }

        $array_horario[]=$dia.'-23:00';
        $dia++;
        if ($dia==8) {
            $dia=1;
        }

        while ($hora_nuevo < $hora_fin) {
            $array_horario[]=$dia.'-'.$hora_nuevo;
            $hora_nuevo=date('H:i', strtotime('+3 minutes', strtotime($hora_nuevo)));
        }
    } else {
        while ($ultima_hora < $hora_fin) {
            $array_horario[]=$dia.'-'.$ultima_hora;
            $ultima_hora=date('H:i', strtotime('+3 minutes', strtotime($ultima_hora)));
        }
    }

    return $array_horario;
}

function generar_horario_lider ($dia, $horario, $usuario) {
    $array_horario = []; // evita variable indefinida

    $horario_explode=explode('-', $horario);
    $hora_1=date('H:i', strtotime(trim($horario_explode[0])));
    $hora_2=date('H:i', strtotime(trim($horario_explode[1])));
    $ultima_hora = $hora_inicio = $hora_1;
    $hora_fin=$hora_2;
    $control_fila=0;

    if ($hora_1>$hora_2) {
        $hora_media=date('H:i', strtotime('23:00'));
        $hora_nuevo=date('H:i', strtotime('00:00'));

        while ($ultima_hora < $hora_media) {
            $array_horario[$control_fila]['turno']=$dia.'-'.$ultima_hora;
            $array_horario[$control_fila]['usuario']=$usuario;
            $ultima_hora=date('H:i', strtotime('+3 minutes', strtotime($ultima_hora)));
            $control_fila++;
        }

        $array_horario[$control_fila]['turno']=$dia.'-23:00';
        $array_horario[$control_fila]['usuario']=$usuario;
        $control_fila++;

        $dia++;
        if ($dia==8) {
            $dia=1;
        }

        while ($hora_nuevo < $hora_fin) {
            // Se conserva tu lógica original (sin corregir comportamiento)
            $array_horario[$control_fila]['turno']=$dia.'-'.$ultima_hora;
            $array_horario[$control_fila]['usuario']=$usuario;
            $hora_nuevo=date('H:i', strtotime('+3 minutes', strtotime($hora_nuevo)));
            $control_fila++;
        }
    } else {
        while ($ultima_hora < $hora_fin) {
            $array_horario[$control_fila]['turno']=$dia.'-'.$ultima_hora;
            $array_horario[$control_fila]['usuario']=$usuario;
            $ultima_hora=date('H:i', strtotime('+3 minutes', strtotime($ultima_hora)));
            $control_fila++;
        }
    }

    return $array_horario;
}

function validar_dia($muestra) {
    $muestra=str_replace('1-', 'Lunes-', $muestra);
    $muestra=str_replace('2-', 'Martes-', $muestra);
    $muestra=str_replace('3-', 'Miércoles-', $muestra);
    $muestra=str_replace('4-', 'Jueves-', $muestra);
    $muestra=str_replace('5-', 'Viernes-', $muestra);
    $muestra=str_replace('6-', 'Sábado-', $muestra);
    $muestra=str_replace('7-', 'Domingo-', $muestra);
    return $muestra;
}

/* =========================
   CONSULTA CONFIG (mensual)
   ========================= */
$consulta_string_fechas="SELECT
    `cmm_id`, `cmm_calculadora`, `cmm_mes`, `cmm_segmento`, `cmm_total_mes`, `cmm_muestra_calculada`,
    `cmm_muestra_auditoria`, `cmm_numero_agentes`, `cmm_muestras_agente_mes`, `cmm_muestras_agente_semana`,
    `cmm_semana_dias`, `cmm_semana_peso`, `cmm_semana_porcentaje`, `cmm_semana_muestras`,
    TS.`cms_nombre_segmento`, `cmm_muestra_realizada`, `cmm_muestra_recalculada`
    FROM `tb_gestion_calidad_cmuestral_mensual`
    LEFT JOIN `tb_gestion_calidad_cmuestral_segmento` AS TS
        ON `tb_gestion_calidad_cmuestral_mensual`.`cmm_segmento`=TS.`cms_id`
    WHERE `cmm_calculadora`=? AND `cmm_mes`=? AND `cmm_segmento`=?";

$consulta_registros_fechas = $enlace_db->prepare($consulta_string_fechas);
$consulta_registros_fechas->bind_param("sss", $id_registro, $fecha_calculadora, $id_segmento);
$consulta_registros_fechas->execute();
$resultado_registros_fechas = $consulta_registros_fechas->get_result()->fetch_all(MYSQLI_NUM);

// Para evitar Undefined offset en el HTML
$nombre_segmento = isset($resultado_registros_fechas[0][14]) ? $resultado_registros_fechas[0][14] : '';

/* =========================
   POST: Generar Aleatorio
   ========================= */
if(isset($_POST["guardar_registro"])){

    // Si hay parámetros inválidos, no intentamos generar
    if ($id_registro === '' || $id_segmento === '' || $fecha_calculadora === '') {
        $respuesta_accion = "<script type='text/javascript'>alertify.warning('Parámetros inválidos para generar aleatorio. Verifique el enlace e intente nuevamente.', 0);</script>";
    } else {

        if($_SESSION['registro_creado_random']!=1){

            // Debe existir configuración mensual
            if (empty($resultado_registros_fechas)) {
                $respuesta_accion = "<script type='text/javascript'>alertify.warning('No se encontró configuración mensual para este mes/segmento. Verifique la calculadora muestral.', 0);</script>";
            } else {

                // Denominador 1: semana_peso (índice 11)
                $semana_peso = (float)$resultado_registros_fechas[0][11];

                if ($semana_peso <= 0) {
                    // ✅ Remediación del DivisionByZeroError (sin cambiar funcionalidad: no se puede calcular si el peso es 0)
                    $respuesta_accion = "<script type='text/javascript'>alertify.warning('No se puede generar aleatorio: el peso de semana está en 0 o inválido. Corrija la configuración e intente nuevamente.', 0);</script>";
                } else {

                    // =========================
                    // Cargar malla (agentes)
                    // =========================
                    $consulta_string_malla="SELECT `ccmm_id`, `ccmm_mes`, `ccmm_segmento`, `ccmm_usuario`, `ccmm_usuario_segmento`, `ccmm_coordinador`,
                        `ccmm_lunes`, `ccmm_martes`, `ccmm_miercoles`, `ccmm_jueves`, `ccmm_viernes`, `ccmm_sabado`, `ccmm_domingo`
                        FROM `tb_gestion_calidad_cmuestral_malla`
                        WHERE `ccmm_mes`=? AND `ccmm_segmento`=?";

                    $consulta_registros_malla = $enlace_db->prepare($consulta_string_malla);
                    $consulta_registros_malla->bind_param("ss", $fecha_calculadora, $id_segmento);
                    $consulta_registros_malla->execute();
                    $resultado_registros_malla = $consulta_registros_malla->get_result()->fetch_all(MYSQLI_NUM);

                    // Inicializaciones
                    $array_usuario_turnos_dias_cantidad = array_fill(1, 7, 0);
                    $array_usuario_turnos_dias = [];
                    $array_usuario_turnos = [];

                    for ($i=0; $i < count($resultado_registros_malla); $i++) {
                        $usu = $resultado_registros_malla[$i][3];

                        $array_usuario_turnos_dias[$usu]=0;
                        $array_usuario_turnos[$usu]=[];

                        if ($resultado_registros_malla[$i][6]!="" AND strpos($resultado_registros_malla[$i][6], '-')!==false) {
                            $array_usuario_turnos[$usu]=array_merge(generar_horario('1', $resultado_registros_malla[$i][6]), $array_usuario_turnos[$usu]);
                            $array_usuario_turnos_dias[$usu]+=1;
                            $array_usuario_turnos_dias_cantidad[1]+=1;
                        }

                        if ($resultado_registros_malla[$i][7]!="" AND strpos($resultado_registros_malla[$i][7], '-')!==false) {
                            $array_usuario_turnos[$usu]=array_merge(generar_horario('2', $resultado_registros_malla[$i][7]), $array_usuario_turnos[$usu]);
                            $array_usuario_turnos_dias[$usu]+=1;
                            $array_usuario_turnos_dias_cantidad[2]+=1;
                        }

                        if ($resultado_registros_malla[$i][8]!="" AND strpos($resultado_registros_malla[$i][8], '-')!==false) {
                            $array_usuario_turnos[$usu]=array_merge(generar_horario('3', $resultado_registros_malla[$i][8]), $array_usuario_turnos[$usu]);
                            $array_usuario_turnos_dias[$usu]+=1;
                            $array_usuario_turnos_dias_cantidad[3]+=1;
                        }

                        if ($resultado_registros_malla[$i][9]!="" AND strpos($resultado_registros_malla[$i][9], '-')!==false) {
                            $array_usuario_turnos[$usu]=array_merge(generar_horario('4', $resultado_registros_malla[$i][9]), $array_usuario_turnos[$usu]);
                            $array_usuario_turnos_dias[$usu]+=1;
                            $array_usuario_turnos_dias_cantidad[4]+=1;
                        }

                        if ($resultado_registros_malla[$i][10]!="" AND strpos($resultado_registros_malla[$i][10], '-')!==false) {
                            $array_usuario_turnos[$usu]=array_merge(generar_horario('5', $resultado_registros_malla[$i][10]), $array_usuario_turnos[$usu]);
                            $array_usuario_turnos_dias[$usu]+=1;
                            $array_usuario_turnos_dias_cantidad[5]+=1;
                        }

                        if ($resultado_registros_malla[$i][11]!="" AND strpos($resultado_registros_malla[$i][11], '-')!==false) {
                            $array_usuario_turnos[$usu]=array_merge(generar_horario('6', $resultado_registros_malla[$i][11]), $array_usuario_turnos[$usu]);
                            $array_usuario_turnos_dias[$usu]+=1;
                            $array_usuario_turnos_dias_cantidad[6]+=1;
                        }

                        if ($resultado_registros_malla[$i][12]!="" AND strpos($resultado_registros_malla[$i][12], '-')!==false) {
                            $array_usuario_turnos[$usu]=array_merge(generar_horario('7', $resultado_registros_malla[$i][12]), $array_usuario_turnos[$usu]);
                            $array_usuario_turnos_dias[$usu]+=1;
                            $array_usuario_turnos_dias_cantidad[7]+=1;
                        }
                    }

                    // =========================
                    // Cargar malla (líderes)
                    // =========================
                    $consulta_string_malla_lider="SELECT `ccmm_id`, `ccmm_mes`, `ccmm_segmento`, `ccmm_usuario`,
                        `ccmm_lunes`, `ccmm_martes`, `ccmm_miercoles`, `ccmm_jueves`, `ccmm_viernes`, `ccmm_sabado`, `ccmm_domingo`
                        FROM `tb_gestion_calidad_cmuestral_malla_lider`
                        WHERE `ccmm_mes`=? AND `ccmm_segmento`=?
                        AND ((`ccmm_lunes`<>'' AND `ccmm_lunes` LIKE '%-%') OR (`ccmm_martes`<>'' AND `ccmm_martes` LIKE '%-%')
                        OR (`ccmm_miercoles`<>'' AND `ccmm_miercoles` LIKE '%-%') OR (`ccmm_jueves`<>'' AND `ccmm_jueves` LIKE '%-%')
                        OR (`ccmm_viernes`<>'' AND `ccmm_viernes` LIKE '%-%') OR (`ccmm_sabado`<>'' AND `ccmm_sabado` LIKE '%-%')
                        OR (`ccmm_domingo`<>'' AND `ccmm_domingo` LIKE '%-%'))";

                    $consulta_registros_malla_lider = $enlace_db->prepare($consulta_string_malla_lider);
                    $consulta_registros_malla_lider->bind_param("ss", $fecha_calculadora, $id_segmento);
                    $consulta_registros_malla_lider->execute();
                    $resultado_registros_malla_lider = $consulta_registros_malla_lider->get_result()->fetch_all(MYSQLI_NUM);

                    // Denominador 2: cantidad de líderes válidos
                    $cant_lideres = count($resultado_registros_malla_lider);
                    if ($cant_lideres <= 0) {
                        // ✅ Remediación del DivisionByZeroError cuando no hay líderes
                        $respuesta_accion = "<script type='text/javascript'>alertify.warning('No se puede generar aleatorio: no hay líderes con horarios válidos para este mes/segmento.', 0);</script>";
                    } else {

                        // ✅ Ya es seguro calcular
                        $cantidad_muestra_lider_real = (($resultado_registros_fechas[0][16]/$semana_peso) / $cant_lideres);
                        $cantidad_muestra_lider = round((($resultado_registros_fechas[0][16]/$semana_peso) / $cant_lideres));

                        if ($cantidad_muestra_lider_real>$cantidad_muestra_lider) {
                            $cantidad_muestra_lider+=1;
                        }
                        $cantidad_muestra_lider+=2;

                        // Total turnos programados (denominador 3)
                        $total_turnos_programado = array_sum($array_usuario_turnos_dias_cantidad);

                        if ($total_turnos_programado <= 0) {
                            // ✅ Remediación de división por cero en porcentaje día
                            $respuesta_accion = "<script type='text/javascript'>alertify.warning('No se puede generar aleatorio: no hay turnos programados válidos en la malla.', 0);</script>";
                        } else {

                            // Inicializa controles para evitar warnings
                            $random_control_dias = array_fill(1, 7, 0);
                            $random_control_lider = [];

                            // Cantidad límite diaria
                            for ($i=1; $i <= 7; $i++) {
                                if ($array_usuario_turnos_dias_cantidad[$i]>0) {
                                    $porcentaje_dia=($array_usuario_turnos_dias_cantidad[$i]/$total_turnos_programado)*100;
                                    $cantidad_muestra_limite_diario_real=($resultado_registros_fechas[0][16]*$porcentaje_dia)/100;
                                    $cantidad_muestra_limite_diario=round(($resultado_registros_fechas[0][16]*$porcentaje_dia)/100);
                                    $cantidad_muestra_limite_diario_array[$i]=$cantidad_muestra_limite_diario;

                                    if ($cantidad_muestra_limite_diario_real>$cantidad_muestra_limite_diario) {
                                        $cantidad_muestra_limite_diario_array[$i]+=1;
                                    }
                                    $cantidad_muestra_limite_diario_array[$i]+=10;
                                } else {
                                    $cantidad_muestra_limite_diario_array[$i] = 0;
                                }
                            }

                            // Armar turnos líderes
                            $array_lider_turnos_id=[];
                            for ($i=0; $i < count($resultado_registros_malla_lider); $i++) {

                                if ($resultado_registros_malla_lider[$i][4]!="" AND strpos($resultado_registros_malla_lider[$i][4], '-')!==false) {
                                    $array_lider_turnos_id=array_merge(generar_horario_lider('1', $resultado_registros_malla_lider[$i][4], $resultado_registros_malla_lider[$i][3]), $array_lider_turnos_id);
                                }
                                if ($resultado_registros_malla_lider[$i][5]!="" AND strpos($resultado_registros_malla_lider[$i][5], '-')!==false) {
                                    $array_lider_turnos_id=array_merge(generar_horario_lider('2', $resultado_registros_malla_lider[$i][5], $resultado_registros_malla_lider[$i][3]), $array_lider_turnos_id);
                                }
                                if ($resultado_registros_malla_lider[$i][6]!="" AND strpos($resultado_registros_malla_lider[$i][6], '-')!==false) {
                                    $array_lider_turnos_id=array_merge(generar_horario_lider('3', $resultado_registros_malla_lider[$i][6], $resultado_registros_malla_lider[$i][3]), $array_lider_turnos_id);
                                }
                                if ($resultado_registros_malla_lider[$i][7]!="" AND strpos($resultado_registros_malla_lider[$i][7], '-')!==false) {
                                    $array_lider_turnos_id=array_merge(generar_horario_lider('4', $resultado_registros_malla_lider[$i][7], $resultado_registros_malla_lider[$i][3]), $array_lider_turnos_id);
                                }
                                if ($resultado_registros_malla_lider[$i][8]!="" AND strpos($resultado_registros_malla_lider[$i][8], '-')!==false) {
                                    $array_lider_turnos_id=array_merge(generar_horario_lider('5', $resultado_registros_malla_lider[$i][8], $resultado_registros_malla_lider[$i][3]), $array_lider_turnos_id);
                                }
                                if ($resultado_registros_malla_lider[$i][9]!="" AND strpos($resultado_registros_malla_lider[$i][9], '-')!==false) {
                                    $array_lider_turnos_id=array_merge(generar_horario_lider('6', $resultado_registros_malla_lider[$i][9], $resultado_registros_malla_lider[$i][3]), $array_lider_turnos_id);
                                }
                                if ($resultado_registros_malla_lider[$i][10]!="" AND strpos($resultado_registros_malla_lider[$i][10], '-')!==false) {
                                    $array_lider_turnos_id=array_merge(generar_horario_lider('7', $resultado_registros_malla_lider[$i][10], $resultado_registros_malla_lider[$i][3]), $array_lider_turnos_id);
                                }
                            }

                            // Si por lógica de horarios no se generó ningún turno líder, también evitamos fatal posterior
                            if (count($array_lider_turnos_id) <= 0) {
                                $respuesta_accion = "<script type='text/javascript'>alertify.warning('No se puede generar aleatorio: no se generaron turnos para líderes (verifique horarios).', 0);</script>";
                            } else {

                                shuffle($array_lider_turnos_id);
                                $array_lideres=array_column($array_lider_turnos_id, 'usuario');
                                $array_lideres_turnos=array_column($array_lider_turnos_id, 'turno');

                                // Inicializa control por líder
                                foreach ($array_lideres as $lid) {
                                    if (!isset($random_control_lider[$lid])) {
                                        $random_control_lider[$lid] = array_fill(1, 7, 0);
                                    }
                                }

                                $muestras_agente=intval($resultado_registros_fechas[0][9]);
                                $total_muestras_objetivo=intval($resultado_registros_fechas[0][16]);

                                if ($total_muestras_objetivo<=0) {
                                    $total_muestras_objetivo=$muestras_agente*count($resultado_registros_malla);
                                }

                                /*
                                 * AJUSTE SOLICITADO:
                                 * Distribución equitativa de muestras por asesor/agente.
                                 *
                                 * No se modifica la lógica de horarios ni la generación de franjas.
                                 * La diferencia es que ya no se llena un asesor completamente antes de pasar al siguiente.
                                 * Ahora se asigna por rondas, priorizando siempre el asesor con menor cantidad de muestras.
                                 */
                                $agentes_malla=[];
                                $random_agente=[];
                                $random_agente_dias=[];
                                $random_agente_total=[];
                                $objetivo_agente=[];

                                for ($i=0; $i < count($resultado_registros_malla); $i++) {
                                    $id_usuario=$resultado_registros_malla[$i][3];

                                    if (!isset($array_usuario_turnos[$id_usuario])) {
                                        $array_usuario_turnos[$id_usuario]=[];
                                    }

                                    if (count($array_usuario_turnos[$id_usuario])>0) {
                                        shuffle($array_usuario_turnos[$id_usuario]);
                                        $agentes_malla[]=$id_usuario;
                                        $random_agente[$id_usuario]['muestra']=[];
                                        $random_agente[$id_usuario]['lider']=[];
                                        $random_agente_dias[$id_usuario]['muestra']=[];
                                        $random_agente_total[$id_usuario]=0;
                                    }
                                }

                                $agentes_malla=array_values(array_unique($agentes_malla));
                                shuffle($agentes_malla);

                                $cantidad_agentes_validos=count($agentes_malla);

                                if ($cantidad_agentes_validos>0) {
                                    $base_muestras_agente=intdiv($total_muestras_objetivo, $cantidad_agentes_validos);
                                    $residuo_muestras_agente=$total_muestras_objetivo % $cantidad_agentes_validos;

                                    for ($i=0; $i < $cantidad_agentes_validos; $i++) {
                                        $id_usuario=$agentes_malla[$i];
                                        $objetivo_agente[$id_usuario]=$base_muestras_agente+(($i<$residuo_muestras_agente) ? 1 : 0);
                                    }
                                }

                                $total_muestras_asignadas=0;

                                $asignar_muestra_agente = function($id_usuario, $exigir_dia_unico, $permitir_exceder_objetivo=false) use (
                                    &$array_usuario_turnos,
                                    &$array_usuario_turnos_dias,
                                    &$cantidad_muestra_limite_diario_array,
                                    &$array_lideres,
                                    &$array_lideres_turnos,
                                    &$random_control_dias,
                                    &$random_control_lider,
                                    &$cantidad_muestra_lider,
                                    &$random_agente,
                                    &$random_agente_dias,
                                    &$random_agente_total,
                                    &$objetivo_agente,
                                    &$total_muestras_asignadas,
                                    &$total_muestras_objetivo
                                ) {
                                    if (!isset($random_agente_total[$id_usuario])) {
                                        $random_agente_total[$id_usuario]=0;
                                    }

                                    if (!isset($objetivo_agente[$id_usuario])) {
                                        $objetivo_agente[$id_usuario]=0;
                                    }

                                    if (!$permitir_exceder_objetivo AND $random_agente_total[$id_usuario]>=$objetivo_agente[$id_usuario]) {
                                        return false;
                                    }

                                    if ($total_muestras_asignadas>=$total_muestras_objetivo) {
                                        return false;
                                    }

                                    if (!isset($array_usuario_turnos[$id_usuario]) OR count($array_usuario_turnos[$id_usuario])<=0) {
                                        return false;
                                    }

                                    if (!isset($random_agente_dias[$id_usuario]['muestra'])) {
                                        $random_agente_dias[$id_usuario]['muestra']=[];
                                    }

                                    $turnos_candidatos=$array_usuario_turnos[$id_usuario];
                                    shuffle($turnos_candidatos);

                                    for ($j=0; $j < count($turnos_candidatos); $j++) {
                                        $turno_registro=$turnos_candidatos[$j];

                                        if ($turno_registro=="") {
                                            continue;
                                        }

                                        $turno_registro_dia=substr($turno_registro, 0, 1);

                                        if ($exigir_dia_unico AND in_array($turno_registro_dia, $random_agente_dias[$id_usuario]['muestra'])) {
                                            continue;
                                        }

                                        if (!isset($cantidad_muestra_limite_diario_array[$turno_registro_dia])) {
                                            $cantidad_muestra_limite_diario_array[$turno_registro_dia]=0;
                                        }

                                        if (!isset($random_control_dias[$turno_registro_dia])) {
                                            $random_control_dias[$turno_registro_dia]=0;
                                        }

                                        if ($random_control_dias[$turno_registro_dia]>$cantidad_muestra_limite_diario_array[$turno_registro_dia]) {
                                            continue;
                                        }

                                        $key_turno_lider=false;
                                        $menor_carga_lider=null;

                                        foreach ($array_lideres_turnos as $key_lider => $turno_lider) {
                                            if ($turno_lider!=$turno_registro) {
                                                continue;
                                            }

                                            if (!isset($array_lideres[$key_lider]) OR $array_lideres[$key_lider]=="") {
                                                continue;
                                            }

                                            $id_lider=$array_lideres[$key_lider];

                                            if (!isset($random_control_lider[$id_lider])) {
                                                $random_control_lider[$id_lider]=array_fill(1, 7, 0);
                                            }

                                            if (!isset($random_control_lider[$id_lider][$turno_registro_dia])) {
                                                $random_control_lider[$id_lider][$turno_registro_dia]=0;
                                            }

                                            if ($random_control_lider[$id_lider][$turno_registro_dia]<$cantidad_muestra_lider) {
                                                $carga_lider=array_sum($random_control_lider[$id_lider]);

                                                if ($key_turno_lider===false OR $carga_lider<$menor_carga_lider) {
                                                    $key_turno_lider=$key_lider;
                                                    $menor_carga_lider=$carga_lider;
                                                }
                                            }
                                        }

                                        if ($key_turno_lider===false) {
                                            continue;
                                        }

                                        $id_lider=$array_lideres[$key_turno_lider];

                                        $random_agente[$id_usuario]['muestra'][]=validar_dia($turno_registro);
                                        $random_agente[$id_usuario]['lider'][]=$id_lider;
                                        $random_agente_dias[$id_usuario]['muestra'][]=$turno_registro_dia;

                                        $random_control_dias[$turno_registro_dia]+=1;
                                        $random_control_lider[$id_lider][$turno_registro_dia]+=1;
                                        $random_agente_total[$id_usuario]+=1;
                                        $total_muestras_asignadas+=1;

                                        unset($array_lideres_turnos[$key_turno_lider]);
                                        unset($array_lideres[$key_turno_lider]);

                                        return true;
                                    }

                                    return false;
                                };

                                // Primera fase: completar la cuota equitativa de cada asesor.
                                for ($ronda=0; $ronda < max(1, $muestras_agente+10); $ronda++) {
                                    $hubo_asignacion=false;

                                    usort($agentes_malla, function($a, $b) use (&$random_agente_total, &$objetivo_agente) {
                                        $ca=$random_agente_total[$a] ?? 0;
                                        $cb=$random_agente_total[$b] ?? 0;

                                        if ($ca==$cb) {
                                            $oa=$objetivo_agente[$a] ?? 0;
                                            $ob=$objetivo_agente[$b] ?? 0;
                                            return $ob<=>$oa;
                                        }

                                        return $ca<=>$cb;
                                    });

                                    for ($i=0; $i < count($agentes_malla); $i++) {
                                        $id_usuario=$agentes_malla[$i];
                                        $exigir_dia_unico=($random_agente_total[$id_usuario] < ($array_usuario_turnos_dias[$id_usuario] ?? 0));

                                        if ($asignar_muestra_agente($id_usuario, $exigir_dia_unico, false)) {
                                            $hubo_asignacion=true;
                                        }

                                        if ($total_muestras_asignadas>=$total_muestras_objetivo) {
                                            break 2;
                                        }
                                    }

                                    if (!$hubo_asignacion) {
                                        break;
                                    }
                                }

                                // Segunda fase: si algún asesor no pudo completar su cuota por disponibilidad,
                                // se asignan faltantes al asesor con menor carga disponible, manteniendo la distribución lo más pareja posible.
                                while ($total_muestras_asignadas<$total_muestras_objetivo) {
                                    $hubo_asignacion=false;

                                    usort($agentes_malla, function($a, $b) use (&$random_agente_total) {
                                        $ca=$random_agente_total[$a] ?? 0;
                                        $cb=$random_agente_total[$b] ?? 0;
                                        return $ca<=>$cb;
                                    });

                                    for ($i=0; $i < count($agentes_malla); $i++) {
                                        $id_usuario=$agentes_malla[$i];
                                        $objetivo_agente[$id_usuario]=max($objetivo_agente[$id_usuario], $random_agente_total[$id_usuario]+1);

                                        if ($asignar_muestra_agente($id_usuario, false, true)) {
                                            $hubo_asignacion=true;
                                        }

                                        if ($total_muestras_asignadas>=$total_muestras_objetivo) {
                                            break;
                                        }
                                    }

                                    if (!$hubo_asignacion) {
                                        break;
                                    }
                                }

                                // Insertar muestras (misma lógica)
                                $sentencia_insert_muestras = $enlace_db->prepare(
                                    "INSERT INTO `tb_gestion_calidad_cmuestral_muestras`
                                    (`cmm_calculadora`, `cmm_mes`, `cmm_segmento`, `cmm_usuario`, `cmm_monitor`, `cmm_muestra_auditoria`)
                                    VALUES (?,?,?,?,?,?)"
                                );

                                $sentencia_insert_muestras->bind_param('ssssss', $id_registro, $fecha_calculadora, $id_segmento, $cmm_usuario, $cmm_monitor, $cmm_muestra_auditoria);

                                $control_insert=0;
                                $control_registros=0;

                                for ($i=0; $i < count($resultado_registros_malla); $i++) {
                                    $id_usuario=$resultado_registros_malla[$i][3];

                                    if (!isset($random_agente[$id_usuario]['muestra'])) {
                                        $random_agente[$id_usuario]['muestra']=[];
                                    }

                                    if (count($random_agente[$id_usuario]['muestra'])>0) {
                                        for ($j=0; $j < count($random_agente[$id_usuario]['muestra']); $j++) {
                                            $control_registros++;

                                            $cmm_usuario=$id_usuario;
                                            $cmm_monitor=$random_agente[$id_usuario]['lider'][$j];
                                            $cmm_muestra_auditoria=$random_agente[$id_usuario]['muestra'][$j];

                                            if ($sentencia_insert_muestras->execute()) {
                                                $control_insert++;
                                            } else {
                                                echo "INSERT INTO `tb_gestion_calidad_cmuestral_muestras`(`cmm_calculadora`, `cmm_mes`, `cmm_segmento`, `cmm_usuario`, `cmm_monitor`, `cmm_muestra_auditoria`) VALUES ('".$id_registro."','".$fecha_calculadora."','".$id_segmento."','".$cmm_usuario."','".$cmm_monitor."','".$cmm_muestra_auditoria."')<br>";
                                            }
                                        }
                                    }
                                }

                                if ($control_insert==$control_registros) {
                                    $respuesta_accion = "<script type='text/javascript'>alertify.success('Random generado exitosamente!', 0);</script>";
                                    $_SESSION['registro_creado_random']=1;
                                } else {
                                    $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Problemas al generar random, por favor verifique e intente nuevamente!', 0);</script>";
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $respuesta_accion = "<script type='text/javascript'>alertify.success('¡Random generado exitosamente, haga clic en <b>Finalizar</b> para salir!', 0);</script>";
        }
    }
}

/* =========================
   CONSULTA MALLA (para mostrar tabla)
   ========================= */
$consulta_string_malla="SELECT `ccmm_id`, `ccmm_mes`, `ccmm_segmento`, `ccmm_usuario`, `ccmm_usuario_segmento`, `ccmm_coordinador`,
    `ccmm_lunes`, `ccmm_martes`, `ccmm_miercoles`, `ccmm_jueves`, `ccmm_viernes`, `ccmm_sabado`, `ccmm_domingo`,
    `ccmm_monitoreo_lunes`, `ccmm_monitoreo_martes`, `ccmm_monitoreo_miercoles`, `ccmm_monitoreo_jueves`, `ccmm_monitoreo_viernes`,
    `ccmm_monitoreo_sabado`, `ccmm_monitoreo_domingo`, `ccmm_actualiza_usuario`, `ccmm_actualiza_fecha`, `ccmm_registro_usuario`, `ccmm_registro_fecha`,
    TU.`usu_nombres_apellidos`, TC.`usu_nombres_apellidos`, TCAM.`ac_nombre_campania`, `ccmm_monitor`, TMONITOR.`usu_nombres_apellidos`
    FROM `tb_gestion_calidad_cmuestral_malla`
    LEFT JOIN `tb_administrador_usuario` AS TU ON `tb_gestion_calidad_cmuestral_malla`.`ccmm_usuario`=TU.`usu_id`
    LEFT JOIN `tb_administrador_usuario` AS TC ON `tb_gestion_calidad_cmuestral_malla`.`ccmm_coordinador`=TC.`usu_id`
    LEFT JOIN `tb_administrador_campania` AS TCAM ON `tb_gestion_calidad_cmuestral_malla`.`ccmm_usuario_segmento`=TCAM.`ac_id`
    LEFT JOIN `tb_administrador_usuario` AS TMONITOR ON `tb_gestion_calidad_cmuestral_malla`.`ccmm_monitor`=TMONITOR.`usu_id`
    WHERE `ccmm_mes`=? AND `ccmm_segmento`=? ORDER BY TU.`usu_nombres_apellidos` ASC";

$consulta_registros_malla = $enlace_db->prepare($consulta_string_malla);
$consulta_registros_malla->bind_param("ss", $fecha_calculadora, $id_segmento);
$consulta_registros_malla->execute();
$resultado_registros_malla = $consulta_registros_malla->get_result()->fetch_all(MYSQLI_NUM);

for ($i=0; $i < count($resultado_registros_malla); $i++) {
    $turno_seg_usuario[$resultado_registros_malla[$i][2]][]=$resultado_registros_malla[$i][3];
    $turno_mostrar_datos[$resultado_registros_malla[$i][3]]['id']=$resultado_registros_malla[$i][0];
    $turno_mostrar_datos[$resultado_registros_malla[$i][3]]['nombre']=$resultado_registros_malla[$i][24];
    $turno_mostrar_datos[$resultado_registros_malla[$i][3]]['coordinador']=$resultado_registros_malla[$i][25];
    $turno_mostrar_datos[$resultado_registros_malla[$i][3]]['monitor']=$resultado_registros_malla[$i][28];
    $turno_mostrar_datos[$resultado_registros_malla[$i][3]]['segmento']=$resultado_registros_malla[$i][26];
    $turno_mostrar_datos[$resultado_registros_malla[$i][3]]['muestra']=$resultado_registros_malla[$i][13].$resultado_registros_malla[$i][14].$resultado_registros_malla[$i][15].$resultado_registros_malla[$i][16].$resultado_registros_malla[$i][17].$resultado_registros_malla[$i][18].$resultado_registros_malla[$i][19];

    // Evita warnings si convención no existe
    $c = $resultado_registros_malla[$i][6] ?? '';
    if (isset($array_convenciones[$c]) && $array_convenciones[$c]!="") {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][0]['turno']=$array_convenciones[$c];
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][0]['color']=$array_convenciones_color[$c] ?? '';
    } else {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][0]['turno']=$c;
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][0]['color']='';
    }

    $c = $resultado_registros_malla[$i][7] ?? '';
    if (isset($array_convenciones[$c]) && $array_convenciones[$c]!="") {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][1]['turno']=$array_convenciones[$c];
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][1]['color']=$array_convenciones_color[$c] ?? '';
    } else {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][1]['turno']=$c;
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][1]['color']='';
    }

    $c = $resultado_registros_malla[$i][8] ?? '';
    if (isset($array_convenciones[$c]) && $array_convenciones[$c]!="") {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][2]['turno']=$array_convenciones[$c];
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][2]['color']=$array_convenciones_color[$c] ?? '';
    } else {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][2]['turno']=$c;
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][2]['color']='';
    }

    $c = $resultado_registros_malla[$i][9] ?? '';
    if (isset($array_convenciones[$c]) && $array_convenciones[$c]!="") {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][3]['turno']=$array_convenciones[$c];
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][3]['color']=$array_convenciones_color[$c] ?? '';
    } else {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][3]['turno']=$c;
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][3]['color']='';
    }

    $c = $resultado_registros_malla[$i][10] ?? '';
    if (isset($array_convenciones[$c]) && $array_convenciones[$c]!="") {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][4]['turno']=$array_convenciones[$c];
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][4]['color']=$array_convenciones_color[$c] ?? '';
    } else {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][4]['turno']=$c;
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][4]['color']='';
    }

    $c = $resultado_registros_malla[$i][11] ?? '';
    if (isset($array_convenciones[$c]) && $array_convenciones[$c]!="") {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][5]['turno']=$array_convenciones[$c];
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][5]['color']=$array_convenciones_color[$c] ?? '';
    } else {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][5]['turno']=$c;
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][5]['color']='';
    }

    $c = $resultado_registros_malla[$i][12] ?? '';
    if (isset($array_convenciones[$c]) && $array_convenciones[$c]!="") {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][6]['turno']=$array_convenciones[$c];
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][6]['color']=$array_convenciones_color[$c] ?? '';
    } else {
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][6]['turno']=$c;
        $turno_mostrar[$resultado_registros_malla[$i][2]][$resultado_registros_malla[$i][3]][6]['color']='';
    }
}

/* =========================
   CONSULTA MUESTRA (para icono ojo)
   ========================= */
$consulta_string_muestra="SELECT `cmm_id`, `cmm_calculadora`, `cmm_mes`, `cmm_segmento`, `cmm_usuario`, `cmm_monitor`,
    `cmm_muestra_auditoria`, TU.`usu_nombres_apellidos`
    FROM `tb_gestion_calidad_cmuestral_muestras`
    LEFT JOIN `tb_administrador_usuario` AS TU ON `tb_gestion_calidad_cmuestral_muestras`.`cmm_monitor`=TU.`usu_id`
    WHERE `cmm_mes`=? AND `cmm_segmento`=?";

$consulta_registros_muestra = $enlace_db->prepare($consulta_string_muestra);
$consulta_registros_muestra->bind_param("ss", $fecha_calculadora, $id_segmento);
$consulta_registros_muestra->execute();
$resultado_registros_muestra = $consulta_registros_muestra->get_result()->fetch_all(MYSQLI_NUM);

for ($i=0; $i < count($resultado_registros_muestra); $i++) {
    $array_muestra[$resultado_registros_muestra[$i][4]][]=$resultado_registros_muestra[$i][6].' | '.$resultado_registros_muestra[$i][7];
}

/*Enlace para botón finalizar y cancelar*/
$ruta_cancelar_finalizar="gestion_cmuestral_configurar.php?reg=".base64_encode($id_registro)."&date=".$fecha_calculadora;
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include("../config/configuracion_estilos.php"); ?>
</head>
<body onresize="tabla_fixed();" onload="tabla_fixed();">
<?php
include("../menu_principal.php");
include("../menu_header.php");
?>
<div class="contenido">
    <div class="row" id="elemento_1">
        <div class="col-md-6 py-2">
            <form name="filtrado" action="" method="POST">
                <div class="input-group">
                    <input type="text" name="id_filtro" value='<?php echo $fecha_calculadora; ?>' class="form-control" readonly>
                    <input type="text" name="id_filtro" value='<?php echo $nombre_segmento; ?>' class="form-control" readonly>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($respuesta_accion)) {echo $respuesta_accion;} ?>

    <form name="guardar_registro" action="" method="POST" enctype="multipart/form-data">
        <div class="row" id="tabla_fixed">
            <div class="col-md-12 pt-2">
                <?php if (!empty($turno_seg_usuario[$id_segmento]) && count($turno_seg_usuario[$id_segmento])>0): ?>
                    <div id="table-fixed" class="table-responsive table-fixed">
                        <table class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                            <tr>
                                <th>Doc. Identidad</th>
                                <th>Nombres y Apellidos</th>
                                <th>Segmento</th>
                                <th>Coordinador</th>
                                <th>Lunes</th>
                                <th>Martes</th>
                                <th>Miércoles</th>
                                <th>Jueves</th>
                                <th>Viernes</th>
                                <th>Sábado</th>
                                <th>Domingo</th>
                                <th>Muestra Aleatoria</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            for ($j=0; $j < count($turno_seg_usuario[$id_segmento]); $j++) {
                                $id_usuario_item=$turno_seg_usuario[$id_segmento][$j];
                                ?>
                                <tr>
                                    <td class="align-middle"><?php echo $id_usuario_item; ?></td>
                                    <td class="align-middle"><?php echo $turno_mostrar_datos[$id_usuario_item]['nombre'] ?? ''; ?></td>
                                    <td class="align-middle"><?php echo $turno_mostrar_datos[$id_usuario_item]['segmento'] ?? ''; ?></td>
                                    <td class="align-middle"><?php echo $turno_mostrar_datos[$id_usuario_item]['coordinador'] ?? ''; ?></td>

                                    <?php for ($d=0; $d<=6; $d++): ?>
                                        <?php
                                            $color = $turno_mostrar[$id_segmento][$id_usuario_item][$d]['color'] ?? '';
                                            $turno = $turno_mostrar[$id_segmento][$id_usuario_item][$d]['turno'] ?? '';
                                        ?>
                                        <td class="align-middle text-center p-0" style="background-color: <?php echo $color; ?>; <?php echo ($color!="") ? 'color: #FFF;' : ''; ?>">
                                            <?php echo $turno; ?>
                                        </td>
                                    <?php endfor; ?>

                                    <td class="align-middle text-center py-0">
                                        <?php if(isset($array_muestra[$id_usuario_item])): ?>
                                            <a href="#" onClick="open_modal_detalle('<?php echo base64_encode($turno_mostrar_datos[$id_usuario_item]['id']); ?>');" class="btn btn-secondary btn-sm btn-width" title="Detalle Muestra"><span class="fas fa-eye"></span></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="alert alert-warning">
                        <span class="fas fa-exclamation-triangle"></span> No se encontraron registros
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12 pt-2">
                <div class="form-group">
                    <?php if($_SESSION['registro_creado_random']==1): ?>
                        <a href="<?php echo $ruta_cancelar_finalizar; ?>" class="btn btn-dark float-right">Finalizar</a>
                    <?php else: ?>
                        <button class="btn btn-success float-right ml-1" type="submit" name="guardar_registro">Generar Aleatorio</button>
                        <button class="btn btn-danger float-right" type="button" onclick="guardar_cancelar();">Cancelar</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <!-- Modal DETALLE -->
    <div class="modal fade" id="modal_ver_detalle" role="dialog">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Detalle muestra aleatoria</h4>
                    <button type="button" class="close" data-dismiss="modal" onClick="close_modal_detalle();">×</button>
                </div>
                <div class="modal-body modal-body-detalle p-1"></div>
                <div class="modal-footer">
                    <a href="#" onClick="close_modal_detalle();" class="btn btn-corp menu float-right" data-dismiss="modal">
                        <div class="float-left"><span class="fas fa-times"></span></div>
                        <div class="pl-2 menu_res float-left">Cerrar</div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include("../footer.php");
include("../config/configuracion_js.php");
?>

<script type="text/javascript">
    $(document).ready(function(){
        $("#modal_ver_detalle").on('hidden.bs.modal', function () {
            close_modal_detalle();
        });
    });

    function open_modal_detalle(id_registro) {
        $('.modal-body-detalle').load('gestion_cmuestral_configurar_malla_editar_calcular_ver.php?reg='+id_registro,function(){
            $('#modal_ver_detalle').modal({show:true});
        });
    }

    function close_modal_detalle() {
        $('.modal-body-detalle').html('');
    }

    function tabla_fixed(){
        var tabla_fixed = document.getElementById("tabla_fixed");
        var elemento = document.getElementById("table-fixed");
        var elemento_1 = document.getElementById("elemento_1");
        alto_ventana=window.outerHeight-elemento_1.clientHeight-100;
        alto_tabla=tabla_fixed.clientHeight+200;

        if (alto_tabla>alto_ventana) {
            alto_elemento=alto_ventana-180;
            elemento.style.height=alto_elemento+"px";
        }
    }
</script>
</body>
</html>