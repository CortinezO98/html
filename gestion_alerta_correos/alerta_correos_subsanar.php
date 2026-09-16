<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';

require_once '../config/validaciones_seguridad.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';

acExigirPerfil(['Usuario', 'Administrador']);

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
}

if (!$id) {
    acFlash(
        'danger',
        'No fue posible identificar la alerta a subsanar.'
    );

    header('Location: alerta_correos.php');
    exit;
}

acFlash(
    'info',
    'La subsanación debe realizarse desde el formulario de corrección de la alerta.'
);

header(
    'Location: alerta_correos_subsanacion_editar.php?id=' . (int)$id
);
exit;
