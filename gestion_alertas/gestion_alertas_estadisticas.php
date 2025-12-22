<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Gestión Alertas";

	require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");

    /*DEFINICIÓN DE VARIABLES*/
    $titulo_header = "Gestión Alertas | Estadísticas";


    // Inicializa variable tipo array
    $data_consulta=array();
    
    // Ejemplo filtro campo buscar GTO
    if (isset($_POST["filtro"])) {
        $fecha_inicio=validar_input($_POST['fecha_inicio']);
        $fecha_fin=validar_input($_POST['fecha_fin']);
        $afectalt=validar_input($_POST['afectalt']);
    } else {
        $fecha_inicio=date('Y-m')."-01";
        $fecha_fin=date('Y-m-d');
        $afectalt="Si";

    }

    $consulta_regionales = mysqli_query($enlace_db, "SELECT `ga_regional`, TR.`gere_regional`, COUNT(`ga_id`) AS TOTAL FROM `tb_gestion_alertas` LEFT JOIN `tb_gestion_encuesta_regional` AS TR ON `tb_gestion_alertas`.`ga_regional`=TR.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON `tb_gestion_alertas`.`ga_centro_zonal`=TCZ.`gercz_id` WHERE `ga_afecta_linea_tecnica`='".$afectalt."' AND `ga_fecha_require_regional`<>'' AND `ga_fecha_alerta`>='".$fecha_inicio."' AND `ga_fecha_alerta`<='".$fecha_fin." 23:59:59' GROUP BY `ga_regional` ORDER BY TOTAL DESC, TR.`gere_regional`, TCZ.`gercz_centro_zonal`");
    $resultado_regionales = mysqli_fetch_all($consulta_regionales);

    $consulta_czonales = mysqli_query($enlace_db, "SELECT `ga_regional`, TR.`gere_regional`, `ga_centro_zonal`, TCZ.`gercz_centro_zonal`, COUNT(`ga_id`) AS TOTAL FROM `tb_gestion_alertas` LEFT JOIN `tb_gestion_encuesta_regional` AS TR ON `tb_gestion_alertas`.`ga_regional`=TR.`gere_id` LEFT JOIN `tb_gestion_encuesta_regional_czonal` AS TCZ ON `tb_gestion_alertas`.`ga_centro_zonal`=TCZ.`gercz_id` WHERE `ga_afecta_linea_tecnica`='".$afectalt."' AND `ga_fecha_require_regional`<>'' AND `ga_fecha_alerta`>='".$fecha_inicio."' AND `ga_fecha_alerta`<='".$fecha_fin." 23:59:59' GROUP BY `ga_regional`, `ga_centro_zonal` ORDER BY TOTAL DESC, TR.`gere_regional`, TCZ.`gercz_centro_zonal`");
    $resultado_czonales = mysqli_fetch_all($consulta_czonales);
    
    for ($i=0; $i < count($resultado_czonales); $i++) { 
        $array_czonal[$resultado_czonales[$i][1]][]=$resultado_czonales[$i][3];
        $array_czonal_data[$resultado_czonales[$i][1]][$resultado_czonales[$i][3]]['cantidad']=$resultado_czonales[$i][4];

    }

    if (count($resultado_regionales)>0) {
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
    }

    $consulta_accion_mejora = mysqli_query($enlace_db, "SELECT `ga_accion_mejora`, COUNT(`ga_id`) FROM `tb_gestion_alertas` WHERE `ga_afecta_linea_tecnica`='Si' AND `ga_accion_mejora`<>'' AND `ga_fecha_alerta`>='".$fecha_inicio."' AND `ga_fecha_alerta`<='".$fecha_fin." 23:59:59' GROUP BY `ga_accion_mejora`");
    $resultado_accion_mejora = mysqli_fetch_all($consulta_accion_mejora);

    $array_accion_mejora['Si']=0;
    $array_accion_mejora['No']=0;

    for ($i=0; $i < count($resultado_accion_mejora); $i++) { 
        $array_accion_mejora[$resultado_accion_mejora[$i][0]]+=$resultado_accion_mejora[$i][1];
    }

    $consulta_afectalt = mysqli_query($enlace_db, "SELECT `ga_afecta_linea_tecnica`, COUNT(`ga_id`) FROM `tb_gestion_alertas` WHERE `ga_afecta_linea_tecnica`<>'' AND `ga_estado`<>'Pendiente RN2' AND `ga_fecha_alerta`>='".$fecha_inicio."' AND `ga_fecha_alerta`<='".$fecha_fin." 23:59:59' GROUP BY `ga_afecta_linea_tecnica`");
    $resultado_afectalt = mysqli_fetch_all($consulta_afectalt);

    $array_afectalt['Si']=0;
    $array_afectalt['No']=0;

    for ($i=0; $i < count($resultado_afectalt); $i++) { 
        $array_afectalt[$resultado_afectalt[$i][0]]+=$resultado_afectalt[$i][1];
    }
?>
<!DOCTYPE html>
<html lang="ES">
<head>
	<?php
        include("../config/configuracion_estilos.php");
    ?>
    <script src="../Highcharts/code/highcharts.js"></script>
    <script src="../Highcharts/code/modules/drilldown.js"></script>
</head>
<body>
    <?php
        include("../menu_principal.php");
        include("../menu_header.php");
    ?>
    <div class="contenido">
        <div class="row" id="elemento_1">
            <div class="col-md-6 py-2">
                <form name="filtrado" action="" method="POST">
                    <div class="input-group">
                        <label class="pt-1 pr-2">Afecta LT:</label>
                        <select class="form-control form-control-sm" name="afectalt" id="afectalt" required style="max-width: 100px;">
                          <option value="Si" <?php if($afectalt=="Si"){ echo "selected"; } ?>>Si</option>
                          <option value="No" <?php if($afectalt=="No"){ echo "selected"; } ?>>No</option>
                        </select>
                        <label class="pt-1 pr-2 pl-2">Inicio:</label>
                        <input type="date" name="fecha_inicio" value='<?php if (isset($_POST["filtro"])) { echo $_POST['fecha_inicio']; } else {if($fecha_inicio!="null"){echo $fecha_inicio;}} ?>' class="form-control" required autofocus>
                        <label class="pt-1 pr-2 pl-2">Fin:</label>
                        <input type="date" name="fecha_fin" value='<?php if (isset($_POST["filtro"])) { echo $_POST['fecha_fin']; } else {if($fecha_fin!="null"){echo $fecha_fin;}} ?>' class="form-control" required>
                        <span class="input-group-btn">
                        <button class="btn btn-corp" type="submit" name="filtro"><span class="fas fa-search"></span></button>
                        </span>
                    </div>
                </form>
            </div>
            <div class="col-md-9 py-2">
                
            </div>
        </div>
        <div class="row justify-content-center">
            <div class="col-md-3">
                <?php echo $lista_informe; ?>
            </div>
            <div class="col-md-9">
                <div class="row">
                    <div class="col-md-12">
                        <div id="gra_regionales" style="min-width: 310px; height: 300px; margin: 0 auto;"></div>
                    </div>
                    <div class="col-md-6">
                        <div id="gra_accion_mejora" style="min-width: 310px; height: 300px; max-width: 600px; margin: 0 auto;"></div>
                    </div>
                    <div class="col-md-6">
                        <div id="gra_afectalt" style="min-width: 310px; height: 300px; max-width: 600px; margin: 0 auto;"></div>
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
        Highcharts.chart('gra_regionales', {
            chart: {
                type: 'column'
            },
            title: {
                text: 'ALERTAS REGIONAL/CENTRO ZONAL',
                style: {
                    fontSize: '14px'
                }
            },
            credits: {
                enabled: false
            },
            subtitle: {
                text: null
            },
            xAxis: {
                type: 'category'
            },
            yAxis: {
                title: {
                    text: 'Cantidad Alertas'
                }

            },
            legend: {
                enabled: false
            },
            plotOptions: {
                series: {
                    borderWidth: 0,
                    dataLabels: {
                        enabled: true,
                        format: '{point.y:.0f}'
                    }
                }
            },

            tooltip: {
                pointFormat: 'Cantidad: <b>{point.y}</b>'
            },

            series: [
                {
                    name: "Regionales",
                    colorByPoint: true,
                    data: [
                        <?php for ($i=0; $i < count($resultado_regionales); $i++): ?>
                            {
                                name: "<?php echo $resultado_regionales[$i][1]; ?>",
                                y: <?php echo $resultado_regionales[$i][2]; ?>,
                                drilldown: "<?php echo $resultado_regionales[$i][1]; ?>"
                            },
                        <?php endfor; ?>
                    ]
                }
            ],
            drilldown: {
                series: [
                    <?php for ($i=0; $i < count($resultado_regionales); $i++): ?>
                        {
                            name: "<?php echo $resultado_regionales[$i][1]; ?>",
                            id: "<?php echo $resultado_regionales[$i][1]; ?>",
                            data: [
                                <?php for ($j=0; $j < count($array_czonal[$resultado_regionales[$i][1]]); $j++): ?>
                                    [
                                        "<?php echo $array_czonal[$resultado_regionales[$i][1]][$j]; ?>",
                                        <?php echo $array_czonal_data[$resultado_regionales[$i][1]][$array_czonal[$resultado_regionales[$i][1]][$j]]['cantidad']; ?>
                                    ],
                                <?php endfor; ?>
                            ]
                        },
                    <?php endfor; ?>
                ]
            }
        });

        Highcharts.chart('gra_accion_mejora', {
            chart: {
                plotBackgroundColor: null,
                plotBorderWidth: null,
                plotShadow: false,
                type: 'pie'
            },
            title: {
                text: 'APLICA ACCIÓN DE MEJORA',
                style: {
                    fontSize: '14px'
                }
            },
            credits: {
                enabled: false
            },
            subtitle: {
                text: null
            },
            tooltip: {
                pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b><br>Cantidad: <b>{point.y}</b>'
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true,
                        distance: 5,
                        format: '{point.percentage:.1f} % <br>{point.y}'
                    },
                    showInLegend: true
                }
            },
            series: [{
                name: 'Porcentaje',
                colorByPoint: true,
                data: [{
                    name: 'Acción de mejora',
                    y: <?php echo $array_accion_mejora['Si']; ?>,
                    sliced: true,
                    selected: true
                }, {
                    name: 'No aplica',
                    y: <?php echo $array_accion_mejora['No']; ?>
                }]
            }]
        });

        Highcharts.chart('gra_afectalt', {
            chart: {
                plotBackgroundColor: null,
                plotBorderWidth: null,
                plotShadow: false,
                type: 'pie'
            },
            title: {
                text: 'AFECTA LÍNEA TÉCNICA',
                style: {
                    fontSize: '14px'
                }
            },
            credits: {
                enabled: false
            },
            subtitle: {
                text: null
            },
            tooltip: {
                pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b><br>Cantidad: <b>{point.y}</b>'
            },
            plotOptions: {
                pie: {
                    allowPointSelect: true,
                    cursor: 'pointer',
                    dataLabels: {
                        enabled: true,
                        distance: 5,
                        format: '{point.percentage:.1f} % <br>{point.y}'
                    },
                    showInLegend: true
                }
            },
            series: [{
                name: 'Porcentaje',
                colorByPoint: true,
                data: [{
                    name: 'Afecta LT',
                    y: <?php echo $array_afectalt['Si']; ?>,
                    sliced: true,
                    selected: true
                }, {
                    name: 'No afecta LT',
                    y: <?php echo $array_afectalt['No']; ?>
                }]
            }]
        });
    </script>
</body>
</html>