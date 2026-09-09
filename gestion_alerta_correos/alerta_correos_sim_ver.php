<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_territorio.php';
require_once __DIR__ . '/lib/alerta_correos_importacion_versionada.php';
require_once __DIR__ . '/lib/alerta_correos_sim_fuente.php';
require_once __DIR__ . '/lib/alerta_correos_sim_admin.php';

acExigirPerfil(['Administrador']);
$id = max(0, (int)($_GET['id'] ?? 0));
$row = acSimAdminObtenerPorId($enlace_db, $id);
if (!$row) { http_response_code(404); die('Registro SIM no encontrado.'); }
$historial = acSimAdminHistorial($enlace_db, (string)$row['ars_sim'], 50);
$titulo_header = 'Alertas Correos | SIM ' . (string)$row['ars_sim'];
$ok = trim((string)($_GET['ok'] ?? ''));
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260909">
</head>
<body>
<?php include '../menu_principal.php'; include '../menu_header.php'; ?>
<div class="contenido ac-module ac-module--footer-safe">
    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a><span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a><span class="ac-separator">/</span>
        <a href="alerta_correos_sim.php">Fuente SIM</a><span class="ac-separator">/</span>
        <span><?php echo acEscape((string)$row['ars_sim']); ?></span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title"><span class="fas fa-hashtag"></span> SIM <?php echo acEscape((string)$row['ars_sim']); ?></h1>
            <p class="ac-page-subtitle">Detalle actual y trazabilidad de cambios de la Fuente SIM.</p>
        </div>
        <div class="ac-page-header__actions">
            <a href="alerta_correos_sim_form.php?id=<?php echo (int)$row['ars_id']; ?>" class="btn ac-btn-green-outline"><span class="fas fa-edit"></span> Editar</a>
            <a href="alerta_correos_sim.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver</a>
        </div>
    </header>

    <?php if ($ok==='creado'): ?><div class="alert alert-success"><strong>SIM registrado correctamente.</strong> Ya puede buscarse desde Nueva alerta.</div><?php endif; ?>

    <section class="ac-panel mb-3">
        <div class="ac-panel__header">
            <h2 class="ac-panel__title"><span class="fas fa-info-circle"></span> Información actual</h2>
            <span class="ac-panel__meta"><?php echo (int)$row['ars_activo']===1?'Activo':'Inactivo'; ?></span>
        </div>
        <div class="ac-panel__body">
            <div class="row">
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">SIM</small><strong><?php echo acEscape((string)$row['ars_sim']); ?></strong></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Regional</small><strong><?php echo acEscape((string)$row['ars_regional']); ?></strong></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Centro Zonal / Punto</small><strong><?php echo acEscape((string)$row['ars_punto_atencion']); ?></strong></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Estado fuente</small><?php echo acEscape((string)$row['ars_estado']); ?></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Categoría</small><?php echo acEscape((string)$row['ars_categoria']); ?></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Subcategoría</small><?php echo acEscape((string)$row['ars_subcategoria']); ?></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Fecha alerta</small><?php echo acEscape((string)$row['ars_fecha_alerta']); ?></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Fecha atención</small><?php echo acEscape((string)$row['ars_fecha_atencion']); ?></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Afecta línea técnica</small><?php echo acEscape((string)$row['ars_afecta_linea_tecnica']); ?></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Mapeo territorial</small><span class="badge <?php echo (string)$row['ars_mapeo_estado']==='MAPEADO'?'badge-success':'badge-warning'; ?>"><?php echo acEscape((string)$row['ars_mapeo_estado']); ?></span></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Fuente</small><?php echo acEscape((string)$row['ars_fuente']); ?></div>
                <div class="col-12 col-md-4 mb-3"><small class="text-muted d-block">Última actualización</small><?php echo acEscape((string)$row['ars_fecha_actualizacion']); ?></div>
                <div class="col-12 mb-3"><small class="text-muted d-block">Descripción</small><div class="border rounded p-2 bg-light"><?php echo nl2br(acEscape((string)$row['ars_descripcion'])); ?></div></div>
                <div class="col-12"><small class="text-muted d-block">Justificación</small><div class="border rounded p-2 bg-light"><?php echo nl2br(acEscape((string)$row['ars_justificacion'])); ?></div></div>
            </div>
        </div>
    </section>

    <section class="ac-panel">
        <div class="ac-panel__header"><h2 class="ac-panel__title"><span class="fas fa-history"></span> Historial</h2><span class="ac-panel__meta"><?php echo count($historial); ?> cambio(s)</span></div>
        <div class="ac-panel__body p-0">
            <div class="ac-table-wrap">
                <table class="table table-hover ac-table mb-0">
                    <thead><tr><th>Fecha</th><th>Acción</th><th>Usuario</th><th>Regional anterior</th><th>Punto anterior</th><th>Fuente anterior</th></tr></thead>
                    <tbody>
                    <?php if (!$historial): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">Aún no existen cambios históricos para este SIM.</td></tr>
                    <?php else: foreach ($historial as $h): $snap=json_decode((string)$h['arsh_snapshot_json'], true); if(!is_array($snap))$snap=[]; ?>
                        <tr>
                            <td><?php echo acEscape((string)$h['arsh_fecha']); ?></td>
                            <td><strong><?php echo acEscape((string)$h['arsh_accion']); ?></strong></td>
                            <td><?php echo acEscape((string)$h['arsh_usuario']); ?></td>
                            <td><?php echo acEscape((string)($snap['ars_regional'] ?? '')); ?></td>
                            <td><?php echo acEscape((string)($snap['ars_punto_atencion'] ?? '')); ?></td>
                            <td><?php echo acEscape((string)($snap['ars_fuente'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
</body>
</html>
