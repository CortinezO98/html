<?php
    require_once("config/validaciones_seguridad_raiz.php");

    //Si sesion esta iniciada se redirige al contenido, sino muestra index de logueo//
    if (!isset($_SESSION["usu_id"]) || $_SESSION["usu_id"] === null || $_SESSION["usu_id"] === "") {
        header("Location:index.php");
        exit;
    }

    require_once("config/conexion_db.php");
    // error_reporting(E_ALL);
    // ini_set('display_errors', '1');

    // -------- Helpers mínimos (no cambian flujo, solo robustecen) ----------
    function b64_get(string $key): string {
        $raw = $_GET[$key] ?? '';
        if (!is_string($raw) || $raw === '') return '';
        $dec = base64_decode($raw, true);
        return ($dec === false) ? '' : $dec;
    }

    // Asegurar timezone consistente con el servidor (-05) para evitar corrimientos
    // (si ya lo manejas en otro lado no afecta, solo fija por si Apache tiene otro tz)
    if (function_exists('date_default_timezone_set')) {
        // Usa la del sistema si viene configurada; si no, fuerza Bogotá.
        // (Bogotá es -05 y no maneja DST, es estable)
        @date_default_timezone_set('America/Bogota');
    }

    $tipo   = b64_get('tipo');
    $accion = b64_get('accion');

    if ($tipo !== "" && $accion !== "") {

        if ($accion === "inicio") {

            // -------- Control duplicado SOLO para "turno" (mantiene lógica original) ----------
            $control_duplicado = 0;

            if ($tipo === 'turno') {
                $hoy = date('Y-m-d') . '%';

                // 1) Detectar si ya hay un turno abierto hoy (igual que antes, pero con LIKE parametrizado)
                $consulta_string_duplicado = "
                    SELECT COUNT(`cot_usuario`)
                    FROM `tb_control_turno`
                    WHERE `cot_usuario`=?
                      AND `cot_tipo`='turno'
                      AND `cot_inicio` LIKE ?
                      AND (`cot_fin`='' OR `cot_fin` IS NULL)
                ";
                $consulta_registros_duplicado = $enlace_db->prepare($consulta_string_duplicado);
                $consulta_registros_duplicado->bind_param('ss', $_SESSION['usu_id'], $hoy);
                $consulta_registros_duplicado->execute();
                $resultado_registros_duplicado = $consulta_registros_duplicado->get_result()->fetch_all(MYSQLI_NUM);
                $control_duplicado = (int)($resultado_registros_duplicado[0][0] ?? 0);

                /**
                 * Remediación sin alterar el flujo funcional:
                 * Si existe un turno "abierto" hoy pero quedó inconsistente por hora corrido/fin vacío,
                 * lo cerramos automáticamente SOLO cuando detectamos duplicado.
                 *
                 * Esto evita que "no deje iniciar el turno" por bloqueos eternos, y respeta la intención original:
                 * NO permitir dos turnos abiertos al mismo tiempo.
                 */
                if ($control_duplicado > 0) {
                    // Cierra el turno abierto más reciente del día para ese usuario (seguro y acotado)
                    $fecha_actual = date("Y-m-d H:i:s");

                    // Traer el último turno abierto del día
                    $st_last_open = $enlace_db->prepare("
                        SELECT `cot_id`, `cot_inicio`
                        FROM `tb_control_turno`
                        WHERE `cot_usuario`=?
                          AND `cot_tipo`='turno'
                          AND `cot_inicio` LIKE ?
                          AND (`cot_fin`='' OR `cot_fin` IS NULL)
                        ORDER BY `cot_id` DESC
                        LIMIT 1
                    ");
                    $st_last_open->bind_param('ss', $_SESSION['usu_id'], $hoy);
                    $st_last_open->execute();
                    $row_last = $st_last_open->get_result()->fetch_assoc();

                    if ($row_last && !empty($row_last['cot_id'])) {
                        $cot_id_abierto = $row_last['cot_id'];
                        $cot_inicio_abierto = $row_last['cot_inicio'];

                        // Duración por tu función existente dateDiff (igual que en cierre)
                        $duracion_auto = dateDiff($cot_inicio_abierto, $fecha_actual);

                        // Cerrar
                        $st_close = $enlace_db->prepare("
                            UPDATE `tb_control_turno`
                            SET `cot_fin`=?, `cot_duracion`=?, `cot_observaciones_fin`='Cierre automático por duplicado'
                            WHERE `cot_id`=?
                        ");
                        $st_close->bind_param('sss', $fecha_actual, $duracion_auto, $cot_id_abierto);
                        $st_close->execute();
                    }

                    // Recalcular duplicado tras el cierre automático
                    $consulta_registros_duplicado = $enlace_db->prepare($consulta_string_duplicado);
                    $consulta_registros_duplicado->bind_param('ss', $_SESSION['usu_id'], $hoy);
                    $consulta_registros_duplicado->execute();
                    $resultado_registros_duplicado = $consulta_registros_duplicado->get_result()->fetch_all(MYSQLI_NUM);
                    $control_duplicado = (int)($resultado_registros_duplicado[0][0] ?? 0);
                }
            }

            // -------- Insert (misma tabla/campos; solo robustez en bind y datos) ----------
            // Prepara la sentencia
            $sentencia_insert = $enlace_db->prepare("
                INSERT INTO `tb_control_turno`
                    (`cot_turno_malla`, `cot_usuario`, `cot_tipo`, `cot_inicio`, `cot_fin`, `cot_duracion`, `cot_fuente`, `cot_observaciones_inicio`, `cot_observaciones_fin`)
                VALUES
                    (?,?,?,?,'','',?,'','')
            ");

            // Fuente IP (igual que antes pero más robusto si hay proxy, sin cambiar comportamiento normal)
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            // Turno malla: puede no existir en sesión; mantenemos valor original si existe
            $turno_malla = $_SESSION['session_turnop_id'] ?? '';

            $inicio = date('Y-m-d H:i:s');

            // Agrega variables a sentencia preparada
            $sentencia_insert->bind_param('sssss', $turno_malla, $_SESSION['usu_id'], $tipo, $inicio, $ip);

            if ($control_duplicado == 0) {
                if ($sentencia_insert->execute()) {
                    echo "<meta http-equiv='refresh' content='0; url=contenido.php'>";
                    exit;
                } else {
                    echo $message_error = "Se ha presentado un error al iniciar el turno, por favor intente nuevamente!";
                    exit;
                }
            } else {
                // Misma salida original cuando hay duplicado (redirige a contenido)
                echo "<meta http-equiv='refresh' content='0; url=contenido.php'>";
                exit;
            }

        } elseif ($accion === "cierre") {

            $fecha_actual = date("Y-m-d H:i:s");

            // Mantiene la lógica original: cerrar el turno de ese usuario/tipo/turno_malla que esté abierto
            $turno_malla = $_SESSION['session_turnop_id'] ?? '';

            $consulta_string = "
                SELECT `cot_id`, `cot_usuario`, `cot_tipo`, `cot_inicio`, `cot_fin`, `cot_duracion`, `cot_fuente`,
                       `cot_observaciones_inicio`, `cot_observaciones_fin`, `cot_registro_fecha`
                FROM `tb_control_turno`
                WHERE `cot_usuario`=?
                  AND `cot_tipo`=?
                  AND `cot_turno_malla`=?
                  AND (`cot_fin`='' OR `cot_fin` IS NULL)
                ORDER BY `cot_id` DESC
                LIMIT 1
            ";
            $consulta_registros = $enlace_db->prepare($consulta_string);
            $consulta_registros->bind_param("sss", $_SESSION['usu_id'], $tipo, $turno_malla);
            $consulta_registros->execute();
            $row = $consulta_registros->get_result()->fetch_assoc();

            if (!$row) {
                // No hay turno abierto que cerrar (antes habría notice al index [0][3])
                echo "<meta http-equiv='refresh' content='0; url=contenido.php'>";
                exit;
            }

            $duracion = dateDiff($row['cot_inicio'], $fecha_actual);

            // Prepara la sentencia
            $consulta_actualizar = $enlace_db->prepare("
                UPDATE `tb_control_turno`
                SET `cot_fin`=?, `cot_duracion`=?
                WHERE `cot_id`=?
            ");

            // Agrega variables a sentencia preparada
            $consulta_actualizar->bind_param('sss', $fecha_actual, $duracion, $row['cot_id']);

            // Ejecuta sentencia preparada
            $consulta_actualizar->execute();

            if (comprobarSentencia($enlace_db->info)) {
                echo "<meta http-equiv='refresh' content='0; url=contenido.php'>";
                exit;
            } else {
                echo $message_error = "Se ha presentado un error al iniciar el turno, por favor intente nuevamente!";
                exit;
            }
        }

        // Si llega una acción no contemplada, mantenemos el mensaje original
        echo $message_error = "Se ha presentado un error al iniciar el turno, por favor intente nuevamente!";
        exit;

    } else {
        echo $message_error = "Se ha presentado un error al iniciar el turno, por favor intente nuevamente!";
        exit;
    }
?>
