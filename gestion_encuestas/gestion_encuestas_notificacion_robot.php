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
    $consulta_notificaciones = mysqli_query($enlace_db, "SELECT `gern_id`, `gern_encuesta`, `gern_seccion`, `gern_id_set_from`, `gern_address`, `gern_cc`, `gern_bcc`, `gern_subject`, `gern_body`, `gern_embeddedimage_ruta`, `gern_embeddedimage_nombre`, `gern_embeddedimage_tipo`, `gern_intentos`, `gern_eliminar`, `gern_estado_envio`, `gern_fecha_envio`, `gern_fecha_registro`, `gern_usuario_registro`, TU.`usu_nombres_apellidos`, TN.`ncr_host`, TN.`ncr_port`, TN.`ncr_smtpsecure`, TN.`ncr_smtpauth`, TN.`ncr_username`, TN.`ncr_password`, TN.`ncr_setfrom`, TN.`ncr_setfrom_name`, TE.`ger_encuesta_id`, TE.`ger_radicado`, TR.`gera_matriz_id`, TR.`gera_fecha_peticion`, TR.`gera_peticionario_id`, TR.`gera_peticionario_nombre`, TR.`gera_tel_fijo`, TR.`gera_tel_movil`, TR.`gera_genero`, TR.`gera_edad`, TR.`gera_motivo`, TR.`gera_regional`, TR.`gera_centro_zonal`, TR.`gera_auxiliar1`, TR.`gera_auxiliar2`, TR.`gera_auxiliar3`, TR.`gera_estado_gestion`, TR.`gera_intentos`, TR.`gera_contactado`, TR.`gera_efectivo`, TR.`gera_ultima_observacion`, TR.`gera_fecha_actualizacion`, TR.`gera_usuario_gestion`, TR.`gera_registro_fecha`, TM.`gem_nombre_encuesta`, TREG.`gere_regional`, TCZ.`gercz_centro_zonal`, `gern_seccion_complemento` FROM `tb_gestion_encuesta_registro_notificacion` LEFT JOIN `tb_gestion_encuesta_registro` AS TE ON `tb_gestion_encuesta_registro_notificacion`.`gern_encuesta`=TE.`ger_consecutivo` LEFT JOIN `tb_gestion_encuesta_radicado` AS TR ON TE.`ger_radicado`=TR.`gera_radicado` LEFT JOIN `tb_administrador_usuario` AS TU ON `tb_gestion_encuesta_registro_notificacion`.`gern_usuario_registro`=TU.`usu_id` LEFT JOIN `tb_notificaciones_central_remitente` AS TN ON `tb_gestion_encuesta_registro_notificacion`.`gern_id_set_from`=TN.`ncr_id` LEFT JOIN `tb_gestion_encuestas_matriz` AS TM ON TR.`gera_matriz_id`=TM.`gem_id` LEFT JOIN `tb_gestion_encuesta_regional` AS TREG ON TR.`gera_regional`=TREG.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON TR.`gera_centro_zonal`=TCZ.`gercz_id` WHERE `gern_estado_envio`='Pendiente' LIMIT 3 OFFSET 0");
    $resultado_notificaciones = mysqli_fetch_all($consulta_notificaciones);

    if (count($resultado_notificaciones)>0) {
        for ($i=0; $i < count($resultado_notificaciones); $i++) { 
            $marca_temporal = date("Y-m-d H:i:s");
            $id_correo=$resultado_notificaciones[$i][0];
            if ($resultado_notificaciones[$i][1]!="" AND $resultado_notificaciones[$i][2]!="" AND $resultado_notificaciones[$i][3]!="") {
                if ($resultado_notificaciones[$i][54]!="") {
                    $filtro_seccion_complemento=" OR `gerd_seccion`='".$resultado_notificaciones[$i][54]."'";
                }

                $consulta_seccion = mysqli_query($enlace_db, "SELECT `gerd_encuesta`, `gerd_seccion`, TSEC.`gems_nombre_seccion`, TSEC.`gems_descripcion`, `gerd_pregunta`, TPRE.`gemp_pregunta_nombre`, TPRE.`gemp_pregunta_descripcion`, TPRE.`gemp_tipo`, `gerd_respuesta`, TOPC.`gemo_opcion_nombre` FROM `tb_gestion_encuesta_registro_data` LEFT JOIN `tb_gestion_encuestas_matriz_seccion` AS TSEC ON `tb_gestion_encuesta_registro_data`.`gerd_seccion`=TSEC.`gems_id` LEFT JOIN `tb_gestion_encuestas_matriz_seccion_pregunta` AS TPRE ON `tb_gestion_encuesta_registro_data`.`gerd_pregunta`=TPRE.`gemp_id` LEFT JOIN `tb_gestion_encuestas_matriz_seccion_pregunta_opcion` AS TOPC ON `tb_gestion_encuesta_registro_data`.`gerd_respuesta`=TOPC.`gemo_id` WHERE `gerd_encuesta`='".$resultado_notificaciones[$i][1]."' AND (`gerd_seccion`='".$resultado_notificaciones[$i][2]."' ".$filtro_seccion_complemento.")");
                $resultado_seccion = mysqli_fetch_all($consulta_seccion);


                $contenido_encuesta="";
                $id_sim_generado="";
                $descripcion_alerta="";
                for ($k=0; $k < count($resultado_seccion); $k++) {
                    if ($resultado_seccion[$k][6]!="") {
                        $texto_descripcion_pregunta=" [".$resultado_seccion[$k][6]."]";
                    } else {
                        $texto_descripcion_pregunta="";
                    }

                    if ($resultado_seccion[$k][7]=="Varias opciones" OR $resultado_seccion[$k][7]=="Casillas" OR $resultado_seccion[$k][7]=="Desplegable") {
                        $texto_respuesta=$resultado_seccion[$k][9];
                        $categoria_alerta=$resultado_seccion[$k][9];
                    } else {
                        $texto_respuesta=$resultado_seccion[$k][8];
                        $descripcion_alerta.=" ".$resultado_seccion[$k][8];
                    }
                    
                    $contenido_encuesta.="<tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>".$resultado_seccion[$k][5]."".$texto_descripcion_pregunta."</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$texto_respuesta."</td>
                        </tr>";

                    if ($k==2) {
                        $id_sim_generado=$texto_respuesta;
                    }
                }



                /*SE ESTRUCTURA COTENIDO DE CORREO*/
                    $contenido_correo="<p style='font-size: 12px; color: #2E2E2E; font-family: Lato, Arial, sans-serif;'><b>¡Alerta!</b> se requiere revisar el siguiente hallazgo asociado al Radicado: <b>".$resultado_notificaciones[$i][28]."</b></p><br>
                    <center>
                    <table style='width: 500px; font-size: 12px; font-family: Lato, Arial, sans-serif;'>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Campaña</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][51]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Fecha Alerta</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][16]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Fecha Atención al Ciudadano</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][30]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Regional</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][52]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Centro Zonal</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][53]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Identificación Peticionario</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][31]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Nombre Peticionario</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][32]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Teléfono</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_notificaciones[$i][33]." - ".$resultado_notificaciones[$i][34]."</td>
                        </tr>
                        <tr>
                            <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Agente</td>
                            <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>". $resultado_notificaciones[$i][18] ."</td>
                        </tr>
                        ".$contenido_encuesta."
                    </table>
                    <br>
                    <table style='width: 100%; background: #2E2E2E; font-family: Lato, Arial, sans-serif;'>
                        <tr>
                            <td style='font-size: 12px; padding: 5px 10px 5px 10px; color: #FFFFFF'>
                                IQ-ICBF | Gestión Integrada de Servicios | &copy; Copyright 2020 Todos los derechos reservados.
                            </td>
                        </tr>
                    </table>
                    <p style='font-family: Lato, Arial, sans-serif; color: #2E2E2E;'>
                    Esta es una notificación automática, por favor no responda este mensaje.
                    </p>
                    <table>
                        <tr>
                            <td style='width: 50px; height: 73px;'><img src='cid:logo_firma_verde' style='margin: 5px; width: 50px; height: 73px;'></td>
                            <td>
                                <p style='font-size: 11px; color: #006400; font-family: Lato, Arial, sans-serif;'><br><b>No imprima este mensaje de no ser necesario; de ésta manera aportamos al cuidado del planeta.</b></p>
                            </td>
                        </tr>
                    </table>
                    </center>";
                /*SE ESTRUCTURA COTENIDO DE CORREO*/


                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $resultado_notificaciones[$i][19];
                $mail->Port = $resultado_notificaciones[$i][20];
                // $mail->SMTPDebug = 2;
                $mail->SMTPSecure = $resultado_notificaciones[$i][21];
                $mail->SMTPAuth = $resultado_notificaciones[$i][22];
                $mail->Username = $resultado_notificaciones[$i][23];
                $mail->Password = $resultado_notificaciones[$i][24];
                $mail->SetFrom($resultado_notificaciones[$i][25], $resultado_notificaciones[$i][26]);
                
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
                
                $mail->IsHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = "Notificación de alerta - evento crítico - Canal Presencial RADICADO ".$resultado_notificaciones[$i][28];
                $asunto_final="Notificación de alerta - evento crítico - Canal Presencial RADICADO ".$resultado_notificaciones[$i][28];
                $mail->Body    = $contenido_correo;
                
                if($mail->send()) {
                    $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_gestion_encuesta_registro_notificacion` SET `gern_estado_envio`='Enviado', `gern_fecha_envio`='".$marca_temporal."', `gern_intentos`='".$num_intentos."', `gern_subject`='".$asunto_final."', `gern_body`='".str_replace("'", '"', $contenido_correo)."' WHERE `gern_id`='".$id_correo."'");

                    $consulta_consecutivo_alerta = mysqli_query($enlace_db, "SELECT MAX(`ga_id`) FROM `tb_gestion_alertas`");
                    $resultado_consecutivo_alerta = mysqli_fetch_all($consulta_consecutivo_alerta);
                    $ultimo_consecutivo_alerta=explode('INC', $resultado_consecutivo_alerta[0][0]);
                    $nuevo_consecutivo_alerta=$ultimo_consecutivo_alerta[1]+1;
                    $inser_consecutivo_alerta="INC".str_pad($nuevo_consecutivo_alerta, 12, 0, STR_PAD_LEFT);

                    $descripcion_alerta=str_replace("'", "", $descripcion_alerta); 

                    $registro_alerta = mysqli_query($enlace_db, "INSERT INTO `tb_gestion_alertas`(`ga_id`, `ga_sim_asociado`, `ga_sim_qr_encuesta`, `ga_estado`, `ga_fecha_alerta`, `ga_fecha_atencion_ciudadano`, `ga_regional`, `ga_centro_zonal`, `ga_peticionario_id`, `ga_peticionario_nombre`, `ga_peticionario_telefono`, `ga_categoria_afectacion`, `ga_descripcion_alerta`, `ga_afecta_linea_tecnica`, `ga_fecha_remite_agente_especializado`, `ga_fecha_require_regional`, `ga_fecha_respuesta_regional`, `ga_concepto_tecnico_pmejora`, `ga_decision_caso`, `ga_observaciones_n1`, `ga_observaciones_n2`, `ga_snc_ac`, `ga_fecha_cierre`, `ga_accion_mejora`, `ga_revisa_n1_usuario`, `ga_revisa_n1_fecha`, `ga_revisa_n2_usuario`, `ga_revisa_n2_fecha`, `ga_registro_usuario`) VALUES ('".$inser_consecutivo_alerta."','".$resultado_notificaciones[$i][28]."','".$id_sim_generado."','Pendiente RN2','".date('Y-m-d H:i:s')."','".$resultado_notificaciones[$i][30]."','".$resultado_notificaciones[$i][38]."','".$resultado_notificaciones[$i][39]."','".$resultado_notificaciones[$i][31]."','".$resultado_notificaciones[$i][32]."','".$resultado_notificaciones[$i][33]."-".$resultado_notificaciones[$i][34]."','".$categoria_alerta."','".$descripcion_alerta."','Si','".date('Y-m-d H:i:s')."','','','','','','','','','','','','','','".$resultado_notificaciones[$i][49]."');");

                } else {
                    $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_gestion_encuesta_registro_notificacion` SET `gern_estado_envio`='".$estado_error."', `gern_fecha_envio`='".$marca_temporal."', `gern_intentos`='".$num_intentos."' WHERE `gern_id`='".$id_correo."'");
                }
                // echo $mail->ErrorInfo;
            } else {
                $consulta_notificaciones_update = mysqli_query($enlace_db, "UPDATE `tb_gestion_encuesta_registro_notificacion` SET `gern_estado_envio`='Error-estructura', `gern_fecha_envio`='".$marca_temporal."', `gern_intentos`='1' WHERE `gern_id`='".$id_correo."'");
            }
        }
    }
?>