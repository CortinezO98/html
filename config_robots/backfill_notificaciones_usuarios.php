<?php
/**
 * Backfill (re-encolado) de notificaciones (Activación / Restablecer).
 *
 * Uso:
 *  sudo -u apache /usr/bin/php /var/www/icbf/html/config_robots/backfill_notificaciones_usuarios.php --dry-run
 *  sudo -u apache /usr/bin/php /var/www/icbf/html/config_robots/backfill_notificaciones_usuarios.php --limit=50
 *  sudo -u apache APP_URL="https://modulocalidad.grupoasd.com/" /usr/bin/php /var/www/icbf/html/config_robots/backfill_notificaciones_usuarios.php
 *
 * Modos:
 *  --mode=activation   (default) -> usu_inicio_sesion = 0 (Primer ingreso / Activación)
 *  --mode=reset                   -> usu_inicio_sesion <> 0 (Restablecer)
 *  --mode=auto                    -> ambos, decide por fila
 *
 * Opciones:
 *  --dry-run
 *  --limit=N
 *  --set-from=ID
 *  --subject="..."                -> fuerza un único asunto para TODO (si no lo pones, usa asunto por tipo)
 *  --subject-activation="..."
 *  --subject-reset="..."
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Este script es solo CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../config/conexion_db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$enlace_db->set_charset('utf8mb4');

function argValue(array $argv, string $prefix, $default = null) {
    foreach ($argv as $a) {
        if (strpos($a, $prefix) === 0) {
            return substr($a, strlen($prefix));
        }
    }
    return $default;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$argvList = $argv ?? [];
$dryRun   = in_array('--dry-run', $argvList, true);
$limitRaw = argValue($argvList, '--limit=', '0');
$setFrom  = (int)argValue($argvList, '--set-from=', '1');

$mode = strtolower((string)argValue($argvList, '--mode=', 'activation'));
if (!in_array($mode, ['activation','reset','auto'], true)) $mode = 'activation';

$subjectOverride = (string)argValue($argvList, '--subject=', '');
$subjectActivation = (string)argValue(
    $argvList,
    '--subject-activation=',
    'Activación de Cuenta (Primer ingreso) - IQ-ICBF | Gestión Integrada de Servicios'
);
$subjectReset = (string)argValue(
    $argvList,
    '--subject-reset=',
    'Restablecer Contraseña - IQ-ICBF | Gestión Integrada de Servicios'
);

$limit = (int)$limitRaw;
if ($limit < 0) $limit = 0;

/**
 * URL base (evitar IP hardcodeada).
 * Prioridad: APP_URL -> BASE_URL -> default producción
 */
$baseUrl = getenv('APP_URL') ?: (getenv('BASE_URL') ?: 'https://modulocalidad.grupoasd.com/');
$baseUrl = trim((string)$baseUrl);
if ($baseUrl === '') $baseUrl = 'https://modulocalidad.grupoasd.com/';
if (!preg_match('~^https?://~i', $baseUrl)) $baseUrl = 'https://' . $baseUrl;
$baseUrl = rtrim($baseUrl, '/') . '/';
$displayUrl = preg_replace('~^https?://~i', '', rtrim($baseUrl, '/'));


// ====== Imágenes embebidas (legacy) ======
$imagesDir = realpath(__DIR__ . '/../images');
if ($imagesDir === false) $imagesDir = '/var/www/icbf/html/images';

$imgFirma = $imagesDir . '/firma-verde.png';
$imgLogo1 = $imagesDir . '/logo.png';
$imgLogo2 = $imagesDir . '/logo_notificacion_correo.png';

$allImagesExist = (is_file($imgFirma) && is_file($imgLogo1) && is_file($imgLogo2));

$nc_embeddedimage_ruta   = $allImagesExist ? ($imgFirma . ';' . $imgLogo1 . ';' . $imgLogo2) : '';
$nc_embeddedimage_nombre = $allImagesExist ? 'firma-verde;logo;logo_notificacion_correo' : '';
$nc_embeddedimage_tipo   = $allImagesExist ? 'image/png;image/png;image/png' : '';

/**
 * ✅ CORREGIDO: ahora recibe $allImagesExist como parámetro (evita warnings por scope).
 */
function buildBody(
    string $tipo,
    string $nombre,
    string $token,
    string $baseUrl,
    string $displayUrl,
    string $confirmUrl,
    string $usuAcceso,
    bool $allImagesExist
): string {
    $nombreSafe = h($nombre);
    $usuarioSafe = h($usuAcceso);

    $esActivacion = ($tipo === 'activation');

    $titulo = $esActivacion ? 'Activación de Cuenta' : 'Restablecer Contraseña';
    $mensaje = $esActivacion
        ? "¡Tu cuenta ha sido habilitada! Para completar el proceso, crea tu contraseña usando el código a continuación."
        : "¡Hemos recibido una solicitud para restablecer la contraseña de tu cuenta! Si no fue tuya, ignora este correo electrónico.";

    $instruccion = $esActivacion
        ? "Ingresa el código anterior para continuar con el proceso de activación y creación de contraseña."
        : "Ingresa el código anterior para continuar con el proceso de restauración de contraseña.";

    $lineUsuario = ($usuarioSafe !== '')
        ? "<p style=\"font-size:12px;padding:0 5px;color:#666666;margin:8px 0 0 0;\"><b>Usuario:</b> {$usuarioSafe}</p>"
        : "";

    $headerImgs = $allImagesExist ? "
        <table style=\"width:100%; max-width:600px; font-size:13px; font-family:Lato, Arial, sans-serif;\">
            <tr>
                <td style=\"padding:5px;\">
                    <img src=\"cid:logo\" style=\"width:160px;\" alt=\"logo\">
                </td>
                <td style=\"padding:5px; text-align:right;\">
                    <img src=\"cid:logo_notificacion_correo\" style=\"width:160px;\" alt=\"logo_notificacion\">
                </td>
            </tr>
        </table>
    " : "";

    $firmaBlock = $allImagesExist ? "
        <center>
            <table style=\"max-width:600px; font-family:Lato, Arial, sans-serif;\">
                <tr>
                    <td style=\"width:45px;\">
                        <img src=\"cid:firma-verde\" style=\"margin:5px; width:45px;\" alt=\"firma\">
                    </td>
                    <td>
                        <p style=\"font-size:11px; color:#196F3D; font-family:Lato, Arial, sans-serif;\">
                            <br>No imprima este mensaje de no ser necesario;<br>de esta manera aportamos al cuidado del planeta.
                        </p>
                    </td>
                </tr>
            </table>
        </center>
    " : "";

    $confirmUrlSafe = h($confirmUrl);
    $displayUrlSafe = h($displayUrl);

    return "
        <center>
            {$headerImgs}

            <table style=\"width:100%; max-width:600px; font-family:Lato, Arial, sans-serif;\">
                <tr>
                    <td style=\"padding:5px;\">
                        <p style=\"font-size:13px; padding:0 5px; color:#666666;\"><b>{$titulo}</b></p>
                        <br>

                        <p style=\"font-size:12px; padding:0 5px; color:#666666;\">
                            Hola <b>{$nombreSafe}</b>,<br><br>
                            {$mensaje}
                        </p>

                        {$lineUsuario}

                        <br>
                        <center>
                            <p style=\"font-size:12px; padding:0 5px; color:#666666;\">
                                <b>Código: {$token}</b>
                            </p>
                        </center>
                        <br>

                        <p style=\"font-size:12px; padding:0 5px; color:#666666;\">
                            {$instruccion}
                        </p>

                        <center>
                            <a href=\"{$confirmUrlSafe}\" target=\"_blank\"
                               style=\"border-radius:4px; color:#ffffff; font-size:12px; padding:8px 10px;
                                      text-align:center; text-decoration:none!important; width:50%; display:block;
                                      background-color:#72BF44\">
                                Ir a {$displayUrlSafe}
                            </a>
                        </center>

                        <br>
                    </td>
                </tr>
            </table>

            <table style=\"width:100%; max-width:600px; background:#666666; font-family:Lato, Arial, sans-serif;\">
                <tr>
                    <td style=\"font-size:12px; padding:8px 10px; color:#FFFFFF\">
                        <center>IQ-ICBF | Gestión Integrada de Servicios | &copy; Copyright 2020 Todos los derechos reservados.</center>
                    </td>
                </tr>
            </table>

            <table style=\"width:100%; max-width:600px; font-family:Lato, Arial, sans-serif;\">
                <tr>
                    <td style=\"padding:5px;\">
                        <center>
                            <p style=\"font-size:12px; font-family:Lato, Arial, sans-serif; color:#666666;\">
                                Te recordamos que este correo electrónico es utilizado solamente para el envío de notificaciones automáticas.
                                <br>Por favor no respondas con consultas personales ya que no podrán ser respondidas.
                            </p>
                        </center>
                    </td>
                </tr>
            </table>

            {$firmaBlock}

            <table style=\"width:100%; max-width:600px; font-family:Lato, Arial, sans-serif;\">
                <tr>
                    <td style=\"padding:5px;\">
                        <center>
                            <i>
                                <p style=\"font-size:11px; font-family:Lato, Arial, sans-serif; color:#666666;\">
                                    AVISO LEGAL (o AVISO DE CONFIDENCIALIDAD): El contenido de este mensaje y sus anexos pueden contener información confidencial o legalmente protegida...
                                    Si por error, recibe este mensaje, deberá dar aviso inmediatamente a su remitente y destruir toda copia que tenga del mismo.
                                </p>
                            </i>
                        </center>
                    </td>
                </tr>
            </table>
        </center>
    ";
}


// ====== 1) Seleccionar usuarios objetivo ======
$sqlUsers = "
    SELECT usu_id, usu_acceso, usu_nombres_apellidos, usu_correo_corporativo, usu_inicio_sesion
    FROM tb_administrador_usuario
    WHERE usu_estado = 'Activo'
      AND usu_correo_corporativo <> ''
";

if ($mode === 'activation') {
    $sqlUsers .= " AND usu_inicio_sesion = 0 ";
} elseif ($mode === 'reset') {
    $sqlUsers .= " AND usu_inicio_sesion <> 0 ";
}

if ($limit > 0) $sqlUsers .= " LIMIT " . (int)$limit;

$usersStmt = $enlace_db->prepare($sqlUsers);
$usersStmt->execute();
$users = $usersStmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "Base URL: {$baseUrl}\n";
echo "Display : {$displayUrl}\n";
echo "SetFrom ID: {$setFrom}\n";
echo "Mode: {$mode}\n";
echo "Usuarios candidatos: " . count($users) . "\n";
echo "Embebidos: " . ($allImagesExist ? "OK\n\n" : "NO (se encola sin imágenes)\n\n");

$enqueued = 0;
$skipped  = 0;
$errors   = 0;

// Validar remitente existe
$stCheckRem = $enlace_db->prepare("
    SELECT COUNT(*) c
    FROM tb_notificaciones_central_remitente
    WHERE ncr_id = ?
");
$stCheckRem->bind_param("i", $setFrom);
$stCheckRem->execute();
$remOk = (int)$stCheckRem->get_result()->fetch_assoc()['c'] > 0;
if (!$remOk) {
    fwrite(STDERR, "ERROR: No existe remitente ncr_id={$setFrom} en tb_notificaciones_central_remitente.\n");
    exit(2);
}

// Reutilizables
$stExistsNotif = $enlace_db->prepare("
    SELECT COUNT(*) c
    FROM tb_notificaciones_central
    WHERE nc_usuario_registro = ?
      AND nc_subject = ?
");

$stInactivateTokens = $enlace_db->prepare("
    UPDATE tb_administrador_token
    SET tk_estado = 'Inactivo'
    WHERE tk_usuario = ?
      AND tk_estado = 'Activo'
");

$stInsertToken = $enlace_db->prepare("
    INSERT INTO tb_administrador_token (tk_usuario, tk_token, tk_estado)
    VALUES (?, ?, 'Activo')
");

$stInsertNotif = $enlace_db->prepare("
    INSERT INTO tb_notificaciones_central
    (nc_id_modulo, nc_prioridad, nc_id_set_from, nc_address, nc_cc, nc_bcc, nc_reply_to,
     nc_subject, nc_body, nc_embeddedimage_ruta, nc_embeddedimage_nombre, nc_embeddedimage_tipo,
     nc_intentos, nc_eliminar, nc_estado_envio, nc_fecha_envio, nc_usuario_registro)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

foreach ($users as $u) {
    $usuId   = (string)($u['usu_id'] ?? '');
    $usuAcc  = (string)($u['usu_acceso'] ?? '');
    $nombre  = (string)($u['usu_nombres_apellidos'] ?? '');
    $correo  = trim((string)($u['usu_correo_corporativo'] ?? ''));
    $inicio  = (int)($u['usu_inicio_sesion'] ?? 0);

    if ($usuId === '' || $correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $skipped++;
        continue;
    }

    $tipo = ($inicio === 0) ? 'activation' : 'reset';

    $subjectFinal = $subjectOverride !== ''
        ? $subjectOverride
        : (($tipo === 'activation') ? $subjectActivation : $subjectReset);

    $confirmUrl = $baseUrl . 'recuperar_contrasena_confirmar.php'
        . '?1=' . rawurlencode(base64_encode($usuId))
        . '&2=' . rawurlencode(base64_encode($correo))
        . (($tipo === 'activation') ? '&t=act' : '');

    try {
        $stExistsNotif->bind_param("ss", $usuId, $subjectFinal);
        $stExistsNotif->execute();
        $exists = (int)$stExistsNotif->get_result()->fetch_assoc()['c'];

        if ($exists > 0) {
            echo "[SKIP] {$usuId} | {$correo} | ya tiene notificación con subject: {$subjectFinal}\n";
            $skipped++;
            continue;
        }

        $token = (string)random_int(100000, 999999);

        $nc_id_modulo = 1;
        $nc_prioridad = 'Alta';
        $nc_id_set_from = $setFrom;
        $nc_address = "{$correo}|{$correo}";
        $nc_cc = '';
        $nc_bcc = '';
        $nc_reply_to = '';
        $nc_subject = $subjectFinal;

        // ✅ CORREGIDO: se pasa $allImagesExist
        $nc_body = buildBody($tipo, $nombre, $token, $baseUrl, $displayUrl, $confirmUrl, $usuAcc, $allImagesExist);

        $nc_intentos = '1';
        $nc_eliminar = 'Si';
        $nc_estado_envio = 'Pendiente';
        $nc_fecha_envio = '';
        $nc_usuario_registro = $usuId;

        if ($dryRun) {
            echo "[DRY] {$tipo} | {$usuId} | {$correo} | token={$token} | subject={$nc_subject}\n";
            $enqueued++;
            continue;
        }

        $enlace_db->begin_transaction();

        $stInactivateTokens->bind_param("s", $usuId);
        $stInactivateTokens->execute();

        $stInsertToken->bind_param("ss", $usuId, $token);
        $stInsertToken->execute();

        $types = "isis" . str_repeat("s", 13);
        $stInsertNotif->bind_param(
            $types,
            $nc_id_modulo,
            $nc_prioridad,
            $nc_id_set_from,
            $nc_address,
            $nc_cc,
            $nc_bcc,
            $nc_reply_to,
            $nc_subject,
            $nc_body,
            $nc_embeddedimage_ruta,
            $nc_embeddedimage_nombre,
            $nc_embeddedimage_tipo,
            $nc_intentos,
            $nc_eliminar,
            $nc_estado_envio,
            $nc_fecha_envio,
            $nc_usuario_registro
        );
        $stInsertNotif->execute();

        $enlace_db->commit();

        echo "[OK ] {$tipo} | {$usuId} | {$correo} | token={$token} | ENCOLADO\n";
        $enqueued++;

    } catch (Throwable $e) {
        try { $enlace_db->rollback(); } catch (Throwable $__) {}
        $errors++;
        echo "[ERR] {$tipo} | {$usuId} | {$correo} | " . $e->getMessage() . "\n";
    }
}

echo "\n==== RESUMEN ====\n";
echo "Encolados: {$enqueued}\n";
echo "Saltados : {$skipped}\n";
echo "Errores  : {$errors}\n";
echo "Modo     : " . ($dryRun ? "DRY-RUN\n" : "COMMIT\n");
