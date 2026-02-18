<?php
//Validación de permisos del usuario para el módulo
$modulo_plataforma="Auditoría-Monitoreos";

require_once("../config/validaciones_seguridad.php");
require_once("../config/conexion_db.php");

/* =========================
   Helpers mínimos (no cambian lógica)
   ========================= */
if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* =========================
   Inicializaciones sesión usadas
   ========================= */
if (!isset($_SESSION['monitoreo_creado_auditoria'])) { $_SESSION['monitoreo_creado_auditoria'] = 0; }
if (!isset($_SESSION['id_monitoreo'])) { $_SESSION['id_monitoreo'] = ''; }

$titulo_header = "Monitoreos | Guardar";
$pagina = validar_input($_GET['pagina'] ?? '');
$filtro_permanente = validar_input($_GET['id'] ?? '');
$id_monitoreo = validar_input(base64_decode($_GET['mon'] ?? ''));

$consulta_string_info="SELECT
    TMC.`gcm_id`, TM.`gcm_nombre_matriz`, TMC.`gcm_fecha_hora_gestion`, TMC.`gcm_fecha_hora_cierre`,
    TMC.`gcm_tipo_monitoreo`, TMC.`gcm_skill_interaccion`, TMC.`gcm_tipo_gestion`, TMC.`gcm_segmento`,
    TMC.`gcm_id_sim`, TMC.`gcm_id_ani`, TMC.`gcm_observaciones_monitoreo`, TMC.`gcm_nota_enc`,
    TMC.`gcm_nota_ecn`, TMC.`gcm_nota_ecuf`, TMC.`gcm_estado`, TUR.`usu_nombres_apellidos`,
    TMC.`gcm_registro_fecha`, `gcm_nota_general`, `gcm_nota_enc_estado`, `gcm_nota_ecn_estado`,
    `gcm_nota_ecuf_estado`, `gcm_aplica_indicador`, `gcm_fecha_monitoreo`, TMC.`gcm_registro_usuario`,
    TM.`gcm_observaciones`, `gcm_auditoria`, `gcm_auditoria_responsable`, TMC.`gcm_matriz`
FROM `tb_gestion_calidad_monitoreo` AS TMC
LEFT JOIN `tb_gestion_calidad_matriz` AS TM ON TMC.`gcm_matriz`=TM.`gcm_id`
LEFT JOIN `tb_administrador_usuario` AS TUR ON TMC.`gcm_auditoria_responsable`=TUR.`usu_id`
WHERE 1=1 AND `gcm_auditoria`='Si' AND TMC.`gcm_id`=?";

$consulta_registros_info = $enlace_db->prepare($consulta_string_info);
$consulta_registros_info->bind_param("s", $id_monitoreo);
$consulta_registros_info->execute();
$resultado_registros_info = $consulta_registros_info->get_result()->fetch_all(MYSQLI_NUM);

if (count($resultado_registros_info) <= 0) {
    $ruta_cancelar_finalizar="gestion_calidad_monitoreo.php?pagina=".$pagina."&id=".$filtro_permanente."&bandeja=".base64_encode('Mes Actual');
    ?>
    <!DOCTYPE html>
    <html lang="ES">
    <head><?php include("../config/configuracion_estilos.php"); ?></head>
    <body>
    <?php include("../menu_principal.php"); include("../menu_header.php"); ?>
    <div class="contenido">
        <p class="alert alert-warning p-2">
            <span class="fas fa-exclamation-triangle"></span>
            No se encontró información del monitoreo para guardar auditoría.
        </p>
        <a href="<?php echo h($ruta_cancelar_finalizar); ?>" class="btn btn-dark">Volver</a>
    </div>
    <?php include("../footer.php"); include("../config/configuracion_js.php"); ?>
    </body>
    </html>
    <?php
    exit;
}

$respuesta_accion = '';

if(isset($_POST["guardar_monitoreo"])){

    $gcm_matriz = $resultado_registros_info[0][27];
    $gcm_analista = '';
    $gcm_responsable = '';
    $gcm_fecha_hora_gestion = $resultado_registros_info[0][2];
    $gcm_fecha_monitoreo = date('Y-m-d');
    $gcm_tipo_monitoreo = $resultado_registros_info[0][4];
    $gcm_skill_interaccion = $resultado_registros_info[0][5];
    $gcm_tipo_gestion = $resultado_registros_info[0][6];
    $gcm_segmento = $resultado_registros_info[0][7];
    $gcm_id_sim = $resultado_registros_info[0][8];
    $gcm_id_ani = $resultado_registros_info[0][9];
    $gcm_afectacion_1 = '';
    $gcm_afectacion_2 = '';

    $gcm_observaciones_monitoreo = validar_input($_POST['observaciones'] ?? '');

    // ======= arrays POST (evita undefined index) =======
    $items_matriz = $_POST['id_campos'] ?? [];
    $grupo_peso   = $_POST['grupo_peso'] ?? [];
    $peso_nota    = $_POST['peso_nota'] ?? [];
    $tipo_error   = $_POST['tipo_error'] ?? [];

    // SLA: si algún hidden no llega, default 0 (no rompe lógica de comparación)
    $peso_sla_ecuf = (float)($_POST['peso_sla_ecuf'] ?? 0);
    $peso_sla_enc  = (float)($_POST['peso_sla_enc'] ?? 0);
    $peso_sla_ecn  = (float)($_POST['peso_sla_ecn'] ?? 0);

    // Inicializaciones para evitar warnings
    $item_respuesta = [];
    $item_comentario = [];
    $item_id_respuesta = [];
    $item_calificable_tipo_error = [];
    $grupos_items_nota = [];
    $grupos_peso_id = [];

    $grupos_tipo_error = array_values(array_unique($tipo_error));

    for ($i=0; $i < count($items_matriz); $i++) {

        $gp = $grupo_peso[$i] ?? '';
        $pn = $peso_nota[$i] ?? 0;
        $te = $tipo_error[$i] ?? '';

        if ($gp !== "") {
            $grupos_items_nota['G-'.$gp] = 100;
            $grupos_peso_id[] = 'G-'.$gp;
        }

        if (isset($_POST['respuesta_'.$items_matriz[$i]])) {
            $item_respuesta[] = $_POST['respuesta_'.$items_matriz[$i]];
            $item_id_respuesta[$items_matriz[$i]] = $_POST['respuesta_'.$items_matriz[$i]];
        } else {
            $item_respuesta[] = "";
            $item_id_respuesta[$items_matriz[$i]] = "";
        }

        if (isset($_POST['comentario_'.$items_matriz[$i]])) {
            $item_comentario[] = $_POST['comentario_'.$items_matriz[$i]];
        } else {
            $item_comentario[] = "";
        }
    }

    $grupos_peso_id = array_values(array_unique($grupos_peso_id));

    for ($i=0; $i < count($items_matriz); $i++) {

        $gp = $grupo_peso[$i] ?? '';
        $pn = $peso_nota[$i] ?? 0;
        $te = $tipo_error[$i] ?? '';

        if ($gp === "") {
            if (($item_respuesta[$i] ?? '') === "No") {
                $item_calificable_tipo_error[$te][$items_matriz[$i]] = 0;
            } else {
                $item_calificable_tipo_error[$te][$items_matriz[$i]] = $pn;
            }
        } else {
            $item_calificable_tipo_error[$te]['G-'.$gp] = $pn;
        }

        if ($gp !== "" && ($item_respuesta[$i] ?? '') === "No") {
            $grupos_items_nota['G-'.$gp] = 0;
        }
    }

    for ($i=0; $i < count($grupos_peso_id); $i++) {
        for ($j=0; $j < count($tipo_error); $j++) {
            $te = $tipo_error[$j] ?? '';
            if (isset($item_calificable_tipo_error[$te][$grupos_peso_id[$i]])) {
                if (($grupos_items_nota[$grupos_peso_id[$i]] ?? 100) == 0) {
                    $item_calificable_tipo_error[$te][$grupos_peso_id[$i]] = 0;
                }
            }
        }
    }

    if (isset($item_calificable_tipo_error['ENC'])) {
        if (count($item_calificable_tipo_error['ENC'])>0) {
            $gcm_nota_enc=array_sum($item_calificable_tipo_error['ENC']);
        } else {
            $gcm_nota_enc="NA";
        }
    } else { $gcm_nota_enc="NA"; }

    if (isset($item_calificable_tipo_error['ECU'])) {
        if (count($item_calificable_tipo_error['ECU'])>0) {
            $gcm_nota_ecuf=array_sum($item_calificable_tipo_error['ECU']);
        } else {
            $gcm_nota_ecuf="NA";
        }
    } else { $gcm_nota_ecuf="NA"; }

    if (isset($item_calificable_tipo_error['ECN'])) {
        if (count($item_calificable_tipo_error['ECN'])>0) {
            $gcm_nota_ecn=array_sum($item_calificable_tipo_error['ECN']);
        } else {
            $gcm_nota_ecn="NA";
        }
    } else { $gcm_nota_ecn="NA"; }

    $nota_general=0;

    if ($gcm_nota_enc==="NA") {
        $control_estado_enc=1;
    } else {
        $nota_general+=$gcm_nota_enc;
        $control_estado_enc = ($gcm_nota_enc >= $peso_sla_enc) ? 1 : 0;
    }

    if ($gcm_nota_ecuf==="NA") {
        $control_estado_ecuf=1;
    } else {
        $nota_general+=$gcm_nota_ecuf;
        $control_estado_ecuf = ($gcm_nota_ecuf >= $peso_sla_ecuf) ? 1 : 0;
    }

    // Nota: conservamos tu asignación original (aunque parezca raro):
    $gcm_fecha_hora_cierre=$control_estado_ecuf;

    if ($gcm_nota_ecn==="NA") {
        $control_estado_ecn=1;
    } else {
        $nota_general+=$gcm_nota_ecn;
        $control_estado_ecn = ($gcm_nota_ecn >= $peso_sla_ecn) ? 1 : 0;
    }

    if ($control_estado_enc==1 AND $control_estado_ecuf==1 AND $control_estado_ecn==1) {
        $gcm_estado="Aceptado";
    } else {
        $gcm_estado="Pendiente";
    }

    $gcm_prioridad='';
    if ($control_estado_ecuf==0) {
        $gcm_prioridad='Alta';
    } elseif ($control_estado_ecn==0) {
        $gcm_prioridad='Baja';
    }

    if($_SESSION['monitoreo_creado_auditoria']!=1){

        $inser_consecutivo=$resultado_registros_info[0][0];

        $sentencia_insert = $enlace_db->prepare("
            INSERT INTO `tb_gestion_auditoria_monitoreo`
            (`gcm_id`, `gcm_matriz`, `gcm_analista`, `gcm_responsable`, `gcm_fecha_hora_gestion`, `gcm_fecha_hora_cierre`,
             `gcm_tipo_monitoreo`, `gcm_skill_interaccion`, `gcm_tipo_gestion`, `gcm_segmento`, `gcm_id_sim`, `gcm_id_ani`,
             `gcm_observaciones_monitoreo`, `gcm_nota_enc`, `gcm_nota_ecn`, `gcm_nota_ecuf`, `gcm_nota_general`,
             `gcm_nota_enc_estado`, `gcm_nota_ecn_estado`, `gcm_nota_ecuf_estado`, `gcm_estado`, `gcm_afectacion_1`,
             `gcm_afectacion_2`, `gcm_fecha_monitoreo`, `gcm_registro_usuario`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $sentencia_insert->bind_param(
            'sssssssssssssssssssssssss',
            $inser_consecutivo, $gcm_matriz, $gcm_analista, $gcm_responsable, $gcm_fecha_hora_gestion, $gcm_fecha_hora_cierre,
            $gcm_tipo_monitoreo, $gcm_skill_interaccion, $gcm_tipo_gestion, $gcm_segmento, $gcm_id_sim, $gcm_id_ani,
            $gcm_observaciones_monitoreo, $gcm_nota_enc, $gcm_nota_ecn, $gcm_nota_ecuf, $nota_general,
            $control_estado_enc, $control_estado_ecn, $control_estado_ecuf, $gcm_estado,
            $gcm_afectacion_1, $gcm_afectacion_2, $gcm_fecha_monitoreo, $_SESSION['usu_id']
        );

        if ($sentencia_insert->execute()) {

            $_SESSION['id_monitoreo']=$inser_consecutivo;
            $control_insert=0;

            $sentencia_insert_calificaciones = $enlace_db->prepare("
                INSERT INTO `tb_gestion_auditoria_monitoreo_calificaciones`
                (`gcmc_monitoreo`, `gcmc_pregunta`, `gcmc_respuesta`, `gcmc_afectaciones`, `gcmc_comentarios`)
                VALUES (?,?,?,?,?)
            ");

            for ($i=0; $i < count($items_matriz); $i++) {

                $item_matriz_pregunta=$items_matriz[$i];
                $afectaciones="";
                $respuesta_item=$item_respuesta[$i] ?? '';
                $comentarios_insert=$item_comentario[$i] ?? '';

                $sentencia_insert_calificaciones->bind_param(
                    'sssss',
                    $inser_consecutivo, $item_matriz_pregunta, $respuesta_item, $afectaciones, $comentarios_insert
                );

                if ($sentencia_insert_calificaciones->execute()) {
                    $control_insert++;
                }
            }

            if (count($items_matriz)==$control_insert) {

                $respuesta_accion = "<script type='text/javascript'>alertify.success('Monitoreo creado exitosamente!', 0);</script>";
                $_SESSION['monitoreo_creado_auditoria']=1;

                //insert log eventos (igual)
                $consulta_string_log = "INSERT INTO `tb_administrador_log`
                    (`clog_log_modulo`, `clog_log_tipo`, `clog_log_accion`, `clog_log_detalle`, `clog_registro_usuario`)
                    VALUES (?,?,?,?,?)";

                $log_modulo=$modulo_plataforma;
                $log_tipo="crear";
                $log_accion="Crear registro";
                $log_detalle="Monitoreo Auditoría | ".$_SESSION['id_monitoreo'];
                $log_usuario=$_SESSION["usu_id"];

                $consulta_registros_log = $enlace_db->prepare($consulta_string_log);
                $consulta_registros_log->bind_param("sssss", $log_modulo, $log_tipo, $log_accion, $log_detalle, $log_usuario);
                $consulta_registros_log->execute();
                //insert log eventos

                if ($gcm_estado=='Pendiente' AND ($control_estado_ecuf==0 OR $control_estado_ecn==0)) {

                    // PROGRAMAR NOTIFICACIÓN CORREO (igual)
                    $array_correo_destino['correos']['TO']="camilo.gomez@icbf.gov.co|camilo.gomez@icbf.gov.co;zollange.beltran@icbf.gov.co|zollange.beltran@icbf.gov.co";
                    $array_correo_destino['correos']['CC']='';

                    $nota_correo_enc  = ($control_estado_enc)  ? 'CUMPLE' : 'INCUMPLE';
                    $nota_correo_ecuf = ($control_estado_ecuf) ? 'CUMPLE' : 'INCUMPLE';
                    $nota_correo_ecn  = ($control_estado_ecn)  ? 'CUMPLE' : 'INCUMPLE';

                    $contenido_correo="<p style='font-size: 12px; color: #2E2E2E; font-family: Lato, Arial, sans-serif;'>Cordial Saludo,<br><br>Se ha generado auditoría al monitoreo ".$_SESSION['id_monitoreo'].", con los siguientes resultados. Por favor verificar el detalle ingresando al siguiente link: <a href='http://20.172.253.76/' target='_blank'>ICBF-IQGIS</a></p><br>
                    <center>
                    <table style='width: 500px; font-size: 13px; font-family: Lato, Arial, sans-serif;'>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Id Monitoreo</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $inser_consecutivo ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Fecha gestión/grabación</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $gcm_fecha_hora_gestion ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Tipo monitoreo</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $gcm_tipo_monitoreo ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Skill interacción</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $gcm_skill_interaccion ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Tipo gestión</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $gcm_tipo_gestion ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Segmento</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $gcm_segmento ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Id SIM</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $gcm_id_sim ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Id/ANI</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $gcm_id_ani ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Nota ENC</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $nota_correo_enc ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Nota ECUF</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $nota_correo_ecuf ."</td></tr>
                        <tr><td style='width: 30%;background-color: #069169; color: #FFFFFF; padding: 5px; text-align: center;'>Nota ECN</td>
                            <td style='width: 70%;padding: 5px;background-color: #F2F2F2;'>". $nota_correo_ecn ."</td></tr>
                    </table>
                    <br>
                    <table style='width: 100%; background: #2E2E2E; font-family: Lato, Arial, sans-serif;'>
                        <tr><td style='font-size: 12px; padding: 5px 10px; color: #FFFFFF'>
                            IQ-ICBF | Gestión Integrada de Servicios | &copy; Copyright 2020 Todos los derechos reservados.
                        </td></tr>
                    </table>
                    <p style='font-family: Lato, Arial, sans-serif; color: #2E2E2E;'>
                    Esta es una notificación automática, por favor no responda este mensaje.
                    </p>
                    <table>
                        <tr>
                            <td style='width: 50px; height: 73px;'><img src='cid:logo_firma_verde' style='margin: 5px; width: 50px; height: 73px;'></td>
                            <td><p style='font-size: 11px; color: #006400; font-family: Lato, Arial, sans-serif;'><br><b>No imprima este mensaje de no ser necesario; de ésta manera aportamos al cuidado del planeta.</b></p></td>
                        </tr>
                    </table>
                    </center>";

                    $nc_id_modulo="19";
                    $nc_prioridad="Alta";
                    $nc_id_set_from="1";
                    $nc_address=$array_correo_destino['correos']['TO'];
                    $nc_cc=$array_correo_destino['correos']['CC'];
                    $nc_bcc="";
                    $nc_reply_to="";
                    $nc_subject="Monitoreo Auditoría | ".$inser_consecutivo;
                    $nc_body=str_replace("'", '"', $contenido_correo);
                    $nc_embeddedimage_ruta="/var/www/html/images/firma-verde.png";
                    $nc_embeddedimage_nombre="logo_firma_verde";
                    $nc_embeddedimage_tipo="image/png";
                    $nc_intentos="";
                    $nc_eliminar="Si";
                    $nc_estado_envio="Pendiente";
                    $nc_fecha_envio="";
                    $nc_usuario_registro=$_SESSION['usu_id'];

                    // Conserva tu lógica de reintento 5 veces
                    for ($i=0; $i < 5; $i++) {

                        // MISMA TABLA/VALORES, pero con prepared para evitar fallos por comillas
                        $stmtNc = $enlace_db->prepare("
                            INSERT INTO `tb_notificaciones_central`
                            (`nc_id_modulo`, `nc_prioridad`, `nc_id_set_from`, `nc_address`, `nc_cc`, `nc_bcc`, `nc_reply_to`,
                             `nc_subject`, `nc_body`, `nc_embeddedimage_ruta`, `nc_embeddedimage_nombre`, `nc_embeddedimage_tipo`,
                             `nc_intentos`, `nc_eliminar`, `nc_estado_envio`, `nc_fecha_envio`, `nc_usuario_registro`)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ");

                        if ($stmtNc) {
                            $stmtNc->bind_param(
                                "sssssssssssssssss",
                                $nc_id_modulo, $nc_prioridad, $nc_id_set_from, $nc_address, $nc_cc, $nc_bcc, $nc_reply_to,
                                $nc_subject, $nc_body, $nc_embeddedimage_ruta, $nc_embeddedimage_nombre, $nc_embeddedimage_tipo,
                                $nc_intentos, $nc_eliminar, $nc_estado_envio, $nc_fecha_envio, $nc_usuario_registro
                            );

                            if ($stmtNc->execute()) {
                                registro_log($enlace_db, $modulo_plataforma, 'notificacion', $nc_subject);
                                break;
                            }
                        }
                    }
                }
            }

        } else {
            $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Problemas al crear el monitoreo, por favor verifique e intente nuevamente!', 0);</script>";
        }

    } else {
        $respuesta_accion = "<script type='text/javascript'>alertify.success('Monitoreo creado exitosamente, haga clic en <b>Finalizar</b> para salir!', 0);</script>";
    }
}

$consulta_string_matriz="SELECT `gcm_id`, `gcm_nombre_matriz`, `gcm_estado`, `gcm_observaciones`, `gcm_registro_usuario`, `gcm_registro_fecha`
FROM `tb_gestion_calidad_matriz` WHERE `gcm_id`=?";

$consulta_registros_matriz = $enlace_db->prepare($consulta_string_matriz);
$consulta_registros_matriz->bind_param("s", $resultado_registros_info[0][27]);
$consulta_registros_matriz->execute();
$resultado_registros_matriz = $consulta_registros_matriz->get_result()->fetch_all(MYSQLI_NUM);

/*Enlace para botón finalizar y cancelar*/
$ruta_cancelar_finalizar="gestion_calidad_monitoreo.php?pagina=".$pagina."&id=".$filtro_permanente."&bandeja=".base64_encode('Mes Actual');
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include("../config/configuracion_estilos.php"); ?>
</head>
<body>
<?php include("../menu_principal.php"); include("../menu_header.php"); ?>
<div class="contenido">
    <?php if (!empty($respuesta_accion)) { echo $respuesta_accion; } ?>
    <div class="row justify-content-center">
        <div class="col-md-8 pt-2 background-blanco">
            <div class="row">
                <div class="col-md-12">
                    <p class="alert alert-success p-1">¡Se ha generado la auditoría del monitoreo <?php echo h($_SESSION['id_monitoreo']); ?>!</p>
                    <div class="form-group">
                        <label for="matriz" class="m-0">Matriz</label>
                        <input type="text" class="form-control form-control-sm" name="matriz" id="matriz" maxlength="50"
                               value="<?php echo h(($resultado_registros_matriz[0][1] ?? '').' ['.($resultado_registros_matriz[0][3] ?? '').']'); ?>"
                               readonly>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="form-group">
                        <?php if($_SESSION['monitoreo_creado_auditoria']==1): ?>
                            <a href="<?php echo h($ruta_cancelar_finalizar); ?>" class="btn btn-dark float-right">Finalizar</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include("../footer.php"); include("../config/configuracion_js.php"); ?>
</body>
</html>
