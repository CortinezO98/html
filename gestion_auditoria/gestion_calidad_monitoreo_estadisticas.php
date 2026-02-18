<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Auditoría-Monitoreos";

    require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");

    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    /*DEFINICIÓN DE VARIABLES*/
    $array_meses=[1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril", 5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto", 9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"];
    $bandeja = validar_input(base64_decode($_GET['bandeja'] ?? ''));
    $titulo_header = "Monitoreos | Estadísticas";
    $pagina = validar_input($_GET['pagina'] ?? '1');

    unset($_SESSION['monitoreo_creado']);
    unset($_SESSION["mon_informacion"]);
    unset($_SESSION['registro_creado_cambio_estado']);
    unset($_SESSION['monitoreo_registro_eliminado']);

    // Inicializa variable tipo array
    $data_consulta = array();

    // Ejemplo filtro campo buscar
    if (isset($_POST["filtro"])) {
        $pagina = 1;
        $filtro_permanente = validar_input($_POST['id_filtro'] ?? '');
        $filtro_mes = " AND TMI.`gcm_registro_fecha` LIKE ?";
        array_push($data_consulta, "$filtro_permanente%");
    } else {
        $filtro_permanente = date('Y-m');
        $filtro_mes = " AND TMI.`gcm_registro_fecha` LIKE ?";
        array_push($data_consulta, "$filtro_permanente%");
    }

    $consulta_string="SELECT
        TMC.`gcm_id`,
        TM.`gcm_nombre_matriz`,
        TUA.`usu_nombres_apellidos`,
        TMC.`gcm_fecha_hora_gestion`,
        TMC.`gcm_fecha_hora_cierre`,
        TMC.`gcm_tipo_monitoreo`,
        TMC.`gcm_skill_interaccion`,
        TMC.`gcm_tipo_gestion`,
        TMC.`gcm_segmento`,
        TMC.`gcm_id_sim`,
        TMC.`gcm_id_ani`,
        TMC.`gcm_observaciones_monitoreo`,
        TMC.`gcm_nota_enc`,
        TMC.`gcm_nota_ecn`,
        TMC.`gcm_nota_ecuf`,
        TMC.`gcm_estado`,
        TUR.`usu_nombres_apellidos`,
        TMC.`gcm_registro_fecha`,
        TP.`ap_nombre_piloto`,
        TS.`usu_nombres_apellidos`,
        TMC.`gcm_nota_general`,
        TMC.`gcm_nota_enc_estado`,
        TMC.`gcm_nota_ecn_estado`,
        TMC.`gcm_nota_ecuf_estado`,
        TMC.`gcm_afectacion_1`,
        TMC.`gcm_afectacion_2`,
        TMC.`gcm_fecha_monitoreo`,
        TMC.`gcm_registro_usuario`,
        TM.`gcm_observaciones`,
        TSA.`usu_nombres_apellidos`,
        TMI.`gcm_registro_fecha`
    FROM `tb_gestion_auditoria_monitoreo` AS TMC
        LEFT JOIN `tb_gestion_calidad_matriz` AS TM ON TMC.`gcm_matriz`=TM.`gcm_id`
        LEFT JOIN `tb_administrador_usuario` AS TUR ON TMC.`gcm_registro_usuario`=TUR.`usu_id`
        LEFT JOIN `tb_administrador_usuario` AS TUA ON TMC.`gcm_analista`=TUA.`usu_id`
        LEFT JOIN `tb_administrador_piloto` AS TP ON TUA.`usu_piloto`=TP.`ap_id`
        LEFT JOIN `tb_administrador_usuario` AS TS ON TMC.`gcm_responsable`=TS.`usu_id`
        LEFT JOIN `tb_administrador_usuario` AS TSA ON TUA.`usu_supervisor`=TSA.`usu_id`
        LEFT JOIN `tb_gestion_calidad_monitoreo` AS TMI ON TMC.`gcm_id`=TMI.`gcm_id`
    WHERE 1=1 ".$filtro_mes."
    ORDER BY `gcm_id`";

    $consulta_registros = $enlace_db->prepare($consulta_string);
    $consulta_registros->bind_param(str_repeat("s", count($data_consulta)), ...$data_consulta);
    $consulta_registros->execute();
    $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

    function fechas_semanas_mes($year, $month) {
        $fechas_semanas = [];

        // Obtener el número de días en el mes
        $num_dias = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);

        // Obtener la fecha del primer día del mes
        $fecha_actual = new DateTime("$year-$month-01");
        $fecha_inicio_mes = new DateTime("$year-$month-01");
        $fecha_fin_mes = new DateTime("$year-$month-$num_dias");

        // Iterar hasta el último día del mes
        while ($fecha_actual->format('n') == (int)$month) {
            // Obtener el número de semana de la fecha actual
            $num_semana = $fecha_actual->format('W');

            // Obtener la fecha del primer día de la semana
            $primer_dia_semana = clone $fecha_actual;
            $primer_dia_semana->modify('last sunday');

            // Obtener la fecha del último día de la semana
            $ultimo_dia_semana = clone $primer_dia_semana;
            $ultimo_dia_semana->modify('+6 days');

            $fecha_primer_dia_mes = $primer_dia_semana->format('Y-m-d');
            $fecha_inicio_mes_dia = $fecha_inicio_mes->format('Y-m-d');

            if ($fecha_inicio_mes_dia > $fecha_primer_dia_mes) {
                $primer_dia_mes = $fecha_inicio_mes_dia;
            } else {
                $primer_dia_mes = $fecha_primer_dia_mes;
            }

            $fecha_ultimo_dia_mes = $ultimo_dia_semana->format('Y-m-d');
            $fecha_fin_mes_dia = $fecha_fin_mes->format('Y-m-d');

            if ($fecha_ultimo_dia_mes > $fecha_fin_mes_dia) {
                $ultimo_dia_mes = $fecha_fin_mes_dia;
            } else {
                $ultimo_dia_mes = $fecha_ultimo_dia_mes;
            }

            // Añadir las fechas de inicio y fin de la semana al arreglo
            $fechas_semanas[$num_semana] = [
                'inicio' => $primer_dia_mes,
                'fin' => $ultimo_dia_mes,
                'numero' => $ultimo_dia_semana->format('W'),
                'total_muestras' => 0,
                'afectacion_1' => 0,
                'afectacion_2' => 0,
                'conciliado_cliente' => 0,
                'total_afectacion' => 0,
                'conciliado_proveedor' => 0
            ];

            // Avanzar al siguiente día
            $fecha_actual->modify('+1 day');
        }

        return $fechas_semanas;
    }

    // Especifica el año y el mes
    $year  = date('Y', strtotime($filtro_permanente));
    $month = date('m', strtotime($filtro_permanente));
    $fechas_semanas = fechas_semanas_mes($year, $month);

    $total_auditoria = 0;
    $afectacion_1 = 0;
    $afectacion_2 = 0;
    $conciliado_cliente = 0;

    for ($i=0; $i < count($resultado_registros); $i++) {
        // OJO: aquí usas índice 30 como fecha; mantengo tu lógica tal cual.
        $semana_numero = date('W', strtotime($resultado_registros[$i][30] ?? ''));

        // A prueba de warnings si por alguna razón no existe esa semana
        if (!isset($fechas_semanas[$semana_numero])) {
            // Si cae fuera, lo inicializamos para no romper
            $fechas_semanas[$semana_numero] = [
                'inicio' => '',
                'fin' => '',
                'numero' => $semana_numero,
                'total_muestras' => 0,
                'afectacion_1' => 0,
                'afectacion_2' => 0,
                'conciliado_cliente' => 0,
                'total_afectacion' => 0,
                'conciliado_proveedor' => 0
            ];
        }

        $fechas_semanas[$semana_numero]['total_muestras']++;

        // Mantengo tu condición (tal cual)
        if (($resultado_registros[$i][4] ?? null) == 0) {
            $fechas_semanas[$semana_numero]['afectacion_1']++;
        }

        if (($resultado_registros[$i][25] ?? '') == 'Si') {
            $fechas_semanas[$semana_numero]['afectacion_2']++;
        }

        if (($resultado_registros[$i][15] ?? '') == 'Conciliado Cliente') {
            $fechas_semanas[$semana_numero]['conciliado_cliente']++;
        }

        if (($resultado_registros[$i][15] ?? '') == 'Conciliado Proveedor') {
            $fechas_semanas[$semana_numero]['conciliado_proveedor']++;
        }

        if (($resultado_registros[$i][23] ?? null) == 0) {
            $fechas_semanas[$semana_numero]['total_afectacion']++;
            $conciliado_cliente++;
        }
    }

    $fechas_semanas = array_values($fechas_semanas);

    $ttotal_muestras = 0;
    $tafectacion_1 = 0;
    $tafectacion_2 = 0;
    $tconciliado_cliente = 0;
    $tconciliado_proveedor = 0;

    // ✅ ESTA era la variable que te faltaba:
    $ttransacciones_afectacion = 0;

    if (count($resultado_registros)>0) {
        $indicador = ($conciliado_cliente / count($resultado_registros)) * 100;
    } else {
        $indicador = 0;
    }
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include("../config/configuracion_estilos.php"); ?>
    <script src="../HighchartsGantt/code/highcharts.js"></script>
    <script src="../HighchartsGantt/code/highcharts-more.js"></script>
    <script src="../HighchartsGantt/code/modules/solid-gauge.js"></script>
</head>
<body>
<?php
    include("../menu_principal.php");
    include("../menu_header.php");
?>
<div class="contenido" style="background-color: #EEEEEE;">
    <div class="row" id="elemento_1">
        <div class="col-md-3 py-2">
            <form name="filtrado" action="" method="POST">
                <div class="input-group">
                    <input type="month" name="id_filtro"
                           value='<?php if (isset($_POST["filtro"])) { echo $_POST['id_filtro']; } else { if($filtro_permanente!="null"){ echo $filtro_permanente; } } ?>'
                           placeholder="Búsqueda" class="form-control" required autofocus>
                    <span class="input-group-btn">
                        <button class="btn btn-corp" type="submit" name="filtro"><span class="fas fa-search"></span></button>
                        <a href="gestion_calidad_monitoreo_estadisticas.php?pagina=1&id=null&bandeja=<?php echo base64_encode($bandeja); ?>" class="btn btn-corp"><span class="fas fa-sync-alt"></span></a>
                    </span>
                </div>
            </form>
        </div>

        <div class="col-md-9 py-2">
            <?php if($perfil_modulo=="Administrador" OR $perfil_modulo=="Gestor" OR $perfil_modulo=="Usuario"): ?>
                <a href="gestion_calidad_monitoreo.php?pagina=1&id=null&bandeja=<?php echo base64_encode('Mes Actual'); ?>" class="btn btn-corp menu float-right">
                    <div class="float-left"><span class="fas fa-clipboard-list"></span></div>
                    <div class="pl-2 menu_res float-left">Monitoreos Mes Actual</div>
                </a>

                <?php if($perfil_modulo=="Administrador"): ?>
                    <a href="gestion_calidad_monitoreo_estadisticas.php?pagina=<?php echo $pagina; ?>&id=<?php echo $filtro_permanente; ?>&bandeja=<?php echo base64_encode($bandeja); ?>" class="btn btn-corp menu float-right">
                        <div class="float-left"><span class="fas fa-chart-pie"></span></div>
                        <div class="pl-2 menu_res float-left">Estadísticas</div>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="col-md-12">
            <div class="row">
                <div class="col-md-6">
                    <div class="row p-1">
                        <div class="col-md-12 p-4 fondo-blanco radius-5">

                            <table class="table table-bordered table-striped table-hover table-sm">
                                <thead>
                                <tr>
                                    <th class="align-middle py-0 font-size-11 text-left">Parámetro</th>
                                    <th class="align-middle py-0 font-size-11">Descuento</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr><td class="align-middle py-0 font-size-11">5% <= ae < 10%</td><td class="align-middle py-0 font-size-11">3%</td></tr>
                                <tr><td class="align-middle py-0 font-size-11">10% <= ae < 15%</td><td class="align-middle py-0 font-size-11">5%</td></tr>
                                <tr><td class="align-middle py-0 font-size-11">15% <= ae < 20%</td><td class="align-middle py-0 font-size-11">7%</td></tr>
                                <tr><td class="align-middle py-0 font-size-11">ae >= 20%</td><td class="align-middle py-0 font-size-11">10%</td></tr>
                                </tbody>
                            </table>

                            <table class="table table-bordered table-striped table-hover table-sm">
                                <thead>
                                <tr>
                                    <th class="align-middle py-0 font-size-11" colspan="2">Fórmula Indicador</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr><td class="align-middle py-0 font-size-11 font-weight-bold" style="width:130px;">Período de medición</td><td class="align-middle py-0 font-size-11">Mensual</td></tr>
                                <tr><td class="align-middle py-0 font-size-11 font-weight-bold">ae</td><td class="align-middle py-0 font-size-11">Auditoría Entidad</td></tr>
                                <tr><td class="align-middle py-0 font-size-11 font-weight-bold">Te</td><td class="align-middle py-0 font-size-11">Transacciones auditadas por la Entidad Compradora con error crítico de usuario</td></tr>
                                <tr><td class="align-middle py-0 font-size-11 font-weight-bold">Tp</td><td class="align-middle py-0 font-size-11">Transacciones dentro de la muestra auditada por la Entidad que ya habían sido marcadas como transacciones con error crítico de usuario por el Proveedor</td></tr>
                                <tr><td class="align-middle py-0 font-size-11 font-weight-bold">Ne</td><td class="align-middle py-0 font-size-11">Cantidad de transacciones auditadas por la Entidad Compradora</td></tr>
                                </tbody>
                            </table>

                            <table class="table table-bordered table-striped table-hover table-sm">
                                <thead>
                                <tr>
                                    <th class="align-middle py-0 font-size-11">Semana</th>
                                    <th class="align-middle py-0 font-size-11" style="width: 100px;">Fechas</th>
                                    <th class="align-middle py-0 font-size-11">Cantidad</th>
                                    <th class="align-middle py-0 font-size-11">Resultados con afectaciones en ECUF 1ra revisión</th>
                                    <th class="align-middle py-0 font-size-11">Transacciones a conciliar</th>
                                    <th class="align-middle py-0 font-size-11">Resultado ICBF</th>
                                    <th class="align-middle py-0 font-size-11">Resultado IQ</th>
                                    <th class="align-middle py-0 font-size-11">Total Transacciones con afectación</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php for ($i=0; $i < count($fechas_semanas); $i++): ?>
                                    <?php
                                        $ttotal_muestras += ($fechas_semanas[$i]['total_muestras'] ?? 0);
                                        $tafectacion_1 += ($fechas_semanas[$i]['afectacion_1'] ?? 0);
                                        $tafectacion_2 += ($fechas_semanas[$i]['afectacion_2'] ?? 0);
                                        $tconciliado_cliente += ($fechas_semanas[$i]['conciliado_cliente'] ?? 0);
                                        $tconciliado_proveedor += ($fechas_semanas[$i]['conciliado_proveedor'] ?? 0);
                                        $ttransacciones_afectacion += ($fechas_semanas[$i]['total_afectacion'] ?? 0);
                                    ?>
                                    <tr>
                                        <td class="align-middle py-0 font-size-11 text-center font-weight-bold"><?php echo $i+1; ?></td>
                                        <td class="align-middle py-0 font-size-11 text-center"><?php echo ($fechas_semanas[$i]['inicio'] ?? '').'<br>A<br>'.($fechas_semanas[$i]['fin'] ?? ''); ?></td>
                                        <td class="align-middle py-0 font-size-11 text-center"><?php echo ($fechas_semanas[$i]['total_muestras'] ?? 0); ?></td>
                                        <td class="align-middle py-0 font-size-11 text-center"><?php echo ($fechas_semanas[$i]['afectacion_1'] ?? 0); ?></td>
                                        <td class="align-middle py-0 font-size-11 text-center"><?php echo ($fechas_semanas[$i]['afectacion_2'] ?? 0); ?></td>
                                        <td class="align-middle py-0 font-size-11 text-center"><?php echo ($fechas_semanas[$i]['conciliado_cliente'] ?? 0); ?></td>
                                        <td class="align-middle py-0 font-size-11 text-center"><?php echo ($fechas_semanas[$i]['conciliado_proveedor'] ?? 0); ?></td>
                                        <td class="align-middle py-0 font-size-11 text-center"><?php echo ($fechas_semanas[$i]['total_afectacion'] ?? 0); ?></td>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                                <thead>
                                <tr>
                                    <th class="align-middle py-0 font-size-11">Total</th>
                                    <th class="align-middle py-0 font-size-11 text-center"></th>
                                    <th class="align-middle py-0 font-size-11 text-center"><?php echo $ttotal_muestras; ?></th>
                                    <th class="align-middle py-0 font-size-11 text-center"><?php echo $tafectacion_1; ?></th>
                                    <th class="align-middle py-0 font-size-11 text-center"><?php echo $tafectacion_2; ?></th>
                                    <th class="align-middle py-0 font-size-11 text-center"><?php echo $tconciliado_cliente; ?></th>
                                    <th class="align-middle py-0 font-size-11 text-center"><?php echo $tconciliado_proveedor; ?></th>
                                    <th class="align-middle py-0 font-size-11 text-center"><?php echo $ttransacciones_afectacion; ?></th>
                                </tr>
                                </thead>
                            </table>

                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="row p-1">
                        <div class="col-md-12 p-1 fondo-blanco radius-5">
                            <div id="grafica_indicador"></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (count($resultado_registros)>0): ?>
            <?php else: ?>
                <p class="alert alert-warning">
                    <span class="fas fa-exclamation-triangle"></span> No se encontraron registros
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
    include("../footer.php");
    include("../config/configuracion_js.php");
?>

<script type="text/javascript">
    var gaugeOptions = {

        chart: {
            type: 'solidgauge'
        },

        title: null,

        pane: {
            center: ['50%', '85%'],
            size: '100%',
            startAngle: -90,
            endAngle: 90,
            background: {
                backgroundColor:
                    Highcharts.defaultOptions.legend.backgroundColor || '#EEE',
                innerRadius: '60%',
                outerRadius: '100%',
                shape: 'arc'
            }
        },

        tooltip: {
            enabled: false
        },

        // the value axis
        yAxis: {
            stops: [
                [0.05, '#55BF3B'],
                [0.10, '#DDDF0D'],
                [0.15, '#DF5353'],
                [0.20, '#DF5353']
            ],
            lineWidth: 0,
            minorTickInterval: null,
            tickAmount: 2,
            title: {
                y: -120
            },
            labels: {
                y: 16
            }
        },

        plotOptions: {
            solidgauge: {
                dataLabels: {
                    y: 5,
                    borderWidth: 0,
                    useHTML: true
                }
            }
        }
    };

    var chartSpeed = Highcharts.chart('grafica_indicador', Highcharts.merge(gaugeOptions, {
        yAxis: {
            min: 0,
            max: 100,
            title: {
                text: 'Indicador Auditoría'
            }
        },
        credits: {
            enabled: false
        },
        series: [{
            name: 'Indicador Auditoría',
            data: [<?php echo number_format($indicador, 2, '.', ''); ?>],
            dataLabels: {
                format:
                    '<div style="text-align:center">' +
                    '<span style="font-size:25px">{y}%</span><br/>' +
                    '<span style="font-size:12px;opacity:0.4">Resultado</span>' +
                    '</div>'
            },
            tooltip: {
                valueSuffix: ' Resultado'
            }
        }]
    }));
</script>
</body>
</html>
