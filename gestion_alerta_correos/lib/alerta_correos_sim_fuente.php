<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_territorio.php';
require_once __DIR__ . '/alerta_correos_importacion_versionada.php';

/**
 * Importación no destructiva de "BBDD encuestas para notificar a territorio.xlsx".
 * La tabla fuente pertenece exclusivamente a Alertas Correos; no se escriben datos
 * en tb_gestion_encuesta_radicado ni en tablas del módulo de Encuestas.
 */

function acSimFuenteExcelFecha(?string $valor, bool $conHora = false): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    if (is_numeric($valor)) {
        $serial = (float)$valor;
        if ($serial <= 0) {
            return null;
        }
        $dias = (int)floor($serial);
        $fraccion = $serial - $dias;
        $base = new DateTimeImmutable('1899-12-30 00:00:00');
        $fecha = $base->modify('+' . $dias . ' days');
        if ($conHora && $fraccion > 0) {
            $segundos = (int)round($fraccion * 86400);
            $fecha = $fecha->modify('+' . $segundos . ' seconds');
        }
        return $conHora ? $fecha->format('Y-m-d H:i:s') : $fecha->format('Y-m-d');
    }

    $formatos = $conHora
        ? ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i']
        : ['Y-m-d', 'd/m/Y', 'd-m-Y'];

    foreach ($formatos as $formato) {
        $dt = DateTimeImmutable::createFromFormat($formato, $valor);
        if ($dt instanceof DateTimeImmutable) {
            return $conHora ? $dt->format('Y-m-d H:i:s') : $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($valor);
    if ($ts === false) {
        return null;
    }
    return $conHora ? date('Y-m-d H:i:s', $ts) : date('Y-m-d', $ts);
}

function acSimFuenteNormalizarPunto(string $valor): string
{
    $clave = acTerritorioNormalizarClave($valor);
    $clave = preg_replace('/^C\s+Z\s+/', 'CZ ', $clave) ?? $clave;
    $clave = preg_replace('/^CENTRO\s+ZONAL\s+/', 'CZ ', $clave) ?? $clave;
    return trim(preg_replace('/\s+/', ' ', $clave) ?? $clave);
}

function acSimFuentePuntoCanonico(string $regional, string $punto): string
{
    $r = acTerritorioNormalizarClave($regional);
    $p = acSimFuenteNormalizarPunto($punto);

    $alias = [
        'ANTIOQUIA|CZ INTEGRAL NORORIENTAL' => 'CZ NORORIENTAL',
        'ANTIOQUIA|CZ INTEGRAL NOROCCIDENTAL' => 'CZ NOROCCIDENTAL',
        'RISARALDA|CZ DOS QUEBRADAS' => 'CZ DOSQUEBRADAS',
        'CORDOBA|CZ 1 MONTERIA' => 'CZ MONTERIA',
        'CORDOBA|CZ PLANETARICA' => 'CZ PLANETA RICA',
        'BOGOTA|CZ SAN CRISTOBAL SUR' => 'CZ SAN CRISTOBAL',
        'GUAVIARE|CZ SAN JOSE DE GUAVIARE' => 'CZ SAN JOSE DEL GUAVIARE',
        'BOLIVAR|CZ EL CARMEN DE BOLIVAR' => 'CZ CARMEN DE BOLIVAR',
    ];

    return $alias[$r . '|' . $p] ?? $p;
}

function acSimFuenteMapearTerritorio(mysqli $db, string $regional, string $punto): array
{
    $regionalCanonica = function_exists('acImportacionRegionalCanonica')
        ? acImportacionRegionalCanonica($regional)
        : acTerritorioNormalizarClave($regional);

    if ($regionalCanonica === '') {
        return ['regional_id' => 0, 'punto_id' => 0, 'estado' => 'NO_MAPEADO'];
    }

    $stmt = $db->prepare(
        "SELECT acp_id, acp_nombre FROM tb_alerta_correo_punto_atencion
         WHERE acp_tipo='REGIONAL' AND acp_activo=1 AND acp_regional_clave=? LIMIT 1"
    );
    $stmt->bind_param('s', $regionalCanonica);
    $stmt->execute();
    $reg = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$reg) {
        return ['regional_id' => 0, 'punto_id' => 0, 'estado' => 'NO_MAPEADO'];
    }

    $regionalId = (int)$reg['acp_id'];
    $puntoClave = acSimFuentePuntoCanonico($regionalCanonica, $punto);

    // Algunas filas históricas están marcadas como "REGIONAL CAUCA", etc.
    // Se conserva el radicado y se asocia a la Regional, pero se deja sin CZ.
    if ($puntoClave === 'REGIONAL ' . $regionalCanonica || $puntoClave === $regionalCanonica) {
        return ['regional_id' => $regionalId, 'punto_id' => 0, 'estado' => 'REGIONAL_SOLAMENTE'];
    }

    $stmt = $db->prepare(
        "SELECT acp_id FROM tb_alerta_correo_punto_atencion
         WHERE acp_tipo='CENTRO_ZONAL'
           AND acp_activo=1
           AND acp_padre_id=?
           AND acp_nombre_clave=?
         LIMIT 1"
    );
    $stmt->bind_param('is', $regionalId, $puntoClave);
    $stmt->execute();
    $cz = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$cz) {
        // Segundo intento por normalización flexible de nombres del catálogo.
        $puntos = acTerritorioListarPuntosRegional($db, $regionalId);
        foreach ($puntos as $candidato) {
            if (acSimFuenteNormalizarPunto((string)$candidato['acp_nombre']) === $puntoClave) {
                $cz = ['acp_id' => (int)$candidato['acp_id']];
                break;
            }
        }
    }

    return $cz
        ? ['regional_id' => $regionalId, 'punto_id' => (int)$cz['acp_id'], 'estado' => 'MAPEADO']
        : ['regional_id' => $regionalId, 'punto_id' => 0, 'estado' => 'NO_MAPEADO'];
}

function acSimFuenteFingerprint(array $r): string
{
    $campos = [
        $r['sim'] ?? '', $r['estado'] ?? '', $r['fecha_alerta'] ?? '', $r['fecha_atencion'] ?? '',
        $r['regional'] ?? '', $r['punto_atencion'] ?? '', $r['categoria'] ?? '', $r['descripcion'] ?? '',
        $r['afecta_linea_tecnica'] ?? '', $r['fecha_remision_agente'] ?? '', $r['justificacion'] ?? '',
        $r['fecha_notificacion_regional'] ?? '', $r['subcategoria'] ?? '',
    ];
    return hash('sha256', implode("\x1F", array_map(static fn($v) => trim((string)$v), $campos)));
}

function acSimFuenteParsear(string $ruta, string $nombreArchivo, mysqli $db): array
{
    $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        throw new RuntimeException('La base de alertas SIM debe cargarse en formato XLSX.');
    }

    $hojas = acImportacionLeerXlsx($ruta);
    $filas = null;
    foreach ($hojas as $nombre => $rows) {
        if (acTerritorioNormalizarClave($nombre) === 'BASE DE ALERTAS') {
            $filas = $rows;
            break;
        }
    }
    if ($filas === null) {
        foreach ($hojas as $rows) {
            $h = acImportacionBuscarFilaEncabezado($rows, [
                ['SIM'], ['REGIONAL'], ['PUNTO_DE_ATENCION']
            ]);
            if ($h) {
                $filas = $rows;
                break;
            }
        }
    }
    if ($filas === null) {
        throw new RuntimeException('No se encontró la hoja "Base de alertas" ni sus encabezados esperados.');
    }

    $header = acImportacionBuscarFilaEncabezado($filas, [
        ['SIM'], ['REGIONAL'], ['PUNTO_DE_ATENCION']
    ], 20);
    if (!$header) {
        throw new RuntimeException('No fue posible identificar las columnas SIM, REGIONAL y PUNTO DE ATENCIÓN.');
    }

    $map = $header['map'];
    $registros = [];
    $errores = [];

    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $sim = trim(acImportacionValor($row, $map, ['SIM']));
        $regional = trim(acImportacionValor($row, $map, ['REGIONAL']));
        $punto = trim(acImportacionValor($row, $map, ['PUNTO_DE_ATENCION']));

        if ($sim === '' && $regional === '' && $punto === '') {
            continue;
        }
        if ($sim === '' || $regional === '' || $punto === '') {
            $errores[] = 'Fila ' . ($i + 1) . ': faltan SIM, Regional o Punto de atención.';
            continue;
        }
        if (mb_strlen($sim, 'UTF-8') > 50) {
            $errores[] = 'Fila ' . ($i + 1) . ': el número SIM supera 50 caracteres.';
            continue;
        }

        $mapeo = acSimFuenteMapearTerritorio($db, $regional, $punto);
        $r = [
            'sim' => $sim,
            'estado' => acImportacionValor($row, $map, ['ESTADO']),
            'fecha_alerta' => acSimFuenteExcelFecha(acImportacionValor($row, $map, ['FECHA_DE_ALERTA']), false),
            'fecha_atencion' => acSimFuenteExcelFecha(acImportacionValor($row, $map, ['FECHA_DE_ATENCION_AL_CIUDADANO']), true),
            'regional' => $regional,
            'punto_atencion' => $punto,
            'categoria' => acImportacionValor($row, $map, ['CATEGORIA_DE_AFECTACION']),
            'descripcion' => acImportacionValor($row, $map, ['DESCRIPCION_DE_LA_ALERTA']),
            'afecta_linea_tecnica' => acImportacionValor($row, $map, ['AFECTA_LA_LINEA_TECNICA']),
            'fecha_remision_agente' => acSimFuenteExcelFecha(acImportacionValor($row, $map, ['FECHA_DE_REMISION_AL_AGENTE_ESPECIALIZADO']), true),
            'justificacion' => acImportacionValor($row, $map, ['JUSTIFICACION']),
            'fecha_notificacion_regional' => acSimFuenteExcelFecha(acImportacionValor($row, $map, ['FECHA_DE_NOTIFICACION_A_LA_REGIONAL']), true),
            'subcategoria' => acImportacionValor($row, $map, ['SUBCATEGORIA']),
            'regional_id' => (int)$mapeo['regional_id'],
            'punto_atencion_id' => (int)$mapeo['punto_id'],
            'mapeo_estado' => (string)$mapeo['estado'],
        ];
        $r['fingerprint'] = acSimFuenteFingerprint($r);
        $registros[$sim] = $r; // SIM es único; la última fila prevalece dentro del archivo.
    }

    if (!$registros) {
        throw new RuntimeException('El archivo no contiene radicados SIM válidos.');
    }

    return ['registros' => array_values($registros), 'errores' => $errores];
}

function acSimFuentePreparar(mysqli $db, string $ruta, string $nombreArchivo): array
{
    $parseado = acSimFuenteParsear($ruta, $nombreArchivo, $db);
    $actuales = [];
    $rs = $db->query("SELECT * FROM tb_alerta_correo_radicado_sim WHERE ars_activo=1");
    while ($row = $rs->fetch_assoc()) {
        $actuales[(string)$row['ars_sim']] = $row;
    }

    $resumen = [
        'nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'no_en_archivo' => 0,
        'mapeados' => 0, 'regional_solamente' => 0, 'no_mapeados' => 0,
        'invalidos' => count($parseado['errores']),
    ];
    $diferencias = [];
    $presentes = [];

    foreach ($parseado['registros'] as $r) {
        $sim = (string)$r['sim'];
        $presentes[$sim] = true;
        $actual = $actuales[$sim] ?? null;

        if ($r['mapeo_estado'] === 'MAPEADO') $resumen['mapeados']++;
        elseif ($r['mapeo_estado'] === 'REGIONAL_SOLAMENTE') $resumen['regional_solamente']++;
        else $resumen['no_mapeados']++;

        if (!$actual) {
            $resumen['nuevos']++;
            $diferencias[] = ['tipo' => 'NUEVO', 'nuevo' => $r, 'actual' => null];
        } elseif (hash_equals((string)$actual['ars_hash_version'], (string)$r['fingerprint'])) {
            $resumen['sin_cambios']++;
        } else {
            $resumen['actualizados']++;
            $diferencias[] = ['tipo' => 'ACTUALIZADO', 'nuevo' => $r, 'actual' => $actual];
        }
    }

    foreach ($actuales as $sim => $row) {
        if (!isset($presentes[$sim])) {
            $resumen['no_en_archivo']++;
        }
    }

    return [
        'registros' => $parseado['registros'],
        'errores' => $parseado['errores'],
        'resumen' => $resumen,
        'diferencias' => $diferencias,
        'nombre_archivo' => $nombreArchivo,
        'sha256' => hash_file('sha256', $ruta) ?: '',
    ];
}

function acSimFuenteSnapshotHistorial(mysqli $db, array $actual, string $usuario, string $accion = 'ACTUALIZACION'): void
{
    $sim = (string)$actual['ars_sim'];
    $hash = (string)($actual['ars_hash_version'] ?? '');
    $json = json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = '{}';

    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_radicado_sim_historial
         (arsh_sim, arsh_accion, arsh_hash_version, arsh_snapshot_json, arsh_usuario)
         VALUES (?,?,?,?,?)'
    );
    $stmt->bind_param('sssss', $sim, $accion, $hash, $json, $usuario);
    $stmt->execute();
    $stmt->close();
}

function acSimFuenteAplicar(mysqli $db, array $preview, string $usuario): array
{
    $resultado = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'no_en_archivo' => (int)$preview['resumen']['no_en_archivo']];

    $sel = $db->prepare('SELECT * FROM tb_alerta_correo_radicado_sim WHERE ars_sim=? LIMIT 1');
    $ins = $db->prepare(
        'INSERT INTO tb_alerta_correo_radicado_sim
        (ars_sim,ars_estado,ars_fecha_alerta,ars_fecha_atencion,ars_regional,ars_punto_atencion,
         ars_categoria,ars_descripcion,ars_afecta_linea_tecnica,ars_fecha_remision_agente,ars_justificacion,
         ars_fecha_notificacion_regional,ars_subcategoria,ars_regional_id,ars_punto_atencion_id,ars_mapeo_estado,
         ars_activo,ars_fuente,ars_hash_version,ars_usuario_actualizacion)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,\'BBDD_ENCUESTAS_TERRITORIO\',?,?)'
    );
    $upd = $db->prepare(
        'UPDATE tb_alerta_correo_radicado_sim SET
         ars_estado=?,ars_fecha_alerta=?,ars_fecha_atencion=?,ars_regional=?,ars_punto_atencion=?,
         ars_categoria=?,ars_descripcion=?,ars_afecta_linea_tecnica=?,ars_fecha_remision_agente=?,ars_justificacion=?,
         ars_fecha_notificacion_regional=?,ars_subcategoria=?,ars_regional_id=?,ars_punto_atencion_id=?,ars_mapeo_estado=?,
         ars_activo=1,ars_fuente=\'BBDD_ENCUESTAS_TERRITORIO\',ars_hash_version=?,ars_usuario_actualizacion=?,ars_fecha_actualizacion=NOW()
         WHERE ars_sim=?'
    );
    $mapOnly = $db->prepare(
        'UPDATE tb_alerta_correo_radicado_sim SET
         ars_regional_id=?,ars_punto_atencion_id=?,ars_mapeo_estado=?,ars_activo=1,ars_usuario_actualizacion=?,ars_fecha_actualizacion=NOW()
         WHERE ars_sim=?'
    );

    foreach ($preview['registros'] as $r) {
        $sim = (string)$r['sim'];
        $sel->bind_param('s', $sim);
        $sel->execute();
        $actual = $sel->get_result()->fetch_assoc() ?: null;

        $estado = (string)$r['estado'];
        $fechaAlerta = $r['fecha_alerta'];
        $fechaAtencion = $r['fecha_atencion'];
        $regional = (string)$r['regional'];
        $punto = (string)$r['punto_atencion'];
        $categoria = (string)$r['categoria'];
        $descripcion = (string)$r['descripcion'];
        $afecta = (string)$r['afecta_linea_tecnica'];
        $fechaRemision = $r['fecha_remision_agente'];
        $justificacion = (string)$r['justificacion'];
        $fechaNotif = $r['fecha_notificacion_regional'];
        $subcategoria = (string)$r['subcategoria'];
        $regionalId = (int)$r['regional_id'];
        $puntoId = (int)$r['punto_atencion_id'];
        $mapeo = (string)$r['mapeo_estado'];
        $hash = (string)$r['fingerprint'];

        if (!$actual) {
            $ins->bind_param(
                'sssssssssssssiisss',
                $sim,$estado,$fechaAlerta,$fechaAtencion,$regional,$punto,$categoria,$descripcion,$afecta,
                $fechaRemision,$justificacion,$fechaNotif,$subcategoria,$regionalId,$puntoId,$mapeo,$hash,$usuario
            );
            $ins->execute();
            $resultado['nuevos']++;
            continue;
        }

        if (hash_equals((string)$actual['ars_hash_version'], $hash)) {
            // Aunque la fuente no haya cambiado, refrescamos el vínculo territorial por si el catálogo cambió.
            // Si estaba inactivo, la presencia explícita en el archivo lo reactiva y deja trazabilidad.
            if ((int)($actual['ars_activo'] ?? 0) !== 1) {
                acSimFuenteSnapshotHistorial($db, $actual, $usuario, 'REACTIVACION');
            }
            $mapOnly->bind_param('iisss', $regionalId, $puntoId, $mapeo, $usuario, $sim);
            $mapOnly->execute();
            $resultado['sin_cambios']++;
            continue;
        }

        acSimFuenteSnapshotHistorial($db, $actual, $usuario, ((int)($actual['ars_activo'] ?? 0) === 1 ? 'ACTUALIZACION' : 'REACTIVACION'));
        $upd->bind_param(
            'ssssssssssssiissss',
            $estado,$fechaAlerta,$fechaAtencion,$regional,$punto,$categoria,$descripcion,$afecta,$fechaRemision,
            $justificacion,$fechaNotif,$subcategoria,$regionalId,$puntoId,$mapeo,$hash,$usuario,$sim
        );
        $upd->execute();
        $resultado['actualizados']++;
    }

    $sel->close();
    $ins->close();
    $upd->close();
    $mapOnly->close();

    return $resultado;
}
