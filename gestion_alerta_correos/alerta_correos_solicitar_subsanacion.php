<?php
declare(strict_types=1);
$modulo_plataforma='Alertas Correos'; require_once '../config/validaciones_seguridad.php'; require_once '../config/conexion_db.php';
require_once __DIR__.'/lib/alerta_correos_seguridad.php'; require_once __DIR__.'/lib/alerta_correos_transiciones.php';
acExigirPerfil(['Gestor','Supervisor','Administrador']); acExigirPost(); acValidarCsrfPost();
try{$id=acPostInt('id');$comentario=acPostString('comentario',20000);acEjecutarTransicion($enlace_db,$id,'SOLICITAR_SUBSANACION',$comentario);acFlash('success','Acción realizada correctamente.');}catch(Throwable $e){$id=(int)($_POST['id']??0);acFlash('danger',$e->getMessage());}
header('Location: alerta_correos_ver.php?id='.(int)$id); exit;
