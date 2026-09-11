<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_helpers.php';

$titulo_header = 'Alertas Correos | Reportería';

function acrBind(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || !$params) {
        return;
    }
    $refs = [];
    foreach ($params as $k => $v) {
        $refs[$k] = &$params[$k];
    }
    $stmt->bind_param($types, ...$refs);
}

function acrFetchAll(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    acrBind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function acrFetchOne(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $rows = acrFetchAll($db, $sql, $types, $params);
    return $rows[0] ?? [];
}

function acrPct(int $num, int $den): float
{
    return $den > 0 ? round(($num / $den) * 100, 1) : 0.0;
}

function acrHrs(?string $inicio, ?string $fin): ?float
{
    if (!$inicio || !$fin) {
        return null;
    }
    $a = strtotime($inicio);
    $b = strtotime($fin);
    if ($a === false || $b === false || $b < $a) {
        return null;
    }
    return round(($b - $a) / 3600, 1);
}

$hoy = date('Y-m-d');
$primerDia = date('Y-m-01');
$filtros = [
    'desde' => trim((string)($_GET['desde'] ?? $primerDia)),
    'hasta' => trim((string)($_GET['hasta'] ?? $hoy)),
    'regional' => trim((string)($_GET['regional'] ?? '')),
    'centro' => trim((string)($_GET['centro'] ?? '')),
    'categoria' => trim((string)($_GET['categoria'] ?? '')),
    'estado' => trim((string)($_GET['estado'] ?? '')),
    'tipo_gestion' => trim((string)($_GET['tipo_gestion'] ?? '')),
    'origen' => trim((string)($_GET['origen'] ?? '')),
];

$where = ['acc_activo = 1'];
$types = '';
$params = [];
if ($filtros['desde'] !== '') { $where[] = 'DATE(acc_fecha_creacion) >= ?'; $types .= 's'; $params[] = $filtros['desde']; }
if ($filtros['hasta'] !== '') { $where[] = 'DATE(acc_fecha_creacion) <= ?'; $types .= 's'; $params[] = $filtros['hasta']; }
if ($filtros['regional'] !== '') { $where[] = 'acc_regional = ?'; $types .= 's'; $params[] = $filtros['regional']; }
if ($filtros['centro'] !== '') { $where[] = 'acc_centro_zonal = ?'; $types .= 's'; $params[] = $filtros['centro']; }
if ($filtros['categoria'] !== '') { $where[] = 'acc_categoria = ?'; $types .= 's'; $params[] = $filtros['categoria']; }
if ($filtros['estado'] !== '') { $where[] = 'acc_estado = ?'; $types .= 's'; $params[] = $filtros['estado']; }
if ($filtros['tipo_gestion'] !== '') { $where[] = 'acc_tipo_gestion = ?'; $types .= 's'; $params[] = $filtros['tipo_gestion']; }
if ($filtros['origen'] !== '') { $where[] = 'acc_origen = ?'; $types .= 's'; $params[] = $filtros['origen']; }
$whereSql = implode(' AND ', $where);

$regionales = acrFetchAll($enlace_db, "SELECT DISTINCT acc_regional valor FROM tb_alerta_correo_caso WHERE acc_activo=1 AND acc_regional<>'' ORDER BY acc_regional");
$centros = acrFetchAll($enlace_db, "SELECT DISTINCT acc_centro_zonal valor FROM tb_alerta_correo_caso WHERE acc_activo=1 AND acc_centro_zonal IS NOT NULL AND acc_centro_zonal<>''" . ($filtros['regional'] !== '' ? " AND acc_regional=?" : '') . " ORDER BY acc_centro_zonal", $filtros['regional'] !== '' ? 's' : '', $filtros['regional'] !== '' ? [$filtros['regional']] : []);
$categorias = acrFetchAll($enlace_db, "SELECT DISTINCT acc_categoria valor FROM tb_alerta_correo_caso WHERE acc_activo=1 AND acc_categoria IS NOT NULL AND acc_categoria<>'' ORDER BY acc_categoria");

if (($_GET['export'] ?? '') === 'csv') {
    $sql = "SELECT acc_radicado,acc_sim,acc_fecha_alerta,acc_fecha_atencion,acc_regional,acc_centro_zonal,acc_categoria,acc_subcategoria,acc_tipo_alerta,acc_tipo_gestion,acc_tiempo_espera_rango,acc_origen,acc_estado,acc_usuario_creador,acc_usuario_aprobador,acc_fecha_aprobacion,acc_envia_correo,acc_fecha_creacion,acc_fecha_actualizacion,acc_descripcion,acc_justificacion,acc_observacion FROM tb_alerta_correo_caso WHERE $whereSql ORDER BY acc_fecha_creacion DESC";
    $rows = acrFetchAll($enlace_db, $sql, $types, $params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="alertas_correos_reporte_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Radicado','SIM','Fecha alerta','Fecha atención','Regional','Centro Zonal','Categoría','Subcategoría','Prioridad','Tipo gestión','Rango espera','Origen','Estado','Creado por','Aprobado por','Fecha aprobación','Envía correo','Fecha creación','Última actualización','Descripción','Justificación','Observación'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['acc_radicado'],$r['acc_sim'],$r['acc_fecha_alerta'],$r['acc_fecha_atencion'],$r['acc_regional'],$r['acc_centro_zonal'],$r['acc_categoria'],$r['acc_subcategoria'],$r['acc_tipo_alerta'],$r['acc_tipo_gestion'],$r['acc_tiempo_espera_rango'],$r['acc_origen'],$r['acc_estado'],$r['acc_usuario_creador'],$r['acc_usuario_aprobador'],$r['acc_fecha_aprobacion'],((int)$r['acc_envia_correo']===1?'SI':'NO'),$r['acc_fecha_creacion'],$r['acc_fecha_actualizacion'],$r['acc_descripcion'],$r['acc_justificacion'],$r['acc_observacion']
        ], ';');
    }
    fclose($out);
    exit;
}

$resumen = acrFetchOne($enlace_db, "SELECT
    COUNT(*) total,
    SUM(acc_estado='PENDIENTE_REVISION') pendientes,
    SUM(acc_estado='PENDIENTE_SUBSANACION') subsanacion,
    SUM(acc_estado='PENDIENTE_REVISION_SUBSANACION') subsanados_revision,
    SUM(acc_estado='APROBADO') aprobadas,
    SUM(acc_estado='RECHAZADO') rechazadas,
    SUM(acc_tipo_gestion='INFORMATIVA') informativas,
    SUM(acc_tipo_gestion='NOTIFICABLE') notificables,
    SUM(acc_origen='CARGA_EXCEL') masivas,
    SUM(acc_origen<>'CARGA_EXCEL') manuales
FROM tb_alerta_correo_caso WHERE $whereSql", $types, $params);

$kpi = array_map('intval', array_merge([
    'total'=>0,'pendientes'=>0,'subsanacion'=>0,'subsanados_revision'=>0,'aprobadas'=>0,'rechazadas'=>0,
    'informativas'=>0,'notificables'=>0,'masivas'=>0,'manuales'=>0
], $resumen));
$decididos = $kpi['aprobadas'] + $kpi['rechazadas'];
$porcentajeAprobacion = acrPct($kpi['aprobadas'], $decididos);
$porcentajeInformativas = acrPct($kpi['informativas'], $kpi['total']);

$tiempoDecision = acrFetchOne($enlace_db, "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE,acc_fecha_creacion,acc_fecha_aprobacion))/60,1) promedio_horas FROM tb_alerta_correo_caso WHERE $whereSql AND acc_fecha_aprobacion IS NOT NULL", $types, $params);
$promedioDecision = isset($tiempoDecision['promedio_horas']) && $tiempoDecision['promedio_horas'] !== null ? (float)$tiempoDecision['promedio_horas'] : 0.0;

$topCategorias = acrFetchAll($enlace_db, "SELECT COALESCE(NULLIF(acc_categoria,''),'Sin categoría') etiqueta, COUNT(*) total FROM tb_alerta_correo_caso WHERE $whereSql GROUP BY COALESCE(NULLIF(acc_categoria,''),'Sin categoría') ORDER BY total DESC LIMIT 8", $types, $params);
$topRegionales = acrFetchAll($enlace_db, "SELECT COALESCE(NULLIF(acc_regional,''),'Sin regional') etiqueta, COUNT(*) total FROM tb_alerta_correo_caso WHERE $whereSql GROUP BY COALESCE(NULLIF(acc_regional,''),'Sin regional') ORDER BY total DESC LIMIT 10", $types, $params);
$topCentros = acrFetchAll($enlace_db, "SELECT COALESCE(NULLIF(acc_centro_zonal,''),'Sin centro zonal') etiqueta, COUNT(*) total FROM tb_alerta_correo_caso WHERE $whereSql GROUP BY COALESCE(NULLIF(acc_centro_zonal,''),'Sin centro zonal') ORDER BY total DESC LIMIT 10", $types, $params);
$porEstado = acrFetchAll($enlace_db, "SELECT acc_estado etiqueta, COUNT(*) total FROM tb_alerta_correo_caso WHERE $whereSql GROUP BY acc_estado ORDER BY total DESC", $types, $params);
$porOrigen = acrFetchAll($enlace_db, "SELECT CASE WHEN acc_origen='CARGA_EXCEL' THEN 'Carga masiva' ELSE 'Registro manual' END etiqueta, COUNT(*) total FROM tb_alerta_correo_caso WHERE $whereSql GROUP BY CASE WHEN acc_origen='CARGA_EXCEL' THEN 'Carga masiva' ELSE 'Registro manual' END ORDER BY total DESC", $types, $params);
$esperas = acrFetchAll($enlace_db, "SELECT COALESCE(NULLIF(acc_tiempo_espera_rango,''),'PENDIENTE_CLASIFICAR') etiqueta, COUNT(*) total FROM tb_alerta_correo_caso WHERE $whereSql AND acc_tipo_gestion='INFORMATIVA' GROUP BY COALESCE(NULLIF(acc_tiempo_espera_rango,''),'PENDIENTE_CLASIFICAR') ORDER BY total DESC", $types, $params);
$evolucion = acrFetchAll($enlace_db, "SELECT DATE(acc_fecha_creacion) fecha, COUNT(*) total, SUM(acc_tipo_gestion='INFORMATIVA') informativas, SUM(acc_estado='APROBADO') aprobadas FROM tb_alerta_correo_caso WHERE $whereSql GROUP BY DATE(acc_fecha_creacion) ORDER BY fecha", $types, $params);

$cargas = acrFetchOne($enlace_db, "SELECT COUNT(*) archivos, COALESCE(SUM(acg_total_registros),0) filas, COALESCE(SUM(acg_registros_creados),0) creados, COALESCE(SUM(acg_registros_duplicados),0) duplicados, COALESCE(SUM(acg_registros_invalidos),0) invalidos, COALESCE(SUM(acg_registros_informativos),0) informativos FROM tb_alerta_correo_carga WHERE acg_tipo IN ('ALERTAS','ALERTA_MASIVA','CARGA_ALERTAS')" . ($filtros['desde'] !== '' ? " AND DATE(acg_fecha)>=?" : '') . ($filtros['hasta'] !== '' ? " AND DATE(acg_fecha)<=?" : ''), ($filtros['desde'] !== '' ? 's' : '') . ($filtros['hasta'] !== '' ? 's' : ''), array_values(array_filter([$filtros['desde'],$filtros['hasta']], fn($v) => $v !== '')));

$notif = acrFetchOne($enlace_db, "SELECT COUNT(*) generadas, SUM(acn_estado='PENDIENTE') pendientes, SUM(acn_ultimo_error IS NOT NULL AND acn_ultimo_error<>'') con_error, SUM(acn_fecha_envio IS NOT NULL) con_fecha_envio FROM tb_alerta_correo_notificacion n INNER JOIN tb_alerta_correo_caso c ON c.acc_id=n.acn_caso_id WHERE $whereSql", $types, $params);

$cobertura = acrFetchOne($enlace_db, "SELECT
  SUM(acp_tipo='REGIONAL' AND acp_activo=1) regionales,
  SUM(acp_tipo='CENTRO_ZONAL' AND acp_activo=1) centros,
  SUM(acp_tipo='REGIONAL' AND acp_activo=1 AND EXISTS(SELECT 1 FROM tb_alerta_correo_responsable r WHERE r.acr_activo=1 AND r.acr_nivel='REGIONAL' AND UPPER(TRIM(r.acr_regional))=UPPER(TRIM(p.acp_regional)))) regionales_cubiertas,
  SUM(acp_tipo='CENTRO_ZONAL' AND acp_activo=1 AND EXISTS(SELECT 1 FROM tb_alerta_correo_responsable r WHERE r.acr_activo=1 AND r.acr_punto_atencion_id=p.acp_id)) centros_cubiertos
FROM tb_alerta_correo_punto_atencion p");

$ultimos = acrFetchAll($enlace_db, "SELECT acc_id,acc_radicado,acc_sim,acc_fecha_creacion,acc_regional,acc_centro_zonal,acc_categoria,acc_tipo_gestion,acc_tiempo_espera_rango,acc_origen,acc_estado,acc_fecha_aprobacion FROM tb_alerta_correo_caso WHERE $whereSql ORDER BY acc_fecha_creacion DESC LIMIT 100", $types, $params);

function acrLabelEstado(string $estado): string {
    $map = [
        'PENDIENTE_REVISION'=>'Pendiente de revisión','PENDIENTE_SUBSANACION'=>'Pendiente de subsanación',
        'PENDIENTE_REVISION_SUBSANACION'=>'Subsanado por revisar','APROBADO'=>'Aprobado','RECHAZADO'=>'Rechazado'
    ];
    return $map[$estado] ?? str_replace('_',' ',ucwords(strtolower($estado),'_'));
}
function acrLabelEspera(string $v): string {
    $map = [
        'MAS_DE_1_HORA'=>'Más de 1 hora','2_HORAS_O_MAS'=>'2 horas o más','3_HORAS_O_MAS'=>'3 horas o más',
        '4_HORAS_O_MAS'=>'4 horas o más','5_HORAS_O_MAS'=>'5 horas o más','6_HORAS_O_MAS'=>'6 horas o más',
        '7_HORAS_O_MAS'=>'7 horas o más','PENDIENTE_CLASIFICAR'=>'Pendiente por clasificar'
    ];
    return $map[$v] ?? ($v !== '' ? $v : 'No aplica');
}

$queryExport = $_GET;
$queryExport['export'] = 'csv';
$queryExportString = http_build_query($queryExport);
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260908">
    <style>
        .acr-page{padding-bottom:105px}.acr-filter{background:#fff;border:1px solid #49b84f;border-radius:12px;padding:18px;margin-bottom:18px}.acr-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.acr-kpi{background:#fff;border:1px solid #dfe5e8;border-radius:12px;padding:16px;min-height:112px;box-shadow:0 2px 8px rgba(0,0,0,.04)}.acr-kpi__label{font-size:.88rem;color:#627080;font-weight:700}.acr-kpi__value{font-size:2rem;font-weight:800;color:#17212b;margin-top:8px}.acr-kpi__meta{font-size:.78rem;color:#73808d;margin-top:4px}.acr-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}.acr-card{background:#fff;border:1px solid #49b84f;border-radius:12px;overflow:hidden}.acr-card__head{background:#49b84f;color:#fff;padding:13px 16px;display:flex;justify-content:space-between;align-items:center}.acr-card__title{font-size:1rem;font-weight:800;margin:0}.acr-card__body{padding:16px}.acr-bar-row{display:grid;grid-template-columns:minmax(150px,1.8fr) 3fr 55px;align-items:center;gap:10px;margin-bottom:10px}.acr-bar-label{font-size:.86rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.acr-bar-track{height:12px;background:#edf2f3;border-radius:999px;overflow:hidden}.acr-bar-fill{height:100%;background:#49b84f;border-radius:999px}.acr-bar-value{text-align:right;font-weight:800}.acr-mini-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.acr-mini{border:1px solid #e2e8eb;border-radius:10px;padding:12px;background:#fafcfc}.acr-mini b{display:block;font-size:1.35rem}.acr-table-wrap{overflow:auto}.acr-table{min-width:1150px}.acr-muted{color:#6d7884}.acr-pill{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:.75rem;font-weight:800;background:#eef4f6}.acr-pill--green{background:#e8f6ea;color:#18742d}.acr-pill--blue{background:#e8f4ff;color:#0b69b7}.acr-pill--orange{background:#fff1dc;color:#9a5800}.acr-pill--red{background:#ffe6e8;color:#a51f2b}.acr-actions{display:flex;gap:8px;flex-wrap:wrap}.acr-chart{width:100%;height:285px}.acr-empty{padding:28px;text-align:center;color:#6f7a86}.acr-section-note{font-size:.82rem;color:#677381}.acr-top-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.acr-download{background:#49b84f;border:1px solid #49b84f;color:#fff!important;font-weight:700}.acr-download:hover{background:#36983d;border-color:#36983d;color:#fff!important}.acr-reset{border:1px solid #dc3545;color:#dc3545;background:#fff;font-weight:700}.acr-reset:hover{background:#dc3545;color:#fff}.acr-page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}
        @media(max-width:1199.98px){.acr-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.acr-grid-2{grid-template-columns:1fr}}
        @media(max-width:767.98px){.acr-page{padding-bottom:125px}.acr-kpis{grid-template-columns:1fr}.acr-page-header{flex-direction:column}.acr-top-actions{width:100%}.acr-top-actions .btn{flex:1 1 100%}.acr-mini-grid{grid-template-columns:1fr}.acr-bar-row{grid-template-columns:1.4fr 2fr 45px}}
    </style>
</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>
<div class="contenido ac-module acr-page">
    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a><span class="ac-separator">/</span><a href="alerta_correos.php">Alertas Correos</a><span class="ac-separator">/</span><span>Reportería</span>
    </nav>

    <header class="acr-page-header">
        <div>
            <h1 class="ac-page-title"><span class="fas fa-chart-bar" aria-hidden="true"></span> Reportería</h1>
            <p class="ac-page-subtitle mb-0">Tablero ejecutivo para analizar volumen, causas, territorio, gestión, alertas informativas, calidad de cargas y notificaciones.</p>
        </div>
        <div class="acr-top-actions">
            <a class="btn acr-download" href="?<?php echo acEscape($queryExportString); ?>"><span class="fas fa-file-download"></span> Descargar reporte</a>
            <a class="btn acr-reset" href="alerta_correos_reporteria.php"><span class="fas fa-undo"></span> Limpiar filtros</a>
        </div>
    </header>

    <form class="acr-filter" method="get">
        <div class="form-row">
            <div class="form-group col-6 col-lg-2"><label>Desde</label><input type="date" class="form-control" name="desde" value="<?php echo acEscape($filtros['desde']); ?>"></div>
            <div class="form-group col-6 col-lg-2"><label>Hasta</label><input type="date" class="form-control" name="hasta" value="<?php echo acEscape($filtros['hasta']); ?>"></div>
            <div class="form-group col-12 col-md-6 col-lg-2"><label>Regional</label><select class="custom-select" name="regional"><option value="">Todas</option><?php foreach($regionales as $r): ?><option value="<?php echo acEscape($r['valor']); ?>" <?php echo $filtros['regional']===$r['valor']?'selected':''; ?>><?php echo acEscape($r['valor']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group col-12 col-md-6 col-lg-2"><label>Centro Zonal</label><select class="custom-select" name="centro"><option value="">Todos</option><?php foreach($centros as $r): ?><option value="<?php echo acEscape($r['valor']); ?>" <?php echo $filtros['centro']===$r['valor']?'selected':''; ?>><?php echo acEscape($r['valor']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group col-12 col-md-6 col-lg-2"><label>Categoría</label><select class="custom-select" name="categoria"><option value="">Todas</option><?php foreach($categorias as $r): ?><option value="<?php echo acEscape($r['valor']); ?>" <?php echo $filtros['categoria']===$r['valor']?'selected':''; ?>><?php echo acEscape($r['valor']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group col-12 col-md-6 col-lg-2"><label>Estado</label><select class="custom-select" name="estado"><option value="">Todos</option><?php foreach(['PENDIENTE_REVISION','PENDIENTE_SUBSANACION','PENDIENTE_REVISION_SUBSANACION','APROBADO','RECHAZADO'] as $e): ?><option value="<?php echo $e; ?>" <?php echo $filtros['estado']===$e?'selected':''; ?>><?php echo acEscape(acrLabelEstado($e)); ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row align-items-end">
            <div class="form-group col-12 col-md-4"><label>Tipo de gestión</label><select class="custom-select" name="tipo_gestion"><option value="">Todos</option><option value="NOTIFICABLE" <?php echo $filtros['tipo_gestion']==='NOTIFICABLE'?'selected':''; ?>>Notificable</option><option value="INFORMATIVA" <?php echo $filtros['tipo_gestion']==='INFORMATIVA'?'selected':''; ?>>Informativa · sin correo</option></select></div>
            <div class="form-group col-12 col-md-4"><label>Origen</label><select class="custom-select" name="origen"><option value="">Todos</option><option value="CARGA_EXCEL" <?php echo $filtros['origen']==='CARGA_EXCEL'?'selected':''; ?>>Carga masiva</option><option value="MANUAL" <?php echo $filtros['origen']==='MANUAL'?'selected':''; ?>>Registro manual</option></select></div>
            <div class="form-group col-12 col-md-4"><button class="btn btn-dark btn-block" type="submit"><span class="fas fa-filter"></span> Aplicar filtros</button></div>
        </div>
    </form>

    <div class="acr-kpis">
        <div class="acr-kpi"><div class="acr-kpi__label">Total de alertas</div><div class="acr-kpi__value"><?php echo $kpi['total']; ?></div><div class="acr-kpi__meta">Casos dentro del periodo seleccionado</div></div>
        <div class="acr-kpi"><div class="acr-kpi__label">Pendientes de revisión</div><div class="acr-kpi__value"><?php echo $kpi['pendientes']; ?></div><div class="acr-kpi__meta">Backlog inicial por gestionar</div></div>
        <div class="acr-kpi"><div class="acr-kpi__label">Aprobadas</div><div class="acr-kpi__value"><?php echo $kpi['aprobadas']; ?></div><div class="acr-kpi__meta"><?php echo number_format($porcentajeAprobacion,1); ?>% de los casos decididos</div></div>
        <div class="acr-kpi"><div class="acr-kpi__label">Rechazadas</div><div class="acr-kpi__value"><?php echo $kpi['rechazadas']; ?></div><div class="acr-kpi__meta">Casos descartados por Gestión</div></div>
        <div class="acr-kpi"><div class="acr-kpi__label">En subsanación</div><div class="acr-kpi__value"><?php echo $kpi['subsanacion'] + $kpi['subsanados_revision']; ?></div><div class="acr-kpi__meta">Requieren corrección o nueva revisión</div></div>
        <div class="acr-kpi"><div class="acr-kpi__label">Informativas</div><div class="acr-kpi__value"><?php echo $kpi['informativas']; ?></div><div class="acr-kpi__meta"><?php echo number_format($porcentajeInformativas,1); ?>% del total · no generan correo</div></div>
        <div class="acr-kpi"><div class="acr-kpi__label">Notificables</div><div class="acr-kpi__value"><?php echo $kpi['notificables']; ?></div><div class="acr-kpi__meta">Pueden generar correo al aprobarse</div></div>
        <div class="acr-kpi"><div class="acr-kpi__label">Tiempo promedio de decisión</div><div class="acr-kpi__value"><?php echo number_format($promedioDecision,1); ?> h</div><div class="acr-kpi__meta">Creación hasta aprobación</div></div>
    </div>

    <div class="acr-grid-2">
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-chart-line"></span> Evolución del periodo</h2><span>Creación diaria</span></div><div class="acr-card__body"><div id="acr_chart_evolucion" class="acr-chart"></div></div></section>
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-tags"></span> Principales categorías</h2><span>Top 8</span></div><div class="acr-card__body"><?php $max=max(array_map(fn($x)=>(int)$x['total'],$topCategorias) ?: [1]); foreach($topCategorias as $r): ?><div class="acr-bar-row"><div class="acr-bar-label" title="<?php echo acEscape($r['etiqueta']); ?>"><?php echo acEscape($r['etiqueta']); ?></div><div class="acr-bar-track"><div class="acr-bar-fill" style="width:<?php echo round(((int)$r['total']/$max)*100,1); ?>%"></div></div><div class="acr-bar-value"><?php echo (int)$r['total']; ?></div></div><?php endforeach; if(!$topCategorias): ?><div class="acr-empty">Sin datos para el periodo.</div><?php endif; ?></div></section>
    </div>

    <div class="acr-grid-2">
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-map-marked-alt"></span> Regionales con mayor volumen</h2><span>Top 10</span></div><div class="acr-card__body"><?php $max=max(array_map(fn($x)=>(int)$x['total'],$topRegionales) ?: [1]); foreach($topRegionales as $r): ?><div class="acr-bar-row"><div class="acr-bar-label"><?php echo acEscape($r['etiqueta']); ?></div><div class="acr-bar-track"><div class="acr-bar-fill" style="width:<?php echo round(((int)$r['total']/$max)*100,1); ?>%"></div></div><div class="acr-bar-value"><?php echo (int)$r['total']; ?></div></div><?php endforeach; ?></div></section>
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-map-marker-alt"></span> Centros Zonales con mayor volumen</h2><span>Top 10</span></div><div class="acr-card__body"><?php $max=max(array_map(fn($x)=>(int)$x['total'],$topCentros) ?: [1]); foreach($topCentros as $r): ?><div class="acr-bar-row"><div class="acr-bar-label"><?php echo acEscape($r['etiqueta']); ?></div><div class="acr-bar-track"><div class="acr-bar-fill" style="width:<?php echo round(((int)$r['total']/$max)*100,1); ?>%"></div></div><div class="acr-bar-value"><?php echo (int)$r['total']; ?></div></div><?php endforeach; ?></div></section>
    </div>

    <div class="acr-grid-2">
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-hourglass-half"></span> Tiempos de espera muy largos</h2><span>Alertas informativas</span></div><div class="acr-card__body"><?php $max=max(array_map(fn($x)=>(int)$x['total'],$esperas) ?: [1]); foreach($esperas as $r): ?><div class="acr-bar-row"><div class="acr-bar-label"><?php echo acEscape(acrLabelEspera($r['etiqueta'])); ?></div><div class="acr-bar-track"><div class="acr-bar-fill" style="width:<?php echo round(((int)$r['total']/$max)*100,1); ?>%"></div></div><div class="acr-bar-value"><?php echo (int)$r['total']; ?></div></div><?php endforeach; if(!$esperas): ?><div class="acr-empty">No hay alertas informativas en el periodo.</div><?php endif; ?></div></section>
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-random"></span> Estado y origen</h2><span>Distribución</span></div><div class="acr-card__body"><div class="acr-mini-grid"><div class="acr-mini"><span class="acr-muted">Carga masiva</span><b><?php echo $kpi['masivas']; ?></b></div><div class="acr-mini"><span class="acr-muted">Registro manual</span><b><?php echo $kpi['manuales']; ?></b></div><div class="acr-mini"><span class="acr-muted">Aprobación</span><b><?php echo number_format($porcentajeAprobacion,1); ?>%</b></div></div><hr><?php foreach($porEstado as $r): ?><div class="d-flex justify-content-between py-1"><span><?php echo acEscape(acrLabelEstado($r['etiqueta'])); ?></span><strong><?php echo (int)$r['total']; ?></strong></div><?php endforeach; ?></div></section>
    </div>

    <div class="acr-grid-2">
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-file-excel"></span> Calidad de la Carga Masiva</h2><span>Proceso de entrada</span></div><div class="acr-card__body"><div class="acr-mini-grid"><div class="acr-mini"><span class="acr-muted">Archivos</span><b><?php echo (int)($cargas['archivos'] ?? 0); ?></b></div><div class="acr-mini"><span class="acr-muted">Filas recibidas</span><b><?php echo (int)($cargas['filas'] ?? 0); ?></b></div><div class="acr-mini"><span class="acr-muted">Casos creados</span><b><?php echo (int)($cargas['creados'] ?? 0); ?></b></div><div class="acr-mini"><span class="acr-muted">Duplicados</span><b><?php echo (int)($cargas['duplicados'] ?? 0); ?></b></div><div class="acr-mini"><span class="acr-muted">Filas inválidas</span><b><?php echo (int)($cargas['invalidos'] ?? 0); ?></b></div><div class="acr-mini"><span class="acr-muted">Informativas</span><b><?php echo (int)($cargas['informativos'] ?? 0); ?></b></div></div></div></section>
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-envelope"></span> Notificaciones</h2><span>Trazabilidad de correo</span></div><div class="acr-card__body"><div class="acr-mini-grid"><div class="acr-mini"><span class="acr-muted">Generadas</span><b><?php echo (int)($notif['generadas'] ?? 0); ?></b></div><div class="acr-mini"><span class="acr-muted">Pendientes</span><b><?php echo (int)($notif['pendientes'] ?? 0); ?></b></div><div class="acr-mini"><span class="acr-muted">Con fecha de envío</span><b><?php echo (int)($notif['con_fecha_envio'] ?? 0); ?></b></div></div><div class="mt-3 acr-section-note">Los casos informativos de tiempos de espera no deben generar correo y por eso no incrementan estos indicadores.</div></div></section>
    </div>

    <div class="acr-grid-2">
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-users-cog"></span> Cobertura territorial</h2><span>Maestra de responsables</span></div><div class="acr-card__body"><div class="acr-mini-grid"><div class="acr-mini"><span class="acr-muted">Regionales</span><b><?php echo (int)($cobertura['regionales'] ?? 0); ?></b><small><?php echo (int)($cobertura['regionales_cubiertas'] ?? 0); ?> con responsable</small></div><div class="acr-mini"><span class="acr-muted">Centros Zonales</span><b><?php echo (int)($cobertura['centros'] ?? 0); ?></b><small><?php echo (int)($cobertura['centros_cubiertos'] ?? 0); ?> con coordinador</small></div><div class="acr-mini"><span class="acr-muted">Cobertura CZ</span><b><?php echo number_format(acrPct((int)($cobertura['centros_cubiertos'] ?? 0),(int)($cobertura['centros'] ?? 0)),1); ?>%</b></div></div></div></section>
        <section class="acr-card"><div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-lightbulb"></span> Lectura ejecutiva</h2><span>Decisiones</span></div><div class="acr-card__body"><ul class="mb-0"><li><strong><?php echo $kpi['pendientes']; ?></strong> casos están pendientes de revisión en el periodo.</li><li><strong><?php echo number_format($porcentajeAprobacion,1); ?>%</strong> de los casos con decisión terminaron aprobados.</li><li><strong><?php echo number_format($porcentajeInformativas,1); ?>%</strong> de las alertas fueron informativas y no requieren correo.</li><li>El tiempo promedio entre creación y aprobación es de <strong><?php echo number_format($promedioDecision,1); ?> horas</strong>.</li><?php if($topRegionales): ?><li>La regional con mayor volumen es <strong><?php echo acEscape($topRegionales[0]['etiqueta']); ?></strong> con <?php echo (int)$topRegionales[0]['total']; ?> alerta(s).</li><?php endif; ?><?php if($topCategorias): ?><li>La categoría más frecuente es <strong><?php echo acEscape($topCategorias[0]['etiqueta']); ?></strong>.</li><?php endif; ?></ul></div></section>
    </div>

    <section class="acr-card">
        <div class="acr-card__head"><h2 class="acr-card__title"><span class="fas fa-table"></span> Detalle analítico</h2><span>Últimos 100 resultados filtrados</span></div>
        <div class="acr-table-wrap"><table class="table table-hover mb-0 acr-table"><thead><tr><th>Radicado</th><th>SIM</th><th>Creación</th><th>Regional</th><th>Centro Zonal</th><th>Categoría</th><th>Gestión</th><th>Origen</th><th>Estado</th><th>Tiempo decisión</th><th>Acción</th></tr></thead><tbody><?php if(!$ultimos): ?><tr><td colspan="11" class="text-center p-4">No hay resultados para los filtros seleccionados.</td></tr><?php endif; ?><?php foreach($ultimos as $r): $hrs=acrHrs($r['acc_fecha_creacion'],$r['acc_fecha_aprobacion']); ?><tr><td><strong><?php echo acEscape($r['acc_radicado']); ?></strong></td><td><?php echo acEscape((string)$r['acc_sim']); ?></td><td><?php echo acEscape((string)$r['acc_fecha_creacion']); ?></td><td><?php echo acEscape($r['acc_regional']); ?></td><td><?php echo acEscape((string)$r['acc_centro_zonal']); ?></td><td><?php echo acEscape((string)$r['acc_categoria']); ?></td><td><?php if($r['acc_tipo_gestion']==='INFORMATIVA'): ?><span class="acr-pill acr-pill--blue">Informativa</span><small class="d-block mt-1"><?php echo acEscape(acrLabelEspera((string)$r['acc_tiempo_espera_rango'])); ?></small><?php else: ?><span class="acr-pill acr-pill--green">Notificable</span><?php endif; ?></td><td><?php echo $r['acc_origen']==='CARGA_EXCEL'?'Carga masiva':'Manual'; ?></td><td><span class="acr-pill <?php echo $r['acc_estado']==='APROBADO'?'acr-pill--green':($r['acc_estado']==='RECHAZADO'?'acr-pill--red':'acr-pill--orange'); ?>"><?php echo acEscape(acrLabelEstado($r['acc_estado'])); ?></span></td><td><?php echo $hrs!==null?number_format($hrs,1).' h':'—'; ?></td><td><a class="btn btn-sm btn-outline-primary" href="alerta_correos_ver.php?id=<?php echo (int)$r['acc_id']; ?>"><span class="fas fa-eye"></span> Ver</a></td></tr><?php endforeach; ?></tbody></table></div>
    </section>
</div>
<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
<script src="../anychart/js/anychart-core.min.js"></script>
<script src="../anychart/js/anychart-base.min.js"></script>
<script>
(function(){
    if(typeof anychart==='undefined'){ return; }
    var raw=<?php echo json_encode($evolucion, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    if(!raw.length){ document.getElementById('acr_chart_evolucion').innerHTML='<div class="acr-empty">Sin datos para el periodo.</div>'; return; }
    var data=raw.map(function(x){return [x.fecha, Number(x.total), Number(x.aprobadas), Number(x.informativas)];});
    anychart.onDocumentReady(function(){
        var set=anychart.data.set(data);
        var chart=anychart.line();
        chart.animation(true);
        chart.background().fill('transparent');
        chart.legend(true);
        chart.tooltip().displayMode('union');
        var s1=chart.line(set.mapAs({x:0,value:1})); s1.name('Creadas');
        var s2=chart.line(set.mapAs({x:0,value:2})); s2.name('Aprobadas');
        var s3=chart.line(set.mapAs({x:0,value:3})); s3.name('Informativas');
        chart.container('acr_chart_evolucion'); chart.draw();
    });
})();
</script>
</body>
</html>
