<?php
declare(strict_types=1);

/**
 * Catálogo territorial y versionamiento de responsables para Alertas Correos.
 * Mantiene compatibilidad con los campos de texto actuales del módulo.
 */

function acTerritorioNormalizarClave(string $valor): string
{
    $valor = trim($valor);
    if ($valor === '') {
        return '';
    }

    // Primero resolvemos explícitamente los caracteres españoles más comunes.
    // En algunas instalaciones de iconv de Windows, transliterar "Á/Í/Ñ" puede
    // introducir separadores (p. ej. BOGOT A). Esta tabla evita ese comportamiento.
    $valor = strtr($valor, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);

    // También elimina marcas combinantes si el archivo viene en Unicode descompuesto.
    $sinMarcas = preg_replace('/\p{Mn}+/u', '', $valor);
    if (is_string($sinMarcas)) {
        $valor = $sinMarcas;
    }

    $valor = mb_strtoupper($valor, 'UTF-8');
    $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if (is_string($transliterado) && $transliterado !== '') {
        $valor = $transliterado;
    }

    $valor = preg_replace('/[^A-Z0-9]+/', ' ', $valor) ?? $valor;
    return trim(preg_replace('/\s+/', ' ', $valor) ?? $valor);
}

function acTerritorioListarRegionales(mysqli $db): array
{
    $sql = "SELECT acp_id, acp_codigo, acp_nombre, acp_regional
            FROM tb_alerta_correo_punto_atencion
            WHERE acp_tipo='REGIONAL' AND acp_activo=1
            ORDER BY acp_nombre ASC";
    $rs = $db->query($sql);
    return $rs->fetch_all(MYSQLI_ASSOC);
}

function acTerritorioListarPuntosRegional(mysqli $db, int $regionalId): array
{
    $stmt = $db->prepare(
        "SELECT acp_id, acp_codigo, acp_nombre, acp_regional
         FROM tb_alerta_correo_punto_atencion
         WHERE acp_tipo='CENTRO_ZONAL'
           AND acp_activo=1
           AND acp_padre_id=?
         ORDER BY acp_nombre ASC"
    );
    $stmt->bind_param('i', $regionalId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function acTerritorioObtenerRegional(mysqli $db, int $regionalId): ?array
{
    $stmt = $db->prepare(
        "SELECT acp_id, acp_codigo, acp_nombre, acp_regional, acp_activo
         FROM tb_alerta_correo_punto_atencion
         WHERE acp_id=? AND acp_tipo='REGIONAL' LIMIT 1"
    );
    $stmt->bind_param('i', $regionalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function acTerritorioObtenerPunto(mysqli $db, int $puntoId): ?array
{
    $stmt = $db->prepare(
        "SELECT acp_id, acp_padre_id, acp_codigo, acp_nombre, acp_regional, acp_activo
         FROM tb_alerta_correo_punto_atencion
         WHERE acp_id=? AND acp_tipo='CENTRO_ZONAL' LIMIT 1"
    );
    $stmt->bind_param('i', $puntoId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function acTerritorioValidarSeleccion(mysqli $db, int $regionalId, int $puntoId): ?array
{
    if ($regionalId <= 0 || $puntoId <= 0) {
        return null;
    }

    $regional = acTerritorioObtenerRegional($db, $regionalId);
    $punto = acTerritorioObtenerPunto($db, $puntoId);

    if (!$regional || !$punto) {
        return null;
    }

    if ((int)$regional['acp_activo'] !== 1 || (int)$punto['acp_activo'] !== 1) {
        return null;
    }

    if ((int)$punto['acp_padre_id'] !== (int)$regional['acp_id']) {
        return null;
    }

    if (acTerritorioNormalizarClave((string)$punto['acp_regional']) !== acTerritorioNormalizarClave((string)$regional['acp_nombre'])) {
        return null;
    }

    return ['regional' => $regional, 'punto' => $punto];
}

function acTerritorioVincularCaso(mysqli $db, int $casoId, int $regionalId, int $puntoId): void
{
    $stmt = $db->prepare(
        'UPDATE tb_alerta_correo_caso
         SET acc_regional_id=?, acc_punto_atencion_id=?
         WHERE acc_id=?'
    );
    $stmt->bind_param('iii', $regionalId, $puntoId, $casoId);
    $stmt->execute();
    $stmt->close();
}

function acTerritorioAsegurarRegional(mysqli $db, string $regional, ?string $fuente = null): int
{
    $regional = trim($regional);
    $clave = acTerritorioNormalizarClave($regional);
    if ($regional === '' || $clave === '') {
        throw new InvalidArgumentException('Regional vacía.');
    }

    $stmt = $db->prepare(
        "SELECT acp_id FROM tb_alerta_correo_punto_atencion
         WHERE acp_tipo='REGIONAL' AND acp_regional_clave=? LIMIT 1"
    );
    $stmt->bind_param('s', $clave);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $id = (int)$row['acp_id'];
        $stmt = $db->prepare(
            "UPDATE tb_alerta_correo_punto_atencion
             SET acp_regional=?, acp_nombre=?, acp_nombre_clave=?, acp_activo=1,
                 acp_fuente=COALESCE(?, acp_fuente)
             WHERE acp_id=?"
        );
        $stmt->bind_param('ssssi', $regional, $regional, $clave, $fuente, $id);
        $stmt->execute();
        $stmt->close();
        return $id;
    }

    $tipo = 'REGIONAL';
    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_punto_atencion
        (acp_padre_id, acp_codigo, acp_tipo, acp_regional, acp_regional_clave,
         acp_nombre, acp_nombre_clave, acp_activo, acp_fuente)
         VALUES (NULL, NULL, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->bind_param('ssssss', $tipo, $regional, $clave, $regional, $clave, $fuente);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function acTerritorioAsegurarPunto(
    mysqli $db,
    string $regional,
    string $nombre,
    ?string $codigo,
    ?string $fuente = null
): int {
    $regional = trim($regional);
    $nombre = trim($nombre);
    $regionalClave = acTerritorioNormalizarClave($regional);
    $nombreClave = acTerritorioNormalizarClave($nombre);

    if ($regional === '' || $nombre === '' || $regionalClave === '' || $nombreClave === '') {
        throw new InvalidArgumentException('Regional o punto de atención vacío.');
    }

    $regionalId = acTerritorioAsegurarRegional($db, $regional, $fuente);

    $stmt = $db->prepare(
        "SELECT acp_id FROM tb_alerta_correo_punto_atencion
         WHERE acp_tipo='CENTRO_ZONAL'
           AND acp_regional_clave=?
           AND acp_nombre_clave=?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $regionalClave, $nombreClave);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $id = (int)$row['acp_id'];
        $stmt = $db->prepare(
            "UPDATE tb_alerta_correo_punto_atencion
             SET acp_padre_id=?,
                 acp_codigo=COALESCE(NULLIF(?,''), acp_codigo),
                 acp_regional=?, acp_nombre=?,
                 acp_activo=1, acp_fuente=COALESCE(?, acp_fuente)
             WHERE acp_id=?"
        );
        $stmt->bind_param('issssi', $regionalId, $codigo, $regional, $nombre, $fuente, $id);
        $stmt->execute();
        $stmt->close();
        return $id;
    }

    $tipo = 'CENTRO_ZONAL';
    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_punto_atencion
        (acp_padre_id, acp_codigo, acp_tipo, acp_regional, acp_regional_clave,
         acp_nombre, acp_nombre_clave, acp_activo, acp_fuente)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->bind_param('isssssss', $regionalId, $codigo, $tipo, $regional, $regionalClave, $nombre, $nombreClave, $fuente);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function acTerritorioResponsablesVigentes(mysqli $db, array $caso): array
{
    $regional = trim((string)($caso['acc_regional'] ?? ''));
    $centro = trim((string)($caso['acc_centro_zonal'] ?? ''));
    $regionalId = (int)($caso['acc_regional_id'] ?? 0);
    $puntoId = (int)($caso['acc_punto_atencion_id'] ?? 0);

    $salida = ['regional' => [], 'zonal' => []];

    // Preferencia de negocio:
    // - Regional: Enlace Relación con el Ciudadano vigente.
    // - Zonal: Coordinador del Centro Zonal vigente.
    // La relación por ID territorial es prioritaria; el texto queda solo como
    // compatibilidad para casos creados antes de la migración territorial.
    $tipoRegionalUpper = 'ENLACE_RELACION_CIUDADANO';
    if ($regionalId > 0) {
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel)='REGIONAL'
               AND acr_punto_atencion_id=?
               AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?
             ORDER BY acr_id DESC"
        );
        $stmt->bind_param('is', $regionalId, $tipoRegionalUpper);
        $stmt->execute();
        $salida['regional'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    if (!$salida['regional']) {
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel)='REGIONAL'
               AND UPPER(TRIM(acr_regional))=UPPER(TRIM(?))
               AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?
             ORDER BY acr_id DESC"
        );
        $stmt->bind_param('ss', $regional, $tipoRegionalUpper);
        $stmt->execute();
        $salida['regional'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // Fallback compatible con registros legacy si aún no fueron clasificados.
    if (!$salida['regional']) {
        if ($regionalId > 0) {
            $stmt = $db->prepare(
                "SELECT * FROM tb_alerta_correo_responsable
                 WHERE acr_activo=1
                   AND UPPER(acr_nivel)='REGIONAL'
                   AND acr_punto_atencion_id=?
                 ORDER BY acr_id DESC"
            );
            $stmt->bind_param('i', $regionalId);
        } else {
            $stmt = $db->prepare(
                "SELECT * FROM tb_alerta_correo_responsable
                 WHERE acr_activo=1
                   AND UPPER(acr_nivel)='REGIONAL'
                   AND UPPER(TRIM(acr_regional))=UPPER(TRIM(?))
                 ORDER BY acr_id DESC"
            );
            $stmt->bind_param('s', $regional);
        }
        $stmt->execute();
        $salida['regional'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $tipoZonalUpper = 'COORDINADOR';
    if ($puntoId > 0) {
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel) IN ('ZONAL','CENTRO_ZONAL')
               AND acr_punto_atencion_id=?
               AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?
             ORDER BY acr_id DESC"
        );
        $stmt->bind_param('is', $puntoId, $tipoZonalUpper);
    } else {
        $stmt = $db->prepare(
            "SELECT * FROM tb_alerta_correo_responsable
             WHERE acr_activo=1
               AND UPPER(acr_nivel) IN ('ZONAL','CENTRO_ZONAL')
               AND UPPER(TRIM(acr_regional))=UPPER(TRIM(?))
               AND UPPER(TRIM(acr_centro_zonal))=UPPER(TRIM(?))
               AND UPPER(TRIM(COALESCE(acr_tipo_responsable,'')))=?
             ORDER BY acr_id DESC"
        );
        $stmt->bind_param('sss', $regional, $centro, $tipoZonalUpper);
    }
    $stmt->execute();
    $salida['zonal'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$salida['zonal']) {
        if ($puntoId > 0) {
            $stmt = $db->prepare(
                "SELECT * FROM tb_alerta_correo_responsable
                 WHERE acr_activo=1
                   AND UPPER(acr_nivel) IN ('ZONAL','CENTRO_ZONAL')
                   AND acr_punto_atencion_id=?
                 ORDER BY acr_id DESC"
            );
            $stmt->bind_param('i', $puntoId);
        } else {
            $stmt = $db->prepare(
                "SELECT * FROM tb_alerta_correo_responsable
                 WHERE acr_activo=1
                   AND UPPER(acr_nivel) IN ('ZONAL','CENTRO_ZONAL')
                   AND UPPER(TRIM(acr_regional))=UPPER(TRIM(?))
                   AND UPPER(TRIM(acr_centro_zonal))=UPPER(TRIM(?))
                 ORDER BY acr_id DESC"
            );
            $stmt->bind_param('ss', $regional, $centro);
        }
        $stmt->execute();
        $salida['zonal'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    return $salida;
}

function acTerritorioSnapshotsCaso(mysqli $db, int $casoId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM tb_alerta_correo_destinatario_snapshot
         WHERE acs_caso_id=?
         ORDER BY acs_fecha_snapshot DESC, acs_id DESC'
    );
    $stmt->bind_param('i', $casoId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function acTerritorioDestinatariosParaVista(mysqli $db, array $caso): array
{
    $casoId = (int)($caso['acc_id'] ?? 0);
    $snapshots = $casoId > 0 ? acTerritorioSnapshotsCaso($db, $casoId) : [];

    if ($snapshots) {
        $fecha = (string)$snapshots[0]['acs_fecha_snapshot'];
        $salida = ['regional' => [], 'zonal' => [], '_origen' => 'SNAPSHOT', '_fecha' => $fecha];
        foreach ($snapshots as $row) {
            if ((string)$row['acs_fecha_snapshot'] !== $fecha) {
                continue; // última aprobación únicamente para la vista principal.
            }
            $adaptado = [
                'acr_id' => $row['acs_responsable_id'],
                'acr_nivel' => $row['acs_nivel'],
                'acr_regional' => $row['acs_regional'],
                'acr_centro_zonal' => $row['acs_centro_zonal'],
                'acr_nombre' => $row['acs_nombre'],
                'acr_correo' => $row['acs_correo'],
                'acr_documento' => $row['acs_documento'],
                'acr_tipo_responsable' => $row['acs_tipo_responsable'],
                'acr_activo' => 0,
            ];
            $nivel = strtoupper((string)$row['acs_nivel']);
            if ($nivel === 'REGIONAL') {
                $salida['regional'][] = $adaptado;
            } else {
                $salida['zonal'][] = $adaptado;
            }
        }
        return $salida;
    }

    $vigentes = acTerritorioResponsablesVigentes($db, $caso);
    $vigentes['_origen'] = 'VIGENTE';
    $vigentes['_fecha'] = null;
    return $vigentes;
}
