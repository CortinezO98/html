<?php
declare(strict_types=1);

/**
 * Selector SIM de Alertas Correos.
 * Prioridad:
 * 1) tb_alerta_correo_radicado_sim (fuente propia del módulo)
 * 2) tb_gestion_encuesta_radicado (fallback si en otro ambiente está poblada)
 */

function acSimTextoSeguro(?string $valor): string { return trim((string)$valor); }

function acSimTablaExiste(mysqli $db, string $tabla): bool
{
    static $cache = [];
    $key = spl_object_id($db) . '|' . $tabla;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->bind_param('s', $tabla); $stmt->execute();
    $cache[$key] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $cache[$key];
}

function acSimNormalizarPuntoClave(string $valor): string
{
    $clave = acTerritorioNormalizarClave($valor);
    if ($clave === '') return '';
    $clave = preg_replace('/^C\s+Z\s+/', 'CZ ', $clave) ?? $clave;
    $clave = preg_replace('/^CENTRO\s+ZONAL\s+/', 'CZ ', $clave) ?? $clave;
    return trim(preg_replace('/\s+/', ' ', $clave) ?? $clave);
}

function acSimBuscarRegionalTerritorial(mysqli $db, string $regional): ?array
{
    $clave = acTerritorioNormalizarClave($regional);
    if ($clave === '') return null;
    // Alias histórico de la base de Enlaces SIM.
    if ($clave === 'VALLE') $clave = 'VALLE DEL CAUCA';
    $stmt=$db->prepare("SELECT acp_id,acp_codigo,acp_nombre,acp_regional,acp_regional_clave FROM tb_alerta_correo_punto_atencion WHERE acp_tipo='REGIONAL' AND acp_activo=1 AND acp_regional_clave=? LIMIT 1");
    $stmt->bind_param('s',$clave);$stmt->execute();$row=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();return $row;
}

function acSimBuscarPuntoTerritorial(mysqli $db, int $regionalId, string $centroZonal): ?array
{
    if($regionalId<=0||trim($centroZonal)==='')return null;
    $buscada=acSimNormalizarPuntoClave($centroZonal);
    $alias=[
      'CZ INTEGRAL NORORIENTAL'=>'CZ NORORIENTAL','CZ INTEGRAL NOROCCIDENTAL'=>'CZ NOROCCIDENTAL',
      'CZ DOS QUEBRADAS'=>'CZ DOSQUEBRADAS','CZ 1 MONTERIA'=>'CZ MONTERIA','CZ PLANETARICA'=>'CZ PLANETA RICA',
      'CZ SAN CRISTOBAL SUR'=>'CZ SAN CRISTOBAL','CZ SAN JOSE DE GUAVIARE'=>'CZ SAN JOSE DEL GUAVIARE',
      'CZ EL CARMEN DE BOLIVAR'=>'CZ CARMEN DE BOLIVAR'
    ];
    $buscada=$alias[$buscada]??$buscada;
    foreach(acTerritorioListarPuntosRegional($db,$regionalId) as $p){
        if(acSimNormalizarPuntoClave((string)$p['acp_nombre'])===$buscada)return $p;
    }
    return null;
}

function acSimEnriquecerTerritorio(mysqli $db, array $row): array
{
    $regionalTexto=acSimTextoSeguro($row['regional_nombre']??$row['gera_regional']??'');
    $centroTexto=acSimTextoSeguro($row['centro_zonal_nombre']??$row['gera_centro_zonal']??'');

    $regionalId=(int)($row['regional_id']??0);$puntoId=(int)($row['punto_atencion_id']??0);
    $regional=null;$punto=null;
    if($regionalId>0)$regional=acTerritorioObtenerRegional($db,$regionalId);
    if($puntoId>0)$punto=acTerritorioObtenerPunto($db,$puntoId);
    if(!$regional)$regional=acSimBuscarRegionalTerritorial($db,$regionalTexto);
    if(!$punto&&$regional)$punto=acSimBuscarPuntoTerritorial($db,(int)$regional['acp_id'],$centroTexto);

    $row['regional_nombre']=$regionalTexto;$row['centro_zonal_nombre']=$centroTexto;
    $row['regional_id']=$regional?(int)$regional['acp_id']:0;$row['punto_atencion_id']=$punto?(int)$punto['acp_id']:0;
    $row['regional_catalogo']=$regional?(string)$regional['acp_nombre']:'';$row['centro_zonal_catalogo']=$punto?(string)$punto['acp_nombre']:'';
    $row['territorio_mapeado']=(bool)($regional&&$punto);
    if(!$regional)$row['territorio_mensaje']='La Regional del SIM no está disponible en el catálogo territorial activo.';
    elseif(!$punto)$row['territorio_mensaje']='El SIM no tiene un Centro Zonal mapeado. Seleccione el punto de atención manualmente.';
    else $row['territorio_mensaje']='';
    return $row;
}

function acSimFilaFuentePropia(array $r): array
{
    return [
      'gera_radicado'=>(string)$r['ars_sim'],
      'gera_fecha_peticion'=>(string)($r['ars_fecha_atencion']??''),
      'gera_peticionario_nombre'=>'',
      'gera_estado_gestion'=>(string)($r['ars_estado']??''),
      'gera_regional'=>(string)$r['ars_regional'],
      'gera_centro_zonal'=>(string)$r['ars_punto_atencion'],
      'regional_nombre'=>(string)$r['ars_regional'],
      'centro_zonal_nombre'=>(string)$r['ars_punto_atencion'],
      'regional_id'=>(int)($r['ars_regional_id']??0),
      'punto_atencion_id'=>(int)($r['ars_punto_atencion_id']??0),
      'fuente'=>'ALERTAS_CORREOS',
      'fecha_alerta'=>(string)($r['ars_fecha_alerta']??''),
      'fecha_atencion'=>(string)($r['ars_fecha_atencion']??''),
      'categoria'=>(string)($r['ars_categoria']??''),
      'descripcion'=>(string)($r['ars_descripcion']??''),
      'afecta_linea_tecnica'=>(string)($r['ars_afecta_linea_tecnica']??''),
      'fecha_remision_agente'=>(string)($r['ars_fecha_remision_agente']??''),
      'justificacion'=>(string)($r['ars_justificacion']??''),
      'fecha_notificacion_regional'=>(string)($r['ars_fecha_notificacion_regional']??''),
      'subcategoria'=>(string)($r['ars_subcategoria']??''),
      'mapeo_estado'=>(string)($r['ars_mapeo_estado']??''),
    ];
}

function acSimObtenerRadicadoPropio(mysqli $db,string $radicado):?array
{
    if(!acSimTablaExiste($db,'tb_alerta_correo_radicado_sim'))return null;
    $stmt=$db->prepare('SELECT * FROM tb_alerta_correo_radicado_sim WHERE ars_sim=? AND ars_activo=1 LIMIT 1');
    $stmt->bind_param('s',$radicado);$stmt->execute();$r=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    return $r?acSimEnriquecerTerritorio($db,acSimFilaFuentePropia($r)):null;
}

function acSimObtenerRadicadoLegacy(mysqli $db,string $radicado):?array
{
    if(!acSimTablaExiste($db,'tb_gestion_encuesta_radicado'))return null;
    $stmt=$db->prepare("SELECT R.gera_radicado,R.gera_fecha_peticion,R.gera_peticionario_nombre,R.gera_estado_gestion,R.gera_regional,R.gera_centro_zonal,COALESCE(NULLIF(TR.gere_regional,''),R.gera_regional) regional_nombre,COALESCE(NULLIF(TCZ.gercz_centro_zonal,''),R.gera_centro_zonal) centro_zonal_nombre FROM tb_gestion_encuesta_radicado R LEFT JOIN tb_gestion_encuesta_regional TR ON R.gera_regional=TR.gere_id LEFT JOIN tb_gestion_encuesta_regional_czonal TCZ ON R.gera_centro_zonal=TCZ.gercz_id WHERE R.gera_radicado=? LIMIT 1");
    $stmt->bind_param('s',$radicado);$stmt->execute();$row=$stmt->get_result()->fetch_assoc()?:null;$stmt->close();
    if($row){$row['fuente']='ENCUESTAS_LEGACY';return acSimEnriquecerTerritorio($db,$row);}return null;
}

function acSimObtenerRadicado(mysqli $db,string $radicado):?array
{
    $radicado=trim($radicado);if($radicado==='')return null;
    return acSimObtenerRadicadoPropio($db,$radicado)??acSimObtenerRadicadoLegacy($db,$radicado);
}

function acSimBuscarRadicados(mysqli $db,string $termino,int $limite=20):array
{
    $termino=trim($termino);$limite=max(1,min(30,$limite));if(mb_strlen($termino,'UTF-8')<3)return[];
    $out=[];$vistos=[];$pref=$termino.'%';
    if(acSimTablaExiste($db,'tb_alerta_correo_radicado_sim')){
      $contiene='%'.$termino.'%';
      $sql='SELECT * FROM tb_alerta_correo_radicado_sim WHERE ars_activo=1 AND (ars_sim LIKE ? OR ars_regional LIKE ? OR ars_punto_atencion LIKE ? OR ars_categoria LIKE ?) ORDER BY CASE WHEN ars_sim LIKE ? THEN 0 ELSE 1 END, ars_sim ASC LIMIT '.(int)$limite;
      $stmt=$db->prepare($sql);$stmt->bind_param('sssss',$pref,$contiene,$contiene,$contiene,$pref);$stmt->execute();
      foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r){$x=acSimEnriquecerTerritorio($db,acSimFilaFuentePropia($r));$out[]=$x;$vistos[(string)$x['gera_radicado']]=true;}
      $stmt->close();
    }
    $restante=$limite-count($out);
    if($restante>0&&acSimTablaExiste($db,'tb_gestion_encuesta_radicado')){
      $sql="SELECT R.gera_radicado,R.gera_fecha_peticion,R.gera_peticionario_nombre,R.gera_estado_gestion,R.gera_regional,R.gera_centro_zonal,COALESCE(NULLIF(TR.gere_regional,''),R.gera_regional) regional_nombre,COALESCE(NULLIF(TCZ.gercz_centro_zonal,''),R.gera_centro_zonal) centro_zonal_nombre FROM tb_gestion_encuesta_radicado R LEFT JOIN tb_gestion_encuesta_regional TR ON R.gera_regional=TR.gere_id LEFT JOIN tb_gestion_encuesta_regional_czonal TCZ ON R.gera_centro_zonal=TCZ.gercz_id WHERE R.gera_radicado LIKE ? ORDER BY R.gera_radicado ASC LIMIT ".(int)$restante;
      $stmt=$db->prepare($sql);$stmt->bind_param('s',$pref);$stmt->execute();
      foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r){if(isset($vistos[(string)$r['gera_radicado']]))continue;$r['fuente']='ENCUESTAS_LEGACY';$out[]=acSimEnriquecerTerritorio($db,$r);}
      $stmt->close();
    }
    return $out;
}
