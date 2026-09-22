<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$modulo_plataforma = 'Alertas Correos';

require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';

require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';
require_once __DIR__ . '/lib/alerta_correos_reglas.php';
require_once __DIR__ . '/lib/alerta_correos_auditoria.php';
require_once __DIR__ . '/lib/alerta_correos_territorio.php';
require_once __DIR__ . '/lib/alerta_correos_informativas.php';

acExigirPerfil(['Usuario', 'Administrador']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('Caso inválido.');
}

$caso = acObtenerCaso($enlace_db, $id);

if (!$caso) {
    http_response_code(404);
    exit('Caso no encontrado.');
}

if ((string)$caso['acc_estado'] !== 'PENDIENTE_SUBSANACION') {
    acFlash(
        'warning',
        'El caso ya no se encuentra pendiente de subsanación.'
    );

    header('Location: alerta_correos_ver.php?id=' . $id);
    exit;
}

$regionales = acTerritorioListarRegionales($enlace_db);

$regionalId = (int)($caso['acc_regional_id'] ?? 0);
$puntoId = (int)($caso['acc_punto_atencion_id'] ?? 0);

/*
 * Compatibilidad con alertas antiguas que todavía no tengan los IDs
 * territoriales pero sí los nombres.
 */
if ($regionalId <= 0 && !empty($caso['acc_regional'])) {
    foreach ($regionales as $r) {
        if (
            acTerritorioNormalizarClave((string)$r['acp_nombre'])
            ===
            acTerritorioNormalizarClave((string)$caso['acc_regional'])
        ) {
            $regionalId = (int)$r['acp_id'];
            break;
        }
    }
}

$puntos = $regionalId > 0
    ? acTerritorioListarPuntosRegional($enlace_db, $regionalId)
    : [];

if ($puntoId <= 0 && !empty($caso['acc_centro_zonal'])) {
    foreach ($puntos as $p) {
        if (
            acTerritorioNormalizarClave((string)$p['acp_nombre'])
            ===
            acTerritorioNormalizarClave((string)$caso['acc_centro_zonal'])
        ) {
            $puntoId = (int)$p['acp_id'];
            break;
        }
    }
}

$fechaLocal = static function (?string $valor): string {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return '';
    }

    $valor = str_replace(' ', 'T', $valor);

    return substr($valor, 0, 16);
};

$errores = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    acValidarCsrfPost();

    $accion = trim((string)($_POST['accion'] ?? 'guardar'));
    $versionFormulario = (int)($_POST['version'] ?? 0);

    if ($versionFormulario <= 0) {
        $errores[] = 'La versión del caso no es válida. Recargue la pantalla.';
    }

    if (!in_array($accion, ['guardar', 'enviar'], true)) {
        $errores[] = 'Acción inválida.';
    }

    $regionalPost = (int)($_POST['regional_id'] ?? 0);
    $puntoPost = (int)($_POST['punto_atencion_id'] ?? 0);

    $territorio = acTerritorioValidarSeleccion(
        $enlace_db,
        $regionalPost,
        $puntoPost
    );

    if (!$territorio) {
        $errores[] =
            'Seleccione una Regional y un Centro Zonal / Punto de atención válidos.';
    }

    $sim = acPostString('sim', 50);
    $tipoAlerta = strtoupper(acPostString('tipo_alerta', 20));

    $fechaAlerta = acPostString('fecha_alerta', 10);
    $fechaAtencion = acPostString('fecha_atencion', 20);

    $categoria = acPostString('categoria', 255);
    $subcategoria = acPostString('subcategoria', 255);

    $descripcion = acPostString('descripcion', 20000);
    $afecta = acPostString('afecta_linea_tecnica', 100);

    $justificacion = acPostString('justificacion', 20000);
    $observacion = acPostString('observacion', 20000);

    $fechaRemision = acPostString('fecha_remision_dsya', 20);
    $descripcionInicial = acPostString('descripcion_inicial', 20000);
    $agenteRegistra = acPostString('agente_registra', 200);
    $fechaMarcacion = acPostString('fecha_marcacion', 20);

    $comentarioSubsanacion = acPostString(
        'comentario_subsanacion',
        20000
    );

    if ($accion === 'enviar' && $comentarioSubsanacion === '') {
        $errores[] =
            'Debe registrar un comentario de subsanación antes de enviarla a revisión.';
    }

    $datosValidacion = [
        'regional' => $territorio
            ? (string)$territorio['regional']['acp_nombre']
            : '',
        'descripcion' => $descripcion,
        'tipo_alerta' => $tipoAlerta,
    ];

    $errores = array_merge(
        $errores,
        acValidarCasoEntrada($datosValidacion)
    );

    /*
     * Regla informativa.
     *
     * Si durante la subsanación se cambia la categoría, volvemos a
     * calcular si el caso debe o no enviar correo.
     */
    $esInformativaNueva =
        acAlertaEsCategoriaTiempoEspera($categoria);

    $tipoGestion = $esInformativaNueva
        ? 'INFORMATIVA'
        : 'NOTIFICABLE';

    $enviaCorreo = $esInformativaNueva ? 0 : 1;

    $tiempoMinutos = null;
    $tiempoRango = null;
    $tiempoFuente = null;

    if ($esInformativaNueva) {
        $minutosPost = trim(
            (string)($_POST['tiempo_espera_minutos'] ?? '')
        );

        $rangoPost = strtoupper(
            trim((string)($_POST['tiempo_espera_rango'] ?? ''))
        );

        if ($minutosPost !== '' && ctype_digit($minutosPost)) {
            $tiempoMinutos = (int)$minutosPost;

            $tiempoRango =
                $rangoPost !== ''
                    ? $rangoPost
                    : acAlertaTiempoRangoDesdeMinutos($tiempoMinutos);

            $tiempoFuente = 'SUBSANACION';

        } elseif ($rangoPost !== '') {
            $tiempoRango = $rangoPost;
            $tiempoFuente = 'SUBSANACION';

        } else {
            $clasificacion = acAlertaClasificarTiempoEspera(
                $justificacion,
                $descripcion
            );

            $tiempoMinutos = $clasificacion['minutos'];
            $tiempoRango = $clasificacion['rango'];
            $tiempoFuente = $clasificacion['fuente'];
        }
    }

    if (!$errores) {
        try {
            $enlace_db->begin_transaction();

            $lock = acObtenerCaso($enlace_db, $id, true);

            if (!$lock) {
                throw new RuntimeException('Caso no encontrado.');
            }

            if (
                (string)$lock['acc_estado']
                !== 'PENDIENTE_SUBSANACION'
            ) {
                throw new RuntimeException(
                    'El caso ya no se encuentra pendiente de subsanación. Recargue la pantalla.'
                );
            }

            if ((int)$lock['acc_version'] !== $versionFormulario) {
                throw new RuntimeException(
                    'Otro usuario modificó esta alerta mientras usted la estaba editando. Recargue la pantalla antes de continuar.'
                );
            }

            $regionalNombre =
                (string)$territorio['regional']['acp_nombre'];

            $puntoNombre =
                (string)$territorio['punto']['acp_nombre'];

            /*
             * Datos principales.
             */
            $stmt = $enlace_db->prepare(
                "UPDATE tb_alerta_correo_caso
                 SET
                    acc_sim=NULLIF(?, ''),
                    acc_tipo_alerta=?,
                    acc_fecha_alerta=NULLIF(?, ''),
                    acc_fecha_atencion=NULLIF(?, ''),
                    acc_regional=?,
                    acc_centro_zonal=?,
                    acc_regional_id=?,
                    acc_punto_atencion_id=?,
                    acc_categoria=NULLIF(?, ''),
                    acc_subcategoria=NULLIF(?, ''),
                    acc_descripcion=?,
                    acc_afecta_linea_tecnica=NULLIF(?, ''),
                    acc_justificacion=NULLIF(?, ''),
                    acc_observacion=NULLIF(?, ''),
                    acc_usuario_ultima_actualizacion=?,
                    acc_version=acc_version+1
                 WHERE acc_id=?
                   AND acc_estado='PENDIENTE_SUBSANACION'"
            );

            $usuario = acUsuarioActual();

            $stmt->bind_param(
                'ssssssiisssssssi',
                $sim,
                $tipoAlerta,
                $fechaAlerta,
                $fechaAtencion,
                $regionalNombre,
                $puntoNombre,
                $regionalPost,
                $puntoPost,
                $categoria,
                $subcategoria,
                $descripcion,
                $afecta,
                $justificacion,
                $observacion,
                $usuario,
                $id
            );

            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException(
                    'No fue posible guardar los cambios.'
                );
            }

            $stmt->close();

            /*
             * Datos adicionales provenientes principalmente de carga Excel.
             */
            $stmt = $enlace_db->prepare(
                "UPDATE tb_alerta_correo_caso
                 SET
                    acc_fecha_remision_dsya=NULLIF(?, ''),
                    acc_descripcion_inicial=NULLIF(?, ''),
                    acc_agente_registra=NULLIF(?, ''),
                    acc_fecha_marcacion=NULLIF(?, ''),
                    acc_tipo_gestion=?,
                    acc_envia_correo=?,
                    acc_tiempo_espera_minutos=?,
                    acc_tiempo_espera_rango=NULLIF(?, ''),
                    acc_tiempo_espera_fuente=NULLIF(?, '')
                 WHERE acc_id=?"
            );

            $stmt->bind_param(
                'sssssiissi',
                $fechaRemision,
                $descripcionInicial,
                $agenteRegistra,
                $fechaMarcacion,
                $tipoGestion,
                $enviaCorreo,
                $tiempoMinutos,
                $tiempoRango,
                $tiempoFuente,
                $id
            );

            $stmt->execute();
            $stmt->close();

            $camposModificados = [];

            $comparaciones = [
                'SIM' => [$lock['acc_sim'] ?? '', $sim],
                'Prioridad' => [$lock['acc_tipo_alerta'] ?? '', $tipoAlerta],
                'Fecha alerta' => [$lock['acc_fecha_alerta'] ?? '', $fechaAlerta],
                'Fecha atención' => [$lock['acc_fecha_atencion'] ?? '', $fechaAtencion],
                'Regional' => [$lock['acc_regional'] ?? '', $regionalNombre],
                'Centro Zonal' => [$lock['acc_centro_zonal'] ?? '', $puntoNombre],
                'Categoría' => [$lock['acc_categoria'] ?? '', $categoria],
                'Subcategoría' => [$lock['acc_subcategoria'] ?? '', $subcategoria],
                'Descripción' => [$lock['acc_descripcion'] ?? '', $descripcion],
                'Afecta línea técnica' => [$lock['acc_afecta_linea_tecnica'] ?? '', $afecta],
                'Justificación' => [$lock['acc_justificacion'] ?? '', $justificacion],
                'Observación' => [$lock['acc_observacion'] ?? '', $observacion],
                'Fecha remisión DSYA' => [$lock['acc_fecha_remision_dsya'] ?? '', $fechaRemision],
                'Descripción inicial' => [$lock['acc_descripcion_inicial'] ?? '', $descripcionInicial],
                'Agente registra' => [$lock['acc_agente_registra'] ?? '', $agenteRegistra],
                'Fecha marcación' => [$lock['acc_fecha_marcacion'] ?? '', $fechaMarcacion],
            ];

            foreach ($comparaciones as $nombre => [$antes, $despues]) {
                if (
                    trim((string)$antes)
                    !==
                    trim((string)$despues)
                ) {
                    $camposModificados[] = $nombre;
                }
            }

            $resumenCambios = $camposModificados
                ? 'Campos modificados: ' . implode(', ', $camposModificados) . '.'
                : 'No se detectaron cambios de valor.';

            if ($accion === 'enviar') {
                $stmt = $enlace_db->prepare(
                    "UPDATE tb_alerta_correo_caso
                     SET
                        acc_estado='PENDIENTE_REVISION',
                        acc_usuario_ultima_actualizacion=?
                     WHERE acc_id=?
                       AND acc_estado='PENDIENTE_SUBSANACION'"
                );

                $stmt->bind_param('si', $usuario, $id);
                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException(
                        'No fue posible enviar el caso nuevamente a revisión.'
                    );
                }

                $stmt->close();

                acRegistrarHistorial(
                    $enlace_db,
                    $id,
                    'PENDIENTE_SUBSANACION',
                    'PENDIENTE_REVISION',
                    'SUBSANAR',
                    $comentarioSubsanacion . ' ' . $resumenCambios
                );

            } else {
                acRegistrarHistorial(
                    $enlace_db,
                    $id,
                    'PENDIENTE_SUBSANACION',
                    'PENDIENTE_SUBSANACION',
                    'EDITAR_SUBSANACION',
                    $resumenCambios
                );
            }

            $enlace_db->commit();

            if ($accion === 'enviar') {
                acFlash(
                    'success',
                    'La subsanación fue guardada y enviada nuevamente a revisión.'
                );
            } else {
                acFlash(
                    'success',
                    'Los cambios de la subsanación fueron guardados correctamente.'
                );
            }

            header('Location: alerta_correos_ver.php?id=' . $id);
            exit;

        } catch (Throwable $e) {
            try {
                $enlace_db->rollback();
            } catch (Throwable $ignored) {
            }

            error_log(
                'Alertas Correos / editar subsanación: '
                . $e->getMessage()
            );

            $errores[] = $e->getMessage();
        }
    }

    /*
     * Conserva lo digitado cuando exista un error.
     */
    $caso['acc_sim'] = $sim;
    $caso['acc_tipo_alerta'] = $tipoAlerta;
    $caso['acc_fecha_alerta'] = $fechaAlerta;
    $caso['acc_fecha_atencion'] = $fechaAtencion;

    if ($territorio) {
        $caso['acc_regional'] =
            (string)$territorio['regional']['acp_nombre'];

        $caso['acc_centro_zonal'] =
            (string)$territorio['punto']['acp_nombre'];
    }

    $caso['acc_categoria'] = $categoria;
    $caso['acc_subcategoria'] = $subcategoria;
    $caso['acc_descripcion'] = $descripcion;
    $caso['acc_afecta_linea_tecnica'] = $afecta;
    $caso['acc_justificacion'] = $justificacion;
    $caso['acc_observacion'] = $observacion;

    $caso['acc_fecha_remision_dsya'] = $fechaRemision;
    $caso['acc_descripcion_inicial'] = $descripcionInicial;
    $caso['acc_agente_registra'] = $agenteRegistra;
    $caso['acc_fecha_marcacion'] = $fechaMarcacion;

    $regionalId = $regionalPost;
    $puntoId = $puntoPost;

    $puntos = $regionalId > 0
        ? acTerritorioListarPuntosRegional($enlace_db, $regionalId)
        : [];
}

$titulo_header =
    'Alertas Correos | Subsanar ' . (string)$caso['acc_radicado'];

$rangosEspera = acAlertaTiempoRangos();

?>
<!DOCTYPE html>
<html lang="ES">
<head>

    <?php include '../config/configuracion_estilos.php'; ?>

    <link
        rel="stylesheet"
        href="assets/alerta_correos.css?v=20260915"
    >

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

        <a href="alerta_correos.php">
            Alertas Correos
        </a>

        <span class="ac-separator">/</span>

        <a href="alerta_correos_ver.php?id=<?php echo $id; ?>">
            <?php echo acEscape((string)$caso['acc_radicado']); ?>
        </a>

        <span class="ac-separator">/</span>

        <span>Subsanar</span>

    </nav>

    <header class="ac-page-header">

        <div class="ac-page-header__main">

            <h1 class="ac-page-title">
                <span class="fas fa-edit"></span>
                Subsanar alerta
            </h1>

            <p class="ac-page-subtitle">
                Corrija los datos solicitados y envíe nuevamente
                el caso a revisión.
            </p>

        </div>

        <div class="ac-page-header__actions">

            <a
                href="alerta_correos_ver.php?id=<?php echo $id; ?>"
                class="btn btn-outline-danger"
            >
                <span class="fas fa-arrow-left"></span>
                Volver
            </a>

        </div>

    </header>

    <?php if ($errores): ?>

        <div class="alert alert-danger">

            <strong>No fue posible guardar:</strong>

            <ul class="mb-0 mt-2">

                <?php foreach ($errores as $error): ?>

                    <li>
                        <?php echo acEscape((string)$error); ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    <div class="ac-alert-box ac-alert-box--warning mb-3">

        <strong>
            <span class="fas fa-users mr-1"></span>
            Subsanación compartida.
        </strong>

        Cualquier usuario con perfil
        <strong>Usuario</strong>
        puede realizar la corrección de este caso.

        La trazabilidad registrará qué usuario realizó
        los cambios.

    </div>

    <form method="post" autocomplete="off">

        <input
            type="hidden"
            name="_csrf"
            value="<?php echo acEscape(acCsrfToken()); ?>"
        >

        <input
            type="hidden"
            name="id"
            value="<?php echo $id; ?>"
        >

        <input
            type="hidden"
            name="version"
            value="<?php echo (int)$caso['acc_version']; ?>"
        >

        <section class="ac-panel mb-3">

            <div class="ac-panel__header">

                <h2 class="ac-panel__title">
                    <span class="fas fa-file-alt"></span>
                    Información de la alerta
                </h2>

                <span class="ac-panel__meta">
                    <?php echo acEscape((string)$caso['acc_radicado']); ?>
                </span>

            </div>

            <div class="ac-panel__body">

                <div class="row">

                    <div class="col-md-6 form-group">

                        <label>SIM asociado</label>

                        <input
                            type="text"
                            class="form-control"
                            name="sim"
                            maxlength="50"
                            value="<?php
                                echo acEscape(
                                    (string)($caso['acc_sim'] ?? '')
                                );
                            ?>"
                        >

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Prioridad *</label>

                        <select
                            class="form-control"
                            name="tipo_alerta"
                            required
                        >

                            <?php
                            foreach (
                                ['CRITICA', 'ALTA', 'MEDIA', 'BAJA']
                                as $tipo
                            ):
                            ?>

                                <option
                                    value="<?php echo $tipo; ?>"
                                    <?php
                                    echo strtoupper(
                                        (string)$caso['acc_tipo_alerta']
                                    ) === $tipo
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    <?php echo $tipo; ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Fecha de alerta</label>

                        <input
                            type="date"
                            class="form-control"
                            name="fecha_alerta"
                            value="<?php
                                echo acEscape(
                                    (string)(
                                        $caso['acc_fecha_alerta'] ?? ''
                                    )
                                );
                            ?>"
                        >

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Fecha de atención</label>

                        <input
                            type="datetime-local"
                            class="form-control"
                            name="fecha_atencion"
                            value="<?php
                                echo acEscape(
                                    $fechaLocal(
                                        $caso['acc_fecha_atencion'] ?? ''
                                    )
                                );
                            ?>"
                        >

                    </div>

                </div>

            </div>

        </section>

        <section class="ac-panel mb-3">

            <div class="ac-panel__header">

                <h2 class="ac-panel__title">
                    <span class="fas fa-map-marker-alt"></span>
                    Ubicación territorial
                </h2>

            </div>

            <div class="ac-panel__body">

                <div class="row">

                    <div class="col-md-6 form-group">

                        <label>Regional *</label>

                        <select
                            id="regional_id"
                            class="form-control"
                            name="regional_id"
                            required
                        >

                            <option value="">
                                Seleccione
                            </option>

                            <?php foreach ($regionales as $regional): ?>

                                <option
                                    value="<?php echo (int)$regional['acp_id']; ?>"
                                    <?php
                                    echo (int)$regional['acp_id'] === $regionalId
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    <?php
                                    echo acEscape(
                                        (string)$regional['acp_nombre']
                                    );
                                    ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-6 form-group">

                        <label>
                            Centro Zonal / Punto de atención *
                        </label>

                        <select
                            id="punto_atencion_id"
                            class="form-control"
                            name="punto_atencion_id"
                            required
                        >

                            <option value="">
                                Seleccione
                            </option>

                            <?php foreach ($puntos as $punto): ?>

                                <option
                                    value="<?php echo (int)$punto['acp_id']; ?>"
                                    <?php
                                    echo (int)$punto['acp_id'] === $puntoId
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    <?php
                                    echo acEscape(
                                        (string)$punto['acp_nombre']
                                    );
                                    ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

            </div>

        </section>

        <section class="ac-panel mb-3">

            <div class="ac-panel__header">

                <h2 class="ac-panel__title">
                    <span class="fas fa-exclamation-circle"></span>
                    Clasificación y descripción
                </h2>

            </div>

            <div class="ac-panel__body">

                <div class="row">

                    <div class="col-md-6 form-group">

                        <label>Categoría</label>

                        <input
                            type="text"
                            class="form-control"
                            name="categoria"
                            maxlength="255"
                            value="<?php
                                echo acEscape(
                                    (string)(
                                        $caso['acc_categoria'] ?? ''
                                    )
                                );
                            ?>"
                        >

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Subcategoría</label>

                        <input
                            type="text"
                            class="form-control"
                            name="subcategoria"
                            maxlength="255"
                            value="<?php
                                echo acEscape(
                                    (string)(
                                        $caso['acc_subcategoria'] ?? ''
                                    )
                                );
                            ?>"
                        >

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Afecta línea técnica</label>

                        <select
                            class="form-control"
                            name="afecta_linea_tecnica"
                        >

                            <option value="">Seleccione</option>

                            <?php
                            foreach (['SI', 'NO', 'PTE'] as $valor):
                            ?>

                                <option
                                    value="<?php echo $valor; ?>"
                                    <?php
                                    echo strtoupper(
                                        (string)(
                                            $caso[
                                                'acc_afecta_linea_tecnica'
                                            ] ?? ''
                                        )
                                    ) === $valor
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    <?php echo $valor; ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12 form-group">

                        <label>Descripción de la alerta *</label>

                        <textarea
                            class="form-control"
                            name="descripcion"
                            rows="5"
                            maxlength="20000"
                            required
                        ><?php
                            echo acEscape(
                                (string)$caso['acc_descripcion']
                            );
                        ?></textarea>

                    </div>

                    <div class="col-12 form-group">

                        <label>
                            Justificación / concepto técnico
                        </label>

                        <textarea
                            class="form-control"
                            name="justificacion"
                            rows="4"
                            maxlength="20000"
                        ><?php
                            echo acEscape(
                                (string)(
                                    $caso['acc_justificacion'] ?? ''
                                )
                            );
                        ?></textarea>

                    </div>

                    <div class="col-12 form-group">

                        <label>Observación adicional</label>

                        <textarea
                            class="form-control"
                            name="observacion"
                            rows="3"
                            maxlength="20000"
                        ><?php
                            echo acEscape(
                                (string)(
                                    $caso['acc_observacion'] ?? ''
                                )
                            );
                        ?></textarea>

                    </div>

                </div>

            </div>

        </section>

        <section class="ac-panel mb-3">

            <div class="ac-panel__header">

                <h2 class="ac-panel__title">
                    <span class="fas fa-file-excel"></span>
                    Información adicional
                </h2>

            </div>

            <div class="ac-panel__body">

                <div class="row">

                    <div class="col-md-6 form-group">

                        <label>Agente que registra</label>

                        <input
                            type="text"
                            class="form-control"
                            name="agente_registra"
                            maxlength="200"
                            value="<?php
                                echo acEscape(
                                    (string)(
                                        $caso['acc_agente_registra'] ?? ''
                                    )
                                );
                            ?>"
                        >

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Fecha de marcación</label>

                        <input
                            type="datetime-local"
                            class="form-control"
                            name="fecha_marcacion"
                            value="<?php
                                echo acEscape(
                                    $fechaLocal(
                                        $caso[
                                            'acc_fecha_marcacion'
                                        ] ?? ''
                                    )
                                );
                            ?>"
                        >

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Fecha de remisión a DSYA</label>

                        <input
                            type="datetime-local"
                            class="form-control"
                            name="fecha_remision_dsya"
                            value="<?php
                                echo acEscape(
                                    $fechaLocal(
                                        $caso[
                                            'acc_fecha_remision_dsya'
                                        ] ?? ''
                                    )
                                );
                            ?>"
                        >

                    </div>

                    <div class="col-12 form-group">

                        <label>Descripción inicial</label>

                        <textarea
                            class="form-control"
                            name="descripcion_inicial"
                            rows="4"
                            maxlength="20000"
                        ><?php
                            echo acEscape(
                                (string)(
                                    $caso[
                                        'acc_descripcion_inicial'
                                    ] ?? ''
                                )
                            );
                        ?></textarea>

                    </div>

                </div>

            </div>

        </section>

        <section class="ac-panel mb-3">

            <div class="ac-panel__header">

                <h2 class="ac-panel__title">
                    <span class="fas fa-clock"></span>
                    Tiempo de espera
                </h2>

            </div>

            <div class="ac-panel__body">

                <div class="row">

                    <div class="col-md-6 form-group">

                        <label>Minutos identificados</label>

                        <input
                            type="number"
                            min="0"
                            max="1440"
                            class="form-control"
                            name="tiempo_espera_minutos"
                            value="<?php
                                echo !empty(
                                    $caso[
                                        'acc_tiempo_espera_minutos'
                                    ]
                                )
                                    ? (int)$caso[
                                        'acc_tiempo_espera_minutos'
                                    ]
                                    : '';
                            ?>"
                        >

                    </div>

                    <div class="col-md-6 form-group">

                        <label>Rango</label>

                        <select
                            class="form-control"
                            name="tiempo_espera_rango"
                        >

                            <option value="">
                                Sin clasificación manual
                            </option>

                            <?php
                            foreach ($rangosEspera as $codigo => $label):
                            ?>

                                <option
                                    value="<?php echo acEscape($codigo); ?>"
                                    <?php
                                    echo (
                                        (string)(
                                            $caso[
                                                'acc_tiempo_espera_rango'
                                            ] ?? ''
                                        )
                                        === $codigo
                                    )
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    <?php echo acEscape($label); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <small class="text-muted">
                    Estos campos solo afectan la clasificación especial
                    de las alertas informativas de tiempos de espera.
                </small>

            </div>

        </section>

        <section class="ac-panel mb-4">

            <div class="ac-panel__header">

                <h2 class="ac-panel__title">
                    <span class="fas fa-comment-alt"></span>
                    Resultado de la subsanación
                </h2>

            </div>

            <div class="ac-panel__body">

                <div class="form-group">

                    <label>
                        Comentario de la subsanación
                    </label>

                    <textarea
                        class="form-control"
                        name="comentario_subsanacion"
                        rows="4"
                        maxlength="20000"
                        placeholder="Explique qué información fue corregida antes de enviarla nuevamente a revisión."
                    ></textarea>

                    <small class="text-muted">
                        Es obligatorio únicamente cuando envía
                        nuevamente el caso a revisión.
                    </small>

                </div>

                <div
                    class="d-flex flex-column flex-md-row justify-content-end"
                    style="gap:10px"
                >

                    <button
                        type="submit"
                        name="accion"
                        value="guardar"
                        class="btn btn-outline-primary"
                    >
                        <span class="fas fa-save"></span>
                        Guardar cambios
                    </button>

                    <button
                        type="submit"
                        name="accion"
                        value="enviar"
                        class="btn btn-success"
                    >
                        <span class="fas fa-paper-plane"></span>
                        Guardar y enviar a revisión
                    </button>

                </div>

            </div>

        </section>

    </form>

</div>

<script>
(function () {
    'use strict';

    const regional = document.getElementById('regional_id');
    const punto = document.getElementById('punto_atencion_id');

    if (!regional || !punto) {
        return;
    }

    regional.addEventListener('change', async function () {
        const regionalId = this.value;

        punto.innerHTML =
            '<option value="">Cargando...</option>';

        if (!regionalId) {
            punto.innerHTML =
                '<option value="">Seleccione primero una Regional</option>';
            return;
        }

        try {
            const response = await fetch(
                'ajax/centros_zonales_por_regional.php?regional_id='
                + encodeURIComponent(regionalId),
                {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            const json = await response.json();

            punto.innerHTML =
                '<option value="">Seleccione</option>';

            if (!response.ok || !json.ok) {
                throw new Error(
                    json.mensaje || 'No fue posible cargar los puntos.'
                );
            }

            json.data.forEach(function (item) {
                const option = document.createElement('option');

                option.value = item.acp_id;
                option.textContent = item.acp_nombre;

                punto.appendChild(option);
            });

        } catch (error) {
            punto.innerHTML =
                '<option value="">Error cargando puntos</option>';

            console.error(error);
        }
    });
})();
</script>

<?php include '../footer.php'; ?>

</body>
</html>
