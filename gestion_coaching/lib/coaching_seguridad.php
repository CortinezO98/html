<?php
declare(strict_types=1);

/**
 * gestion_coaching/lib/coaching_seguridad.php
 *
 * Autorización POR RECURSO para operaciones de LECTURA (bandeja, detalle,
 * reportes). Para escritura/transiciones de estado, coaching_transiciones.php
 * ya resuelve su propia autorización comparando contra el paquete concreto.
 *
 * Se apoya en $_SESSION['modulos_acceso_permisos'] que YA carga
 * contenido.php para todo el sistema (no se reinventa el mecanismo de
 * sesión, solo se usa lo que ya existe).
 */

/** Perfil que tiene el usuario actual para el módulo 'Coaching', o null si no tiene acceso. */
function coachingPerfilUsuarioActual(): ?string
{
    if (isset($_SESSION['modulos_acceso_permisos']['Coaching']) && $_SESSION['modulos_acceso_permisos']['Coaching'] !== '') {
        return $_SESSION['modulos_acceso_permisos']['Coaching'];
    }
    return null;
}

/**
 * Filtro de alcance para listados (bandeja): construye la condición SQL +
 * parámetros según el perfil del usuario autenticado, para que nadie vea
 * paquetes fuera de su alcance real, independientemente de lo que el
 * frontend muestre u oculte.
 *
 * Perfiles reconocidos (ver tb_configuracion_perfil_usu_mod.per_perfil):
 *  - 'Agente' / 'Calidad'        -> solo paquetes donde ÉL/ELLA es el coacheado (gcp_agente_id = su usu_id)
 *  - 'Supervisor'                -> según $vista:
 *       'equipo'    (por defecto) -> paquetes de su equipo, donde gcp_supervisor_id = su usu_id
 *       'recibidos'               -> paquetes donde a ÉL/ELLA se le hizo coaching, gcp_agente_id = su usu_id
 *  - 'Administrador' / 'Coordinación' / 'Gerencia' -> ven todo, alcance amplio intencional
 *  - 'Gestor' NO tiene alcance amplio en este módulo: en este portal, el
 *    valor 'Gestor' representa al Líder de Calidad (ver convención de
 *    per_perfil por módulo), que debe ver solo lo suyo — por eso NO está
 *    en la lista de arriba y cae en el "default" de más abajo (mismo
 *    tratamiento que 'Usuario'/Agente).
 *  - CUALQUIER OTRO VALOR (incluye 'Usuario', vacío, o cualquier etiqueta
 *    no anticipada) -> FALLA SEGURA: en producción, muchas cuentas reales
 *    tienen guardadas etiquetas genéricas de todo el sistema en vez de
 *    los strings específicos de Coaching (se confirmó con cuentas reales
 *    que traían 'Usuario' o el módulo sin configurar). Antes, cualquier
 *    valor no reconocido caía en "sin restricción" — el default quedaba
 *    MÁS permisivo que los perfiles explícitamente definidos, que es
 *    exactamente al revés de lo que debe ser. Ahora el default es el
 *    alcance mínimo por recurso real: solo paquetes donde el usuario es
 *    el coacheado O el supervisor asignado. Un perfil mal configurado se
 *    traduce en "ver menos de lo esperado", nunca en "ver más de lo debido".
 *
 * @return array{0:string,1:array} [fragmento SQL a concatenar con AND, parámetros correspondientes]
 */
function coachingFiltroAlcance(string $perfil, string $usu_id, string $vista = 'equipo'): array
{
    switch ($perfil) {
        case 'Agente':
        case 'Calidad':
            return ['AND `gcp_agente_id` = ?', [$usu_id]];
        case 'Supervisor':
            if ($vista === 'recibidos') {
                return ['AND `gcp_agente_id` = ?', [$usu_id]];
            }
            return ['AND `gcp_supervisor_id` = ?', [$usu_id]];
        case 'Administrador':
        case 'Coordinación':
        case 'Gerencia':
            // Alcance amplio intencional, sin restricción de propietario.
            // Si negocio confirma un alcance por campaña para
            // Coordinación/Gerencia, se agrega aquí comparando contra
            // tb_administrador_usuario.usu_campania.
            return ['', []];
        default:
            return ['AND (`gcp_agente_id` = ? OR `gcp_supervisor_id` = ?)', [$usu_id, $usu_id]];
    }
}

/**
 * Cuenta cuántos paquetes requieren acción del usuario actual EN ESTE
 * MOMENTO (no todos sus paquetes, solo los que están "en su cancha").
 * Usado por la alerta flotante — ver coaching_widget_flotante.php.
 */
function coachingContarPendientesAccion(mysqli $enlace_db, string $usu_id, string $perfil): int
{
    if ($perfil === 'Supervisor') {
        // Un Supervisor puede tener pendientes en DOS roles distintos sobre
        // paquetes distintos: como gestor de su equipo (gcp_supervisor_id)
        // y como coacheado por su propio Coordinador (gcp_agente_id) — se
        // cuentan ambos con UNION ALL, cada uno con los estados que
        // realmente le corresponden a ese rol.
        $consulta = $enlace_db->prepare(
            "SELECT COUNT(*) AS total FROM (
                SELECT `gcp_id` FROM `tb_gestion_coaching_paquete`
                WHERE `gcp_supervisor_id` = ? AND `gcp_activo` = 1
                  AND `gcp_estado_id` IN (
                      SELECT `gce_id` FROM `tb_gestion_coaching_estado` WHERE `gce_codigo` IN ('ASIGNADO','PENDIENTE_SUPERVISOR','RESPONDIDO_AGENTE','PENDIENTE_CIERRE')
                  )
                UNION ALL
                SELECT `gcp_id` FROM `tb_gestion_coaching_paquete`
                WHERE `gcp_agente_id` = ? AND `gcp_activo` = 1
                  AND `gcp_estado_id` IN (
                      SELECT `gce_id` FROM `tb_gestion_coaching_estado` WHERE `gce_codigo` IN ('PENDIENTE_AGENTE','PENDIENTE_FIRMA_AGENTE')
                  )
            ) AS pendientes_supervisor"
        );
        $consulta->bind_param('ss', $usu_id, $usu_id);
    } elseif (in_array($perfil, ['Administrador', 'Coordinación', 'Gerencia'], true)) {
        // Estos perfiles tienen alcance amplio de LECTURA sobre todo el
        // módulo, pero no son "el coacheado" de ningún paquete en
        // particular por defecto — no tiene sentido una alerta personal
        // aquí (si el Administrador SÍ resulta ser el gcp_agente_id de
        // algún paquete puntual, ese caso queda fuera del widget, igual
        // que ya pasaba antes de este ajuste).
        return 0;
    } else {
        // Fallback seguro para cualquier otro perfil — incluye 'Agente',
        // 'Calidad', y las etiquetas genéricas reales del portal
        // ('Usuario' = Agente, 'Gestor' = Líder de Calidad, ver
        // coachingFiltroAlcance()). Su rol aquí es siempre el del
        // coacheado, nunca el de un "equipo".
        $consulta = $enlace_db->prepare(
            "SELECT COUNT(*) AS total FROM `tb_gestion_coaching_paquete`
             WHERE `gcp_agente_id` = ? AND `gcp_activo` = 1
               AND `gcp_estado_id` IN (
                   SELECT `gce_id` FROM `tb_gestion_coaching_estado` WHERE `gce_codigo` IN ('PENDIENTE_AGENTE','PENDIENTE_FIRMA_AGENTE')
               )"
        );
        $consulta->bind_param('s', $usu_id);
    }
    $consulta->execute();
    $fila = $consulta->get_result()->fetch_assoc();
    return (int) ($fila['total'] ?? 0);
}

/**
 * Igual que coachingContarPendientesAccion(), pero además devuelve el
 * gcp_id cuando hay exactamente un paquete pendiente — para que la alerta
 * flotante pueda enlazar directo al paquete en vez de siempre a la
 * bandeja general.
 */
function coachingPendientesDetalle(mysqli $enlace_db, string $usu_id, string $perfil): array
{
    if ($perfil === 'Supervisor') {
        // Mismo criterio dual que coachingContarPendientesAccion(): un
        // Supervisor puede tener pendientes como gestor de su equipo y,
        // por separado, como coacheado por su propio Coordinador.
        $consulta = $enlace_db->prepare(
            "SELECT `gcp_id` FROM `tb_gestion_coaching_paquete`
             WHERE `gcp_supervisor_id` = ? AND `gcp_activo` = 1
               AND `gcp_estado_id` IN (
                   SELECT `gce_id` FROM `tb_gestion_coaching_estado` WHERE `gce_codigo` IN ('ASIGNADO','PENDIENTE_SUPERVISOR','RESPONDIDO_AGENTE','PENDIENTE_CIERRE')
               )
             UNION ALL
             SELECT `gcp_id` FROM `tb_gestion_coaching_paquete`
             WHERE `gcp_agente_id` = ? AND `gcp_activo` = 1
               AND `gcp_estado_id` IN (
                   SELECT `gce_id` FROM `tb_gestion_coaching_estado` WHERE `gce_codigo` IN ('PENDIENTE_AGENTE','PENDIENTE_FIRMA_AGENTE')
               )"
        );
        $consulta->bind_param('ss', $usu_id, $usu_id);
    } elseif (in_array($perfil, ['Administrador', 'Coordinación', 'Gerencia'], true)) {
        return ['total' => 0, 'unico_gcp_id' => null];
    } else {
        // Fallback seguro — ver nota en coachingContarPendientesAccion().
        $consulta = $enlace_db->prepare(
            "SELECT `gcp_id` FROM `tb_gestion_coaching_paquete`
             WHERE `gcp_agente_id` = ? AND `gcp_activo` = 1
               AND `gcp_estado_id` IN (
                   SELECT `gce_id` FROM `tb_gestion_coaching_estado` WHERE `gce_codigo` IN ('PENDIENTE_AGENTE','PENDIENTE_FIRMA_AGENTE')
               )"
        );
        $consulta->bind_param('s', $usu_id);
    }
    $consulta->execute();
    $filas = $consulta->get_result()->fetch_all(MYSQLI_ASSOC);
    $total = count($filas);
    return ['total' => $total, 'unico_gcp_id' => $total === 1 ? $filas[0]['gcp_id'] : null];
}


/**
 * Autorización de RECURSO para el detalle de un paquete concreto: ¿puede
 * este usuario ver ESTE gcp_id? Complementa (no reemplaza) el filtro de
 * alcance de la bandeja — se vuelve a validar aquí porque alguien podría
 * intentar acceder directo por URL a un gcp_id que no le corresponde
 * (defensa contra IDOR, ver Entregable 5 sección 2).
 */
function usuarioPuedeVerPaquete(mysqli $enlace_db, string $usu_id, string $perfil, string $gcp_id): bool
{
    // 'Gestor' NO tiene bypass amplio aquí — en este portal ese valor
    // representa al Líder de Calidad, que debe quedar sujeto al mismo
    // chequeo de recurso que cualquier otro perfil (ver nota extendida
    // en coachingFiltroAlcance()).
    if (in_array($perfil, ['Administrador', 'Calidad', 'Coordinación', 'Gerencia'], true)) {
        return true;
    }

    $consulta = $enlace_db->prepare(
        "SELECT `gcp_agente_id`, `gcp_supervisor_id` FROM `tb_gestion_coaching_paquete` WHERE `gcp_id` = ? LIMIT 1"
    );
    $consulta->bind_param('s', $gcp_id);
    $consulta->execute();
    $fila = $consulta->get_result()->fetch_assoc();

    if (!$fila) {
        return false;
    }

    // Autorización por RECURSO real, sin ramificar por el perfil de
    // módulo del actor: se compara directamente contra los dueños reales
    // del paquete (gcp_agente_id = a quién se le hace coaching,
    // gcp_supervisor_id = quién lo gestiona). Esto es lo que permite que
    // un Supervisor sea el COACHEADO de un paquete armado por su
    // Coordinador (perfil 'Administrador' en este módulo) y aun así pueda
    // ver su propio paquete — con el branching anterior por `$perfil`,
    // un Supervisor solo podía calzar contra gcp_supervisor_id y jamás
    // contra gcp_agente_id, aunque el paquete fuera suyo.
    return $usu_id === $fila['gcp_agente_id'] || $usu_id === $fila['gcp_supervisor_id'];
}



