<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Gestión Alertas";

    require_once("../config/validaciones_seguridad.php");
	require_once("../config/conexion_db.php");

    /*DEFINICIÓN DE VARIABLES*/

    $titulo_header = "Gestión Alertas | Reenviar Notificación";
    $pagina=validar_input($_GET['pagina']);
    $filtro_permanente=validar_input($_GET['id']);
    $estado=validar_input($_GET['est']);
    $id_registro=validar_input(base64_decode($_GET['reg']));

    if(isset($_POST["guardar_registro"])){
        $reenviar=validar_input($_POST['reenviar']);

        if($_SESSION['gestion_alerta_reenvia']!=1){
            if($reenviar=="Si") {
                $consulta_string_validar_notificacion="SELECT `ga_id`, `ga_sim_asociado`, `ga_sim_qr_encuesta`, `ga_estado`, `ga_fecha_alerta`, `ga_fecha_atencion_ciudadano`, `ga_regional`, `ga_centro_zonal`, `ga_peticionario_id`, `ga_peticionario_nombre`, `ga_peticionario_telefono`, `ga_categoria_afectacion`, `ga_descripcion_alerta`, `ga_afecta_linea_tecnica`, `ga_fecha_remite_agente_especializado`, `ga_fecha_require_regional`, `ga_fecha_respuesta_regional`, `ga_concepto_tecnico_pmejora`, `ga_decision_caso`, `ga_snc_ac`, `ga_registro_usuario`, `ga_registro_fecha`, TR.`gere_regional`, TCZ.`gercz_centro_zonal`, TU.`usu_nombres_apellidos`, TU.`usu_correo_corporativo`, TCZ.`gercz_correos`, TR.`gere_correos`, `ga_revisa_n1_usuario`, TUN1.`usu_nombres_apellidos`, TUN1.`usu_correo_corporativo`, `ga_revisa_n2_usuario`, TUN2.`usu_nombres_apellidos`, TUN2.`usu_correo_corporativo` FROM `tb_gestion_alertas` LEFT JOIN `tb_administrador_usuario` AS TU ON `tb_gestion_alertas`.`ga_registro_usuario`=TU.`usu_id` LEFT JOIN `tb_administrador_usuario` AS TUN1 ON `tb_gestion_alertas`.`ga_revisa_n1_usuario`=TUN1.`usu_id` LEFT JOIN `tb_administrador_usuario` AS TUN2 ON `tb_gestion_alertas`.`ga_revisa_n2_usuario`=TUN2.`usu_id` LEFT JOIN `tb_gestion_encuesta_regional` AS TR ON `tb_gestion_alertas`.`ga_regional`=TR.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON `tb_gestion_alertas`.`ga_centro_zonal`=TCZ.`gercz_id` WHERE `ga_id`=?";

                $consulta_registros_validar_notificacion = $enlace_db->prepare($consulta_string_validar_notificacion);
                $consulta_registros_validar_notificacion->bind_param("s", $id_registro);
                $consulta_registros_validar_notificacion->execute();
                $resultado_registros_validar_notificacion = $consulta_registros_validar_notificacion->get_result()->fetch_all(MYSQLI_NUM);

                $destinatarios_cz=explode(';', $resultado_registros_validar_notificacion[0][26]);
                $destinatarios_notificacion="";
                for ($i=0; $i < count($destinatarios_cz); $i++) { 
                    $destinatarios_notificacion.=$destinatarios_cz[$i]."|".$destinatarios_cz[$i].";";
                }

                $destinatarios_reg=explode(';', $resultado_registros_validar_notificacion[0][27]);
                for ($i=0; $i < count($destinatarios_reg); $i++) { 
                    $destinatarios_notificacion.=$destinatarios_reg[$i]."|".$destinatarios_reg[$i].";";
                }

                /*SE CONFIGURAN PARÁMETROS A REGISTRAR EN SISTEMA DE NOTIFICACIÓN*/
                    /*SE ESTRUCTURA COTENIDO DE CORREO*/
                        $contenido_correo="<p style='font-size: 12px; color: #2E2E2E; font-family: Lato, Arial, sans-serif;'><b>Cordial saludo,</b><br>Se realiza escalamiento de la alerta que se relaciona a continuación:</p><br>
                        <center>
                        <table style='width: 500px; font-size: 12px; font-family: Lato, Arial, sans-serif;'>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Radicado SIM</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][1]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Radicado Encuesta</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][2]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Fecha Alerta</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][4]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Fecha Atención al Ciudadano</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][5]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Regional</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][22]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Centro Zonal</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][23]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Doc. Peticionario</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][8]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Nombre Peticionario</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][9]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Teléfono</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>".$resultado_registros_validar_notificacion[0][10]."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Agente</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>". $resultado_registros_validar_notificacion[0][24] ."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Categoría</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>". $resultado_registros_validar_notificacion[0][11] ."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Contexto alerta</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>". $resultado_registros_validar_notificacion[0][12] ."</td>
                            </tr>
                            <tr>
                                <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px;'>Observaciones/comentarios</td>
                                <td style='width: 70%;padding: 5px 5px 5px 5px;background-color: #F2F2F2;'>". $observaciones ."</td>
                            </tr>
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

                    $consulta_string_destinatarios="SELECT `gad_id`, `gad_filtro`, `gad_correos_to`, `gad_correos_cc` FROM `tb_gestion_alertas_destinatarios` WHERE `gad_filtro`='afectalt_nivel2'";
                    $consulta_registros_destinatarios = $enlace_db->prepare($consulta_string_destinatarios);
                    $consulta_registros_destinatarios->execute();
                    $resultado_registros_destinatarios = $consulta_registros_destinatarios->get_result()->fetch_all(MYSQLI_NUM);
                    
                    $destino_to=explode(";", $resultado_registros_destinatarios[0][2]);
                    $nc_address=$destinatarios_notificacion;
                    for ($j=0; $j < count($destino_to); $j++) { 
                        if ($destino_to[$j]!="") {
                            $nc_address.=$destino_to[$j].'|'.$destino_to[$j].';';
                        }
                    }

                    $destino_cc=explode(";", $resultado_registros_destinatarios[0][3]);
                    $nc_cc=$resultado_registros_validar_notificacion[0][30]."|".$resultado_registros_validar_notificacion[0][30].";".$resultado_registros_validar_notificacion[0][33]."|".$resultado_registros_validar_notificacion[0][33].";";
                    for ($j=0; $j < count($destino_cc); $j++) { 
                        if ($destino_cc[$j]!="") {
                            $nc_cc.=$destino_cc[$j].'|'.$destino_cc[$j].';';
                        }
                    }

                    $nc_id_set_from="1";
                    $nc_bcc="";
                    $nc_subject="Escalamiento Alerta a Regional";
                    $nc_body=str_replace("'", '"', $contenido_correo);
                    $nc_embeddedimage_ruta="/var/www/html/images/firma-verde.png";
                    $nc_embeddedimage_nombre="logo_firma_verde";
                    $nc_embeddedimage_tipo="image/png";
                    $nc_adjuntos_nombre="";
                    $nc_adjuntos_ruta="";
                    $nc_intentos="";
                    $nc_eliminar="Si";
                    $nc_estado_envio="Pendiente";
                    $nc_fecha_envio="";
                    $nc_usuario_registro=$_SESSION["usu_id"];

                    $consulta_notificacion = mysqli_query($enlace_db, "INSERT INTO `tb_gestion_alertas_notificacion`(`gan_consecutivo`, `gan_tipo`, `gan_id_set_from`, `gan_address`, `gan_cc`, `gan_bcc`, `gan_subject`, `gan_body`, `gan_embeddedimage_ruta`, `gan_embeddedimage_nombre`, `gan_embeddedimage_tipo`, `gan_adjunto_nombre`, `gan_adjunto_ruta`, `gan_intentos`, `gan_eliminar`, `gan_estado_envio`, `gan_fecha_envio`, `gan_usuario_registro`) VALUES ('".$id_registro."','escalamiento_regional','".$nc_id_set_from."','".$nc_address."','".$nc_cc."','".$nc_bcc."','".$nc_subject."','".$nc_body."','".$nc_embeddedimage_ruta."','".$nc_embeddedimage_nombre."','".$nc_embeddedimage_tipo."','".$nc_adjuntos_nombre."','".$nc_adjuntos_ruta."','".$nc_intentos."','".$nc_eliminar."','".$nc_estado_envio."','".$nc_fecha_envio."','".$nc_usuario_registro."');");

                    if ($consulta_notificacion) {
                        registro_log($enlace_db, $modulo_plataforma, 'notificacion', $nc_subject.' ['.$id_registro.']');
                        $respuesta_accion = "<script type='text/javascript'>alertify.success('Registro actualizado exitosamente!', 0);</script>";
                        $_SESSION['gestion_alerta_reenvia']=1;
                    } else {
                        registro_log($enlace_db, $modulo_plataforma, 'notificacion_error', $nc_subject.' ['.$id_registro.']');
                        $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Problemas al actualizar el registro, por favor verifique e intente nuevamente!', 0);</script>";
                    }
                /*SE CONFIGURAN PARÁMETROS A REGISTRAR EN SISTEMA DE NOTIFICACIÓN*/
            }
        } else {
            $respuesta_accion = "<script type='text/javascript'>alertify.success('¡Registro actualizado exitosamente, haga clic en <b>Finalizar</b> para salir!', 0);</script>";
        }
    }

    $consulta_string="SELECT `ga_id`, `ga_sim_asociado`, `ga_sim_qr_encuesta`, `ga_estado`, `ga_fecha_alerta`, `ga_fecha_atencion_ciudadano`, `ga_regional`, `ga_centro_zonal`, `ga_peticionario_id`, `ga_peticionario_nombre`, `ga_peticionario_telefono`, `ga_categoria_afectacion`, `ga_descripcion_alerta`, `ga_afecta_linea_tecnica`, `ga_fecha_remite_agente_especializado`, `ga_fecha_require_regional`, `ga_fecha_respuesta_regional`, `ga_concepto_tecnico_pmejora`, `ga_decision_caso`, `ga_snc_ac`, `ga_registro_usuario`, `ga_registro_fecha`, TR.`gere_regional`, TCZ.`gercz_centro_zonal`, TU.`usu_nombres_apellidos`,`ga_fecha_cierre` FROM `tb_gestion_alertas` LEFT JOIN `tb_administrador_usuario` AS TU ON `tb_gestion_alertas`.`ga_registro_usuario`=TU.`usu_id` LEFT JOIN `tb_gestion_encuesta_regional` AS TR ON `tb_gestion_alertas`.`ga_regional`=TR.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON `tb_gestion_alertas`.`ga_centro_zonal`=TCZ.`gercz_id` WHERE `ga_id`=?";

    $consulta_registros = $enlace_db->prepare($consulta_string);
    $consulta_registros->bind_param("s", $id_registro);
    $consulta_registros->execute();
    $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string_encuestas="SELECT `ger_consecutivo`, `ger_encuesta_id`, `ger_radicado`, `ger_observaciones`, `ger_registro_usuario`, `ger_registro_fecha`, TU.`usu_nombres_apellidos`, `ger_contactado`, `ger_efectivo` FROM `tb_gestion_encuesta_registro` LEFT JOIN `tb_administrador_usuario` AS TU ON `tb_gestion_encuesta_registro`.`ger_registro_usuario`=TU.`usu_id` WHERE `ger_radicado`=? ORDER BY `ger_registro_fecha` DESC LIMIT 0,1";

    $consulta_registros_encuestas = $enlace_db->prepare($consulta_string_encuestas);
    $consulta_registros_encuestas->bind_param("s", $resultado_registros[0][1]);
    $consulta_registros_encuestas->execute();
    $resultado_registros_encuestas = $consulta_registros_encuestas->get_result()->fetch_all(MYSQLI_NUM);

    /*Enlace para botón finalizar y cancelar*/
    $ruta_cancelar_finalizar="gestion_alertas.php?pagina=".$pagina."&id=".$filtro_permanente."&est=".$estado;

?>
<!DOCTYPE html>
<html lang="ES">
<head>
	<?php
        include("../config/configuracion_estilos.php");
    ?>
</head>
<body>
    <?php
        include("../menu_principal.php");
        include("../menu_header.php");
    ?>
    <div class="contenido">
        <?php if (!empty($respuesta_accion)) {echo $respuesta_accion;} ?>
        <form name="guardar_registro" action="" method="POST" enctype="multipart/form-data">
        <div class="row justify-content-center">
            <div class="col-md-4 pt-2">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover table-sm">
                        <thead>
                            <tr>
                                <th class="align-middle text-left">Consecutivo</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][0]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Radicado SIM</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][1]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left" style="min-width: 100px;">Fecha Alerta</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][4]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Fecha Atención al Ciudadano</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][5]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Regional</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][22]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Centro Zonal</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][23]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Doc. Peticionario</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][8]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Nombre Peticionario</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][9]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Teléfono</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][10]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Agente</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][24]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Radicado SIM Encuesta</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][2]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Categoría</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][11]; ?></td>
                            </tr>
                            <tr>
                                <th class="align-middle text-left">Contexto</th>
                                <td class="align-middle"><?php echo $resultado_registros[0][12]; ?></td>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>

            <div class="col-md-4 pt-2 background-blanco">
                <div class="row">
                    <div class="col-md-12">
                        <a href="#" onClick="open_modal_encuesta('<?php echo base64_encode($resultado_registros_encuestas[0][0]); ?>');" class="btn btn-success mb-1 font-size-11" title="Ver Encuesta"><span class="fas fa-file-alt"></span> <?php echo $resultado_registros_encuestas[0][0]; ?></a>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="afecta_lt" class="m-0">Reenviar notificación?</label>
                            <select class="form-control form-control-sm" name="reenviar" id="reenviar" <?php if($_SESSION['gestion_alerta_reenvia']==1) { echo 'disabled'; } ?> required>
                              <option value="">Seleccione</option>
                              <option value="Si">Si</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <?php if($_SESSION['gestion_alerta_reenvia']==1): ?>
                                <a href="<?php echo $ruta_cancelar_finalizar; ?>" class="btn btn-dark float-right">Finalizar</a>
                            <?php else: ?>
                                <button class="btn btn-success float-right ml-1" type="submit" name="guardar_registro">Guardar</button>
                                <button class="btn btn-danger float-right" type="button" onclick="guardar_cancelar();">Cancelar</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
        </form>
        <!-- Modal ENCUESTA -->
        <div class="modal fade" id="modal_ver_encuesta" role="dialog">
            <div class="modal-dialog modal-xl">
                <!-- Modal content-->
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title">Detalle encuesta</h4>
                        <button type="button" class="close" data-dismiss="modal" onClick="close_modal_encuesta();">×</button>
                    </div>
                    <div class="modal-body modal-body-solicitud">

                    </div>
                    <div class="modal-footer">
                        <a href="#" onClick="close_modal_encuesta();" class="btn btn-corp menu float-right" data-dismiss="modal"><div class="float-left"><span class="fas fa-times"></span></div><div class="pl-2 menu_res float-left">Cerrar</div></a>
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
            $("#modal_ver_encuesta").on('hidden.bs.modal', function () {
                close_modal_encuesta();
            });
        });
        
        function open_modal_encuesta(id_registro) {
            $('.modal-body-solicitud').load('gestion_alertas_encuesta_ver.php?reg='+id_registro,function(){
                $('#modal_ver_encuesta').modal({show:true});
            });
        }

        function close_modal_encuesta() {
            $('.modal-body-solicitud').html('');
        }
    </script>
</body>
</html>