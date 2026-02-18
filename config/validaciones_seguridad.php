<?php

$isHttps = (
    (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
    || (isset($_SERVER["SERVER_PORT"]) && (int)$_SERVER["SERVER_PORT"] === 443)
    || (!empty($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https")
);


ini_set("session.cookie_httponly", "1");


ini_set("session.cookie_samesite", "Lax");

if ($isHttps) {
    ini_set("session.cookie_secure", "1");
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(0);

if (!defined('URL')) {
    $proto = '';

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = (string)$_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $proto = 'https';
    } else {
        $proto = 'http';
    }

    // Host (respetando reverse proxy)
    $host = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = (string)$_SERVER['HTTP_X_FORWARDED_HOST'];
        $parts = explode(',', $host);
        $host = trim($parts[0]);
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string)$_SERVER['HTTP_HOST'];
    } else {
        $host = 'localhost';
    }

    // Normaliza
    $proto = ($proto === 'https') ? 'https' : 'http';
    $base  = $proto . '://' . $host . '/';

    define('URL', $base);
}


if (!function_exists('validar_input')) {
    function validar_input($variable): string
    {
        $variable = (string)($variable ?? '');
        $variable = trim($variable);
        $variable = strip_tags($variable);
        $variable = stripslashes($variable);
        $variable = htmlspecialchars($variable, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $variable = str_replace("'", "", $variable);
        $variable = preg_replace('/\s+/', ' ', $variable);
        return trim($variable);
    }
}

if (!function_exists('validar_output')) {
    function validar_output($variable): string
    {
        $variable = (string)($variable ?? '');
        $variable = trim($variable);
        $variable = strip_tags($variable);
        $variable = stripslashes($variable);
        $variable = htmlspecialchars($variable, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $variable = str_replace("'", "", $variable);
        return trim($variable);
    }
}

if (!function_exists('comprobarSentencia')) {
    function comprobarSentencia($valor): bool
    {
        $valor = (string)($valor ?? '');
        preg_match_all('/(\S[^:]+): (\d+)/', $valor, $matches);
        if (empty($matches[1]) || empty($matches[2])) return false;

        $array_info = @array_combine($matches[1], $matches[2]);
        if (!is_array($array_info)) return false;

        return (
            isset($array_info['Rows matched'], $array_info['Warnings'])
            && (int)$array_info['Rows matched'] === 1
            && (int)$array_info['Warnings'] === 0
        );
    }
}

if (!function_exists('includeFileContent')) {
    function includeFileContent($fileName)
    {
        ob_start();
        ob_implicit_flush(false);
        include($fileName);
        return ob_get_clean();
    }
}


// Validación de sesión
if (!isset($_SESSION["usu_id"]) || $_SESSION["usu_id"] === null || $_SESSION["usu_id"] === "") {
    header("Location:../index.php");
    exit;
}

if (!isset($modulo_plataforma) || $modulo_plataforma === "") {
    header("Location:../permiso_denegado.php");
    exit;
}

if (!isset($_SESSION['modulos_acceso_permisos']) || !is_array($_SESSION['modulos_acceso_permisos'])) {
    header("Location:../contenido.php");
    exit;
}

if (
    !isset($_SESSION['modulos_acceso_permisos'][$modulo_plataforma])
    || $_SESSION['modulos_acceso_permisos'][$modulo_plataforma] === ""
) {
    header("Location:../permiso_denegado.php");
    exit;
}

$perfil_modulo = $_SESSION['modulos_acceso_permisos'][$modulo_plataforma];

require_once("validaciones_funciones.php");
?>
