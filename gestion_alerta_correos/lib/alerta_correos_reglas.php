<?php
declare(strict_types=1);

function acValidarCasoEntrada(array $d): array
{
    $errores = [];
    if (trim((string)($d['regional'] ?? '')) === '') $errores[] = 'La regional es obligatoria.';
    if (trim((string)($d['descripcion'] ?? '')) === '') $errores[] = 'La descripción es obligatoria.';
    if (!in_array((string)($d['tipo_alerta'] ?? ''), ['CRITICA','ALTA','MEDIA','BAJA'], true)) $errores[] = 'Tipo de alerta inválido.';
    if (mb_strlen((string)($d['descripcion'] ?? '')) > 20000) $errores[] = 'La descripción supera la longitud permitida.';
    return $errores;
}

function acTransicionPermitida(string $estado, string $accion): ?string
{
    $mapa = [
        'PENDIENTE_REVISION' => [
            'APROBAR' => 'APROBADO',
            'RECHAZAR' => 'RECHAZADO',
            'SOLICITAR_SUBSANACION' => 'PENDIENTE_SUBSANACION',
        ],
        'PENDIENTE_SUBSANACION' => [
            'SUBSANAR' => 'PENDIENTE_REVISION_SUBSANACION',
        ],
        'PENDIENTE_REVISION_SUBSANACION' => [
            'APROBAR' => 'APROBADO',
            'RECHAZAR' => 'RECHAZADO',
            'SOLICITAR_SUBSANACION' => 'PENDIENTE_SUBSANACION',
        ],
        'RECHAZADO' => [
            'REABRIR' => 'PENDIENTE_REVISION',
        ],
    ];
    return $mapa[$estado][$accion] ?? null;
}
