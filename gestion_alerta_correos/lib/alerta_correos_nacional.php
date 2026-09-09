<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_territorio.php';
require_once __DIR__ . '/alerta_correos_importacion_versionada.php';

function acNacionalTipoUnidad(string $unidad): string
{
    $k = acTerritorioNormalizarClave($unidad);
    if (str_starts_with($k, 'SUBDIRECCION')) return 'SUBDIRECCION';
    if (str_starts_with($k, 'DIRECCION')) return 'DIRECCION';
    if (str_starts_with($k, 'OFICINA')) return 'OFICINA';
    if (str_starts_with($k, 'SECRETARIA')) return 'SECRETARIA';
    return 'OTRO';
}

function acNacionalFingerprint(array $r): string
{
    return hash('sha256', implode("\x1F", [
        acTerritorioNormalizarClave((string)$r['unidad']),
        trim((string)$r['director']), trim((string)$r['enlace']), strtolower(trim((string)$r['correo'])),
        trim((string)$r['documento']), trim((string)$r['extension']),
    ]));
}

function acNacionalParsear(string $ruta, string $nombreArchivo): array
{
    if (strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new RuntimeException('El directorio nacional debe cargarse en formato XLSX.');
    }
    $hojas = acImportacionLeerXlsx($ruta);
    $filas = null;
    foreach ($hojas as $nombre => $rows) {
        if (acTerritorioNormalizarClave($nombre) === 'SEDE NACIONAL') {
            $filas = $rows;
            break;
        }
    }
    if ($filas === null) {
        throw new RuntimeException('No se encontró la hoja "SEDE NACIONAL".');
    }
    $header = acImportacionBuscarFilaEncabezado($filas, [
        ['DIRECCION_SUB_DIRECCION'], ['ENLACE_SIM'], ['E_MAIL_ENLACE', 'EMAIL_ENLACE']
    ], 20);
    if (!$header) {
        throw new RuntimeException('No fue posible identificar los encabezados del directorio nacional.');
    }
    $map = $header['map'];
    $registros = [];
    $errores = [];
    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $unidad = trim(acImportacionValor($row, $map, ['DIRECCION_SUB_DIRECCION']));
        if ($unidad === '') continue;
        $enlace = trim(acImportacionValor($row, $map, ['ENLACE_SIM']));
        $correoRaw = trim(acImportacionValor($row, $map, ['E_MAIL_ENLACE', 'EMAIL_ENLACE']));
        $correo = acImportacionLimpiarCorreo($correoRaw);
        if (in_array(acTerritorioNormalizarClave($correoRaw), ['', 'NA', 'N A', 'SIN INFORMACION'], true)) {
            $correo = '';
        }
        $contactable = $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL);
        if ($correo !== '' && !$contactable) {
            $errores[] = 'Fila ' . ($i + 1) . ': correo nacional inválido para ' . $unidad . '.';
            continue;
        }
        $r = [
            'unidad' => $unidad,
            'unidad_clave' => acTerritorioNormalizarClave($unidad),
            'tipo_unidad' => acNacionalTipoUnidad($unidad),
            'director' => acImportacionValor($row, $map, ['DIRECTOR_SUBDIRECTOR', 'DIRECTOR_SUB_DIRECTOR']),
            'enlace' => in_array(acTerritorioNormalizarClave($enlace), ['NA', 'N A', 'SIN INFORMACION'], true) ? '' : $enlace,
            'correo' => $correo,
            'documento' => acImportacionValor($row, $map, ['CEDULA', 'DOCUMENTO']),
            'extension' => acImportacionValor($row, $map, ['EXTENSION']),
            'contactable' => $contactable ? 1 : 0,
        ];
        $r['fingerprint'] = acNacionalFingerprint($r);
        $registros[$r['unidad_clave']] = $r;
    }
    if (!$registros) throw new RuntimeException('La hoja SEDE NACIONAL no contiene registros válidos.');
    return ['registros' => array_values($registros), 'errores' => $errores];
}

function acNacionalPreparar(mysqli $db, string $ruta, string $nombreArchivo): array
{
    $p = acNacionalParsear($ruta, $nombreArchivo);
    $actuales = [];
    $rs = $db->query("SELECT * FROM tb_alerta_correo_enlace_nacional WHERE aen_activo=1 ORDER BY aen_id");
    while ($row = $rs->fetch_assoc()) $actuales[(string)$row['aen_unidad_clave']][] = $row;
    $res = ['nuevos'=>0,'actualizados'=>0,'sin_cambios'=>0,'no_en_archivo'=>0,'contactables'=>0,'sin_correo'=>0,'invalidos'=>count($p['errores'])];
    $presentes = [];
    foreach ($p['registros'] as $r) {
        $k = $r['unidad_clave']; $presentes[$k] = true;
        $r['contactable'] ? $res['contactables']++ : $res['sin_correo']++;
        $cand = $actuales[$k] ?? [];
        $match = false;
        foreach ($cand as $c) if (hash_equals((string)$c['aen_hash_version'], $r['fingerprint'])) { $match=true; break; }
        if (!$cand) $res['nuevos']++;
        elseif ($match) $res['sin_cambios']++;
        else $res['actualizados']++;
    }
    foreach ($actuales as $k=>$rows) if (!isset($presentes[$k])) $res['no_en_archivo']++;
    return ['registros'=>$p['registros'],'errores'=>$p['errores'],'resumen'=>$res,'nombre_archivo'=>$nombreArchivo,'sha256'=>hash_file('sha256',$ruta)?:''];
}

function acNacionalAplicar(mysqli $db, array $preview, string $usuario): array
{
    $out=['nuevos'=>0,'actualizados'=>0,'sin_cambios'=>0,'no_en_archivo'=>(int)$preview['resumen']['no_en_archivo']];
    $sel=$db->prepare("SELECT * FROM tb_alerta_correo_enlace_nacional WHERE aen_unidad_clave=? AND aen_activo=1 ORDER BY aen_id DESC");
    $close=$db->prepare("UPDATE tb_alerta_correo_enlace_nacional SET aen_activo=0,aen_vigente_hasta=NOW(),aen_usuario_actualizacion=?,aen_fecha_actualizacion=NOW() WHERE aen_unidad_clave=? AND aen_activo=1");
    $ins=$db->prepare("INSERT INTO tb_alerta_correo_enlace_nacional
      (aen_unidad,aen_unidad_clave,aen_tipo_unidad,aen_director_subdirector,aen_enlace_nombre,aen_correo,aen_documento,aen_extension,aen_contactable,aen_activo,aen_vigente_desde,aen_vigente_hasta,aen_fuente,aen_hash_version,aen_usuario_actualizacion)
      VALUES (?,?,?,?,?,?,?,?,?,1,NOW(),NULL,'ENLACES_SIM_SEDE_NACIONAL',?,?)");
    foreach($preview['registros'] as $r){
        $k=$r['unidad_clave']; $sel->bind_param('s',$k); $sel->execute(); $rows=$sel->get_result()->fetch_all(MYSQLI_ASSOC);
        $match=false; foreach($rows as $c) if(hash_equals((string)$c['aen_hash_version'],$r['fingerprint'])){$match=true;break;}
        if($match){$out['sin_cambios']++;continue;}
        if($rows){$close->bind_param('ss',$usuario,$k);$close->execute();$out['actualizados']++;} else {$out['nuevos']++;}
        $unidad=$r['unidad'];$tipo=$r['tipo_unidad'];$director=$r['director'];$enlace=$r['enlace'];$correo=$r['correo'];$doc=$r['documento'];$ext=$r['extension'];$contact=(int)$r['contactable'];$hash=$r['fingerprint'];
        $ins->bind_param('ssssssssiss', $unidad,$k,$tipo,$director,$enlace,$correo,$doc,$ext,$contact,$hash,$usuario);
        $ins->execute();
    }
    $sel->close();$close->close();$ins->close();
    return $out;
}
