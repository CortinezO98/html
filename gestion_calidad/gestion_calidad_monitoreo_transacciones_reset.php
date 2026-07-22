<?php
    $modulo_plataforma="Auditoría-Monitoreos";

    require_once("../config/validaciones_seguridad.php");
    require_once("../config/conexion_db.php");

    // Solo Administrador puede ejecutar resets
    if ($perfil_modulo !== "Administrador") {
        header('Location:gestion_calidad_monitoreo_transacciones.php?pagina=1&id=null');
        exit;
    }

    // Solo acepta POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location:gestion_calidad_monitoreo_transacciones.php?pagina=1&id=null');
        exit;
    }

    $modo           = isset($_POST['modo']) ? validar_input($_POST['modo']) : '';
    $pagina_retorno = isset($_POST['pagina_retorno']) ? (int)$_POST['pagina_retorno'] : 1;
    $filtro_retorno = isset($_POST['filtro_retorno']) ? validar_input($_POST['filtro_retorno']) : 'null';

    $ruta_retorno = "gestion_calidad_monitoreo_transacciones.php?pagina=".$pagina_retorno."&id=".$filtro_retorno;

    $registros_reseteados = 0;

    // -- MODO: Selección individual por checkboxes ----------------------------
    if ($modo === 'seleccion') {

        if (!isset($_POST['ids_reset']) || !is_array($_POST['ids_reset']) || count($_POST['ids_reset']) === 0) {
            header('Location:'.$ruta_retorno.'&reset=0');
            exit;
        }

        $ids = $_POST['ids_reset'];

        $ids_limpios = array();
        foreach ($ids as $id) {
            $id_limpio = preg_replace('/[^A-Za-z0-9\-]/', '', $id);
            if ($id_limpio !== '') {
                $ids_limpios[] = $id_limpio;
            }
        }

        if (count($ids_limpios) === 0) {
            header('Location:'.$ruta_retorno.'&reset=0');
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids_limpios), '?'));
        $consulta_reset = $enlace_db->prepare(
            "UPDATE `tb_gestion_calidad_monitoreo`
             SET `gcm_auditoria`='No',
                 `gcm_auditoria_responsable`=''
             WHERE `gcm_id` IN (".$placeholders.")
               AND `gcm_auditoria`='Si'"
        );
        $consulta_reset->bind_param(str_repeat("s", count($ids_limpios)), ...$ids_limpios);
        $consulta_reset->execute();
        $registros_reseteados = $consulta_reset->affected_rows;

    // -- MODO: Rango de fechas ------------------------------------------------
    } elseif ($modo === 'rango') {

        $fecha_inicio = isset($_POST['fecha_inicio']) ? validar_input($_POST['fecha_inicio']) : '';
        $fecha_fin    = isset($_POST['fecha_fin'])    ? validar_input($_POST['fecha_fin'])    : '';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin) ||
            $fecha_inicio > $fecha_fin) {
            header('Location:'.$ruta_retorno.'&reset=0');
            exit;
        }

        $fecha_fin_completa = $fecha_fin . ' 23:59:59';

        $consulta_reset = $enlace_db->prepare(
            "UPDATE `tb_gestion_calidad_monitoreo`
             SET `gcm_auditoria`='No',
                 `gcm_auditoria_responsable`=''
             WHERE `gcm_auditoria`='Si'
               AND `gcm_registro_fecha` >= ?
               AND `gcm_registro_fecha` <= ?"
        );
        $consulta_reset->bind_param("ss", $fecha_inicio, $fecha_fin_completa);
        $consulta_reset->execute();
        $registros_reseteados = $consulta_reset->affected_rows;

    } else {
        header('Location:'.$ruta_retorno.'&reset=0');
        exit;
    }

    header('Location:'.$ruta_retorno.'&reset='.$registros_reseteados);
    exit;
?>