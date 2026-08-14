<?php
    $modulo_plataforma = "Coaching-Reportes";

    require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");
    require_once("lib/coaching_seguridad.php");
    require_once("lib/coaching_datos.php");

    $titulo_header = "Coaching | Reporte";

    $tiene_reportes = isset($_SESSION['modulos_acceso_permisos']['Coaching-Reportes']) && $_SESSION['modulos_acceso_permisos']['Coaching-Reportes'] !== '';
    if (!$tiene_reportes) {
        header("Location:../permiso_denegado.php");
        exit;
    }

    $perfil_coaching = coachingPerfilUsuarioActual();
    // Antes solo se aplicaba el filtro de alcance si el perfil coincidía
    // exactamente con 'Supervisor'/'Agente' — pero se confirmó que cuentas
    // reales traen etiquetas genéricas del portal ('Usuario', 'Gestor')
    // que nunca calzaban con ese string, dejando a CUALQUIER otro perfil
    // (incluidos Agentes reales) ver el reporte completo sin restricción.
    // coachingFiltroAlcance() ya es fail-safe por sí sola (ver
    // lib/coaching_seguridad.php): se llama siempre, sin condicionar por
    // perfil, y ella misma decide el alcance correcto para cada caso.
    [$filtro_alcance_sql, $parametros_alcance] = coachingFiltroAlcance($perfil_coaching ?? '', $_SESSION['usu_id']);

    // ---- Filtros ----
    $fecha_desde = validar_input($_GET['desde'] ?? '');
    $fecha_hasta = validar_input($_GET['hasta'] ?? '');
    if ($fecha_desde === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) { $fecha_desde = date('Y-m-d', strtotime('-90 days')); }
    if ($fecha_hasta === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) { $fecha_hasta = date('Y-m-d'); }

    $filtro_estado = validar_input($_GET['estado'] ?? '');
    $filtro_tipo = validar_input($_GET['tipo'] ?? '');
    $filtro_origen = validar_input($_GET['origen'] ?? '');

    $condiciones = " AND DATE(P.`gcp_registro_fecha`) BETWEEN ? AND ? ";
    $parametros = array_merge($parametros_alcance, [$fecha_desde, $fecha_hasta]);

    if ($filtro_estado !== '' && $filtro_estado !== 'Todos') {
        $condiciones .= " AND E.`gce_codigo` = ? ";
        $parametros[] = $filtro_estado;
    }
    if ($filtro_tipo !== '' && $filtro_tipo !== 'Todos') {
        $condiciones .= " AND T.`gct_codigo` = ? ";
        $parametros[] = $filtro_tipo;
    }
    if ($filtro_origen !== '' && $filtro_origen !== 'Todos') {
        $condiciones .= " AND P.`gcp_origen_tipo` = ? ";
        $parametros[] = $filtro_origen;
    }

    // Filtro por Rol + Usuario: 'agente' y 'lider_calidad' son ambos
    // posibles ocupantes de la columna "Agente" (gcp_agente_id, el
    // coacheado) — un Líder de Calidad también puede ser coacheado por su
    // Supervisor, ver lib/coaching_seguridad.php. 'supervisor' filtra por
    // gcp_supervisor_id. El alcance normal (coachingFiltroAlcance) sigue
    // aplicando de forma independiente vía $filtro_alcance_sql — si
    // alguien selecciona un usuario fuera de su propio alcance, el AND
    // combinado simplemente no trae resultados, nunca se salta la
    // restricción de por sí.
    $filtro_rol = validar_input($_GET['rol'] ?? '');
    if (!in_array($filtro_rol, ['agente', 'supervisor', 'lider_calidad'], true)) { $filtro_rol = ''; }
    $filtro_usuario_id = validar_input($_GET['usuario_id'] ?? '');

    if ($filtro_rol !== '' && $filtro_usuario_id !== '') {
        $condiciones .= $filtro_rol === 'supervisor' ? " AND P.`gcp_supervisor_id` = ? " : " AND P.`gcp_agente_id` = ? ";
        $parametros[] = $filtro_usuario_id;
    }

    $tipos_bind = str_repeat('s', count($parametros));

    // ---- Paginación ----
    $registros_x_pagina = 50;
    $pagina = (int) validar_input($_GET['pagina'] ?? '1');
    if ($pagina <= 0) { $pagina = 1; }

    // KPIs y total de páginas se calculan sobre TODO el conjunto filtrado
    // (agregado en SQL), nunca sobre solo los 50 registros de la página
    // actual — si no, los KPIs mentirían apenas hubiera más de una página.
    $sql_kpis =
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN E.`gce_codigo` IN ('CERRADO', 'RECHAZADO') THEN 1 ELSE 0 END) AS cerrados,
                SUM(CASE WHEN P.`gcp_fecha_limite` IS NOT NULL
                          AND E.`gce_codigo` NOT IN ('CERRADO', 'RECHAZADO', 'ANULADO')
                          AND P.`gcp_fecha_limite` < CURDATE() THEN 1 ELSE 0 END) AS vencidos
         FROM `tb_gestion_coaching_paquete` AS P
         LEFT JOIN `tb_gestion_coaching_estado` AS E ON P.`gcp_estado_id` = E.`gce_id`
         LEFT JOIN `tb_gestion_coaching_tipo` AS T ON P.`gcp_tipo_id` = T.`gct_id`
         WHERE P.`gcp_activo` = 1 {$filtro_alcance_sql} {$condiciones}";
    $stmt_kpis = $enlace_db->prepare($sql_kpis);
    if (count($parametros) > 0) {
        $stmt_kpis->bind_param($tipos_bind, ...$parametros);
    }
    $stmt_kpis->execute();
    $fila_kpis = $stmt_kpis->get_result()->fetch_assoc();

    $kpi_total = (int) ($fila_kpis['total'] ?? 0);
    $kpi_cerrados = (int) ($fila_kpis['cerrados'] ?? 0);
    $kpi_vencidos = (int) ($fila_kpis['vencidos'] ?? 0);
    $kpi_pendientes = $kpi_total - $kpi_cerrados;
    $kpi_pct_cerrados = $kpi_total > 0 ? round(($kpi_cerrados / $kpi_total) * 100) : 0;

    $numero_paginas = (int) ceil($kpi_total / $registros_x_pagina);
    if ($pagina > $numero_paginas && $numero_paginas > 0) { $pagina = $numero_paginas; }
    $inicio_pagina = ($pagina - 1) * $registros_x_pagina;

    $sql =
        "SELECT P.`gcp_id`, P.`gcp_origen_tipo`, T.`gct_nombre`, E.`gce_nombre`, E.`gce_codigo`,
                TA.`usu_nombres_apellidos` AS agente_nombre, TS.`usu_nombres_apellidos` AS supervisor_nombre,
                P.`gcp_registro_fecha`, P.`gcp_fecha_limite`, P.`gcp_fecha_cierre`, P.`gcp_prioridad`,
                (SELECT GROUP_CONCAT(I.`gci_nombre` SEPARATOR '; ')
                 FROM `tb_gestion_coaching_paquete_indicador` AS PI
                 INNER JOIN `tb_gestion_coaching_indicador` AS I ON PI.`gcpi_indicador_id` = I.`gci_id`
                 WHERE PI.`gcpi_paquete` = P.`gcp_id`) AS indicadores_multiples,
                ESC.`gcpe_destinatario_nombre`, ESC.`gcpe_asunto`
         FROM `tb_gestion_coaching_paquete` AS P
         LEFT JOIN `tb_gestion_coaching_estado` AS E ON P.`gcp_estado_id` = E.`gce_id`
         LEFT JOIN `tb_gestion_coaching_tipo` AS T ON P.`gcp_tipo_id` = T.`gct_id`
         LEFT JOIN `tb_administrador_usuario` AS TA ON P.`gcp_agente_id` = TA.`usu_id`
         LEFT JOIN `tb_administrador_usuario` AS TS ON P.`gcp_supervisor_id` = TS.`usu_id`
         LEFT JOIN `tb_gestion_coaching_paquete_escalamiento` AS ESC ON P.`gcp_id` = ESC.`gcpe_paquete`
         WHERE P.`gcp_activo` = 1 {$filtro_alcance_sql} {$condiciones}
         ORDER BY P.`gcp_registro_fecha` DESC
         LIMIT ?, ?";

    $parametros_pagina = $parametros;
    $parametros_pagina[] = $inicio_pagina;
    $parametros_pagina[] = $registros_x_pagina;

    $stmt = $enlace_db->prepare($sql);
    $stmt->bind_param($tipos_bind . 'ii', ...$parametros_pagina);
    $stmt->execute();
    $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fechas por hito de trazabilidad (Asignado, Enviado a agente,
    // Respondido, Firmado, Cerrado) — ver coachingHitosTrazabilidad()
    // en lib/coaching_datos.php. Consulta SEPARADA y deliberadamente
    // simple, no se mete dentro del SQL principal de arriba (con su
    // propio conjunto de JOINs ya establecido).
    $hitos_por_paquete = coachingHitosTrazabilidad($enlace_db, array_column($registros, 'gcp_id'));

    // Catálogos para los <select> de filtro
    $tipos_catalogo = $enlace_db->query("SELECT `gct_codigo`, `gct_nombre` FROM `tb_gestion_coaching_tipo` WHERE `gct_activo`=1 ORDER BY `gct_nombre`")->fetch_all(MYSQLI_ASSOC);
    $estados_catalogo = $enlace_db->query("SELECT `gce_codigo`, `gce_nombre` FROM `tb_gestion_coaching_estado` WHERE `gce_activo`=1 ORDER BY `gce_orden`")->fetch_all(MYSQLI_ASSOC);

    function claseEstadoCoachingRep(string $gce_codigo): string
    {
        $mapa = [
            'BORRADOR' => 'coaching_estado_gris', 'ASIGNADO' => 'coaching_estado_azul',
            'PENDIENTE_SUPERVISOR' => 'coaching_estado_azul', 'PENDIENTE_AGENTE' => 'coaching_estado_naranja',
            'RESPONDIDO_AGENTE' => 'coaching_estado_azul', 'PENDIENTE_FIRMA_AGENTE' => 'coaching_estado_naranja',
            'EN_SEGUIMIENTO' => 'coaching_estado_morado', 'PENDIENTE_CIERRE' => 'coaching_estado_azul',
            'CERRADO' => 'coaching_estado_verde', 'RECHAZADO' => 'coaching_estado_rojo', 'ANULADO' => 'coaching_estado_gris',
        ];
        return $mapa[$gce_codigo] ?? 'coaching_estado_gris';
    }

    // Listas para el filtro Rol + Usuario — se traen las 3 siempre (no
    // solo la del rol activo) para que el JS pueda cambiar de rol sin
    // recargar la página.
    $usuarios_agente = coachingUsuariosPorRol($enlace_db, 'agente');
    $usuarios_supervisor = coachingUsuariosPorRol($enlace_db, 'supervisor');
    $usuarios_lider_calidad = coachingUsuariosPorRol($enlace_db, 'lider_calidad');

    // Query string para reusar los filtros actuales en el link de Excel/estadísticas
    // (SIN 'pagina' a propósito: esos exportables siempre traen el conjunto
    // COMPLETO que cumple el filtro, no solo la página que se está viendo).
    $query_filtros = http_build_query(['desde' => $fecha_desde, 'hasta' => $fecha_hasta, 'estado' => $filtro_estado, 'tipo' => $filtro_tipo, 'origen' => $filtro_origen, 'rol' => $filtro_rol, 'usuario_id' => $filtro_usuario_id]);

    /** Arma la URL de esta misma pantalla con una página específica, conservando los filtros actuales. */
    function coachingUrlReportePagina(int $pagina, string $query_filtros_base): string
    {
        return 'gestion_coaching_reporte.php?' . $query_filtros_base . '&pagina=' . $pagina;
    }
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <meta charset="UTF-8">
    <?php include("../config/configuracion_estilos.php"); ?>
    <style>
        .coaching_breadcrumb { font-size: 11px; color: #6E6E6E; margin-bottom: 10px; }
        .coaching_breadcrumb a { color: #4CAF50; }
        .coaching_estado_pill { display: inline-block; font-size: 11px; padding: 2px 10px; border-radius: 10px; }
        .coaching_estado_verde   { color: #00BF6F; border: solid 1px #00BF6F; background-color: rgba(40, 180, 99, 0.15); }
        .coaching_estado_naranja { color: #F39C12; border: solid 1px #F39C12; background-color: rgba(243, 156, 18, 0.15); }
        .coaching_estado_azul    { color: #175E83; border: solid 1px #175E83; background-color: rgba(23, 94, 131, 0.15); }
        .coaching_estado_morado  { color: #6C3483; border: solid 1px #6C3483; background-color: rgba(108, 52, 131, 0.15); }
        .coaching_estado_rojo    { color: #FF0000; border: solid 1px #FF0000; background-color: rgba(255, 0, 0, 0.15); }
        .coaching_estado_gris    { color: #6E6E6E; border: solid 1px #6E6E6E; background-color: rgba(110, 110, 110, 0.15); }
        .coaching_filtros { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
        .coaching_filtros label { font-size: 11px; font-weight: bold; color: #1A1A1A; display: block; margin-bottom: 3px; }
        .coaching_filtros select, .coaching_filtros input { font-size: 12px; }
        .coaching_filtros_caja { background: #FFFFFF; border: 1px solid #F2F2F2; border-radius: 8px; padding: 15px; margin-bottom: 18px; }
        .coaching_tabla td.col-centro, .coaching_tabla th.col-centro { text-align: center; }
        .coaching_tabla td.col-izq, .coaching_tabla th.col-izq { text-align: left; }
        .coaching_tabla tbody tr:hover { background: #F1F8F2; }

        .coaching_kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .coaching_kpi { background: #FFFFFF; border: 1px solid #F2F2F2; border-left: 4px solid #4CAF50; border-radius: 8px; padding: 14px 16px; }
        .coaching_kpi .valor { font-size: 24px; font-weight: bold; color: #1A1A1A; line-height: 1; }
        .coaching_kpi .etiqueta { font-size: 11px; color: #6E6E6E; margin-top: 5px; }
        .coaching_kpi.borde_verde   { border-left-color: #00BF6F; }
        .coaching_kpi.borde_naranja { border-left-color: #F39C12; }
        .coaching_kpi.borde_azul    { border-left-color: #175E83; }
        .coaching_kpi.borde_rojo    { border-left-color: #FF0000; }
    </style>
</head>
<body>
    <?php
        include("../menu_principal.php");
        include("../menu_header.php");
    ?>
    <div class="contenido">
        <nav class="coaching_breadcrumb">
            <a href="gestion_coaching.php?pagina=1&id=null&est=Pendientes">Coaching</a>
            <span class="mx-1">/</span>
            <span>Reporte</span>
        </nav>

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
            <h4 class="titulo_seccion mb-0">Reporte de Coaching</h4>
            <div>
                <a href="gestion_coaching_estadisticas.php?<?php echo $query_filtros; ?>" class="btn-corp-2 px-3 py-1" style="border-radius:5px;">
                    <span class="fas fa-chart-pie"></span> Ver estadísticas
                </a>
                <a href="gestion_coaching_reporte_excel.php?<?php echo $query_filtros; ?>" class="btn-corp px-3 py-1" style="border-radius:5px;">
                    <span class="fas fa-file-excel"></span> Exportar a Excel
                </a>
                <?php if ($perfil_coaching === 'Administrador'): ?>
                    <a href="gestion_coaching_descarga_masiva.php?<?php echo $query_filtros; ?>" class="btn-corp px-3 py-1" style="border-radius:5px; background-color:#175E83;" title="Descarga en un .zip los PDF vigentes de todos los paquetes que cumplan el filtro actual">
                        <span class="fas fa-file-archive"></span> Descargar PDF (.zip)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($_GET['zip_error'])): ?>
            <div class="coaching_aviso_error" style="background: #FDEDED; border: 1px solid #FF0000; color: #FF0000; border-radius: 5px; padding: 10px 12px; font-size: 12px; margin-bottom: 15px;">
                <span class="fas fa-exclamation-circle"></span> <?php echo validar_output($_GET['zip_error']); ?>
            </div>
        <?php endif; ?>

        <div class="coaching_kpis">
            <div class="coaching_kpi">
                <div class="valor"><?php echo $kpi_total; ?></div>
                <div class="etiqueta">Total en el filtro</div>
            </div>
            <div class="coaching_kpi borde_naranja">
                <div class="valor"><?php echo $kpi_pendientes; ?></div>
                <div class="etiqueta">Pendientes / en curso</div>
            </div>
            <div class="coaching_kpi borde_verde">
                <div class="valor"><?php echo $kpi_cerrados; ?> <span style="font-size:13px; color:#6E6E6E;">(<?php echo $kpi_pct_cerrados; ?>%)</span></div>
                <div class="etiqueta">Cerrados</div>
            </div>
            <div class="coaching_kpi borde_rojo">
                <div class="valor"><?php echo $kpi_vencidos; ?></div>
                <div class="etiqueta">Vencidos sin cerrar</div>
            </div>
        </div>

        <div class="coaching_filtros_caja">
        <form method="GET" action="" class="coaching_filtros">
            <div>
                <label for="desde">Desde</label>
                <input type="date" name="desde" id="desde" class="form-control" value="<?php echo htmlspecialchars($fecha_desde); ?>">
            </div>
            <div>
                <label for="hasta">Hasta</label>
                <input type="date" name="hasta" id="hasta" class="form-control" value="<?php echo htmlspecialchars($fecha_hasta); ?>">
            </div>
            <div>
                <label for="estado">Estado</label>
                <select name="estado" id="estado" class="form-control">
                    <option value="Todos">Todos</option>
                    <?php foreach ($estados_catalogo as $e): ?>
                        <option value="<?php echo htmlspecialchars($e['gce_codigo']); ?>" <?php echo $filtro_estado === $e['gce_codigo'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['gce_nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="tipo">Tipo</label>
                <select name="tipo" id="tipo" class="form-control">
                    <option value="Todos">Todos</option>
                    <?php foreach ($tipos_catalogo as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['gct_codigo']); ?>" <?php echo $filtro_tipo === $t['gct_codigo'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['gct_nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="origen">Origen</label>
                <select name="origen" id="origen" class="form-control">
                    <option value="Todos">Todos</option>
                    <option value="monitoreo" <?php echo $filtro_origen === 'monitoreo' ? 'selected' : ''; ?>>Monitoreo (automático)</option>
                    <option value="global" <?php echo $filtro_origen === 'global' ? 'selected' : ''; ?>>Global (manual)</option>
                </select>
            </div>
            <div>
                <label for="rol">Rol</label>
                <select name="rol" id="rol" class="form-control">
                    <option value="">Todos</option>
                    <option value="agente" <?php echo $filtro_rol === 'agente' ? 'selected' : ''; ?>>Agente</option>
                    <option value="supervisor" <?php echo $filtro_rol === 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                    <option value="lider_calidad" <?php echo $filtro_rol === 'lider_calidad' ? 'selected' : ''; ?>>Líder de Calidad</option>
                </select>
            </div>
            <div>
                <label for="usuario_id">Usuario</label>
                <select name="usuario_id" id="usuario_id" class="form-control" <?php echo $filtro_rol === '' ? 'disabled' : ''; ?>>
                    <option value="">Todos</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn-corp px-3 py-2" style="border-radius:5px; border:0;">
                    <span class="fas fa-filter"></span> Filtrar
                </button>
            </div>
        </form>
        </div>

        <script>
        (function () {
            var USUARIOS_POR_ROL = {
                agente: <?php echo json_encode($usuarios_agente, JSON_UNESCAPED_UNICODE); ?>,
                supervisor: <?php echo json_encode($usuarios_supervisor, JSON_UNESCAPED_UNICODE); ?>,
                lider_calidad: <?php echo json_encode($usuarios_lider_calidad, JSON_UNESCAPED_UNICODE); ?>
            };
            var usuarioPreseleccionado = <?php echo json_encode($filtro_usuario_id); ?>;
            var selectRol = document.getElementById('rol');
            var selectUsuario = document.getElementById('usuario_id');

            function poblarUsuarios() {
                var rol = selectRol.value;
                selectUsuario.innerHTML = '<option value="">Todos</option>';
                if (rol === '') {
                    selectUsuario.disabled = true;
                    return;
                }
                selectUsuario.disabled = false;
                (USUARIOS_POR_ROL[rol] || []).forEach(function (u) {
                    var opcion = document.createElement('option');
                    opcion.value = u.usu_id;
                    opcion.textContent = u.usu_nombres_apellidos;
                    if (u.usu_id === usuarioPreseleccionado) { opcion.selected = true; }
                    selectUsuario.appendChild(opcion);
                });
            }
            selectRol.addEventListener('change', function () {
                usuarioPreseleccionado = ''; // al cambiar de rol a mano, no arrastra la preselección anterior
                poblarUsuarios();
            });
            poblarUsuarios();
        })();
        </script>

        <p style="font-size:11px; color:#6E6E6E;">
            <?php echo $kpi_total; ?> resultado(s) en total <?php echo $numero_paginas > 1 ? '— página ' . $pagina . ' de ' . $numero_paginas : ''; ?>
        </p>

        <div class="div_tabla">
            <table class="tabla_list coaching_tabla">
                <thead>
                    <tr>
                        <th class="col-izq">Código</th>
                        <th class="col-centro">Origen</th>
                        <th class="col-centro">Tipo</th>
                        <th class="col-izq">Agente</th>
                        <th class="col-izq">Supervisor</th>
                        <th class="col-izq">Indicadores</th>
                        <th class="col-centro">Estado</th>
                        <th class="col-centro">Creado</th>
                        <th class="col-centro">Asignado</th>
                        <th class="col-centro">Enviado a agente</th>
                        <th class="col-centro">Respondido</th>
                        <th class="col-centro">Firmado</th>
                        <th class="col-centro">Cerrado</th>
                        <th class="col-centro"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($registros) === 0): ?>
                        <tr><td colspan="14" class="text-center" style="font-size:12px; color:#6E6E6E; padding:15px;">No hay resultados para los filtros seleccionados.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($registros as $r): ?>
                        <tr class="tabla_contenido_1">
                            <td class="col-izq"><strong><?php echo validar_output($r['gcp_id']); ?></strong></td>
                            <td class="col-centro"><?php echo validar_output(ucfirst($r['gcp_origen_tipo'])); ?></td>
                            <td class="col-centro"><?php echo validar_output($r['gct_nombre']); ?></td>
                            <td class="col-izq"><?php echo validar_output($r['agente_nombre'] ?? '—'); ?></td>
                            <td class="col-izq"><?php echo validar_output($r['supervisor_nombre'] ?? '—'); ?></td>
                            <td class="col-izq" style="font-size:11px; max-width:200px;"><?php echo validar_output($r['indicadores_multiples'] ?? '—'); ?></td>
                            <td class="col-centro"><span class="coaching_estado_pill <?php echo claseEstadoCoachingRep($r['gce_codigo']); ?>"><?php echo validar_output($r['gce_nombre']); ?></span></td>
                            <td class="col-centro"><?php echo date('d/m/Y', strtotime($r['gcp_registro_fecha'])); ?></td>
                            <?php
                                $hito_asignado = coachingFechaHito($hitos_por_paquete, $r['gcp_id'], 'asignado');
                                $hito_enviado = coachingFechaHito($hitos_por_paquete, $r['gcp_id'], 'enviado_agente');
                                $hito_respondido = coachingFechaHito($hitos_por_paquete, $r['gcp_id'], 'respondido');
                                $hito_firmado = coachingFechaHito($hitos_por_paquete, $r['gcp_id'], 'firmado');
                                $hito_cerrado = coachingFechaHito($hitos_por_paquete, $r['gcp_id'], 'cerrado');
                            ?>
                            <td class="col-centro" style="font-size:11px;"><?php echo $hito_asignado ? date('d/m/Y H:i', strtotime($hito_asignado)) : '—'; ?></td>
                            <td class="col-centro" style="font-size:11px;"><?php echo $hito_enviado ? date('d/m/Y H:i', strtotime($hito_enviado)) : '—'; ?></td>
                            <td class="col-centro" style="font-size:11px;"><?php echo $hito_respondido ? date('d/m/Y H:i', strtotime($hito_respondido)) : '—'; ?></td>
                            <td class="col-centro" style="font-size:11px;"><?php echo $hito_firmado ? date('d/m/Y H:i', strtotime($hito_firmado)) : '—'; ?></td>
                            <td class="col-centro" style="font-size:11px;"><?php echo $hito_cerrado ? date('d/m/Y H:i', strtotime($hito_cerrado)) : '—'; ?></td>
                            <td class="col-centro">
                                <a href="gestion_coaching_ver.php?reg=<?php echo base64_encode($r['gcp_id']); ?>" class="btn-corp" style="width:26px;height:26px;padding:0;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;" title="Ver">
                                    <span class="fas fa-eye"></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($numero_paginas > 1): ?>
            <nav class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php for ($p = 1; $p <= $numero_paginas; $p++): ?>
                        <li class="page-item <?php echo $p === $pagina ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo coachingUrlReportePagina($p, $query_filtros); ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
    <?php include("../footer.php"); include("../config/configuracion_js.php"); ?>
</body>
</html>
