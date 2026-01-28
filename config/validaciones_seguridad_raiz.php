<?php
// --- Sesión segura (cookie Secure en HTTPS) ---
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

function validar_input($variable): string {
    $variable = (string)($variable ?? '');
    $variable = trim($variable);
    $variable = strip_tags($variable);
    $variable = stripslashes($variable);
    $variable = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
    $variable = str_replace("'", "", $variable);
    $variable = preg_replace('/\s+/', ' ', $variable);
    return trim($variable);
}

function validar_output($variable): string {
    $variable = (string)($variable ?? '');
    $variable = trim($variable);
    $variable = strip_tags($variable);
    $variable = stripslashes($variable);
    $variable = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
    $variable = str_replace("'", "", $variable);
    return trim($variable);
}

function comprobarSentencia($valor): bool {
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

function includeFileContent($fileName) {
    ob_start();
    ob_implicit_flush(false);
    include($fileName);
    return ob_get_clean();
}

require_once("validaciones_funciones.php");
