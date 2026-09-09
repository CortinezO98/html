<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_helpers.php';

function acExigirPerfil(array $perfiles): void
{
    if (!acTienePerfil($perfiles)) {
        http_response_code(403);
        header('Location: ../permiso_denegado.php');
        exit;
    }
}

function acExigirPost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit('Método no permitido.');
    }
}

function acValidarCsrfPost(): void
{
    if (!acCsrfValidar($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        exit('La sesión del formulario expiró. Recargue la página e intente nuevamente.');
    }
}

function acPostString(string $clave, int $max = 2000): string
{
    $valor = trim((string)($_POST[$clave] ?? ''));
    if (mb_strlen($valor) > $max) {
        throw new InvalidArgumentException("El campo {$clave} supera la longitud permitida.");
    }
    return $valor;
}

function acPostInt(string $clave): int
{
    $valor = filter_input(INPUT_POST, $clave, FILTER_VALIDATE_INT);
    if ($valor === false || $valor === null || $valor <= 0) {
        throw new InvalidArgumentException('Identificador inválido.');
    }
    return (int)$valor;
}
