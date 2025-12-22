<?php
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    require_once("/var/www/html/config/conexion_db.php");

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
    use PHPMailer\PHPMailer\SMTP;

    require '/var/www/html/PHPMailer-master/src/Exception.php';
    require '/var/www/html/PHPMailer-master/src/PHPMailer.php';
    require '/var/www/html/PHPMailer-master/src/SMTP.php';

    //consulta de notificaciones pendientes de enviar
    $consulta_notificaciones = mysqli_query($enlace_db, "SELECT `gan_id`, `gan_consecutivo`, `gan_tipo`, `gan_id_set_from`, `gan_address`, `gan_cc`, `gan_bcc`, `gan_subject`, `gan_body`, `gan_embeddedimage_ruta`, `gan_embeddedimage_nombre`, `gan_embeddedimage_tipo`, `gan_intentos`, `gan_eliminar`, `gan_estado_envio`, `gan_fecha_envio`, `gan_fecha_registro`, `gan_usuario_registro`, TN.`ncr_host`, TN.`ncr_port`, TN.`ncr_smtpsecure`, TN.`ncr_smtpauth`, TN.`ncr_username`, TN.`ncr_password`, TN.`ncr_setfrom`, TN.`ncr_setfrom_name`, `gan_adjunto_nombre`, `gan_adjunto_ruta` FROM `tb_gestion_alertas_notificacion` LEFT JOIN `tb_notificaciones_central_remitente` AS TN ON `tb_gestion_alertas_notificacion`.`gan_id_set_from`=TN.`ncr_id` WHERE `gan_estado_envio`='Pendiente' LIMIT 5 OFFSET 0");
    $resultado_notificaciones = mysqli_fetch_all($consulta_notificaciones);

    if (count($resultado_notificaciones)>0) {
        for ($i=0; $i < count($resultado_notificaciones); $i++) { 
            $marca_temporal = date("Y-m-d H:i:s");
            $id_correo=$resultado_notificaciones[$i][0];
            if ($resultado_notificaciones[$i][3]!="" AND $resultado_notificaciones[$i][7]!="" AND $resultado_notificaciones[$i][8]!="") {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $resultado_notificaciones[$i][18];
                $mail->Port = $resultado_notificaciones[$i][19];
                // $mail->SMTPDebug = 2;
                $mail->SMTPSecure = $resultado_notificaciones[$i][20];
                $mail->SMTPAuth = $resultado_notificaciones[$i][21];
                $mail->Username = $resultado_notificaciones[$i][22];
                $mail->Password = $resultado_notificaciones[$i][23];
                $mail->SetFrom($resultado_notificaciones[$i][24], $resultado_notificaciones[$i][25]);
                
                $num_intentos=intval($resultado_notificaciones[$i][12])+1;

                if ($num_intentos>=2) {
                    $estado_error="Error";
                } else {
                    $estado_error="Pendiente";
                }

                $destino_to=explode(";", $resultado_notificaciones[$i][4]);
                for ($j=0; $j < count($destino_to); $j++) { 
                    unset($destino_to_part);
                    $destino_to_part=explode("|", $destino_to[$j]);
                    if ($destino_to_part[0]!="" AND $destino_to_part[1]!="") {
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
                $image_embedded_ruta=explode(";", $resultado_notificaciones[$i][9]);
                $image_embedded_nombre=explode(";", $resultado_notificaciones[$i][10]);
                $image_embedded_tipo=explode(";", $resultado_notificaciones[$i][11]);
                for ($j=0; $j < count($image_embedded_ruta); $j++) { 
                    if ($image_embedded_ruta[$j]!="" AND $image_embedded_nombre[$j]!="" AND $image_embedded_tipo[$j]!="") {
                        $mail->AddEmbeddedImage($image_embedded_ruta[$j], $image_embedded_nombre[$j], $image_embedded_ruta[$j], 'base64', $image_embedded_tipo[$j]);
                    }
                }

                //adjuntos
                $adjuntos_ruta=explode(";", $resultado_notificaciones[$i][27]);
                $adjuntos_nombre=explode(";", $resultado_notificaciones[$i][26]);
                for ($j=0; $j < count($adjuntos_ruta); $j++) { 
                    if ($adjuntos_ruta[$j]!="" AND $adjuntos_nombre[$j]!="" AND file_exists($adjuntos_ruta[$j])) {
                        $mail->AddAttachment($adjuntos_ruta[$j], $adjuntos_nombre[$j]);
                    }
                }
                
                $mail->IsHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = $resultado_notificaciones[$i][7];
                $mail->Body    = $resultado_notificaciones[$i][8];
                
                if($mail->send()) {
                    $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_gestion_alertas_notificacion` SET `gan_estado_envio`='Enviado', `gan_fecha_envio`='".$marca_temporal."', `gan_intentos`='".$num_intentos."' WHERE `gan_id`='".$id_correo."'");
                } else {
                    $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_gestion_alertas_notificacion` SET `gan_estado_envio`='".$estado_error."', `gan_fecha_envio`='".$marca_temporal."', `gan_intentos`='".$num_intentos."' WHERE `gan_id`='".$id_correo."'");
                }
                // echo $mail->ErrorInfo;
            } else {
                $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_gestion_alertas_notificacion` SET `gan_estado_envio`='Error-estructura', `gan_fecha_envio`='".$marca_temporal."', `gan_intentos`='1' WHERE `gan_id`='".$id_correo."'");
            }
        }
    }
?>