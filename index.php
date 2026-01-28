<?php
require_once("config/validaciones_seguridad_raiz.php");
require_once("config/conexion_db.php");

// Si sesion esta iniciada se redirige al contenido, sino muestra index de logueo //
unset($_SESSION["password_recovery"]);

// ✅ FLUJO PROFESIONAL:
// - Si hay sesión y es primer inicio -> FORZAR cambio de clave
// - Si hay sesión y ya inició -> contenido
if (isset($_SESSION["usu_id"])) {
    if ((int)($_SESSION['usu_inicio_sesion'] ?? 1) === 0) {
        header("Location: config_seguridad.php");
        exit;
    }
    header("Location: contenido.php");
    exit;
}

if (isset($_POST["form_login"])) {

    // obtiene variable de captcha
    $captcha_validacion = validar_input($_POST['captcha_validacion'] ?? '');
    $captcha_original   = validar_input($_COOKIE['captcha'] ?? '');

    // obtiene variable usuario y contraseña
    $usuario_acceso = validar_input($_POST['usuario_acceso'] ?? '');
    $contrasena     = validar_input($_POST['contrasena'] ?? '');

    // valida el captcha correcto
    if ($captcha_original !== '' && $captcha_original == sha1($captcha_validacion)) {

        // ✅ IMPORTANTE: también traemos usu_cargo_rol
        $consulta_string = "SELECT `usu_id`, `usu_acceso`, `usu_contrasena`, `usu_nombres_apellidos`,
                                   `usu_correo_corporativo`, `usu_estado`, `usu_inicio_sesion`,
                                   `usu_campania`, `usu_cargo_rol`
                            FROM `tb_administrador_usuario`
                            WHERE `usu_acceso`= ?";

        $consulta_registros = $enlace_db->prepare($consulta_string);
        $consulta_registros->bind_param("s", $usuario_acceso);
        $consulta_registros->execute();
        $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

        if (count($resultado_registros) > 0) {

            $db_usu_id           = $resultado_registros[0][0];
            $db_usu_acceso       = $resultado_registros[0][1];
            $db_usu_contrasena   = $resultado_registros[0][2];
            $db_usu_nombre       = $resultado_registros[0][3];
            $db_usu_correo       = $resultado_registros[0][4];
            $db_usu_estado       = $resultado_registros[0][5];
            $db_usu_inicio       = (int)$resultado_registros[0][6];
            $db_usu_campania     = $resultado_registros[0][7];
            $db_usu_cargo_rol    = $resultado_registros[0][8]; // ✅ clave

            if (
                $usuario_acceso == $db_usu_acceso &&
                $db_usu_estado === 'Activo' &&
                crypt($contrasena, $db_usu_contrasena) === $db_usu_contrasena
            ) {

                // ✅ Seguridad: evita session fixation
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }

                $_SESSION["usu_id"]              = $db_usu_id;
                $_SESSION["usu_acceso"]          = $db_usu_acceso;
                $_SESSION["usu_nombre_completo"] = $db_usu_nombre;
                $_SESSION["usu_correo"]          = $db_usu_correo;
                $_SESSION["usu_estado_usuario"]  = $db_usu_estado;
                $_SESSION["usu_inicio_sesion"]   = $db_usu_inicio;
                $_SESSION["usu_campania"]        = $db_usu_campania;
                $_SESSION["usu_cargo_rol"]       = $db_usu_cargo_rol; // ✅ ESTO es lo que te faltaba

                $consulta_string_log = "INSERT INTO `tb_administrador_log`
                    (`clog_log_modulo`, `clog_log_tipo`, `clog_log_accion`, `clog_log_detalle`, `clog_registro_usuario`)
                    VALUES ('Login','inicio_sesion','Inicio de sesión','Inicio de sesión',?)";

                $consulta_registros_log = $enlace_db->prepare($consulta_string_log);
                $consulta_registros_log->bind_param("s", $db_usu_id);
                $consulta_registros_log->execute();

                // ✅ Primer ingreso -> forzar cambio de contraseña
                if ($db_usu_inicio === 0) {
                    header("Location: config_seguridad.php");
                    exit;
                }

                header("Location: contenido.php");
                exit;

            } else {
                $respuesta_accion = "<p class='alert alert-danger'>Inicio de sesión fallido, verifique e intente nuevamente!</p>";
            }

        } else {
            $respuesta_accion = "<p class='alert alert-danger'>Inicio de sesión fallido, verifique e intente nuevamente!</p>";
        }

    } else {
        $respuesta_accion = "<p class='alert alert-danger'>Inicio de sesión fallido, verifique e intente nuevamente!</p>";
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
                <div class="col-md-12">
                    <h4 class="form-titulo">Iniciar sesión</h4>
                    <?php if (!empty($respuesta_accion)) { echo $respuesta_accion; } ?>
                </div>
                <div class="col-md-12">
                    <input name="usuario_acceso" id="usuario_acceso" type="text" class="form-control"
                           value="<?php if(isset($_POST["form_login"])){ echo validar_output($usuario_acceso); } ?>"
                           placeholder="Usuario" maxlength="50" autofocus autocomplete="off" required>
                </div>
                <div class="col-md-12">
                    <input name="contrasena" id="contrasena" type="password" class="form-control"
                           placeholder="Contraseña" maxlength="20" autocomplete="off" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-9 ">
                    <input name="captcha_validacion" id="captcha_validacion" type="text" class="form-control"
                           placeholder="Escriba los caracteres de la imagen" maxlength="5" autocomplete="off" required>
                </div>
                <div class="col-md-3">
                    <center><img src="captcha_imagen.php" title="Código aleatorio"></center>
                </div>
                <div class="col-md-12">
                    <a href="recuperar_contrasena.php" class="float-right font-size-13">
                        <span class="fas fa-key"></span> Olvidé mi contraseña
                    </a>
                </div>
                <div class="col-md-12 pt-1">
                    <button class="btn btn-block btn-success" type="submit" name="form_login" id="submit_btn"
                            data-loading-text="Iniciando....">Ingresar</button>
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
