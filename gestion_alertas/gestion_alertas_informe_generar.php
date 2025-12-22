<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Gestión Alertas";

    require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");
    require_once('../PHPOffice/vendor/autoload.php');
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\IOFactory;
// error_reporting(E_ALL);
    /*DEFINICIÓN DE VARIABLES*/

    $fecha_inicio=validar_input($_POST['inicio']);
    $fecha_fin=validar_input($_POST['fin']);
    $observaciones=$_POST['observaciones'];
    $accion=validar_input($_POST['acc']);
   
    $consulta_regionales = mysqli_query($enlace_db, "SELECT `ga_regional`, TR.`gere_regional`, COUNT(`ga_id`) AS TOTAL, TR.`gere_correos_trimestral` FROM `tb_gestion_alertas` LEFT JOIN `tb_gestion_encuesta_regional` AS TR ON `tb_gestion_alertas`.`ga_regional`=TR.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON `tb_gestion_alertas`.`ga_centro_zonal`=TCZ.`gercz_id` WHERE `ga_afecta_linea_tecnica`='Si' AND `ga_estado`='Escalado Regional' AND `ga_fecha_alerta`>='".$fecha_inicio."' AND `ga_fecha_alerta`<='".$fecha_fin." 23:59:59' GROUP BY `ga_regional` ORDER BY TOTAL DESC, TR.`gere_regional`, TCZ.`gercz_centro_zonal`");
    $resultado_regionales = mysqli_fetch_all($consulta_regionales);

    $consulta_czonales = mysqli_query($enlace_db, "SELECT `ga_regional`, TR.`gere_regional`, `ga_centro_zonal`, TCZ.`gercz_centro_zonal`, COUNT(`ga_id`) AS TOTAL, TCZ.`gercz_correos_trimestral` FROM `tb_gestion_alertas` LEFT JOIN `tb_gestion_encuesta_regional` AS TR ON `tb_gestion_alertas`.`ga_regional`=TR.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON `tb_gestion_alertas`.`ga_centro_zonal`=TCZ.`gercz_id` WHERE `ga_afecta_linea_tecnica`='Si' AND `ga_estado`='Escalado Regional' AND `ga_fecha_alerta`>='".$fecha_inicio."' AND `ga_fecha_alerta`<='".$fecha_fin." 23:59:59' GROUP BY `ga_regional`, `ga_centro_zonal` ORDER BY TOTAL DESC, TR.`gere_regional`, TCZ.`gercz_centro_zonal`");
    $resultado_czonales = mysqli_fetch_all($consulta_czonales);
    
    for ($i=0; $i < count($resultado_czonales); $i++) { 
        $array_czonal[$resultado_czonales[$i][1]][]=$resultado_czonales[$i][3];
        $array_czonal_data[$resultado_czonales[$i][1]][$resultado_czonales[$i][3]]['cantidad']=$resultado_czonales[$i][4];
        $array_czonal_data[$resultado_czonales[$i][1]][$resultado_czonales[$i][3]]['correos']=$resultado_czonales[$i][5];

    }

    if (count($resultado_regionales)>0) {
        $estado_envio="generado";
        $lista_informe='<table class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th class="align-middle">Regional</th>
                                    <th class="align-middle">Total Alertas</th>
                                </tr>
                            </thead>
                            <tbody>';
        $total_regionales=0;
        for ($i=0; $i < count($resultado_regionales); $i++) { 
            $lista_informe.='<tr>
                            <td class="align-middle">'.$resultado_regionales[$i][1].'</td>
                            <td class="align-middle text-center">'.$resultado_regionales[$i][2].'</td>
                            </tr>';
            $total_regionales+=$resultado_regionales[$i][2];
        }

        $lista_informe.='<tr>
                            <td class="align-middle font-weight-bold">Total</td>
                            <td class="align-middle text-center font-weight-bold">'.$total_regionales.'</td>
                            </tr>';
        
        $lista_informe.='</tbody>
                        </table><br>';

        $lista_informe.='<table class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th class="align-middle">Regional/Centro Zonal</th>
                                    <th class="align-middle">Total Alertas</th>
                                </tr>
                            </thead>
                            <tbody>';
        
        for ($i=0; $i < count($resultado_regionales); $i++) { 
            $lista_informe.='<tr>
                            <td class="align-middle font-weight-bold">'.$resultado_regionales[$i][1].'</td>
                            <td class="align-middle text-center font-weight-bold">'.$resultado_regionales[$i][2].'</td>
                            </tr>';
            for ($j=0; $j < count($array_czonal[$resultado_regionales[$i][1]]); $j++) { 
                $lista_informe.='<tr>
                                <td class="align-middle pl-4 font-size-11">'.$array_czonal[$resultado_regionales[$i][1]][$j].'</td>
                                <td class="align-middle text-center font-size-11">'.$array_czonal_data[$resultado_regionales[$i][1]][$array_czonal[$resultado_regionales[$i][1]][$j]]['cantidad'].'</td>
                                </tr>';
            }
        }

        $lista_informe.='<tr>
                            <td class="align-middle font-weight-bold">Total</td>
                            <td class="align-middle text-center font-weight-bold">'.$total_regionales.'</td>
                            </tr>';

        $lista_informe.='</tbody>
                        </table>';
    } else {
        $lista_informe='<p class="alert alert-warning col-md-12 p-1 font-size-11 mb-1">
                            <span class="fas fa-exclamation-triangle"></span> No se encontraron registros
                        </p>';
        $estado_envio="";
    }

    if ($accion=="enviar") {

        if (count($resultado_regionales)>0) {
            $destinatarios_notificacion="";
            
            /*GENERAR REPORTE EXCEL*/
                $data_consulta=array();

                array_push($data_consulta, $fecha_inicio);

                $titulo_reporte="Gestión Alertas-Consolidado ".date('Y-m-d H_i_s').".xlsx";
                $titulo_hoja="Consolidado";
                $consulta_string="SELECT `ga_id`, `ga_sim_asociado`, `ga_sim_qr_encuesta`, `ga_estado`, `ga_fecha_alerta`, `ga_fecha_atencion_ciudadano`, `ga_regional`, `ga_centro_zonal`, `ga_peticionario_id`, `ga_peticionario_nombre`, `ga_peticionario_telefono`, `ga_categoria_afectacion`, `ga_descripcion_alerta`, `ga_afecta_linea_tecnica`, `ga_fecha_remite_agente_especializado`, `ga_fecha_require_regional`, `ga_fecha_respuesta_regional`, `ga_concepto_tecnico_pmejora`, `ga_decision_caso`, `ga_snc_ac`, `ga_fecha_cierre`, `ga_accion_mejora`, `ga_revisa_n1_usuario`, `ga_revisa_n1_fecha`, `ga_revisa_n2_usuario`, `ga_revisa_n2_fecha`, `ga_registro_usuario`, `ga_registro_fecha`, TR.`gere_regional`, TCZ.`gercz_centro_zonal`, TU.`usu_nombres_apellidos`, `ga_fecha_cierre` FROM `tb_gestion_alertas` LEFT JOIN `tb_administrador_usuario` AS TU ON `tb_gestion_alertas`.`ga_registro_usuario`=TU.`usu_id` LEFT JOIN `tb_gestion_encuesta_regional` AS TR ON `tb_gestion_alertas`.`ga_regional`=TR.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON `tb_gestion_alertas`.`ga_centro_zonal`=TCZ.`gercz_id` WHERE `ga_afecta_linea_tecnica`='Si' AND `ga_estado`='Escalado Regional' AND `ga_fecha_alerta`>=? AND `ga_fecha_alerta`<='".$fecha_fin." 23:59:59' ORDER BY `ga_id` ASC";
                $consulta_registros = $enlace_db->prepare($consulta_string);
                $consulta_registros->bind_param(str_repeat("s", count($data_consulta)), ...$data_consulta);
                $consulta_registros->execute();
                $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

                // Creamos nueva instancia de PHPExcel 
                $spreadsheet = new Spreadsheet();

                // Establecer propiedades
                $spreadsheet->getProperties()
                ->setCreator("IQ-ICBF Gestión Integrada de Servicios")
                ->setLastModifiedBy($_SESSION['usu_nombre_completo'])
                ->setTitle("IQ-ICBF Gestión Integrada de Servicios")
                ->setSubject("IQ-ICBF Gestión Integrada de Servicios")
                ->setDescription("IQ-ICBF Gestión Integrada de Servicios")
                ->setKeywords("IQ-ICBF Gestión Integrada de Servicios")
                ->setCategory("Reporte");

                //Estilos para todo el libro de excel
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
                    
                //Activar hoja 0
                $sheet = $spreadsheet->getActiveSheet(0);
                
                // // Nombramos la hoja 0
                $spreadsheet->getActiveSheet()->setTitle($titulo_hoja);

                // //Estilos de la Hoja 0
                $spreadsheet->getActiveSheet()->getRowDimension('1')->setRowHeight(15);
                $spreadsheet->getActiveSheet()->getRowDimension('2')->setRowHeight(15);
                $spreadsheet->getActiveSheet()->getRowDimension('3')->setRowHeight(15);
                $spreadsheet->getActiveSheet()->getRowDimension('4')->setRowHeight(15);
                $spreadsheet->getActiveSheet()->getRowDimension('5')->setRowHeight(15);
                $spreadsheet->getActiveSheet()->getRowDimension('6')->setRowHeight(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('A')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('B')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('C')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('D')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('E')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('F')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('G')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('H')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('I')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('J')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('K')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('L')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('M')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('N')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('O')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('P')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('Q')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('R')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('S')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('T')->setWidth(20);
                $spreadsheet->getActiveSheet()->getColumnDimension('U')->setWidth(20);
                
                $spreadsheet->getActiveSheet()->getStyle('A6:U6')->applyFromArray($styleArrayTitulos);
                $spreadsheet->getActiveSheet()->setAutoFilter('A6:U6');

                // Escribiendo los titulos
                $spreadsheet->getActiveSheet()->setCellValue('A6','NÚMERO DE ALERTA');
                $spreadsheet->getActiveSheet()->setCellValue('B6','ESTADO');
                $spreadsheet->getActiveSheet()->setCellValue('C6','SIM ASOCIADO');
                $spreadsheet->getActiveSheet()->setCellValue('D6','SIM QUEJA O RECLAMO AL MOMENTO DE LA ENCUESTA');
                $spreadsheet->getActiveSheet()->setCellValue('E6','FECHA DE ALERTA');
                $spreadsheet->getActiveSheet()->setCellValue('F6','FECHA DE ATENCIÓN AL CIUDADANO');
                $spreadsheet->getActiveSheet()->setCellValue('G6','REGIONAL AFECTADA POR LA ALERTA');
                $spreadsheet->getActiveSheet()->setCellValue('H6','PUNTO DE ATENCIÓN AFECTADO POR LA ALERTA (CENTRO ZONAL)');
                $spreadsheet->getActiveSheet()->setCellValue('I6','CATEGORÍA DE AFECTACIÓN');
                $spreadsheet->getActiveSheet()->setCellValue('J6','DESCRIPCIÓN DE LA ALERTA');
                $spreadsheet->getActiveSheet()->setCellValue('K6','VALIDACIÓN DE LA AFECTACIÓN DE LA LÍNEA TÉCNICA DEL PROCESO (SI / NO)');
                $spreadsheet->getActiveSheet()->setCellValue('L6','FECHA DE REMISIÓN AL AGENTE ESPECIALIZADO ');
                $spreadsheet->getActiveSheet()->setCellValue('M6','FECHA DE REQUERIMIENTO A LA REGIONAL');
                $spreadsheet->getActiveSheet()->setCellValue('N6','RESPUESTA POR PARTE DE LA REGIONAL AL REQUERIMIENTO (SI / NO)');
                $spreadsheet->getActiveSheet()->setCellValue('O6','FECHA RESPUESTA DE LA REGIONAL');
                $spreadsheet->getActiveSheet()->setCellValue('P6','SÍNTESIS DE LA RESPUESTA DE LA REGIONAL Y CONCEPTO TÉCNICO FRENTE A LA RESPUESTA DE LA REGIONAL');
                $spreadsheet->getActiveSheet()->setCellValue('Q6','DECISIÓN DEL CASO');
                $spreadsheet->getActiveSheet()->setCellValue('R6','No. SNC/AC');
                $spreadsheet->getActiveSheet()->setCellValue('S6','SEGUIMIENTO A LA DECISIÓN ');
                $spreadsheet->getActiveSheet()->setCellValue('T6','CIERRE DE LA ALERTA (SI/NO)');
                $spreadsheet->getActiveSheet()->setCellValue('U6','AGENTE REMITENTE');
                
                $spreadsheet->getActiveSheet()->setCellValue('A1','Gestión Alertas');
                $spreadsheet->getActiveSheet()->setCellValue('A2','Tipo reporte: Consolidado');
                $spreadsheet->getActiveSheet()->setCellValue('A3','Estado: Escalado Regional');
                $spreadsheet->getActiveSheet()->setCellValue('A4','Fecha filtro: '.$fecha_inicio.' A '.$fecha_fin);
                $spreadsheet->getActiveSheet()->setCellValue('A5','Fecha reporte: '.date('Y-m-d H:i:s'));

                //Ingresar Data consultada a partir de la fila 2

                for ($i=7; $i < count($resultado_registros)+7; $i++) {
                    $spreadsheet->getActiveSheet()->setCellValue('A'.$i,$resultado_registros[$i-7][0]);
                    $spreadsheet->getActiveSheet()->setCellValue('B'.$i,$resultado_registros[$i-7][3]);
                    $spreadsheet->getActiveSheet()->setCellValue('C'.$i,$resultado_registros[$i-7][1]);
                    $spreadsheet->getActiveSheet()->setCellValue('D'.$i,$resultado_registros[$i-7][2]);
                    $spreadsheet->getActiveSheet()->setCellValue('E'.$i,$resultado_registros[$i-7][4]);
                    $spreadsheet->getActiveSheet()->setCellValue('F'.$i,$resultado_registros[$i-7][5]);
                    $spreadsheet->getActiveSheet()->setCellValue('G'.$i,$resultado_registros[$i-7][28]);
                    $spreadsheet->getActiveSheet()->setCellValue('H'.$i,$resultado_registros[$i-7][29]);
                    $spreadsheet->getActiveSheet()->setCellValue('I'.$i,$resultado_registros[$i-7][11]);
                    $spreadsheet->getActiveSheet()->setCellValue('J'.$i,$resultado_registros[$i-7][12]);
                    $spreadsheet->getActiveSheet()->setCellValue('K'.$i,$resultado_registros[$i-7][13]);
                    $spreadsheet->getActiveSheet()->setCellValue('L'.$i,$resultado_registros[$i-7][14]);
                    $spreadsheet->getActiveSheet()->setCellValue('M'.$i,$resultado_registros[$i-7][15]);
                    $spreadsheet->getActiveSheet()->setCellValue('N'.$i,$resultado_registros[$i-7][16]);
                    $spreadsheet->getActiveSheet()->setCellValue('O'.$i,$resultado_registros[$i-7][16]);
                    $spreadsheet->getActiveSheet()->setCellValue('P'.$i,'');
                    $spreadsheet->getActiveSheet()->setCellValue('Q'.$i,$resultado_registros[$i-7][18]);
                    $spreadsheet->getActiveSheet()->setCellValue('R'.$i,$resultado_registros[$i-7][19]);
                    $spreadsheet->getActiveSheet()->setCellValue('S'.$i,'');
                    $spreadsheet->getActiveSheet()->setCellValue('T'.$i,$resultado_registros[$i-7][20]);
                    $spreadsheet->getActiveSheet()->setCellValue('U'.$i,$resultado_registros[$i-7][30]);
                }

                // Guardamos el archivo, en este caso lo guarda con el mismo nombre del php
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('/var/www/html/gestion_alertas/temporal/'.$titulo_reporte);

            /*GENERAR REPORTE EXCEL*/


            $destinatarios_cz=explode(';', $resultado_registros_validar_notificacion[0][26]);
            for ($i=0; $i < count($destinatarios_cz); $i++) { 
                $destinatarios_notificacion.=$destinatarios_cz[$i]."|".$destinatarios_cz[$i].";";
            }

            $lista_informe_correo="<table style='width: 400px; font-size: 12px; font-family: Lato, Arial, sans-serif;'>
                                <tr>
                                    <td style='width: 70%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px; text-align: center;'>Regional</td>
                                    <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px; text-align: center;'>Total Alertas</td>
                                </tr>";
            $total_regionales=0;
            for ($i=0; $i < count($resultado_regionales); $i++) { 
                $lista_informe_correo.="<tr>
                                <td style='width: 70%;padding: 3px 3px 3px 3px;background-color: #F2F2F2;'>".$resultado_regionales[$i][1]."</td>
                                <td style='width: 30%;padding: 3px 3px 3px 3px;background-color: #F2F2F2; text-align: center;'>".$resultado_regionales[$i][2]."</td>
                                </tr>";
                $total_regionales+=$resultado_regionales[$i][2];

                if ($resultado_regionales[$i][3]!="") {
                    $destinatarios_reg=explode(';', $resultado_regionales[$i][3]);
                    for ($k=0; $k < count($destinatarios_reg); $k++) { 
                        $destinatarios_notificacion.=$destinatarios_reg[$k]."|".$destinatarios_reg[$k].";";
                    }
                }
            }

            $lista_informe_correo.="<tr>
                                <td style='width: 70%;padding: 3px 3px 3px 3px;background-color: #F2F2F2; font-weight: bold;'>Total</td>
                                <td style='width: 30%;padding: 3px 3px 3px 3px;background-color: #F2F2F2; text-align: center; font-weight: bold;'>".$total_regionales."</td>
                                </tr>";

            $lista_informe_correo.="</table><br>";

            $lista_informe_correo.="<table style='width: 400px; font-size: 12px; font-family: Lato, Arial, sans-serif;'>
                                <tr>
                                    <td style='width: 70%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px; text-align: center;'>Regional/Centro Zonal</td>
                                    <td style='width: 30%;background-color: #4CAF50; color: #FFFFFF; padding: 5px 5px 5px 5px; text-align: center;'>Total Alertas</td>
                                </tr>";
            
            for ($i=0; $i < count($resultado_regionales); $i++) { 
                $lista_informe_correo.="<tr>
                                <td style='width: 70%;padding: 3px 3px 3px 3px;background-color: #F2F2F2; font-weight: bold;'>".$resultado_regionales[$i][1]."</td>
                                <td style='width: 30%;padding: 3px 3px 3px 3px;background-color: #F2F2F2; font-weight: bold; text-align: center;'>".$resultado_regionales[$i][2]."</td>
                                </tr>";
                for ($j=0; $j < count($array_czonal[$resultado_regionales[$i][1]]); $j++) { 
                    $lista_informe_correo.="<tr>
                                    <td style='width: 70%;padding: 2px 2px 2px 15px;background-color: #F2F2F2;'>".$array_czonal[$resultado_regionales[$i][1]][$j]."</td>
                                    <td style='width: 30%;padding: 2px 2px 2px 2px;background-color: #F2F2F2; text-align: center;'>".$array_czonal_data[$resultado_regionales[$i][1]][$array_czonal[$resultado_regionales[$i][1]][$j]]['cantidad']."</td>
                                    </tr>";

                    if ($array_czonal_data[$resultado_regionales[$i][1]][$array_czonal[$resultado_regionales[$i][1]][$j]]['correos']!="") {
                        $destinatarios_reg=explode(';', $array_czonal_data[$resultado_regionales[$i][1]][$array_czonal[$resultado_regionales[$i][1]][$j]]['correos']);
                        for ($k=0; $k < count($destinatarios_reg); $k++) { 
                            $destinatarios_notificacion.=$destinatarios_reg[$k]."|".$destinatarios_reg[$k].";";
                        }
                    }
                }
            }

            $lista_informe_correo.="<tr>
                                <td style='width: 70%;padding: 3px 3px 3px 3px;background-color: #F2F2F2; font-weight: bold;'>Total</td>
                                <td style='width: 30%;padding: 3px 3px 3px 3px;background-color: #F2F2F2; text-align: center; font-weight: bold;'>".$total_regionales."</td>
                                </tr>";

            $lista_informe_correo.="</table>";

            if ($observaciones!="") {
                $observaciones_enviar="<b>Notas: </b>".nl2br($observaciones)."<br>";
            } else {
                $observaciones_enviar="";
            }


            /*SE CONFIGURAN PARÁMETROS A REGISTRAR EN SISTEMA DE NOTIFICACIÓN*/
                /*SE ESTRUCTURA COTENIDO DE CORREO*/
                    $contenido_correo="<p style='font-size: 12px; color: #2E2E2E; font-family: Lato, Arial, sans-serif;'><b>Cordial saludo,</b><br><br>A continuación se relacionan las alertas generadas entre el ".$fecha_inicio." y el ".$fecha_fin.".</p><br>
                    <center>
                    ".$lista_informe_correo."
                    <br>
                    </center>
                    <p style='font-size: 12px; color: #2E2E2E; font-family: Lato, Arial, sans-serif;'>".$observaciones_enviar."</p><br>
                    <center>
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

                $consulta_string_destinatarios="SELECT `gad_id`, `gad_filtro`, `gad_correos_to`, `gad_correos_cc` FROM `tb_gestion_alertas_destinatarios` WHERE `gad_filtro`='informe_regional'";
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

                $nc_id_set_from="2";
                $nc_bcc="mariostiv@hotmail.com|mariostiv@hotmail.com;";
                $nc_subject="Informe Gestión Alertas";
                $nc_body=str_replace("'", '"', $contenido_correo);
                $nc_embeddedimage_ruta="/var/www/html/images/firma-verde.png";
                $nc_embeddedimage_nombre="logo_firma_verde";
                $nc_embeddedimage_tipo="image/png";
                $nc_adjuntos_nombre="Consolidado Alertas.xlsx;";
                $nc_adjuntos_ruta="/var/www/html/gestion_alertas/temporal/".$titulo_reporte.";";
                $nc_intentos="";
                $nc_eliminar="Si";
                $nc_estado_envio="Pendiente";
                $nc_fecha_envio="";
                $nc_usuario_registro=$_SESSION["usu_id"];

                $consulta_notificacion = mysqli_query($enlace_db, "INSERT INTO `tb_gestion_alertas_notificacion`(`gan_consecutivo`, `gan_tipo`, `gan_id_set_from`, `gan_address`, `gan_cc`, `gan_bcc`, `gan_subject`, `gan_body`, `gan_embeddedimage_ruta`, `gan_embeddedimage_nombre`, `gan_embeddedimage_tipo`, `gan_adjunto_nombre`, `gan_adjunto_ruta`, `gan_intentos`, `gan_eliminar`, `gan_estado_envio`, `gan_fecha_envio`, `gan_usuario_registro`) VALUES ('".$id_registro."','informe','".$nc_id_set_from."','".$nc_address."','".$nc_cc."','".$nc_bcc."','".$nc_subject."','".$nc_body."','".$nc_embeddedimage_ruta."','".$nc_embeddedimage_nombre."','".$nc_embeddedimage_tipo."','".$nc_adjuntos_nombre."','".$nc_adjuntos_ruta."','".$nc_intentos."','".$nc_eliminar."','".$nc_estado_envio."','".$nc_fecha_envio."','".$nc_usuario_registro."');");

                if ($consulta_notificacion) {
                    registro_log($enlace_db, $modulo_plataforma, 'notificacion', $nc_subject.' ['.$id_registro.']');
                    $estado_envio='enviado';
                } else {
                    registro_log($enlace_db, $modulo_plataforma, 'notificacion_error', $nc_subject.' ['.$id_registro.']');
                    $estado_envio='no_enviado';
                }
            /*SE CONFIGURAN PARÁMETROS A REGISTRAR EN SISTEMA DE NOTIFICACIÓN*/


        } else {
            $estado_envio='<p class="alert alert-warning col-md-12 p-1 font-size-11 mb-1"><span class="fas fa-exclamation-triangle"></span> ¡No se encontraron registros para enviar!</p>';
        } 
    }

    $datos=array('informe_lista'=>$lista_informe, 'informe_estado'=>$estado_envio);

    echo json_encode($datos);
?>