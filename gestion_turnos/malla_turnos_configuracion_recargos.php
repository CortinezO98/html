<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Gestión Turnos-Malla";

	require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");
    require_once("../config/validar_festivos.php");

    /* ============================================================
       Helper seguro para bind_param con arrays (por referencias)
       - NO cambia el diseño
       - Evita errores raros con ...$array en bind_param
    ============================================================ */
    function bindParamsMysqli($stmt, $types, $params) {
        if (!$stmt) return false;
        $refs = [];
        $refs[] = $types;
        foreach ($params as $k => $v) {
            $refs[] = &$params[$k];
        }
        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    /*DEFINICIÓN DE VARIABLES*/
    $titulo_header = "Gestión Turnos | Configuración Recargos";

    // Página (asegura int)
    $pagina = isset($_GET['pagina']) ? (int)validar_input($_GET['pagina']) : 1;
    if ($pagina <= 0) { $pagina = 1; }

    // Operación (en esta vista se usa en paginación; evita undefined variable)
    $filtro_operacion = isset($_GET['operacion']) ? base64_decode($_GET['operacion']) : 'Todas';
    $filtro_operacion = validar_input((string)$filtro_operacion);

    unset($_SESSION['mturno_novedad_crear']);

    // Inicializa variable tipo array
    $data_consulta = [];
    $data_consulta_supervisor = []; // se deja por compatibilidad

    // Defaults seguros
    $filtro_permanente = "null";
    $FechaInicio = date('Y-m'); // YYYY-MM
    $filtro_buscar = "";
    $cantidad_filtros = 0;

    // Ejemplo filtro campo buscar
    if (isset($_POST["filtro"])) {
        $filtro_permanente = validar_input($_POST['id_filtro'] ?? '');
        $FechaInicio = validar_input($_POST["filtro_fecha"] ?? date('Y-m'));
    } else {
        $filtro_permanente = validar_input($_GET['id'] ?? 'null');

        if (isset($_GET['fechainicio'])) {
            $tmp = base64_decode($_GET['fechainicio']);
            if ($tmp) {
                $FechaInicio = validar_input($tmp);
            }
        }
    }

    // Valida formato del mes para evitar consultas raras
    if (!preg_match('/^\d{4}-\d{2}$/', $FechaInicio)) {
        $FechaInicio = date('Y-m');
    }

    // Primer parámetro SIEMPRE: mes
    $data_consulta[] = $FechaInicio;

    // Configuracón Paginación
    $registros_x_pagina = 50;
    $iniciar_pagina = ($pagina - 1) * $registros_x_pagina;

    // Valida que filtro se deba ejecutar
    if ($filtro_permanente !== "null" && $filtro_permanente !== "") {
        $filtro_buscar = "AND (`ctmcr_usuario` LIKE ? OR TU.`usu_nombres_apellidos` LIKE ? OR TC.`ac_nombre_campania` LIKE ?)";
        $cantidad_filtros = 3;

        for ($i=0; $i < $cantidad_filtros; $i++) {
            $data_consulta[] = "%{$filtro_permanente}%";
        }
    }

    // ============ CONSULTA CONTAR ============
    $registros_cantidad_total = 0;
    $numero_paginas = 1;
    $resultado_registros = [];
    $respuesta_accion = "";

    try {

        $consulta_contar_string = "
            SELECT COUNT(`ctmcr_id`)
            FROM `tb_control_turno_malla_configuracion_recargos`
            LEFT JOIN `tb_administrador_usuario` AS TU
                ON `tb_control_turno_malla_configuracion_recargos`.`ctmcr_usuario`=TU.`usu_id`
            LEFT JOIN `tb_administrador_usuario` AS TUR
                ON `tb_control_turno_malla_configuracion_recargos`.`ctmcr_registro_usuario`=TUR.`usu_id`
            LEFT JOIN `tb_administrador_campania` AS TC
                ON TU.`usu_campania`=TC.`ac_id`
            WHERE `ctmcr_mes`=? $filtro_buscar
        ";

        $consulta_contar_registros = $enlace_db->prepare($consulta_contar_string);
        if (!$consulta_contar_registros) {
            throw new Exception("Prepare COUNT falló: " . $enlace_db->error);
        }

        // Types: 1 string (mes) + filtros (strings)
        $types_count = "s" . str_repeat("s", $cantidad_filtros);
        bindParamsMysqli($consulta_contar_registros, $types_count, $data_consulta);

        $consulta_contar_registros->execute();
        $resultado_registros_contar = $consulta_contar_registros->get_result()->fetch_all(MYSQLI_NUM);

        $registros_cantidad_total = (int)($resultado_registros_contar[0][0] ?? 0);
        $numero_paginas = (int)ceil($registros_cantidad_total / $registros_x_pagina);
        if ($numero_paginas <= 0) $numero_paginas = 1;

        // Corrige navegación si viene página inválida (incluye fechainicio y operacion)
        if (!isset($_GET['pagina']) || ($pagina > $numero_paginas && $registros_cantidad_total > 0) || $pagina <= 0) {
            header('Location:malla_turnos_configuracion_recargos.php?pagina=1&id=' . urlencode($filtro_permanente) .
                '&fechainicio=' . base64_encode($FechaInicio) .
                '&operacion=' . base64_encode($filtro_operacion)
            );
            exit;
        }

        // ============ CONSULTA LISTADO ============
        $data_listado = $data_consulta; // copia
        $data_listado[] = (int)$iniciar_pagina;
        $data_listado[] = (int)$registros_x_pagina;

        $consulta_string = "
            SELECT
                `ctmcr_id`, `ctmcr_mes`, `ctmcr_usuario`, `ctmcr_registro_usuario`, `ctmcr_registro_fecha`,
                TU.`usu_nombres_apellidos` AS nombres_usuario,
                TUR.`usu_nombres_apellidos` AS nombres_registro,
                TC.`ac_nombre_campania` AS campania,
                `ctmcr_sop_ruta`
            FROM `tb_control_turno_malla_configuracion_recargos`
            LEFT JOIN `tb_administrador_usuario` AS TU
                ON `tb_control_turno_malla_configuracion_recargos`.`ctmcr_usuario`=TU.`usu_id`
            LEFT JOIN `tb_administrador_usuario` AS TUR
                ON `tb_control_turno_malla_configuracion_recargos`.`ctmcr_registro_usuario`=TUR.`usu_id`
            LEFT JOIN `tb_administrador_campania` AS TC
                ON TU.`usu_campania`=TC.`ac_id`
            WHERE `ctmcr_mes`=? $filtro_buscar
            ORDER BY TU.`usu_nombres_apellidos`
            LIMIT ?,?
        ";

        $consulta_registros = $enlace_db->prepare($consulta_string);
        if (!$consulta_registros) {
            throw new Exception("Prepare LISTADO falló: " . $enlace_db->error);
        }

        // Types: mes(s) + filtros(s) + offset(i) + limit(i)
        $types_list = "s" . str_repeat("s", $cantidad_filtros) . "ii";
        bindParamsMysqli($consulta_registros, $types_list, $data_listado);

        $consulta_registros->execute();
        $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

    } catch (Throwable $e) {
        // Mensaje amigable en UI y log técnico al servidor
        $respuesta_accion = "<p class='alert alert-danger p-1 mb-2'>Ocurrió un error al consultar la información. Verifica la tabla de recargos y la conexión a BD.</p>";
        error_log("[malla_turnos_configuracion_recargos] " . $e->getMessage());
    }
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
            <div class="col-md-5 py-2">
                <form name="filtrado" action="" method="POST">
                    <div class="input-group">
                        <input type="month" class="form-control form-control-sm" name="filtro_fecha" value='<?php echo $FechaInicio; ?>' placeholder="Búsqueda" class="form-control" required autofocus>
                        <input type="text" class="form-control form-control-sm" name="id_filtro" value='<?php if (isset($_POST["filtro"])) { echo $_POST['id_filtro']; } else { if($filtro_permanente!="null"){ echo $filtro_permanente; } } ?>' placeholder="Búsqueda" class="form-control">
                        <span class="input-group-btn">
                            <button class="btn btn-corp" type="submit" name="filtro"><span class="fas fa-search"></span></button>
                            <a href="malla_turnos_configuracion_recargos.php?pagina=1&id=null&fechainicio=<?php echo base64_encode(date('Y-m'));?>&operacion=<?php echo base64_encode('Todas'); ?>" class="btn btn-corp"><span class="fas fa-sync-alt"></span></a>
                        </span>
                    </div>
                </form>
            </div>
            <div class="col-md-7 py-2">
                <?php if(isset($perfil_modulo) && ($perfil_modulo=="Administrador" OR $perfil_modulo=="Gestor")): ?>
                    <button type="button" data-toggle="modal" class='btn btn-corp menu float-right' data-target="#dataexport"><span class="fas fa-file-excel float-left"></span></button>
                <?php endif; ?>
                <a href="malla_turnos_cambio.php?pagina=1&id=null&fechainicio=<?php echo base64_encode(date('Y-m'));?>&operacion=<?php echo base64_encode('Todas'); ?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-retweet"></span></div><div class="pl-2 menu_res float-left">Cambio Turno</div></a>
                <a href="malla_turnos_novedades.php?pagina=1&id=null&fechainicio=<?php echo base64_encode(date('Y-m'));?>&operacion=<?php echo base64_encode('Todas'); ?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-bell"></span></div><div class="pl-2 menu_res float-left">Novedades</div></a>
                <a href="malla_turnos.php?fechainicio=<?php echo base64_encode(date('Y')."-W".date('W'));?>&operacion=<?php echo base64_encode('Todas'); ?>&id=null" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-calendar-week"></span></div><div class="pl-2 menu_res float-left">Malla Turnos</div></a>
                <a href="malla_turnos_configuracion_recargos_editar.php?pagina=1&id=null&fechainicio=<?php echo base64_encode($FechaInicio);?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-pen"></span></div><div class="pl-2 menu_res float-left">Configurar Recargos</div></a>
            </div>
        </div>

        <div class="row" id="tabla_fixed">
            <div class="col-md-12">

                <?php if (!empty($respuesta_accion)) { echo $respuesta_accion; } ?>

                <?php if ($registros_cantidad_total>0): ?>
                    <div class="col-md-7 float-left">
                        <!-- sub menú (cambiar a col-md-5 a paginacion) -->
                    </div>
                    <div class="col-md-12 float-left">
                        <nav aria-label="Paginación" class="paginacion">
                            <ul class="pagination justify-content-end">
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=1&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-double-left"></span></a></li>
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $pagina-1; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-left"></span></a></li>
                                <?php
                                    if ($numero_paginas<=5 OR $pagina<=3) {
                                        $pagina_inicio=1; $pagina_fin=$numero_paginas;
                                        if ($pagina<=3 AND $numero_paginas>=5) {
                                            $pagina_fin=5;
                                        }
                                    } else {
                                        $pagina_inicio=$pagina-2; $pagina_fin=$pagina+2;
                                        if (($numero_paginas-$pagina_inicio)<=5) {
                                            $pagina_inicio=$numero_paginas-4; $pagina_fin=$numero_paginas;
                                        }
                                    }
                                ?>
                                <?php for ($i=$pagina_inicio; $i <= $pagina_fin; $i++): ?>
                                    <li class="page-item <?php echo $pagina==$i ? 'active':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $i; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><?php echo $i; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $pagina+1; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-right"></span></a></li>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $numero_paginas; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-double-right"></span></a></li>
                            </ul>
                        </nav>
                    </div>

                    <div id="table-fixed" class="table-responsive table-fixed">
                        <table class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th class="align-middle" style="width: 55px;"></th>
                                    <th class="align-middle">Mes</th>
                                    <th class="align-middle">Doc. Usuario</th>
                                    <th class="align-middle">Nombres y Apellidos</th>
                                    <th class="align-middle">Campaña</th>
                                    <th class="align-middle">Usuario Registro</th>
                                    <th class="align-middle">Fecha Registro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i=0; $i < count($resultado_registros); $i++): ?>
                                    <tr>
                                        <td class="align-middle text-center">
                                            <?php if(isset($resultado_registros[$i][8]) && $resultado_registros[$i][8]!=""): ?>
                                                <a href="#" onClick="open_modal_detalle('<?php echo base64_encode($resultado_registros[$i][0]); ?>');" class="btn btn-secondary btn-sm btn-width mb-1" title="Soporte"><span class="fas fa-file-alt"></span></a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle"><?php echo $resultado_registros[$i][1]; ?></td>
                                        <td class="align-middle"><?php echo $resultado_registros[$i][2]; ?></td>
                                        <td class="align-middle"><?php echo $resultado_registros[$i][5]; ?></td>
                                        <td class="align-middle"><?php echo $resultado_registros[$i][7]; ?></td>
                                        <td class="align-middle"><?php echo $resultado_registros[$i][6]; ?></td>
                                        <td class="align-middle"><?php echo $resultado_registros[$i][4]; ?></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="col-md-7 float-left">
                        <p class="paginacion_descripcion">
                            Mostrando <?php echo ($registros_cantidad_total>0) ? (($pagina*$registros_x_pagina)-$registros_x_pagina+1) : "0"; ?>
                            a <?php echo (($pagina*$registros_x_pagina)>$registros_cantidad_total) ? $registros_cantidad_total : ($pagina*$registros_x_pagina); ?>
                            de <?php echo $registros_cantidad_total; ?>
                        </p>
                    </div>

                    <div class="col-md-5 float-left">
                        <nav aria-label="Paginación" class="paginacion">
                            <ul class="pagination justify-content-end">
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=1&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-double-left"></span></a></li>
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $pagina-1; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-left"></span></a></li>
                                <?php for ($i=$pagina_inicio; $i <= $pagina_fin; $i++): ?>
                                    <li class="page-item <?php echo $pagina==$i ? 'active':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $i; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><?php echo $i; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $pagina+1; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-right"></span></a></li>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="malla_turnos_configuracion_recargos.php?pagina=<?php echo $numero_paginas; ?>&id=<?php echo $filtro_permanente; ?>&fechainicio=<?php echo base64_encode($FechaInicio); ?>&operacion=<?php echo base64_encode($filtro_operacion); ?>"><span class="fas fa-angle-double-right"></span></a></li>
                            </ul>
                        </nav>
                    </div>

                <?php else: ?>
                    <p class="alert alert-warning">
                        <span class="fas fa-exclamation-triangle"></span> No se encontraron registros
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modal detalle -->
        <div class="modal fade" id="modal_ver_detalle" role="dialog">
            <div class="modal-dialog mw-100 w-100">
                <!-- Modal content-->
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title">Soporte</h4>
                        <button type="button" class="close" data-dismiss="modal" onClick="close_modal_detalle();">×</button>
                    </div>
                    <div class="modal-body modal-body-detalle p-1">

                    </div>
                    <div class="modal-footer">
                        <a href="#" onClick="close_modal_detalle();" class="btn btn-corp menu float-right" data-dismiss="modal"><div class="float-left"><span class="fas fa-times"></span></div><div class="pl-2 menu_res float-left">Cerrar</div></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
        include("../footer.php");
        include("../config/configuracion_js.php");
        include("malla_turnos_novedades_reporte.php");
    ?>

    <script type="text/javascript">
        $(document).ready(function(){
            $("#modal_ver_detalle").on('hidden.bs.modal', function () {
                close_modal_detalle();
            });
        });

        function open_modal_detalle(id_registro) {
            // Se conserva la lógica original del modal (si tu backend usa este endpoint)
            $('.modal-body-detalle').load('malla_turnos_novedades_ver.php?reg='+id_registro,function(){
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
                alto_elemento=alto_ventana-200;
                elemento.style.height=alto_elemento+"px";
            }
        }
    </script>
</body>
</html>
