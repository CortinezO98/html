<?php
declare(strict_types=1);
$modulo_plataforma='Alertas Correos';
require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__.'/lib/alerta_correos_seguridad.php';
require_once __DIR__.'/lib/alerta_correos_territorio.php';
require_once __DIR__.'/lib/alerta_correos_importacion_versionada.php';
require_once __DIR__.'/lib/alerta_correos_nacional.php';
acExigirPerfil(['Administrador']);
$titulo_header='Alertas Correos | Directorio nacional';
$error=null;$preview=null;$resultado=null;$token='';
if(!isset($_SESSION['ac_nacional_sync'])||!is_array($_SESSION['ac_nacional_sync']))$_SESSION['ac_nacional_sync']=[];
foreach($_SESSION['ac_nacional_sync'] as $k=>$v)if(!is_array($v)||(int)($v['expires']??0)<time())unset($_SESSION['ac_nacional_sync'][$k]);
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
  acValidarCsrfPost();$accion=(string)($_POST['accion']??'analizar');
  if($accion==='analizar'){
   if(!isset($_FILES['archivo'])||$_FILES['archivo']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Seleccione BBDD enlaces SIM.xlsx.');
   $nombre=(string)$_FILES['archivo']['name'];$tmp=(string)$_FILES['archivo']['tmp_name'];
   if(strtolower(pathinfo($nombre,PATHINFO_EXTENSION))!=='xlsx')throw new RuntimeException('Solo se permite XLSX.');
   if((int)$_FILES['archivo']['size']>10*1024*1024)throw new RuntimeException('El archivo supera 10 MB.');
   if(!is_uploaded_file($tmp))throw new RuntimeException('El archivo recibido no es válido.');
   $preview=acNacionalPreparar($enlace_db,$tmp,$nombre);$token=bin2hex(random_bytes(24));
   $_SESSION['ac_nacional_sync'][$token]=['expires'=>time()+1800,'preview'=>$preview];
  }elseif($accion==='confirmar'){
   $token=preg_replace('/[^a-f0-9]/','',(string)($_POST['token_preview']??''))??'';$item=$_SESSION['ac_nacional_sync'][$token]??null;
   if(!$item||(int)($item['expires']??0)<time())throw new RuntimeException('La previsualización venció.');
   $preview=$item['preview'];if((int)$preview['resumen']['invalidos']>0)throw new RuntimeException('Corrija los correos inválidos antes de confirmar.');
   $enlace_db->begin_transaction();
   try{
    $usuario=acUsuarioActual();
    $resultado=acNacionalAplicar($enlace_db,$preview,$usuario);

    // Auditoría de la carga, reutilizando la bitácora central del módulo.
    $tipo='ENLACES_SIM_SEDE_NACIONAL';
    $estado='COMPLETADA';
    $nombre=(string)$preview['nombre_archivo'];
    $sha=(string)$preview['sha256'];
    $total=count($preview['registros']);
    $validos=$total;
    $invalidos=0;
    $detalle='Nuevos='.$resultado['nuevos'].'; Actualizados='.$resultado['actualizados'].'; Sin cambios='.$resultado['sin_cambios'].'; No en archivo='.$resultado['no_en_archivo'].'; Contactables='.(int)$preview['resumen']['contactables'].'; Sin correo='.(int)$preview['resumen']['sin_correo'];
    $stmt=$enlace_db->prepare('INSERT INTO tb_alerta_correo_carga (acg_tipo,acg_archivo_nombre,acg_archivo_sha256,acg_total_registros,acg_registros_validos,acg_registros_invalidos,acg_estado,acg_detalle_error,acg_usuario) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('sssiiisss',$tipo,$nombre,$sha,$total,$validos,$invalidos,$estado,$detalle,$usuario);
    $stmt->execute();$stmt->close();

    $enlace_db->commit();unset($_SESSION['ac_nacional_sync'][$token]);$preview=null;$token='';
   }
   catch(Throwable $e){$enlace_db->rollback();throw $e;}
  }else throw new RuntimeException('Acción no válida.');
 }catch(Throwable $e){error_log('Alertas Correos / nacional: '.$e->getMessage());$error=$e->getMessage();}
}
?>
<!DOCTYPE html><html lang="ES"><head><?php include '../config/configuracion_estilos.php'; ?><link rel="stylesheet" href="assets/alerta_correos.css?v=20260909"></head><body>
<?php include '../menu_principal.php';include '../menu_header.php'; ?>
<div class="contenido ac-module ac-module--footer-safe">
<nav class="ac-breadcrumb"><a href="../contenido.php">Inicio</a><span class="ac-separator">/</span><a href="alerta_correos.php">Alertas Correos</a><span class="ac-separator">/</span><span>Directorio nacional</span></nav>
<header class="ac-page-header"><div class="ac-page-header__main"><h1 class="ac-page-title"><span class="fas fa-building"></span> Enlaces SIM · Sede Nacional</h1><p class="ac-page-subtitle">Sincroniza la hoja <strong>SEDE NACIONAL</strong>. Este directorio se conserva separado del enrutamiento Regional/CZ.</p></div><div class="ac-page-header__actions"><a href="alerta_correos.php" class="btn ac-btn-red-outline"><span class="fas fa-arrow-left"></span> Volver</a></div></header>
<?php if($error): ?><div class="alert alert-danger"><?php echo acEscape($error); ?></div><?php endif; ?>
<?php if($resultado): ?><div class="alert alert-success"><strong>Directorio nacional sincronizado.</strong> Nuevos: <?php echo (int)$resultado['nuevos']; ?> · Actualizados: <?php echo (int)$resultado['actualizados']; ?> · Sin cambios: <?php echo (int)$resultado['sin_cambios']; ?>.</div><?php endif; ?>
<?php if($preview): $r=$preview['resumen']; ?>
<section class="ac-panel"><div class="ac-panel__header"><h2 class="ac-panel__title">Previsualización Sede Nacional</h2></div><div class="ac-panel__body">
<div class="ac-kpis-inline mb-3"><div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Nuevos</span><span class="ac-kpi-inline__value"><?php echo (int)$r['nuevos']; ?></span></div><div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Cambios</span><span class="ac-kpi-inline__value"><?php echo (int)$r['actualizados']; ?></span></div><div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Sin cambios</span><span class="ac-kpi-inline__value"><?php echo (int)$r['sin_cambios']; ?></span></div><div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Contactables</span><span class="ac-kpi-inline__value"><?php echo (int)$r['contactables']; ?></span></div><div class="ac-kpi-inline"><span class="ac-kpi-inline__label">Sin correo</span><span class="ac-kpi-inline__value"><?php echo (int)$r['sin_correo']; ?></span></div></div>
<?php if($preview['errores']): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach(array_slice($preview['errores'],0,30) as $e): ?><li><?php echo acEscape($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="ac-alert-box ac-alert-box--info mb-3">La Sede Nacional queda disponible como directorio versionado. <strong>No se agrega automáticamente a los destinatarios</strong> hasta definir qué Dirección/Subdirección corresponde a cada alerta.</div>
<div class="alert alert-warning mb-3"><span class="fas fa-eye mr-1"></span><strong>Vista previa únicamente.</strong> Aún no se ha guardado ningún registro del archivo. Revise la tabla antes de confirmar.</div>
<details class="mb-3" open>
<summary class="font-weight-bold" style="cursor:pointer;">Vista previa de registros del archivo</summary>
<div class="ac-table-wrap mt-2"><table class="table table-sm table-hover ac-table"><thead><tr><th>Unidad</th><th>Tipo</th><th>Enlace SIM</th><th>Correo</th><th>Cédula</th><th>Extensión</th><th>Estado</th></tr></thead><tbody>
<?php foreach(array_slice($preview['registros'],0,100) as $row): ?>
<tr><td><?php echo acEscape((string)$row['unidad']); ?></td><td><?php echo acEscape((string)$row['tipo_unidad']); ?></td><td><?php echo acEscape((string)$row['enlace']); ?></td><td><?php echo acEscape((string)$row['correo']); ?></td><td><?php echo acEscape((string)$row['documento']); ?></td><td><?php echo acEscape((string)$row['extension']); ?></td><td><?php echo (int)$row['contactable']===1?'Contactable':'Sin correo válido'; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if(count($preview['registros'])>100): ?><small class="text-muted">Se muestran los primeros 100 de <?php echo count($preview['registros']); ?> registros. El resumen superior corresponde al archivo completo.</small><?php endif; ?>
</details>
<div class="ac-actions-bar ac-actions-bar--center"><a href="alerta_correos_nacional_cargar.php" class="btn ac-btn-red-outline"><span class="fas fa-times"></span> Cancelar</a><form method="post" class="d-inline" data-ac-lock-submit="1" onsubmit="return confirm('¿Confirma la carga del Directorio Nacional? Los datos se guardarán únicamente después de esta confirmación.');"><input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="token_preview" value="<?php echo acEscape($token); ?>"><button class="btn ac-btn-green-outline" type="submit" <?php echo (int)$r['invalidos']>0?'disabled':''; ?>><span class="fas fa-check"></span> Confirmar directorio</button></form></div>
</div></section>
<?php else: ?>
<div class="row justify-content-center"><div class="col-12 col-xl-10"><section class="ac-panel"><div class="ac-panel__header"><h2 class="ac-panel__title"><span class="fas fa-file-excel"></span> Cargar BBDD enlaces SIM.xlsx</h2></div><div class="ac-panel__body"><div class="ac-alert-box ac-alert-box--info mb-3"><div class="row align-items-center"><div class="col-12 col-md"><strong>Archivo que debe cargar:</strong> <code>BBDD enlaces SIM.xlsx</code><br><small>Se utiliza únicamente la hoja <strong>SEDE NACIONAL</strong>, con Dirección/Subdirección, Director/Subdirector, Enlace SIM, correo, cédula y extensión.</small></div><div class="col-12 col-md-auto mt-2 mt-md-0"><a href="plantillas/Plantilla_Ejemplo_Directorio_Nacional.xlsx" class="btn ac-btn-blue-outline" download><span class="fas fa-download"></span> Descargar archivo de ejemplo</a></div></div></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?php echo acEscape(acCsrfToken()); ?>"><input type="hidden" name="accion" value="analizar"><div class="ac-upload-zone"><div class="ac-upload-zone__icon"><span class="fas fa-file-excel"></span></div><div class="ac-upload-zone__title">Hoja SEDE NACIONAL</div><div class="ac-upload-zone__text">Dirección/Subdirección, director, enlace SIM, correo, cédula y extensión.</div><label for="archivo" class="btn ac-btn-green-outline mb-0">Seleccionar archivo</label><input id="archivo" class="d-none" type="file" name="archivo" accept=".xlsx" required data-ac-file-input><div class="ac-upload-file-name" data-ac-file-name>Ningún archivo seleccionado</div></div><div class="ac-actions-bar ac-actions-bar--center"><button class="btn ac-btn-green-outline" type="submit">Analizar archivo</button></div></form></div></section></div></div>
<?php endif; ?>
</div><?php include '../footer.php';include '../config/configuracion_js.php'; ?><script src="assets/alerta_correos.js?v=20260909"></script></body></html>
