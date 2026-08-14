<?php
    $modulo_plataforma = "Coaching-Reportes";

    require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");
    require_once("lib/coaching_seguridad.php");
    require_once("lib/coaching_documentos.php");

    // ---------------------------------------------------------------------
    // Descarga masiva de documentos PDF de Coaching en un .zip — SOLO
    // Administrador (Coordinador Nacional en la convención de este
    // módulo). No es una restricción de solo-lectura como el resto de
    // reportería: exportar en bloque todos los soportes documentales de
    // un periodo es una operación sensible (posible evidencia de procesos
    // disciplinarios incluidos), por eso el alcance queda deliberadamente
    // más cerrado que "cualquiera con acceso a Coaching-Reportes".
    // ---------------------------------------------------------------------
    $tiene_reportes = isset($_SESSION['modulos_acceso_permisos']['Coaching-Reportes']) && $_SESSION['modulos_acceso_permisos']['Coaching-Reportes'] !== '';
    $perfil_coaching = coachingPerfilUsuarioActual();
    if (!$tiene_reportes || $perfil_coaching !== 'Administrador') {
        header("Location:../permiso_denegado.php");
        exit;
    }

    // ---- Filtros (mismos nombres/semántica que gestion_coaching_reporte.php,
    // para que el .zip corresponda exactamente a lo que la persona ya
    // filtró en pantalla) ----
    $fecha_desde = validar_input($_GET['desde'] ?? '');
    $fecha_hasta = validar_input($_GET['hasta'] ?? '');
    if ($fecha_desde === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) { $fecha_desde = date('Y-m-d', strtotime('-90 days')); }
    if ($fecha_hasta === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) { $fecha_hasta = date('Y-m-d'); }

    $filtro_estado = validar_input($_GET['estado'] ?? '');
    $filtro_tipo = validar_input($_GET['tipo'] ?? '');
    $filtro_origen = validar_input($_GET['origen'] ?? '');

    // Límite de seguridad: evita que un rango demasiado amplio genere un
    // .zip descomunal o agote el tiempo de ejecución. Si se excede, se
    // pide acotar el periodo en vez de fallar a medio camino.
    const COACHING_DESCARGA_MASIVA_LIMITE = 500;

    $condiciones = " AND DATE(P.`gcp_registro_fecha`) BETWEEN ? AND ? ";
    $parametros = [$fecha_desde, $fecha_hasta];

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

    // Mismo filtro Rol + Usuario que gestion_coaching_reporte.php — ver
    // nota extendida allá.
    $filtro_rol = validar_input($_GET['rol'] ?? '');
    if (!in_array($filtro_rol, ['agente', 'supervisor', 'lider_calidad'], true)) { $filtro_rol = ''; }
    $filtro_usuario_id = validar_input($_GET['usuario_id'] ?? '');
    if ($filtro_rol !== '' && $filtro_usuario_id !== '') {
        $condiciones .= $filtro_rol === 'supervisor' ? " AND P.`gcp_supervisor_id` = ? " : " AND P.`gcp_agente_id` = ? ";
        $parametros[] = $filtro_usuario_id;
    }

    $tipos_bind = str_repeat('s', count($parametros));

    // Solo interesan paquetes cuyo tipo real SÍ genera documento (Retroalimentación
    // / Acta de Compromiso / Llamado Verbal / No Renovación / Escalamiento
    // Disciplinario, todos los que en el flujo normal pasan por
    // generarDocumentoCoaching() con tipo_documento 'Retroalimentacion' o
    // 'Acta_Compromiso' — ver gestion_coaching_responder_agente.php).
    // Administrador ya tiene alcance amplio de por sí (usuarioPuedeVerPaquete),
    // así que aquí no se excluye Escalamiento Disciplinario: para este
    // perfil sí es información legítima de documentar/exportar.
    $sql_paquetes =
        "SELECT P.`gcp_id`, T.`gct_codigo`, TA.`usu_nombres_apellidos` AS agente_nombre
         FROM `tb_gestion_coaching_paquete` AS P
         LEFT JOIN `tb_gestion_coaching_estado` AS E ON P.`gcp_estado_id` = E.`gce_id`
         LEFT JOIN `tb_gestion_coaching_tipo` AS T ON P.`gcp_tipo_id` = T.`gct_id`
         LEFT JOIN `tb_administrador_usuario` AS TA ON P.`gcp_agente_id` = TA.`usu_id`
         WHERE P.`gcp_activo` = 1 {$condiciones}
         ORDER BY P.`gcp_registro_fecha` ASC
         LIMIT " . (COACHING_DESCARGA_MASIVA_LIMITE + 1);

    $consulta_paquetes = $enlace_db->prepare($sql_paquetes);
    $consulta_paquetes->bind_param($tipos_bind, ...$parametros);
    $consulta_paquetes->execute();
    $paquetes = $consulta_paquetes->get_result()->fetch_all(MYSQLI_ASSOC);

    if (count($paquetes) > COACHING_DESCARGA_MASIVA_LIMITE) {
        header("Location:gestion_coaching_reporte.php?" . http_build_query(['desde' => $fecha_desde, 'hasta' => $fecha_hasta, 'estado' => $filtro_estado, 'tipo' => $filtro_tipo, 'origen' => $filtro_origen, 'rol' => $filtro_rol, 'usuario_id' => $filtro_usuario_id]) . "&zip_error=" . urlencode('El periodo seleccionado tiene más de ' . COACHING_DESCARGA_MASIVA_LIMITE . ' paquetes. Acote el rango de fechas e intente de nuevo.'));
        exit;
    }

    // ---- Recolectar el documento VIGENTE de cada paquete (Retroalimentación
    // o Acta de Compromiso, el que corresponda) ----
    $documentos_a_incluir = [];
    foreach ($paquetes as $p) {
        $doc = obtenerDocumentoVigente($enlace_db, $p['gcp_id'], 'Retroalimentacion')
            ?? obtenerDocumentoVigente($enlace_db, $p['gcp_id'], 'Acta_Compromiso');
        if ($doc && is_readable($doc['gcd_ruta'])) {
            $documentos_a_incluir[] = ['paquete' => $p, 'documento' => $doc];
        }
    }

    if (count($documentos_a_incluir) === 0) {
        header("Location:gestion_coaching_reporte.php?" . http_build_query(['desde' => $fecha_desde, 'hasta' => $fecha_hasta, 'estado' => $filtro_estado, 'tipo' => $filtro_tipo, 'origen' => $filtro_origen, 'rol' => $filtro_rol, 'usuario_id' => $filtro_usuario_id]) . "&zip_error=" . urlencode('No hay documentos PDF generados para los filtros seleccionados.'));
        exit;
    }

    if (!class_exists('ZipArchive')) {
        header("Location:gestion_coaching_reporte.php?zip_error=" . urlencode('La extensión ZipArchive de PHP no está instalada en el servidor. Contacte al administrador del sistema.'));
        exit;
    }

    // ---- Construir el .zip en un temporal, fuera del directorio público ----
    set_time_limit(180);

    $nombre_zip_temporal = 'coaching_descarga_' . bin2hex(random_bytes(8)) . '.zip';
    $ruta_zip_temporal = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $nombre_zip_temporal;

    $zip = new ZipArchive();
    if ($zip->open($ruta_zip_temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header("Location:gestion_coaching_reporte.php?zip_error=" . urlencode('No fue posible crear el archivo .zip en el servidor.'));
        exit;
    }

    $nombres_usados = [];
    foreach ($documentos_a_incluir as $item) {
        $paquete = $item['paquete'];
        $documento = $item['documento'];

        // Nombre legible dentro del .zip: CO0000000001 - Nombre Agente - v2.pdf
        // (sin caracteres que compliquen el sistema de archivos del que lo descarga).
        $nombre_agente_limpio = preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($paquete['agente_nombre'] ?? 'Sin_nombre'));
        $nombre_base = trim($paquete['gcp_id'] . ' - ' . $nombre_agente_limpio . ' - v' . (int) $documento['gcd_version']);
        $nombre_en_zip = $nombre_base . '.pdf';

        // Defensa simple contra colisiones de nombre dentro del mismo .zip
        // (paquetes distintos con agente homónimo truncado, etc.).
        $sufijo = 2;
        while (in_array($nombre_en_zip, $nombres_usados, true)) {
            $nombre_en_zip = $nombre_base . ' (' . $sufijo . ').pdf';
            $sufijo++;
        }
        $nombres_usados[] = $nombre_en_zip;

        $zip->addFile($documento['gcd_ruta'], $nombre_en_zip);
    }
    $zip->close();

    if (!is_file($ruta_zip_temporal)) {
        header("Location:gestion_coaching_reporte.php?zip_error=" . urlencode('El archivo .zip no se generó correctamente.'));
        exit;
    }

    $nombre_descarga = 'coaching_documentos_' . $fecha_desde . '_a_' . $fecha_hasta . '.zip';

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
    header('Content-Length: ' . filesize($ruta_zip_temporal));
    header('X-Content-Type-Options: nosniff');
    readfile($ruta_zip_temporal);
    @unlink($ruta_zip_temporal);
    exit;
