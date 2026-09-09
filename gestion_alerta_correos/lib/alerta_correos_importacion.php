<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_datos.php';

function acNormalizarCabecera(string $s): string
{
    $s=mb_strtoupper(trim($s),'UTF-8');
    $map=['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'];
    $s=strtr($s,$map);
    $s=preg_replace('/[^A-Z0-9]+/','_',$s) ?? $s;
    return trim($s,'_');
}

function acLeerCsv(string $ruta): array
{
    $fh=fopen($ruta,'rb'); if(!$fh) throw new RuntimeException('No se pudo leer el archivo.');
    $rows=[]; while(($r=fgetcsv($fh,0,','))!==false){$rows[]=$r;} fclose($fh); return $rows;
}

function acLeerXlsxPrimeraHoja(string $ruta): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('La extensión ZIP de PHP es requerida para leer XLSX.');
    $zip=new ZipArchive(); if($zip->open($ruta)!==true) throw new RuntimeException('No se pudo abrir el XLSX.');
    $shared=[]; $xml=$zip->getFromName('xl/sharedStrings.xml');
    if($xml!==false){$sx=simplexml_load_string($xml); if($sx){foreach($sx->si as $si){$parts=[]; if(isset($si->t))$parts[]=(string)$si->t; foreach($si->r as $r)$parts[]=(string)$r->t; $shared[]=implode('',$parts);}}}
    $sheet=$zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
    if($sheet===false) throw new RuntimeException('No se encontró la primera hoja.');
    $sx=simplexml_load_string($sheet); if(!$sx) throw new RuntimeException('XLSX inválido.');
    $rows=[];
    foreach($sx->sheetData->row as $row){$out=[]; foreach($row->c as $c){$ref=(string)$c['r']; preg_match('/^([A-Z]+)/',$ref,$m); $letters=$m[1]??'A'; $idx=0; foreach(str_split($letters) as $ch){$idx=$idx*26+(ord($ch)-64);} $idx--; $v=(string)$c->v; if((string)$c['t']==='s')$v=$shared[(int)$v]??''; elseif((string)$c['t']==='inlineStr')$v=(string)$c->is->t; $out[$idx]=$v;} if($out){ksort($out); $max=max(array_keys($out)); $dense=[]; for($i=0;$i<=$max;$i++)$dense[]=$out[$i]??''; $rows[]=$dense;}}
    return $rows;
}

function acImportarResponsablesArchivo(mysqli $db, string $ruta, string $nombreArchivo): array
{
    $ext=strtolower(pathinfo($nombreArchivo,PATHINFO_EXTENSION));
    $rows=$ext==='xlsx'?acLeerXlsxPrimeraHoja($ruta):acLeerCsv($ruta);
    if(!$rows) throw new RuntimeException('El archivo no contiene filas.');

    // Busca automáticamente una fila de encabezados conocida.
    $headerIndex=null; $headers=[];
    foreach(array_slice($rows,0,15,true) as $i=>$row){$norm=array_map(fn($x)=>acNormalizarCabecera((string)$x),$row); if(in_array('REGIONAL',$norm,true) || in_array('REGIONAL_CZ',$norm,true)){ $headerIndex=$i; $headers=$norm; break; }}
    if($headerIndex===null) throw new RuntimeException('No se reconoció la fila de encabezados.');
    $pos=[]; foreach($headers as $i=>$h){if($h!=='')$pos[$h]=$i;}
    $ok=0;$bad=0;$errores=[];
    for($r=$headerIndex+1;$r<count($rows);$r++){
        $row=$rows[$r];
        try {
            // Plantilla estándar descargable del módulo Alertas Correos.
            // Encabezados: NIVEL, REGIONAL, CENTRO_ZONAL, NOMBRE, CORREO,
            // DOCUMENTO, CODIGO_CENTRO, TIPO_RESPONSABLE, PERFIL, EXTENSION_IP, ESTADO.
            if (isset($pos['NIVEL'], $pos['REGIONAL'], $pos['NOMBRE'], $pos['CORREO'], $pos['TIPO_RESPONSABLE'])) {
                $estado = strtoupper(trim((string)($row[$pos['ESTADO'] ?? -1] ?? 'A')));
                if ($estado !== 'A') {
                    continue;
                }

                $nivel = strtoupper(trim((string)($row[$pos['NIVEL']] ?? '')));
                $regional = trim((string)($row[$pos['REGIONAL']] ?? ''));
                $centroZonal = trim((string)($row[$pos['CENTRO_ZONAL'] ?? -1] ?? ''));
                $nombre = trim((string)($row[$pos['NOMBRE']] ?? ''));
                $correo = trim((string)($row[$pos['CORREO']] ?? ''));
                $tipoResponsable = strtoupper(trim((string)($row[$pos['TIPO_RESPONSABLE']] ?? '')));

                if (!in_array($nivel, ['REGIONAL', 'ZONAL'], true)) {
                    throw new InvalidArgumentException('NIVEL debe ser REGIONAL o ZONAL.');
                }
                if ($regional === '') {
                    throw new InvalidArgumentException('REGIONAL es obligatorio.');
                }
                if ($nombre === '') {
                    throw new InvalidArgumentException('NOMBRE es obligatorio.');
                }
                if ($correo === '') {
                    throw new InvalidArgumentException('CORREO es obligatorio.');
                }
                if ($nivel === 'ZONAL' && $centroZonal === '') {
                    throw new InvalidArgumentException('CENTRO_ZONAL es obligatorio cuando NIVEL es ZONAL.');
                }
                if ($tipoResponsable === '') {
                    throw new InvalidArgumentException('TIPO_RESPONSABLE es obligatorio.');
                }

                acGuardarResponsable($db, [
                    'documento' => $row[$pos['DOCUMENTO'] ?? -1] ?? '',
                    'nombre' => $nombre,
                    'correo' => $correo,
                    'nivel' => $nivel,
                    'regional' => $regional,
                    'centro_zonal' => $nivel === 'ZONAL' ? $centroZonal : '',
                    'codigo_centro' => $row[$pos['CODIGO_CENTRO'] ?? -1] ?? '',
                    'tipo_responsable' => $tipoResponsable,
                    'perfil' => $row[$pos['PERFIL'] ?? -1] ?? '',
                    'extension_ip' => $row[$pos['EXTENSION_IP'] ?? -1] ?? '',
                    'origen' => 'PLANTILLA_ALERTAS_CORREOS',
                ]);
                $ok++;
                continue;
            }

            // Formato coordinadores CZ
            if(isset($pos['FUNCIONARIO_CORREO'],$pos['REGIONAL'],$pos['NOMBRE_DEL_PUNTO'])){
                $raw=(string)($row[$pos['FUNCIONARIO_CORREO']]??'');
                preg_match('/^(.*?)\s*<([^>]+)>\s*$/u',$raw,$m);
                $nombre=trim($m[1]??$raw); $correo=trim($m[2]??'');
                if($correo==='') preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',$raw,$mm) && $correo=$mm[0];
                $estado=(string)($row[$pos['ESTADO']??-1]??'A');
                if(strtoupper(trim($estado))!=='A') continue;
                acGuardarResponsable($db,[
                    'documento'=>$row[$pos['CEDULA']??-1]??'', 'nombre'=>$nombre,'correo'=>$correo,'nivel'=>'ZONAL',
                    'regional'=>$row[$pos['REGIONAL']]??'','centro_zonal'=>$row[$pos['NOMBRE_DEL_PUNTO']]??'',
                    'codigo_centro'=>$row[$pos['CODIGO']??-1]??'','tipo_responsable'=>'COORDINADOR','perfil'=>$row[$pos['PERFIL']??-1]??'',
                    'origen'=>'COORDINADORES_CZ'
                ]); $ok++; continue;
            }
            // Formato enlaces SIM regional/CZ
            $regionalKey=isset($pos['REGIONAL_CZ'])?'REGIONAL_CZ':(isset($pos['REGIONAL'])?'REGIONAL':null);
            $emailKey=isset($pos['E_MAIL_ENLACE'])?'E_MAIL_ENLACE':(isset($pos['EMAIL_ENLACE'])?'EMAIL_ENLACE':null);
            if($regionalKey && $emailKey){
                $lugar=trim((string)($row[$pos[$regionalKey]]??'')); if($lugar==='')continue;
                $correo=trim((string)($row[$pos[$emailKey]]??'')," <>\t\r\n");
                $nombreKey=$pos['ENLACE_RELACION_CON_EL_CIUDADANO']??($pos['ENLACE_SIM']??null);
                $nombre=$nombreKey!==null?(string)($row[$nombreKey]??''):'Enlace SIM';
                $esCz = stripos($lugar,'CZ ')===0 || stripos($lugar,'C.Z.')===0 || !preg_match('/^[A-ZÁÉÍÓÚÑ ]+$/u',$lugar);
                acGuardarResponsable($db,[
                    'documento'=>$row[$pos['CEDULA']??-1]??'','nombre'=>$nombre,'correo'=>$correo,'nivel'=>$esCz?'ZONAL':'REGIONAL',
                    'regional'=>$esCz?'BOGOTA':$lugar,'centro_zonal'=>$esCz?$lugar:'','tipo_responsable'=>'ENLACE_RELACION_CIUDADANO',
                    'extension_ip'=>$row[$pos['EXTENSION_IP']??-1]??'','origen'=>'ENLACES_SIM'
                ]); $ok++; continue;
            }
            $bad++;
        } catch(Throwable $e){$bad++; if(count($errores)<20)$errores[]='Fila '.($r+1).': '.$e->getMessage();}
    }
    return ['validos'=>$ok,'invalidos'=>$bad,'errores'=>$errores];
}
