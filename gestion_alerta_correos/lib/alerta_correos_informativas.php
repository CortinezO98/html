<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_territorio.php';

/**
 * Reglas para alertas informativas.
 *
 * La regla solicitada aplica únicamente a casos creados por carga masiva:
 * - Categoría: "Tiempos de espera muy largos".
 * - Se conserva el ciclo de revisión/aprobación.
 * - Al aprobar NO se genera ni se encola correo electrónico.
 * - Se clasifica el tiempo reportado con los mismos rangos usados en la matriz 2026.
 */

function acAlertaEsCategoriaTiempoEspera(?string $categoria): bool
{
    return acTerritorioNormalizarClave((string)$categoria) === 'TIEMPOS DE ESPERA MUY LARGOS';
}

function acAlertaEsInformativa(array $caso): bool
{
    if (array_key_exists('acc_envia_correo', $caso) && (int)$caso['acc_envia_correo'] === 0) {
        return true;
    }
    if (strtoupper(trim((string)($caso['acc_tipo_gestion'] ?? ''))) === 'INFORMATIVA') {
        return true;
    }

    // Compatibilidad defensiva para casos masivos anteriores a la migración nueva.
    return strtoupper(trim((string)($caso['acc_origen'] ?? ''))) === 'CARGA_EXCEL'
        && acAlertaEsCategoriaTiempoEspera((string)($caso['acc_categoria'] ?? ''));
}

function acAlertaTiempoRangos(): array
{
    return [
        'MAS_DE_1_HORA' => 'Más de 1 hora',
        '2_HORAS_O_MAS' => '2 horas o más',
        '3_HORAS_O_MAS' => '3 horas o más',
        '4_HORAS_O_MAS' => '4 horas o más',
        '5_HORAS_O_MAS' => '5 horas o más',
        '6_HORAS_O_MAS' => '6 horas o más',
        '7_HORAS_O_MAS' => '7 horas o más',
    ];
}

function acAlertaTiempoRangoLabel(?string $codigo): string
{
    $codigo = strtoupper(trim((string)$codigo));
    if ($codigo === '') return 'Pendiente por clasificar';
    return acAlertaTiempoRangos()[$codigo] ?? $codigo;
}

function acAlertaTiempoRangoDesdeMinutos(?int $minutos): ?string
{
    if ($minutos === null || $minutos <= 60) return null;
    if ($minutos < 120) return 'MAS_DE_1_HORA';
    if ($minutos < 180) return '2_HORAS_O_MAS';
    if ($minutos < 240) return '3_HORAS_O_MAS';
    if ($minutos < 300) return '4_HORAS_O_MAS';
    if ($minutos < 360) return '5_HORAS_O_MAS';
    if ($minutos < 420) return '6_HORAS_O_MAS';
    return '7_HORAS_O_MAS';
}

function acAlertaTiempoTextoBase(string $texto): string
{
    $texto = strtr($texto, [
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
    ]);
    $texto = mb_strtoupper($texto, 'UTF-8');
    $texto = str_replace(["\r", "\n", "\t"], ' ', $texto);
    $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;

    // Convierte números escritos frecuentes a dígitos, solo como palabras completas.
    $palabras = [
        'UNA'=>'1','UNO'=>'1','UN'=>'1',
        'DOS'=>'2','TRES'=>'3','CUATRO'=>'4','CINCO'=>'5','SEIS'=>'6','SIETE'=>'7','OCHO'=>'8','NUEVE'=>'9','DIEZ'=>'10',
    ];
    foreach ($palabras as $palabra => $numero) {
        $texto = preg_replace('/\b' . $palabra . '\b/u', $numero, $texto) ?? $texto;
    }
    return trim($texto);
}

function acAlertaTiempoHora12AMinutos(int $hora, int $minuto, string $ampm): int
{
    $ampm = strtoupper(str_replace(['.', ' '], '', $ampm));
    $hora = max(1, min(12, $hora));
    $minuto = max(0, min(59, $minuto));
    if ($ampm === 'AM') {
        if ($hora === 12) $hora = 0;
    } elseif ($ampm === 'PM') {
        if ($hora !== 12) $hora += 12;
    }
    return ($hora * 60) + $minuto;
}

/**
 * Extrae minutos de espera de un texto con reglas determinísticas.
 * Prioriza expresiones explícitas de espera/duración y, como respaldo, intervalos de llegada-atención/salida.
 */
function acAlertaTiempoExtraerMinutos(string $texto): ?int
{
    $t = acAlertaTiempoTextoBase($texto);
    if ($t === '') return null;

    // Expresiones cualitativas como "esperó más de una hora".
    // Se devuelve el umbral + 1 minuto para conservar la semántica "más de".
    if (preg_match('/(?:TIEMPO\s+DE\s+ESPERA|ESPERA|ESPERE|ESPERO|DURE|DURO|DEMOR(?:E|O)|TARDE|TARDO|PERMANECI|ESTUVE).{0,90}?MAS\s+DE\s+(\d{1,2})\s+HORAS?/u', $t, $m)) {
        return ((int)$m[1] * 60) + 1;
    }

    // "tiempo de espera fue de 2 horas y 22 minutos"
    if (preg_match('/TIEMPO\s+DE\s+ESPERA.{0,90}?(\d{1,2})\s+HORAS?(?:\s+(?:Y\s+)?(\d{1,2})\s+MINUTOS?)?/u', $t, $m)) {
        return ((int)$m[1] * 60) + (isset($m[2]) ? (int)$m[2] : 0);
    }

    // "esperé 2 horas y media", "duré 3 horas y 20 minutos", "demoré una hora y media"
    if (preg_match('/(?:ESPERE|ESPERO|ESPERAR|DURE|DURO|DEMOR(?:E|O)|TARDE|TARDO|PERMANECI|ESTUVE).{0,70}?(\d{1,2})\s+HORAS?\s+Y\s+MEDIA/u', $t, $m)) {
        return ((int)$m[1] * 60) + 30;
    }
    if (preg_match('/(?:ESPERE|ESPERO|ESPERAR|DURE|DURO|DEMOR(?:E|O)|TARDE|TARDO|PERMANECI|ESTUVE).{0,70}?(\d{1,2})\s+HORAS?(?:\s+(?:Y\s+)?(\d{1,2})\s+MINUTOS?)?/u', $t, $m)) {
        return ((int)$m[1] * 60) + (isset($m[2]) ? (int)$m[2] : 0);
    }

    // "hora y media" sin número explícito.
    if (preg_match('/(?:ESPERA.{0,60}?)?(?:1\s+)?HORA\s+Y\s+MEDIA/u', $t)) {
        return 90;
    }

    // "esperé 90 minutos" / "tiempo de espera no supera los 60 minutos".
    if (preg_match('/(?:TIEMPO\s+DE\s+ESPERA|ESPERE|ESPERO|DURE|DURO|DEMOR(?:E|O)|TARDE|TARDO).{0,70}?(\d{2,3})\s+MINUTOS?/u', $t, $m)) {
        return (int)$m[1];
    }

    // Intervalo de llegada/ingreso hasta atención/salida. Soporta 10:00 AM y 10 00 AM.
    $patronIntervalo = '/(?:LLEG(?:UE|O)|INGRES(?:E|O)|ACERQUE|ENTRE).{0,90}?(\d{1,2})(?:\s*[:\.]?\s*(\d{2}))?\s*(A\.?\s*M\.?|P\.?\s*M\.?).{0,180}?(?:ATENDIERON|ATENDIO|SAL(?:I|IO)|RETIR(?:E|O)).{0,90}?(\d{1,2})(?:\s*[:\.]?\s*(\d{2}))?\s*(A\.?\s*M\.?|P\.?\s*M\.?)/u';
    if (preg_match($patronIntervalo, $t, $m)) {
        $inicio = acAlertaTiempoHora12AMinutos((int)$m[1], isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0, (string)$m[3]);
        $fin = acAlertaTiempoHora12AMinutos((int)$m[4], isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0, (string)$m[6]);
        if ($fin < $inicio) $fin += 24 * 60;
        $delta = $fin - $inicio;
        if ($delta > 0 && $delta <= 16 * 60) return $delta;
    }

    // Último respaldo: una duración explícita cercana a la palabra ESPERA.
    if (preg_match('/ESPERA.{0,70}?(\d{1,2})\s+HORAS?(?:\s+(?:Y\s+)?(\d{1,2})\s+MINUTOS?)?/u', $t, $m)) {
        return ((int)$m[1] * 60) + (isset($m[2]) ? (int)$m[2] : 0);
    }

    return null;
}

function acAlertaJustificacionNoAplica(string $justificacion): bool
{
    $t = acAlertaTiempoTextoBase($justificacion);
    return str_contains($t, 'NO APLICA ALERTA')
        || str_contains($t, 'NO PROCEDE ALERTA')
        || str_contains($t, 'NO APLICA LA ALERTA')
        || str_contains($t, 'NO PROCEDE LA ALERTA');
}

/**
 * Clasificación por texto. La justificación tiene precedencia porque suele contener la validación del cliente.
 * Si la justificación declara que no aplica y el valor validado es <=60 min, no se fuerza un rango >1h desde la descripción inicial.
 *
 * @return array{minutos:?int,rango:?string,fuente:string}
 */
function acAlertaClasificarTiempoEspera(string $justificacion, string $descripcion): array
{
    $minJustificacion = acAlertaTiempoExtraerMinutos($justificacion);
    if ($minJustificacion !== null) {
        return [
            'minutos' => $minJustificacion,
            'rango' => acAlertaTiempoRangoDesdeMinutos($minJustificacion),
            'fuente' => 'JUSTIFICACION',
        ];
    }

    if (acAlertaJustificacionNoAplica($justificacion)) {
        return ['minutos' => null, 'rango' => null, 'fuente' => 'JUSTIFICACION_NO_APLICA'];
    }

    $minDescripcion = acAlertaTiempoExtraerMinutos($descripcion);
    if ($minDescripcion !== null) {
        return [
            'minutos' => $minDescripcion,
            'rango' => acAlertaTiempoRangoDesdeMinutos($minDescripcion),
            'fuente' => 'DESCRIPCION',
        ];
    }

    return ['minutos' => null, 'rango' => null, 'fuente' => 'NO_IDENTIFICADO'];
}
