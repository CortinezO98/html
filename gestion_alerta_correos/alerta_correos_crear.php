<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_reglas.php';
require_once __DIR__ . '/lib/alerta_correos_datos.php';
require_once __DIR__ . '/lib/alerta_correos_territorio.php';
require_once __DIR__ . '/lib/alerta_correos_sim.php';
require_once __DIR__ . '/lib/alerta_correos_sim_admin.php';

acExigirPerfil(['Operador', 'Gestor', 'Supervisor', 'Administrador']);

$errores = [];
$acDetalleTecnicoLocal = '';
$regionales = acTerritorioListarRegionales($enlace_db);
$regionalSeleccionada = (int)($_POST['regional_id'] ?? 0);
$puntoSeleccionado = (int)($_POST['punto_atencion_id'] ?? 0);
$simSeleccionado = trim((string)($_POST['sim'] ?? ''));
$simBusqueda = trim((string)($_POST['sim_busqueda'] ?? $simSeleccionado));
$simRegistro = null;
$simEsNuevo = false;
$simCreadoAutomaticamente = false;

// Si el usuario digitó un número exacto pero no alcanzó a hacer clic en el
// resultado del autocompletado, el servidor intenta resolverlo de forma segura.
if ($simSeleccionado === '' && $simBusqueda !== '') {
    $simCandidato = acSimObtenerRadicado($enlace_db, $simBusqueda);
    if ($simCandidato) {
        $simSeleccionado = (string)$simCandidato['gera_radicado'];
        $simRegistro = $simCandidato;
        if ($regionalSeleccionada <= 0 && !empty($simCandidato['regional_id'])) {
            $regionalSeleccionada = (int)$simCandidato['regional_id'];
        }
        if ($puntoSeleccionado <= 0 && !empty($simCandidato['punto_atencion_id'])) {
            $puntoSeleccionado = (int)$simCandidato['punto_atencion_id'];
        }
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && preg_match('/^\d{3,50}$/', $simBusqueda)) {
        // Si el SIM numérico no existe, el mismo formulario de Nueva alerta puede crearlo.
        $simSeleccionado = $simBusqueda;
        $simEsNuevo = true;
    }
}

if ($simSeleccionado !== '' && !$simRegistro) {
    $simRegistro = acSimObtenerRadicado($enlace_db, $simSeleccionado);
    if (!$simRegistro && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && preg_match('/^\d{3,50}$/', $simSeleccionado)) {
        $simEsNuevo = true;
    }
}

// El SIM es una ayuda de captura, pero la relación territorial se conserva por ID.
// Si el formulario llega sin los selects (por ejemplo, el usuario digitó el SIM exacto
// y envió el formulario), recuperamos los IDs mapeados desde la fuente propia.
if ($simRegistro) {
    if ($regionalSeleccionada <= 0 && !empty($simRegistro['regional_id'])) {
        $regionalSeleccionada = (int)$simRegistro['regional_id'];
    }
    if ($puntoSeleccionado <= 0 && !empty($simRegistro['punto_atencion_id'])) {
        $puntoSeleccionado = (int)$simRegistro['punto_atencion_id'];
    }
}

$normalizarAfectaSim = static function (string $valor): string {
    $v = acTerritorioNormalizarClave($valor);
    if (in_array($v, ['PTE', 'PENDIENTE', 'PENDIENTE POR VALIDAR'], true)) return 'PTE';
    if (in_array($v, ['SI', 'YES', '1'], true)) return 'SI';
    if (in_array($v, ['NO', '0'], true)) return 'NO';
    return '';
};

$simValor = static function (?array $registro, string $clave): string {
    return $registro ? trim((string)($registro[$clave] ?? '')) : '';
};

$fechaAtencionFormulario = static function (string $valor): string {
    $valor = trim($valor);
    if ($valor === '') return '';
    $valor = str_replace(' ', 'T', $valor);
    return strlen($valor) >= 16 ? substr($valor, 0, 16) : $valor;
};


/**
 * Inserción segura de un caso nuevo.
 *
 * Se mantiene aquí de forma local para evitar el INSERT defectuoso de la
 * versión anterior de acCrearCaso(), que enviaba una cantidad de valores
 * distinta a la cantidad de columnas y provocaba:
 * "Column count doesn't match value count at row 1".
 *
 * La relación territorial por ID se sigue consolidando después mediante
 * acTerritorioVincularCaso(), igual que en el flujo existente.
 */
function acCrearCasoSeguro(mysqli $db, array $datos): int
{
    $usuario = acUsuarioActual();
    $radicado = trim((string)($datos['radicado'] ?? ''));
    $radicadoAutomatico = ($radicado === '');

    // Se usa un valor temporal único para poder obtener el AUTO_INCREMENT y
    // construir después un radicado legible sin condiciones de carrera.
    if ($radicadoAutomatico) {
        $radicado = 'TMP-' . strtoupper(bin2hex(random_bytes(12)));
    }

    $sim = trim((string)($datos['sim'] ?? ''));
    $origen = trim((string)($datos['origen'] ?? 'MANUAL'));
    $origenReferencia = trim((string)($datos['origen_referencia'] ?? ''));
    $tipoAlerta = trim((string)($datos['tipo_alerta'] ?? 'MEDIA'));
    $fechaAlerta = trim((string)($datos['fecha_alerta'] ?? ''));
    $fechaAtencion = trim((string)($datos['fecha_atencion'] ?? ''));
    $regional = trim((string)($datos['regional'] ?? ''));
    $centroZonal = trim((string)($datos['centro_zonal'] ?? ''));
    $categoria = trim((string)($datos['categoria'] ?? ''));
    $subcategoria = trim((string)($datos['subcategoria'] ?? ''));
    $descripcion = trim((string)($datos['descripcion'] ?? ''));
    $afecta = trim((string)($datos['afecta_linea_tecnica'] ?? ''));
    $justificacion = trim((string)($datos['justificacion'] ?? ''));
    $observacion = trim((string)($datos['observacion'] ?? ''));

    $sql = "INSERT INTO tb_alerta_correo_caso (
                acc_radicado,
                acc_sim,
                acc_origen,
                acc_origen_referencia,
                acc_tipo_alerta,
                acc_estado,
                acc_fecha_alerta,
                acc_fecha_atencion,
                acc_regional,
                acc_centro_zonal,
                acc_categoria,
                acc_subcategoria,
                acc_descripcion,
                acc_afecta_linea_tecnica,
                acc_justificacion,
                acc_observacion,
                acc_usuario_creador,
                acc_usuario_ultima_actualizacion
            ) VALUES (
                ?, NULLIF(?, ''), ?, NULLIF(?, ''), ?, 'PENDIENTE_REVISION',
                NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''),
                NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?
            )";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        'sssssssssssssssss',
        $radicado,
        $sim,
        $origen,
        $origenReferencia,
        $tipoAlerta,
        $fechaAlerta,
        $fechaAtencion,
        $regional,
        $centroZonal,
        $categoria,
        $subcategoria,
        $descripcion,
        $afecta,
        $justificacion,
        $observacion,
        $usuario,
        $usuario
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    if ($id <= 0) {
        throw new RuntimeException('No se obtuvo el identificador del caso después de insertarlo.');
    }

    if ($radicadoAutomatico) {
        $radicadoFinal = sprintf('AC-%s-%06d', date('Ymd'), $id);
        $upd = $db->prepare('UPDATE tb_alerta_correo_caso SET acc_radicado=? WHERE acc_id=?');
        $upd->bind_param('si', $radicadoFinal, $id);
        $upd->execute();
        $upd->close();
    }

    return $id;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    acValidarCsrfPost();

    if ($simBusqueda !== '' && !$simRegistro && !$simEsNuevo) {
        $errores[] = 'El número SIM no existe y no tiene un formato numérico válido para registrarlo automáticamente.';
    }

    // Si el SIM ya existe pero está inactivo, no se crea un duplicado silencioso.
    if ($simEsNuevo && $simSeleccionado !== '') {
        $simExistente = acSimAdminObtenerPorSim($enlace_db, $simSeleccionado);
        if ($simExistente && (int)($simExistente['ars_activo'] ?? 0) !== 1) {
            $errores[] = 'El número SIM ' . $simSeleccionado . ' ya existe en Radicados SIM de referencia pero está inactivo. Reactívelo antes de utilizarlo.';
        }
    }

    $territorio = acTerritorioValidarSeleccion($enlace_db, $regionalSeleccionada, $puntoSeleccionado);
    if (!$territorio) {
        $errores[] = 'Seleccione una Regional y un Centro Zonal / Punto de atención válidos.';
    }

    // Los campos que ya existen en la fuente SIM se usan como valor por defecto
    // únicamente cuando el operador no escribió/modificó un valor en el formulario.
    $fechaAlertaPost = acPostString('fecha_alerta', 10);
    $fechaAtencionPost = acPostString('fecha_atencion', 20);
    $categoriaPost = acPostString('categoria', 255);
    $subcategoriaPost = acPostString('subcategoria', 255);
    $descripcionPost = acPostString('descripcion', 20000);
    $afectaPost = acPostString('afecta_linea_tecnica', 100);
    $justificacionPost = acPostString('justificacion', 20000);

    if ($simRegistro) {
        if ($fechaAlertaPost === '') $fechaAlertaPost = substr($simValor($simRegistro, 'fecha_alerta'), 0, 10);
        if ($fechaAtencionPost === '') $fechaAtencionPost = $fechaAtencionFormulario($simValor($simRegistro, 'fecha_atencion'));
        if ($categoriaPost === '') $categoriaPost = mb_substr($simValor($simRegistro, 'categoria'), 0, 255, 'UTF-8');
        if ($subcategoriaPost === '') $subcategoriaPost = mb_substr($simValor($simRegistro, 'subcategoria'), 0, 255, 'UTF-8');
        if ($descripcionPost === '') $descripcionPost = mb_substr($simValor($simRegistro, 'descripcion'), 0, 20000, 'UTF-8');
        if ($afectaPost === '') $afectaPost = $normalizarAfectaSim($simValor($simRegistro, 'afecta_linea_tecnica'));
        if ($justificacionPost === '') $justificacionPost = mb_substr($simValor($simRegistro, 'justificacion'), 0, 20000, 'UTF-8');
    }

    $datos = [
        'radicado' => acPostString('radicado', 50),
        'sim' => $simSeleccionado,
        'origen' => 'MANUAL',
        'tipo_alerta' => acPostString('tipo_alerta', 20),
        'fecha_alerta' => $fechaAlertaPost,
        'fecha_atencion' => $fechaAtencionPost,
        // Se resuelven en servidor. El usuario no puede inyectar el nombre territorial.
        'regional' => $territorio ? (string)$territorio['regional']['acp_nombre'] : '',
        'centro_zonal' => $territorio ? (string)$territorio['punto']['acp_nombre'] : '',
        'categoria' => $categoriaPost,
        'subcategoria' => $subcategoriaPost,
        'descripcion' => $descripcionPost,
        'afecta_linea_tecnica' => $afectaPost,
        'justificacion' => $justificacionPost,
        'observacion' => acPostString('observacion', 20000),
    ];

    $errores = array_merge($errores, acValidarCasoEntrada($datos));

    if (!$errores) {
        try {
            $enlace_db->begin_transaction();

            // Cuando el operador escribió un SIM que todavía no existe, se registra
            // automáticamente en Fuente SIM usando los mismos datos de esta alerta.
            if ($simEsNuevo && $simSeleccionado !== '') {
                $entradaSim = [
                    'sim' => $simSeleccionado,
                    'estado' => 'Pendiente',
                    'fecha_alerta' => $fechaAlertaPost,
                    'fecha_atencion' => $fechaAtencionPost,
                    'regional_id' => $regionalSeleccionada,
                    'punto_atencion_id' => $puntoSeleccionado,
                    'categoria' => $categoriaPost,
                    'subcategoria' => $subcategoriaPost,
                    'afecta_linea_tecnica' => $afectaPost,
                    'descripcion' => $descripcionPost,
                    'justificacion' => $justificacionPost,
                    'fecha_remision_agente' => '',
                    'fecha_notificacion_regional' => '',
                ];
                error_log('Alertas Correos / crear etapa: creando SIM nuevo');
                acSimAdminCrear($enlace_db, $entradaSim, acUsuarioActual());
                $simCreadoAutomaticamente = true;
            }

            error_log('Alertas Correos / crear etapa: creando caso');
            $id = acCrearCasoSeguro($enlace_db, $datos);
            error_log('Alertas Correos / crear etapa: vinculando territorio');
            acTerritorioVincularCaso($enlace_db, $id, $regionalSeleccionada, $puntoSeleccionado);
            $enlace_db->commit();

            if ($simCreadoAutomaticamente) {
                acFlash('success', 'La alerta fue registrada y el SIM ' . $simSeleccionado . ' se agregó automáticamente a Radicados SIM de referencia. El caso quedó pendiente de revisión.');
            } else {
                acFlash('success', 'La alerta fue registrada y quedó pendiente de revisión.');
            }
            header('Location: alerta_correos_ver.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            try {
                $enlace_db->rollback();
            } catch (Throwable $ignored) {
            }
            $acDetalleTecnicoLocal = trim((string)$e->getMessage());
            error_log('Alertas Correos / crear: ' . $acDetalleTecnicoLocal);
            $errores[] = 'No fue posible crear la alerta. Verifique la información e intente nuevamente.';

            // En desarrollo local mostramos el detalle real para poder corregir la causa
            // sin exponer información técnica en SAN/producción.
            $acServidor = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
            $acHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $acEsLocal = in_array($acServidor, ['localhost', '127.0.0.1', '::1'], true)
                || str_starts_with($acHost, 'localhost:')
                || str_starts_with($acHost, '127.0.0.1:');
            if ($acEsLocal && $acDetalleTecnicoLocal !== '') {
                $errores[] = 'Detalle técnico local: ' . $acDetalleTecnicoLocal;
            }
        }
    }
}

$titulo_header = 'Alertas Correos | Nueva alerta manual';

// Valores de presentación: POST siempre gana; la fuente SIM solo completa vacíos.
$valorFechaAlerta = array_key_exists('fecha_alerta', $_POST)
    ? (string)$_POST['fecha_alerta']
    : (($simRegistro && $simValor($simRegistro, 'fecha_alerta') !== '') ? substr($simValor($simRegistro, 'fecha_alerta'), 0, 10) : date('Y-m-d'));
$valorFechaAtencion = array_key_exists('fecha_atencion', $_POST)
    ? (string)$_POST['fecha_atencion']
    : $fechaAtencionFormulario($simValor($simRegistro, 'fecha_atencion'));
$valorCategoria = array_key_exists('categoria', $_POST) ? (string)$_POST['categoria'] : $simValor($simRegistro, 'categoria');
$valorSubcategoria = array_key_exists('subcategoria', $_POST) ? (string)$_POST['subcategoria'] : $simValor($simRegistro, 'subcategoria');
$valorDescripcion = array_key_exists('descripcion', $_POST) ? (string)$_POST['descripcion'] : $simValor($simRegistro, 'descripcion');
$valorJustificacion = array_key_exists('justificacion', $_POST) ? (string)$_POST['justificacion'] : $simValor($simRegistro, 'justificacion');
$valorAfecta = array_key_exists('afecta_linea_tecnica', $_POST)
    ? (string)$_POST['afecta_linea_tecnica']
    : $normalizarAfectaSim($simValor($simRegistro, 'afecta_linea_tecnica'));
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>
    <link rel="stylesheet" href="assets/alerta_correos.css?v=20260909">
    <link rel="stylesheet" href="assets/alerta_correos_sim.css?v=20260909-3">
    <link rel="stylesheet" href="assets/alerta_correos_alertas.css?v=20260909-1">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php
include '../menu_principal.php';
include '../menu_header.php';
?>

<div class="contenido ac-module ac-module--footer-safe">
    <nav class="ac-breadcrumb" aria-label="breadcrumb">
        <a href="../contenido.php">Inicio</a>
        <span class="ac-separator">/</span>
        <a href="alerta_correos.php">Alertas Correos</a>
        <span class="ac-separator">/</span>
        <span>Nueva alerta manual</span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title">
                <span class="fas fa-plus-circle" aria-hidden="true"></span>
                Registrar alerta manualmente
            </h1>
            <p class="ac-page-subtitle">Complete la información disponible. Los campos marcados con <strong>*</strong> son obligatorios y el caso quedará pendiente de revisión.</p>
        </div>
        <div class="ac-page-header__actions">
            <a href="alerta_correos.php" class="btn ac-btn-red-outline">
                <span class="fas fa-arrow-left"></span> Volver a la bandeja
            </a>
        </div>
    </header>

    <?php if ($errores): ?>
        <div class="alert alert-danger d-none" role="alert" data-ac-alert-fallback>
            <div class="font-weight-bold mb-1"><span class="fas fa-exclamation-circle mr-1"></span> Revise la información ingresada</div>
            <ul class="mb-0 pl-4">
                <?php foreach ($errores as $error): ?>
                    <li><?php echo acEscape($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <noscript>
            <div class="alert alert-danger" role="alert">Active JavaScript para visualizar las alertas interactivas del módulo.</div>
        </noscript>
    <?php endif; ?>

    <?php if (!$regionales): ?>
        <div class="alert alert-warning" role="alert">
            <strong><span class="fas fa-database mr-1"></span> Catálogo territorial vacío.</strong>
            Antes de registrar alertas, un administrador debe realizar la carga de responsables para crear las Regionales y Centros Zonales disponibles.
        </div>
    <?php endif; ?>

    <form id="ac-form-nueva-alerta" method="post" novalidate data-ac-lock-submit="1"
          data-ac-swal-confirm="1"
          data-ac-swal-title="¿Registrar esta alerta?"
          data-ac-swal-text="El caso quedará pendiente de revisión. No se enviará correo hasta que un Gestor/Supervisor lo apruebe."
          data-ac-swal-confirm-text="Sí, registrar"
          data-ac-swal-icon="question">
        <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">

        <section class="ac-form-section" aria-labelledby="ac-seccion-identificacion">
            <h2 id="ac-seccion-identificacion" class="ac-form-section__title">
                <span class="fas fa-fingerprint"></span> Identificación de la alerta
            </h2>

            <div class="form-row">
                <div class="col-12 col-md-4 form-group">
                    <label class="ac-label" for="radicado">Radicado</label>
                    <input id="radicado" class="form-control" name="radicado" maxlength="50"
                           value="<?php echo acEscape((string)($_POST['radicado'] ?? '')); ?>"
                           autocomplete="off" placeholder="Ej. AC-20260909-001">
                    <small class="ac-help">Opcional. Si se deja vacío, el sistema genera uno automáticamente.</small>
                </div>

                <div class="col-12 col-md-4 form-group">
                    <label class="ac-label" for="sim_busqueda">Número SIM</label>
                    <div class="ac-sim-picker">
                        <div class="ac-sim-picker__input-wrap">
                            <input id="sim_busqueda"
                                   class="form-control ac-sim-picker__input"
                                   type="search"
                                   name="sim_busqueda"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   value="<?php echo acEscape($simBusqueda); ?>"
                                   placeholder="Buscar radicado SIM..."
                                   data-endpoint="ajax/radicados_sim.php"
                                   aria-autocomplete="list"
                                   aria-controls="ac-sim-resultados">
                            <span class="fas fa-search" aria-hidden="true"></span>
                        </div>
                        <input type="hidden" id="sim" name="sim" value="<?php echo acEscape($simSeleccionado); ?>">
                        <div id="ac-sim-resultados" class="ac-sim-picker__results d-none" role="listbox"></div>
                    </div>
                    <small class="ac-help">Digite al menos 3 caracteres para buscar. Si el SIM existe, selecciónelo y se precargarán sus datos. Si no existe, complete esta misma alerta y el SIM se registrará automáticamente en Fuente SIM al guardar.</small>

                    <div id="ac-sim-seleccionado" class="ac-sim-selected <?php echo $simRegistro ? '' : 'd-none'; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div id="ac-sim-seleccionado-titulo" class="ac-sim-selected__title">
                                    <?php echo $simRegistro ? 'SIM ' . acEscape((string)$simRegistro['gera_radicado']) : ''; ?>
                                </div>
                                <div id="ac-sim-seleccionado-meta" class="ac-sim-selected__meta">
                                    <?php
                                    if ($simRegistro) {
                                        $partesSim = [];
                                        if (!empty($simRegistro['regional_catalogo']) || !empty($simRegistro['regional_nombre'])) {
                                            $partesSim[] = (string)($simRegistro['regional_catalogo'] ?: $simRegistro['regional_nombre']);
                                        }
                                        if (!empty($simRegistro['centro_zonal_catalogo']) || !empty($simRegistro['centro_zonal_nombre'])) {
                                            $partesSim[] = (string)($simRegistro['centro_zonal_catalogo'] ?: $simRegistro['centro_zonal_nombre']);
                                        }
                                        if (!empty($simRegistro['fecha_atencion']) || !empty($simRegistro['gera_fecha_peticion'])) {
                                            $partesSim[] = 'Atención: ' . (string)($simRegistro['fecha_atencion'] ?: $simRegistro['gera_fecha_peticion']);
                                        }
                                        if (!empty($simRegistro['categoria'])) {
                                            $partesSim[] = (string)$simRegistro['categoria'];
                                        }
                                        echo acEscape(implode(' · ', $partesSim));
                                    }
                                    ?>
                                </div>
                            </div>
                            <button id="ac-sim-limpiar" type="button" class="ac-sim-selected__clear" title="Quitar radicado SIM">
                                <span class="fas fa-times mr-1"></span>Quitar
                            </button>
                        </div>
                    </div>

                    <div id="ac-sim-nuevo" class="ac-sim-new d-none" role="status" aria-live="polite">
                        <div class="ac-sim-new__title"><span class="fas fa-plus-circle mr-1"></span>SIM nuevo</div>
                        <div class="ac-sim-new__text">
                            <span id="ac-sim-nuevo-numero"></span> no está registrado. Complete Regional, Centro Zonal y los datos de la alerta; al pulsar <strong>Guardar alerta</strong> se agregará automáticamente a Fuente SIM y se creará el caso en la misma transacción.
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4 form-group">
                    <label class="ac-label" for="tipo_alerta">Tipo de alerta <span class="ac-required">*</span></label>
                    <select id="tipo_alerta" class="custom-select" name="tipo_alerta" required>
                        <?php foreach (['CRITICA' => 'Crítica', 'ALTA' => 'Alta', 'MEDIA' => 'Media', 'BAJA' => 'Baja'] as $valor => $texto): ?>
                            <option value="<?php echo $valor; ?>" <?php echo $valor === ($_POST['tipo_alerta'] ?? 'MEDIA') ? 'selected' : ''; ?>><?php echo $texto; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="col-12 col-md-6 form-group mb-md-0">
                    <label class="ac-label" for="fecha_alerta">Fecha de alerta</label>
                    <input id="fecha_alerta" type="date" class="form-control" name="fecha_alerta"
                           value="<?php echo acEscape($valorFechaAlerta); ?>">
                </div>
                <div class="col-12 col-md-6 form-group mb-0">
                    <label class="ac-label" for="fecha_atencion">Fecha y hora de atención</label>
                    <input id="fecha_atencion" type="datetime-local" class="form-control" name="fecha_atencion"
                           value="<?php echo acEscape($valorFechaAtencion); ?>">
                </div>
            </div>
        </section>

        <section class="ac-form-section" aria-labelledby="ac-seccion-territorio">
            <h2 id="ac-seccion-territorio" class="ac-form-section__title">
                <span class="fas fa-map-marker-alt"></span> Ubicación territorial
            </h2>

            <div class="form-row">
                <div class="col-12 col-md-6 form-group mb-md-0">
                    <label class="ac-label" for="regional_id">Regional <span class="ac-required">*</span></label>
                    <select id="regional_id" class="custom-select" name="regional_id" required <?php echo !$regionales ? 'disabled' : ''; ?>>
                        <option value="">Seleccione una regional</option>
                        <?php foreach ($regionales as $regional): ?>
                            <option value="<?php echo (int)$regional['acp_id']; ?>" <?php echo $regionalSeleccionada === (int)$regional['acp_id'] ? 'selected' : ''; ?>>
                                <?php echo acEscape((string)$regional['acp_nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="ac-help">La lista proviene del catálogo territorial activo del módulo. Si selecciona un SIM mapeado, este campo se completa automáticamente.</small>
                </div>

                <div class="col-12 col-md-6 form-group mb-0">
                    <label class="ac-label" for="punto_atencion_id">Centro Zonal / Punto de atención <span class="ac-required">*</span></label>
                    <select id="punto_atencion_id"
                            class="custom-select"
                            name="punto_atencion_id"
                            required
                            disabled
                            data-endpoint="ajax/centros_zonales_por_regional.php"
                            data-selected="<?php echo $puntoSeleccionado > 0 ? (int)$puntoSeleccionado : ''; ?>">
                        <option value="">Seleccione primero una regional</option>
                    </select>
                    <div id="ac-territorio-feedback" class="small mt-1 text-muted">Seleccione una regional para consultar sus puntos de atención.</div>
                </div>
            </div>

            <div class="ac-alert-box ac-alert-box--info mt-3 mb-0">
                <span class="fas fa-info-circle mr-1"></span>
                El responsable y el correo <strong>no se seleccionan manualmente</strong>. Cuando el caso sea aprobado, el sistema resolverá automáticamente los responsables vigentes asociados a la Regional y al Punto de atención seleccionado.
            </div>
        </section>

        <section class="ac-form-section" aria-labelledby="ac-seccion-clasificacion">
            <h2 id="ac-seccion-clasificacion" class="ac-form-section__title">
                <span class="fas fa-tags"></span> Clasificación
            </h2>

            <div class="form-row">
                <div class="col-12 col-md-6 form-group">
                    <label class="ac-label" for="categoria">Categoría</label>
                    <input id="categoria" class="form-control" name="categoria" maxlength="255"
                           value="<?php echo acEscape($valorCategoria); ?>"
                           placeholder="Ej. Tiempos de espera muy largos">
                </div>
                <div class="col-12 col-md-6 form-group">
                    <label class="ac-label" for="subcategoria">Subcategoría</label>
                    <input id="subcategoria" class="form-control" name="subcategoria" maxlength="255"
                           value="<?php echo acEscape($valorSubcategoria); ?>"
                           placeholder="Detalle de la categoría, si aplica">
                </div>
                <div class="col-12 col-md-6 form-group mb-0">
                    <label class="ac-label" for="afecta_linea_tecnica">¿Afecta línea técnica?</label>
                    <select id="afecta_linea_tecnica" class="custom-select" name="afecta_linea_tecnica">
                        <option value="" <?php echo $valorAfecta === '' ? 'selected' : ''; ?>>Seleccione una opción</option>
                        <option value="SI" <?php echo $valorAfecta === 'SI' ? 'selected' : ''; ?>>Sí</option>
                        <option value="NO" <?php echo $valorAfecta === 'NO' ? 'selected' : ''; ?>>No</option>
                        <option value="PTE" <?php echo $valorAfecta === 'PTE' ? 'selected' : ''; ?>>Pendiente por validar</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="ac-form-section" aria-labelledby="ac-seccion-detalle">
            <h2 id="ac-seccion-detalle" class="ac-form-section__title">
                <span class="fas fa-align-left"></span> Detalle y concepto
            </h2>

            <div class="form-group">
                <label class="ac-label" for="descripcion">Descripción de la alerta <span class="ac-required">*</span><span id="ac-descripcion-count" class="ac-char-count"></span></label>
                <textarea id="descripcion" class="form-control" rows="5" name="descripcion" maxlength="20000"
                          data-ac-maxlength="20000" data-ac-counter="ac-descripcion-count" required
                          placeholder="Describa de forma clara y verificable la situación reportada..."><?php echo acEscape($valorDescripcion); ?></textarea>
                <small class="ac-help">Evite incluir información innecesaria y priorice hechos, tiempos y condiciones observables.</small>
            </div>

            <div class="form-group">
                <label class="ac-label" for="justificacion">Justificación / concepto técnico <span id="ac-justificacion-count" class="ac-char-count"></span></label>
                <textarea id="justificacion" class="form-control" rows="4" name="justificacion" maxlength="20000"
                          data-ac-maxlength="20000" data-ac-counter="ac-justificacion-count"
                          placeholder="Registre el concepto técnico o evidencia que sustenta la alerta..."><?php echo acEscape($valorJustificacion); ?></textarea>
            </div>

            <div class="form-group mb-0">
                <label class="ac-label" for="observacion">Observación adicional <span id="ac-observacion-count" class="ac-char-count"></span></label>
                <textarea id="observacion" class="form-control" rows="3" name="observacion" maxlength="20000"
                          data-ac-maxlength="20000" data-ac-counter="ac-observacion-count"
                          placeholder="Incluya únicamente información complementaria que ayude a la revisión."><?php echo acEscape((string)($_POST['observacion'] ?? '')); ?></textarea>
            </div>
        </section>

        <div class="ac-alert-box ac-alert-box--info mb-3">
            <span class="fas fa-info-circle mr-1"></span>
            Al guardar, el sistema registrará la trazabilidad del usuario y el caso pasará a <strong>Pendiente de revisión</strong>. El correo territorial no se envía en esta etapa.
        </div>

        <div class="ac-actions-bar ac-actions-bar--center">
            <a href="alerta_correos.php" class="btn ac-btn-red-outline">
                <span class="fas fa-times"></span> Cancelar
            </a>
            <button type="submit" class="btn ac-btn-green-outline" <?php echo !$regionales ? 'disabled' : ''; ?>>
                <span class="fas fa-save"></span> Guardar alerta
            </button>
        </div>
    </form>
</div>

<?php
include '../footer.php';
include '../config/configuracion_js.php';
?>
<script>
window.AC_ALERTAS_CONFIG = <?php echo json_encode([
    'errores' => array_values($errores),
    'flash' => null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/alerta_correos.js?v=20260909"></script>
<script src="assets/alerta_correos_territorio.js?v=20260909-2"></script>
<script src="assets/alerta_correos_sim.js?v=20260909-3"></script>
<script src="assets/alerta_correos_alertas.js?v=20260909-2"></script>
</body>
</html>

