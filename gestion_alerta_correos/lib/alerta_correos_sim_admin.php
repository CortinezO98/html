<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_territorio.php';
require_once __DIR__ . '/alerta_correos_sim_fuente.php';

/**
 * Administración manual de la Fuente SIM.
 * - El SIM es único.
 * - Regional y Centro Zonal se validan contra el catálogo territorial.
 * - Cada edición/inactivación/reactivación conserva snapshot histórico.
 */

function acSimAdminObtenerPorId(mysqli $db, int $id): ?array
{
    if ($id <= 0) return null;
    $stmt = $db->prepare('SELECT * FROM tb_alerta_correo_radicado_sim WHERE ars_id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function acSimAdminObtenerPorSim(mysqli $db, string $sim): ?array
{
    $sim = trim($sim);
    if ($sim === '') return null;
    $stmt = $db->prepare('SELECT * FROM tb_alerta_correo_radicado_sim WHERE ars_sim=? LIMIT 1');
    $stmt->bind_param('s', $sim);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function acSimAdminFechaFormulario(?string $valor, bool $conHora = false): ?string
{
    $valor = trim((string)$valor);
    if ($valor === '') return null;
    $normalizada = acSimFuenteExcelFecha($valor, $conHora);
    if ($normalizada === null) {
        throw new InvalidArgumentException($conHora ? 'La fecha/hora ingresada no es válida.' : 'La fecha ingresada no es válida.');
    }
    return $normalizada;
}

function acSimAdminConstruirRegistro(mysqli $db, array $input): array
{
    $sim = trim((string)($input['sim'] ?? ''));
    if ($sim === '') {
        throw new InvalidArgumentException('El número SIM es obligatorio.');
    }
    if (mb_strlen($sim, 'UTF-8') > 50) {
        throw new InvalidArgumentException('El número SIM supera el máximo de 50 caracteres.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $sim)) {
        throw new InvalidArgumentException('El número SIM contiene caracteres no permitidos.');
    }

    $regionalId = (int)($input['regional_id'] ?? 0);
    $puntoId = (int)($input['punto_atencion_id'] ?? 0);
    $territorio = acTerritorioValidarSeleccion($db, $regionalId, $puntoId);
    if (!$territorio) {
        throw new InvalidArgumentException('Seleccione una Regional y un Centro Zonal / Punto de atención válidos.');
    }

    $regional = (string)$territorio['regional']['acp_nombre'];
    $punto = (string)$territorio['punto']['acp_nombre'];

    $registro = [
        'sim' => $sim,
        'estado' => trim((string)($input['estado'] ?? '')),
        'fecha_alerta' => acSimAdminFechaFormulario($input['fecha_alerta'] ?? null, false),
        'fecha_atencion' => acSimAdminFechaFormulario($input['fecha_atencion'] ?? null, true),
        'regional' => $regional,
        'punto_atencion' => $punto,
        'categoria' => trim((string)($input['categoria'] ?? '')),
        'descripcion' => trim((string)($input['descripcion'] ?? '')),
        'afecta_linea_tecnica' => trim((string)($input['afecta_linea_tecnica'] ?? '')),
        'fecha_remision_agente' => acSimAdminFechaFormulario($input['fecha_remision_agente'] ?? null, true),
        'justificacion' => trim((string)($input['justificacion'] ?? '')),
        'fecha_notificacion_regional' => acSimAdminFechaFormulario($input['fecha_notificacion_regional'] ?? null, true),
        'subcategoria' => trim((string)($input['subcategoria'] ?? '')),
        'regional_id' => $regionalId,
        'punto_atencion_id' => $puntoId,
        'mapeo_estado' => 'MAPEADO',
    ];
    $registro['fingerprint'] = acSimFuenteFingerprint($registro);
    return $registro;
}

function acSimAdminCrear(mysqli $db, array $input, string $usuario): int
{
    $r = acSimAdminConstruirRegistro($db, $input);
    if (acSimAdminObtenerPorSim($db, (string)$r['sim'])) {
        throw new RuntimeException('El radicado SIM ' . $r['sim'] . ' ya existe. Consulte el registro existente y utilice Editar.');
    }

    $fuente = 'REGISTRO_MANUAL';
    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_radicado_sim
        (ars_sim,ars_estado,ars_fecha_alerta,ars_fecha_atencion,ars_regional,ars_punto_atencion,
         ars_categoria,ars_descripcion,ars_afecta_linea_tecnica,ars_fecha_remision_agente,ars_justificacion,
         ars_fecha_notificacion_regional,ars_subcategoria,ars_regional_id,ars_punto_atencion_id,ars_mapeo_estado,
         ars_activo,ars_fuente,ars_hash_version,ars_usuario_actualizacion)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)'
    );

    $sim=(string)$r['sim']; $estado=(string)$r['estado']; $fa=$r['fecha_alerta']; $fat=$r['fecha_atencion'];
    $regional=(string)$r['regional']; $punto=(string)$r['punto_atencion']; $categoria=(string)$r['categoria'];
    $descripcion=(string)$r['descripcion']; $afecta=(string)$r['afecta_linea_tecnica']; $fra=$r['fecha_remision_agente'];
    $justificacion=(string)$r['justificacion']; $fnr=$r['fecha_notificacion_regional']; $subcategoria=(string)$r['subcategoria'];
    $regionalId=(int)$r['regional_id']; $puntoId=(int)$r['punto_atencion_id']; $mapeo=(string)$r['mapeo_estado'];
    $hash=(string)$r['fingerprint'];

    $stmt->bind_param(
        'sssssssssssssiissss',
        $sim,$estado,$fa,$fat,$regional,$punto,$categoria,$descripcion,$afecta,$fra,$justificacion,$fnr,$subcategoria,
        $regionalId,$puntoId,$mapeo,$fuente,$hash,$usuario
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function acSimAdminActualizar(mysqli $db, int $id, array $input, string $usuario): void
{
    $actual = acSimAdminObtenerPorId($db, $id);
    if (!$actual) throw new RuntimeException('El registro SIM no existe.');

    // El identificador SIM no se modifica al editar: evita romper trazabilidad.
    $input['sim'] = (string)$actual['ars_sim'];
    $r = acSimAdminConstruirRegistro($db, $input);
    acSimFuenteSnapshotHistorial($db, $actual, $usuario, 'ACTUALIZACION');

    $fuente = 'EDICION_MANUAL';
    $stmt = $db->prepare(
        'UPDATE tb_alerta_correo_radicado_sim SET
         ars_estado=?,ars_fecha_alerta=?,ars_fecha_atencion=?,ars_regional=?,ars_punto_atencion=?,
         ars_categoria=?,ars_descripcion=?,ars_afecta_linea_tecnica=?,ars_fecha_remision_agente=?,ars_justificacion=?,
         ars_fecha_notificacion_regional=?,ars_subcategoria=?,ars_regional_id=?,ars_punto_atencion_id=?,ars_mapeo_estado=?,
         ars_fuente=?,ars_hash_version=?,ars_usuario_actualizacion=?,ars_fecha_actualizacion=NOW()
         WHERE ars_id=?'
    );

    $estado=(string)$r['estado']; $fa=$r['fecha_alerta']; $fat=$r['fecha_atencion']; $regional=(string)$r['regional'];
    $punto=(string)$r['punto_atencion']; $categoria=(string)$r['categoria']; $descripcion=(string)$r['descripcion'];
    $afecta=(string)$r['afecta_linea_tecnica']; $fra=$r['fecha_remision_agente']; $justificacion=(string)$r['justificacion'];
    $fnr=$r['fecha_notificacion_regional']; $subcategoria=(string)$r['subcategoria']; $regionalId=(int)$r['regional_id'];
    $puntoId=(int)$r['punto_atencion_id']; $mapeo=(string)$r['mapeo_estado']; $hash=(string)$r['fingerprint'];

    $stmt->bind_param(
        'ssssssssssssiissssi',
        $estado,$fa,$fat,$regional,$punto,$categoria,$descripcion,$afecta,$fra,$justificacion,$fnr,$subcategoria,
        $regionalId,$puntoId,$mapeo,$fuente,$hash,$usuario,$id
    );
    $stmt->execute();
    $stmt->close();
}

function acSimAdminCambiarActivo(mysqli $db, int $id, bool $activo, string $usuario): void
{
    $actual = acSimAdminObtenerPorId($db, $id);
    if (!$actual) throw new RuntimeException('El registro SIM no existe.');
    if ((int)$actual['ars_activo'] === ($activo ? 1 : 0)) return;

    acSimFuenteSnapshotHistorial($db, $actual, $usuario, $activo ? 'REACTIVACION' : 'INACTIVACION');
    $valor = $activo ? 1 : 0;
    $stmt = $db->prepare('UPDATE tb_alerta_correo_radicado_sim SET ars_activo=?, ars_usuario_actualizacion=?, ars_fecha_actualizacion=NOW() WHERE ars_id=?');
    $stmt->bind_param('isi', $valor, $usuario, $id);
    $stmt->execute();
    $stmt->close();
}

function acSimAdminHistorial(mysqli $db, string $sim, int $limite = 50): array
{
    $limite = max(1, min(100, $limite));
    $sql = 'SELECT * FROM tb_alerta_correo_radicado_sim_historial WHERE arsh_sim=? ORDER BY arsh_fecha DESC, arsh_id DESC LIMIT ' . $limite;
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $sim);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function acSimAdminListar(mysqli $db, string $q, string $estadoActivo, int $pagina = 1, int $porPagina = 25): array
{
    $q = trim($q);
    $pagina = max(1, $pagina);
    $porPagina = max(10, min(100, $porPagina));
    $offset = ($pagina - 1) * $porPagina;

    $estadoActivo = in_array($estadoActivo, ['1','0','todos'], true) ? $estadoActivo : '1';
    $where = [];
    if ($estadoActivo === '1') $where[] = 'ars_activo=1';
    elseif ($estadoActivo === '0') $where[] = 'ars_activo=0';

    if ($q !== '') {
        $where[] = '(ars_sim LIKE ? OR ars_regional LIKE ? OR ars_punto_atencion LIKE ? OR ars_categoria LIKE ? OR ars_descripcion LIKE ?)';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $countSql = 'SELECT COUNT(*) AS total FROM tb_alerta_correo_radicado_sim' . $whereSql;
    $stmt = $db->prepare($countSql);
    if ($q !== '') {
        $like1 = '%' . $q . '%'; $like2 = $like1; $like3 = $like1; $like4 = $like1; $like5 = $like1;
        $stmt->bind_param('sssss', $like1, $like2, $like3, $like4, $like5);
    }
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $sql = 'SELECT * FROM tb_alerta_correo_radicado_sim' . $whereSql . ' ORDER BY ars_fecha_actualizacion DESC, ars_id DESC LIMIT ' . $porPagina . ' OFFSET ' . $offset;
    $stmt = $db->prepare($sql);
    if ($q !== '') {
        $like1 = '%' . $q . '%'; $like2 = $like1; $like3 = $like1; $like4 = $like1; $like5 = $like1;
        $stmt->bind_param('sssss', $like1, $like2, $like3, $like4, $like5);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'rows' => $rows,
        'total' => $total,
        'pagina' => $pagina,
        'por_pagina' => $porPagina,
        'paginas' => max(1, (int)ceil($total / $porPagina)),
    ];
}

function acSimAdminResumen(mysqli $db): array
{
    $sql = "SELECT
        SUM(ars_activo=1) AS activos,
        SUM(ars_activo=0) AS inactivos,
        SUM(ars_activo=1 AND ars_mapeo_estado='MAPEADO') AS mapeados,
        SUM(ars_activo=1 AND ars_mapeo_estado='REGIONAL_SOLAMENTE') AS solo_regional,
        SUM(ars_activo=1 AND ars_mapeo_estado='NO_MAPEADO') AS no_mapeados,
        SUM(ars_activo=1 AND ars_fuente='REGISTRO_MANUAL') AS creados_manual
        FROM tb_alerta_correo_radicado_sim";
    $row = $db->query($sql)->fetch_assoc() ?: [];
    foreach (['activos','inactivos','mapeados','solo_regional','no_mapeados','creados_manual'] as $k) {
        $row[$k] = (int)($row[$k] ?? 0);
    }
    return $row;
}
