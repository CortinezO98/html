<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';

acExigirPerfil(['Administrador']);

$q = trim((string)($_GET['q'] ?? ''));
$rows = acListarResponsables($enlace_db, $q);
$flash = acFlashTomar();
$titulo_header = 'Alertas Correos | Responsables';

// Paginación de la consulta ya filtrada. Se limita a opciones conocidas para evitar valores arbitrarios.
$perPageOptions = [25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 25;
}

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, (int)($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pagedRows = array_slice($rows, $offset, $perPage);
$from = $totalRows === 0 ? 0 : $offset + 1;
$to = min($offset + $perPage, $totalRows);

/** Construye enlaces de paginación conservando búsqueda y tamaño de página. */
function acResponsablesUrl(int $targetPage, string $q, int $perPage): string
{
    $params = ['page' => max(1, $targetPage), 'per_page' => $perPage];
    if ($q !== '') {
        $params['q'] = $q;
    }
    return 'alerta_correos_responsables.php?' . http_build_query($params);
}

$activos = 0;
$regionales = 0;
$zonales = 0;
foreach ($rows as $row) {
    if ((int)$row['acr_activo'] === 1) {
        $activos++;
    }
    if (strtoupper((string)$row['acr_nivel']) === 'REGIONAL') {
        $regionales++;
    }
    if (strtoupper((string)$row['acr_nivel']) === 'ZONAL') {
        $zonales++;
    }
}
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260908">

    <style>
        /* Ajustes locales: paginación y separación del footer sin alterar estilos globales. */
        .ac-module { padding-bottom: 110px; }
        .ac-resp-pagination {
            display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
            padding:16px 20px 20px; border-top:1px solid #e5e7eb; background:#fff;
        }
        .ac-resp-pagination__summary { color:#5f6b7a; font-size:.95rem; }
        .ac-resp-pagination__size { display:flex; align-items:center; gap:8px; margin:0; }
        .ac-resp-pagination__size label { margin:0; color:#5f6b7a; font-weight:600; white-space:nowrap; }
        .ac-resp-pagination__size select { width:72px; }
        .ac-resp-pagination__nav { display:flex; align-items:center; gap:8px; }
        .ac-page-btn {
            display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:38px; padding:7px 12px;
            border:1px solid #49b64f; border-radius:8px; color:#278c31; background:#fff; font-weight:700; text-decoration:none !important;
            transition:background-color .18s ease,color .18s ease,transform .08s ease,box-shadow .18s ease;
        }
        .ac-page-btn:hover { background:#49b64f; color:#fff; box-shadow:0 3px 10px rgba(39,140,49,.18); }
        .ac-page-btn:active { transform:translateY(1px); }
        .ac-page-btn.is-disabled { opacity:.45; pointer-events:none; }
        .ac-page-current { min-width:120px; text-align:center; color:#344054; }
        @media (max-width:767.98px) {
            .ac-module { padding-bottom:130px; }
            .ac-resp-pagination { align-items:stretch; padding:14px; }
            .ac-resp-pagination__summary { width:100%; text-align:center; }
            .ac-resp-pagination__size { justify-content:center; flex:1 1 100%; }
            .ac-resp-pagination__nav { justify-content:center; width:100%; }
            .ac-page-btn { flex:1 1 0; }
        }
    </style>
</head>
<body>
<?php
include '../menu_principal.php';
include '../menu_header.php';
?>

<div class="contenido ac-module">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo acEscape($flash['tipo']); ?> alert-dismissible fade show" role="alert">
            <span class="fas fa-info-circle mr-1"></span>
            <?php echo acEscape($flash['mensaje']); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php endif; ?>

    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a>
        <span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a>
        <span class="ac-separator">/</span>
        <span>Responsables</span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title">
                <span class="fas fa-address-book" aria-hidden="true"></span>
                Responsables regionales y zonales
            </h1>
            <p class="ac-page-subtitle">Directorio utilizado para resolver automáticamente los destinatarios de las notificaciones aprobadas.</p>
        </div>
        <div class="ac-page-header__actions">
            <a class="btn ac-btn-blue-outline" href="alerta_correos_responsables_cargar.php">
                <span class="fas fa-file-upload"></span> Carga masiva
            </a>
        </div>
    </header>

    <div class="ac-kpis-inline mb-3">
        <div class="ac-kpi-inline">
            <span class="ac-kpi-inline__label">Registros mostrados</span>
            <span class="ac-kpi-inline__value"><?php echo $totalRows; ?></span>
        </div>
        <div class="ac-kpi-inline">
            <span class="ac-kpi-inline__label">Activos</span>
            <span class="ac-kpi-inline__value"><?php echo $activos; ?></span>
        </div>
        <div class="ac-kpi-inline">
            <span class="ac-kpi-inline__label">Nivel regional</span>
            <span class="ac-kpi-inline__value"><?php echo $regionales; ?></span>
        </div>
        <div class="ac-kpi-inline">
            <span class="ac-kpi-inline__label">Nivel zonal</span>
            <span class="ac-kpi-inline__value"><?php echo $zonales; ?></span>
        </div>
    </div>

    <section class="ac-panel" aria-labelledby="ac-responsables-title">
        <div class="ac-panel__header">
            <h2 id="ac-responsables-title" class="ac-panel__title">
                <span class="fas fa-users"></span> Directorio de responsables
            </h2>
            <span class="ac-panel__meta"><?php echo $totalRows === 0 ? 'Sin resultados' : 'Mostrando ' . $from . '–' . $to . ' de ' . $totalRows; ?></span>
        </div>

        <form method="get" class="ac-toolbar" role="search">
            <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
            <div class="form-row align-items-end">
                <div class="col-12 col-md-8 col-lg-6 form-group">
                    <label for="ac_responsables_q">Buscar responsable</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><span class="fas fa-search"></span></span></div>
                        <input
                            id="ac_responsables_q"
                            type="search"
                            class="form-control"
                            name="q"
                            value="<?php echo acEscape($q); ?>"
                            maxlength="120"
                            placeholder="Nombre, correo, regional o centro zonal"
                            autocomplete="off"
                        >
                    </div>
                </div>
                <div class="col-12 col-md-4 col-lg-3 form-group">
                    <div class="d-flex">
                        <button type="submit" class="btn btn-dark flex-grow-1"><span class="fas fa-search"></span> Buscar</button>
                        <?php if ($q !== ''): ?>
                            <a href="alerta_correos_responsables.php" class="btn btn-light ml-2" title="Limpiar búsqueda" aria-label="Limpiar búsqueda"><span class="fas fa-undo m-0"></span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <div class="ac-table-wrap">
            <table class="table table-hover ac-table">
                <thead>
                    <tr>
                        <th>Nivel</th>
                        <th>Regional</th>
                        <th>Centro Zonal</th>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="p-0">
                            <div class="ac-empty">
                                <div class="ac-empty__icon"><span class="fas fa-address-book"></span></div>
                                <p class="ac-empty__title"><?php echo $q !== '' ? 'No encontramos responsables' : 'El directorio aún está vacío'; ?></p>
                                <p class="ac-empty__text"><?php echo $q !== '' ? 'Cambie el término de búsqueda o limpie el filtro.' : 'Use la carga masiva para importar Coordinadores CZ y Enlaces SIM.'; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($pagedRows as $row): ?>
                    <tr>
                        <td><span class="ac-badge ac-badge--info"><?php echo acEscape($row['acr_nivel']); ?></span></td>
                        <td><?php echo acEscape($row['acr_regional']); ?></td>
                        <td><?php echo acEscape($row['acr_centro_zonal']); ?></td>
                        <td>
                            <span class="ac-cell-main"><?php echo acEscape($row['acr_nombre']); ?></span>
                            <?php if (!empty($row['acr_documento'])): ?><span class="ac-cell-sub">ID: <?php echo acEscape($row['acr_documento']); ?></span><?php endif; ?>
                        </td>
                        <td><span class="ac-person__mail"><span class="fas fa-envelope"></span><?php echo acEscape($row['acr_correo']); ?></span></td>
                        <td><?php echo acEscape($row['acr_tipo_responsable']); ?></td>
                        <td>
                            <?php if ((int)$row['acr_activo'] === 1): ?>
                                <span class="ac-badge ac-badge--success"><span class="fas fa-check-circle mr-1"></span>Activo</span>
                            <?php else: ?>
                                <span class="ac-badge ac-badge--secondary"><span class="fas fa-ban mr-1"></span>Inactivo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalRows > 0): ?>
            <div class="ac-resp-pagination" aria-label="Controles de paginación del directorio">
                <div class="ac-resp-pagination__summary">
                    <strong><?php echo $from; ?>–<?php echo $to; ?></strong> de <strong><?php echo $totalRows; ?></strong> responsables
                </div>

                <form method="get" class="ac-resp-pagination__size">
                    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo acEscape($q); ?>"><?php endif; ?>
                    <input type="hidden" name="page" value="1">
                    <label for="ac_per_page">Filas por página</label>
                    <select id="ac_per_page" name="per_page" class="form-control form-control-sm" onchange="this.form.submit()">
                        <?php foreach ($perPageOptions as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($totalPages > 1): ?>
                    <nav class="ac-resp-pagination__nav" aria-label="Paginación de responsables">
                        <a class="ac-page-btn <?php echo $page <= 1 ? 'is-disabled' : ''; ?>"
                           href="<?php echo $page <= 1 ? '#' : acEscape(acResponsablesUrl($page - 1, $q, $perPage)); ?>"
                           aria-label="Página anterior" <?php echo $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
                            <span class="fas fa-chevron-left"></span><span class="d-none d-sm-inline">Anterior</span>
                        </a>

                        <span class="ac-page-current" aria-current="page">Página <strong><?php echo $page; ?></strong> de <?php echo $totalPages; ?></span>

                        <a class="ac-page-btn <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>"
                           href="<?php echo $page >= $totalPages ? '#' : acEscape(acResponsablesUrl($page + 1, $q, $perPage)); ?>"
                           aria-label="Página siguiente" <?php echo $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
                            <span class="d-none d-sm-inline">Siguiente</span><span class="fas fa-chevron-right"></span>
                        </a>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php
include '../footer.php';
include '../config/configuracion_js.php';
?>
<script src="assets/alerta_correos.js?v=20260908"></script>
</body>
</html>

