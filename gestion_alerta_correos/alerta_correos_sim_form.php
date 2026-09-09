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
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$editando = $id > 0;
$actual = $editando ? acSimAdminObtenerPorId($enlace_db, $id) : null;
if ($editando && !$actual) {
    http_response_code(404);
    die('Registro SIM no encontrado.');
}

$titulo_header = $editando ? 'Alertas Correos | Editar SIM' : 'Alertas Correos | Nuevo SIM';
$error = null;
$regionales = acTerritorioListarRegionales($enlace_db);

$val = [
    'sim' => $actual['ars_sim'] ?? '',
    'estado' => $actual['ars_estado'] ?? 'Pendiente',
    'fecha_alerta' => $actual['ars_fecha_alerta'] ?? '',
    'fecha_atencion' => !empty($actual['ars_fecha_atencion']) ? str_replace(' ', 'T', substr((string)$actual['ars_fecha_atencion'],0,16)) : '',
    'regional_id' => (string)($actual['ars_regional_id'] ?? ''),
    'punto_atencion_id' => (string)($actual['ars_punto_atencion_id'] ?? ''),
    'categoria' => $actual['ars_categoria'] ?? '',
    'subcategoria' => $actual['ars_subcategoria'] ?? '',
    'afecta_linea_tecnica' => $actual['ars_afecta_linea_tecnica'] ?? '',
    'fecha_remision_agente' => !empty($actual['ars_fecha_remision_agente']) ? str_replace(' ', 'T', substr((string)$actual['ars_fecha_remision_agente'],0,16)) : '',
    'fecha_notificacion_regional' => !empty($actual['ars_fecha_notificacion_regional']) ? str_replace(' ', 'T', substr((string)$actual['ars_fecha_notificacion_regional'],0,16)) : '',
    'descripcion' => $actual['ars_descripcion'] ?? '',
    'justificacion' => $actual['ars_justificacion'] ?? '',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach (array_keys($val) as $k) {
        if (array_key_exists($k, $_POST)) $val[$k] = (string)$_POST[$k];
    }
    try {
        acValidarCsrfPost();
        $usuario = acUsuarioActual();
        $enlace_db->begin_transaction();
        try {
            if ($editando) {
                acSimAdminActualizar($enlace_db, $id, $val, $usuario);
                $enlace_db->commit();
                header('Location: alerta_correos_sim.php?ok=actualizado'); exit;
            }
            $nuevoId = acSimAdminCrear($enlace_db, $val, $usuario);
            $enlace_db->commit();
            header('Location: alerta_correos_sim_ver.php?id=' . $nuevoId . '&ok=creado'); exit;
        } catch (Throwable $e) {
            $enlace_db->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        error_log('Alertas Correos / formulario Fuente SIM: ' . $e->getMessage());
        $error = $e->getMessage();
    }
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
        <a href="alerta_correos_sim.php">Fuente SIM</a><span class="ac-separator">/</span>
        <span><?php echo $editando ? 'Editar SIM' : 'Nuevo SIM'; ?></span>
    </nav>

    <header class="ac-page-header">
        <div class="ac-page-header__main">
            <h1 class="ac-page-title"><span class="fas <?php echo $editando?'fa-edit':'fa-plus-circle'; ?>"></span> <?php echo $editando?'Editar radicado SIM':'Registrar nuevo radicado SIM'; ?></h1>
            <p class="ac-page-subtitle">La ubicación se valida contra el catálogo territorial activo. El SIM no se duplica y las ediciones conservan historial.</p>
        </div>
        <div class="ac-page-header__actions"><a href="alerta_correos_sim.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver</a></div>
    </header>

    <?php if ($error): ?><div class="alert alert-danger"><span class="fas fa-exclamation-circle mr-1"></span><?php echo acEscape($error); ?></div><?php endif; ?>

    <form method="post" data-ac-lock-submit="1">
        <input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <section class="ac-panel mb-3">
            <div class="ac-panel__header"><h2 class="ac-panel__title"><span class="fas fa-hashtag"></span> Identificación y ubicación</h2></div>
            <div class="ac-panel__body">
                <div class="row">
                    <div class="form-group col-12 col-md-4">
                        <label for="sim">Número SIM *</label>
                        <input id="sim" name="sim" class="form-control" maxlength="50" required value="<?php echo acEscape((string)$val['sim']); ?>" <?php echo $editando?'readonly':''; ?> placeholder="Ej.: 140164556">
                        <?php if ($editando): ?><small class="form-text text-muted">El número SIM no se modifica para preservar trazabilidad.</small><?php endif; ?>
                    </div>
                    <div class="form-group col-12 col-md-4">
                        <label for="regional_id">Regional *</label>
                        <select id="regional_id" name="regional_id" class="form-control" required>
                            <option value="">Seleccione una regional</option>
                            <?php foreach ($regionales as $r): ?>
                                <option value="<?php echo (int)$r['acp_id']; ?>" <?php echo (string)$val['regional_id']===(string)$r['acp_id']?'selected':''; ?>><?php echo acEscape((string)$r['acp_nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-12 col-md-4">
                        <label for="punto_atencion_id">Centro Zonal / Punto de atención *</label>
                        <select id="punto_atencion_id" name="punto_atencion_id" class="form-control" required data-endpoint="ajax/centros_zonales_por_regional.php" data-selected="<?php echo acEscape((string)$val['punto_atencion_id']); ?>">
                            <option value="">Seleccione primero una regional</option>
                        </select>
                        <div id="ac-territorio-feedback" class="small mt-1 text-muted">Seleccione una regional para consultar sus puntos de atención.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="ac-panel mb-3">
            <div class="ac-panel__header"><h2 class="ac-panel__title"><span class="fas fa-clipboard-list"></span> Datos de la alerta</h2></div>
            <div class="ac-panel__body">
                <div class="row">
                    <div class="form-group col-12 col-md-4"><label for="estado">Estado fuente</label><input id="estado" name="estado" class="form-control" maxlength="50" value="<?php echo acEscape((string)$val['estado']); ?>"></div>
                    <div class="form-group col-12 col-md-4"><label for="fecha_alerta">Fecha de alerta</label><input id="fecha_alerta" name="fecha_alerta" type="date" class="form-control" value="<?php echo acEscape((string)$val['fecha_alerta']); ?>"></div>
                    <div class="form-group col-12 col-md-4"><label for="fecha_atencion">Fecha de atención al ciudadano</label><input id="fecha_atencion" name="fecha_atencion" type="datetime-local" class="form-control" value="<?php echo acEscape((string)$val['fecha_atencion']); ?>"></div>
                    <div class="form-group col-12 col-md-6"><label for="categoria">Categoría de afectación</label><input id="categoria" name="categoria" class="form-control" maxlength="255" value="<?php echo acEscape((string)$val['categoria']); ?>"></div>
                    <div class="form-group col-12 col-md-6"><label for="subcategoria">Subcategoría</label><input id="subcategoria" name="subcategoria" class="form-control" maxlength="255" value="<?php echo acEscape((string)$val['subcategoria']); ?>"></div>
                    <div class="form-group col-12 col-md-4"><label for="afecta_linea_tecnica">¿Afecta línea técnica?</label><select id="afecta_linea_tecnica" name="afecta_linea_tecnica" class="form-control"><option value="">Seleccione</option><?php foreach (['SI'=>'Sí','NO'=>'No','PTE'=>'Pendiente'] as $k=>$txt): ?><option value="<?php echo $k; ?>" <?php echo strtoupper((string)$val['afecta_linea_tecnica'])===$k?'selected':''; ?>><?php echo $txt; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group col-12 col-md-4"><label for="fecha_remision_agente">Fecha remisión agente especializado</label><input id="fecha_remision_agente" name="fecha_remision_agente" type="datetime-local" class="form-control" value="<?php echo acEscape((string)$val['fecha_remision_agente']); ?>"></div>
                    <div class="form-group col-12 col-md-4"><label for="fecha_notificacion_regional">Fecha notificación regional</label><input id="fecha_notificacion_regional" name="fecha_notificacion_regional" type="datetime-local" class="form-control" value="<?php echo acEscape((string)$val['fecha_notificacion_regional']); ?>"></div>
                    <div class="form-group col-12"><label for="descripcion">Descripción de la alerta</label><textarea id="descripcion" name="descripcion" rows="4" class="form-control"><?php echo acEscape((string)$val['descripcion']); ?></textarea></div>
                    <div class="form-group col-12"><label for="justificacion">Justificación</label><textarea id="justificacion" name="justificacion" rows="3" class="form-control"><?php echo acEscape((string)$val['justificacion']); ?></textarea></div>
                </div>
            </div>
        </section>

        <div class="ac-actions-bar ac-actions-bar--center">
            <a href="alerta_correos_sim.php" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a>
            <button type="submit" class="btn ac-btn-green-outline"><span class="fas fa-save"></span> <?php echo $editando?'Guardar cambios':'Registrar SIM'; ?></button>
        </div>
    </form>
</div>

<?php include '../footer.php'; include '../config/configuracion_js.php'; ?>
<script src="assets/alerta_correos_territorio.js?v=20260909"></script>
</body>
</html>
