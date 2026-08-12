<?php
    $modulo_plataforma = 'Coaching';
    require_once('../config/validaciones_seguridad.php');
    require_once('../config/conexion_db.php');
    require_once('lib/coaching_seguridad.php');
    require_once('lib/coaching_complementos.php');

    $perfil = coachingPerfilUsuarioActual();
    $gcp_id = validar_input(base64_decode($_POST['reg'] ?? ''));
    $gcsp_id = (int) ($_POST['gcsp_id'] ?? 0);

    // Este endpoint es exclusivamente procesador de POST — no renderiza
    // pantalla propia. Cualquier acceso por GET se redirige a la bandeja.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location:gestion_coaching.php?pagina=1&id=null&est=Pendientes');
        exit;
    }

    if (!isset($_POST['_csrf_token']) || !hash_equals($_SESSION['_csrf_token'] ?? '', $_POST['_csrf_token'])) {
        header('Location:gestion_coaching_ver.php?reg=' . base64_encode($gcp_id) . '&sop_error=' . urlencode('Solicitud inválida (CSRF). Recargue e intente de nuevo.'));
        exit;
    }

    // Chequeo grueso de acceso al paquete (evita filtrar existencia del
    // recurso a quien no tiene ni siquiera permiso de lectura). La
    // autorización FINA (dueño real + estado del paquete) la resuelve
    // eliminarSoporteCoaching() contra el soporte concreto, sin confiar en
    // el $gcp_id recibido por POST.
    if (!$perfil || !usuarioPuedeVerPaquete($enlace_db, $_SESSION['usu_id'], $perfil, $gcp_id)) {
        header('Location:../permiso_denegado.php');
        exit;
    }

    if ($gcsp_id <= 0) {
        header('Location:gestion_coaching_ver.php?reg=' . base64_encode($gcp_id) . '&sop_error=' . urlencode('Soporte inválido.'));
        exit;
    }

    try {
        eliminarSoporteCoaching($enlace_db, $gcsp_id, $_SESSION['usu_id'], $perfil);
        header('Location:gestion_coaching_ver.php?reg=' . base64_encode($gcp_id) . '&sop_ok=1');
    } catch (Throwable $e) {
        header('Location:gestion_coaching_ver.php?reg=' . base64_encode($gcp_id) . '&sop_error=' . urlencode($e->getMessage()));
    }
    exit;
