<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';
require_once __DIR__ . '/lib/alerta_correos_helpers.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_informativas.php';

acExigirPerfil(['Usuario', 'Supervisor', 'Cliente', 'Administrador']);

$filtros = [
    'estado' => trim((string)($_GET['estado'] ?? '')),
    'regional' => trim((string)($_GET['regional'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];

$contadores = acContadores($enlace_db);
$casos = acListarCasos($enlace_db, $filtros, 150);
$regionales = acRegionalesCasos($enlace_db);
$flash = acFlashTomar();
$titulo_header = 'Alertas Correos | Bandeja';

$totalActivos = array_sum($contadores);
$hayFiltros = $filtros['estado'] !== '' || $filtros['regional'] !== '' || $filtros['q'] !== '';
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260908">
    <style>
        /* Ajustes exclusivos de la bandeja: no alteran el resto del módulo */
        .ac-bandeja-page {
            padding-bottom: 88px;
        }

        .ac-table th.ac-action-col,
        .ac-table td.ac-action-col {
            width: 112px;
            min-width: 112px;
            text-align: center;
            vertical-align: middle;
        }

        .ac-view-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-width: 82px;
            min-height: 38px;
            padding: 7px 13px;
            border: 1.5px solid #1687e8;
            border-radius: 9px;
            background: #fff;
            color: #0876d1;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
            text-decoration: none !important;
            transition: background-color .18s ease, color .18s ease, border-color .18s ease, box-shadow .18s ease;
        }

        .ac-view-btn .fas {
            margin: 0;
            font-size: 15px;
        }

        .ac-view-btn:hover,
        .ac-view-btn:focus {
            background: #0876d1;
            border-color: #0876d1;
            color: #fff;
            box-shadow: 0 3px 10px rgba(8, 118, 209, .18);
        }

        .ac-view-btn:focus {
            outline: 0;
        }

        @media (max-width: 767.98px) {
            .ac-bandeja-page {
                padding-bottom: 105px;
            }

            .ac-table th.ac-action-col,
            .ac-table td.ac-action-col {
                width: 96px;
                min-width: 96px;
            }

            .ac-view-btn {
                min-width: 74px;
                min-height: 36px;
                padding: 6px 10px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
<?php
include '../menu_principal.php';
include '../menu_header.php';
?>

<div class="contenido ac-module ac-bandeja-page">
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
        <span>Alertas Correos</span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title">
                <span class="fas fa-envelope-open-text" aria-hidden="true"></span>
                Alertas Correos
            </h1>
            <p class="ac-page-subtitle">Consulte, filtre y haga seguimiento al ciclo de revisión, subsanación, aprobación y notificación territorial.</p>
        </div>
        <div class="ac-page-header__actions">
            <?php if (acTienePerfil(['Usuario', 'Supervisor', 'Administrador'])): ?>
                <a href="alerta_correos_crear.php" class="btn ac-btn-green-outline">
                    <span class="fas fa-plus" aria-hidden="true"></span>
                    Nueva alerta manual
                </a>
            <?php endif; ?>
            <?php if (acTienePerfil(['Usuario', 'Supervisor', 'Administrador'])): ?>
                <a href="alerta_correos_cargar_excel.php" class="btn ac-btn-blue-outline">
                    <span class="fas fa-file-excel" aria-hidden="true"></span>
                    Crear Alerta Masiva
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="ac-alert-box ac-alert-box--info mb-3">
        <strong>¿Cómo se crean los casos?</strong>
        <span class="d-block mt-1"><strong>Nueva alerta manual</strong> crea un caso individual. <strong>Crear Alerta Masiva</strong> convierte cada fila válida del archivo en un caso independiente pendiente de revisión. La carga masiva no envía correos al crear los casos. Los casos notificables podrán enviarse al aprobarse; los casos de <strong>Tiempos de espera muy largos</strong> son informativos y nunca generan correo.</span>
    </div>

    <div class="row mb-3">
        <div class="col-6 col-xl-3 mb-3 mb-xl-0">
            <a class="ac-stat ac-stat--pending d-block" href="alerta_correos.php?estado=PENDIENTE_REVISION" aria-label="Filtrar pendientes de revisión">
                <span class="ac-stat__icon"><span class="fas fa-inbox"></span></span>
                <span class="ac-stat__label">Pendientes de revisión</span>
                <span class="ac-stat__value"><?php echo (int)$contadores['PENDIENTE_REVISION']; ?></span>
            </a>
        </div>
        <div class="col-6 col-xl-3 mb-3 mb-xl-0">
            <a class="ac-stat ac-stat--warning d-block" href="alerta_correos.php?estado=PENDIENTE_SUBSANACION" aria-label="Filtrar pendientes de subsanación">
                <span class="ac-stat__icon"><span class="fas fa-tools"></span></span>
                <span class="ac-stat__label">En subsanación</span>
                <span class="ac-stat__value"><?php echo (int)$contadores['PENDIENTE_SUBSANACION'] + (int)$contadores['PENDIENTE_REVISION_SUBSANACION']; ?></span>
            </a>
        </div>
        <div class="col-6 col-xl-3 mb-3 mb-sm-0">
            <a class="ac-stat ac-stat--success d-block" href="alerta_correos.php?estado=APROBADO" aria-label="Filtrar aprobadas">
                <span class="ac-stat__icon"><span class="fas fa-check"></span></span>
                <span class="ac-stat__label">Aprobadas</span>
                <span class="ac-stat__value"><?php echo (int)$contadores['APROBADO']; ?></span>
            </a>
        </div>
        <div class="col-6 col-xl-3">
            <a class="ac-stat ac-stat--danger d-block" href="alerta_correos.php?estado=RECHAZADO" aria-label="Filtrar rechazadas">
                <span class="ac-stat__icon"><span class="fas fa-times"></span></span>
                <span class="ac-stat__label">Rechazadas</span>
                <span class="ac-stat__value"><?php echo (int)$contadores['RECHAZADO']; ?></span>
            </a>
        </div>
    </div>

    <section class="ac-panel" aria-labelledby="ac-bandeja-title">
        <div class="ac-panel__header">
            <h2 id="ac-bandeja-title" class="ac-panel__title">
                <span class="fas fa-list-alt" aria-hidden="true"></span>
                Bandeja de alertas
            </h2>
            <span class="ac-panel__meta">
                <?php echo count($casos); ?> resultado(s) mostrado(s) · <?php echo (int)$totalActivos; ?> caso(s) activo(s)
            </span>
        </div>

        <form method="get" class="ac-toolbar" role="search">
            <div class="form-row align-items-end">
                <div class="col-12 col-lg-4 form-group">
                    <label for="ac_q">Buscar</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><span class="fas fa-search"></span></span>
                        </div>
                        <input
                            id="ac_q"
                            class="form-control"
                            name="q"
                            value="<?php echo acEscape($filtros['q']); ?>"
                            maxlength="120"
                            autocomplete="off"
                            placeholder="Radicado, SIM o texto de la descripción"
                        >
                    </div>
                </div>

                <div class="col-12 col-md-6 col-lg-3 form-group">
                    <label for="ac_estado">Estado</label>
                    <select id="ac_estado" class="custom-select" name="estado">
                        <option value="">Todos los estados</option>
                        <?php foreach (['PENDIENTE_REVISION', 'PENDIENTE_SUBSANACION', 'PENDIENTE_REVISION_SUBSANACION', 'APROBADO', 'RECHAZADO'] as $estado): ?>
                            <option value="<?php echo acEscape($estado); ?>" <?php echo $filtros['estado'] === $estado ? 'selected' : ''; ?>>
                                <?php echo acEscape(acEstadoLabel($estado)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-6 col-lg-3 form-group">
                    <label for="ac_regional">Regional</label>
                    <select id="ac_regional" class="custom-select" name="regional">
                        <option value="">Todas las regionales</option>
                        <?php foreach ($regionales as $regional): ?>
                            <option value="<?php echo acEscape($regional); ?>" <?php echo $filtros['regional'] === $regional ? 'selected' : ''; ?>>
                                <?php echo acEscape($regional); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-lg-2 form-group">
                    <div class="d-flex">
                        <button class="btn btn-dark flex-grow-1" type="submit">
                            <span class="fas fa-filter"></span> Filtrar
                        </button>
                        <?php if ($hayFiltros): ?>
                            <a href="alerta_correos.php" class="btn btn-light ml-2" title="Limpiar filtros" aria-label="Limpiar filtros">
                                <span class="fas fa-undo m-0"></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>

        <div class="ac-table-wrap">
            <table class="table table-hover ac-table" aria-describedby="ac-bandeja-title">
                <thead>
                    <tr>
                        <th>Radicado</th>
                        <th>SIM</th>
                        <th>Prioridad</th>
                        <th>Regional</th>
                        <th>Centro Zonal</th>
                        <th>Categoría</th>
                        <th>Gestión</th>
                        <th>Estado</th>
                        <th class="text-center ac-action-col">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$casos): ?>
                    <tr>
                        <td colspan="9" class="p-0">
                            <div class="ac-empty">
                                <div class="ac-empty__icon"><span class="fas fa-inbox"></span></div>
                                <p class="ac-empty__title"><?php echo $hayFiltros ? 'No encontramos resultados' : 'Aún no hay alertas registradas'; ?></p>
                                <p class="ac-empty__text">
                                    <?php echo $hayFiltros ? 'Ajuste o limpie los filtros para ampliar la búsqueda.' : 'Cuando se registre una alerta, aparecerá aquí para su seguimiento.'; ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($casos as $caso): ?>
                    <tr>
                        <td>
                            <span class="ac-cell-main"><?php echo acEscape($caso['acc_radicado']); ?></span>
                            <span class="ac-cell-sub"><?php echo acEscape((string)$caso['acc_fecha_creacion']); ?></span>
                        </td>
                        <td><?php echo acEscape($caso['acc_sim']); ?></td>
                        <td>
                            <span class="ac-badge ac-badge--<?php echo acEscape(acTipoClase($caso['acc_tipo_alerta'])); ?>">
                                <?php echo acEscape($caso['acc_tipo_alerta']); ?>
                            </span>
                        </td>
                        <td><?php echo acEscape($caso['acc_regional']); ?></td>
                        <td><?php echo acEscape($caso['acc_centro_zonal']); ?></td>
                        <td><?php echo acEscape($caso['acc_categoria']); ?></td>
                        <td>
                            <?php if (acAlertaEsInformativa($caso)): ?>
                                <span class="badge badge-info">Informativa · sin correo</span>
                                <span class="ac-cell-sub"><?php echo acEscape(acAlertaTiempoRangoLabel((string)($caso['acc_tiempo_espera_rango'] ?? ''))); ?></span>
                            <?php else: ?>
                                <span class="badge badge-primary">Notificable</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="ac-badge ac-badge--<?php echo acEscape(acEstadoClase($caso['acc_estado'])); ?>">
                                <?php echo acEscape(acEstadoLabel($caso['acc_estado'])); ?>
                            </span>
                        </td>
                        <td class="text-center ac-action-col">
                            <a class="ac-view-btn" href="alerta_correos_ver.php?id=<?php echo (int)$caso['acc_id']; ?>" aria-label="Ver alerta <?php echo acEscape($caso['acc_radicado']); ?>">
                                <span class="fas fa-eye" aria-hidden="true"></span>
                                <span>Ver</span>
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

