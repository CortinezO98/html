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
$titulo_header = 'Alertas Correos | Fuente SIM';
$error = null;
$ok = trim((string)($_GET['ok'] ?? ''));

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        acValidarCsrfPost();
        $accion = (string)($_POST['accion'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Registro SIM inválido.');
        $usuario = acUsuarioActual();

        if ($accion === 'inactivar') {
            acSimAdminCambiarActivo($enlace_db, $id, false, $usuario);
            header('Location: alerta_correos_sim.php?ok=inactivado'); exit;
        }
        if ($accion === 'reactivar') {
            acSimAdminCambiarActivo($enlace_db, $id, true, $usuario);
            header('Location: alerta_correos_sim.php?ok=reactivado'); exit;
        }
        throw new RuntimeException('Acción no válida.');
    }
} catch (Throwable $e) {
    error_log('Alertas Correos / administrar Fuente SIM: ' . $e->getMessage());
    $error = $e->getMessage();
}

$q = trim((string)($_GET['q'] ?? ''));
$activo = (string)($_GET['activo'] ?? '1');
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$datos = acSimAdminListar($enlace_db, $q, $activo, $pagina, 25);
$resumen = acSimAdminResumen($enlace_db);

function acSimAdminUrlPagina(int $pagina, string $q, string $activo): string {
    return 'alerta_correos_sim.php?' . http_build_query(['q'=>$q,'activo'=>$activo,'pagina'=>$pagina]);
}
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
        <span>Fuente SIM</span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title"><span class="fas fa-database"></span> Administración de Fuente SIM</h1>
            <p class="ac-page-subtitle">Consulte, registre, edite o cargue masivamente los radicados SIM utilizados por Nueva alerta.</p>
        </div>
        <div class="ac-page-header__actions">
            <a href="alerta_correos_sim_form.php" class="btn ac-btn-green-outline"><span class="fas fa-plus"></span> Nuevo SIM</a>
            <a href="alerta_correos_sim_cargar.php" class="btn ac-btn-blue-outline"><span class="fas fa-file-excel"></span> Carga masiva</a>
            <a href="plantillas/Plantilla_Ejemplo_Fuente_SIM.xlsx" class="btn ac-btn-blue-outline" download><span class="fas fa-download"></span> Plantilla</a>
        </div>
    </header>

    <?php if ($error): ?><div class="alert alert-danger"><span class="fas fa-exclamation-circle mr-1"></span><?php echo acEscape($error); ?></div><?php endif; ?>
    <?php if ($ok === 'creado'): ?><div class="alert alert-success"><strong>SIM registrado correctamente.</strong> Ya está disponible para búsqueda en Nueva alerta.</div><?php endif; ?>
    <?php if ($ok === 'actualizado'): ?><div class="alert alert-success"><strong>SIM actualizado correctamente.</strong></div><?php endif; ?>
    <?php if ($ok === 'inactivado'): ?><div class="alert alert-success"><strong>SIM inactivado.</strong> Ya no aparecerá en Nueva alerta, pero conserva su historial.</div><?php endif; ?>
    <?php if ($ok === 'reactivado'): ?><div class="alert alert-success"><strong>SIM reactivado.</strong> Vuelve a estar disponible en Nueva alerta.</div><?php endif; ?>

    <section class="ac-panel mb-3">
        <div class="ac-panel__body">
            <div class="ac-kpis-inline">
                <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Activos</span><span class="ac-kpi-inline__value"><?php echo $resumen['activos']; ?></span></div>
                <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Mapeados a CZ</span><span class="ac-kpi-inline__value"><?php echo $resumen['mapeados']; ?></span></div>
                <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Solo Regional</span><span class="ac-kpi-inline__value"><?php echo $resumen['solo_regional']; ?></span></div>
                <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Sin mapear</span><span class="ac-kpi-inline__value"><?php echo $resumen['no_mapeados']; ?></span></div>
                <div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Inactivos</span><span class="ac-kpi-inline__value"><?php echo $resumen['inactivos']; ?></span></div>
            </div>
        </div>
    </section>

    <section class="ac-panel mb-3">
        <div class="ac-panel__header">
            <h2 class="ac-panel__title"><span class="fas fa-search"></span> Buscar radicados</h2>
            <span class="ac-panel__meta"><?php echo (int)$datos['total']; ?> resultado(s)</span>
        </div>
        <div class="ac-panel__body">
            <form method="get" class="row align-items-end">
                <div class="form-group col-12 col-lg-8">
                    <label for="q">SIM, Regional, Centro Zonal, categoría o texto de descripción</label>
                    <input id="q" name="q" type="search" class="form-control" value="<?php echo acEscape($q); ?>" placeholder="Ej.: 140164556, BOSA, BOGOTA, Mal procedimiento">
                </div>
                <div class="form-group col-12 col-md-5 col-lg-2">
                    <label for="activo">Estado</label>
                    <select id="activo" name="activo" class="form-control">
                        <option value="1" <?php echo $activo==='1'?'selected':''; ?>>Activos</option>
                        <option value="0" <?php echo $activo==='0'?'selected':''; ?>>Inactivos</option>
                        <option value="todos" <?php echo $activo==='todos'?'selected':''; ?>>Todos</option>
                    </select>
                </div>
                <div class="form-group col-12 col-md-7 col-lg-2 d-flex">
                    <button class="btn ac-btn-green-outline flex-fill" type="submit"><span class="fas fa-search"></span> Buscar</button>
                    <a href="alerta_correos_sim.php" class="btn ac-btn-red-outline ml-2" title="Limpiar filtros"><span class="fas fa-eraser"></span></a>
                </div>
            </form>
        </div>
    </section>

    <section class="ac-panel">
        <div class="ac-panel__header">
            <h2 class="ac-panel__title"><span class="fas fa-list"></span> Radicados registrados</h2>
            <span class="ac-panel__meta">Fuente propia de Alertas Correos</span>
        </div>
        <div class="ac-panel__body p-0">
            <div class="ac-table-wrap">
                <table class="table table-hover ac-table mb-0">
                    <thead>
                    <tr>
                        <th>SIM</th><th>Regional</th><th>Centro Zonal</th><th>Categoría</th><th>Mapeo</th><th>Fuente</th><th>Actualización</th><th>Estado</th><th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$datos['rows']): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No hay radicados SIM para los filtros seleccionados.</td></tr>
                    <?php else: foreach ($datos['rows'] as $row): ?>
                        <tr>
                            <td><strong><?php echo acEscape((string)$row['ars_sim']); ?></strong></td>
                            <td><?php echo acEscape((string)$row['ars_regional']); ?></td>
                            <td><?php echo acEscape((string)$row['ars_punto_atencion']); ?></td>
                            <td><?php echo acEscape((string)$row['ars_categoria']); ?></td>
                            <td>
                                <?php $map=(string)$row['ars_mapeo_estado']; ?>
                                <span class="badge <?php echo $map==='MAPEADO'?'badge-success':($map==='REGIONAL_SOLAMENTE'?'badge-warning':'badge-danger'); ?>"><?php echo acEscape($map); ?></span>
                            </td>
                            <td><small><?php echo acEscape((string)$row['ars_fuente']); ?></small></td>
                            <td><small><?php echo acEscape((string)$row['ars_fecha_actualizacion']); ?></small></td>
                            <td><?php echo (int)$row['ars_activo']===1?'<span class="badge badge-success">Activo</span>':'<span class="badge badge-secondary">Inactivo</span>'; ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm ac-btn-blue-outline" href="alerta_correos_sim_ver.php?id=<?php echo (int)$row['ars_id']; ?>" title="Ver"><span class="fas fa-eye"></span></a>
                                <a class="btn btn-sm ac-btn-green-outline" href="alerta_correos_sim_form.php?id=<?php echo (int)$row['ars_id']; ?>" title="Editar"><span class="fas fa-edit"></span></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?php echo (int)$row['ars_activo']===1?'¿Inactivar este SIM? Dejará de aparecer en Nueva alerta.':'¿Reactivar este SIM?'; ?>');">
                                    <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int)$row['ars_id']; ?>">
                                    <input type="hidden" name="accion" value="<?php echo (int)$row['ars_activo']===1?'inactivar':'reactivar'; ?>">
                                    <button class="btn btn-sm <?php echo (int)$row['ars_activo']===1?'ac-btn-red-outline':'ac-btn-green-outline'; ?>" type="submit" title="<?php echo (int)$row['ars_activo']===1?'Inactivar':'Reactivar'; ?>"><span class="fas <?php echo (int)$row['ars_activo']===1?'fa-ban':'fa-undo'; ?>"></span></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ($datos['paginas'] > 1): ?>
    <nav class="mt-3" aria-label="Paginación Fuente SIM">
        <ul class="pagination justify-content-center">
            <?php $prev=max(1,$datos['pagina']-1); $next=min($datos['paginas'],$datos['pagina']+1); ?>
            <li class="page-item <?php echo $datos['pagina']<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo acEscape(acSimAdminUrlPagina($prev,$q,$activo)); ?>">Anterior</a></li>
            <li class="page-item disabled"><span class="page-link">Página <?php echo $datos['pagina']; ?> de <?php echo $datos['paginas']; ?></span></li>
            <li class="page-item <?php echo $datos['pagina']>=$datos['paginas']?'disabled':''; ?>"><a class="page-link" href="<?php echo acEscape(acSimAdminUrlPagina($next,$q,$activo)); ?>">Siguiente</a></li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
</body>
</html>
