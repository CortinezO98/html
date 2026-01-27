<?php
    //Validación de permisos del usuario para el módulo
    $modulo_plataforma="Administrador";

    require_once("../config/validaciones_seguridad.php");
	require_once("../config/conexion_db.php");

    /*DEFINICIÓN DE VARIABLES*/

    $titulo_header = "Gestión Usuarios | Crear";
    $pagina=validar_input($_GET['pagina']);
    $filtro_permanente=validar_input($_GET['id']);

    // Asegura la variable de control (sin cambiar el flujo)
    if (!isset($_SESSION['registro_creado'])) {
        $_SESSION['registro_creado'] = 0;
    }

    // -------------------- CONFIG CORREO (encolado) --------------------
    // Root del proyecto (robusto)
    $ROOT = realpath(__DIR__ . "/..");
    if ($ROOT === false) { $ROOT = "/var/www/html"; }

    // Si no hay BASE_URL en env, usa la que tú vienes usando

    $BASE_URL = getenv('APP_URL') ?: (getenv('BASE_URL') ?: "https://modulocalidad.grupoasd.com/");
    $BASE_URL = rtrim($BASE_URL, '/') . '/';

    $SETFROM_ID = 1;                 // tb_notificaciones_central_remitente.ncr_id
    $MODULO_ID  = 1;                 // nc_id_modulo (ajusta si tu módulo real es otro)
    $PRIORIDAD  = "Alta";
    $SUBJECT    = "Restablecer Contraseña - IQ-ICBF | Gestión Integrada de Servicios";

    // Embedded images (se guardan en BD y el robot las embebe si existen)
    $img_firma = $ROOT . "/images/firma-verde.png";
    $img_logo  = $ROOT . "/images/logo.png";
    $img_logo2 = $ROOT . "/images/logo_notificacion_correo.png";

    $emb_ruta   = $img_firma . ";" . $img_logo . ";" . $img_logo2;
    $emb_nombre = "firma_verde;logo;logo_notificacion_correo";
    $emb_tipo   = "image/png;image/png;image/png";

    function build_body_user($baseUrl, $token, $nombres) {
        $baseUrl = rtrim((string)$baseUrl, "/") . "/";
        $url = $baseUrl . "recuperar_contrasena_confirmar.php?token=" . urlencode((string)$token);

        $nombresSafe = htmlspecialchars((string)$nombres, ENT_QUOTES, "UTF-8");

        return '
        <div style="font-family: Arial, sans-serif; font-size: 14px; color:#222;">
          <div style="text-align:center; margin-bottom:10px;">
            <img src="cid:logo_notificacion_correo" alt="Logo" style="max-width:260px;">
          </div>

          <p>Hola <b>'.$nombresSafe.'</b>,</p>

          <p>Se ha generado una solicitud para <b>restablecer tu contraseña</b> en el portal
          <b>IQ-ICBF | Gestión Integrada de Servicios</b>.</p>

          <p><b>Código:</b> '.htmlspecialchars((string)$token, ENT_QUOTES, "UTF-8").'</p>

          <p>Puedes continuar desde este enlace:</p>
          <p><a href="'.$url.'">'.$url.'</a></p>

          <hr style="border:none;border-top:1px solid #ddd;margin:18px 0;">

          <div style="text-align:center;">
            <img src="cid:firma_verde" alt="Firma" style="max-width:260px;">
          </div>
          <div style="text-align:center; margin-top:8px;">
            <img src="cid:logo" alt="IQ" style="max-width:160px;">
          </div>
        </div>';
    }

    function encolar_correo_usuario(
        $db,
        $usu_id,
        $correo,
        $nombre,
        $moduloId,
        $prioridad,
        $setFromId,
        $subject,
        $baseUrl,
        $embRuta,
        $embNombre,
        $embTipo,
        &$errorMsg
    ) {
        $errorMsg = "";

        $correo = (string)$correo;
        $usu_id = (string)$usu_id;

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = "Correo inválido: " . $correo;
            return false;
        }

        // Evita duplicado por (usuario + subject)
        $qDup = "SELECT COUNT(nc_id) c FROM tb_notificaciones_central WHERE nc_usuario_registro=? AND nc_subject=?";
        $stDup = $db->prepare($qDup);
        if (!$stDup) {
            $errorMsg = "Prepare duplicado: " . $db->error;
            return false;
        }
        $stDup->bind_param("ss", $usu_id, $subject);
        $stDup->execute();
        $row = $stDup->get_result()->fetch_assoc();
        $c = isset($row['c']) ? (int)$row['c'] : 0;
        $stDup->close();

        // Si ya existe, no vuelve a encolar (no rompe la creación)
        if ($c > 0) {
            return true;
        }

        // Token 6 dígitos
        try {
            $token = (string)random_int(100000, 999999);
        } catch (Throwable $e) {
            // Fallback muy raro, pero evita romper el flujo
            $token = (string)mt_rand(100000, 999999);
        }

        $body = build_body_user($baseUrl, $token, $nombre);

        // Transacción: token + notificación
        $db->begin_transaction();
        try {
            // Inactivar tokens previos
            $stTokOff = $db->prepare("UPDATE tb_administrador_token SET tk_estado='Inactivo' WHERE tk_usuario=? AND tk_estado='Activo'");
            if ($stTokOff) {
                $stTokOff->bind_param("s", $usu_id);
                $stTokOff->execute();
                $stTokOff->close();
            }

            // Insert token (según tu implementación que ya funciona en backfill)
            $stTok = $db->prepare("INSERT INTO tb_administrador_token (tk_usuario, tk_token, tk_estado) VALUES (?, ?, 'Activo')");
            if (!$stTok) {
                throw new Exception("Prepare token: " . $db->error);
            }
            $stTok->bind_param("ss", $usu_id, $token);
            $stTok->execute();
            $stTok->close();

            // Insert notificación
            $nc_address    = $correo . "|" . $correo;
            $nc_cc         = "";
            $nc_bcc        = "";
            $nc_reply      = "";
            $nc_intentos   = "0";
            $nc_eliminar   = "0";
            $nc_estado     = "Pendiente";
            $nc_fecha_envio= "";

            $stNc = $db->prepare("
                INSERT INTO tb_notificaciones_central
                (nc_id_modulo, nc_prioridad, nc_id_set_from, nc_address, nc_cc, nc_bcc, nc_reply_to,
                 nc_subject, nc_body, nc_embeddedimage_ruta, nc_embeddedimage_nombre, nc_embeddedimage_tipo,
                 nc_intentos, nc_eliminar, nc_estado_envio, nc_fecha_envio, nc_usuario_registro)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            if (!$stNc) {
                throw new Exception("Prepare notificación: " . $db->error);
            }

            // Tipos: i(s)i(s)(s)(s)(s)(s)(s)(s)(s)(s)(s)(s)(s)(s)(s)
            $types = "isissssssssssssss";
            $stNc->bind_param(
                $types,
                $moduloId,
                $prioridad,
                $setFromId,
                $nc_address,
                $nc_cc,
                $nc_bcc,
                $nc_reply,
                $subject,
                $body,
                $embRuta,
                $embNombre,
                $embTipo,
                $nc_intentos,
                $nc_eliminar,
                $nc_estado,
                $nc_fecha_envio,
                $usu_id
            );
            $stNc->execute();
            $stNc->close();

            $db->commit();
            return true;

        } catch (Throwable $e) {
            $db->rollback();
            $errorMsg = $e->getMessage();
            error_log("encolar_correo_usuario error usu_id={$usu_id}: " . $errorMsg);
            return false;
        }
    }

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
        $inicio_sesion=0;

        if($_SESSION['registro_creado']!=1){
            $salt = substr(base64_encode(openssl_random_pseudo_bytes(30)), 0, 22);
            $salt = strtr($salt, array('+' => '.'));
            $contrasena = crypt($documento_identidad, '$2y$10$' . $salt);

            $consulta_duplicado="SELECT COUNT(`usu_id`) FROM `tb_administrador_usuario` WHERE `usu_id`=?";

            $consulta_registros_duplicados = $enlace_db->prepare($consulta_duplicado);
            $consulta_registros_duplicados->bind_param("s", $documento_identidad);
            $consulta_registros_duplicados->execute();
            $resultado_registros_duplicados = $consulta_registros_duplicados->get_result()->fetch_all(MYSQLI_NUM);

            if ($resultado_registros_duplicados[0][0]==0) {

                // Prepara la sentencia
                $sentencia_insert = $enlace_db->prepare("INSERT INTO `tb_administrador_usuario`(`usu_id`, `usu_acceso`, `usu_contrasena`, `usu_nombres_apellidos`, `usu_correo_corporativo`, `usu_fecha_incorporacion`, `usu_campania`, `usu_usuario_red`, `usu_cargo_rol`, `usu_sede`, `usu_ciudad`, `usu_estado`, `usu_supervisor`, `usu_lider_calidad`, `usu_inicio_sesion`, `usu_piloto`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

                // Agrega variables a sentencia preparada
                $sentencia_insert->bind_param('ssssssssssssssss', $documento_identidad, $usuario_acceso, $contrasena, $nombres_apellidos, $correo_corporativo, $fecha_ingreso, $campania, $usuario_red, $cargo_rol, $ubicacion, $ciudad, $estado, $supervisor, $lider_calidad, $inicio_sesion, $primer_empleo);

                if ($sentencia_insert->execute()) {

                    // Encolar correo (sin romper el flujo si falla)
                    $err = "";
                    $encolado_ok = encolar_correo_usuario(
                        $enlace_db,
                        $documento_identidad,
                        $correo_corporativo,
                        $nombres_apellidos,
                        $MODULO_ID,
                        $PRIORIDAD,
                        $SETFROM_ID,
                        $SUBJECT,
                        $BASE_URL,
                        $emb_ruta,
                        $emb_nombre,
                        $emb_tipo,
                        $err
                    );

                    if ($encolado_ok) {
                        // Mantiene estilo de mensaje (success) sin tocar diseño
                        $respuesta_accion = "<script type='text/javascript'>alertify.success('¡Registro creado exitosamente!', 0);</script>";
                    } else {
                        $msg = htmlspecialchars((string)$err, ENT_QUOTES, 'UTF-8');
                        $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Registro creado, pero no se pudo encolar correo! Detalle: {$msg}', 0);</script>";
                    }

                    $_SESSION['registro_creado']=1;

                } else {
                    $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Problemas al crear el registro, por favor verifique e intente nuevamente!', 0);</script>";
                }
            } else {
                $respuesta_accion = "<script type='text/javascript'>alertify.warning('¡Usuario duplicado, por favor verifique e intente nuevamente!', 0);</script>";
            }

        } else {
            $respuesta_accion = "<script type='text/javascript'>alertify.success('¡Registro creado exitosamente, haga clic en <b>Finalizar</b> para salir!', 0);</script>";
        }
    }

    $consulta_string_ciudad="SELECT `ciu_codigo`, `ciu_departamento`, `ciu_municipio` FROM `tb_administrador_ciudades` ORDER BY `ciu_departamento`, `ciu_municipio`";

    $consulta_registros_ciudad = $enlace_db->prepare($consulta_string_ciudad);
    $consulta_registros_ciudad->execute();
    $resultado_registros_ciudad = $consulta_registros_ciudad->get_result()->fetch_all(MYSQLI_NUM);

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
                          <input type="text" class="form-control form-control-sm" name="documento_identidad" id="documento_identidad" maxlength="20" value="<?php if(isset($_POST["guardar_registro"])){ echo $documento_identidad; } ?>" <?php if($_SESSION['registro_creado']==1) { echo 'readonly'; } ?> required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                          <label for="nombres_apellidos">Nombres y apellidos</label>
                          <input type="text" class="form-control form-control-sm" name="nombres_apellidos" id="nombres_apellidos" maxlength="100" value="<?php if(isset($_POST["guardar_registro"])){ echo $nombres_apellidos; } ?>" <?php if($_SESSION['registro_creado']==1) { echo 'readonly'; } ?> required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="estado">Estado</label>
                            <select class="form-control form-control-sm" name="estado" id="estado" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?> required>
                              <option value="">Seleccione</option>
                              <option value="Activo" <?php if(isset($_POST["guardar_registro"]) AND $estado=="Activo"){ echo "selected"; } ?>>Activo</option>
                              <option value="Inactivo" <?php if(isset($_POST["guardar_registro"]) AND $estado=="Inactivo"){ echo "selected"; } ?>>Inactivo</option>
                              <option value="Retirado" <?php if(isset($_POST["guardar_registro"]) AND $estado=="Retirado"){ echo "selected"; } ?>>Retirado</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                          <label for="usuario_acceso">Usuario acceso</label>
                          <input type="text" class="form-control form-control-sm" name="usuario_acceso" id="usuario_acceso" maxlength="20" value="<?php if(isset($_POST["guardar_registro"])){ echo $usuario_acceso; } ?>" <?php if($_SESSION['registro_creado']==1) { echo 'readonly'; } ?> required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                          <label for="usuario_red">Usuario red</label>
                          <input type="text" class="form-control form-control-sm" name="usuario_red" id="usuario_red" maxlength="20" value="<?php if(isset($_POST["guardar_registro"])){ echo $usuario_red; } ?>" <?php if($_SESSION['registro_creado']==1) { echo 'readonly'; } ?> required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                          <label for="fecha_ingreso">Fecha ingreso</label>
                          <input type="date" class="form-control form-control-sm" name="fecha_ingreso" id="fecha_ingreso" maxlength="20" value="<?php if(isset($_POST["guardar_registro"])){ echo $fecha_ingreso; } ?>" <?php if($_SESSION['registro_creado']==1) { echo 'readonly'; } ?> required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="primer_empleo">Primer empleo</label>
                            <select class="form-control form-control-sm" name="primer_empleo" id="primer_empleo" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?> required>
                              <option value="">Seleccione</option>
                              <option value="Si" <?php if(isset($_POST["guardar_registro"]) AND $primer_empleo=="Si"){ echo "selected"; } ?>>Si</option>
                              <option value="No" <?php if(isset($_POST["guardar_registro"]) AND $primer_empleo=="No"){ echo "selected"; } ?>>No</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                          <label for="correo_corporativo">Correo corporativo</label>
                          <input type="email" class="form-control form-control-sm" name="correo_corporativo" id="correo_corporativo" maxlength="100" value="<?php if(isset($_POST["guardar_registro"])){ echo $correo_corporativo; } ?>" <?php if($_SESSION['registro_creado']==1) { echo 'readonly'; } ?> required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="ciudad">Ciudad</label>
                            <select class="form-control form-control-sm" name="ciudad" id="ciudad" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?> required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_ciudad); $i++): ?>
                                    <option value="<?php echo $resultado_registros_ciudad[$i][0]; ?>" <?php if(isset($_POST["guardar_registro"]) AND $ciudad==$resultado_registros_ciudad[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_ciudad[$i][2].", ".$resultado_registros_ciudad[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="ubicacion">Ubicación</label>
                            <select class="form-control form-control-sm" name="ubicacion" id="ubicacion" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?> required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_ubicacion); $i++): ?>
                                    <option value="<?php echo $resultado_registros_ubicacion[$i][0]; ?>" <?php if(isset($_POST["guardar_registro"]) AND $ubicacion==$resultado_registros_ubicacion[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_ubicacion[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="campania">Campaña</label>
                            <select class="form-control form-control-sm" name="campania" id="campania" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?> required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_campania); $i++): ?>
                                    <option value="<?php echo $resultado_registros_campania[$i][0]; ?>" <?php if(isset($_POST["guardar_registro"]) AND $campania==$resultado_registros_campania[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_campania[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="cargo_rol">Cargo/rol</label>
                            <select class="form-control form-control-sm" name="cargo_rol" id="cargo_rol" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?> required>
                              <option value="">Seleccione</option>
                              <option value="Agente-General" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Agente-General"){ echo "selected"; } ?>>Agente-General</option>
                              <option value="Agente-Técnico" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Agente-Técnico"){ echo "selected"; } ?>>Agente-Técnico</option>
                              <option value="Agente-Profesional" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Agente-Profesional"){ echo "selected"; } ?>>Agente-Profesional</option>
                              <option value="Supervisor" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Supervisor"){ echo "selected"; } ?>>Supervisor</option>
                              <option value="Coordinador" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Coordinador"){ echo "selected"; } ?>>Coordinador</option>
                              <option value="Líder de calidad y formación" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Líder de calidad y formación"){ echo "selected"; } ?>>Líder de calidad y formación</option>
                              <option value="Monitor calidad" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Monitor calidad"){ echo "selected"; } ?>>Monitor calidad</option>
                              <option value="Auditor Calidad" <?php if(isset($_POST["guardar_registro"]) AND $cargo_rol=="Auditor Calidad"){ echo "selected"; } ?>>Auditor Calidad</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="supervisor">Supervisor</label>
                            <select class="form-control form-control-sm" name="supervisor" id="supervisor" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?> required>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_supervisor); $i++): ?>
                                    <option value="<?php echo $resultado_registros_supervisor[$i][0]; ?>" <?php if(isset($_POST["guardar_registro"]) AND $supervisor==$resultado_registros_supervisor[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_supervisor[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="lider_calidad">Líder de calidad</label>
                            <select class="form-control form-control-sm" name="lider_calidad" id="lider_calidad" <?php if($_SESSION['registro_creado']==1) { echo 'disabled'; } ?>>
                                <option value="">Seleccione</option>
                                <?php for ($i=0; $i < count($resultado_registros_calidad); $i++): ?>
                                    <option value="<?php echo $resultado_registros_calidad[$i][0]; ?>" <?php if(isset($_POST["guardar_registro"]) AND $lider_calidad==$resultado_registros_calidad[$i][0]){ echo "selected"; } ?>><?php echo $resultado_registros_calidad[$i][1]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="form-group">
                            <?php if($_SESSION['registro_creado']==1): ?>
                                <a href="<?php echo $ruta_cancelar_finalizar; ?>" class="btn btn-dark">Finalizar</a>
                            <?php else: ?>
                                <button class="btn btn-danger" type="button" onclick="guardar_cancelar();">Cancelar</button>
                                <button class="btn btn-success" type="submit" name="guardar_registro">Guardar</button>
                            <?php endif; ?>
                        </div>
                    </div>
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
