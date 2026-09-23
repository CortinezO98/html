<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_territorio.php';
require_once __DIR__ . '/alerta_correos_importacion_versionada.php';

function acCargaSectorizadaConfiguracion(string $tipo): array
{
    return match ($tipo) {
        'TERRITORIOS' => [
            'titulo' => 'Cargar regionales y centros zonales',
            'subtitulo' => 'Administra el catálogo territorial que utilizarán las demás cargas.',
            'tipo_carga' => 'TERRITORIOS_MAESTRO',
            'tipo_responsable' => null,
            'perfil' => null,
            'fuente' => 'MAESTRO_TERRITORIAL',
        ],
        'COORDINADORES' => [
            'titulo' => 'Cargar coordinadores zonales',
            'subtitulo' => 'Asocia o actualiza el coordinador únicamente de un Centro Zonal existente.',
            'tipo_carga' => 'COORDINADORES_SECTORIZADOS',
            'tipo_responsable' => 'COORDINADOR',
            'perfil' => 'Coordinador',
            'fuente' => 'COORDINADORES_SECTORIZADOS',
            'acp_tipo_permitido' => 'CENTRO_ZONAL',
        ],
        'RESPONSABLES' => [
            'titulo' => 'Cargar enlaces regionales',
            'subtitulo' => 'Asocia o actualiza el Enlace de Relación con el Ciudadano únicamente de una Regional existente.',
            'tipo_carga' => 'RESPONSABLES_SECTORIZADOS',
            'tipo_responsable' => 'ENLACE_RELACION_CIUDADANO',
            'perfil' => 'Enlace',
            'fuente' => 'RESPONSABLES_SECTORIZADOS',
            'acp_tipo_permitido' => 'REGIONAL',
        ],
        default => throw new InvalidArgumentException('Tipo de carga no válido.'),
    };
}

function acCargaSectorizadaLeerArchivo(string $ruta, string $nombreArchivo): array
{
    $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Solo se permiten archivos CSV o XLSX.');
    }

    return $ext === 'csv'
        ? acImportacionLeerCsv($ruta)
        : acImportacionLeerXlsx($ruta);
}

function acCargaSectorizadaFilaHeader(array $hojas, array $requeridos): array
{
    foreach ($hojas as $nombreHoja => $filas) {
        $header = acImportacionBuscarFilaEncabezado($filas, $requeridos, 30);
        if ($header) {
            return [$nombreHoja, $filas, $header];
        }
    }

    throw new RuntimeException('No fue posible reconocer los encabezados del archivo.');
}

function acCargaSectorizadaEstado(string $valor): bool
{
    return acImportacionEstadoActivo($valor);
}

function acCargaSectorizadaTerritorios(mysqli $db, string $ruta, string $nombreArchivo): array
{
    $hojas = acCargaSectorizadaLeerArchivo($ruta, $nombreArchivo);
    [, $filas, $header] = acCargaSectorizadaFilaHeader($hojas, [
        ['NIVEL'],
        ['REGIONAL'],
        ['CODIGO_CENTRO', 'CODIGO'],
        ['ESTADO'],
    ]);

    $map = $header['map'];
    $registros = [];
    $errores = [];
    $codigosArchivo = [];

    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $nivel = acTerritorioNormalizarClave(acImportacionValor($row, $map, ['NIVEL']));
        $regional = trim(acImportacionValor($row, $map, ['REGIONAL']));
        $centro = trim(acImportacionValor($row, $map, ['CENTRO_ZONAL', 'NOMBRE_DEL_PUNTO']));
        $codigo = trim(acImportacionValor($row, $map, ['CODIGO_CENTRO', 'CODIGO']));
        $estado = acImportacionValor($row, $map, ['ESTADO']);

        if ($nivel === '' && $regional === '' && $centro === '' && $codigo === '') {
            continue;
        }

        if ($nivel === 'CENTRO ZONAL') {
            $nivel = 'ZONAL';
        }
        if (!in_array($nivel, ['REGIONAL', 'ZONAL'], true)) {
            $errores[] = 'Fila ' . ($i + 1) . ': NIVEL debe ser REGIONAL o ZONAL.';
            continue;
        }
        if ($regional === '' || $codigo === '') {
            $errores[] = 'Fila ' . ($i + 1) . ': REGIONAL y CODIGO_CENTRO son obligatorios.';
            continue;
        }
        if ($nivel === 'ZONAL' && $centro === '') {
            $errores[] = 'Fila ' . ($i + 1) . ': CENTRO_ZONAL es obligatorio para nivel ZONAL.';
            continue;
        }

        $codigoClave = mb_strtoupper($codigo, 'UTF-8');
        $identidad = $nivel . '|' . acTerritorioNormalizarClave($regional) . '|' . acTerritorioNormalizarClave($centro);
        if (isset($codigosArchivo[$codigoClave]) && $codigosArchivo[$codigoClave] !== $identidad) {
            $errores[] = 'Fila ' . ($i + 1) . ': el código ' . $codigo . ' está repetido para territorios diferentes.';
            continue;
        }
        $codigosArchivo[$codigoClave] = $identidad;

        $registros[] = [
            'nivel' => $nivel,
            'regional' => $regional,
            'centro_zonal' => $nivel === 'REGIONAL' ? '' : $centro,
            'codigo_centro' => $codigo,
            'activo' => acCargaSectorizadaEstado($estado),
            'fila' => $i + 1,
        ];
    }

    if (!$registros) {
        throw new RuntimeException('El archivo no contiene territorios válidos.');
    }

    $diferencias = [];
    $resumen = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'inactivaciones_explicitas' => 0, 'invalidos' => count($errores)];

    foreach ($registros as $r) {
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_punto_atencion
             WHERE TRIM(COALESCE(acp_codigo,''))=?"
        );
        $stmt->bind_param('s', $r['codigo_centro']);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($rows) > 1) {
            $errores[] = 'Código ' . $r['codigo_centro'] . ': existen varios territorios actuales con el mismo código.';
            $resumen['invalidos']++;
            continue;
        }

        $actual = $rows[0] ?? null;
        if (!$r['activo']) {
            $resumen['inactivaciones_explicitas']++;
            $diferencias[] = ['tipo' => 'INACTIVAR', 'nuevo' => $r, 'actual' => $actual];
            continue;
        }

        if (!$actual) {
            $resumen['nuevos']++;
            $diferencias[] = ['tipo' => 'NUEVO', 'nuevo' => $r, 'actual' => null];
            continue;
        }

        $tipoActual = strtoupper((string)($actual['acp_tipo'] ?? ''));
        $tipoNuevo = $r['nivel'] === 'REGIONAL' ? 'REGIONAL' : 'CENTRO_ZONAL';
        $nombreActual = $tipoNuevo === 'REGIONAL'
            ? (string)($actual['acp_regional'] ?? $actual['acp_nombre'] ?? '')
            : (string)($actual['acp_nombre'] ?? '');

        $igual = $tipoActual === $tipoNuevo
            && acTerritorioNormalizarClave((string)($actual['acp_regional'] ?? '')) === acTerritorioNormalizarClave($r['regional'])
            && acTerritorioNormalizarClave($nombreActual) === acTerritorioNormalizarClave($tipoNuevo === 'REGIONAL' ? $r['regional'] : $r['centro_zonal'])
            && (int)($actual['acp_activo'] ?? 0) === 1;

        if ($igual) {
            $resumen['sin_cambios']++;
        } else {
            $resumen['actualizados']++;
            $diferencias[] = ['tipo' => 'ACTUALIZAR', 'nuevo' => $r, 'actual' => $actual];
        }
    }

    return [
        'tipo' => 'TERRITORIOS',
        'registros' => $registros,
        'errores' => $errores,
        'diferencias' => $diferencias,
        'resumen' => $resumen,
        'nombre_archivo' => $nombreArchivo,
        'sha256' => hash_file('sha256', $ruta) ?: '',
    ];
}

function acCargaSectorizadaBuscarTerritorioCodigo(mysqli $db, string $codigo): array
{
    $stmt = $db->prepare(
        "SELECT * FROM tb_alerta_correo_punto_atencion
         WHERE TRIM(COALESCE(acp_codigo,''))=? AND acp_activo=1"
    );
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        throw new RuntimeException('Código ' . $codigo . ': territorio no registrado. Cargue primero el maestro territorial.');
    }
    if (count($rows) > 1) {
        throw new RuntimeException('Código ' . $codigo . ': existe más de un territorio activo con el mismo código.');
    }

    return $rows[0];
}

function acCargaSectorizadaPersonas(mysqli $db, string $ruta, string $nombreArchivo, string $tipo): array
{
    $config = acCargaSectorizadaConfiguracion($tipo);
    $hojas = acCargaSectorizadaLeerArchivo($ruta, $nombreArchivo);
    [, $filas, $header] = acCargaSectorizadaFilaHeader($hojas, [
        ['CODIGO_CENTRO', 'CODIGO'],
        ['NOMBRE'],
        ['CORREO', 'EMAIL'],
        ['ESTADO'],
    ]);

    $map = $header['map'];
    $registros = [];
    $errores = [];
    $vistos = [];

    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $codigo = trim(acImportacionValor($row, $map, ['CODIGO_CENTRO', 'CODIGO']));
        $documento = trim(acImportacionValor($row, $map, ['DOCUMENTO', 'CEDULA']));
        $nombre = trim(acImportacionValor($row, $map, ['NOMBRE']));
        $correo = acImportacionLimpiarCorreo(acImportacionValor($row, $map, ['CORREO', 'EMAIL']));
        $extension = trim(acImportacionValor($row, $map, ['EXTENSION_IP', 'EXTENSION']));
        $estado = acImportacionValor($row, $map, ['ESTADO']);

        if ($codigo === '' && $nombre === '' && $correo === '') {
            continue;
        }
        if ($codigo === '' || $nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Fila ' . ($i + 1) . ': CODIGO_CENTRO, NOMBRE y CORREO válido son obligatorios.';
            continue;
        }

        try {
            $territorio = acCargaSectorizadaBuscarTerritorioCodigo($db, $codigo);
        } catch (Throwable $e) {
            $errores[] = 'Fila ' . ($i + 1) . ': ' . $e->getMessage();
            continue;
        }

        $tipoTerritorio = strtoupper(trim((string)($territorio['acp_tipo'] ?? '')));
        $tipoPermitido = strtoupper(trim((string)($config['acp_tipo_permitido'] ?? '')));

        if ($tipoPermitido !== '' && $tipoTerritorio !== $tipoPermitido) {
            $esperado = $tipoPermitido === 'CENTRO_ZONAL' ? 'un Centro Zonal' : 'una Regional';
            $errores[] = 'Fila ' . ($i + 1) . ': el CODIGO_CENTRO ' . $codigo
                . ' corresponde a ' . ($tipoTerritorio === 'REGIONAL' ? 'una Regional' : 'un Centro Zonal')
                . '. Para esta carga debe corresponder a ' . $esperado . '.';
            continue;
        }

        $clave = (int)$territorio['acp_id'] . '|' . $config['tipo_responsable'];
        if (isset($vistos[$clave])) {
            $errores[] = 'Fila ' . ($i + 1) . ': el código ' . $codigo . ' está repetido para el mismo tipo de responsable.';
            continue;
        }
        $vistos[$clave] = true;

        $esRegional = strtoupper((string)$territorio['acp_tipo']) === 'REGIONAL';
        $registro = [
            'punto_id' => (int)$territorio['acp_id'],
            'nivel' => $esRegional ? 'REGIONAL' : 'ZONAL',
            'regional' => (string)($territorio['acp_regional'] ?? ''),
            'centro_zonal' => $esRegional ? '' : (string)($territorio['acp_nombre'] ?? ''),
            'codigo_centro' => $codigo,
            'documento' => $documento,
            'nombre' => $nombre,
            'correo' => $correo,
            'extension_ip' => $extension,
            'tipo_responsable' => (string)$config['tipo_responsable'],
            'perfil' => (string)$config['perfil'],
            'activo' => acCargaSectorizadaEstado($estado),
            'fuente' => (string)$config['fuente'],
            'fila' => $i + 1,
        ];
        $registro['fingerprint'] = acImportacionFingerprint($registro);
        $registros[] = $registro;
    }

    if (!$registros) {
        throw new RuntimeException('El archivo no contiene registros válidos.');
    }

    $resumen = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'inactivaciones_explicitas' => 0, 'invalidos' => count($errores)];
    $diferencias = [];

    foreach ($registros as $r) {
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND acr_punto_atencion_id=?
               AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?
             ORDER BY acr_id DESC"
        );
        $tipoResp = strtoupper($r['tipo_responsable']);
        $stmt->bind_param('is', $r['punto_id'], $tipoResp);
        $stmt->execute();
        $actuales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!$r['activo']) {
            $resumen['inactivaciones_explicitas']++;
            $diferencias[] = ['tipo' => 'INACTIVAR', 'nuevo' => $r, 'actual' => $actuales[0] ?? null];
            continue;
        }

        if (!$actuales) {
            $resumen['nuevos']++;
            $diferencias[] = ['tipo' => 'NUEVO', 'nuevo' => $r, 'actual' => null];
            continue;
        }

        $igual = false;
        foreach ($actuales as $actual) {
            $actualReg = acImportacionFilaDbARegistro($actual);
            if (hash_equals(acImportacionFingerprint($actualReg), $r['fingerprint'])) {
                $igual = true;
                break;
            }
        }

        if ($igual && count($actuales) === 1) {
            $resumen['sin_cambios']++;
        } else {
            $resumen['actualizados']++;
            $diferencias[] = ['tipo' => 'ACTUALIZAR', 'nuevo' => $r, 'actual' => $actuales[0] ?? null];
        }
    }

    return [
        'tipo' => $tipo,
        'registros' => $registros,
        'errores' => $errores,
        'diferencias' => $diferencias,
        'resumen' => $resumen,
        'nombre_archivo' => $nombreArchivo,
        'sha256' => hash_file('sha256', $ruta) ?: '',
    ];
}

function acCargaSectorizadaPreparar(mysqli $db, string $ruta, string $nombreArchivo, string $tipo): array
{
    return $tipo === 'TERRITORIOS'
        ? acCargaSectorizadaTerritorios($db, $ruta, $nombreArchivo)
        : acCargaSectorizadaPersonas($db, $ruta, $nombreArchivo, $tipo);
}

function acCargaSectorizadaAplicarTerritorios(mysqli $db, array $preview): array
{
    $resultado = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'inactivados' => 0];

    $registros = $preview['registros'];
    usort(
        $registros,
        static fn(array $a, array $b): int =>
            ($a['nivel'] === 'REGIONAL' ? 0 : 1) <=> ($b['nivel'] === 'REGIONAL' ? 0 : 1)
    );

    foreach ($registros as $r) {
        $codigo = trim((string)$r['codigo_centro']);
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_punto_atencion
             WHERE TRIM(COALESCE(acp_codigo,''))=?"
        );
        $stmt->bind_param('s', $codigo);
        $stmt->execute();
        $existentesCodigo = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($existentesCodigo) > 1) {
            throw new RuntimeException(
                'El código ' . $codigo . ' ya está asociado a más de un territorio. Corrija el catálogo antes de continuar.'
            );
        }

        $actual = $existentesCodigo[0] ?? null;

        if (!$r['activo']) {
            if ($actual && (int)$actual['acp_activo'] === 1) {
                $id = (int)$actual['acp_id'];
                $stmt = $db->prepare(
                    'UPDATE tb_alerta_correo_punto_atencion
                     SET acp_activo=0, acp_fecha_actualizacion=NOW()
                     WHERE acp_id=?'
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $resultado['inactivados'] += $stmt->affected_rows;
                $stmt->close();
            }
            continue;
        }

        $fuente = 'MAESTRO_TERRITORIAL';
        $tipoNuevo = $r['nivel'] === 'REGIONAL' ? 'REGIONAL' : 'CENTRO_ZONAL';
        $regional = trim((string)$r['regional']);
        $centro = trim((string)$r['centro_zonal']);
        $regionalClave = acTerritorioNormalizarClave($regional);
        $nombreNuevo = $tipoNuevo === 'REGIONAL' ? $regional : $centro;
        $nombreClave = acTerritorioNormalizarClave($nombreNuevo);

        $padreId = null;
        if ($tipoNuevo === 'CENTRO_ZONAL') {
            $stmt = $db->prepare(
                "SELECT acp_id
                 FROM tb_alerta_correo_punto_atencion
                 WHERE acp_tipo='REGIONAL'
                   AND acp_activo=1
                   AND acp_regional_clave=?
                 ORDER BY acp_id DESC
                 LIMIT 1"
            );
            $stmt->bind_param('s', $regionalClave);
            $stmt->execute();
            $padre = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$padre) {
                throw new RuntimeException(
                    'No existe la Regional ' . $regional . ' para asociar el centro ' . $centro . '.'
                );
            }
            $padreId = (int)$padre['acp_id'];
        }

        if ($actual) {
            $id = (int)$actual['acp_id'];

            $stmt = $db->prepare(
                "SELECT acp_id
                 FROM tb_alerta_correo_punto_atencion
                 WHERE acp_tipo=?
                   AND acp_regional_clave=?
                   AND acp_nombre_clave=?
                   AND acp_id<>?
                 LIMIT 1"
            );
            $stmt->bind_param('sssi', $tipoNuevo, $regionalClave, $nombreClave, $id);
            $stmt->execute();
            $conflicto = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($conflicto) {
                throw new RuntimeException(
                    'El territorio ' . $regional . ($centro !== '' ? ' / ' . $centro : '') .
                    ' ya existe con otro código. Corrija el maestro antes de continuar.'
                );
            }

            $stmt = $db->prepare(
                'UPDATE tb_alerta_correo_punto_atencion
                 SET acp_padre_id=?,
                     acp_codigo=?,
                     acp_tipo=?,
                     acp_regional=?,
                     acp_regional_clave=?,
                     acp_nombre=?,
                     acp_nombre_clave=?,
                     acp_activo=1,
                     acp_fuente=?,
                     acp_fecha_actualizacion=NOW()
                 WHERE acp_id=?'
            );
            $stmt->bind_param(
                'isssssssi',
                $padreId,
                $codigo,
                $tipoNuevo,
                $regional,
                $regionalClave,
                $nombreNuevo,
                $nombreClave,
                $fuente,
                $id
            );
            $stmt->execute();
            $stmt->close();

            $igual = strtoupper((string)$actual['acp_tipo']) === $tipoNuevo
                && acTerritorioNormalizarClave((string)$actual['acp_regional']) === $regionalClave
                && acTerritorioNormalizarClave((string)$actual['acp_nombre']) === $nombreClave
                && (int)$actual['acp_activo'] === 1;

            $igual ? $resultado['sin_cambios']++ : $resultado['actualizados']++;
            continue;
        }

        $stmt = $db->prepare(
            "SELECT acp_id
             FROM tb_alerta_correo_punto_atencion
             WHERE acp_tipo=?
               AND acp_regional_clave=?
               AND acp_nombre_clave=?
             LIMIT 1"
        );
        $stmt->bind_param('sss', $tipoNuevo, $regionalClave, $nombreClave);
        $stmt->execute();
        $mismoTerritorio = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($mismoTerritorio) {
            $id = (int)$mismoTerritorio['acp_id'];
            $stmt = $db->prepare(
                'UPDATE tb_alerta_correo_punto_atencion
                 SET acp_padre_id=?, acp_codigo=?, acp_activo=1, acp_fuente=?, acp_fecha_actualizacion=NOW()
                 WHERE acp_id=?'
            );
            $stmt->bind_param('issi', $padreId, $codigo, $fuente, $id);
            $stmt->execute();
            $stmt->close();
            $resultado['actualizados']++;
            continue;
        }

        $stmt = $db->prepare(
            'INSERT INTO tb_alerta_correo_punto_atencion
             (acp_padre_id, acp_codigo, acp_tipo, acp_regional, acp_regional_clave,
              acp_nombre, acp_nombre_clave, acp_activo, acp_fuente)
             VALUES (?,?,?,?,?,?,?,1,?)'
        );
        $stmt->bind_param(
            'isssssss',
            $padreId,
            $codigo,
            $tipoNuevo,
            $regional,
            $regionalClave,
            $nombreNuevo,
            $nombreClave,
            $fuente
        );
        $stmt->execute();
        $stmt->close();
        $resultado['nuevos']++;
    }

    return $resultado;
}

function acCargaSectorizadaAplicarPersonas(mysqli $db, array $preview, string $usuario): array
{
    $resultado = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'inactivados' => 0];

    foreach ($preview['registros'] as $r) {
        $tipoResp = strtoupper($r['tipo_responsable']);
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND acr_punto_atencion_id=?
               AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?
             ORDER BY acr_id DESC"
        );
        $stmt->bind_param('is', $r['punto_id'], $tipoResp);
        $stmt->execute();
        $actuales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!$r['activo']) {
            if ($actuales) {
                $stmt = $db->prepare(
                    'UPDATE tb_alerta_correo_responsable
                     SET acr_activo=0, acr_vigente_hasta=NOW(), acr_actualizado_por=?, acr_fecha_actualizacion=NOW()
                     WHERE acr_punto_atencion_id=? AND acr_activo=1
                       AND UPPER(TRIM(COALESCE(acr_tipo_responsable,\'\')))=?'
                );
                $stmt->bind_param('sis', $usuario, $r['punto_id'], $tipoResp);
                $stmt->execute();
                $resultado['inactivados'] += $stmt->affected_rows;
                $stmt->close();
            }
            continue;
        }

        $matching = null;
        foreach ($actuales as $actual) {
            $actualReg = acImportacionFilaDbARegistro($actual);
            if (hash_equals(acImportacionFingerprint($actualReg), $r['fingerprint'])) {
                $matching = $actual;
                break;
            }
        }

        if ($matching && count($actuales) === 1) {
            $resultado['sin_cambios']++;
            continue;
        }

        if ($actuales) {
            $stmt = $db->prepare(
                'UPDATE tb_alerta_correo_responsable
                 SET acr_activo=0, acr_vigente_hasta=NOW(), acr_actualizado_por=?, acr_fecha_actualizacion=NOW()
                 WHERE acr_punto_atencion_id=? AND acr_activo=1
                   AND UPPER(TRIM(COALESCE(acr_tipo_responsable,\'\')))=?'
            );
            $stmt->bind_param('sis', $usuario, $r['punto_id'], $tipoResp);
            $stmt->execute();
            $stmt->close();
            $resultado['actualizados']++;
        } else {
            $resultado['nuevos']++;
        }

        acImportacionInsertarResponsable($db, $r, (int)$r['punto_id'], $usuario);
    }

    return $resultado;
}

function acCargaSectorizadaAplicar(mysqli $db, array $preview, string $usuario): array
{
    return $preview['tipo'] === 'TERRITORIOS'
        ? acCargaSectorizadaAplicarTerritorios($db, $preview)
        : acCargaSectorizadaAplicarPersonas($db, $preview, $usuario);
}
