<?php
    // --- Sesion segura (cookie Secure en HTTPS) ---
    $isHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (isset($_SERVER["SERVER_PORT"]) && (int)$_SERVER["SERVER_PORT"] === 443)
        || (!empty($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https");

    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_samesite", "Lax");
    if ($isHttps) {
        ini_set("session.cookie_secure", "1");
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    error_reporting(0);

    // ✅ FIX: a prueba de null/bool (evita TypeError -> 500 en PHP 8)
    function validar_input($variable) {
        $variable = (string)($variable ?? '');
        $variable = trim($variable);
        $variable = strip_tags($variable);
        $variable = stripslashes($variable);
        $variable = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
        $variable = str_replace("'", "", $variable);
        return $variable;
    }

    // ✅ FIX: a prueba de null/bool (evita TypeError -> 500 en PHP 8)
    function validar_output($variable) {
        $variable = (string)($variable ?? '');
        $variable = trim($variable);
        $variable = strip_tags($variable);
        $variable = stripslashes($variable);
        $variable = htmlspecialchars($variable, ENT_QUOTES, 'UTF-8');
        $variable = str_replace("'", "", $variable);
        $variable = trim($variable);
        return $variable;
    }

    function comprobarSentencia ($valor) {
        preg_match_all('/(\S[^:]+): (\d+)/', $valor, $matches);
        $array_info = array_combine ($matches[1], $matches[2]);

        if ($array_info['Rows matched']==1 AND $array_info['Warnings']==0) {
            return true;
        } else {
            return false;
        }
    }

    function includeFileContent($fileName) {
        ob_start();
        ob_implicit_flush(false);
        include($fileName);
        return ob_get_clean();
    }

    require_once("validaciones_funciones.php");
?>
