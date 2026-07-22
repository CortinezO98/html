<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Auditoría-Monitoreos";

	require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");
// error_reporting(E_ALL);
// ini_set('display_errors', '1');
    /*DEFINICIÓN DE VARIABLES*/
    $titulo_header = "Auditoría | Transacciones";
    $pagina=validar_input($_GET['pagina']);
    $bandeja=validar_input(base64_decode($_GET['bandeja']));
    unset($_SESSION['gcmta_registro_creado']);

    // Inicializa variables
    $data_consulta=array();
    $filtro_buscar="";
    $filtro_perfil="";

    // Filtro campo buscar
    if (isset($_POST["filtro"])) {
        $pagina=1;
        $filtro_permanente=validar_input($_POST['id_filtro']);
    } else {
        $filtro_permanente=validar_input($_GET['id']);
    }

    // Configuración Paginación
    $registros_x_pagina=50;
    $iniciar_pagina=($pagina-1)*$registros_x_pagina;

    // Valida que filtro se deba ejecutar
    if ($filtro_permanente!="null" AND $filtro_permanente!="") {
        $filtro_buscar="AND (TMC.`gcm_id` LIKE ? OR TM.`gcm_nombre_matriz` LIKE ? OR TUA.`usu_nombres_apellidos` LIKE ? OR TMC.`gcm_fecha_hora_gestion` LIKE ? OR TMC.`gcm_fecha_hora_cierre` LIKE ? OR TMC.`gcm_tipo_monitoreo` LIKE ? OR TMC.`gcm_skill_interaccion` LIKE ? OR TMC.`gcm_tipo_gestion` LIKE ? OR TMC.`gcm_segmento` LIKE ? OR TMC.`gcm_id_sim` LIKE ? OR TMC.`gcm_id_ani` LIKE ? OR TMC.`gcm_observaciones_monitoreo` LIKE ? OR TMC.`gcm_nota_enc` LIKE ? OR TMC.`gcm_nota_ecn` LIKE ? OR TMC.`gcm_nota_ecuf` LIKE ? OR TMC.`gcm_estado` LIKE ? OR TUR.`usu_nombres_apellidos` LIKE ? OR TMC.`gcm_fecha_monitoreo` LIKE ? OR TP.`ap_nombre_piloto` LIKE ? OR TS.`usu_nombres_apellidos` LIKE ? OR TSA.`usu_nombres_apellidos` LIKE ?)";
        $cantidad_filtros=count(explode('?', $filtro_buscar))-1;
        for ($i=0; $i < $cantidad_filtros; $i++) {
            array_push($data_consulta, "%$filtro_permanente%");
        }
    }

    if ($perfil_modulo=="Administrador") {
        $filtro_perfil="";
    } elseif ($perfil_modulo=="Gestor") {
        $filtro_perfil=" AND TMC.`gcm_aplica_indicador`<>'No-Cliente'";
    } elseif($perfil_modulo=="Supervisor"){
        $filtro_perfil=" AND (TUA.`usu_supervisor`=? OR TMC.`gcm_analista`=? OR TMC.`gcm_registro_usuario`=?)";
        array_push($data_consulta, $_SESSION["usu_id"]);
        array_push($data_consulta, $_SESSION["usu_id"]);
        array_push($data_consulta, $_SESSION["usu_id"]);
    } elseif($perfil_modulo=="Formador"){
        $filtro_perfil=" AND TMC.`gcm_registro_usuario`=?";
        array_push($data_consulta, $_SESSION["usu_id"]);
    } elseif($perfil_modulo=="Usuario"){
        $filtro_perfil=" AND TMC.`gcm_analista`=?";
        array_push($data_consulta, $_SESSION["usu_id"]);
    } elseif($perfil_modulo=="Cliente"){
        $filtro_perfil=" AND TMC.`gcm_aplica_indicador`='No-Cliente'";
    }

    $consulta_contar_string="SELECT COUNT(TMC.`gcm_id`) FROM `tb_gestion_calidad_monitoreo` AS TMC LEFT JOIN `tb_gestion_calidad_matriz` AS TM ON TMC.`gcm_matriz`=TM.`gcm_id` LEFT JOIN `tb_administrador_usuario` AS TUR ON TMC.`gcm_auditoria_responsable`=TUR.`usu_id` WHERE 1=1 AND `gcm_auditoria`='Si' ".$filtro_buscar." ".$filtro_perfil."";

    $consulta_contar_registros = $enlace_db->prepare($consulta_contar_string);
    if (count($data_consulta)>0) {
        $consulta_contar_registros->bind_param(str_repeat("s", count($data_consulta)), ...$data_consulta);
    }
    $consulta_contar_registros->execute();
    $resultado_registros_contar = $consulta_contar_registros->get_result()->fetch_all(MYSQLI_NUM);
    $registros_cantidad_total = $resultado_registros_contar[0][0];
    $numero_paginas=ceil($registros_cantidad_total/$registros_x_pagina);

    if (!isset($_GET['pagina']) || ($pagina>$numero_paginas AND $numero_paginas>0) || $pagina<=0) {
        header('Location:gestion_calidad_monitoreo_transacciones.php?pagina=1&id=null');
    }

    array_push($data_consulta, $iniciar_pagina);
    array_push($data_consulta, $registros_x_pagina);

    $consulta_string="SELECT TMC.`gcm_id`, TM.`gcm_nombre_matriz`, TMC.`gcm_fecha_hora_gestion`, TMC.`gcm_fecha_hora_cierre`, TMC.`gcm_tipo_monitoreo`, TMC.`gcm_skill_interaccion`, TMC.`gcm_tipo_gestion`, TMC.`gcm_segmento`, TMC.`gcm_id_sim`, TMC.`gcm_id_ani`, TMC.`gcm_observaciones_monitoreo`, TMC.`gcm_nota_enc`, TMC.`gcm_nota_ecn`, TMC.`gcm_nota_ecuf`, TMC.`gcm_estado`, TUR.`usu_nombres_apellidos`, TMC.`gcm_registro_fecha`, `gcm_nota_general`, `gcm_nota_enc_estado`, `gcm_nota_ecn_estado`, `gcm_nota_ecuf_estado`, `gcm_aplica_indicador`, `gcm_fecha_monitoreo`, TMC.`gcm_registro_usuario`, TM.`gcm_observaciones`, `gcm_auditoria`, `gcm_auditoria_responsable` FROM `tb_gestion_calidad_monitoreo` AS TMC LEFT JOIN `tb_gestion_calidad_matriz` AS TM ON TMC.`gcm_matriz`=TM.`gcm_id` LEFT JOIN `tb_administrador_usuario` AS TUR ON TMC.`gcm_auditoria_responsable`=TUR.`usu_id` WHERE 1=1 AND `gcm_auditoria`='Si' ".$filtro_buscar." ".$filtro_perfil." ORDER BY TMC.`gcm_id` DESC LIMIT ?,?";

    $consulta_registros = $enlace_db->prepare($consulta_string);
    $consulta_registros->bind_param(str_repeat("s", count($data_consulta)), ...$data_consulta);
    $consulta_registros->execute();
    $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

    // Mensaje post-reset
    $msg_reset = '';
    if (isset($_GET['reset'])) {
        $n = (int)$_GET['reset'];
        if ($n > 0) {
            $msg_reset = "<script type='text/javascript'>alertify.success('¡".$n." auditoría(s) reseteada(s) exitosamente!', 0);</script>";
        } else {
            $msg_reset = "<script type='text/javascript'>alertify.warning('No se encontraron registros para resetear en ese rango.', 0);</script>";
        }
    }
?>
<!DOCTYPE html>
<html lang="ES">
<head>
	<?php include("../config/configuracion_estilos.php"); ?>
<script type="text/javascript">
    // Definida aquí para que esté disponible antes de menu_header.php
    function tabla_fixed(){
        var tf  = document.getElementById("tabla_fixed");
        var el  = document.getElementById("table-fixed");
        var el1 = document.getElementById("elemento_1");
        if (!el || !el1 || !tf) return;
        var alto_ventana = window.outerHeight - el1.clientHeight - 100;
        var alto_tabla   = tf.clientHeight + 200;
        if (alto_tabla > alto_ventana) {
            el.style.height = (alto_ventana - 200) + "px";
        }
    }
</script>
</head>
<body>
    <?php
        include("../menu_principal.php");
        include("../menu_header.php");
    ?>
    <div class="contenido">
        <?php if (!empty($msg_reset)) { echo $msg_reset; } ?>

        <!-- Form de búsqueda: FUERA del form_reset_seleccion para evitar anidamiento -->
        <form name="filtrado" id="form_filtrado" action="" method="POST">
            <input type="hidden" name="filtro" value="1">
        </form>

        <!-- Formulario principal: solo checkboxes + hidden fields (NO anida otros forms) -->
        <form name="form_reset_seleccion" id="form_reset_seleccion" action="gestion_calidad_monitoreo_transacciones_reset.php" method="POST">
        <input type="hidden" name="modo" value="seleccion">
        <input type="hidden" name="pagina_retorno" value="<?php echo $pagina; ?>">
        <input type="hidden" name="filtro_retorno" value="<?php echo $filtro_permanente; ?>">

        <div class="row" id="elemento_1">
            <div class="col-md-3 py-2">
                <!-- Búsqueda: inputs apuntan a form_filtrado via atributo form= -->
                <div class="input-group">
                    <input type="text" name="id_filtro" form="form_filtrado" value='<?php if (isset($_POST["filtro"])) { echo $_POST['id_filtro']; } else {if($filtro_permanente!="null"){echo $filtro_permanente;}} ?>' placeholder="Búsqueda" class="form-control" autofocus>
                    <span class="input-group-btn">
                        <button class="btn btn-corp" type="submit" name="filtro" form="form_filtrado"><span class="fas fa-search"></span></button>
                        <a href="gestion_calidad_monitoreo_transacciones.php?pagina=1&id=null" class="btn btn-corp"><span class="fas fa-sync-alt"></span></a>
                    </span>
                </div>
            </div>
            <div class="col-md-9 py-2">
                <a href="gestion_calidad_monitoreo.php?pagina=1&id=<?php echo $filtro_permanente; ?>&bandeja=<?php echo base64_encode('Mes Actual'); ?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-clipboard-list"></span></div><div class="pl-2 menu_res float-left">Monitoreos Mes Actual</div></a>
                <a href="gestion_calidad_monitoreo_transacciones.php?pagina=1&id=null" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-qrcode"></span></div><div class="pl-2 menu_res float-left">Transacciones</div></a>
                <?php if($perfil_modulo=="Administrador"): ?>
                    <a href="gestion_calidad_monitoreo_transacciones_auditoria.php?pagina=<?php echo $pagina; ?>&id=<?php echo $filtro_permanente; ?>" class="btn btn-corp menu float-right"><div class="float-left"><span class="fas fa-plus"></span></div><div class="pl-2 menu_res float-left">Crear Auditoría</div></a>
                    <button type="button" class="btn btn-danger menu float-right mr-1" onclick="abrir_modal_reset()">
                        <div class="float-left"><span class="fas fa-undo"></span></div>
                        <div class="pl-2 menu_res float-left">Resetear Auditorías <span id="badge_seleccionados" class="badge badge-light ml-1" style="display:none;">0</span></div>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="row" id="tabla_fixed">
            <div class="col-md-12">
                <?php if ($registros_cantidad_total>0): ?>
                    <div class="col-md-7 float-left"><!-- sub menú --></div>
                    <div class="col-md-12 float-left">
                        <nav aria-label="Paginación" class="paginacion">
                            <ul class="pagination justify-content-end">
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=1&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-double-left"></span></a></li>
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $pagina-1; ?>&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-left"></span></a></li>
                                <?php
                                    if ($numero_paginas<=5 OR $pagina<=3) {
                                        $pagina_inicio=1; $pagina_fin=$numero_paginas;
                                        if ($pagina<=3 AND $numero_paginas>=5) { $pagina_fin=5; }
                                    } else {
                                        $pagina_inicio=$pagina-2; $pagina_fin=$pagina+2;
                                        if (($numero_paginas-$pagina_inicio)<=5) { $pagina_inicio=$numero_paginas-4; $pagina_fin=$numero_paginas; }
                                    }
                                ?>
                                <?php for ($i=$pagina_inicio; $i <= $pagina_fin; $i++): ?>
                                    <li class="page-item <?php echo $pagina==$i ? 'active':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $i; ?>&id=<?php echo $filtro_permanente; ?>"><?php echo $i; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $pagina+1; ?>&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-right"></span></a></li>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $numero_paginas; ?>&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-double-right"></span></a></li>
                            </ul>
                        </nav>
                    </div>
                    <div id="table-fixed" class="table-responsive table-fixed">
                        <table class="table table-bordered table-striped table-hover table-sm">
                            <thead>
                                <tr>
                                    <th class="align-middle text-center" style="width: 40px;">
                                        <?php if($perfil_modulo=="Administrador"): ?>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="chk_todos" title="Seleccionar todos">
                                            <label class="custom-control-label" for="chk_todos"></label>
                                        </div>
                                        <?php endif; ?>
                                    </th>
                                    <th class="align-middle">Consecutivo</th>
                                    <th class="align-middle">Matriz</th>
                                    <th class="align-middle">Responsable</th>
                                    <th class="align-middle">Skill Interacción</th>
                                    <th class="align-middle">Tipo Gestión</th>
                                    <th class="align-middle">Segmento</th>
                                    <th class="align-middle">Id SIM</th>
                                    <th class="align-middle">Id/ANI</th>
                                    <th class="align-middle">Duración</th>
                                    <th class="align-middle">Fecha Gestión</th>
                                    <th class="align-middle">Fecha Monitoreo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i=0; $i < count($resultado_registros); $i++): ?>
                                <tr>
                                    <td class="align-middle text-center">
                                        <?php if($perfil_modulo=="Administrador"): ?>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input chk_registro" name="ids_reset[]" id="chk_<?php echo $i; ?>" value="<?php echo htmlspecialchars($resultado_registros[$i][0]); ?>">
                                            <label class="custom-control-label" for="chk_<?php echo $i; ?>"></label>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][0]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][1]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][15]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][5]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][6]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][7]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][8]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][9]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][3]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][2]; ?></td>
                                    <td class="align-middle"><?php echo $resultado_registros[$i][22]; ?></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-7 float-left">
                        <p class="paginacion_descripcion">Mostrando <?php if($registros_cantidad_total>0){ echo ($pagina*$registros_x_pagina)-$registros_x_pagina+1;}else{echo "0";} ?> a <?php if(($pagina*$registros_x_pagina)>$registros_cantidad_total) { echo $registros_cantidad_total; } else { echo $pagina*$registros_x_pagina; } ?> de <?php echo $registros_cantidad_total; ?></p>
                    </div>
                    <div class="col-md-5 float-left">
                        <nav aria-label="Paginación" class="paginacion">
                            <ul class="pagination justify-content-end">
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=1&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-double-left"></span></a></li>
                                <li class="page-item <?php echo $pagina<=1 ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $pagina-1; ?>&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-left"></span></a></li>
                                <?php for ($i=$pagina_inicio; $i <= $pagina_fin; $i++): ?>
                                    <li class="page-item <?php echo $pagina==$i ? 'active':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $i; ?>&id=<?php echo $filtro_permanente; ?>"><?php echo $i; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $pagina+1; ?>&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-right"></span></a></li>
                                <li class="page-item <?php echo $pagina>=$numero_paginas ? 'disabled':'' ?>"><a class="page-link" href="gestion_calidad_monitoreo_transacciones.php?pagina=<?php echo $numero_paginas; ?>&id=<?php echo $filtro_permanente; ?>"><span class="fas fa-angle-double-right"></span></a></li>
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
        </form><!-- /form_reset_seleccion -->

        <!-- ============================================================ -->
        <!-- MODAL RESETEAR AUDITORÍAS (solo Administrador)               -->
        <!-- ============================================================ -->
        <?php if($perfil_modulo=="Administrador"): ?>
        <div class="modal fade" id="modal_reset" role="dialog" tabindex="-1">
            <div class="modal-dialog modal-md">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><span class="fas fa-undo mr-2"></span>Resetear Auditorías</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">×</button>
                    </div>
                    <div class="modal-body">
                        <!-- Pestañas -->
                        <ul class="nav nav-tabs mb-3">
                            <li class="nav-item">
                                <a class="nav-link active" id="tab_seleccion_link" href="#" onclick="cambiar_tab('seleccion'); return false;">
                                    <span class="fas fa-check-square mr-1"></span>Seleccionados
                                    <span id="badge_modal" class="badge badge-danger ml-1">0</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="tab_rango_link" href="#" onclick="cambiar_tab('rango'); return false;">
                                    <span class="fas fa-calendar-alt mr-1"></span>Por rango de fechas
                                </a>
                            </li>
                        </ul>

                        <!-- Tab: Seleccionados -->
                        <div id="tab_seleccion">
                            <p class="text-muted small mb-2">Se resetearán únicamente los registros marcados en la tabla. Se establece <code>gcm_auditoria='No'</code> y se libera el responsable asignado.</p>
                            <div id="lista_seleccionados" class="mb-2" style="max-height:160px; overflow-y:auto; font-size:0.85em;"></div>
                            <div class="alert alert-warning py-2 mb-0" id="aviso_sin_seleccion">
                                <span class="fas fa-exclamation-triangle"></span> No hay registros seleccionados. Marque filas en la tabla o use la pestaña <strong>Por rango de fechas</strong>.
                            </div>
                        </div>

                        <!-- Tab: Por rango de fechas -->
                        <div id="tab_rango" style="display:none;">
                            <p class="text-muted small mb-2">Se resetearán <strong>todos</strong> los registros con <code>gcm_auditoria='Si'</code> dentro del rango indicado, independientemente de la selección.</p>
                            <form id="form_reset_rango" action="gestion_calidad_monitoreo_transacciones_reset.php" method="POST">
                                <input type="hidden" name="modo" value="rango">
                                <input type="hidden" name="pagina_retorno" value="<?php echo $pagina; ?>">
                                <input type="hidden" name="filtro_retorno" value="<?php echo $filtro_permanente; ?>">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Fecha inicio</label>
                                            <input type="date" name="fecha_inicio" id="reset_fecha_inicio" class="form-control form-control-sm" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="font-weight-bold">Fecha fin</label>
                                            <input type="date" name="fecha_fin" id="reset_fecha_fin" class="form-control form-control-sm" required>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"><span class="fas fa-times mr-1"></span>Cancelar</button>
                        <button type="button" class="btn btn-danger" id="btn_confirmar_reset" onclick="ejecutar_reset()">
                            <span class="fas fa-undo mr-1"></span>Confirmar Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- ============================================================ -->

        <!-- Modal VER MONITOREO -->
        <div class="modal fade" id="modal_ver_monitoreo" role="dialog">
            <div class="modal-dialog mw-100 w-100">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title">Detalle monitoreo</h4>
                        <button type="button" class="close" data-dismiss="modal" onClick="close_modal_monitoreo();">×</button>
                    </div>
                    <div class="modal-body modal-body-monitoreo p-1"></div>
                    <div class="modal-footer">
                        <a href="#" onClick="close_modal_monitoreo();" class="btn btn-corp menu float-right" data-dismiss="modal"><div class="float-left"><span class="fas fa-times"></span></div><div class="pl-2 menu_res float-left">Cerrar</div></a>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /contenido -->

    <?php
        include("../footer.php");
        include("../config/configuracion_js.php");
        include("gestion_calidad_monitoreo_reporte.php");
    ?>
    <script type="text/javascript">
        var tab_activo = 'seleccion';

        // ── Inicialización: espera que jQuery y el DOM estén listos ───
        function init_listeners() {
            if (typeof $ === 'undefined') {
                // jQuery aún no cargó, reintenta en 50ms
                setTimeout(init_listeners, 50);
                return;
            }

            // Modal ver monitoreo
            $("#modal_ver_monitoreo").on('hidden.bs.modal', function () {
                close_modal_monitoreo();
            });

            // Tabla fija al cargar
            tabla_fixed();

            // Resize
            window.addEventListener('resize', tabla_fixed);

            // Checkbox "Seleccionar todos"
            document.addEventListener('change', function(e){
                if (e.target && e.target.id === 'chk_todos') {
                    toggle_todos(e.target);
                }
            });

            // Checkboxes individuales
            document.addEventListener('change', function(e){
                if (e.target && e.target.classList.contains('chk_registro')) {
                    actualizar_contador();
                }
            });
        }

        // jQuery ya está cargado (configuracion_js.php se incluyó antes)
        // Ejecutamos directo al llegar a este script
        init_listeners();

        function open_modal_monitoreo(id_registro) {
            $('.modal-body-monitoreo').load('gestion_calidad_monitoreo_ver.php?reg='+id_registro, function(){
                $('#modal_ver_monitoreo').modal({show:true});
            });
        }

        function close_modal_monitoreo() {
            $('.modal-body-monitoreo').html('');
        }

        // ── Tabla fija ───────────────────────────────────────────────────
        function tabla_fixed(){
            var tf  = document.getElementById("tabla_fixed");
            var el  = document.getElementById("table-fixed");
            var el1 = document.getElementById("elemento_1");
            if (!el || !el1 || !tf) return;
            var alto_ventana = window.outerHeight - el1.clientHeight - 100;
            var alto_tabla   = tf.clientHeight + 200;
            if (alto_tabla > alto_ventana) {
                el.style.height = (alto_ventana - 200) + "px";
            }
        }

        // ── Checkboxes ───────────────────────────────────────────────────
        function toggle_todos(chk) {
            document.querySelectorAll('#form_reset_seleccion .chk_registro').forEach(function(c){ c.checked = chk.checked; });
            actualizar_contador();
        }

        function actualizar_contador() {
            var n = document.querySelectorAll('#form_reset_seleccion .chk_registro:checked').length;
            // Badge en botón barra superior
            var badge = document.getElementById('badge_seleccionados');
            if (badge) {
                badge.textContent = n;
                badge.style.display = n > 0 ? 'inline-block' : 'none';
            }
            // Badge en modal
            var bm = document.getElementById('badge_modal');
            if (bm) bm.textContent = n;
        }

        // ── Modal Reset ──────────────────────────────────────────────────
        function abrir_modal_reset() {
            actualizar_contador();
            refrescar_lista_seleccionados();
            cambiar_tab('seleccion');
            $('#modal_reset').modal({show:true});
        }

        function cambiar_tab(tab) {
            tab_activo = tab;
            var es_seleccion = (tab === 'seleccion');
            document.getElementById('tab_seleccion').style.display = es_seleccion ? '' : 'none';
            document.getElementById('tab_rango').style.display     = es_seleccion ? 'none' : '';
            document.getElementById('tab_seleccion_link').classList[es_seleccion ? 'add' : 'remove']('active');
            document.getElementById('tab_rango_link').classList[es_seleccion ? 'remove' : 'add']('active');
            if (es_seleccion) refrescar_lista_seleccionados();
        }

        function refrescar_lista_seleccionados() {
            var checks = document.querySelectorAll('#form_reset_seleccion .chk_registro:checked');
            var lista  = document.getElementById('lista_seleccionados');
            var aviso  = document.getElementById('aviso_sin_seleccion');
            if (checks.length === 0) {
                lista.innerHTML = '';
                aviso.style.display = '';
            } else {
                aviso.style.display = 'none';
                var html = '<ul class="list-unstyled mb-0">';
                checks.forEach(function(c){
                    html += '<li><span class="fas fa-dot-circle text-danger mr-1"></span>' + c.value + '</li>';
                });
                html += '</ul>';
                lista.innerHTML = html;
            }
        }

        function ejecutar_reset() {
            if (tab_activo === 'seleccion') {
                var checks = document.querySelectorAll('#form_reset_seleccion .chk_registro:checked');
                if (checks.length === 0) {
                    alertify.warning('Seleccione al menos un registro o use la pestaña "Por rango de fechas".', 0);
                    return;
                }
                if (!confirm('¿Resetear ' + checks.length + ' auditoría(s) seleccionada(s)?\n\nEsta acción no se puede deshacer.')) return;
                document.getElementById('form_reset_seleccion').submit();
            } else {
                var fi = document.getElementById('reset_fecha_inicio').value;
                var ff = document.getElementById('reset_fecha_fin').value;
                if (!fi || !ff) {
                    alertify.warning('Complete la fecha de inicio y fin.', 0);
                    return;
                }
                if (fi > ff) {
                    alertify.warning('La fecha de inicio no puede ser mayor a la fecha fin.', 0);
                    return;
                }
                if (!confirm('¿Resetear TODAS las auditorías entre ' + fi + ' y ' + ff + '?\n\nEsta acción no se puede deshacer.')) return;
                document.getElementById('form_reset_rango').submit();
            }
        }
    </script>
</body>
</html>