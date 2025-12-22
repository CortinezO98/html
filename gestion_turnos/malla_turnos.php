<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Gestión Turnos-Malla";

	require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");
    require_once("../config/validar_festivos.php");

    /*DEFINICIÓN DE VARIABLES*/
    $titulo_header = "Gestión Turnos | Malla de Turnos";

    unset($_SESSION['registro_cargue_base']);
    unset($_SESSION['malla_temporal']);

    // Inicializa variable tipo array
    $data_consulta=array();
    $data_consulta_supervisor=array();
    $data_consulta_usuarios=array();
    
    // Ejemplo filtro campo buscar
    if (isset($_POST["filtro"])) {
        $filtro_permanente=validar_input($_POST['id_filtro']);
        $FechaInicio = $_POST["filtro_fecha"];
        $filtro_operacion = $_POST["operacion"];
    } else {
        $filtro_permanente=validar_input($_GET['id']);
        $FechaInicio = base64_decode($_GET['fechainicio']);
        $filtro_operacion = base64_decode($_GET["operacion"]);
    }

    if ($filtro_operacion!='Todas') {
        $filtro_usuario_operacion='AND (`usu_campania`=?)';
        array_push($data_consulta_usuarios, $filtro_operacion);
    }

    //AGREGAR NÚMERO AL DÍA, SEGÚN SEMANA SELECCIONADA
    $dias_semana = array();
    for ($i=0; $i < 7; $i++) { 
        array_push($dias_semana, date("Y-m-d", strtotime("first day", strtotime($FechaInicio . $i))));
    }
    $fecha_fin_consulta = date("Y-m-d", strtotime("+ 1 day", strtotime($dias_semana[6])))." 23:59:59";

    if ($perfil_modulo=="Administrador" OR $perfil_modulo=="Gestor" OR $perfil_modulo=="Formador" OR $perfil_modulo=="Visitante" OR $perfil_modulo=="Cliente" OR $perfil_modulo=="Usuario") {
        $filtro_supervisor="";
    } elseif($perfil_modulo=="Supervisor"){
        $filtro_supervisor=" AND (`usu_supervisor`=?)";
        array_push($data_consulta_supervisor, $_SESSION["usu_id"]);
        array_push($data_consulta_usuarios, $_SESSION["usu_id"]);
    }

    $consulta_string_areas="SELECT DISTINCT `usu_campania`, TC.`ac_nombre_campania` FROM `tb_administrador_usuario` LEFT JOIN `tb_administrador_campania` AS TC ON `tb_administrador_usuario`.`usu_campania`=TC.`ac_id` WHERE `usu_estado`<>'Retirado' ".$filtro_supervisor." ORDER BY TC.`ac_nombre_campania` ASC";
    $consulta_registros_areas = $enlace_db->prepare($consulta_string_areas);
    if (count($data_consulta_supervisor)>0) {
        $consulta_registros_areas->bind_param(str_repeat("s", count($data_consulta_supervisor)), ...$data_consulta_supervisor);
    }
        
    $consulta_registros_areas->execute();
    $resultado_registros_areas = $consulta_registros_areas->get_result()->fetch_all(MYSQLI_NUM);


    // Valida que filtro se deba ejecutar
    if ($filtro_permanente!="null" AND $filtro_permanente!="") {
        $filtro_buscar="AND (`usu_id` LIKE ? OR `usu_nombres_apellidos` LIKE ?)";

        //Contar catidad de variables a filtrar
        $cantidad_filtros=count(explode('?', $filtro_buscar))-1;

        //Agregar catidad de variables a filtrar a data consulta
        for ($i=0; $i < $cantidad_filtros; $i++) { 
            array_push($data_consulta_usuarios, "%$filtro_permanente%");//Se agrega llave por ser variable evaluada en un like
        }
    }


    $consulta_string_usuarios="SELECT `usu_id`, `usu_nombres_apellidos`, `usu_estado`, TC.`ac_nombre_campania` FROM `tb_administrador_usuario` LEFT JOIN `tb_administrador_campania` AS TC ON `tb_administrador_usuario`.`usu_campania`=TC.`ac_id` WHERE `usu_estado`<>'Retirado' ".$filtro_usuario_operacion." ".$filtro_supervisor." ".$filtro_buscar." ORDER BY TC.`ac_nombre_campania` ASC, `usu_nombres_apellidos` ASC";
    $consulta_registros_usuarios = $enlace_db->prepare($consulta_string_usuarios);
    if (count($data_consulta_usuarios)>0) {
        $consulta_registros_usuarios->bind_param(str_repeat("s", count($data_consulta_usuarios)), ...$data_consulta_usuarios);
    }
    $consulta_registros_usuarios->execute();
    $resultado_registros_usuarios = $consulta_registros_usuarios->get_result()->fetch_all(MYSQLI_NUM);

    $filtro_usuarios_turno='';
    if (count($resultado_registros_usuarios)>0) {
        for ($i=0; $i < count($resultado_registros_usuarios); $i++) { 
            $filtro_usuarios_turno.="`cotm_usuario`='".$resultado_registros_usuarios[$i][0]."' OR ";
        }
        $filtro_usuarios_turno='AND ('.substr($filtro_usuarios_turno, 0, -4).')';
    }

    //CONSULTA TURNO PROGRAMADO
    $consulta_inicio_turnos_programados = mysqli_query($enlace_db, "SELECT `cotm_id`, `cotm_usuario`, `cotm_tipo`, `cotm_inicio`, `cotm_fin`, `cotm_duracion`, `cotm_jornada`, `cotm_observaciones_inicio`, `cotm_observaciones_fin`, `cotm_registro_fecha`, `cotm_estado` FROM `tb_control_turno_malla` WHERE `cotm_inicio`>='".$dias_semana[0]."' AND `cotm_inicio`<='".$fecha_fin_consulta."' ".$filtro_usuarios_turno." ORDER BY `cotm_usuario`, `cotm_inicio`");
    $resultado_inicio_turnos_programados = mysqli_fetch_all($consulta_inicio_turnos_programados);

    for ($i=0; $i < count($resultado_inicio_turnos_programados); $i++) { 
        $fecha_turno=date('Y-m-d', strtotime($resultado_inicio_turnos_programados[$i][3]));
        if ($resultado_inicio_turnos_programados[$i][2]=="turno") {
            $hora_inicio=date('H:i', strtotime($resultado_inicio_turnos_programados[$i][3]));
            $hora_fin=substr($resultado_inicio_turnos_programados[$i][4], 11, 5);

            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['turno'][$fecha_turno]=$hora_inicio.'-'.$hora_fin;
            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['turno_descripcion'][$fecha_turno]=$resultado_inicio_turnos_programados[$i][3].' A '.$resultado_inicio_turnos_programados[$i][4];
            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['color'][$fecha_turno]='';
            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['observaciones'][$fecha_turno]=$resultado_inicio_turnos_programados[$i][10];
            $array_turnos_malla[]=$hora_inicio.'-'.$hora_fin;
            $array_turnos_diario[$fecha_turno][]=$hora_inicio.'-'.$hora_fin;
            $array_turnos_diario_cont[$fecha_turno][$hora_inicio.'-'.$hora_fin]+=1;
            $array_turnos_detalle[$hora_inicio.'-'.$hora_fin]['descripcion']=$hora_inicio.'-'.$hora_fin;
        } else {
            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['turno'][$fecha_turno]=$array_convenciones[$resultado_inicio_turnos_programados[$i][2]];
            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['turno_descripcion'][$fecha_turno]=$array_convenciones[$resultado_inicio_turnos_programados[$i][2]];
            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['color'][$fecha_turno]=$array_convenciones_color[$resultado_inicio_turnos_programados[$i][2]];
            $array_turno_programado[$resultado_inicio_turnos_programados[$i][1]]['observaciones'][$fecha_turno]=$resultado_inicio_turnos_programados[$i][10];
            $array_turnos_malla[]=$array_convenciones[$resultado_inicio_turnos_programados[$i][2]];
            $array_turnos_diario[$fecha_turno][]=$array_convenciones[$resultado_inicio_turnos_programados[$i][2]];
            $array_turnos_diario_cont[$fecha_turno][$array_convenciones[$resultado_inicio_turnos_programados[$i][2]]]+=1;
            $array_turnos_detalle[$array_convenciones[$resultado_inicio_turnos_programados[$i][2]]]['descripcion']=$array_convenciones[$resultado_inicio_turnos_programados[$i][2]];
        }
    }
    $array_turnos_malla=array_values(array_unique($array_turnos_malla));
    sort($array_turnos_malla);

    //llamamos la clase festivos
    $dias_festivos = new festivos();
?>
<!DOCTYPE html>
<html lang="ES">
<head>
	<?php
        include("../config/configuracion_estilos.php");
    ?>
    <style type="text/css">
        .festivo_domingo {
            background-color: rgba(180,4,4,0.2);
        }
    </style>
</head>
<body>
    <?php
        include("../menu_principal.php");
        include("../menu_header.php");
    ?>
    <div class="contenido">
        <div class="row" id="elemento_1">
            <div class="col-md-7 py-2">
                <form name="filtrado" action="" method="POST">
                    <div class="input-group">
                        <label class="pr-1 pt-1">Campaña:</label>
                        <select class="form-control form-control-sm" name="operacion" id="operacion" required style="max-width: 300px;">
                            <option value="Todas" <?php if($filtro_operacion=="Todas"){ echo "selected"; } ?>>Todas</option>
                            <?php for ($i=0; $i < count($resultado_registros_areas); $i++): ?>
                                <option value="<?php echo $resultado_registros_areas[$i][0]; ?>" <?php if($filtro_operacion==$resultado_registros_areas[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_areas[$i][1]; ?></option>
                            <?php endfor; ?>
                        </select>
                        <input type="week" class="form-control form-control-sm" name="filtro_fecha" value='<?php echo $FechaInicio; ?>' placeholder="Búsqueda" class="form-control" required autofocus>
                        <input type="text" class="form-control form-control-sm" name="id_filtro" value='<?php if (isset($_POST["filtro"])) { echo $_POST['id_filtro']; } else {if($filtro_permanente!="null"){echo $filtro_permanente;}} ?>' placeholder="Búsqueda" class="form-control">
                        <span class="input-group-btn">
                            <button class="btn btn-corp" type="submit" name="filtro"><span class="fas fa-search"></span></button>
                            <a href="malla_turnos.php?fechainicio=<?php echo base64_encode(date('Y')."-W".date('W'));?>&operacion=<?php echo base64_encode('Todas'); ?>&id=null" class="btn btn-corp"><span class="fas fa-sync-alt"></span></a>
                        </span>
                    </div>
                </form>
            </div>
            <div class="col-md-5 py-2">
                <?php if($perfil_modulo=="Administrador" OR $perfil_modulo=="Gestor"): ?>
                    <button type="button" data-toggle="modal" class='btn btn-corp menu float-right' data-target="#dataexport"><span class="fas fa-file-excel float-left"></span></button>
                <?php endif; ?>
                <a href="malla_turnos_cambio.php?pagina=1&id=null&fechainicio=<?php echo base64_encode(date('Y-m'));?>&operacion=<?php echo base64_encode('Todas'); ?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-retweet"></span></div><div class="pl-2 menu_res float-left">Cambio Turno</div></a>
                <a href="malla_turnos_novedades.php?pagina=1&id=null&fechainicio=<?php echo base64_encode(date('Y-m'));?>&operacion=<?php echo base64_encode('Todas'); ?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-bell"></span></div><div class="pl-2 menu_res float-left">Novedades</div></a>
                <a href="malla_turnos.php?fechainicio=<?php echo base64_encode(date('Y')."-W".date('W'));?>&operacion=<?php echo base64_encode('Todas'); ?>&id=null" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-calendar-week"></span></div><div class="pl-2 menu_res float-left">Malla Turnos</div></a>
                <?php if($perfil_modulo=="Administrador" OR $perfil_modulo=="Gestor"): ?>
                    <a href="malla_turnos_configuracion_recargos.php?pagina=1&id=null&fechainicio=<?php echo base64_encode(date('Y-m'));?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-user-cog"></span></div></a>
                    <a href="malla_turnos_cargar.php" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-upload"></span></div></a>
                <?php endif; ?>
            </div>
        </div>
        <div class="row" id="tabla_fixed">
            <div class="col-md-12">
                <?php if (count($resultado_registros_usuarios)>0): ?>
                    <div id="table-fixed" class="table-responsive table-fixed">
                        <table class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <!-- <tr>
                                    <th class="align-middle text-left font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 250px;">Total</th>
                                    <th class="align-middle font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 100px; width: 120px;">
                                        <?php
                                            if (isset($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[0]))])) {
                                                $total_diario=array_sum($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[0]))]);
                                            } else {
                                                $total_diario=0;
                                            }
                                            echo $total_diario;
                                        ?>
                                    </th>
                                    <th class="align-middle font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 100px; width: 120px;">
                                        <?php
                                            if (isset($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[1]))])) {
                                                $total_diario=array_sum($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[1]))]);
                                            } else {
                                                $total_diario=0;
                                            }
                                            echo $total_diario;
                                        ?>
                                    </th>
                                    <th class="align-middle font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 100px; width: 120px;">
                                        <?php
                                            if (isset($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[2]))])) {
                                                $total_diario=array_sum($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[2]))]);
                                            } else {
                                                $total_diario=0;
                                            }
                                            echo $total_diario;
                                        ?>
                                    </th>
                                    <th class="align-middle font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 100px; width: 120px;">
                                        <?php
                                            if (isset($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[3]))])) {
                                                $total_diario=array_sum($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[3]))]);
                                            } else {
                                                $total_diario=0;
                                            }
                                            echo $total_diario;
                                        ?>
                                    </th>
                                    <th class="align-middle font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 100px; width: 120px;">
                                        <?php
                                            if (isset($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[4]))])) {
                                                $total_diario=array_sum($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[4]))]);
                                            } else {
                                                $total_diario=0;
                                            }
                                            echo $total_diario;
                                        ?>
                                    </th>
                                    <th class="align-middle font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 100px; width: 120px;">
                                        <?php
                                            if (isset($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[5]))])) {
                                                $total_diario=array_sum($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[5]))]);
                                            } else {
                                                $total_diario=0;
                                            }
                                            echo $total_diario;
                                        ?>
                                    </th>
                                    <th class="align-middle font-size-10 py-0 px-1 font-weight-bold radius-5" style="min-width: 100px; width: 120px;">
                                        <?php
                                            if (isset($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[6]))])) {
                                                $total_diario=array_sum($array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[6]))]);
                                            } else {
                                                $total_diario=0;
                                            }
                                            echo $total_diario;
                                        ?>
                                    </th>
                                </tr> -->
                                <?php for ($i=0; $i < count($array_turnos_malla); $i++): ?>
                                    <?php
                                        $id_turno_con=$array_turnos_malla[$i];
                                    ?>
                                    <!-- <tr>
                                        <td class="align-middle text-left font-size-10 py-0 px-1 radius-5" style="min-width: 250px;">
                                            <?php echo $array_turnos_detalle[$id_turno_con]['descripcion']; ?>
                                        </td>
                                        <td class="align-middle text-center font-weight-bold font-size-10 py-0 px-1 radius-5" style="min-width: 100px; width: 120px;">
                                            <?php if(isset($array_turnos_diario[date("Y-m-d", strtotime($dias_semana[0]))])): ?>
                                                <?php echo $array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[0]))][$id_turno_con]; ?>        
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle text-center font-weight-bold font-size-10 py-0 px-1 radius-5" style="min-width: 100px; width: 120px;">
                                            <?php if(isset($array_turnos_diario[date("Y-m-d", strtotime($dias_semana[1]))])): ?>
                                                <?php echo $array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[1]))][$id_turno_con]; ?>        
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle text-center font-weight-bold font-size-10 py-0 px-1 radius-5" style="min-width: 100px; width: 120px;">
                                            <?php if(isset($array_turnos_diario[date("Y-m-d", strtotime($dias_semana[2]))])): ?>
                                                <?php echo $array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[2]))][$id_turno_con]; ?>        
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle text-center font-weight-bold font-size-10 py-0 px-1 radius-5" style="min-width: 100px; width: 120px;">
                                            <?php if(isset($array_turnos_diario[date("Y-m-d", strtotime($dias_semana[3]))])): ?>
                                                <?php echo $array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[3]))][$id_turno_con]; ?>        
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle text-center font-weight-bold font-size-10 py-0 px-1 radius-5" style="min-width: 100px; width: 120px;">
                                            <?php if(isset($array_turnos_diario[date("Y-m-d", strtotime($dias_semana[4]))])): ?>
                                                <?php echo $array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[4]))][$id_turno_con]; ?>        
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle text-center font-weight-bold font-size-10 py-0 px-1 radius-5" style="min-width: 100px; width: 120px;">
                                            <?php if(isset($array_turnos_diario[date("Y-m-d", strtotime($dias_semana[5]))])): ?>
                                                <?php echo $array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[5]))][$id_turno_con]; ?>        
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle text-center font-weight-bold font-size-10 py-0 px-1 radius-5" style="min-width: 100px; width: 120px;">
                                            <?php if(isset($array_turnos_diario[date("Y-m-d", strtotime($dias_semana[6]))])): ?>
                                                <?php echo $array_turnos_diario_cont[date("Y-m-d", strtotime($dias_semana[6]))][$id_turno_con]; ?>        
                                            <?php endif; ?>
                                        </td>
                                    </tr> -->
                                <?php endfor; ?>
                                <tr>
                                    <th class="align-middle" style="min-width: 80px;">Doc. Identidad</th>
                                    <th class="align-middle" style="min-width: 270px;">Usuario</th>
                                    <th class="align-middle" style="min-width: 230px;">Campaña</th>
                                    <th class="align-middle" style="min-width: 100px; width: 120px;">
                                        Lunes <?php echo date("d", strtotime($dias_semana[0])); ?>, <?php echo $array_mes_min[intval(date("m", strtotime($dias_semana[0])))]; ?>
                                    </th>
                                    <th class="align-middle" style="min-width: 100px; width: 120px;">
                                        Martes <?php echo date("d", strtotime($dias_semana[1])); ?>, <?php echo $array_mes_min[intval(date("m", strtotime($dias_semana[1])))]; ?>
                                    </th>
                                    <th class="align-middle" style="min-width: 100px; width: 120px;">
                                        Miércoles <?php echo date("d", strtotime($dias_semana[2])); ?>, <?php echo $array_mes_min[intval(date("m", strtotime($dias_semana[2])))]; ?>
                                    </th>
                                    <th class="align-middle" style="min-width: 100px; width: 120px;">
                                        Jueves <?php echo date("d", strtotime($dias_semana[3])); ?>, <?php echo $array_mes_min[intval(date("m", strtotime($dias_semana[3])))]; ?>
                                    </th>
                                    <th class="align-middle" style="min-width: 100px; width: 120px;">
                                        Viernes <?php echo date("d", strtotime($dias_semana[4])); ?>, <?php echo $array_mes_min[intval(date("m", strtotime($dias_semana[4])))]; ?>
                                    </th>
                                    <th class="align-middle" style="min-width: 100px; width: 120px;">
                                        Sábado <?php echo date("d", strtotime($dias_semana[5])); ?>, <?php echo $array_mes_min[intval(date("m", strtotime($dias_semana[5])))]; ?>
                                    </th>
                                    <th class="align-middle" style="min-width: 100px; width: 120px;">
                                        Domingo <?php echo date("d", strtotime($dias_semana[6])); ?>, <?php echo $array_mes_min[intval(date("m", strtotime($dias_semana[6])))]; ?>
                                    </th>
                                </tr>
                            </thead>    
                            <tbody>    
                                <?php
                                    for ($i=0; $i<count($resultado_registros_usuarios); $i++) {//cantidad usuarios
                                ?>
                                <tr>
                                    <td class="align-middle"><?php echo $resultado_registros_usuarios[$i][0]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros_usuarios[$i][1]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros_usuarios[$i][3]; ?></td>
                                    <?php
                                        for ($j=0; $j<7; $j++) {//cantidad días a mostrar (7)
                                            if ($resultado_inicio_turnos_programados[$i][2]=="turno") {
                                                $tipo_turno='Turno'."-".$resultado_inicio_turnos_programados[$i][6];
                                                $color_turno=$array_convenciones_color[$resultado_inicio_turnos_programados[$i][6]];
                                            } else {
                                                $tipo_turno=$array_convenciones[$resultado_inicio_turnos_programados[$i][2]];
                                                $color_turno=$array_convenciones_color[$resultado_inicio_turnos_programados[$i][2]];
                                            }
                                    ?>
                                    <td class="align-middle text-center <?php echo validarFestivo($dias_semana[$j]); ?>" title="<?php echo $array_turno_programado[$resultado_registros_usuarios[$i][0]]['turno_descripcion'][$dias_semana[$j]]; ?>" style="background-color: <?php echo $array_turno_programado[$resultado_registros_usuarios[$i][0]]['color'][$dias_semana[$j]]; ?>; <?php echo ($array_turno_programado[$resultado_registros_usuarios[$i][0]]['color'][$dias_semana[$j]]!="") ? 'color: #FFF;' : ''; ?>">
                                        <?php echo $array_turno_programado[$resultado_registros_usuarios[$i][0]]['descripcion'][$dias_semana[$j]]; ?>
                                        <?php if($array_turno_programado[$resultado_registros_usuarios[$i][0]]['observaciones'][$dias_semana[$j]]=='Novedad'): ?>
                                            <span class="fas fa-bell"></span>
                                        <?php elseif($array_turno_programado[$resultado_registros_usuarios[$i][0]]['observaciones'][$dias_semana[$j]]=='Cambio turno'): ?>
                                            <span class="fas fa-retweet"></span>
                                        <?php endif; ?>
                                        <?php echo '<b>'.$array_turno_programado[$resultado_registros_usuarios[$i][0]]['turno'][$dias_semana[$j]].'</b>'; ?>
                                    </td>
                                    <?php
                                        }
                                    ?>
                                </tr>
                                <?php
                                    }
                                ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="alert alert-warning p-1 font-size-11">
                        <span class="fas fa-exclamation-triangle"></span> No se encontraron registros
                    </p>
                <?php endif; ?>
            </div>
        </div>
            
    </div>
    <?php
        include("../footer.php");
        include("../config/configuracion_js.php");
        include("malla_turnos_reporte.php");
    ?>
</body>
</html>