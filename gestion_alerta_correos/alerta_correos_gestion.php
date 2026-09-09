<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';

acExigirPerfil(['Gestor', 'Supervisor', 'Administrador']);

$q = trim((string)($_GET['q'] ?? ''));
$filtrosPendientes = ['estado' => 'PENDIENTE_REVISION', 'q' => $q];
$filtrosSubsanados = ['estado' => 'PENDIENTE_REVISION_SUBSANACION', 'q' => $q];

$pendientesIniciales = acListarCasos($enlace_db, $filtrosPendientes, 250);
$pendientesSubsanados = acListarCasos($enlace_db, $filtrosSubsanados, 250);
$pendientes = array_merge($pendientesIniciales, $pendientesSubsanados);

usort($pendientes, static function (array $a, array $b): int {
    return strcmp((string)$b['acc_fecha_creacion'], (string)$a['acc_fecha_creacion']);
});

$titulo_header = 'Alertas Correos | Gestión y aprobación';
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260908">
</head>
<body>
<?php
include '../menu_principal.php';
include '../menu_header.php';
?>

<div class="contenido ac-module">
    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a>
        <span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a>
        <span class="ac-separator">/</span>
        <span>Gestión y aprobación</span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title">
                <span class="fas fa-user-check" aria-hidden="true"></span>
                Gestión y aprobación
            </h1>
            <p class="ac-page-subtitle">Bandeja de casos que requieren validación del gestor antes de notificar a los responsables regionales y zonales.</p>
        </div>
        <div class="ac-page-header__actions">
            <a href="alerta_correos.php" class="btn btn-light">
                <span class="fas fa-arrow-left"></span> Bandeja general
            </a>
        </div>
    </header>

    <div class="ac-kpis-inline mb-3" aria-label="Resumen de casos pendientes">
        <div class="ac-kpi-inline">
            <span class="ac-kpi-inline__label">Pendientes iniciales</span>
            <span class="ac-kpi-inline__value"><?php echo count($pendientesIniciales); ?></span>
        </div>
        <div class="ac-kpi-inline">
            <span class="ac-kpi-inline__label">Subsanados por revisar</span>
            <span class="ac-kpi-inline__value"><?php echo count($pendientesSubsanados); ?></span>
        </div>
        <div class="ac-kpi-inline">
            <span class="ac-kpi-inline__label">Total por gestionar</span>
            <span class="ac-kpi-inline__value"><?php echo count($pendientes); ?></span>
        </div>
    </div>

    <section class="ac-panel" aria-labelledby="ac-gestion-title">
        <div class="ac-panel__header">
            <h2 id="ac-gestion-title" class="ac-panel__title">
                <span class="fas fa-clipboard-check"></span> Casos pendientes de decisión
            </h2>
            <span class="ac-panel__meta">Ordenados del más reciente al más antiguo</span>
        </div>

        <form method="get" class="ac-toolbar" role="search">
            <div class="form-row align-items-end">
                <div class="col-12 col-md-8 col-lg-6 form-group">
                    <label for="ac_gestion_q">Buscar en pendientes</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><span class="fas fa-search"></span></span></div>
                        <input
                            id="ac_gestion_q"
                            type="search"
                            class="form-control"
                            name="q"
                            maxlength="120"
                            value="<?php echo acEscape($q); ?>"
                            placeholder="Radicado, SIM o descripción"
                            autocomplete="off"
                        >
                    </div>
                </div>
                <div class="col-12 col-md-4 col-lg-3 form-group">
                    <div class="d-flex">
                        <button type="submit" class="btn btn-dark flex-grow-1"><span class="fas fa-search"></span> Buscar</button>
                        <?php if ($q !== ''): ?>
                            <a href="alerta_correos_gestion.php" class="btn btn-light ml-2" title="Limpiar búsqueda" aria-label="Limpiar búsqueda"><span class="fas fa-undo m-0"></span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <div class="ac-table-wrap">
            <table class="table table-hover ac-table">
                <thead>
                    <tr>
                        <th>Radicado</th>
                        <th>SIM</th>
                        <th>Prioridad</th>
                        <th>Regional</th>
                        <th>Centro Zonal</th>
                        <th>Estado</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$pendientes): ?>
                    <tr>
                        <td colspan="7" class="p-0">
                            <div class="ac-empty">
                                <div class="ac-empty__icon"><span class="fas fa-check-circle"></span></div>
                                <p class="ac-empty__title"><?php echo $q !== '' ? 'No hay coincidencias' : 'No hay casos pendientes de aprobación'; ?></p>
                                <p class="ac-empty__text"><?php echo $q !== '' ? 'Pruebe con otro término de búsqueda.' : 'La bandeja se actualizará cuando existan alertas listas para revisión.'; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($pendientes as $caso): ?>
                    <tr>
                        <td>
                            <span class="ac-cell-main"><?php echo acEscape($caso['acc_radicado']); ?></span>
                            <span class="ac-cell-sub"><?php echo acEscape((string)$caso['acc_fecha_creacion']); ?></span>
                        </td>
                        <td><?php echo acEscape($caso['acc_sim']); ?></td>
                        <td><span class="ac-badge ac-badge--<?php echo acEscape(acTipoClase($caso['acc_tipo_alerta'])); ?>"><?php echo acEscape($caso['acc_tipo_alerta']); ?></span></td>
                        <td><?php echo acEscape($caso['acc_regional']); ?></td>
                        <td><?php echo acEscape($caso['acc_centro_zonal']); ?></td>
                        <td><span class="ac-badge ac-badge--<?php echo acEscape(acEstadoClase($caso['acc_estado'])); ?>"><?php echo acEscape(acEstadoLabel($caso['acc_estado'])); ?></span></td>
                        <td class="text-center">
                            <a class="btn btn-primary btn-sm" href="alerta_correos_ver.php?id=<?php echo (int)$caso['acc_id']; ?>">
                                <span class="fas fa-search"></span> Revisar
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php
include '../footer.php';
include '../config/configuracion_js.php';
?>
<script src="assets/alerta_correos.js?v=20260908"></script>
</body>
</html>
