<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_helpers.php';
require_once __DIR__ . '/alerta_correos_informativas.php';

function acResponsablesCaso(mysqli $db, array $caso): array
{
    $regional = (string)$caso['acc_regional'];
    $cz = trim((string)($caso['acc_centro_zonal'] ?? ''));
    $resultado = ['regional' => [], 'zonal' => []];

    $stmt = $db->prepare("SELECT acr_id,acr_nombre,acr_correo,acr_nivel,acr_regional,acr_centro_zonal FROM tb_alerta_correo_responsable WHERE acr_activo=1 AND acr_regional=? AND (acr_vigente_hasta IS NULL OR acr_vigente_hasta>=CURDATE()) ORDER BY acr_nivel,acr_nombre");
    $stmt->bind_param('s', $regional);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if ($r['acr_nivel'] === 'REGIONAL') $resultado['regional'][] = $r;
        if ($r['acr_nivel'] === 'ZONAL' && $cz !== '' && strcasecmp(trim((string)$r['acr_centro_zonal']), $cz) === 0) $resultado['zonal'][] = $r;
    }
    $stmt->close();
    return $resultado;
}

function acConstruirAddress(array $responsables): string
{
    $items=[];
    foreach ($responsables as $r) {
        $correo=trim((string)$r['acr_correo']);
        if (!filter_var($correo,FILTER_VALIDATE_EMAIL)) continue;
        $nombre=str_replace([';','|'], ' ', (string)$r['acr_nombre']);
        $items[]=$correo.'|'.$nombre;
    }
    return implode(';', array_values(array_unique($items)));
}

function acCorreoAprobacionHtml(array $caso): string
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div style="font-family:Arial,sans-serif;font-size:14px;color:#333">'
        . '<h3>Notificación de alerta ICBF</h3>'
        . '<p>Se informa la aprobación de una alerta para gestión territorial.</p>'
        . '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;max-width:760px">'
        . '<tr><th align="left">Radicado</th><td>'.$e($caso['acc_radicado']).'</td></tr>'
        . '<tr><th align="left">SIM</th><td>'.$e($caso['acc_sim']).'</td></tr>'
        . '<tr><th align="left">Regional</th><td>'.$e($caso['acc_regional']).'</td></tr>'
        . '<tr><th align="left">Centro Zonal</th><td>'.$e($caso['acc_centro_zonal']).'</td></tr>'
        . '<tr><th align="left">Categoría</th><td>'.$e($caso['acc_categoria']).'</td></tr>'
        . '<tr><th align="left">Descripción</th><td>'.nl2br($e($caso['acc_descripcion'])).'</td></tr>'
        . '<tr><th align="left">Justificación</th><td>'.nl2br($e($caso['acc_justificacion'])).'</td></tr>'
        . '</table><p>Este mensaje fue generado automáticamente por el módulo Alertas Correos.</p></div>';
}


/**
 * Encola una notificación de subsanación para el agente que registró el caso.
 * La subsanación es interna: no se notifica a responsables regionales/zonal.
 * Devuelve 0 si el creador no existe, está inactivo o no tiene correo válido.
 */
function acEncolarSubsanacionAgente(mysqli $db, array $caso, string $comentario): int
{
    $creadorId = trim((string)($caso['acc_usuario_creador'] ?? ''));
    if ($creadorId === '') return 0;

    $u = $db->prepare("SELECT usu_id,usu_nombres_apellidos,usu_correo_corporativo,usu_estado FROM tb_administrador_usuario WHERE usu_id=? LIMIT 1");
    $u->bind_param('s', $creadorId);
    $u->execute();
    $agente = $u->get_result()->fetch_assoc() ?: null;
    $u->close();

    if (!$agente || strcasecmp(trim((string)($agente['usu_estado'] ?? '')), 'Activo') !== 0) return 0;
    $correo = trim((string)($agente['usu_correo_corporativo'] ?? ''));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) return 0;

    $nombre = trim((string)($agente['usu_nombres_apellidos'] ?? '')) ?: $creadorId;
    $address = $correo . '|' . str_replace([';','|'], ' ', $nombre);
    $version = (int)($caso['acc_version'] ?? 0) + 1;
    $clave = 'SUBSANACION-' . (int)$caso['acc_id'] . '-V' . $version;

    $check = $db->prepare('SELECT acn_id,acn_nc_id FROM tb_alerta_correo_notificacion WHERE acn_clave_idempotencia=? LIMIT 1');
    $check->bind_param('s', $clave);
    $check->execute();
    $exist = $check->get_result()->fetch_assoc();
    $check->close();
    if ($exist) return (int)($exist['acn_nc_id'] ?? 0);

    $m = $db->prepare("SELECT mod_id FROM tb_configuracion_modulo WHERE mod_modulo_nombre='Alertas Correos' LIMIT 1");
    $m->execute();
    $mod = $m->get_result()->fetch_assoc();
    $m->close();
    if (!$mod) return 0;

    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $radicado = (string)($caso['acc_radicado'] ?? '');
    $asunto = substr('Alerta ICBF - Subsanación requerida - ' . $radicado, 0, 100);
    $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#333">'
        . '<h3>Subsanación requerida - Alertas Correos</h3>'
        . '<p>Hola <strong>'.$e($nombre).'</strong>,</p>'
        . '<p>El cliente solicitó una subsanación sobre la alerta <strong>'.$e($radicado).'</strong> que usted registró.</p>'
        . '<p><strong>Observación de subsanación:</strong><br>'.nl2br($e($comentario)).'</p>'
        . '<p>Ingrese al módulo <strong>Alertas Correos</strong>, revise el caso y envíelo nuevamente a revisión cuando haya realizado la corrección.</p>'
        . '<p>Este mensaje fue generado automáticamente por el módulo Alertas Correos.</p></div>';

    $modId=(int)$mod['mod_id']; $prioridad='Alta'; $setFrom=1; $cc=''; $bcc=''; $reply='';
    $imgRuta=''; $imgNombre=''; $imgTipo=''; $intentos='0'; $eliminar='0'; $estado='Pendiente'; $fechaEnvio=''; $usuario=acUsuarioActual();
    $ins=$db->prepare('INSERT INTO tb_notificaciones_central (nc_id_modulo,nc_prioridad,nc_id_set_from,nc_address,nc_cc,nc_bcc,nc_reply_to,nc_subject,nc_body,nc_embeddedimage_ruta,nc_embeddedimage_nombre,nc_embeddedimage_tipo,nc_intentos,nc_eliminar,nc_estado_envio,nc_fecha_envio,nc_usuario_registro) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->bind_param('isissssssssssssss',$modId,$prioridad,$setFrom,$address,$cc,$bcc,$reply,$asunto,$body,$imgRuta,$imgNombre,$imgTipo,$intentos,$eliminar,$estado,$fechaEnvio,$usuario);
    $ins->execute();
    $ncId=(int)$db->insert_id;
    $ins->close();

    $snapshot=json_encode([['usu_id'=>$creadorId,'nombre'=>$nombre,'correo'=>$correo]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $evento='SOLICITAR_SUBSANACION'; $nEstado='ENCOLADA'; $ahora=date('Y-m-d H:i:s');
    $n=$db->prepare('INSERT INTO tb_alerta_correo_notificacion (acn_caso_id,acn_nc_id,acn_evento,acn_clave_idempotencia,acn_to,acn_cc,acn_bcc,acn_destinatarios_snapshot,acn_asunto,acn_estado,acn_usuario,acn_fecha_encolada) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $casoId=(int)$caso['acc_id'];
    $n->bind_param('iissssssssss',$casoId,$ncId,$evento,$clave,$address,$cc,$bcc,$snapshot,$asunto,$nEstado,$usuario,$ahora);
    $n->execute();
    $n->close();
    return $ncId;
}

function acEncolarAprobacion(mysqli $db, array $caso): int
{
    if (acAlertaEsInformativa($caso)) {
        throw new RuntimeException('Esta alerta es informativa y no admite encolado de correo electrónico.');
    }
    $res = acResponsablesCaso($db, $caso);
    if (!$res['regional']) throw new RuntimeException('No existe responsable regional activo con correo válido.');
    if (trim((string)$caso['acc_centro_zonal']) !== '' && !$res['zonal']) throw new RuntimeException('No existe responsable zonal activo para el Centro Zonal del caso.');
    $todos = array_merge($res['regional'], $res['zonal']);
    $address = acConstruirAddress($todos);
    if ($address === '') throw new RuntimeException('No se pudo construir la lista de destinatarios.');

    $clave = 'APROBACION-' . (int)$caso['acc_id'] . '-V' . (int)$caso['acc_version'];
    $check=$db->prepare('SELECT acn_id,acn_nc_id FROM tb_alerta_correo_notificacion WHERE acn_clave_idempotencia=? LIMIT 1');
    $check->bind_param('s',$clave); $check->execute(); $exist=$check->get_result()->fetch_assoc(); $check->close();
    if ($exist) return (int)($exist['acn_nc_id'] ?? 0);

    $stmt=$db->prepare("SELECT mod_id FROM tb_configuracion_modulo WHERE mod_modulo_nombre='Alertas Correos' LIMIT 1");
    $stmt->execute(); $mod=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$mod) throw new RuntimeException('El módulo Alertas Correos no está registrado.');

    $modId=(int)$mod['mod_id']; $prioridad='Alta'; $setFrom=1; $cc=''; $bcc=''; $reply='';
    $asunto=substr('Alerta ICBF - '.$caso['acc_radicado'],0,100); $body=acCorreoAprobacionHtml($caso);
    $imgRuta='';$imgNombre='';$imgTipo='';$intentos='0';$eliminar='0';$estado='Pendiente';$fechaEnvio='';$usuario=acUsuarioActual();
    $ins=$db->prepare('INSERT INTO tb_notificaciones_central (nc_id_modulo,nc_prioridad,nc_id_set_from,nc_address,nc_cc,nc_bcc,nc_reply_to,nc_subject,nc_body,nc_embeddedimage_ruta,nc_embeddedimage_nombre,nc_embeddedimage_tipo,nc_intentos,nc_eliminar,nc_estado_envio,nc_fecha_envio,nc_usuario_registro) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->bind_param('isissssssssssssss',$modId,$prioridad,$setFrom,$address,$cc,$bcc,$reply,$asunto,$body,$imgRuta,$imgNombre,$imgTipo,$intentos,$eliminar,$estado,$fechaEnvio,$usuario);
    $ins->execute(); $ncId=(int)$db->insert_id; $ins->close();

    $snapshot=json_encode($todos,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $evento='APROBACION';$nEstado='ENCOLADA';$ahora=date('Y-m-d H:i:s');
    $n=$db->prepare('INSERT INTO tb_alerta_correo_notificacion (acn_caso_id,acn_nc_id,acn_evento,acn_clave_idempotencia,acn_to,acn_cc,acn_bcc,acn_destinatarios_snapshot,acn_asunto,acn_estado,acn_usuario,acn_fecha_encolada) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $n->bind_param('iissssssssss',$caso['acc_id'],$ncId,$evento,$clave,$address,$cc,$bcc,$snapshot,$asunto,$nEstado,$usuario,$ahora);
    $n->execute();$n->close(); return $ncId;
}

