<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Administrador";

    require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");

    /* =========================================================
       HELPERS (nuevo): base URL + ruta imágenes + encolar correo
       ========================================================= */
    function getBaseUrl(): string {
        $u = getenv('APP_URL') ?: getenv('BASE_URL');

        if (!$u) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            $proto = $isHttps ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'modulocalidad.grupoasd.com';
            $u = $proto . '://' . $host . '/';
        }

        $u = trim($u);
        if ($u === '') $u = 'https://modulocalidad.grupoasd.com/';

        // Si viene sin esquema, forzamos https
        if (!preg_match('~^https?://~i', $u)) {
            $u = 'https://' . $u;
        }

        return rtrim($u, '/') . '/';
    }

    function getImagesDirFromAdmin(): string {
        // administrador/ -> raíz del proyecto está en ../
        $imagesDir = realpath(__DIR__ . '/../images');
        if ($imagesDir === false) {
            // fallback por si tu ruta real es fija
            $imagesDir = '/var/www/icbf/html/images';
        }
        return rtrim($imagesDir, '/');
    }

    function encolarNotificacionCentral(mysqli $db, array $p): bool {
        $sql = "INSERT INTO tb_notificaciones_central
            (nc_id_modulo, nc_prioridad, nc_id_set_from, nc_address, nc_cc, nc_bcc, nc_reply_to,
             nc_subject, nc_body, nc_embeddedimage_ruta, nc_embeddedimage_nombre, nc_embeddedimage_tipo,
             nc_intentos, nc_eliminar, nc_estado_envio, nc_fecha_envio, nc_usuario_registro)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        $st = $db->prepare($sql);
        if (!$st) return false;

        // Tipos: module como int (i) + el resto strings
        $st->bind_param(
            "issssssssssssssss",
            $p['nc_id_modulo'],
            $p['nc_prioridad'],
            $p['nc_id_set_from'],
            $p['nc_address'],
            $p['nc_cc'],
            $p['nc_bcc'],
            $p['nc_reply_to'],
            $p['nc_subject'],
            $p['nc_body'],
            $p['nc_embeddedimage_ruta'],
            $p['nc_embeddedimage_nombre'],
            $p['nc_embeddedimage_tipo'],
            $p['nc_intentos'],
            $p['nc_eliminar'],
            $p['nc_estado_envio'],
            $p['nc_fecha_envio'],
            $p['nc_usuario_registro']
        );

        // mismo estilo “reintentos” (5)
        for ($i=0; $i<5; $i++) {
            if ($st->execute()) { $st->close(); return true; }
        }
        $st->close();
        return false;
    }

    /*DEFINICIÓN DE VARIABLES*/
    $titulo_header = "Gestión Usuarios | Editar";
    $pagina=validar_input($_GET['pagina']);
    $filtro_permanente=validar_input($_GET['id']);
    $id_registro=validar_input(base64_decode($_GET['reg']));

    if(isset($_POST["guardar_registro"])){
        $documento_identidad=validar_input($_POST['documento_identidad']);
        $nombres_apellidos=validar_input($_POST['nombres_apellidos']);
        $usuario_acceso=validar_input($_POST['usuario_acceso']);
        $correo_corporativo=validar_input($_POST['correo_corporativo']);
        $fecha_ingreso=validar_input($_POST['fecha_ingreso']);
        $estado=validar_input($_POST['estado']);
        $usuario_red=validar_input($_POST['usuario_red']);
        $ciudad=validar_input($_POST['ciudad']);
        $ubicacion=validar_input($_POST['ubicacion']);
        $campania=validar_input($_POST['campania']);
        $cargo_rol=validar_input($_POST['cargo_rol']);
        $supervisor=validar_input($_POST['supervisor']);
        $lider_calidad=validar_input($_POST['lider_calidad']);
        $primer_empleo=validar_input($_POST['primer_empleo']);

        $modulo_permiso=$_POST['modulo_permiso'];
        $contador_insert=0;

        for ($i=0; $i < count($modulo_permiso); $i++) {
            $modulo_separado=explode("|", $modulo_permiso[$i]);
            $key_registro=$id_registro.$modulo_separado[0];

            if ($modulo_separado[1]=="") {
                // Prepara la sentencia
                $sentencia_insert = $enlace_db->prepare("DELETE FROM `tb_configuracion_perfil_usu_mod` WHERE `per_id`=?");
                // Agrega variables a sentencia preparada
                $sentencia_insert->bind_param('s', $key_registro);
            } else {
                // Prepara la sentencia
                $sentencia_insert = $enlace_db->prepare("INSERT INTO `tb_configuracion_perfil_usu_mod`(`per_id`, `per_usuario`, `per_modulo`, `per_perfil`) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE `per_perfil` = ?");
                // Agrega variables a sentencia preparada
                $sentencia_insert->bind_param('sssss', $key_registro, $id_registro, $modulo_separado[0], $modulo_separado[1], $modulo_separado[1]);
            }

            if ($sentencia_insert->execute()) {
                $contador_insert++;
            }
        }

        // Prepara la sentencia
        $consulta_actualizar = $enlace_db->prepare("UPDATE `tb_administrador_usuario` SET `usu_acceso`=?,`usu_nombres_apellidos`=?,`usu_correo_corporativo`=?,`usu_fecha_incorporacion`=?,`usu_campania`=?,`usu_usuario_red`=?,`usu_cargo_rol`=?,`usu_sede`=?,`usu_ciudad`=?,`usu_estado`=?,`usu_supervisor`=?,`usu_lider_calidad`=?, `usu_piloto`=? WHERE `usu_id`=?");
        // Agrega variables a sentencia preparada
        $consulta_actualizar->bind_param('ssssssssssssss', $usuario_acceso, $nombres_apellidos, $correo_corporativo, $fecha_ingreso, $campania, $usuario_red, $cargo_rol, $ubicacion, $ciudad, $estado, $supervisor, $lider_calidad, $primer_empleo, $id_registro);
        // Ejecuta sentencia preparada
        $consulta_actualizar->execute();

        if (comprobarSentencia($enlace_db->info) AND $contador_insert==count($modulo_permiso)) {
            $respuesta_accion = "<script type='text/javascript'>alertify.success('¡Registro actualizado exitosamente!', 0);</script>";
        } else {
            $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Problemas al actualizar el registro, por favor verifique e intente nuevamente!', 0);</script>";
        }
    }

    /* =========================================================
       RESET CONTRASEÑA (MODIFICADO): resetea + ENCOLA NOTIFICACIÓN
       ========================================================= */
    if (isset($_POST["reset_contrasena"])) {

        $salt = substr(base64_encode(openssl_random_pseudo_bytes(30)), 0, 22);
        $salt = strtr($salt, array('+' => '.'));

        // Mantengo tu lógica: password temporal = doc/id del usuario
        $contrasena = crypt($id_registro, '$2y$10$' . $salt);
        $inicio_sesion = 0;

        // UPDATE (ojo: tipos correctos: s i s)
        $consulta_actualizar = $enlace_db->prepare(
            "UPDATE tb_administrador_usuario
             SET usu_contrasena=?, usu_inicio_sesion=?
             WHERE usu_id=?"
        );
        $consulta_actualizar->bind_param("sis", $contrasena, $inicio_sesion, $id_registro);
        $consulta_actualizar->execute();

        if (comprobarSentencia($enlace_db->info)) {

            // 1) Consultar correo/nombre del usuario (no depender del POST)
            $stU = $enlace_db->prepare(
                "SELECT usu_correo_corporativo, usu_nombres_apellidos
                 FROM tb_administrador_usuario
                 WHERE usu_id=? LIMIT 1"
            );
            $stU->bind_param("s", $id_registro);
            $stU->execute();
            $u = $stU->get_result()->fetch_assoc();
            $stU->close();

            $correo = $u['usu_correo_corporativo'] ?? '';
            $nombre = $u['usu_nombres_apellidos'] ?? $id_registro;

            if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL)) {

                $baseUrl   = getBaseUrl();
                $imagesDir = getImagesDirFromAdmin();

                $nc_embeddedimage_ruta   = $imagesDir . "/firma-verde.png;"
                                         . $imagesDir . "/logo.png;"
                                         . $imagesDir . "/logo_notificacion_correo.png";
                $nc_embeddedimage_nombre = "firma-verde;logo;logo_notificacion_correo";
                $nc_embeddedimage_tipo   = "image/png;image/png;image/png";

                $contenido_correo = "
                    <center>
                    <table style='width:100%; max-width:600px; font-family:Lato, Arial, sans-serif; font-size:13px;'>
                        <tr>
                            <td style='padding:5px;'><img src='cid:logo' style='width:160px;'></td>
                            <td style='padding:5px; text-align:right;'><img src='cid:logo_notificacion_correo'></td>
                        </tr>
                    </table>

                    <table style='width:100%; max-width:600px; font-family:Lato, Arial, sans-serif;'>
                        <tr>
                            <td style='padding:5px;'>
                                <p style='font-size:13px;color:#666;'><b>Restablecimiento de contraseña</b></p>
                                <p style='font-size:12px;color:#666;'>
                                    Cordial saludo,<br><br>
                                    Un administrador restableció tu contraseña.<br>
                                    Para ingresar, usa tu <b>documento de identidad</b> como contraseña temporal y luego el sistema te pedirá definir una nueva.
                                </p>

                                <center>
                                    <a href='".htmlspecialchars($baseUrl, ENT_QUOTES, "UTF-8")."' target='_blank'
                                       style='border-radius:4px; color:#fff; font-size:12px; padding:8px 10px;
                                              text-align:center; text-decoration:none!important; width:60%;
                                              display:block; background-color:#72BF44'>
                                        Ir a IQGIS-ICBF
                                    </a>
                                </center>
                                <br>
                            </td>
                        </tr>
                    </table>

                    <table style='width:100%; max-width:600px; background:#666; font-family:Lato, Arial, sans-serif;'>
                        <tr>
                            <td style='font-size:12px; padding:5px 10px; color:#FFF'>
                                <center>IQ-ICBF | Gestión Integrada de Servicios | &copy; Copyright 2020</center>
                            </td>
                        </tr>
                    </table>

                    <center>
                        <table style='max-width:600px; font-family:Lato, Arial, sans-serif;'>
                            <tr>
                                <td style='width:45px;'><img src='cid:firma-verde' style='margin:5px; width:45px;'></td>
                                <td>
                                    <p style='font-size:11px; color:#196F3D;'><br>No imprima este mensaje de no ser necesario;<br>de ésta manera aportamos al cuidado del planeta.</p>
                                </td>
                            </tr>
                        </table>
                    </center>
                    </center>
                ";

                $payload = [
                    'nc_id_modulo'            => 1,
                    'nc_prioridad'            => 'Alta',
                    'nc_id_set_from'          => '1',
                    'nc_address'              => $correo . '|' . $nombre,
                    'nc_cc'                   => '',
                    'nc_bcc'                  => '',
                    'nc_reply_to'             => '',
                    'nc_subject'              => 'Restablecimiento de contraseña - IQ-ICBF',
                    'nc_body'                 => str_replace("'", '"', $contenido_correo),
                    'nc_embeddedimage_ruta'   => $nc_embeddedimage_ruta,
                    'nc_embeddedimage_nombre' => $nc_embeddedimage_nombre,
                    'nc_embeddedimage_tipo'   => $nc_embeddedimage_tipo,
                    'nc_intentos'             => '',
                    'nc_eliminar'             => 'Si',
                    'nc_estado_envio'         => 'Pendiente',
                    'nc_fecha_envio'          => '',
                    'nc_usuario_registro'     => $_SESSION['usu_id'] ?? $id_registro,
                ];

                $okNotif = encolarNotificacionCentral($enlace_db, $payload);

                if ($okNotif) {
                    if (function_exists('registro_log')) {
                        registro_log($enlace_db, $modulo_plataforma, 'notificacion', $payload['nc_subject'], 'NULL', $_SESSION['usu_id'] ?? 'SYSTEM');
                    }
                    $respuesta_accion = "<script type='text/javascript'>alertify.success('¡Contraseña reiniciada y notificación encolada!', 0);</script>";
                } else {
                    $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Contraseña reiniciada, pero no se pudo encolar el correo!', 0);</script>";
                }

            } else {
                $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Contraseña reiniciada, pero el usuario no tiene correo válido!', 0);</script>";
            }

        } else {
            $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Problemas al reiniciar contraseña, por favor verifique e intente nuevamente!', 0);</script>";
        }
    }

    $consulta_string_ciudad="SELECT `ciu_codigo`, `ciu_departamento`, `ciu_municipio` FROM `tb_administrador_ciudades` ORDER BY `ciu_departamento`, `ciu_municipio`";

    $consulta_registros_ciudad = $enlace_db->prepare($consulta_string_ciudad);
    $consulta_registros_ciudad->execute();
    $resultado_registros_ciudad = $consulta_registros_ciudad->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string="SELECT `usu_id`, `usu_acceso`, `usu_contrasena`, `usu_nombres_apellidos`, `usu_correo_corporativo`, `usu_fecha_incorporacion`, `usu_campania`, `usu_usuario_red`, `usu_cargo_rol`, `usu_sede`, `usu_ciudad`, `usu_estado`, `usu_supervisor`, `usu_lider_calidad`, `usu_inicio_sesion`, `usu_piloto` FROM `tb_administrador_usuario` WHERE `usu_id`=?";

    $consulta_registros = $enlace_db->prepare($consulta_string);
    $consulta_registros->bind_param("s", $id_registro);
    $consulta_registros->execute();
    $resultado_registros = $consulta_registros->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string_supervisor="SELECT `usu_id`, `usu_nombres_apellidos` FROM `tb_administrador_usuario` WHERE `usu_cargo_rol`='Supervisor' OR `usu_cargo_rol`='Sistema' OR `usu_cargo_rol`='Supervisor' OR `usu_cargo_rol`='Coordinador' OR `usu_cargo_rol`='Líder de calidad y formación' ORDER BY `usu_nombres_apellidos`";

    $consulta_registros_supervisor = $enlace_db->prepare($consulta_string_supervisor);
    $consulta_registros_supervisor->execute();
    $resultado_registros_supervisor = $consulta_registros_supervisor->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string_calidad="SELECT `usu_id`, `usu_nombres_apellidos` FROM `tb_administrador_usuario` WHERE `usu_cargo_rol`='Líder de calidad y formación' OR `usu_cargo_rol`='Sistema' ORDER BY `usu_nombres_apellidos`";

    $consulta_registros_calidad = $enlace_db->prepare($consulta_string_calidad);
    $consulta_registros_calidad->execute();
    $resultado_registros_calidad = $consulta_registros_calidad->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string_ubicacion="SELECT `au_id`, `au_nombre_ubicacion`, `au_observaciones` FROM `tb_administrador_ubicacion` ORDER BY `au_nombre_ubicacion`";

    $consulta_registros_ubicacion = $enlace_db->prepare($consulta_string_ubicacion);
    $consulta_registros_ubicacion->execute();
    $resultado_registros_ubicacion = $consulta_registros_ubicacion->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string_campania="SELECT `ac_id`, `ac_nombre_campania`, `ac_observaciones` FROM `tb_administrador_campania` ORDER BY `ac_nombre_campania`";

    $consulta_registros_campania = $enlace_db->prepare($consulta_string_campania);
    $consulta_registros_campania->execute();
    $resultado_registros_campania = $consulta_registros_campania->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string_modulos="SELECT `mod_id`, `mod_modulo_nombre` FROM `tb_configuracion_modulo` ORDER BY `mod_modulo_nombre`";

    $consulta_registros_modulos = $enlace_db->prepare($consulta_string_modulos);
    $consulta_registros_modulos->execute();
    $resultado_registros_modulos = $consulta_registros_modulos->get_result()->fetch_all(MYSQLI_NUM);

    $consulta_string_permisos="SELECT `per_id`, `per_usuario`, `per_modulo`, `per_perfil` FROM `tb_configuracion_perfil_usu_mod` WHERE `per_usuario`=?";

    $consulta_registros_permisos = $enlace_db->prepare($consulta_string_permisos);
    $consulta_registros_permisos->bind_param("s", $id_registro);
    $consulta_registros_permisos->execute();
    $resultado_registros_permisos = $consulta_registros_permisos->get_result()->fetch_all(MYSQLI_NUM);

    for ($i=0; $i < count($resultado_registros_permisos); $i++) {
        $array_permisos[$resultado_registros_permisos[$i][2]]=$resultado_registros_permisos[$i][3];
    }

    /*Enlace para botón finalizar y cancelar*/
    $ruta_cancelar_finalizar="gestion_usuarios.php?pagina=".$pagina."&id=".$filtro_permanente;

?>
<!DOCTYPE html>
<html lang="ES">
<head>
	<?php
        include("../config/configuracion_estilos.php");
    ?>
</head>
<body>
    <?php
        include("../menu_principal.php");
        include("../menu_header.php");
    ?>
    <div class="contenido">
        <?php if (!empty($respuesta_accion)) {echo $respuesta_accion;} ?>
        <form name="guardar_registro" action="" method="POST" enctype="multipart/form-data">
        <div class="row justify-content-center">
            <div class="col-md-6 pt-2 background-blanco">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                          <label for="documento_identidad">Doc. identidad</label>
                          <input type="text" class="form-control form-control-sm" name="documento_identidad" id="documento_identidad" maxlength="20" value="<?php echo $resultado_registros[0][0]; ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                          <label for="nombres_apellidos">Nombres y apellidos</label>
                          <input type="text" class="form-control form-control-sm" name="nombres_apellidos" id="nombres_apellidos" maxlength="100" value="<?php echo $resultado_registros[0][3]; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="estado">Estado</label>
                            <select class="form-control form-control-sm" name="estado" id="estado" required>
                              <option value="">Seleccione</option>
                              <option value="Activo" <?php if($resultado_registros[0][11]=="Activo"){ echo "selected"; } ?>>Activo</option>
                              <option value="Inactivo" <?php if($resultado_registros[0][11]=="Inactivo"){ echo "selected"; } ?>>Inactivo</option>
                              <option value="Retirado" <?php if($resultado_registros[0][11]=="Retirado"){ echo "selected"; } ?>>Retirado</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                          <label for="usuario_acceso">Usuario acceso</label>
                          <input type="text" class="form-control form-control-sm" name="usuario_acceso" id="usuario_acceso" maxlength="20" value="<?php echo $resultado_registros[0][1]; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                          <label for="usuario_red">Usuario red</label>
                          <input type="text" class="form-control form-control-sm" name="usuario_red" id="usuario_red" maxlength="20" value="<?php echo $resultado_registros[0][7]; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                          <label for="fecha_ingreso">Fecha ingreso</label>
                          <input type="date" class="form-control form-control-sm" name="fecha_ingreso" id="fecha_ingreso" maxlength="20" value="<?php echo $resultado_registros[0][5]; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="primer_empleo">Primer empleo</label>
                            <select class="form-control form-control-sm" name="primer_empleo" id="primer_empleo" required>
                              <option value="">Seleccione</option>
                              <option value="Si" <?php if($resultado_registros[0][15]=="Si"){ echo "selected"; } ?>>Si</option>
                              <option value="No" <?php if($resultado_registros[0][15]=="No"){ echo "selected"; } ?>>No</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                          <label for="correo_corporativo">Correo corporativo</label>
                          <input type="email" class="form-control form-control-sm" name="correo_corporativo" id="correo_corporativo" maxlength="100" value="<?php echo $resultado_registros[0][4]; ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="ciudad">Ciudad</label>
                            <select class="form-control form-control-sm" name="ciudad" id="ciudad" required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_ciudad); $i++): ?>
                                    <option value="<?php echo $resultado_registros_ciudad[$i][0]; ?>" <?php if($resultado_registros[0][10]==$resultado_registros_ciudad[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_ciudad[$i][2].", ".$resultado_registros_ciudad[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="ubicacion">Ubicación</label>
                            <select class="form-control form-control-sm" name="ubicacion" id="ubicacion" required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_ubicacion); $i++): ?>
                                    <option value="<?php echo $resultado_registros_ubicacion[$i][0]; ?>" <?php if($resultado_registros[0][9]==$resultado_registros_ubicacion[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_ubicacion[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="campania">Campaña</label>
                            <select class="form-control form-control-sm" name="campania" id="campania" required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_campania); $i++): ?>
                                    <option value="<?php echo $resultado_registros_campania[$i][0]; ?>" <?php if($resultado_registros[0][6]==$resultado_registros_campania[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_campania[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="cargo_rol">Cargo/rol</label>
                            <select class="form-control form-control-sm" name="cargo_rol" id="cargo_rol" required>
                              <option value="">Seleccione</option>
                              <option value="Agente-General" <?php if($resultado_registros[0][8]=="Agente-General"){ echo "selected"; } ?>>Agente-General</option>
                              <option value="Agente-Técnico" <?php if($resultado_registros[0][8]=="Agente-Técnico"){ echo "selected"; } ?>>Agente-Técnico</option>
                              <option value="Agente-Profesional" <?php if($resultado_registros[0][8]=="Agente-Profesional"){ echo "selected"; } ?>>Agente-Profesional</option>
                              <option value="Supervisor" <?php if($resultado_registros[0][8]=="Supervisor"){ echo "selected"; } ?>>Supervisor</option>
                              <option value="Coordinador" <?php if($resultado_registros[0][8]=="Coordinador"){ echo "selected"; } ?>>Coordinador</option>
                              <option value="Líder de calidad y formación" <?php if($resultado_registros[0][8]=="Líder de calidad y formación"){ echo "selected"; } ?>>Líder de calidad y formación</option>
                              <option value="Monitor calidad" <?php if($resultado_registros[0][8]=="Monitor calidad"){ echo "selected"; } ?>>Monitor calidad</option>
                              <option value="Auditor calidad" <?php if($resultado_registros[0][8]=="Auditor calidad"){ echo "selected"; } ?>>Auditor calidad</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="supervisor">Supervisor</label>
                            <select class="form-control form-control-sm" name="supervisor" id="supervisor" required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_supervisor); $i++): ?>
                                    <option value="<?php echo $resultado_registros_supervisor[$i][0]; ?>" <?php if($resultado_registros[0][12]==$resultado_registros_supervisor[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_supervisor[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="lider_calidad">Líder de calidad</label>
                            <select class="form-control form-control-sm" name="lider_calidad" id="lider_calidad">
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_calidad); $i++): ?>
                                    <option value="<?php echo $resultado_registros_calidad[$i][0]; ?>" <?php if($resultado_registros[0][13]==$resultado_registros_calidad[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_calidad[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                </div>
            </div>

            <div class="col-md-4 pt-2 background-blanco">
                <table class="table table-bordered table-striped table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Módulo</th>
                            <th>Permiso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i=0; $i < count($resultado_registros_modulos); $i++) { ?>
                        <tr>
                            <td class="align-middle"><?php echo $resultado_registros_modulos[$i][1]; ?></td>
                            <td>
                                <select class="form-control form-control-sm" name="modulo_permiso[]" id="modulo_permiso">
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|">Seleccione</option>
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|Visitante" <?php if(($array_permisos[$resultado_registros_modulos[$i][0]] ?? "")=="Visitante"){ echo "selected"; } ?>>Visitante</option>
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|Cliente" <?php if(($array_permisos[$resultado_registros_modulos[$i][0]] ?? "")=="Cliente"){ echo "selected"; } ?>>Cliente</option>
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|Usuario" <?php if(($array_permisos[$resultado_registros_modulos[$i][0]] ?? "")=="Usuario"){ echo "selected"; } ?>>Usuario</option>
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|Supervisor" <?php if(($array_permisos[$resultado_registros_modulos[$i][0]] ?? "")=="Supervisor"){ echo "selected"; } ?>>Supervisor</option>
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|Formador" <?php if(($array_permisos[$resultado_registros_modulos[$i][0]] ?? "")=="Formador"){ echo "selected"; } ?>>Formador</option>
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|Gestor" <?php if(($array_permisos[$resultado_registros_modulos[$i][0]] ?? "")=="Gestor"){ echo "selected"; } ?>>Gestor</option>
                                  <option value="<?php echo $resultado_registros_modulos[$i][0]; ?>|Administrador" <?php if(($array_permisos[$resultado_registros_modulos[$i][0]] ?? "")=="Administrador"){ echo "selected"; } ?>>Administrador</option>
                                </select>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <div class="col-md-10">
                <div class="form-group">
                    <?php if(!isset($_POST["guardar_registro"]) AND !isset($_POST["reset_contrasena"])): ?>
                        <button class="btn btn-danger" type="button" onclick="guardar_cancelar();">Cancelar</button>
                    <?php endif; ?>
                        <button class="btn btn-success" type="submit" name="guardar_registro">Guardar</button>
                    <?php if(isset($_POST["guardar_registro"]) OR isset($_POST["reset_contrasena"])): ?>
                        <a href="<?php echo $ruta_cancelar_finalizar; ?>" class="btn btn-dark">Finalizar</a>
                    <?php endif; ?>
                    <button class="btn btn-warning float-right" type="submit" name="reset_contrasena">Reset contraseña</button>
                </div>
            </div>

        </div>
        </form>
    </div>

    <?php
        include("../footer.php");
        include("../config/configuracion_js.php");
    ?>
</body>
</html>
