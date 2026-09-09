<?php
declare(strict_types=1);

/**
 * Plantilla oficial y encolado de correo para Alertas Correos.
 *
 * Diseño:
 * - TO: responsable(s) ZONAL(ES) vigente(s).
 * - CC: responsable(s) REGIONAL(ES) vigente(s).
 * - Deduplicación de correos entre TO/CC.
 * - Cuerpo HTML autocontenido (sin imágenes externas ni CSS externo).
 * - Encolado en tb_notificaciones_central, reutilizando el robot existente.
 * - Snapshot de destinatarios antes de construir el correo.
 */

const AC_EMAIL_TEMPLATE_VERSION = 'AC-MAIL-1.0';

function acEmailH(?string $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function acEmailTexto(?string $valor): string
{
    return trim((string)$valor);
}

function acEmailNombreSeguro(string $valor): string
{
    $valor = str_replace(["\r", "\n", ';', '|'], ' ', trim($valor));
    return trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);
}

function acEmailCorreoValido(?string $correo): bool
{
    $correo = trim((string)$correo);
    return $correo !== '' && (bool)filter_var($correo, FILTER_VALIDATE_EMAIL);
}

function acEmailTipoAlertaLabel(?string $tipo): string
{
    $tipo = strtoupper(trim((string)$tipo));
    return match ($tipo) {
        'CRITICA', 'CRÍTICA' => 'Crítica',
        'ALTA' => 'Alta',
        'BAJA' => 'Baja',
        default => 'Media',
    };
}

function acEmailPrioridadCentral(?string $tipo): string
{
    $tipo = strtoupper(trim((string)$tipo));
    return match ($tipo) {
        'CRITICA', 'CRÍTICA', 'ALTA' => 'Alta',
        'BAJA' => 'Baja',
        default => 'Media',
    };
}

function acEmailAfectaLabel(?string $valor): string
{
    $v = strtoupper(trim((string)$valor));
    return match ($v) {
        'SI', 'SÍ', '1' => 'Sí',
        'NO', '0' => 'No',
        'PTE', 'PENDIENTE', 'PENDIENTE POR VALIDAR' => 'Pendiente por validar',
        default => $valor !== null && trim($valor) !== '' ? trim($valor) : 'No registrado',
    };
}

function acEmailFechaLabel(?string $valor): string
{
    $valor = trim((string)$valor);
    if ($valor === '') return 'No registrada';
    try {
        $dt = new DateTime($valor);
        return strlen($valor) <= 10 ? $dt->format('d/m/Y') : $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $valor;
    }
}

function acEmailTruncarUtf8(string $valor, int $max): string
{
    if ($max < 4) return substr($valor, 0, max(0, $max));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($valor, 'UTF-8') > $max
            ? mb_substr($valor, 0, $max - 3, 'UTF-8') . '...'
            : $valor;
    }
    $chars = preg_split('//u', $valor, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($chars)) {
        return count($chars) > $max
            ? implode('', array_slice($chars, 0, $max - 3)) . '...'
            : $valor;
    }
    return strlen($valor) > $max ? substr($valor, 0, $max - 3) . '...' : $valor;
}

/** @return array<int,array<string,mixed>> */
function acEmailConsultarResponsables(mysqli $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la consulta de responsables: ' . $db->error);
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $salida = [];
    $vistos = [];
    foreach ($filas as $fila) {
        $correo = strtolower(trim((string)($fila['acr_correo'] ?? '')));
        if (!acEmailCorreoValido($correo) || isset($vistos[$correo])) continue;
        $vistos[$correo] = true;
        $salida[] = [
            'responsable_id' => isset($fila['acr_id']) ? (int)$fila['acr_id'] : null,
            'punto_atencion_id' => isset($fila['acr_punto_atencion_id']) ? (int)$fila['acr_punto_atencion_id'] : null,
            'nivel' => strtoupper(trim((string)($fila['acr_nivel'] ?? ''))),
            'regional' => trim((string)($fila['acr_regional'] ?? '')),
            'centro_zonal' => trim((string)($fila['acr_centro_zonal'] ?? '')),
            'nombre' => trim((string)($fila['acr_nombre'] ?? $correo)),
            'correo' => $correo,
            'documento' => trim((string)($fila['acr_documento'] ?? '')),
            'tipo_responsable' => trim((string)($fila['acr_tipo_responsable'] ?? '')),
        ];
    }
    return $salida;
}

/** @return array{regional:array<int,array<string,mixed>>,zonal:array<int,array<string,mixed>>} */
function acEmailResolverDestinatariosVigentes(mysqli $db, array $caso): array
{
    $regionalId = (int)($caso['acc_regional_id'] ?? 0);
    $puntoId = (int)($caso['acc_punto_atencion_id'] ?? 0);
    $regionalTxt = trim((string)($caso['acc_regional'] ?? ''));
    $czTxt = trim((string)($caso['acc_centro_zonal'] ?? ''));

    $regional = [];
    if ($regionalId > 0) {
        $regional = acEmailConsultarResponsables(
            $db,
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel)='REGIONAL'
               AND acr_punto_atencion_id=?
             ORDER BY CASE WHEN UPPER(COALESCE(acr_tipo_responsable,''))='ENLACE_RELACION_CIUDADANO' THEN 0 ELSE 1 END,
                      acr_id DESC",
            'i',
            [$regionalId]
        );
    }
    if (!$regional && $regionalTxt !== '') {
        $regional = acEmailConsultarResponsables(
            $db,
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel)='REGIONAL'
               AND UPPER(TRIM(acr_regional))=UPPER(TRIM(?))
             ORDER BY CASE WHEN UPPER(COALESCE(acr_tipo_responsable,''))='ENLACE_RELACION_CIUDADANO' THEN 0 ELSE 1 END,
                      acr_id DESC",
            's',
            [$regionalTxt]
        );
    }

    $zonal = [];
    if ($puntoId > 0) {
        $zonal = acEmailConsultarResponsables(
            $db,
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel) IN ('ZONAL','CENTRO_ZONAL')
               AND acr_punto_atencion_id=?
             ORDER BY CASE WHEN UPPER(COALESCE(acr_tipo_responsable,''))='COORDINADOR' THEN 0 ELSE 1 END,
                      acr_id DESC",
            'i',
            [$puntoId]
        );
    }
    if (!$zonal && $regionalTxt !== '' && $czTxt !== '') {
        $zonal = acEmailConsultarResponsables(
            $db,
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel) IN ('ZONAL','CENTRO_ZONAL')
               AND UPPER(TRIM(acr_regional))=UPPER(TRIM(?))
               AND UPPER(TRIM(acr_centro_zonal))=UPPER(TRIM(?))
             ORDER BY CASE WHEN UPPER(COALESCE(acr_tipo_responsable,''))='COORDINADOR' THEN 0 ELSE 1 END,
                      acr_id DESC",
            'ss',
            [$regionalTxt, $czTxt]
        );
    }

    return ['regional' => $regional, 'zonal' => $zonal];
}

/** @return array{regional:array<int,array<string,mixed>>,zonal:array<int,array<string,mixed>>} */
function acEmailObtenerSnapshot(mysqli $db, int $casoId): array
{
    // Si el caso fue reabierto y aprobado más de una vez, usar únicamente
    // el snapshot más reciente. El trigger inserta ambos niveles con el mismo NOW().
    $stmt = $db->prepare(
        "SELECT acs_responsable_id, acs_punto_atencion_id, acs_nivel, acs_regional, acs_centro_zonal,
                acs_nombre, acs_correo, acs_documento, acs_tipo_responsable
         FROM tb_alerta_correo_destinatario_snapshot
         WHERE acs_caso_id=?
           AND acs_fecha_snapshot=(
               SELECT MAX(S2.acs_fecha_snapshot)
               FROM tb_alerta_correo_destinatario_snapshot S2
               WHERE S2.acs_caso_id=?
           )
         ORDER BY CASE WHEN UPPER(acs_nivel)='REGIONAL' THEN 0 ELSE 1 END, acs_id"
    );
    if (!$stmt) return ['regional' => [], 'zonal' => []];
    $stmt->bind_param('ii', $casoId, $casoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $out = ['regional' => [], 'zonal' => []];
    $vistos = ['regional' => [], 'zonal' => []];
    foreach ($filas as $fila) {
        $nivel = strtoupper(trim((string)$fila['acs_nivel']));
        $grupo = $nivel === 'REGIONAL' ? 'regional' : 'zonal';
        $correo = strtolower(trim((string)$fila['acs_correo']));
        if (!acEmailCorreoValido($correo) || isset($vistos[$grupo][$correo])) continue;
        $vistos[$grupo][$correo] = true;
        $out[$grupo][] = [
            'responsable_id' => $fila['acs_responsable_id'] !== null ? (int)$fila['acs_responsable_id'] : null,
            'punto_atencion_id' => $fila['acs_punto_atencion_id'] !== null ? (int)$fila['acs_punto_atencion_id'] : null,
            'nivel' => $nivel,
            'regional' => trim((string)$fila['acs_regional']),
            'centro_zonal' => trim((string)$fila['acs_centro_zonal']),
            'nombre' => trim((string)$fila['acs_nombre']),
            'correo' => $correo,
            'documento' => trim((string)$fila['acs_documento']),
            'tipo_responsable' => trim((string)$fila['acs_tipo_responsable']),
        ];
    }
    return $out;
}

function acEmailAsegurarSnapshot(mysqli $db, int $casoId, array $destinatarios): array
{
    $snapshot = acEmailObtenerSnapshot($db, $casoId);
    if ($snapshot['regional'] && $snapshot['zonal']) {
        return $snapshot;
    }

    $stmt = $db->prepare(
        "INSERT INTO tb_alerta_correo_destinatario_snapshot
         (acs_caso_id, acs_responsable_id, acs_punto_atencion_id, acs_nivel, acs_regional, acs_centro_zonal,
          acs_nombre, acs_correo, acs_documento, acs_tipo_responsable, acs_fecha_snapshot)
         VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, NULLIF(?,''), ?, ?, NULLIF(?,''), NULLIF(?,''), NOW())"
    );
    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar el snapshot de destinatarios: ' . $db->error);
    }

    $existentes = [];
    foreach (array_merge($snapshot['regional'], $snapshot['zonal']) as $e) {
        $existentes[strtoupper($e['nivel']) . '|' . strtolower($e['correo'])] = true;
    }

    foreach (['regional', 'zonal'] as $grupo) {
        foreach ($destinatarios[$grupo] as $d) {
            $clave = strtoupper((string)$d['nivel']) . '|' . strtolower((string)$d['correo']);
            if (isset($existentes[$clave])) continue;
            $rid = (int)($d['responsable_id'] ?? 0);
            $pid = (int)($d['punto_atencion_id'] ?? 0);
            $nivel = (string)$d['nivel'];
            $regional = (string)$d['regional'];
            $cz = (string)$d['centro_zonal'];
            $nombre = (string)$d['nombre'];
            $correo = (string)$d['correo'];
            $documento = (string)$d['documento'];
            $tipo = (string)$d['tipo_responsable'];
            $stmt->bind_param('iiisssssss', $casoId, $rid, $pid, $nivel, $regional, $cz, $nombre, $correo, $documento, $tipo);
            $stmt->execute();
        }
    }
    $stmt->close();

    $snapshot = acEmailObtenerSnapshot($db, $casoId);
    if (!$snapshot['regional'] || !$snapshot['zonal']) {
        throw new RuntimeException('No fue posible consolidar el snapshot regional y zonal del caso.');
    }
    return $snapshot;
}

function acEmailFormatoCentral(array $personas): string
{
    $partes = [];
    $vistos = [];
    foreach ($personas as $p) {
        $correo = strtolower(trim((string)($p['correo'] ?? '')));
        if (!acEmailCorreoValido($correo) || isset($vistos[$correo])) continue;
        $vistos[$correo] = true;
        $nombre = acEmailNombreSeguro((string)($p['nombre'] ?? $correo));
        $partes[] = $correo . '|' . ($nombre !== '' ? $nombre : $correo);
    }
    return implode(';', $partes);
}

function acEmailFila(string $label, string $valor): string
{
    return '<tr>'
        . '<td style="width:34%;padding:10px 12px;background:#4CAF50;color:#ffffff;font-weight:700;border-bottom:1px solid #ffffff;vertical-align:top;">' . acEmailH($label) . '</td>'
        . '<td style="width:66%;padding:10px 12px;background:#f5f7f6;color:#263238;border-bottom:1px solid #e5e9e7;vertical-align:top;">' . acEmailH($valor !== '' ? $valor : 'No registrado') . '</td>'
        . '</tr>';
}

function acEmailBloqueTexto(string $titulo, ?string $texto): string
{
    $texto = trim((string)$texto);
    if ($texto === '') return '';
    return '<tr><td style="padding:0 28px 20px 28px;">'
        . '<div style="font-size:13px;font-weight:700;color:#4CAF50;margin:0 0 7px 0;">' . acEmailH($titulo) . '</div>'
        . '<div style="font-size:13px;line-height:1.6;color:#37474f;background:#f7f9f8;border:1px solid #e1e8e4;border-left:4px solid #4CAF50;border-radius:4px;padding:12px 14px;">'
        . nl2br(acEmailH($texto), false)
        . '</div></td></tr>';
}

/**
 * @return array{version:string,subject:string,body:string,to:string,cc:string,to_personas:array,cc_personas:array}
 */
function acEmailConstruirPlantilla(array $caso, array $destinatarios, string $fechaAprobacion): array
{
    if (!$destinatarios['regional']) {
        throw new RuntimeException('No existe un responsable regional vigente con correo válido.');
    }
    if (!$destinatarios['zonal']) {
        throw new RuntimeException('No existe un responsable zonal vigente con correo válido.');
    }

    // TO = Zonal. CC = Regional. La deduplicación ocurre después de comprobar que ambos roles existen.
    $toPersonas = $destinatarios['zonal'];
    $emailsTo = [];
    foreach ($toPersonas as $p) $emailsTo[strtolower((string)$p['correo'])] = true;
    $ccPersonas = [];
    foreach ($destinatarios['regional'] as $p) {
        if (!isset($emailsTo[strtolower((string)$p['correo'])])) $ccPersonas[] = $p;
    }

    $to = acEmailFormatoCentral($toPersonas);
    $cc = acEmailFormatoCentral($ccPersonas);
    if ($to === '') throw new RuntimeException('No fue posible construir la lista de destinatarios zonales.');

    $radicado = acEmailTexto($caso['acc_radicado'] ?? '');
    $sim = acEmailTexto($caso['acc_sim'] ?? '');
    $regional = acEmailTexto($caso['acc_regional'] ?? '');
    $cz = acEmailTexto($caso['acc_centro_zonal'] ?? '');
    $identificador = $sim !== '' ? 'SIM ' . $sim : $radicado;
    $subject = '[ICBF] Alerta aprobada | ' . $identificador . ($cz !== '' ? ' | ' . $cz : '');
    $subject = acEmailTruncarUtf8($subject, 100);

    $filas = '';
    $filas .= acEmailFila('Radicado de alerta', $radicado);
    if ($sim !== '') $filas .= acEmailFila('Radicado SIM', $sim);
    $filas .= acEmailFila('Tipo de alerta', acEmailTipoAlertaLabel($caso['acc_tipo_alerta'] ?? ''));
    $filas .= acEmailFila('Regional', $regional);
    $filas .= acEmailFila('Centro Zonal / Punto', $cz);
    $filas .= acEmailFila('Fecha de alerta', acEmailFechaLabel($caso['acc_fecha_alerta'] ?? ''));
    $filas .= acEmailFila('Fecha de atención', acEmailFechaLabel($caso['acc_fecha_atencion'] ?? ''));
    if (acEmailTexto($caso['acc_categoria'] ?? '') !== '') $filas .= acEmailFila('Categoría', (string)$caso['acc_categoria']);
    if (acEmailTexto($caso['acc_subcategoria'] ?? '') !== '') $filas .= acEmailFila('Subcategoría', (string)$caso['acc_subcategoria']);
    if (acEmailTexto($caso['acc_afecta_linea_tecnica'] ?? '') !== '') $filas .= acEmailFila('¿Afecta línea técnica?', acEmailAfectaLabel($caso['acc_afecta_linea_tecnica'] ?? ''));
    $filas .= acEmailFila('Fecha de aprobación', acEmailFechaLabel($fechaAprobacion));

    $body = '<!doctype html><html><body style="margin:0;padding:0;background:#eef2ef;font-family:Lato,Arial,Helvetica,sans-serif;color:#263238;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#eef2ef;padding:24px 10px;"><tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="680" style="width:100%;max-width:680px;background:#ffffff;border-collapse:separate;border-spacing:0;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">'
        . '<tr><td style="background:#4CAF50;padding:22px 28px;color:#ffffff;text-align:center;">'
        . '<div style="font-size:20px;font-weight:800;line-height:1.2;text-align:center;">ICBF · Gestión de Alertas sobre Encuestas</div>'
        . '<div style="font-size:12px;opacity:.9;margin-top:5px;text-align:center;">Notificación territorial de alerta aprobada</div>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 28px 12px 28px;font-size:13px;line-height:1.6;color:#37474f;">'
        . '<strong>Cordial saludo,</strong><br><br>'
        . 'Se informa que una alerta asociada a una atención registrada en el Sistema de Información Misional (SIM) fue revisada y <strong>aprobada</strong> por el equipo de gestión. A continuación se relaciona la información necesaria para su conocimiento y gestión.'
        . '</td></tr>'
        . '<tr><td style="padding:8px 28px 20px 28px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:12px;border:1px solid #dfe6e2;">' . $filas . '</table>'
        . '</td></tr>'
        . acEmailBloqueTexto('Descripción de la alerta', $caso['acc_descripcion'] ?? '')
        . acEmailBloqueTexto('Justificación / concepto técnico', $caso['acc_justificacion'] ?? '')
        . acEmailBloqueTexto('Observación adicional', $caso['acc_observacion'] ?? '')
        . '<tr><td style="padding:0 28px 22px 28px;">'
        . '<div style="font-size:13px;line-height:1.6;color:#37474f;background:#edf7f0;border:1px solid #cfe4d5;border-radius:4px;padding:12px 14px;">'
        . '<strong style="color:#4CAF50;">Acción requerida:</strong> agradecemos revisar la situación reportada y adelantar las acciones que correspondan de acuerdo con los procedimientos internos aplicables.'
        . '</div></td></tr>'
        . '<tr><td style="background:#2f3432;color:#ffffff;padding:14px 28px;font-size:11px;line-height:1.5;text-align:center;">'
        . 'IQ-ICBF | Gestión Integrada de Servicios<br>'
        . 'Esta es una notificación automática generada por el módulo Alertas Correos. Por favor no responda este mensaje.'
        . '</td></tr>'
        . '<tr><td style="padding:10px 28px 16px 28px;font-size:10px;color:#78909c;text-align:right;">Plantilla ' . acEmailH(AC_EMAIL_TEMPLATE_VERSION) . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    return [
        'version' => AC_EMAIL_TEMPLATE_VERSION,
        'subject' => $subject,
        'body' => $body,
        'to' => $to,
        'cc' => $cc,
        'to_personas' => $toPersonas,
        'cc_personas' => $ccPersonas,
    ];
}

function acEmailModuloId(mysqli $db): int
{
    $stmt = $db->prepare("SELECT mod_id FROM tb_configuracion_modulo WHERE mod_modulo_nombre='Alertas Correos' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($fila && (int)$fila['mod_id'] > 0) return (int)$fila['mod_id'];
    }
    return 26;
}

function acEmailRemitenteId(mysqli $db): int
{
    foreach ([1, 2] as $preferido) {
        $stmt = $db->prepare('SELECT ncr_id FROM tb_notificaciones_central_remitente WHERE ncr_id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $preferido);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($fila) return (int)$fila['ncr_id'];
        }
    }
    $res = $db->query('SELECT ncr_id FROM tb_notificaciones_central_remitente ORDER BY ncr_id LIMIT 1');
    $fila = $res ? $res->fetch_assoc() : null;
    if (!$fila) throw new RuntimeException('No existe un remitente configurado en tb_notificaciones_central_remitente.');
    return (int)$fila['ncr_id'];
}

function acEmailEncolarCentral(mysqli $db, array $caso, array $plantilla, string $usuario): int
{
    $moduloId = acEmailModuloId($db);
    $remitenteId = acEmailRemitenteId($db);
    $prioridad = acEmailPrioridadCentral($caso['acc_tipo_alerta'] ?? 'MEDIA');
    $to = (string)$plantilla['to'];
    $cc = (string)$plantilla['cc'];
    $subject = (string)$plantilla['subject'];
    $body = (string)$plantilla['body'];

    $stmt = $db->prepare(
        "INSERT INTO tb_notificaciones_central
         (nc_id_modulo, nc_prioridad, nc_id_set_from, nc_address, nc_cc, nc_bcc, nc_reply_to,
          nc_subject, nc_body, nc_embeddedimage_ruta, nc_embeddedimage_nombre, nc_embeddedimage_tipo,
          nc_intentos, nc_eliminar, nc_estado_envio, nc_fecha_envio, nc_usuario_registro)
         VALUES (?, ?, ?, ?, ?, '', '', ?, ?, '', '', '', '0', '0', 'Pendiente', '', ?)"
    );
    if (!$stmt) throw new RuntimeException('No fue posible preparar la notificación central: ' . $db->error);
    $stmt->bind_param('isisssss', $moduloId, $prioridad, $remitenteId, $to, $cc, $subject, $body, $usuario);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    if ($id <= 0) throw new RuntimeException('La notificación central no devolvió identificador.');
    return $id;
}

/** @return array<string,array<string,mixed>> */
function acEmailDescribirTabla(mysqli $db, string $tabla): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) throw new InvalidArgumentException('Tabla inválida.');
    $res = $db->query("SHOW COLUMNS FROM `{$tabla}`");
    if (!$res) return [];
    $out = [];
    while ($f = $res->fetch_assoc()) $out[(string)$f['Field']] = $f;
    return $out;
}

function acEmailValorGenericoParaColumna(array $col): mixed
{
    $type = strtolower((string)($col['Type'] ?? ''));
    if (preg_match('/int|decimal|float|double|bit/', $type)) return 0;
    if (preg_match('/date|time|timestamp/', $type)) return date('Y-m-d H:i:s');
    return '';
}

/**
 * Inserta de forma compatible en tablas ya existentes: completa columnas NOT NULL
 * sin default que pudieran pertenecer a una versión anterior del módulo.
 */
function acEmailInsertFlexible(mysqli $db, string $tabla, array $valores): int
{
    $cols = acEmailDescribirTabla($db, $tabla);
    if (!$cols) throw new RuntimeException("No existe o no se pudo describir {$tabla}.");

    $insert = [];
    foreach ($cols as $nombre => $meta) {
        $extra = strtolower((string)($meta['Extra'] ?? ''));
        if (str_contains($extra, 'auto_increment')) continue;
        if (array_key_exists($nombre, $valores)) {
            $insert[$nombre] = $valores[$nombre];
            continue;
        }
        $esRequerida = strtoupper((string)($meta['Null'] ?? 'YES')) === 'NO'
            && ($meta['Default'] ?? null) === null
            && !str_contains($extra, 'default_generated');
        if ($esRequerida) $insert[$nombre] = acEmailValorGenericoParaColumna($meta);
    }

    if (!$insert) throw new RuntimeException("No hay columnas compatibles para insertar en {$tabla}.");
    $nombres = array_keys($insert);
    $placeholders = implode(',', array_fill(0, count($nombres), '?'));
    $sql = 'INSERT INTO `' . $tabla . '` (`' . implode('`,`', $nombres) . '`) VALUES (' . $placeholders . ')';
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException("No fue posible preparar INSERT en {$tabla}: " . $db->error);

    $types = '';
    $params = [];
    foreach ($insert as $v) {
        if (is_int($v)) $types .= 'i';
        elseif (is_float($v)) $types .= 'd';
        else $types .= 's';
        $params[] = $v === null ? '' : $v;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function acEmailRegistrarAuditoriaLocal(mysqli $db, int $casoId, int $ncId, array $plantilla, string $usuario, string $fecha): void
{
    // La migración 008 garantiza estas columnas; el insert flexible conserva compatibilidad
    // con posibles columnas requeridas de versiones anteriores.
    acEmailInsertFlexible($db, 'tb_alerta_correo_notificacion', [
        'acn_caso_id' => $casoId,
        'acn_notificacion_central_id' => $ncId,
        'acn_template_version' => (string)$plantilla['version'],
        'acn_to' => (string)$plantilla['to'],
        'acn_cc' => (string)$plantilla['cc'],
        'acn_asunto' => (string)$plantilla['subject'],
        'acn_cuerpo' => (string)$plantilla['body'],
        'acn_estado' => 'PENDIENTE',
        'acn_usuario' => $usuario,
        'acn_fecha' => $fecha,
    ]);
}

function acEmailRegistrarHistorialAprobacion(mysqli $db, int $casoId, string $estadoAnterior, int $ncId, array $plantilla, string $usuario): void
{
    $comentario = 'Caso aprobado y notificación oficial encolada. Notificación central #' . $ncId
        . '. Plantilla ' . (string)$plantilla['version']
        . '. TO: ' . (string)$plantilla['to']
        . ((string)$plantilla['cc'] !== '' ? '. CC: ' . (string)$plantilla['cc'] : '.');

    acEmailInsertFlexible($db, 'tb_alerta_correo_historial', [
        'ach_caso_id' => $casoId,
        'ach_accion' => 'APROBAR_Y_NOTIFICAR',
        'ach_estado_anterior' => $estadoAnterior,
        'ach_estado_nuevo' => 'APROBADO',
        'ach_comentario' => $comentario,
        'ach_usuario' => $usuario,
        'ach_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ach_fecha' => date('Y-m-d H:i:s'),
    ]);
}
