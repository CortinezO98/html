<?php
require_once("config/validaciones_seguridad_raiz.php");
require_once("config/conexion_db.php");

// Fuerza errores de MySQLi a excepciones (para que el catch capture el motivo real)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$enlace_db->set_charset('utf8mb4');

// ==== LOG SEGURO (no muestra al usuario) ====
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/var/log/httpd/modulocalidad_php_errors.log');
error_log("HIT recuperar_contrasena_confirmar.php " . date('c') . " URI=" . ($_SERVER['REQUEST_URI'] ?? ''));

error_reporting(E_ALL);

function safe_prepare(mysqli $db, string $sql): ?mysqli_stmt {
    $st = $db->prepare($sql);
    if (!$st) {
        error_log("PREPARE FAIL: " . $db->error . " | SQL=" . $sql);
        return null;
    }
    return $st;
}

function b64_get(string $key): string {
    $raw = $_GET[$key] ?? '';
    if (!is_string($raw) || $raw === '') return '';
    $dec = base64_decode($raw, true); // strict
    if ($dec === false) return '';
    return $dec;
}

// Si sesión está iniciada y ya es usuario activo -> al contenido
if (isset($_SESSION["usu_id"]) && (int)($_SESSION['usu_inicio_sesion'] ?? 0) !== 0) {
    header("Location:contenido.php");
    exit;
}

// BASE URL (evita IP hardcodeada)
$baseUrl = getenv('APP_URL') ?: (getenv('BASE_URL') ?: 'https://modulocalidad.grupoasd.com/');
$baseUrl = trim((string)$baseUrl);
if ($baseUrl === '') $baseUrl = 'https://modulocalidad.grupoasd.com/';
if (!preg_match('~^https?://~i', $baseUrl)) $baseUrl = 'https://' . $baseUrl;
$baseUrl = rtrim($baseUrl, '/') . '/';
$displayUrl = preg_replace('~^https?://~i', '', rtrim($baseUrl, '/'));

// ---- GET params: soporta u/e (nuevo) o 1/2 (legacy) ----
$id_usuario_get     = b64_get('u');
$correo_usuario_get = b64_get('e');

if ($id_usuario_get === '' && $correo_usuario_get === '') {
    $id_usuario_get     = b64_get('1');
    $correo_usuario_get = b64_get('2');
}

$id_usuario     = validar_input($id_usuario_get);
$correo_usuario = validar_input($correo_usuario_get);

// Identifica si es activación (por flag o por BD)
$isActivationParam = (($_GET['t'] ?? '') === 'act');
$isActivationDb = false;

if (!$isActivationParam && $id_usuario !== '') {
    $stUser = safe_prepare($enlace_db, "SELECT usu_inicio_sesion FROM tb_administrador_usuario WHERE usu_id = ? LIMIT 1");
    if ($stUser) {
        $stUser->bind_param("s", $id_usuario);
        if ($stUser->execute()) {
            $row = $stUser->get_result()->fetch_assoc();
            if ($row) $isActivationDb = ((int)$row['usu_inicio_sesion'] === 0);
        }
        $stUser->close();
    }
}

$isActivation = ($isActivationParam || $isActivationDb);

// Mensajes/asuntos según flujo
$subjectOk = $isActivation
    ? "Activación Exitosa de Cuenta - IQ-ICBF | Gestión Integrada de Servicios"
    : "Confirmación Exitosa Cambio de Contraseña - IQ-ICBF | Gestión Integrada de Servicios";

$tituloMail = $isActivation ? "Activación Exitosa de Cuenta" : "Confirmación Exitosa Cambio de Contraseña";
$textoMail  = $isActivation
    ? "¡Tu cuenta ha sido activada exitosamente! Ya puedes ingresar a la plataforma con tu usuario y la contraseña que acabas de crear."
    : "¡Se ha realizado cambio exitoso de la contraseña de tu cuenta!";

$respuesta_accion = "";

// Si faltan params críticos, muestra error controlado
if ($id_usuario === '' || $correo_usuario === '' || !filter_var($correo_usuario, FILTER_VALIDATE_EMAIL)) {
    $respuesta_accion = "<p class='alert alert-danger p-1 font-size-13'>Enlace inválido o incompleto. Solicita nuevamente el proceso.</p>";
} else {

    if (isset($_POST["form_recovery_confirm"])) {

        $contrasena_1 = validar_input($_POST['contrasena_1'] ?? '');
        $contrasena_2 = validar_input($_POST['contrasena_2'] ?? '');
        $codigo_token = validar_input($_POST['codigo_token'] ?? '');

        if (!isset($_SESSION["password_recovery"])) {

            // Token válido, Activo y no expirado (24h)
            $sqlTok = "
                SELECT tk_usuario, tk_token
                FROM tb_administrador_token
                WHERE tk_usuario = ?
                  AND tk_token = ?
                  AND tk_estado = 'Activo'
                  AND tk_registro_fecha >= (NOW() - INTERVAL 24 HOUR)
                LIMIT 1
            ";

            $stTok = safe_prepare($enlace_db, $sqlTok);
            if (!$stTok) {
                $respuesta_accion = "<p class='alert alert-danger p-1 font-size-13'>Error interno validando el código. Intenta nuevamente.</p>";
            } else {
                $stTok->bind_param("ss", $id_usuario, $codigo_token);
                $stTok->execute();
                $rowTok = $stTok->get_result()->fetch_assoc();
                $stTok->close();

                if ($rowTok) {

                    if ($contrasena_1 === $contrasena_2) {

                        // Hash BCrypt (mantiene tu lógica)
                        $salt = substr(base64_encode(openssl_random_pseudo_bytes(30)), 0, 22);
                        $salt = strtr($salt, array('+' => '.'));
                        $contrasena_update = crypt($contrasena_1, '$2y$10$' . $salt);

                        $enlace_db->begin_transaction();

                        try {
                            // 1) Update usuario
                            if ($isActivation) {
                                $inicio_sesion = 1;
                                $stUpd = safe_prepare($enlace_db, "
                                    UPDATE tb_administrador_usuario
                                    SET usu_contrasena = ?, usu_inicio_sesion = ?
                                    WHERE usu_id = ?
                                ");
                                if (!$stUpd) throw new Exception("No se pudo preparar update usuario (activation)");
                                $stUpd->bind_param('sis', $contrasena_update, $inicio_sesion, $id_usuario);
                            } else {
                                $stUpd = safe_prepare($enlace_db, "
                                    UPDATE tb_administrador_usuario
                                    SET usu_contrasena = ?
                                    WHERE usu_id = ?
                                ");
                                if (!$stUpd) throw new Exception("No se pudo preparar update usuario (reset)");
                                $stUpd->bind_param('ss', $contrasena_update, $id_usuario);
                            }
                            $stUpd->execute();
                            $stUpd->close();

                            // 2) Encolar correo confirmación
                            $contenido_correo = "<center>
                                <table style='width:100%; max-width: 600px; font-size: 13px; font-family: Lato, Arial, sans-serif;'>
                                    <tr>
                                        <td style='padding: 5px;'><img src='cid:logo' style='width: 160px;'></td>
                                        <td style='padding: 5px; text-align: right;'><img src='cid:logo_notificacion_correo'></td>
                                    </tr>
                                </table>

                                <table style='width:100%; max-width: 600px; font-family: Lato, Arial, sans-serif;'>
                                    <tr>
                                        <td style='padding: 5px;'>
                                            <p style='font-size: 13px;padding: 0 5px;color: #666;'><b>{$tituloMail}</b></p>
                                            <br>
                                            <p style='font-size: 12px;padding: 0 5px; color: #666;'>Cordial saludo,<br><br>{$textoMail}</p>
                                            <br><br>
                                            <center>
                                                <a href='{$baseUrl}' target='_blank'
                                                   style='border-radius:4px; color:#fff; font-size:12px; padding: 8px 10px;
                                                          text-align:center; text-decoration:none!important; width:50%;
                                                          display:block; background-color:#72BF44'>
                                                    Ir a {$displayUrl}
                                                </a>
                                            </center>
                                            <br>
                                        </td>
                                    </tr>
                                </table>

                                <table style='width:100%; max-width:600px; background:#666; font-family:Lato, Arial, sans-serif;'>
                                    <tr>
                                        <td style='font-size:12px; padding:8px 10px; color:#fff'>
                                            <center>IQ-ICBF | Gestión Integrada de Servicios | &copy; Copyright 2020</center>
                                        </td>
                                    </tr>
                                </table>
                            </center>";

                            $nc_id_set_from = "1";
                            $nc_address = $correo_usuario . '|' . $correo_usuario;
                            $nc_cc = "";
                            $nc_bcc = "";
                            $nc_subject = $subjectOk;
                            $nc_body = str_replace("'", '"', $contenido_correo);

                            $nc_embeddedimage_ruta = "/var/www/icbf/html/images/firma-verde.png;/var/www/icbf/html/images/logo.png;/var/www/icbf/html/images/logo_notificacion_correo.png";
                            $nc_embeddedimage_nombre = "firma-verde;logo;logo_notificacion_correo";
                            $nc_embeddedimage_tipo = "image/png;image/png;image/png";

                            $nc_intentos = "1";
                            $nc_eliminar = "Si";
                            $nc_estado_envio = "Pendiente";
                            $nc_fecha_envio = null; // NULL (más seguro)
                            $nc_usuario_registro = $id_usuario;

                            $sql_notif = "INSERT INTO tb_notificaciones_central
                                (nc_id_modulo, nc_prioridad, nc_id_set_from, nc_address, nc_cc, nc_bcc, nc_reply_to,
                                 nc_subject, nc_body, nc_embeddedimage_ruta, nc_embeddedimage_nombre, nc_embeddedimage_tipo,
                                 nc_intentos, nc_eliminar, nc_estado_envio, nc_fecha_envio, nc_usuario_registro)
                                VALUES
                                ('1','Alta', ?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                            $stNotif = safe_prepare($enlace_db, $sql_notif);
                            if (!$stNotif) throw new Exception("No se pudo preparar insert notificación");

                            // OJO: son 14 parámetros => 14 "s"
                            $okBind = $stNotif->bind_param(
                                "ssssssssssssss",
                                $nc_id_set_from,
                                $nc_address,
                                $nc_cc,
                                $nc_bcc,
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
                            if (!$okBind) throw new Exception("bind_param notificación falló: " . $stNotif->error);

                            $stNotif->execute();
                            $stNotif->close();

                            // 3) Marcar token como usado (solo si estaba Activo)
                            $stTokUpd = safe_prepare($enlace_db, "
                                UPDATE tb_administrador_token
                                SET tk_estado = 'Usado'
                                WHERE tk_usuario = ?
                                  AND tk_token = ?
                                  AND tk_estado = 'Activo'
                            ");
                            if (!$stTokUpd) throw new Exception("No se pudo preparar update token");
                            $stTokUpd->bind_param('ss', $id_usuario, $codigo_token);
                            $stTokUpd->execute();
                            $stTokUpd->close();

                            $enlace_db->commit();

                            // Log app + flag sesión
                            registro_log($enlace_db, 'Login', 'notificacion', $nc_subject, 'NULL', $id_usuario);
                            $_SESSION["password_recovery"] = 'Ok';
                            $respuesta_accion = "<p class='alert alert-success p-1 font-size-13'>¡Proceso completado exitosamente!</p>";

                        } catch (Throwable $e) {
                            $enlace_db->rollback();
                            error_log("TX FAIL: " . $e->getMessage());
                            $respuesta_accion = "<p class='alert alert-danger p-1 font-size-13'>Error interno finalizando el proceso. Intenta nuevamente.</p>";
                        }

                    } else {
                        $respuesta_accion = "<p class='alert alert-danger p-1 font-size-13'>¡Las contraseñas no coinciden, por favor verifique e intente nuevamente!</p>";
                    }

                } else {
                    $respuesta_accion = "<p class='alert alert-danger p-1 font-size-13'>¡Código no válido o expirado, por favor solicite el proceso nuevamente!</p>";
                }
            }

        } else {
            $respuesta_accion = "<p class='alert alert-success p-1 font-size-13'>¡Proceso completado exitosamente!</p>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta http-equiv="x-ua-compatible" content="ie-edge">
    <link rel="stylesheet" type="text/css" href="css/bootstrap.css?v=2">
    <link rel="stylesheet" type="text/css" href="css/login.css?v=2">
    <link rel="stylesheet" type="text/css" href="fonts/css/all.css?v=2">
    <link rel="shortcut icon" type="image/icon" href="images/favicon.ico?v=2"/>
    <title>IQ-ICBF | Gestión Integrada de Servicios</title>
</head>
<body>
<div class="container-fluid">
    <div class="login-form">
        <div class="form-header">
            <img src="images/header_dian.png" class="img-fluid" style="width: 80%;">
        </div>

        <form id="login-form" method="post" class="form-signin fluid" role="form" action="">
            <div class="row">
                <?php if(!isset($_SESSION["password_recovery"])): ?>
                    <div class="col-md-12">
                        <h4 class="form-titulo"><?php echo $isActivation ? "Activar cuenta" : "Cambiar contraseña"; ?></h4>
                        <h6 class="font-weight-normal">Por favor ingresa una nueva contraseña y el código enviado al correo electrónico:</h6>
                        <?php if (!empty($respuesta_accion)) { echo $respuesta_accion; } ?>
                    </div>

                    <div class="col-md-12">
                        <input name="contrasena_1" id="contrasena_1" type="password" class="form-control"
                               placeholder="Contraseña nueva" maxlength="50" autofocus autocomplete="off" required>
                    </div>

                    <div class="col-md-12">
                        <input name="contrasena_2" id="contrasena_2" type="password" class="form-control"
                               placeholder="Confirmar contraseña" maxlength="50" autocomplete="off" required>
                    </div>

                    <div class="col-md-12">
                        <input name="codigo_token" id="codigo_token" type="text" class="form-control"
                               placeholder="Código" maxlength="50" autocomplete="off" required>
                    </div>
                <?php endif; ?>

                <?php if(isset($_SESSION["password_recovery"])): ?>
                    <div class="col-md-12">
                        <?php if (!empty($respuesta_accion)) { echo $respuesta_accion; } ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="row pt-1">
                <div class="col-md-12 pt-1">
                    <?php if(!isset($_SESSION["password_recovery"])): ?>
                        <button class="btn btn-block btn-success" type="submit" name="form_recovery_confirm" id="submit_btn">
                            <?php echo $isActivation ? "Activar" : "Restablecer"; ?>
                        </button>
                        <a href="index.php" class="btn btn-block btn-danger mt-1">Cancelar</a>
                    <?php endif; ?>

                    <?php if(isset($_SESSION["password_recovery"])): ?>
                        <a href="index.php" class="btn btn-block btn-dark mt-1">Finalizar</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <div class="form-footer">
            <div class="row">
                <div class="col-xs-12 col-sm-12 col-md-12">
                    <p>IQ-Instituto Colombiano de Bienestar Familiar<br>Gestión Integrada de Servicios | &copy; Copyright 2026</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="js/jquery-3.7.1.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
