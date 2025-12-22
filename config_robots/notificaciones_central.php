<?php
    require_once("/var/www/html/config/conexion_db.php");

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
    use PHPMailer\PHPMailer\SMTP;

    require '/var/www/html/PHPMailer-master/src/Exception.php';
    require '/var/www/html/PHPMailer-master/src/PHPMailer.php';
    require '/var/www/html/PHPMailer-master/src/SMTP.php';
// error_reporting(E_ALL);
// ini_set('display_errors', '1');
    //consulta de notificaciones pendientes de enviar
    $consulta_notificaciones = mysqli_query($enlace_db, "SELECT `nc_id`, `nc_id_modulo`, `nc_prioridad`, `nc_id_set_from`, `nc_address`, `nc_cc`, `nc_bcc`, `nc_reply_to`, `nc_subject`, `nc_body`, `nc_embeddedimage_ruta`, `nc_intentos`, `nc_eliminar`, `nc_estado_envio`, `ncr_host`, `ncr_port`, `ncr_smtpsecure`, `ncr_smtpauth`, `ncr_username`, `ncr_password`, `ncr_setfrom`, `ncr_setfrom_name`, `nc_embeddedimage_ruta`, `nc_embeddedimage_nombre`, `nc_embeddedimage_tipo` FROM `tb_notificaciones_central` LEFT JOIN `tb_notificaciones_central_remitente` AS RT ON `tb_notificaciones_central`.`nc_id_set_from`=RT.`ncr_id` WHERE `nc_estado_envio`='Pendiente' ORDER BY `nc_prioridad` LIMIT 5 OFFSET 0");
    $resultado_notificaciones = mysqli_fetch_all($consulta_notificaciones);

    if (count($resultado_notificaciones)>0) {
        for ($i=0; $i < count($resultado_notificaciones); $i++) { 
            $marca_temporal = date("Y-m-d H:i:s");
            $id_correo=$resultado_notificaciones[$i][0];
            if ($resultado_notificaciones[$i][14]!="" AND $resultado_notificaciones[$i][15]!="" AND $resultado_notificaciones[$i][16]!="" AND $resultado_notificaciones[$i][17]!="" AND $resultado_notificaciones[$i][18]!="" AND $resultado_notificaciones[$i][19]!="" AND $resultado_notificaciones[$i][20]!="" AND $resultado_notificaciones[$i][21]!="" AND $resultado_notificaciones[$i][4]!="" AND $resultado_notificaciones[$i][8]!="" AND $resultado_notificaciones[$i][9]!="") {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->SMTPDebug  = 2;
                    $mail->Host = $resultado_notificaciones[$i][14];
                    $mail->Port = $resultado_notificaciones[$i][15];
                    $mail->SMTPSecure = $resultado_notificaciones[$i][16];
                    $mail->SMTPAuth = $resultado_notificaciones[$i][17];
                    $mail->Username = $resultado_notificaciones[$i][18];
                    $mail->Password = $resultado_notificaciones[$i][19];
                    $mail->SetFrom($resultado_notificaciones[$i][20], $resultado_notificaciones[$i][21]);
                    
                    $num_intentos=intval($resultado_notificaciones[$i][11])+1;

                    if ($num_intentos>=2) {
                        $estado_error="Error";
                    } else {
                        $estado_error="Pendiente";
                    }

                    $valida_destinatario=0;
                    $destino_to=explode(";", $resultado_notificaciones[$i][4]);
                    for ($j=0; $j < count($destino_to); $j++) { 
                        unset($destino_to_part);
                        $destino_to_part=explode("|", $destino_to[$j]);
                        if ($destino_to_part[0]!="" AND $destino_to_part[1]!="") {
                            $valida_destinatario=1;
                            $mail->addAddress($destino_to_part[0], $destino_to_part[1]);
                        }
                    }

                    $destino_cc=explode(";", $resultado_notificaciones[$i][5]);
                    for ($j=0; $j < count($destino_cc); $j++) { 
                        unset($destino_cc_part);
                        $destino_cc_part=explode("|", $destino_cc[$j]);
                        if ($destino_cc_part[0]!="" AND $destino_cc_part[1]!="") {
                            $mail->addCC($destino_cc_part[0], $destino_cc_part[1]);
                        }
                    }

                    $destino_bcc=explode(";", $resultado_notificaciones[$i][6]);
                    for ($j=0; $j < count($destino_bcc); $j++) { 
                        unset($destino_bcc_part);
                        $destino_bcc_part=explode("|", $destino_bcc[$j]);
                        if ($destino_bcc_part[0]!="" AND $destino_bcc_part[1]!="") {
                            $mail->addBCC($destino_bcc_part[0], $destino_bcc_part[1]);
                        }
                    }

                    //embeddedimage
                    $image_embedded_ruta=explode(";", $resultado_notificaciones[$i][22]);
                    $image_embedded_nombre=explode(";", $resultado_notificaciones[$i][23]);
                    $image_embedded_tipo=explode(";", $resultado_notificaciones[$i][24]);
                    for ($j=0; $j < count($image_embedded_ruta); $j++) { 
                        if ($image_embedded_ruta[$j]!="" AND $image_embedded_nombre[$j]!="" AND $image_embedded_tipo[$j]!="") {
                            $mail->AddEmbeddedImage($image_embedded_ruta[$j], $image_embedded_nombre[$j], $image_embedded_ruta[$j], 'base64', $image_embedded_tipo[$j]);
                        }
                    }
                    
                    $mail->IsHTML(true);
                    $mail->CharSet = 'UTF-8';
                    $mail->Subject = $resultado_notificaciones[$i][8];
                    $mail->Body    = $resultado_notificaciones[$i][9];
                    
                    if ($valida_destinatario) {
                        if($mail->send()) {
                            $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_notificaciones_central` SET `nc_estado_envio`='Enviado', `nc_fecha_envio`='".$marca_temporal."', `nc_intentos`='".$num_intentos."' WHERE `nc_id`='".$id_correo."'");
                        } else {
                            $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_notificaciones_central` SET `nc_estado_envio`='".$estado_error."', `nc_fecha_envio`='".$marca_temporal."', `nc_intentos`='".$num_intentos."' WHERE `nc_id`='".$id_correo."'");
                        }
                    } else {
                        $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_notificaciones_central` SET `nc_estado_envio`='Destinatario inválido', `nc_fecha_envio`='".$marca_temporal."', `nc_intentos`='".$num_intentos."' WHERE `nc_id`='".$id_correo."'");
                    }
                }  catch (Exception $e) {
                    $reporte_error="";
                    $estado_error_final="";
                    $reporte_error=$e->getMessage(); // error messages from anything else!
                    //Validación excepciones
                    settype($reporte_error, 'string');
                    if (stristr($reporte_error, 'Invalid address:')) {
                        $estado_error_final='Destinatario inválido';
                    } elseif ($reporte_error=='SMTP Error: Could not authenticate.') {
                        $estado_error_final='Error de autenticación';
                    }
                    echo $reporte_error;
                    if ($estado_error_final!="") {
                        $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_notificaciones_central` SET `nc_estado_envio`='".$estado_error_final."', `nc_fecha_envio`='".$marca_temporal."', `nc_intentos`='".$num_intentos."' WHERE `nc_id`='".$id_correo."'");
                    }
                }
            } else {
                $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_notificaciones_central` SET `nc_estado_envio`='Error-estructura', `nc_fecha_envio`='".$marca_temporal."', `nc_intentos`='1' WHERE `nc_id`='".$id_correo."'");
            }
        }
    }
?>