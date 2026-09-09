<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_helpers.php';
require_once __DIR__ . '/alerta_correos_auditoria.php';

function acContadores(mysqli $db): array
{
    $base = [
        'PENDIENTE_REVISION' => 0,
        'PENDIENTE_SUBSANACION' => 0,
        'PENDIENTE_REVISION_SUBSANACION' => 0,
        'APROBADO' => 0,
        'RECHAZADO' => 0,
    ];
    $rs = $db->query('SELECT acc_estado, COUNT(*) total FROM tb_alerta_correo_caso WHERE acc_activo=1 GROUP BY acc_estado');
    while ($fila = $rs->fetch_assoc()) {
        if (array_key_exists($fila['acc_estado'], $base)) {
            $base[$fila['acc_estado']] = (int)$fila['total'];
        }
    }
    return $base;
}

function acListarCasos(mysqli $db, array $filtros = [], int $limite = 100): array
{
    $where = ['acc_activo=1'];
    $tipos = '';
    $params = [];

    if (!empty($filtros['estado'])) {
        $where[] = 'acc_estado=?'; $tipos .= 's'; $params[] = $filtros['estado'];
    }
    if (!empty($filtros['regional'])) {
        $where[] = 'acc_regional=?'; $tipos .= 's'; $params[] = $filtros['regional'];
    }
    if (!empty($filtros['q'])) {
        $where[] = '(acc_radicado LIKE ? OR acc_sim LIKE ? OR acc_descripcion LIKE ?)';
        $tipos .= 'sss';
        $q = '%' . $filtros['q'] . '%'; array_push($params, $q, $q, $q);
    }
    $limite = max(1, min($limite, 500));
    $sql = 'SELECT acc_id,acc_radicado,acc_sim,acc_tipo_alerta,acc_estado,acc_fecha_alerta,acc_regional,acc_centro_zonal,acc_categoria,acc_fecha_creacion FROM tb_alerta_correo_caso WHERE ' . implode(' AND ', $where) . ' ORDER BY acc_id DESC LIMIT ' . $limite;
    $stmt = $db->prepare($sql);
    if ($params) { $stmt->bind_param($tipos, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function acRegionalesCasos(mysqli $db): array
{
    $rs = $db->query("SELECT DISTINCT acc_regional regional FROM tb_alerta_correo_caso WHERE acc_activo=1 AND acc_regional<>'' ORDER BY acc_regional");
    return array_column($rs->fetch_all(MYSQLI_ASSOC), 'regional');
}


function acRegionalesResponsables(mysqli $db): array
{
    $rs = $db->query("SELECT DISTINCT acr_regional regional FROM tb_alerta_correo_responsable WHERE acr_activo=1 AND acr_regional<>'' ORDER BY acr_regional");
    return array_column($rs->fetch_all(MYSQLI_ASSOC), 'regional');
}

function acCentrosZonalesResponsables(mysqli $db): array
{
    $rs = $db->query("SELECT DISTINCT acr_centro_zonal centro_zonal FROM tb_alerta_correo_responsable WHERE acr_activo=1 AND acr_centro_zonal IS NOT NULL AND acr_centro_zonal<>'' ORDER BY acr_centro_zonal");
    return array_column($rs->fetch_all(MYSQLI_ASSOC), 'centro_zonal');
}

function acObtenerCaso(mysqli $db, int $id, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM tb_alerta_correo_caso WHERE acc_id=? AND acc_activo=1 LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function acGenerarRadicado(): string
{
    return 'AC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function acCrearCaso(mysqli $db, array $data): int
{
    $radicado = trim((string)($data['radicado'] ?? '')) ?: acGenerarRadicado();
    $sim = trim((string)($data['sim'] ?? '')) ?: null;
    $origen = (string)($data['origen'] ?? 'MANUAL');
    $tipo = (string)($data['tipo_alerta'] ?? 'MEDIA');
    $fechaAlerta = ($data['fecha_alerta'] ?? '') ?: null;
    $fechaAtencion = ($data['fecha_atencion'] ?? '') ?: null;
    $regional = acNormalizarTexto((string)$data['regional']);
    $cz = acNormalizarTexto((string)($data['centro_zonal'] ?? '')) ?: null;
    $categoria = trim((string)($data['categoria'] ?? '')) ?: null;
    $subcategoria = trim((string)($data['subcategoria'] ?? '')) ?: null;
    $descripcion = trim((string)$data['descripcion']);
    $afecta = trim((string)($data['afecta_linea_tecnica'] ?? '')) ?: null;
    $justificacion = trim((string)($data['justificacion'] ?? '')) ?: null;
    $observacion = trim((string)($data['observacion'] ?? '')) ?: null;
    $usuario = acUsuarioActual();

    $stmt = $db->prepare('INSERT INTO tb_alerta_correo_caso (acc_radicado,acc_sim,acc_origen,acc_tipo_alerta,acc_estado,acc_fecha_alerta,acc_fecha_atencion,acc_regional,acc_centro_zonal,acc_categoria,acc_subcategoria,acc_descripcion,acc_afecta_linea_tecnica,acc_justificacion,acc_observacion,acc_usuario_creador,acc_usuario_ultima_actualizacion) VALUES (?,?,?,? ,\'PENDIENTE_REVISION\', ?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('ssssssssssssssss', $radicado, $sim, $origen, $tipo, $fechaAlerta, $fechaAtencion, $regional, $cz, $categoria, $subcategoria, $descripcion, $afecta, $justificacion, $observacion, $usuario, $usuario);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    acRegistrarHistorial($db, $id, null, 'PENDIENTE_REVISION', 'CREAR', 'Caso creado');
    return $id;
}

function acHistorialCaso(mysqli $db, int $id): array
{
    $stmt = $db->prepare('SELECT h.*,u.usu_nombres_apellidos FROM tb_alerta_correo_historial h LEFT JOIN tb_administrador_usuario u ON u.usu_id=h.ach_usuario WHERE h.ach_caso_id=? ORDER BY h.ach_id DESC');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function acListarResponsables(mysqli $db, string $q = ''): array
{
    if ($q === '') {
        $rs = $db->query('SELECT * FROM tb_alerta_correo_responsable ORDER BY acr_activo DESC, acr_regional, acr_centro_zonal, acr_nombre LIMIT 500');
        return $rs->fetch_all(MYSQLI_ASSOC);
    }
    $like = '%' . $q . '%';
    $stmt = $db->prepare('SELECT * FROM tb_alerta_correo_responsable WHERE acr_nombre LIKE ? OR acr_correo LIKE ? OR acr_regional LIKE ? OR acr_centro_zonal LIKE ? ORDER BY acr_activo DESC, acr_regional, acr_centro_zonal, acr_nombre LIMIT 500');
    $stmt->bind_param('ssss', $like,$like,$like,$like);
    $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
}

function acGuardarResponsable(mysqli $db, array $d): int
{
    $documento = trim((string)($d['documento'] ?? '')) ?: null;
    $nombre = acNormalizarTexto((string)$d['nombre']);
    $correo = strtolower(trim((string)$d['correo']));
    $nivel = strtoupper(trim((string)$d['nivel']));
    $regional = acNormalizarTexto((string)$d['regional']);
    $cz = acNormalizarTexto((string)($d['centro_zonal'] ?? '')) ?: null;
    $codigo = trim((string)($d['codigo_centro'] ?? '')) ?: null;
    $tipo = trim((string)($d['tipo_responsable'] ?? 'OTRO')) ?: 'OTRO';
    $perfil = trim((string)($d['perfil'] ?? '')) ?: null;
    $ext = trim((string)($d['extension_ip'] ?? '')) ?: null;
    $origen = trim((string)($d['origen'] ?? 'MANUAL')) ?: 'MANUAL';
    $usuario = acUsuarioActual();
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Correo inválido.');
    $stmt=$db->prepare('INSERT INTO tb_alerta_correo_responsable (acr_documento,acr_nombre,acr_correo,acr_nivel,acr_regional,acr_centro_zonal,acr_codigo_centro,acr_tipo_responsable,acr_perfil,acr_extension_ip,acr_origen,acr_activo,acr_usuario_registro) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $stmt->bind_param('ssssssssssss',$documento,$nombre,$correo,$nivel,$regional,$cz,$codigo,$tipo,$perfil,$ext,$origen,$usuario);
    $stmt->execute(); $id=(int)$db->insert_id; $stmt->close(); return $id;
}
