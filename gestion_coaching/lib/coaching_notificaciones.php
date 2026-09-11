<?php
declare(strict_types=1);

/**
 * gestion_coaching/lib/coaching_notificaciones.php
 *
 * Integra Coaching con la infraestructura de correo YA EXISTENTE del
 * sistema: `tb_notificaciones_central` + `tb_notificaciones_central_remitente`,
 * consumida por el robot real `config_robots/notificaciones_central.php`
 * (que ya corre por cron vía `notificaciones_central.sh`).
 *
 * NO se crea ningún robot nuevo ni ninguna tabla nueva de notificaciones:
 * esta capa solo hace INSERT en la tabla central, con el mismo formato que
 * ya usa el resto del sistema (direcciones "correo|Nombre" separadas por
 * ";", estado 'Pendiente'). El robot existente recoge y envía sin que se
 * le haya tocado ni una línea.
 */

/** Correo y nombre de un usuario, para armar destinatarios. */
function coachingCorreoYNombre(mysqli $enlace_db, string $usu_id): ?array
{
    $consulta = $enlace_db->prepare(
        "SELECT `usu_correo_corporativo`, `usu_nombres_apellidos` FROM `tb_administrador_usuario` WHERE `usu_id` = ? LIMIT 1"
    );
    $consulta->bind_param('s', $usu_id);
    $consulta->execute();
    $fila = $consulta->get_result()->fetch_assoc();
    if (!$fila || empty($fila['usu_correo_corporativo'])) {
        return null;
    }
    return ['correo' => $fila['usu_correo_corporativo'], 'nombre' => $fila['usu_nombres_apellidos']];
}

/**
 * URL real y absoluta al detalle del paquete en la plataforma — mismo
 * patrón que ya usa el resto del sistema para armar enlaces absolutos
 * (ver recuperar_contrasena.php / recuperar_contrasena_confirmar.php):
 * primero variable de entorno APP_URL/BASE_URL si está configurada,
 * si no, se arma a partir de $_SERVER['HTTP_HOST'] con
 * 'modulocalidad.grupoasd.com' como último respaldo. Antes esto era un
 * texto de relleno fijo ("enlace disponible en la plataforma..."), sin
 * ningún link real — el destinatario tenía que ir a buscar el paquete
 * por su cuenta.
 */
function coachingUrlPaquete(string $gcp_id): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'modulocalidad.grupoasd.com';
    $base = getenv('APP_URL') ?: (getenv('BASE_URL') ?: ('https://' . $host));
    if ($base === '') {
        $base = 'https://modulocalidad.grupoasd.com';
    }
    if (!preg_match('~^https?://~i', $base)) {
        $base = 'https://' . $base;
    }
    return rtrim($base, '/') . '/gestion_coaching/gestion_coaching_ver.php?reg=' . base64_encode($gcp_id);
}

/**
 * Plantilla HTML profesional para los correos de Coaching — mismos
 * colores corporativos ya usados en el resto del módulo (encabezado
 * #156082, igual que el título de los PDF generados; botón de acción
 * #4CAF50, igual que los botones principales de la plataforma).
 *
 * Estructura en tablas (no flexbox/grid) a propósito: es el estándar
 * real para que el HTML se vea correctamente en clientes de correo
 * (Outlook de escritorio en particular no soporta CSS moderno).
 *
 * @param string[] $parrafos Cada elemento es un párrafo de texto (ya en HTML simple, sin <p>).
 */
function coachingPlantillaCorreoHtml(
    string $titulo,
    string $nombre_destinatario,
    array $parrafos,
    string $url_accion,
    string $texto_boton
): string {
    // #4CAF50 es el color PRINCIPAL de la plataforma (confirmado por el
    // usuario) — encabezado y botón usan exactamente el mismo verde.
    $color_primario = '#4CAF50';
    $color_acento = '#4CAF50';
    $color_texto = '#1A1A1A';
    $color_texto_secundario = '#6E6E6E';

    $parrafos_html = '';
    foreach ($parrafos as $parrafo) {
        $parrafos_html .= '<p style="margin:0 0 14px; font-size:14px; line-height:1.6; color:' . $color_texto . ';">' . $parrafo . '</p>';
    }

    return '
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F2F2F2; padding:24px 0; font-family:Arial, Helvetica, sans-serif;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF; border-radius:8px; overflow:hidden;">
                    <tr>
                        <td style="background-color:' . $color_primario . '; padding:22px 30px;">
                            <span style="color:#FFFFFF; font-size:11px; letter-spacing:1px; text-transform:uppercase; opacity:.85; font-family:Arial, Helvetica, sans-serif;">IQ-ICBF &middot; Gesti&oacute;n Integrada de Servicios &middot; Coaching</span><br>
                            <span style="color:#FFFFFF; font-size:19px; font-weight:bold; font-family:Arial, Helvetica, sans-serif;">' . htmlspecialchars($titulo) . '</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px;">
                            <p style="margin:0 0 14px; font-size:14px; color:' . $color_texto . '; font-family:Arial, Helvetica, sans-serif;">Hola <strong>' . htmlspecialchars($nombre_destinatario) . '</strong>,</p>
                            ' . $parrafos_html . '
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px auto 8px;">
                                <tr>
                                    <td style="background-color:' . $color_acento . '; border-radius:5px;">
                                        <a href="' . htmlspecialchars($url_accion) . '" style="display:inline-block; padding:12px 30px; color:#FFFFFF; text-decoration:none; font-size:14px; font-weight:bold; font-family:Arial, Helvetica, sans-serif;">' . htmlspecialchars($texto_boton) . '</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:20px 0 0; font-size:11px; color:' . $color_texto_secundario . '; word-break:break-all; font-family:Arial, Helvetica, sans-serif;">Si el bot&oacute;n no funciona, copie y pegue este enlace en su navegador:<br>' . htmlspecialchars($url_accion) . '</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#F2F2F2; padding:16px 30px; text-align:center;">
                            <p style="margin:0; font-size:11px; color:' . $color_texto_secundario . '; font-family:Arial, Helvetica, sans-serif;">Este es un mensaje autom&aacute;tico del m&oacute;dulo Coaching &mdash; IQ-ICBF. No responda a este correo.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>';
}

/**
 * Inserta una notificación en la tabla central. Reutiliza el remitente ya
 * configurado en el sistema (toma el primero activo — si en tu ambiente
 * hay varios remitentes para propósitos distintos, dime el `ncr_id` exacto
 * a usar para Coaching y lo fijo aquí en vez de tomar "el primero").
 */
function coachingCrearNotificacionCentral(
    mysqli $enlace_db,
    string $prioridad,
    string $correo_destino,
    string $nombre_destino,
    string $asunto,
    string $cuerpo_html,
    string $usu_id_registro
): bool {
    // Módulo real ya sembrado en tb_configuracion_modulo (migración 007).
    $consulta_modulo = $enlace_db->prepare("SELECT `mod_id` FROM `tb_configuracion_modulo` WHERE `mod_modulo_nombre` = 'Coaching' LIMIT 1");
    $consulta_modulo->execute();
    $modulo = $consulta_modulo->get_result()->fetch_assoc();
    if (!$modulo) {
        return false; // Módulo no sembrado todavía; no se puede asociar nc_id_modulo.
    }

    // Remitente estándar del sistema — confirmado en el código real
    // (gestion_calidad_monitoreo_refutar.php usa literalmente
    // `$nc_id_set_from = "1"`), no una heurística de "el primero que haya".
    $id_set_from = '1';

    $direccion = $correo_destino . '|' . $nombre_destino;

    $insertar = $enlace_db->prepare(
        "INSERT INTO `tb_notificaciones_central`
            (`nc_id_modulo`, `nc_prioridad`, `nc_id_set_from`, `nc_address`, `nc_cc`, `nc_bcc`, `nc_reply_to`,
             `nc_subject`, `nc_body`, `nc_embeddedimage_ruta`, `nc_embeddedimage_nombre`, `nc_embeddedimage_tipo`,
             `nc_intentos`, `nc_eliminar`, `nc_estado_envio`, `nc_fecha_envio`, `nc_usuario_registro`)
         VALUES (?, ?, ?, ?, '', '', '', ?, ?, '', '', '', '0', '0', 'Pendiente', '', ?)"
    );
    $insertar->bind_param(
        'issssss',
        $modulo['mod_id'],
        $prioridad,
        $id_set_from,
        $direccion,
        $asunto,
        $cuerpo_html,
        $usu_id_registro
    );
    return (bool) $insertar->execute();
}

/**
 * Punto único que decide QUÉ correo enviar según a qué estado quedó el
 * paquete tras una transición exitosa. Se llama desde
 * coaching_transiciones.php, nunca directamente desde las pantallas —así
 * la notificación queda garantizada sin importar por cuál pantalla se
 * disparó la transición.
 *
 * Nunca lanza excepción hacia el llamador: si la notificación falla (SMTP
 * mal configurado, correo vacío, etc.), el cambio de estado YA ocurrió y
 * NO debe revertirse por un problema de notificaciones — se audita y sigue.
 */
function coachingNotificarPorTransicion(mysqli $enlace_db, string $gcp_id, string $gce_codigo_destino, array $paquete): void
{
    try {
        $url_paquete = coachingUrlPaquete($gcp_id);

        // Ningún tipo 100% interno (Escalamiento Disciplinario, No
        // Renovación) debe notificarse por correo a la persona afectada
        // (gcp_agente_id) — son documentación interna del supervisor/
        // Coordinador, el mismo criterio que ya aplica en el resto del
        // módulo (ver 'ESCALAMIENTO_DISCIPLINARIO'/'NO_RENOVACION' en
        // gestion_coaching_retroalimentacion.php, y la exclusión de
        // visibilidad de Escalamiento Disciplinario en
        // usuarioPuedeVerPaquete(), lib/coaching_seguridad.php). Antes
        // solo se excluía Escalamiento Disciplinario — No Renovación
        // seguía mandando correos reales al agente aunque el resto del
        // sistema ya lo tratara como interno.
        $tipos_100_internos = ['ESCALAMIENTO_DISCIPLINARIO', 'NO_RENOVACION'];
        $consulta_tipo = $enlace_db->prepare(
            "SELECT T.`gct_codigo` FROM `tb_gestion_coaching_paquete` AS P
             LEFT JOIN `tb_gestion_coaching_tipo` AS T ON P.`gcp_tipo_id` = T.`gct_id`
             WHERE P.`gcp_id` = ? LIMIT 1"
        );
        $consulta_tipo->bind_param('s', $gcp_id);
        $consulta_tipo->execute();
        $fila_tipo = $consulta_tipo->get_result()->fetch_assoc();
        $es_tipo_interno = in_array($fila_tipo['gct_codigo'] ?? '', $tipos_100_internos, true);

        // Estados cuya notificación va dirigida al AGENTE/COACHEADO
        // (gcp_agente_id) — estos son los que se omiten para los tipos
        // 100% internos. Los que van al supervisor (PENDIENTE_CIERRE,
        // RECHAZADO) no se tocan: él sí debe enterarse, es quien lo gestiona.
        if ($es_tipo_interno && in_array($gce_codigo_destino, ['PENDIENTE_AGENTE', 'PENDIENTE_FIRMA_AGENTE', 'CERRADO'], true)) {
            return;
        }

        switch ($gce_codigo_destino) {
            case 'PENDIENTE_AGENTE':
                $destino = coachingCorreoYNombre($enlace_db, $paquete['gcp_agente_id']);
                if ($destino) {
                    coachingCrearNotificacionCentral(
                        $enlace_db, 'Media', $destino['correo'], $destino['nombre'],
                        "Coaching {$gcp_id}: tiene una retroalimentación pendiente de respuesta",
                        coachingPlantillaCorreoHtml(
                            'Retroalimentación pendiente de respuesta',
                            $destino['nombre'],
                            [
                                "Su supervisor registró una retroalimentación de coaching (paquete <strong>{$gcp_id}</strong>) que requiere su respuesta.",
                                'Ingrese a la plataforma para revisarla y responderla dentro del plazo asignado.',
                            ],
                            $url_paquete,
                            'Responder ahora'
                        ),
                        'SISTEMA'
                    );
                }
                break;

            case 'PENDIENTE_FIRMA_AGENTE':
                $destino = coachingCorreoYNombre($enlace_db, $paquete['gcp_agente_id']);
                if ($destino) {
                    coachingCrearNotificacionCentral(
                        $enlace_db, 'Media', $destino['correo'], $destino['nombre'],
                        "Coaching {$gcp_id}: documento listo para su firma",
                        coachingPlantillaCorreoHtml(
                            'Documento listo para firma',
                            $destino['nombre'],
                            [
                                "El documento de su paquete de coaching <strong>{$gcp_id}</strong> ya está listo para su firma electrónica.",
                                'Ingrese a la plataforma para revisarlo y firmarlo.',
                            ],
                            $url_paquete,
                            'Firmar documento'
                        ),
                        'SISTEMA'
                    );
                }
                break;

            case 'PENDIENTE_CIERRE':
                $destino = coachingCorreoYNombre($enlace_db, $paquete['gcp_supervisor_id']);
                if ($destino) {
                    coachingCrearNotificacionCentral(
                        $enlace_db, 'Baja', $destino['correo'], $destino['nombre'],
                        "Coaching {$gcp_id}: agente respondió, listo para cierre",
                        coachingPlantillaCorreoHtml(
                            'Listo para cierre',
                            $destino['nombre'],
                            [
                                "El agente ya firmó el documento del paquete de coaching <strong>{$gcp_id}</strong>.",
                                'Puede revisarlo y cerrarlo cuando lo considere pertinente.',
                            ],
                            $url_paquete,
                            'Revisar y cerrar'
                        ),
                        'SISTEMA'
                    );
                }
                break;

            case 'CERRADO':
                $destino = coachingCorreoYNombre($enlace_db, $paquete['gcp_agente_id']);
                if ($destino) {
                    coachingCrearNotificacionCentral(
                        $enlace_db, 'Baja', $destino['correo'], $destino['nombre'],
                        "Coaching {$gcp_id}: paquete cerrado",
                        coachingPlantillaCorreoHtml(
                            'Paquete de coaching cerrado',
                            $destino['nombre'],
                            [
                                "Su paquete de coaching <strong>{$gcp_id}</strong> fue cerrado.",
                                'Gracias por su participación en el proceso.',
                            ],
                            $url_paquete,
                            'Ver detalle'
                        ),
                        'SISTEMA'
                    );
                }
                break;

            case 'RECHAZADO':
                $destino = coachingCorreoYNombre($enlace_db, $paquete['gcp_supervisor_id']);
                if ($destino) {
                    coachingCrearNotificacionCentral(
                        $enlace_db, 'Media', $destino['correo'], $destino['nombre'],
                        "Coaching {$gcp_id}: paquete rechazado",
                        coachingPlantillaCorreoHtml(
                            'Paquete rechazado',
                            $destino['nombre'],
                            [
                                "El paquete de coaching <strong>{$gcp_id}</strong> fue rechazado.",
                                'Revise el historial del paquete para más detalle.',
                            ],
                            $url_paquete,
                            'Ver historial'
                        ),
                        'SISTEMA'
                    );
                }
                break;

            // ASIGNADO, PENDIENTE_SUPERVISOR, EN_SEGUIMIENTO, ANULADO: sin
            // notificación por correo (el propio actor disparó la
            // transición, no hace falta avisarle a sí mismo).
        }
    } catch (Throwable $e) {
        if (function_exists('registrarErrorCoaching')) {
            registrarErrorCoaching($enlace_db, $gcp_id, 'Fallo al crear notificación por correo: ' . $e->getMessage());
        }
    }
}
