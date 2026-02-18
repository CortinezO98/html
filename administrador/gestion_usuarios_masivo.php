<?php
/**
 * gestion_usuarios_masivo.php
 * ------------------------------------------------------------
 * Carga masiva de usuarios (Excel) + encolado de correo (Pendiente)
 * + creación de perfiles por defecto (mod 9,13,14,15).
 *
 * ✅ MISMA ESTRUCTURA EXACTA QUE crear.php
 * ✅ Inserción IDÉNTICA (bind_param con comillas invertidas y 'ssssssssssssssss')
 * ✅ Misma función hash_password_from_document()
 * ✅ Misma función encolar_correo_usuario()
 * ✅ CORREGIDO: Lectura de fechas de Excel (formato DD/MM/YYYY)
 */

$modulo_plataforma = "Administrador";

require_once("../config/validaciones_seguridad.php");
require_once("../config/conexion_db.php");

/* DEFINICIÓN DE VARIABLES */
$titulo_header = "Gestión Usuarios | Carga masiva";
$pagina = validar_input($_GET['pagina'] ?? '');
$filtro_permanente = validar_input($_GET['id'] ?? '');

// Root del proyecto (robusto)
$ROOT = realpath(__DIR__ . "/..");
if ($ROOT === false) { $ROOT = "/var/www/html"; }

// -------------------- CONFIG CORREO (encolado) --------------------
$BASE_URL = getenv('APP_URL') ?: (getenv('BASE_URL') ?: "https://modulocalidad.grupoasd.com/");
$BASE_URL = rtrim($BASE_URL, '/') . '/';

$SETFROM_ID = 1;
$MODULO_ID  = 1;
$PRIORIDAD  = "Alta";
$SUBJECT    = "Restablecer Contraseña - IQ-ICBF | Gestión Integrada de Servicios";

// Embedded images
$img_firma = $ROOT . "/images/firma-verde.png";
$img_logo  = $ROOT . "/images/logo.png";
$img_logo2 = $ROOT . "/images/logo_notificacion_correo.png";

$emb_ruta   = $img_firma . ";" . $img_logo . ";" . $img_logo2;
$emb_nombre = "firma_verde;logo;logo_notificacion_correo";
$emb_tipo   = "image/png;image/png;image/png";

// -------------------- DESCARGA DE PLANTILLA CSV --------------------
if (isset($_GET['download']) && $_GET['download'] === 'template') {
    $filename = "plantilla_carga_masiva_usuarios.csv";
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    $sep = ";";

    $headers = [
        "documento_identidad", "nombres_apellidos", "usuario_acceso",
        "correo_corporativo", "fecha_ingreso", "estado", "usuario_red",
        "ciudad", "ubicacion", "campania", "cargo_rol", "supervisor",
        "lider_calidad", "primer_empleo", "inicio_sesion"
    ];
    echo implode($sep, $headers) . "\n";

    $rows = [
        ["1000005325","GAMBOA MALDONADO CAROL DANIELA","Carol.Gamboa",
         "Carol.Gamboa@icbf.gov.co","2023-11-01","Activo","Carol.Gamboa",
         "11001","1","1","Agente-General","1019127060","","No","1"],
        ["1000020197","CAMPUZANO PARRA CAMILO","Camilo.Parra",
         "Camilo.Parra@icbf.gov.co","2025-10-03","Activo","Camilo.Parra",
         "11001","1","1","Líder de calidad y formación","1013666854","","No","1"],
        ["1000033333","EJEMPLO SUPERVISOR","Supervisor.Ejemplo",
         "Supervisor.Ejemplo@icbf.gov.co","2025-10-03","Activo","Supervisor.Ejemplo",
         "11001","1","1","Supervisor","1013666854","","No","1"],
    ];

    foreach ($rows as $r) {
        $out = [];
        foreach ($r as $v) {
            $v = (string)$v;
            $needQuotes = (strpos($v, $sep) !== false) || (strpos($v, '"') !== false) || (strpos($v, "\n") !== false);
            if ($needQuotes) $v = '"' . str_replace('"', '""', $v) . '"';
            $out[] = $v;
        }
        echo implode($sep, $out) . "\n";
    }
    exit;
}

// -------------------- Autoload PhpSpreadsheet --------------------
$AUTOLOAD = $ROOT . "/vendor/autoload.php";
$autoload_missing = !file_exists($AUTOLOAD);
if (!$autoload_missing) require_once($AUTOLOAD);

// -------------------- FUNCIÓN PARA CONVERTIR FECHA DE EXCEL --------------------
/**
 * Convierte fecha de Excel (número o string) a formato YYYY-MM-DD
 */
function convertir_fecha_excel($fecha) {
    // Si es nulo o vacío
    if ($fecha === null || $fecha === '') {
        return '';
    }
    
    // 🔥 CASO 1: ES NÚMERO (FECHA DE EXCEL)
    if (is_numeric($fecha)) {
        $unix = ($fecha - 25569) * 86400;
        return date('Y-m-d', $unix);
    }
    
    // 🔥 CASO 2: ES STRING
    $fecha = trim((string)$fecha);
    if ($fecha === '') {
        return '';
    }
    
    // 🔥 CASO 3: YA ESTÁ EN FORMATO YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return $fecha;
    }
    
    // 🔥 CASO 4: DD/MM/YYYY (EL FORMATO QUE USAS)
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $fecha)) {
        $partes = explode('/', $fecha);
        $dia = str_pad($partes[0], 2, '0', STR_PAD_LEFT);
        $mes = str_pad($partes[1], 2, '0', STR_PAD_LEFT);
        $anio = $partes[2];
        return $anio . '-' . $mes . '-' . $dia;
    }
    
    // 🔥 CASO 5: DD-MM-YYYY
    if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $fecha)) {
        $partes = explode('-', $fecha);
        $dia = str_pad($partes[0], 2, '0', STR_PAD_LEFT);
        $mes = str_pad($partes[1], 2, '0', STR_PAD_LEFT);
        $anio = $partes[2];
        return $anio . '-' . $mes . '-' . $dia;
    }
    
    // 🔥 CASO 6: DD.MM.YYYY
    if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/', $fecha)) {
        $partes = explode('.', $fecha);
        $dia = str_pad($partes[0], 2, '0', STR_PAD_LEFT);
        $mes = str_pad($partes[1], 2, '0', STR_PAD_LEFT);
        $anio = $partes[2];
        return $anio . '-' . $mes . '-' . $dia;
    }
    
    // 🔥 CASO 7: FECHA CON HORA
    if (strpos($fecha, ' ') !== false) {
        $partes = explode(' ', $fecha);
        return convertir_fecha_excel($partes[0]);
    }
    
    return '';
}

// -------------------- Helpers IDÉNTICOS a crear.php --------------------
function build_body_user($baseUrl, $token, $nombres, $usu_id, $correo, $usuarioAcceso) {
    $baseUrl = rtrim((string)$baseUrl, "/") . "/";

    $url = $baseUrl . "recuperar_contrasena_confirmar.php"
        . "?u=" . urlencode(base64_encode((string)$usu_id))
        . "&e=" . urlencode(base64_encode((string)$correo))
        . "&t=act"
        . "&token=" . urlencode((string)$token);

    $nombresSafe  = htmlspecialchars((string)$nombres, ENT_QUOTES, "UTF-8");
    $usuarioSafe  = htmlspecialchars((string)$usuarioAcceso, ENT_QUOTES, "UTF-8");
    $tokenSafe    = htmlspecialchars((string)$token, ENT_QUOTES, "UTF-8");

    return '
    <div style="font-family: Arial, sans-serif; font-size: 14px; color:#222;">
      <div style="text-align:center; margin-bottom:10px;">
        <img src="cid:logo_notificacion_correo" alt="Logo" style="max-width:260px;">
      </div>
      <p>Hola <b>'.$nombresSafe.'</b>,</p>
      <p>Se ha generado una solicitud para <b>restablecer tu contraseña</b> en el portal
      <b>IQ-ICBF | Gestión Integrada de Servicios</b>.</p>
      <p><b>Usuario:</b> '.$usuarioSafe.'<br>
         <b>Código:</b> '.$tokenSafe.'</p>
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
    $usuarioAcceso,
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

    $qDup = "
      SELECT COUNT(nc_id) c
      FROM tb_notificaciones_central
      WHERE nc_usuario_registro=?
        AND nc_subject=?
        AND nc_estado_envio='Pendiente'
        AND nc_fecha_registro >= (NOW() - INTERVAL 24 HOUR)
    ";
    $stDup = $db->prepare($qDup);
    if (!$stDup) { $errorMsg = "Prepare duplicado: " . $db->error; return false; }
    $stDup->bind_param("ss", $usu_id, $subject);
    $stDup->execute();
    $row = $stDup->get_result()->fetch_assoc();
    $c = isset($row['c']) ? (int)$row['c'] : 0;
    $stDup->close();

    if ($c > 0) return true;

    try { $token = (string)random_int(100000, 999999); }
    catch (Throwable $e) { $token = (string)mt_rand(100000, 999999); }

    $body = build_body_user($baseUrl, $token, $nombre, $usu_id, $correo, $usuarioAcceso);

    $db->begin_transaction();
    try {
        $stTokOff = $db->prepare("UPDATE tb_administrador_token SET tk_estado='Inactivo' WHERE tk_usuario=? AND tk_estado='Activo'");
        if ($stTokOff) { $stTokOff->bind_param("s", $usu_id); $stTokOff->execute(); $stTokOff->close(); }

        $stTok = $db->prepare("INSERT INTO tb_administrador_token (tk_usuario, tk_token, tk_estado) VALUES (?, ?, 'Activo')");
        if (!$stTok) throw new Exception("Prepare token: " . $db->error);
        $stTok->bind_param("ss", $usu_id, $token);
        $stTok->execute();
        $stTok->close();

        $nc_address = $correo . "|" . $correo;
        $nc_cc = $nc_bcc = $nc_reply = "";
        $nc_intentos = "0";
        $nc_eliminar = "0";
        $nc_estado = "Pendiente";
        $nc_fecha_envio = "";

        $stNc = $db->prepare("
            INSERT INTO tb_notificaciones_central
            (nc_id_modulo, nc_prioridad, nc_id_set_from, nc_address, nc_cc, nc_bcc, nc_reply_to,
             nc_subject, nc_body, nc_embeddedimage_ruta, nc_embeddedimage_nombre, nc_embeddedimage_tipo,
             nc_intentos, nc_eliminar, nc_estado_envio, nc_fecha_envio, nc_usuario_registro)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$stNc) throw new Exception("Prepare notificación: " . $db->error);

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
        error_log("encolar_correo_usuario masivo error usu_id={$usu_id}: " . $errorMsg);
        return false;
    }
}

function hash_password_from_document($documento) {
    $salt = substr(base64_encode(openssl_random_pseudo_bytes(30)), 0, 22);
    $salt = strtr($salt, array('+' => '.'));
    return crypt((string)$documento, '$2y$10$' . $salt);
}

function norm($v) { $v = trim((string)$v); return $v === '' ? '' : $v; }
function norm_estado($estado) {
    $estado = trim((string)$estado);
    if ($estado === '') return "Activo";
    $allow = ["Activo","Inactivo","Retirado"];
    return in_array($estado, $allow, true) ? $estado : "Activo";
}
function norm_si_no($v, $default="No") {
    $v = trim((string)$v);
    if ($v === '') return $default;
    return in_array($v, ["Si","No"], true) ? $v : $default;
}
function norm_inicio_sesion($v) {
    $v = trim((string)$v);
    if ($v === '') return "1";
    return ($v === "0" || $v === "1") ? $v : "1";
}
function is_digits($v) { return ($v !== '' && ctype_digit((string)$v)); }

/**
 * Mapea Cargo/Rol a perfil
 */
function perfil_por_cargo_rol($cargoRol) {
    $v = trim((string)$cargoRol);
    $v = preg_replace('/\s+/', ' ', $v);
    
    if (strcasecmp($v, "Agente-General") === 0) return "Usuario";
    if (strcasecmp($v, "Líder de calidad y formación") === 0) return "Gestor";
    if (strcasecmp($v, "Supervisor") === 0) return "Supervisor";
    return "Usuario";
}

// -------------------- Variables salida --------------------
$mensaje = "";
$mensaje_tipo = "info";
$resumen = [
    "procesados" => 0, "creados" => 0, "duplicados" => 0,
    "encolados_ok" => 0, "encolados_fail" => 0, "perfiles_creados" => 0,
    "errores" => []
];

// -------------------- Catálogos para validación --------------------
$ciudades_validas = [];
$campanias_validas = [];
$ubicaciones_validas = [];
try {
    $qCiu = $enlace_db->prepare("SELECT ciu_codigo FROM tb_administrador_ciudades");
    if ($qCiu && $qCiu->execute()) {
        $rs = $qCiu->get_result();
        while ($row = $rs->fetch_assoc()) $ciudades_validas[(string)$row['ciu_codigo']] = true;
        $qCiu->close();
    }
    $qCamp = $enlace_db->prepare("SELECT ac_id FROM tb_administrador_campania");
    if ($qCamp && $qCamp->execute()) {
        $rs = $qCamp->get_result();
        while ($row = $rs->fetch_assoc()) $campanias_validas[(string)$row['ac_id']] = true;
        $qCamp->close();
    }
    $qUbi = $enlace_db->prepare("SELECT au_id FROM tb_administrador_ubicacion");
    if ($qUbi && $qUbi->execute()) {
        $rs = $qUbi->get_result();
        while ($row = $rs->fetch_assoc()) $ubicaciones_validas[(string)$row['au_id']] = true;
        $qUbi->close();
    }
} catch (Throwable $e) {
    error_log("Error cargando catálogos: ".$e->getMessage());
}

// -------------------- PROCESAMIENTO EXCEL --------------------
if (isset($_POST["procesar_excel"])) {
    try {
        if ($autoload_missing) {
            throw new Exception("Falta Composer autoload. Instala phpoffice/phpspreadsheet.");
        }

        if (!isset($_FILES["archivo_excel"]) || $_FILES["archivo_excel"]["error"] !== UPLOAD_ERR_OK) {
            throw new Exception("No se recibió archivo o hubo error de carga.");
        }

        $tmp  = $_FILES["archivo_excel"]["tmp_name"];
        $name = $_FILES["archivo_excel"]["name"] ?? "archivo.xlsx";
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, ["xlsx", "xls"], true)) {
            throw new Exception("Formato no permitido. Sube .xlsx o .xls");
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();
        
        // 🔥🔥🔥 LECTURA MEJORADA - DETECTA FECHAS AUTOMÁTICAMENTE 🔥🔥🔥
        $rows = [];
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        for ($row = 1; $row <= $highestRow; $row++) {
            $rowData = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);
                $valor = $cell->getValue();
                
                // 🔥 DETECTAR Y CONVERTIR FECHAS AUTOMÁTICAMENTE
                if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                    try {
                        $fechaObj = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($valor);
                        $valor = $fechaObj->format('Y-m-d');
                    } catch (Exception $e) {
                        // Si falla, mantener valor original
                    }
                }
                
                $rowData[] = $valor;
            }
            $rows[] = $rowData;
        }

        if (count($rows) < 2) {
            throw new Exception("El archivo no contiene filas de datos.");
        }

        // 🔥 La fila 0 es el encabezado
        $header = $rows[0];
        $map = [];
        foreach ($header as $col => $val) {
            $key = strtolower(trim((string)$val));
            if ($key !== '') $map[$key] = $col;
        }

        $required = [
            "documento_identidad", "nombres_apellidos", "usuario_acceso",
            "correo_corporativo", "fecha_ingreso", "estado", "usuario_red",
            "ciudad", "ubicacion", "campania", "cargo_rol", "supervisor",
            "lider_calidad", "primer_empleo"
        ];

        $missing = [];
        foreach ($required as $req) {
            if (!isset($map[$req])) $missing[] = $req;
        }
        if (!empty($missing)) {
            throw new Exception("Faltan columnas obligatorias: " . implode(", ", $missing));
        }

        $has_inicio_sesion = isset($map["inicio_sesion"]);

        // --- PREPARACIÓN DE CONSULTAS (IGUAL QUE crear.php) ---
        $consulta_duplicado = "SELECT COUNT(`usu_id`) FROM `tb_administrador_usuario` WHERE `usu_id`=?";
        $stDup = $enlace_db->prepare($consulta_duplicado);
        
        // ⚠️⚠️⚠️ IMPORTANTE: MISMA ESTRUCTURA EXACTA QUE crear.php ⚠️⚠️⚠️
        $sentencia_insert = $enlace_db->prepare("INSERT INTO `tb_administrador_usuario`(`usu_id`, `usu_acceso`, `usu_contrasena`, `usu_nombres_apellidos`, `usu_correo_corporativo`, `usu_fecha_incorporacion`, `usu_campania`, `usu_usuario_red`, `usu_cargo_rol`, `usu_sede`, `usu_ciudad`, `usu_estado`, `usu_supervisor`, `usu_lider_calidad`, `usu_inicio_sesion`, `usu_piloto`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stPerDup = $enlace_db->prepare("SELECT COUNT(per_id) c FROM tb_configuracion_perfil_usu_mod WHERE per_id=?");
        $stPerIns = $enlace_db->prepare("INSERT INTO tb_configuracion_perfil_usu_mod (per_id, per_usuario, per_modulo, per_perfil) VALUES (?, ?, ?, ?)");

        if (!$stDup || !$sentencia_insert || !$stPerDup || !$stPerIns) {
            throw new Exception("Error en preparación de consultas SQL");
        }

        $stats = [
            'procesados' => 0, 'creados' => 0, 'duplicados' => 0,
            'encolados_ok' => 0, 'encolados_fail' => 0, 'perfiles_creados' => 0,
            'errores' => []
        ];

        // 🔥 Empezamos desde 1 porque 0 es el header
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $stats["procesados"]++;

            $doc   = norm($r[$map["documento_identidad"]] ?? '');
            $nom   = norm($r[$map["nombres_apellidos"]] ?? '');
            $acc   = norm($r[$map["usuario_acceso"]] ?? '');
            $mail  = norm($r[$map["correo_corporativo"]] ?? '');
            
            // 🔥🔥🔥 APLICAR CONVERSIÓN DE FECHA 🔥🔥🔥
            $fecha_raw = $r[$map["fecha_ingreso"]] ?? '';
            $fing = convertir_fecha_excel($fecha_raw);

            $estado = norm_estado($r[$map["estado"]] ?? '');
            $ured   = norm($r[$map["usuario_red"]] ?? '');
            $ciu    = norm($r[$map["ciudad"]] ?? '');
            $ubi    = norm($r[$map["ubicacion"]] ?? '');
            $camp   = norm($r[$map["campania"]] ?? '');
            $rol    = norm($r[$map["cargo_rol"]] ?? '');
            $sup    = norm($r[$map["supervisor"]] ?? '');
            $lid    = norm($r[$map["lider_calidad"]] ?? '');
            $piloto = norm_si_no($r[$map["primer_empleo"]] ?? '', "No");
            $inicio = $has_inicio_sesion ? norm_inicio_sesion($r[$map["inicio_sesion"]] ?? '') : "1";

            $perfil = perfil_por_cargo_rol($rol);

            // VALIDACIONES BÁSICAS
            $fila_errores = [];
            
            if ($doc === '') $fila_errores[] = "Documento obligatorio";
            if ($nom === '') $fila_errores[] = "Nombres obligatorios";
            if ($acc === '') $fila_errores[] = "Usuario acceso obligatorio";
            if ($mail === '') $fila_errores[] = "Correo obligatorio";
            if ($fing === '') {
                $fila_errores[] = "Fecha ingreso obligatoria";
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fing)) {
                $fila_errores[] = "Formato fecha inválido: '$fecha_raw' - Use DD/MM/YYYY";
            }
            
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) $fila_errores[] = "Correo inválido: $mail";
            if ($sup === '') $fila_errores[] = "Supervisor obligatorio";
            
            if ($ciu === '') $fila_errores[] = "Ciudad obligatoria";
            elseif (!empty($ciudades_validas) && !isset($ciudades_validas[$ciu])) $fila_errores[] = "Ciudad '$ciu' no existe";
            
            if ($ubi === '') $fila_errores[] = "Ubicación obligatoria";
            elseif (!is_digits($ubi)) $fila_errores[] = "Ubicación debe ser ID numérico";
            elseif (!empty($ubicaciones_validas) && !isset($ubicaciones_validas[$ubi])) $fila_errores[] = "Ubicación '$ubi' no existe";
            
            if ($camp === '') $fila_errores[] = "Campaña obligatoria";
            elseif (!is_digits($camp)) $fila_errores[] = "Campaña debe ser ID numérico";
            elseif (!empty($campanias_validas) && !isset($campanias_validas[$camp])) $fila_errores[] = "Campaña '$camp' no existe";
            
            if ($rol === '') $fila_errores[] = "Cargo/Rol obligatorio";

            if (!empty($fila_errores)) {
                $stats["errores"][] = "Fila " . ($i + 1) . ": " . implode(", ", $fila_errores);
                continue;
            }

            // VERIFICAR DUPLICADO (IGUAL QUE crear.php)
            $stDup->bind_param("s", $doc);
            $stDup->execute();
            $resultado = $stDup->get_result()->fetch_all(MYSQLI_NUM);
            
            if (isset($resultado[0][0]) && $resultado[0][0] > 0) {
                $stats["duplicados"]++;
                continue;
            }

            // GENERAR CONTRASEÑA (IGUAL QUE crear.php)
            $salt = substr(base64_encode(openssl_random_pseudo_bytes(30)), 0, 22);
            $salt = strtr($salt, array('+' => '.'));
            $contrasena = crypt($doc, '$2y$10$' . $salt);

            // ⚠️⚠️⚠️ INSERTAR USUARIO - MISMA ESTRUCTURA EXACTA QUE crear.php ⚠️⚠️⚠️
            $sentencia_insert->bind_param(
                'ssssssssssssssss',
                $doc, $acc, $contrasena, $nom, $mail, $fing,
                $camp, $ured, $rol, $ubi, $ciu, $estado, $sup, $lid,
                $inicio, $piloto
            );

            if ($sentencia_insert->execute()) {
                $stats["creados"]++;

                // ENCOLAR CORREO (IGUAL QUE crear.php)
                $err = "";
                $encolado_ok = encolar_correo_usuario(
                    $enlace_db, $doc, $mail, $nom, $acc,
                    $MODULO_ID, $PRIORIDAD, $SETFROM_ID, $SUBJECT,
                    $BASE_URL, $emb_ruta, $emb_nombre, $emb_tipo, $err
                );

                if ($encolado_ok) {
                    $stats["encolados_ok"]++;
                } else {
                    $stats["encolados_fail"]++;
                    $stats["errores"][] = "Fila " . ($i + 1) . ": Usuario creado pero falló encolado de correo";
                }

                // ASIGNAR PERFILES
                $defaultModules = [9, 13, 14, 15];
                foreach ($defaultModules as $mod) {
                    $perId = $doc . (string)$mod;
                    
                    $stPerDup->bind_param("s", $perId);
                    $stPerDup->execute();
                    $pc = (int)($stPerDup->get_result()->fetch_assoc()["c"] ?? 0);
                    
                    if ($pc == 0) {
                        $stPerIns->bind_param("ssis", $perId, $doc, $mod, $perfil);
                        if ($stPerIns->execute()) $stats["perfiles_creados"]++;
                    }
                }
            } else {
                $stats["errores"][] = "Fila " . ($i + 1) . ": Error al insertar usuario ({$doc}) - " . $sentencia_insert->error;
            }
        }

        $stDup->close();
        $sentencia_insert->close();
        $stPerDup->close();
        $stPerIns->close();

        $resumen = $stats;

        if ($stats["creados"] > 0) {
            $mensaje_tipo = "success";
            $mensaje = "✅ Proceso completado. Se crearon {$stats['creados']} usuarios.";
        } else {
            $mensaje_tipo = "warning";
            $mensaje = "⚠️ No se crearon usuarios. Revise los errores.";
        }

    } catch (Throwable $e) {
        $mensaje_tipo = "danger";
        $mensaje = "❌ Error: " . $e->getMessage();
    }
}

$ruta_volver = "gestion_usuarios.php?pagina=" . urlencode((string)$pagina) . "&id=" . urlencode((string)$filtro_permanente);
$downloadUrl = "gestion_usuarios_masivo.php?download=template&pagina=" . urlencode((string)$pagina) . "&id=" . urlencode((string)$filtro_permanente);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php include("../config/configuracion_estilos.php"); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{ --brand:#5cb85c; --text:#333; --muted:#777; --line:#e5e5e5; }
        .contenido .container-fluid{ max-width:1200px; }
        .page-header.pro{ margin:0 0 20px 0; padding:0 0 10px 0; border-bottom:2px solid var(--brand); }
        .page-header.pro h3{ margin:0; font-weight:600; color:var(--text); }
        .page-header.pro small{ color:var(--muted); font-weight:400; }
        .panel{ border-radius:6px; border-color:var(--line); box-shadow:0 1px 2px rgba(0,0,0,.04); }
        .panel-heading{ border-top-left-radius:6px; border-top-right-radius:6px; }
        .panel-title{ font-weight:600; }
        .upload-area{ border:2px dashed #d9d9d9; border-radius:8px; padding:22px; text-align:center; background:#fcfcfc; transition:all .2s ease; margin-bottom:15px; }
        .upload-area:hover{ border-color:var(--brand); background:#f7fff7; }
        .upload-area .glyphicon{ font-size:44px; color:var(--brand); margin-bottom:8px; }
        .upload-area h4{ margin:8px 0 6px 0; font-weight:600; color:#555; }
        .code-example{ background:#2b2b2b; color:#f1f1f1; padding:12px 14px; border-radius:6px; font-family: Menlo, Monaco, Consolas, "Courier New", monospace; font-size:12px; overflow-x:auto; white-space:nowrap; }
        .stats-card{ background:#fff; border:1px solid var(--line); border-left:4px solid var(--brand); padding:14px; border-radius:6px; margin-bottom:14px; }
        .stats-number{ font-size:28px; font-weight:700; color:var(--text); line-height:1.1; }
        .stats-label{ font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.6px; }
        .kpi-item{ display:block; padding:8px 10px; border:1px solid #eee; border-radius:6px; margin-bottom:8px; background:#fff; }
        .kpi-item .badge{ float:right; }
        .error-list{ max-height:320px; overflow-y:auto; }
        .error-item{ padding:9px 10px; margin-bottom:6px; background:#fff; border:1px solid #f2dede; border-left:4px solid #d9534f; border-radius:6px; color:#a94442; }
        .glyphicon-refresh-animate{ animation:spin 1s infinite linear; }
        @keyframes spin{ from{ transform:rotate(0deg); } to{ transform:rotate(360deg); } }
    </style>
</head>
<body>
<?php include("../menu_principal.php"); ?>
<?php include("../menu_header.php"); ?>

<div class="contenido">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="page-header pro">
                    <h3><i class="glyphicon glyphicon-upload" style="color:var(--brand);"></i> Carga masiva de usuarios <small>Importación desde Excel</small></h3>
                </div>
            </div>
        </div>

        <?php if ($autoload_missing): ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> Falta librería phpoffice/phpspreadsheet. Ejecute: composer require phpoffice/phpspreadsheet
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Columna principal -->
            <div class="col-md-8">
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title"><i class="glyphicon glyphicon-file"></i> Archivo de importación</h3></div>
                    <div class="panel-body">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <div class="upload-area" id="uploadArea">
                                <i class="glyphicon glyphicon-cloud-upload"></i>
                                <h4>Arrastre su archivo Excel aquí</h4>
                                <p>o</p>
                                <div class="form-group" style="margin-bottom:0;">
                                    <input id="archivo_excel" type="file" name="archivo_excel" style="display:none;" accept=".xlsx,.xls" <?php echo $autoload_missing ? 'disabled' : ''; ?> required>
                                    <button type="button" class="btn btn-primary" id="selectFileBtn"><i class="glyphicon glyphicon-folder-open"></i> Seleccionar archivo</button>
                                    <span id="fileSelectedText" style="margin-left:10px;color:#666;"></span>
                                </div>
                                <p class="help-block" style="margin-top:15px;">Formatos: .xlsx, .xls | Fechas: DD/MM/YYYY</p>
                            </div>
                            <div class="row">
                                <div class="col-sm-6">
                                    <a href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success btn-block" style="padding:12px;"><i class="glyphicon glyphicon-download-alt"></i> Descargar plantilla</a>
                                </div>
                                <div class="col-sm-6">
                                    <button class="btn btn-primary btn-block" type="submit" name="procesar_excel" id="procesarBtn" style="padding:12px;" <?php echo $autoload_missing ? 'disabled' : ''; ?>><i class="glyphicon glyphicon-play"></i> Procesar archivo</button>
                                </div>
                            </div>
                        </form>
                        <?php if ($mensaje !== ""): ?>
                            <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade in" style="margin-top:15px;">
                                <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                                <strong><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar resultados -->
            <div class="col-md-4">
                <?php if (($resumen["procesados"] ?? 0) > 0): ?>
                    <div class="panel panel-success">
                        <div class="panel-heading" style="background-color:#5cb85c; color:white;">
                            <h3 class="panel-title"><i class="glyphicon glyphicon-stats"></i> Resultados</h3>
                        </div>
                        <div class="panel-body">
                            <div class="stats-card">
                                <div class="row">
                                    <div class="col-xs-6"><div class="stats-number"><?php echo (int)$resumen["procesados"]; ?></div><div class="stats-label">Procesados</div></div>
                                    <div class="col-xs-6"><div class="stats-number" style="color:#5cb85c;"><?php echo (int)$resumen["creados"]; ?></div><div class="stats-label">Creados</div></div>
                                </div>
                            </div>
                            <div class="kpi-item">Correos encolados <span class="badge" style="background-color:#5cb85c;"><?php echo (int)$resumen["encolados_ok"]; ?></span></div>
                            <div class="kpi-item">Duplicados <span class="badge" style="background-color:#f0ad4e;"><?php echo (int)$resumen["duplicados"]; ?></span></div>
                            <div class="kpi-item">Perfiles asignados <span class="badge" style="background-color:#337ab7;"><?php echo (int)$resumen["perfiles_creados"]; ?></span></div>
                            <?php if (!empty($resumen["errores"])): ?>
                                <hr>
                                <h5 style="color:#d9534f;"><i class="glyphicon glyphicon-warning-sign"></i> Errores</h5>
                                <div class="error-list">
                                    <?php foreach (array_slice($resumen["errores"], 0, 10) as $err): ?>
                                        <div class="error-item"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="panel panel-default">
                    <div class="panel-heading"><h3 class="panel-title"><i class="glyphicon glyphicon-cog"></i> Acciones</h3></div>
                    <div class="panel-body">
                        <a href="<?php echo htmlspecialchars($ruta_volver, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-default btn-block"><i class="glyphicon glyphicon-arrow-left"></i> Volver</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('archivo_excel');
    const selectBtn = document.getElementById('selectFileBtn');
    const fileText = document.getElementById('fileSelectedText');
    const uploadArea = document.getElementById('uploadArea');
    const procesarBtn = document.getElementById('procesarBtn');

    if (selectBtn && fileInput) {
        selectBtn.addEventListener('click', function() { fileInput.click(); });
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                fileText.innerHTML = '<span class="label label-success">' + this.files[0].name + '</span>';
                uploadArea.style.borderColor = '#5cb85c';
                uploadArea.style.backgroundColor = '#f7fff7';
                if (procesarBtn) procesarBtn.disabled = false;
            }
        });
    }

    if (uploadArea && fileInput) {
        ['dragenter','dragover','dragleave','drop'].forEach(ev => {
            uploadArea.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); });
        });
        ['dragenter','dragover'].forEach(ev => {
            uploadArea.addEventListener(ev, () => { 
                uploadArea.style.borderColor = '#5cb85c'; 
                uploadArea.style.backgroundColor = '#f0fff0'; 
            });
        });
        ['dragleave','drop'].forEach(ev => {
            uploadArea.addEventListener(ev, () => { 
                uploadArea.style.borderColor = '#d9d9d9'; 
                uploadArea.style.backgroundColor = '#fcfcfc'; 
            });
        });
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (e.dataTransfer.files[0]) {
                fileInput.files = e.dataTransfer.files;
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
        if (fileInput && !fileInput.files[0]) {
            e.preventDefault();
            alert('Por favor seleccione un archivo Excel para procesar.');
            return false;
        }
        if (procesarBtn) {
            procesarBtn.innerHTML = '<i class="glyphicon glyphicon-refresh glyphicon-refresh-animate"></i> Procesando...';
            procesarBtn.disabled = true;
        }
    });
});
</script>

<?php include("../footer.php"); ?>
<?php include("../config/configuracion_js.php"); ?>
</body>
</html>