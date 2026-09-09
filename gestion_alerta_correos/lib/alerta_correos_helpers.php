<?php
declare(strict_types=1);

function acEscape(?string $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function acUsuarioActual(): string
{
    return (string)($_SESSION['usu_id'] ?? $_SESSION['usu_acceso'] ?? '');
}

function acPerfilActual(): string
{
    return (string)($_SESSION['modulos_acceso_permisos']['Alertas Correos'] ?? '');
}

function acTienePerfil(array $perfiles): bool
{
    return in_array(acPerfilActual(), $perfiles, true);
}

function acEstadoLabel(string $estado): string
{
    $mapa = [
        'PENDIENTE_REVISION' => 'Pendiente de revisión',
        'PENDIENTE_SUBSANACION' => 'Pendiente de subsanación',
        'PENDIENTE_REVISION_SUBSANACION' => 'Revisión de subsanación',
        'APROBADO' => 'Aprobado',
        'RECHAZADO' => 'Rechazado',
    ];
    return $mapa[$estado] ?? $estado;
}

function acEstadoClase(string $estado): string
{
    return match ($estado) {
        'APROBADO' => 'success',
        'RECHAZADO' => 'danger',
        'PENDIENTE_SUBSANACION', 'PENDIENTE_REVISION_SUBSANACION' => 'warning',
        default => 'secondary',
    };
}

function acTipoClase(string $tipo): string
{
    return match (strtoupper($tipo)) {
        'CRITICA' => 'danger',
        'ALTA' => 'warning',
        'MEDIA' => 'info',
        default => 'secondary',
    };
}

function acClienteIp(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) && strlen($ip) <= 45 ? $ip : null;
}

function acCsrfToken(): string
{
    if (empty($_SESSION['ac_csrf'])) {
        $_SESSION['ac_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['ac_csrf'];
}

function acCsrfValidar(?string $token): bool
{
    $actual = (string)($_SESSION['ac_csrf'] ?? '');
    return $actual !== '' && is_string($token) && hash_equals($actual, $token);
}

function acFlash(string $tipo, string $mensaje): void
{
    $_SESSION['ac_flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function acFlashTomar(): ?array
{
    $flash = $_SESSION['ac_flash'] ?? null;
    unset($_SESSION['ac_flash']);
    return is_array($flash) ? $flash : null;
}

function acNormalizarTexto(string $valor): string
{
    return trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);
}
