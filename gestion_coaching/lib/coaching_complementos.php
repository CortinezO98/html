<?php
declare(strict_types=1);

/**
 * Funciones complementarias del módulo Coaching: soportes/adjuntos y
 * detalle estructurado por tipo (Acta/Felicitación).
 *
 * NOTA: las funciones de encuesta que traía originalmente este archivo
 * se eliminaron — usaban columnas que no existen en la base real
 * (prefijos gce_ / gcer_, no confirmados en la BD). La encuesta de
 * percepción REAL ya vive en
 * lib/coaching_datos.php (listarPreguntasEncuestaActivas,
 * guardarEncuestaPercepcion, obtenerEncuestaPercepcion) e integrada en
 * gestion_coaching_firmar.php — no duplicar aquí. Ver
 * ESTADO_REAL_MODULO_COACHING_v2.md.
 */
function coachingEsc(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------------------------------------------------------------------
// Detalle estructurado por tipo (Acta de Compromiso / Felicitación).
// Tabla NUEVA, sin choque con nada existente — requiere la migración
// 20260724_014_create_detalle_tipo.sql antes de usarse.
// ---------------------------------------------------------------------
function obtenerDetalleTipo(mysqli $db, string $paquete): ?array
{
    $s = $db->prepare("SELECT * FROM `tb_gestion_coaching_detalle_tipo` WHERE `gcdt_paquete` = ? LIMIT 1");
    $s->bind_param('s', $paquete);
    $s->execute();
    return $s->get_result()->fetch_assoc() ?: null;
}

function guardarDetalleTipo(mysqli $db, string $paquete, array $d, string $usuario): void
{
    $sql = "INSERT INTO `tb_gestion_coaching_detalle_tipo`
                (`gcdt_paquete`, `gcdt_fecha_ocurrencia`, `gcdt_descripcion_falta`, `gcdt_impacto`, `gcdt_evidencias`,
                 `gcdt_reincidente`, `gcdt_retroalimentaciones_previas`, `gcdt_tipo_reconocimiento`, `gcdt_periodo_reconocido`,
                 `gcdt_resultado_obtenido`, `gcdt_meta`, `gcdt_monitoreo_destacado`, `gcdt_fecha_monitoreo`,
                 `gcdt_fortalezas_reconocimiento`, `gcdt_descripcion_reconocimiento`, `gcdt_fecha_entrega`,
                 `gcdt_responsable_entrega`, `gcdt_actualizado_por`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                `gcdt_fecha_ocurrencia` = VALUES(`gcdt_fecha_ocurrencia`),
                `gcdt_descripcion_falta` = VALUES(`gcdt_descripcion_falta`),
                `gcdt_impacto` = VALUES(`gcdt_impacto`),
                `gcdt_evidencias` = VALUES(`gcdt_evidencias`),
                `gcdt_reincidente` = VALUES(`gcdt_reincidente`),
                `gcdt_retroalimentaciones_previas` = VALUES(`gcdt_retroalimentaciones_previas`),
                `gcdt_tipo_reconocimiento` = VALUES(`gcdt_tipo_reconocimiento`),
                `gcdt_periodo_reconocido` = VALUES(`gcdt_periodo_reconocido`),
                `gcdt_resultado_obtenido` = VALUES(`gcdt_resultado_obtenido`),
                `gcdt_meta` = VALUES(`gcdt_meta`),
                `gcdt_monitoreo_destacado` = VALUES(`gcdt_monitoreo_destacado`),
                `gcdt_fecha_monitoreo` = VALUES(`gcdt_fecha_monitoreo`),
                `gcdt_fortalezas_reconocimiento` = VALUES(`gcdt_fortalezas_reconocimiento`),
                `gcdt_descripcion_reconocimiento` = VALUES(`gcdt_descripcion_reconocimiento`),
                `gcdt_fecha_entrega` = VALUES(`gcdt_fecha_entrega`),
                `gcdt_responsable_entrega` = VALUES(`gcdt_responsable_entrega`),
                `gcdt_actualizado_por` = VALUES(`gcdt_actualizado_por`),
                `gcdt_actualizado_fecha` = NOW()";
    $s = $db->prepare($sql);
    $vals = [
        $paquete, $d['fecha_ocurrencia'] ?? null, $d['descripcion_falta'] ?? null, $d['impacto'] ?? null, $d['evidencias'] ?? null,
        $d['reincidente'] ?? null, $d['retroalimentaciones_previas'] ?? null, $d['tipo_reconocimiento'] ?? null, $d['periodo_reconocido'] ?? null,
        $d['resultado_obtenido'] ?? null, $d['meta'] ?? null, $d['monitoreo_destacado'] ?? null, $d['fecha_monitoreo'] ?? null,
        $d['fortalezas_reconocimiento'] ?? null, $d['descripcion_reconocimiento'] ?? null, $d['fecha_entrega'] ?? null,
        $d['responsable_entrega'] ?? null, $usuario,
    ];
    $s->bind_param(str_repeat('s', count($vals)), ...$vals);
    if (!$s->execute()) {
        throw new RuntimeException('No fue posible guardar el detalle especializado.');
    }
}

// ---------------------------------------------------------------------
// Soportes/adjuntos — CORREGIDO para usar las columnas REALES de
// tb_gestion_coaching_soporte (prefijo gcsp_*, ya desplegada desde la
// migración 004). La lógica de validación (hash SHA-256, MIME real vía
// finfo, nombre interno aleatorio) se mantiene igual — era correcta.
// ---------------------------------------------------------------------
const COACHING_SOPORTES_RUTA = __DIR__ . '/../storage/coaching_soportes';

function listarSoportesCoaching(mysqli $db, string $paquete): array
{
    $s = $db->prepare("SELECT * FROM `tb_gestion_coaching_soporte` WHERE `gcsp_paquete` = ? AND `gcsp_estado` = 'Activo' ORDER BY `gcsp_registro_fecha` DESC");
    $s->bind_param('s', $paquete);
    $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}

function guardarSoporteCoaching(mysqli $db, string $paquete, array $archivo, string $tipo, string $usuario): int
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No fue posible recibir el archivo.');
    }
    $max = 10 * 1024 * 1024;
    if ((int) $archivo['size'] > $max) {
        throw new RuntimeException('El archivo supera 10 MB.');
    }
    $permitidos = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    ];
    $ext = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
    if (!isset($permitidos[$ext])) {
        throw new RuntimeException('Tipo de archivo no permitido.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if ($mime !== $permitidos[$ext]) {
        throw new RuntimeException('El contenido del archivo no coincide con su extensión.');
    }
    if (!is_dir(COACHING_SOPORTES_RUTA) && !mkdir(COACHING_SOPORTES_RUTA, 0750, true) && !is_dir(COACHING_SOPORTES_RUTA)) {
        throw new RuntimeException('No fue posible preparar el almacenamiento.');
    }
    $interno = bin2hex(random_bytes(20)) . '.' . $ext;
    $ruta = COACHING_SOPORTES_RUTA . DIRECTORY_SEPARATOR . $interno;
    if (!move_uploaded_file($archivo['tmp_name'], $ruta)) {
        throw new RuntimeException('No fue posible almacenar el soporte.');
    }
    chmod($ruta, 0640);
    $hash = hash_file('sha256', $ruta);
    $nombre = mb_substr(basename((string) $archivo['name']), 0, 255);

    $s = $db->prepare(
        "INSERT INTO `tb_gestion_coaching_soporte`
            (`gcsp_paquete`, `gcsp_nombre_original`, `gcsp_nombre_interno`, `gcsp_ruta`, `gcsp_extension`,
             `gcsp_mime`, `gcsp_tamano_bytes`, `gcsp_hash_sha256`, `gcsp_tipo_documental`, `gcsp_usuario`)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $size = (int) $archivo['size'];
    $s->bind_param('ssssssisss', $paquete, $nombre, $interno, $ruta, $ext, $mime, $size, $hash, $tipo, $usuario);
    if (!$s->execute()) {
        @unlink($ruta);
        throw new RuntimeException('No fue posible registrar el soporte.');
    }
    return $db->insert_id;
}

/** Máximo de archivos permitidos en una sola carga de soportes. */
const COACHING_SOPORTES_MAX_POR_CARGA = 10;

/**
 * Reorganiza la estructura multi-archivo que PHP entrega para
 * `<input type="file" name="soporte[]" multiple>` — por defecto PHP
 * agrupa TODOS los "name"/"tmp_name"/"error"/etc. de todos los archivos
 * en un solo array paralelo (formato "raro" heredado de PHP, no algo
 * intuitivo), en vez de un array de archivos individuales. Esta función
 * lo convierte en una lista de arrays "normales", uno por archivo, con
 * el mismo formato que ya espera guardarSoporteCoaching().
 */
function reorganizarArchivosMultiples(array $files_soporte): array
{
    if (!isset($files_soporte['name']) || !is_array($files_soporte['name'])) {
        return [];
    }
    $reorganizados = [];
    $total = count($files_soporte['name']);
    for ($i = 0; $i < $total; $i++) {
        // Slots vacíos (el usuario abrió el selector pero no eligió nada
        // en esa posición) se ignoran, no cuentan como error.
        if (($files_soporte['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && ($files_soporte['name'][$i] ?? '') === '') {
            continue;
        }
        $reorganizados[] = [
            'name'     => $files_soporte['name'][$i] ?? '',
            'type'     => $files_soporte['type'][$i] ?? '',
            'tmp_name' => $files_soporte['tmp_name'][$i] ?? '',
            'error'    => $files_soporte['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files_soporte['size'][$i] ?? 0,
        ];
    }
    return $reorganizados;
}

/**
 * Sube VARIOS soportes en una sola operación — a diferencia de
 * guardarSoporteCoaching() (un archivo, lanza excepción y aborta todo
 * ante cualquier error), esta función intenta cada archivo por separado:
 * un archivo inválido (tipo no permitido, muy pesado, etc.) NO bloquea
 * la carga de los demás que sí sean válidos.
 *
 * @return array{exitosos: int, fallidos: array<array{nombre: string, motivo: string}>}
 */
function guardarSoportesMultiples(mysqli $db, string $paquete, array $files_soporte, string $tipo, string $usuario): array
{
    $archivos = reorganizarArchivosMultiples($files_soporte);

    if (count($archivos) === 0) {
        throw new RuntimeException('Debe seleccionar al menos un archivo.');
    }
    if (count($archivos) > COACHING_SOPORTES_MAX_POR_CARGA) {
        throw new RuntimeException('Máximo ' . COACHING_SOPORTES_MAX_POR_CARGA . ' archivos por carga. Seleccionó ' . count($archivos) . '.');
    }

    $exitosos = 0;
    $fallidos = [];

    foreach ($archivos as $archivo) {
        try {
            guardarSoporteCoaching($db, $paquete, $archivo, $tipo, $usuario);
            $exitosos++;
        } catch (Throwable $e) {
            $fallidos[] = [
                'nombre' => (string) ($archivo['name'] ?? 'archivo sin nombre'),
                'motivo' => $e->getMessage(),
            ];
        }
    }

    return ['exitosos' => $exitosos, 'fallidos' => $fallidos];
}

function obtenerSoporteCoaching(mysqli $db, int $id): ?array
{
    $s = $db->prepare("SELECT * FROM `tb_gestion_coaching_soporte` WHERE `gcsp_id` = ? AND `gcsp_estado` = 'Activo' LIMIT 1");
    $s->bind_param('i', $id);
    $s->execute();
    return $s->get_result()->fetch_assoc() ?: null;
}

/** Marca inactivo sin borrar el archivo físico (requisito de auditoría). */
function inactivarSoporteCoaching(mysqli $db, int $id): void
{
    $s = $db->prepare("UPDATE `tb_gestion_coaching_soporte` SET `gcsp_estado` = 'Inactivo' WHERE `gcsp_id` = ?");
    $s->bind_param('i', $id);
    if (!$s->execute()) {
        throw new RuntimeException('No fue posible inactivar el soporte.');
    }
}

/**
 * Estados del paquete en los que aún se permite eliminar soportes: mientras
 * el supervisor está construyendo la retroalimentación (ASIGNADO,
 * PENDIENTE_SUPERVISOR) o mientras el paquete ya está en manos del agente
 * pero este todavía no ha respondido (PENDIENTE_AGENTE). Una vez el agente
 * responde o el paquete avanza más allá de estos tres estados, los soportes
 * quedan fijos por trazabilidad de auditoría.
 */
const COACHING_ESTADOS_PERMITEN_ELIMINAR_SOPORTE = ['ASIGNADO', 'PENDIENTE_SUPERVISOR', 'PENDIENTE_AGENTE'];

/**
 * Elimina (inactiva) un soporte, validando en el SERVIDOR — no solo
 * ocultando el botón en el frontend — que:
 *  1) El paquete dueño del soporte esté en un estado que admite borrado.
 *  2) El paquete esté activo (no anulado).
 *  3) El usuario actuante tenga relación real con el recurso: sea
 *     Administrador/Gestor, o sea el supervisor/agente asignado a ESE
 *     paquete concreto (defensa contra IDOR, mismo patrón que
 *     usuarioPuedeVerPaquete() y coaching_transiciones.php).
 *
 * No borra el archivo físico (requisito de auditoría) — reutiliza
 * inactivarSoporteCoaching() para eso.
 */
function eliminarSoporteCoaching(mysqli $db, int $soporte_id, string $usu_id_actor, string $perfil_actor): void
{
    $s = $db->prepare(
        "SELECT SP.`gcsp_id`, SP.`gcsp_paquete`, P.`gcp_agente_id`, P.`gcp_supervisor_id`, P.`gcp_activo`, E.`gce_codigo`
         FROM `tb_gestion_coaching_soporte` AS SP
         INNER JOIN `tb_gestion_coaching_paquete` AS P ON SP.`gcsp_paquete` = P.`gcp_id`
         INNER JOIN `tb_gestion_coaching_estado` AS E ON P.`gcp_estado_id` = E.`gce_id`
         WHERE SP.`gcsp_id` = ? AND SP.`gcsp_estado` = 'Activo' LIMIT 1"
    );
    $s->bind_param('i', $soporte_id);
    $s->execute();
    $fila = $s->get_result()->fetch_assoc();

    if (!$fila) {
        throw new RuntimeException('El soporte indicado no existe o ya fue eliminado.');
    }
    if ((int) $fila['gcp_activo'] !== 1) {
        throw new RuntimeException('El paquete está anulado; no se pueden modificar sus soportes.');
    }
    if (!in_array($fila['gce_codigo'], COACHING_ESTADOS_PERMITEN_ELIMINAR_SOPORTE, true)) {
        throw new RuntimeException('Los soportes solo pueden eliminarse mientras el paquete está en retroalimentación o pendiente de respuesta del agente.');
    }

    // Autorización por RECURSO real, sin depender del string de perfil
    // (salvo el bypass amplio e intencional de Administrador — 'Gestor'
    // representa al Líder de Calidad en este portal y no tiene bypass
    // aquí; igual puede eliminar su propio soporte vía la coincidencia
    // con gcp_agente_id más abajo) — ver nota extendida en
    // gestion_coaching_ver.php sobre por qué no se puede confiar en la
    // etiqueta exacta del perfil.
    $autorizado = $perfil_actor === 'Administrador'
        || $usu_id_actor === $fila['gcp_supervisor_id']
        || $usu_id_actor === $fila['gcp_agente_id'];

    if (!$autorizado) {
        throw new RuntimeException('No tiene autorización para eliminar este soporte.');
    }

    inactivarSoporteCoaching($db, $soporte_id);
}

function descargarSoporteCoaching(array $s): void
{
    if (!is_readable($s['gcsp_ruta']) || hash_file('sha256', $s['gcsp_ruta']) !== $s['gcsp_hash_sha256']) {
        http_response_code(409);
        exit('Soporte no disponible o con integridad comprometida.');
    }
    header('Content-Type: ' . $s['gcsp_mime']);
    header('Content-Disposition: attachment; filename="' . rawurlencode($s['gcsp_nombre_original']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($s['gcsp_ruta']));
    readfile($s['gcsp_ruta']);
    exit;
}
