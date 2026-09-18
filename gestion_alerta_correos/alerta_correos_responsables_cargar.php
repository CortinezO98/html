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
        .ac-instructions{display:none;margin-top:.9rem;padding:.95rem 1rem;background:#f8fbf9;border:1px solid #dce9df;border-radius:9px;color:#455a64;font-size:.86rem;line-height:1.5}
        .ac-instructions.is-open{display:block}
        .ac-instructions h3{font-size:.92rem;font-weight:800;color:#2f5f3a;margin:0 0 .6rem}
        .ac-instructions ol,.ac-instructions ul{padding-left:1.15rem;margin-bottom:.6rem}
        .ac-instructions li{margin-bottom:.32rem}
        .ac-instructions .ac-instructions__note{margin-top:.65rem;padding:.6rem .7rem;background:#fff;border-left:3px solid #4caf50;border-radius:5px}
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
                    <button type="button" class="btn btn-outline-info" data-ac-instructions-button="inst-territorios" aria-expanded="false">
                        <span class="fas fa-info-circle"></span> Instrucciones
                    </button>
                </div>
                <div id="inst-territorios" class="ac-instructions" data-ac-instructions>
                    <h3><span class="fas fa-map-marked-alt mr-1"></span> ¿Cómo cargar Regionales y Centros Zonales?</h3>
                    <ol>
                        <li>Descargue la plantilla y conserve los encabezados sin modificarlos.</li>
                        <li>Use <strong>REGIONAL</strong> para una Regional y <strong>ZONAL</strong> para un Centro Zonal.</li>
                        <li>Registre <strong>REGIONAL</strong>, <strong>CENTRO_ZONAL</strong> cuando aplique, <strong>CODIGO_CENTRO</strong> y <strong>ESTADO</strong>.</li>
                        <li>El <strong>CODIGO_CENTRO</strong> debe identificar un único territorio y no debe reutilizarse para otro centro.</li>
                        <li>Cargue el archivo, revise la previsualización y confirme únicamente cuando no existan errores.</li>
                    </ol>
                    <div class="ac-instructions__note"><strong>Importante:</strong> esta es la carga maestra. Coordinadores y responsables solo podrán asociarse a códigos registrados aquí.</div>
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
                    <button type="button" class="btn btn-outline-info" data-ac-instructions-button="inst-coordinadores" aria-expanded="false">
                        <span class="fas fa-info-circle"></span> Instrucciones
                    </button>
                </div>
                <div id="inst-coordinadores" class="ac-instructions" data-ac-instructions>
                    <h3><span class="fas fa-user-tie mr-1"></span> ¿Cómo cargar Coordinadores?</h3>
                    <ol>
                        <li>Verifique primero que el territorio exista en la carga maestra.</li>
                        <li>Use la plantilla de Coordinadores y diligencie <strong>CODIGO_CENTRO, DOCUMENTO, NOMBRE, CORREO, EXTENSION_IP y ESTADO</strong>.</li>
                        <li>No escriba Regional ni Centro Zonal: el sistema los obtiene automáticamente mediante <strong>CODIGO_CENTRO</strong>.</li>
                        <li>Si el territorio ya tiene coordinador y cambia la persona, se cierra la vigencia anterior y se registra la nueva versión.</li>
                        <li>Revise la previsualización antes de confirmar la carga.</li>
                    </ol>
                    <div class="ac-instructions__note"><strong>Control:</strong> un código inexistente en el maestro territorial será rechazado y no creará un territorio nuevo.</div>
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
                    <button type="button" class="btn btn-outline-info" data-ac-instructions-button="inst-responsables" aria-expanded="false">
                        <span class="fas fa-info-circle"></span> Instrucciones
                    </button>
                </div>
                <div id="inst-responsables" class="ac-instructions" data-ac-instructions>
                    <h3><span class="fas fa-user-check mr-1"></span> ¿Cómo cargar Responsables / Enlaces?</h3>
                    <ol>
                        <li>Verifique que la Regional o Centro Zonal ya exista en el maestro territorial.</li>
                        <li>Use la plantilla y diligencie <strong>CODIGO_CENTRO, DOCUMENTO, NOMBRE, CORREO, EXTENSION_IP y ESTADO</strong>.</li>
                        <li>Regional y Centro Zonal no se solicitan porque se resuelven por <strong>CODIGO_CENTRO</strong>.</li>
                        <li>Si cambia el responsable, la asignación anterior queda cerrada y se conserva el histórico.</li>
                        <li>Cargue, analice las diferencias y confirme cuando el archivo esté correcto.</li>
                    </ol>
                    <div class="ac-instructions__note"><strong>Control:</strong> esta carga administra únicamente responsables/enlaces; no modifica coordinadores ni crea territorios.</div>
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
<script>
document.querySelectorAll('[data-ac-instructions-button]').forEach(function(button) {
    button.addEventListener('click', function() {
        var id = button.getAttribute('data-ac-instructions-button');
        var panel = document.getElementById(id);
        if (!panel) return;
        var open = panel.classList.toggle('is-open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.innerHTML = open
            ? '<span class="fas fa-times-circle"></span> Cerrar instrucciones'
            : '<span class="fas fa-info-circle"></span> Instrucciones';
    });
});
</script>
</body>
</html>
