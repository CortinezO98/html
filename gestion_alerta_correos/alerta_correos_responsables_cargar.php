<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';

acExigirPerfil(['Usuario', 'Administrador']);
$titulo_header = 'Alertas Correos | Cargas territoriales';

$kpis = [
    'regionales' => 0,
    'zonales' => 0,
    'coordinadores' => 0,
    'responsables' => 0,
];

$rs = $enlace_db->query(
    "SELECT acp_tipo, COUNT(*) total
     FROM tb_alerta_correo_punto_atencion
     WHERE acp_activo=1
     GROUP BY acp_tipo"
);
while ($row = $rs->fetch_assoc()) {
    if ((string)$row['acp_tipo'] === 'REGIONAL') {
        $kpis['regionales'] = (int)$row['total'];
    } elseif ((string)$row['acp_tipo'] === 'CENTRO_ZONAL') {
        $kpis['zonales'] = (int)$row['total'];
    }
}

$rs = $enlace_db->query(
    "SELECT UPPER(TRIM(COALESCE(acr_tipo_responsable,''))) tipo, COUNT(*) total
     FROM tb_alerta_correo_responsable
     WHERE acr_activo=1
     GROUP BY UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))"
);
while ($row = $rs->fetch_assoc()) {
    if ((string)$row['tipo'] === 'COORDINADOR') {
        $kpis['coordinadores'] = (int)$row['total'];
    } elseif ((string)$row['tipo'] === 'ENLACE_RELACION_CIUDADANO') {
        $kpis['responsables'] = (int)$row['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260917">
    <style>
        .ac-cargas-page{padding-top:3.5rem}
        .ac-cargas-top{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem}
        .ac-cargas-top .ac-breadcrumb{margin:0}
        .ac-cargas-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem}
        .ac-carga-card{background:#fff;border:1px solid #dfe7e2;border-radius:12px;overflow:hidden;box-shadow:0 3px 12px rgba(0,0,0,.05);display:flex;flex-direction:column;min-height:100%}
        .ac-carga-card__head{background:#4caf50;color:#fff;padding:1rem 1.1rem;display:flex;align-items:center;gap:.75rem}
        .ac-carga-card__step{width:34px;height:34px;border-radius:50%;background:#fff;color:#4caf50;display:inline-flex;align-items:center;justify-content:center;font-weight:800;flex:0 0 auto}
        .ac-carga-card__title{margin:0;font-size:1rem;font-weight:800}
        .ac-carga-card__body{padding:1.1rem;display:flex;flex-direction:column;flex:1}
        .ac-carga-card__body p{color:#607d8b;font-size:.9rem;line-height:1.5}
        .ac-carga-card__actions{margin-top:auto;display:flex;gap:.5rem;flex-wrap:wrap}
        .ac-cargas-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}
        .ac-cargas-kpi{background:#fff;border:1px solid #e1e8e4;border-left:4px solid #4caf50;border-radius:9px;padding:.8rem 1rem}
        .ac-cargas-kpi strong{display:block;font-size:1.35rem;color:#263238}
        .ac-cargas-kpi span{font-size:.78rem;color:#78909c}
        .ac-cargas-rule{margin-top:1rem;padding:1rem 1.1rem;background:#f6faf7;border-left:4px solid #4caf50;border-radius:8px;color:#455a64}
        @media(max-width:991.98px){.ac-cargas-grid{grid-template-columns:1fr}.ac-cargas-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:767.98px){.ac-cargas-page{padding-top:4.75rem}.ac-cargas-top{flex-direction:column;align-items:stretch}.ac-cargas-kpis{grid-template-columns:1fr}}
    </style>
</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>

<div class="contenido ac-module ac-module--footer-safe ac-cargas-page">
    <div class="ac-cargas-top">
        <nav class="ac-breadcrumb" aria-label="breadcrumb">
            <a href="../contenido.php">Inicio</a>
            <span class="ac-separator">/</span>
            <a href="alerta_correos.php">Alertas Correos</a>
            <span class="ac-separator">/</span>
            <a href="alerta_correos_responsables.php">Responsables territoriales</a>
            <span class="ac-separator">/</span>
            <span>Cargas territoriales</span>
        </nav>
        <a href="alerta_correos_responsables.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver al directorio</a>
    </div>

    <header class="ac-page-header">
        <h1 class="ac-page-title"><span class="fas fa-sitemap"></span> Cargas territoriales</h1>
        <p class="ac-page-subtitle">Administre por separado el catálogo territorial, los coordinadores y los responsables/enlaces. El territorio se crea una sola vez; las personas se asocian mediante el código del centro.</p>
    </header>

    <div class="ac-cargas-kpis">
        <div class="ac-cargas-kpi"><strong><?php echo $kpis['regionales']; ?></strong><span>Regionales activas</span></div>
        <div class="ac-cargas-kpi"><strong><?php echo $kpis['zonales']; ?></strong><span>Centros zonales activos</span></div>
        <div class="ac-cargas-kpi"><strong><?php echo $kpis['coordinadores']; ?></strong><span>Coordinadores activos</span></div>
        <div class="ac-cargas-kpi"><strong><?php echo $kpis['responsables']; ?></strong><span>Responsables / enlaces activos</span></div>
    </div>

    <div class="ac-cargas-grid">
        <section class="ac-carga-card">
            <div class="ac-carga-card__head">
                <span class="ac-carga-card__step">1</span>
                <h2 class="ac-carga-card__title">Regionales y Centros Zonales</h2>
            </div>
            <div class="ac-carga-card__body">
                <p>Define la estructura territorial oficial. Aquí se crean o actualizan Regionales y Centros Zonales con su código único.</p>
                <div class="ac-carga-card__actions">
                    <a href="alerta_correos_territorios_cargar.php" class="btn ac-btn-green-outline"><span class="fas fa-upload"></span> Ir a cargar</a>
                    <a href="alerta_correos_plantilla_sectorizada.php?tipo=territorios" class="btn btn-outline-secondary"><span class="fas fa-download"></span> Plantilla</a>
                </div>
            </div>
        </section>

        <section class="ac-carga-card">
            <div class="ac-carga-card__head">
                <span class="ac-carga-card__step">2</span>
                <h2 class="ac-carga-card__title">Coordinadores</h2>
            </div>
            <div class="ac-carga-card__body">
                <p>Asocia el coordinador a un territorio existente. Solo se solicita el código del centro y los datos de la persona.</p>
                <div class="ac-carga-card__actions">
                    <a href="alerta_correos_coordinadores_cargar.php" class="btn ac-btn-green-outline"><span class="fas fa-user-tie"></span> Ir a cargar</a>
                    <a href="alerta_correos_plantilla_sectorizada.php?tipo=coordinadores" class="btn btn-outline-secondary"><span class="fas fa-download"></span> Plantilla</a>
                </div>
            </div>
        </section>

        <section class="ac-carga-card">
            <div class="ac-carga-card__head">
                <span class="ac-carga-card__step">3</span>
                <h2 class="ac-carga-card__title">Responsables / Enlaces</h2>
            </div>
            <div class="ac-carga-card__body">
                <p>Asocia el enlace o responsable operativo al territorio existente. No vuelve a crear la Regional ni el Centro Zonal.</p>
                <div class="ac-carga-card__actions">
                    <a href="alerta_correos_enlaces_cargar.php" class="btn ac-btn-green-outline"><span class="fas fa-user-check"></span> Ir a cargar</a>
                    <a href="alerta_correos_plantilla_sectorizada.php?tipo=responsables" class="btn btn-outline-secondary"><span class="fas fa-download"></span> Plantilla</a>
                </div>
            </div>
        </section>
    </div>

    <div class="ac-cargas-rule">
        <strong><span class="fas fa-shield-alt mr-1"></span> Regla principal:</strong>
        Coordinadores y responsables no crean territorios. Si un <strong>CODIGO_CENTRO</strong> no existe en el maestro territorial, la fila se rechaza y debe corregirse antes de confirmar la carga.
    </div>
</div>

<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
</body>
</html>
