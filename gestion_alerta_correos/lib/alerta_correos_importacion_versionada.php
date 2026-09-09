<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_territorio.php';

function acImportacionNormalizarHeader(string $valor): string
{
    // Normalización explícita de caracteres españoles antes de la
    // normalización general. Esto evita diferencias de iconv entre
    // Windows/XAMPP y Linux para encabezados como "CÓDIGO".
    $valor = strtr(trim($valor), [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);
    $valor = acTerritorioNormalizarClave($valor);
    return str_replace(' ', '_', $valor);
}

function acImportacionExtraerNombreCorreo(string $valor): array
{
    $valor = trim($valor);
    if ($valor === '') {
        return ['', ''];
    }

    if (preg_match('/^(.*?)\s*<\s*([^<>\s]+@[^<>\s]+)\s*>\s*$/u', $valor, $m)) {
        return [trim($m[1]), strtolower(trim($m[2]))];
    }

    if (filter_var($valor, FILTER_VALIDATE_EMAIL)) {
        return ['', strtolower($valor)];
    }

    return [$valor, ''];
}

function acImportacionLimpiarCorreo(string $correo): string
{
    $correo = trim($correo, " \t\n\r\0\x0B<>\"'");
    return strtolower($correo);
}

function acImportacionEstadoActivo(?string $estado): bool
{
    $estado = acTerritorioNormalizarClave((string)$estado);
    return !in_array($estado, ['I', 'INACTIVO', 'INACTIVA', '0', 'NO', 'FALSE'], true);
}

/**
 * Canoniza el nombre de la regional proveniente de fuentes externas.
 * Mantiene la misma clave usada por BBDD Coordinadores CZ para evitar
 * regionales duplicadas por acentos, mayúsculas o alias históricos.
 */
function acImportacionRegionalCanonica(string $regional): string
{
    $clave = acTerritorioNormalizarClave($regional);
    if ($clave === '') {
        return '';
    }

    // Alias conocido en BBDD Enlaces SIM: "Valle" corresponde a
    // la regional oficial "VALLE DEL CAUCA" del catálogo territorial.
    $alias = [
        'VALLE' => 'VALLE DEL CAUCA',
    ];

    return $alias[$clave] ?? $clave;
}

function acImportacionFingerprint(array $r): string
{
    $partes = [
        acTerritorioNormalizarClave((string)($r['nivel'] ?? '')),
        acTerritorioNormalizarClave((string)($r['regional'] ?? '')),
        acTerritorioNormalizarClave((string)($r['centro_zonal'] ?? '')),
        acTerritorioNormalizarClave((string)($r['tipo_responsable'] ?? '')),
        acTerritorioNormalizarClave((string)($r['nombre'] ?? '')),
        strtolower(trim((string)($r['correo'] ?? ''))),
        preg_replace('/\D+/', '', (string)($r['documento'] ?? '')),
        acTerritorioNormalizarClave((string)($r['perfil'] ?? '')),
        trim((string)($r['extension_ip'] ?? '')),
    ];
    return hash('sha256', implode('|', $partes));
}

function acImportacionClaveAsignacion(array $r): string
{
    return implode('|', [
        acTerritorioNormalizarClave((string)($r['nivel'] ?? '')),
        acTerritorioNormalizarClave((string)($r['regional'] ?? '')),
        acTerritorioNormalizarClave((string)($r['centro_zonal'] ?? '')),
        acTerritorioNormalizarClave((string)($r['tipo_responsable'] ?? '')),
    ]);
}

/**
 * Lector XLSX sin dependencias externas. Devuelve hojas => filas => celdas.
 */
function acImportacionLeerXlsx(string $ruta): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extensión ZipArchive de PHP es requerida para leer XLSX.');
    }

    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new RuntimeException('No fue posible abrir el archivo XLSX.');
    }

    try {
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkgRelNs = 'http://schemas.openxmlformats.org/package/2006/relationships';

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml !== false) {
                $main = $xml->children($mainNs);
                foreach ($main->si as $si) {
                    $texto = '';
                    $siMain = $si->children($mainNs);
                    if (isset($siMain->t)) {
                        $texto = (string)$siMain->t;
                    } else {
                        foreach ($siMain->r as $r) {
                            $rMain = $r->children($mainNs);
                            $texto .= (string)$rMain->t;
                        }
                    }
                    $shared[] = $texto;
                }
            }
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('El XLSX no contiene la estructura esperada.');
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('No fue posible interpretar la estructura del XLSX.');
        }

        $relMap = [];
        $relsMain = $rels->children($pkgRelNs);
        foreach ($relsMain->Relationship as $rel) {
            $attrs = $rel->attributes();
            $relMap[(string)$attrs['Id']] = (string)$attrs['Target'];
        }

        $salida = [];
        $workbookMain = $workbook->children($mainNs);

        foreach ($workbookMain->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes();
            $rAttrs = $sheet->attributes($relNs);
            $nombre = (string)$attrs['name'];
            $rid = (string)$rAttrs['id'];
            if (!isset($relMap[$rid])) {
                continue;
            }

            $target = $relMap[$rid];
            if (!str_starts_with($target, '/')) {
                $target = 'xl/' . ltrim($target, '/');
            } else {
                $target = ltrim($target, '/');
            }

            $sheetXml = $zip->getFromName($target);
            if ($sheetXml === false) {
                continue;
            }

            $xml = simplexml_load_string($sheetXml);
            if ($xml === false) {
                continue;
            }
            $sheetMain = $xml->children($mainNs);
            if (!isset($sheetMain->sheetData)) {
                continue;
            }

            $filas = [];
            foreach ($sheetMain->sheetData->row as $row) {
                $fila = [];
                $rowMain = $row->children($mainNs);
                foreach ($rowMain->c as $c) {
                    $cAttrs = $c->attributes();
                    $cMain = $c->children($mainNs);
                    $ref = (string)$cAttrs['r'];
                    preg_match('/^([A-Z]+)\d+$/', $ref, $m);
                    $letters = $m[1] ?? 'A';
                    $col = 0;
                    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
                        $col = $col * 26 + (ord($letters[$i]) - 64);
                    }
                    $col--;

                    $tipo = (string)$cAttrs['t'];
                    $valor = '';
                    if ($tipo === 'inlineStr' && isset($cMain->is)) {
                        $isMain = $cMain->is->children($mainNs);
                        if (isset($isMain->t)) {
                            $valor = (string)$isMain->t;
                        } else {
                            foreach ($isMain->r as $run) {
                                $runMain = $run->children($mainNs);
                                $valor .= (string)$runMain->t;
                            }
                        }
                    } else {
                        $raw = isset($cMain->v) ? (string)$cMain->v : '';
                        if ($tipo === 's' && $raw !== '') {
                            $valor = $shared[(int)$raw] ?? '';
                        } elseif ($tipo === 'b') {
                            $valor = $raw === '1' ? '1' : '0';
                        } else {
                            $valor = $raw;
                        }
                    }
                    $fila[$col] = trim($valor);
                }

                if ($fila) {
                    $max = max(array_keys($fila));
                    $normalizada = array_fill(0, $max + 1, '');
                    foreach ($fila as $idx => $value) {
                        $normalizada[$idx] = $value;
                    }
                    $filas[] = $normalizada;
                }
            }
            $salida[$nombre] = $filas;
        }

        return $salida;
    } finally {
        $zip->close();
    }
}

function acImportacionLeerCsv(string $ruta): array
{
    $fh = fopen($ruta, 'rb');
    if (!$fh) {
        throw new RuntimeException('No fue posible abrir el CSV.');
    }

    $sample = fgets($fh);
    rewind($fh);
    $delimiter = substr_count((string)$sample, ';') > substr_count((string)$sample, ',') ? ';' : ',';
    $filas = [];
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        $filas[] = array_map(static fn($v) => trim((string)$v), $row);
    }
    fclose($fh);
    return ['CSV' => $filas];
}

function acImportacionBuscarFilaEncabezado(array $filas, array $requeridos, int $max = 30): ?array
{
    $limite = min(count($filas), $max);
    for ($i = 0; $i < $limite; $i++) {
        $map = [];
        foreach ($filas[$i] as $idx => $valor) {
            $h = acImportacionNormalizarHeader((string)$valor);
            if ($h !== '') {
                $map[$h] = $idx;
            }
        }

        $encontrados = 0;
        foreach ($requeridos as $alternativas) {
            foreach ($alternativas as $alt) {
                if (array_key_exists($alt, $map)) {
                    $encontrados++;
                    break;
                }
            }
        }

        if ($encontrados === count($requeridos)) {
            return ['index' => $i, 'map' => $map];
        }
    }
    return null;
}

function acImportacionValor(array $row, array $map, array $alternativas): string
{
    foreach ($alternativas as $alt) {
        if (isset($map[$alt])) {
            return trim((string)($row[$map[$alt]] ?? ''));
        }
    }
    return '';
}

function acImportacionDesdeCoordinadores(array $filas): array
{
    $header = acImportacionBuscarFilaEncabezado($filas, [
        ['CEDULA'],
        ['FUNCIONARIO_CORREO', 'FUNCIONARIO'],
        ['REGIONAL'],
        ['NOMBRE_DEL_PUNTO', 'CENTRO_ZONAL'],
    ]);
    if (!$header) {
        return [];
    }

    $map = $header['map'];
    $salida = [];
    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $regional = acImportacionValor($row, $map, ['REGIONAL']);
        $centro = acImportacionValor($row, $map, ['NOMBRE_DEL_PUNTO', 'CENTRO_ZONAL']);
        $funcionario = acImportacionValor($row, $map, ['FUNCIONARIO_CORREO', 'FUNCIONARIO']);
        [$nombre, $correo] = acImportacionExtraerNombreCorreo($funcionario);
        $correo = acImportacionLimpiarCorreo($correo);
        $documento = acImportacionValor($row, $map, ['CEDULA', 'DOCUMENTO']);
        $codigo = acImportacionValor($row, $map, ['CODIGO', 'CODIGO_CENTRO']);
        $perfil = acImportacionValor($row, $map, ['PERFIL']);
        $estado = acImportacionValor($row, $map, ['ESTADO']);

        if ($regional === '' && $centro === '' && $funcionario === '') {
            continue;
        }
        if ($regional === '' || $centro === '' || $nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $salida[] = ['_error' => "Fila " . ($i + 1) . ': faltan Regional, Punto, Nombre o correo válido.'];
            continue;
        }

        $salida[] = [
            'nivel' => 'ZONAL',
            'regional' => $regional,
            'centro_zonal' => $centro,
            'codigo_centro' => $codigo,
            'nombre' => $nombre,
            'correo' => $correo,
            'documento' => $documento,
            'tipo_responsable' => 'COORDINADOR',
            'perfil' => $perfil !== '' ? $perfil : 'Coordinador',
            'extension_ip' => '',
            'activo' => acImportacionEstadoActivo($estado),
            'fuente' => 'COORDINADORES_CZ',
        ];
    }
    return $salida;
}

function acImportacionDesdeEnlaces(array $filas): array
{
    $header = acImportacionBuscarFilaEncabezado($filas, [
        ['REGIONAL_CZ', 'REGIONAL'],
        ['ENLACE_RELACION_CON_EL_CIUDADANO', 'ENLACE_RELACION_CON_CIUDADANO'],
        ['E_MAIL_ENLACE', 'EMAIL_ENLACE', 'CORREO'],
    ]);
    if (!$header) {
        return [];
    }

    $map = $header['map'];
    $salida = [];
    $regionalActual = '';

    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $regionalCz = acImportacionValor($row, $map, ['REGIONAL_CZ', 'REGIONAL']);
        $coordinador = acImportacionValor($row, $map, ['COORDINADOR']);
        $nombre = acImportacionValor($row, $map, ['ENLACE_RELACION_CON_EL_CIUDADANO', 'ENLACE_RELACION_CON_CIUDADANO', 'NOMBRE']);
        $correo = acImportacionLimpiarCorreo(acImportacionValor($row, $map, ['E_MAIL_ENLACE', 'EMAIL_ENLACE', 'CORREO']));
        $documento = acImportacionValor($row, $map, ['CEDULA', 'DOCUMENTO']);
        $extension = acImportacionValor($row, $map, ['EXTENSION_IP', 'EXTENSION']);

        if ($regionalCz === '' && $nombre === '' && $correo === '') {
            continue;
        }

        $esRegional = $coordinador === '';
        if ($esRegional) {
            // Para Alertas Correos el destinatario REGIONAL se toma de Enlaces SIM.
            $regionalActual = acImportacionRegionalCanonica($regionalCz);
            $regional = $regionalActual;
            $centro = '';
            $nivel = 'REGIONAL';
        } else {
            // Los destinatarios ZONALES se versionan desde BBDD Coordinadores CZ.
            // Conservamos la jerarquía del archivo para detectar la siguiente regional,
            // pero no duplicamos un segundo destinatario zonal con el Enlace SIM.
            continue;
        }

        if ($regional === '' || $nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $salida[] = ['_error' => "Fila " . ($i + 1) . ': faltan Regional, Nombre o correo válido.'];
            continue;
        }

        $salida[] = [
            'nivel' => $nivel,
            'regional' => $regional,
            'centro_zonal' => $centro,
            'codigo_centro' => '',
            'nombre' => $nombre,
            'correo' => $correo,
            'documento' => $documento,
            'tipo_responsable' => 'ENLACE_RELACION_CIUDADANO',
            'perfil' => 'Enlace Relación con el Ciudadano',
            'extension_ip' => $extension,
            'activo' => true,
            'fuente' => 'ENLACES_SIM',
        ];
    }

    return $salida;
}

function acImportacionDesdePlantilla(array $filas): array
{
    $header = acImportacionBuscarFilaEncabezado($filas, [
        ['NIVEL'], ['REGIONAL'], ['NOMBRE'], ['CORREO'], ['TIPO_RESPONSABLE']
    ]);
    if (!$header) {
        return [];
    }

    $map = $header['map'];
    $salida = [];
    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $nivel = strtoupper(acImportacionValor($row, $map, ['NIVEL']));
        $regional = acImportacionValor($row, $map, ['REGIONAL']);
        $centro = acImportacionValor($row, $map, ['CENTRO_ZONAL', 'NOMBRE_DEL_PUNTO']);
        $nombre = acImportacionValor($row, $map, ['NOMBRE', 'FUNCIONARIO']);
        $correo = acImportacionLimpiarCorreo(acImportacionValor($row, $map, ['CORREO', 'EMAIL']));
        $documento = acImportacionValor($row, $map, ['DOCUMENTO', 'CEDULA']);
        $codigo = acImportacionValor($row, $map, ['CODIGO_CENTRO', 'CODIGO']);
        $tipo = acImportacionValor($row, $map, ['TIPO_RESPONSABLE']);
        $perfil = acImportacionValor($row, $map, ['PERFIL']);
        $extension = acImportacionValor($row, $map, ['EXTENSION_IP', 'EXTENSION']);
        $estado = acImportacionValor($row, $map, ['ESTADO']);

        if ($nivel === '' && $regional === '' && $nombre === '' && $correo === '') {
            continue;
        }
        $nivel = in_array($nivel, ['REGIONAL', 'ZONAL', 'CENTRO_ZONAL'], true) ? $nivel : 'ZONAL';
        if ($nivel === 'CENTRO_ZONAL') $nivel = 'ZONAL';

        if ($regional === '' || $nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL) || ($nivel === 'ZONAL' && $centro === '')) {
            $salida[] = ['_error' => "Fila " . ($i + 1) . ': datos obligatorios incompletos.'];
            continue;
        }

        $salida[] = [
            'nivel' => $nivel,
            'regional' => $regional,
            'centro_zonal' => $nivel === 'REGIONAL' ? '' : $centro,
            'codigo_centro' => $codigo,
            'nombre' => $nombre,
            'correo' => $correo,
            'documento' => $documento,
            'tipo_responsable' => $tipo !== '' ? strtoupper($tipo) : 'OTRO',
            'perfil' => $perfil,
            'extension_ip' => $extension,
            'activo' => acImportacionEstadoActivo($estado),
            'fuente' => 'PLANTILLA_RESPONSABLES',
        ];
    }
    return $salida;
}

function acImportacionDetectarRegistros(string $ruta, string $nombreArchivo): array
{
    $ext = strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION));
    $hojas = $ext === 'csv' ? acImportacionLeerCsv($ruta) : acImportacionLeerXlsx($ruta);

    // 1) Base oficial de coordinadores CZ.
    foreach ($hojas as $filas) {
        $rows = acImportacionDesdeCoordinadores($filas);
        if ($rows) {
            return ['fuente' => 'COORDINADORES_CZ', 'registros' => $rows];
        }
    }

    // 2) Base de Enlaces SIM: prioriza hoja REGIONALES.
    foreach ($hojas as $nombre => $filas) {
        if (acTerritorioNormalizarClave($nombre) === 'REGIONALES') {
            $rows = acImportacionDesdeEnlaces($filas);
            if ($rows) {
                return ['fuente' => 'ENLACES_SIM', 'registros' => $rows];
            }
        }
    }
    foreach ($hojas as $filas) {
        $rows = acImportacionDesdeEnlaces($filas);
        if ($rows) {
            return ['fuente' => 'ENLACES_SIM', 'registros' => $rows];
        }
    }

    // 3) Plantilla genérica del módulo.
    foreach ($hojas as $filas) {
        $rows = acImportacionDesdePlantilla($filas);
        if ($rows) {
            return ['fuente' => 'PLANTILLA_RESPONSABLES', 'registros' => $rows];
        }
    }

    throw new RuntimeException('No fue posible reconocer los encabezados del archivo. Use la plantilla oficial o las bases de Coordinadores CZ / Enlaces SIM.');
}

function acImportacionResponsablesActivosFuente(mysqli $db, string $fuente): array
{
    if ($fuente === 'COORDINADORES_CZ') {
        $tipoLegacy = 'COORDINADOR';
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND (
                    COALESCE(acr_fuente,'')=?
                    OR (COALESCE(acr_fuente,'')='' AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?)
               )
             ORDER BY acr_id ASC"
        );
        $stmt->bind_param('ss', $fuente, $tipoLegacy);
    } elseif ($fuente === 'ENLACES_SIM') {
        $tipoLegacy = 'ENLACE_RELACION_CIUDADANO';
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND (
                    COALESCE(acr_fuente,'')=?
                    OR (COALESCE(acr_fuente,'')='' AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?)
               )
             ORDER BY acr_id ASC"
        );
        $stmt->bind_param('ss', $fuente, $tipoLegacy);
    } else {
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1 AND COALESCE(acr_fuente,'')=?
             ORDER BY acr_id ASC"
        );
        $stmt->bind_param('s', $fuente);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function acImportacionFilaDbARegistro(array $row): array
{
    return [
        'nivel' => (string)$row['acr_nivel'],
        'regional' => (string)$row['acr_regional'],
        'centro_zonal' => (string)($row['acr_centro_zonal'] ?? ''),
        'codigo_centro' => (string)($row['acr_codigo_centro'] ?? ''),
        'nombre' => (string)$row['acr_nombre'],
        'correo' => (string)$row['acr_correo'],
        'documento' => (string)($row['acr_documento'] ?? ''),
        'tipo_responsable' => (string)($row['acr_tipo_responsable'] ?? ''),
        'perfil' => (string)($row['acr_perfil'] ?? ''),
        'extension_ip' => (string)($row['acr_extension_ip'] ?? ''),
        'activo' => (int)$row['acr_activo'] === 1,
        'fuente' => (string)($row['acr_fuente'] ?? ''),
    ];
}

function acPrepararSincronizacionResponsables(mysqli $db, string $ruta, string $nombreArchivo): array
{
    $detectado = acImportacionDetectarRegistros($ruta, $nombreArchivo);
    $fuente = $detectado['fuente'];
    $validos = [];
    $errores = [];

    foreach ($detectado['registros'] as $r) {
        if (isset($r['_error'])) {
            $errores[] = $r['_error'];
            continue;
        }
        $r['fingerprint'] = acImportacionFingerprint($r);
        $r['clave_asignacion'] = acImportacionClaveAsignacion($r);
        $validos[] = $r;
    }

    if (!$validos) {
        throw new RuntimeException('El archivo no contiene registros válidos para sincronizar.');
    }

    // Deduplicación dentro del propio archivo: la última fila gana.
    $dedup = [];
    foreach ($validos as $r) {
        $dedup[$r['clave_asignacion']] = $r;
    }
    $validos = array_values($dedup);

    $actuales = acImportacionResponsablesActivosFuente($db, $fuente);
    $actualPorClave = [];
    foreach ($actuales as $row) {
        $r = acImportacionFilaDbARegistro($row);
        $r['fingerprint'] = acImportacionFingerprint($r);
        $r['clave_asignacion'] = acImportacionClaveAsignacion($r);
        $r['_db'] = $row;
        $actualPorClave[$r['clave_asignacion']][] = $r;
    }

    $resumen = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'inactivaciones_explicitas' => 0, 'no_encontrados' => 0, 'invalidos' => count($errores)];
    $diferencias = [];
    $clavesArchivo = [];

    foreach ($validos as $r) {
        $clave = $r['clave_asignacion'];
        $clavesArchivo[$clave] = true;
        $candidatos = $actualPorClave[$clave] ?? [];
        $matching = null;
        foreach ($candidatos as $c) {
            if (hash_equals($c['fingerprint'], $r['fingerprint'])) {
                $matching = $c;
                break;
            }
        }

        if (!$r['activo']) {
            $resumen['inactivaciones_explicitas']++;
            $diferencias[] = ['tipo' => 'INACTIVAR', 'nuevo' => $r, 'actual' => $candidatos[0] ?? null];
        } elseif (!$candidatos) {
            $resumen['nuevos']++;
            $diferencias[] = ['tipo' => 'NUEVO', 'nuevo' => $r, 'actual' => null];
        } elseif ($matching) {
            $resumen['sin_cambios']++;
        } else {
            $resumen['actualizados']++;
            $diferencias[] = ['tipo' => 'CAMBIO_RESPONSABLE', 'nuevo' => $r, 'actual' => $candidatos[0] ?? null];
        }
    }

    foreach ($actualPorClave as $clave => $rows) {
        if (!isset($clavesArchivo[$clave])) {
            $resumen['no_encontrados']++;
            $diferencias[] = ['tipo' => 'NO_EN_ARCHIVO', 'nuevo' => null, 'actual' => $rows[0] ?? null];
        }
    }

    return [
        'fuente' => $fuente,
        'registros' => $validos,
        'resumen' => $resumen,
        'errores' => $errores,
        'diferencias' => $diferencias,
        'nombre_archivo' => $nombreArchivo,
        'sha256' => hash_file('sha256', $ruta) ?: '',
    ];
}

function acImportacionCerrarAsignacion(mysqli $db, string $clave, string $fuente, string $usuario): int
{
    $actuales = acImportacionResponsablesActivosFuente($db, $fuente);
    $ids = [];
    foreach ($actuales as $row) {
        $r = acImportacionFilaDbARegistro($row);
        if (acImportacionClaveAsignacion($r) === $clave) {
            $ids[] = (int)$row['acr_id'];
        }
    }

    if (!$ids) return 0;
    $cerrados = 0;
    $stmt = $db->prepare(
        'UPDATE tb_alerta_correo_responsable
         SET acr_activo=0, acr_vigente_hasta=NOW(), acr_actualizado_por=?, acr_fecha_actualizacion=NOW()
         WHERE acr_id=? AND acr_activo=1'
    );
    foreach ($ids as $id) {
        $stmt->bind_param('si', $usuario, $id);
        $stmt->execute();
        $cerrados += $stmt->affected_rows;
    }
    $stmt->close();
    return $cerrados;
}

function acImportacionInsertarResponsable(mysqli $db, array $r, int $puntoId, string $usuario): int
{
    $nivel = $r['nivel'];
    $regional = $r['regional'];
    $centro = $r['centro_zonal'];
    $codigo = $r['codigo_centro'];
    $nombre = $r['nombre'];
    $correo = $r['correo'];
    $documento = $r['documento'];
    $tipo = $r['tipo_responsable'];
    $perfil = $r['perfil'];
    $extension = $r['extension_ip'];
    $fuente = $r['fuente'];
    $hash = $r['fingerprint'];
    $activo = $r['activo'] ? 1 : 0;

    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_responsable
        (acr_punto_atencion_id, acr_nivel, acr_regional, acr_centro_zonal, acr_codigo_centro,
         acr_nombre, acr_correo, acr_documento, acr_tipo_responsable, acr_perfil, acr_extension_ip,
         acr_activo, acr_vigente_desde, acr_vigente_hasta, acr_fuente, acr_hash_version,
         acr_actualizado_por, acr_fecha_actualizacion)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NULL,?,?,?,NOW())'
    );
    $stmt->bind_param(
        'issssssssssisss',
        $puntoId,
        $nivel,
        $regional,
        $centro,
        $codigo,
        $nombre,
        $correo,
        $documento,
        $tipo,
        $perfil,
        $extension,
        $activo,
        $fuente,
        $hash,
        $usuario
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function acAplicarSincronizacionResponsables(mysqli $db, array $preview, string $usuario): array
{
    $fuente = (string)$preview['fuente'];
    $resultado = ['nuevos' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'inactivados' => 0, 'no_encontrados' => (int)($preview['resumen']['no_encontrados'] ?? 0)];

    $actuales = acImportacionResponsablesActivosFuente($db, $fuente);
    $actualPorClave = [];
    foreach ($actuales as $row) {
        $r = acImportacionFilaDbARegistro($row);
        $r['fingerprint'] = acImportacionFingerprint($r);
        $r['clave_asignacion'] = acImportacionClaveAsignacion($r);
        $r['_db'] = $row;
        $actualPorClave[$r['clave_asignacion']][] = $r;
    }

    foreach ($preview['registros'] as $r) {
        $clave = $r['clave_asignacion'];
        $candidatos = $actualPorClave[$clave] ?? [];
        $matching = null;
        foreach ($candidatos as $c) {
            if (hash_equals($c['fingerprint'], $r['fingerprint'])) {
                $matching = $c;
                break;
            }
        }

        $regionalId = acTerritorioAsegurarRegional($db, $r['regional'], $fuente);
        if ($r['nivel'] === 'REGIONAL') {
            $puntoId = $regionalId;
        } else {
            $puntoId = acTerritorioAsegurarPunto($db, $r['regional'], $r['centro_zonal'], $r['codigo_centro'] ?: null, $fuente);
        }

        if (!$r['activo']) {
            $resultado['inactivados'] += acImportacionCerrarAsignacion($db, $clave, $fuente, $usuario);
            continue;
        }

        if ($matching) {
            // Vincula registros legacy al nuevo catálogo, sin generar una versión nueva.
            $id = (int)$matching['_db']['acr_id'];
            $codigo = trim((string)($r['codigo_centro'] ?? ''));
            $stmt = $db->prepare(
                "UPDATE tb_alerta_correo_responsable
                 SET acr_punto_atencion_id=?,
                     acr_codigo_centro=COALESCE(NULLIF(?,''), acr_codigo_centro),
                     acr_hash_version=COALESCE(acr_hash_version,?),
                     acr_fuente=?, acr_actualizado_por=?, acr_fecha_actualizacion=NOW()
                 WHERE acr_id=?"
            );
            $stmt->bind_param('issssi', $puntoId, $codigo, $r['fingerprint'], $fuente, $usuario, $id);
            $stmt->execute();
            $stmt->close();
            $resultado['sin_cambios']++;
            continue;
        }

        if ($candidatos) {
            acImportacionCerrarAsignacion($db, $clave, $fuente, $usuario);
            $resultado['actualizados']++;
        } else {
            $resultado['nuevos']++;
        }

        acImportacionInsertarResponsable($db, $r, $puntoId, $usuario);
    }

    // Importante: los registros que faltan en el archivo NO se inactivan automáticamente.
    return $resultado;
}
