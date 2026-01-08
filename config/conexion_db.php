<?php
    $server   = '127.0.0.1'; 
    $user     = 'icbf_user';
    $password = 'IQ@ICBF2026@#*DevJDesarrolloCC';
    $db       = 'icbf-iqgis';

    $enlace_db = new mysqli($server, $user, $password, $db);

    if ($enlace_db->connect_errno) {
        error_log("Fallo al conectar BD ({$enlace_db->connect_errno}): {$enlace_db->connect_error}");
        die("Error de conexión a la base de datos.");
    }
    $enlace_db->set_charset("utf8mb4");
?>
