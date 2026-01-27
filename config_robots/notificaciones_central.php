<?php
/**
 * Robot: Envío de notificaciones (tb_notificaciones_central) usando PHPMailer
 * Mantiene la lógica funcional, pero:
 * - No se queda pegado por imágenes embebidas faltantes (file_exists/is_readable + continue)
 * - En cualquier Exception SIEMPRE marca estado en BD (no queda Pendiente eterno)
 * - Queries/updates con prepared statements
 * - Parse robusto de destinatarios: "correo|Nombre;correo2|Nombre2" y también "correo" solo
 */

declare(strict_types=1);

// -------------------- RUTAS (robustas) --------------------
$ROOT = realpath(__DIR__ . '/..'); // /var/www/icbf/html
if ($ROOT === false) {
    $ROOT = '/var/www/html'; // fallback
}

// conexion_db.php
$conexion1 = $ROOT . '/config/conexion_db.php';
$conexion2 = '/var/www/html/config/conexion_db.php';
if (is_file($conexion1)) {
    require_once($conexion1);
} else {
    require_once($conexion2);
}

// PHPMailer
$phpmailerBase1 = $ROOT . '/PHPMailer-master/src/';
$phpmailerBase2 = '/var/www/html/PHPMailer-master/src/';
$phpmailerBase  = is_dir($phpmailerBase1) ? $phpmailerBase1 : $phpmailerBase2;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require $phpmailerBase . 'Exception.php';
require $phpmailerBase . 'PHPMailer.php';
require $phpmailerBase . 'SMTP.php';

// -------------------- CONFIG --------------------
$BATCH_LIMIT = (int)(getenv('BATCH_LIMIT') ?: 5);
// seguridad: evita valores raros
if ($BATCH_LIMIT < 1)   { $BATCH_LIMIT = 1; }
if ($BATCH_LIMIT > 200) { $BATCH_LIMIT = 200; } // tope prudente

$SMTP_TIMEOUT  = 20;

// Mantengo debug por defecto como tu original (2). Puedes bajarlo con env SMTP_DEBUG=0
$SMTP_DEBUG = getenv('SMTP_DEBUG');
$SMTP_DEBUG = ($SMTP_DEBUG === false || $SMTP_DEBUG === '') ? 2 : (int)$SMTP_DEBUG;

// -------------------- HELPERS --------------------
function parse_recipients(string $raw): array
{
    // Formato: "correo|Nombre;correo2|Nombre2"
    // Mejora: si viene solo "correo", usa correo como nombre
    $out = [];
    $raw = trim($raw);
    if ($raw === '') return $out;

    foreach (explode(';', $raw) as $p) {
        $p = trim($p);
        if ($p === '') continue;

        $pair  = explode('|', $p, 2);
        $email = trim($pair[0] ?? '');
        $name  = trim($pair[1] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if ($name === '') $name = $email;

        $out[] = [$email, $name];
    }
    return $out;
}

function update_status(mysqli $db, int $id, string $estado, string $fecha, int $intentos): void
{
    $sql = "UPDATE tb_notificaciones_central
            SET nc_estado_envio=?, nc_fecha_envio=?, nc_intentos=?
            WHERE nc_id=?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("update_status prepare error: " . $db->error);
        return;
    }
    $intentosStr = (string)$intentos; // nc_intentos es varchar(2)
    $stmt->bind_param("sssi", $estado, $fecha, $intentosStr, $id);
    $stmt->execute();
    $stmt->close();
}

function update_estructura_invalida(mysqli $db, int $id, string $fecha): void
{
    $sql = "UPDATE tb_notificaciones_central
            SET nc_estado_envio='Error-estructura', nc_fecha_envio=?, nc_intentos='1'
            WHERE nc_id=?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("update_estructura_invalida prepare error: " . $db->error);
        return;
    }
    $stmt->bind_param("si", $fecha, $id);
    $stmt->execute();
    $stmt->close();
}

// -------------------- QUERY --------------------
$sql = "
SELECT
    nc.nc_id,
    nc.nc_id_modulo,
    nc.nc_prioridad,
    nc.nc_id_set_from,
    nc.nc_address,
    nc.nc_cc,
    nc.nc_bcc,
    nc.nc_reply_to,
    nc.nc_subject,
    nc.nc_body,
    nc.nc_embeddedimage_ruta,
    nc.nc_intentos,
    nc.nc_eliminar,
    nc.nc_estado_envio,
    rt.ncr_host,
    rt.ncr_port,
    rt.ncr_smtpsecure,
    rt.ncr_smtpauth,
    rt.ncr_username,
    rt.ncr_password,
    rt.ncr_setfrom,
    rt.ncr_setfrom_name,
    nc.nc_embeddedimage_ruta,
    nc.nc_embeddedimage_nombre,
    nc.nc_embeddedimage_tipo
FROM tb_notificaciones_central nc
LEFT JOIN tb_notificaciones_central_remitente rt
  ON nc.nc_id_set_from = rt.ncr_id
WHERE nc.nc_estado_envio='Pendiente'
ORDER BY nc.nc_prioridad
LIMIT ? OFFSET 0
";

$stmt = $enlace_db->prepare($sql);
if (!$stmt) {
    die("SQL prepare error: " . $enlace_db->error . PHP_EOL);
}
$stmt->bind_param("i", $BATCH_LIMIT);
$stmt->execute();
$res = $stmt->get_result();
$resultado_notificaciones = $res->fetch_all(MYSQLI_NUM);
$stmt->close();

// -------------------- PROCESO --------------------
if (count($resultado_notificaciones) > 0) {
    for ($i = 0; $i < count($resultado_notificaciones); $i++) {

        $marca_temporal = date("Y-m-d H:i:s");
        $id_correo      = (int)$resultado_notificaciones[$i][0];

        $host     = (string)$resultado_notificaciones[$i][14];
        $port     = (string)$resultado_notificaciones[$i][15];
        $secure   = (string)$resultado_notificaciones[$i][16];
        $authRaw  = (string)$resultado_notificaciones[$i][17];
        $user     = (string)$resultado_notificaciones[$i][18];
        $pass     = (string)$resultado_notificaciones[$i][19];
        $setFrom  = (string)$resultado_notificaciones[$i][20];
        $fromName = (string)$resultado_notificaciones[$i][21];

        $toRaw    = (string)$resultado_notificaciones[$i][4];
        $ccRaw    = (string)$resultado_notificaciones[$i][5];
        $bccRaw   = (string)$resultado_notificaciones[$i][6];
        $replyRaw = (string)$resultado_notificaciones[$i][7];

        $subject  = (string)$resultado_notificaciones[$i][8];
        $body     = (string)$resultado_notificaciones[$i][9];

        $intentosActual = (int)$resultado_notificaciones[$i][11];
        $num_intentos   = $intentosActual + 1;
        $estado_error   = ($num_intentos >= 2) ? "Error" : "Pendiente";

        $estructura_ok =
            ($host !== "" && $port !== "" && $secure !== "" && $authRaw !== "" &&
             $user !== "" && $pass !== "" && $setFrom !== "" && $fromName !== "" &&
             $toRaw !== "" && $subject !== "" && $body !== "");

        if (!$estructura_ok) {
            update_estructura_invalida($enlace_db, $id_correo, $marca_temporal);
            continue;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();

            $mail->SMTPDebug = $SMTP_DEBUG;
            $mail->Timeout   = $SMTP_TIMEOUT;

            $mail->Host      = $host;
            $mail->Port      = (int)$port;
            $mail->SMTPSecure = $secure;

            $mail->SMTPAuth = ($authRaw === "1" || strtolower($authRaw) === "true");
            $mail->Username = $user;
            $mail->Password = $pass;

            $mail->setFrom($setFrom, $fromName);

            // Reply-To (si viene)
            foreach (parse_recipients($replyRaw) as [$email, $name]) {
                $mail->addReplyTo($email, $name);
            }

            // TO (obligatorio)
            $valida_destinatario = 0;
            foreach (parse_recipients($toRaw) as [$email, $name]) {
                $valida_destinatario = 1;
                $mail->addAddress($email, $name);
            }

            // CC / BCC
            foreach (parse_recipients($ccRaw) as [$email, $name]) {
                $mail->addCC($email, $name);
            }
            foreach (parse_recipients($bccRaw) as [$email, $name]) {
                $mail->addBCC($email, $name);
            }

            // -------------------- embeddedimage (FIX) --------------------
            $image_embedded_ruta   = explode(";", (string)$resultado_notificaciones[$i][22]);
            $image_embedded_nombre = explode(";", (string)$resultado_notificaciones[$i][23]);
            $image_embedded_tipo   = explode(";", (string)$resultado_notificaciones[$i][24]);

            for ($j = 0; $j < count($image_embedded_ruta); $j++) {
                $ruta = trim($image_embedded_ruta[$j] ?? "");
                $cid  = trim($image_embedded_nombre[$j] ?? "");
                $tipo = trim($image_embedded_tipo[$j] ?? "");

                if ($ruta === "" || $cid === "" || $tipo === "") continue;
                if (!file_exists($ruta) || !is_readable($ruta)) continue;

                $mail->AddEmbeddedImage($ruta, $cid, basename($ruta), 'base64', $tipo);
            }

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $body;

            if ($valida_destinatario) {
                if ($mail->send()) {
                    update_status($enlace_db, $id_correo, "Enviado", $marca_temporal, $num_intentos);
                } else {
                    update_status($enlace_db, $id_correo, $estado_error, $marca_temporal, $num_intentos);
                }
            } else {
                update_status($enlace_db, $id_correo, "Destinatario inválido", $marca_temporal, $num_intentos);
            }

        } catch (Exception $e) {
            $reporte_error = (string)$e->getMessage();
            $estado_error_final = "";

            if (stripos($reporte_error, 'Invalid address:') !== false) {
                $estado_error_final = 'Destinatario inválido';
            } elseif ($reporte_error === 'SMTP Error: Could not authenticate.') {
                $estado_error_final = 'Error de autenticación';
            } elseif (stripos($reporte_error, 'Could not access file') !== false) {
                $estado_error_final = 'Error-recurso';
            }

            if ($estado_error_final === "") {
                $estado_error_final = $estado_error; // Pendiente o Error según intentos
            }

            error_log("notificaciones_central.php nc_id={$id_correo} error: " . $reporte_error);
            echo $reporte_error . PHP_EOL;

            update_status($enlace_db, $id_correo, $estado_error_final, $marca_temporal, $num_intentos);
        }
    }
}
?>
