<?php
declare(strict_types=1);

require_once __DIR__ . '/alerta_correos_helpers.php';
require_once __DIR__ . '/alerta_correos_auditoria.php';
require_once __DIR__ . '/alerta_correos_territorio.php';
require_once __DIR__ . '/alerta_correos_importacion_versionada.php';
require_once __DIR__ . '/alerta_correos_sim_fuente.php';
require_once __DIR__ . '/alerta_correos_informativas.php';

/**
 * Importador de casos de Alertas Correos desde el formato oficial de Excel.
 *
 * Reglas principales:
 * - NO crea regionales ni centros zonales. La maestra territorial existente manda.
 * - Cada fila válida y territorialmente mapeada crea un caso PENDIENTE_REVISION.
 * - NO envía correos al cargar. Los casos notificables conservan el flujo de aprobación; los informativos nunca generan correo.
 * - Evita duplicados con una huella SHA-256 del contenido funcional de la fila.
 * - Conserva datos adicionales del archivo para trazabilidad.
 */

function acCargaAlertasNormalizarAfecta(string $valor): string
{
    $v = acTerritorioNormalizarClave($valor);
    if (in_array($v, ['PTE', 'PENDIENTE', 'PENDIENTE POR VALIDAR'], true)) {
        return 'PTE';
    }
    if (in_array($v, ['SI', 'YES', '1'], true)) {
        return 'SI';
    }
    if (in_array($v, ['NO', '0'], true)) {
        return 'NO';
    }
    return trim($valor);
}

function acCargaAlertasNormalizarPrioridad(string $valor, string $defecto = 'MEDIA'): string
{
    $defecto = strtoupper(trim($defecto));
    if (!in_array($defecto, ['CRITICA', 'ALTA', 'MEDIA', 'BAJA'], true)) {
        $defecto = 'MEDIA';
    }

    $v = acTerritorioNormalizarClave($valor);
    $map = [
        'CRITICA' => 'CRITICA',
        'CRITICO' => 'CRITICA',
        'ALTA' => 'ALTA',
        'ALTO' => 'ALTA',
        'MEDIA' => 'MEDIA',
        'MEDIO' => 'MEDIA',
        'BAJA' => 'BAJA',
        'BAJO' => 'BAJA',
    ];

    return $map[$v] ?? $defecto;
}

function acCargaAlertasMarcaActiva(string $valor): bool
{
    $v = acTerritorioNormalizarClave($valor);
    return in_array($v, ['1', 'X', 'SI', 'S', 'TRUE'], true);
}

/**
 * Si el archivo trae las columnas de clasificación de la matriz 2026, se respeta esa marca.
 */
function acCargaAlertasRangoEsperaExplicito(array $row, array $map): ?string
{
    $columnas = [
        'MAS_DE_1_HORA' => 'MAS_DE_1_HORA',
        '2_HORAS_O_MAS' => '2_HORAS_O_MAS',
        '3_HORAS_O_MAS' => '3_HORAS_O_MAS',
        '4_HORAS_O_MAS' => '4_HORAS_O_MAS',
        '5_HORAS_O_MAS' => '5_HORAS_O_MAS',
        '6_HORAS_O_MAS' => '6_HORAS_O_MAS',
        '7_HORAS_O_MAS' => '7_HORAS_O_MAS',
    ];
    foreach ($columnas as $header => $codigo) {
        if (acCargaAlertasMarcaActiva(acImportacionValor($row, $map, [$header]))) {
            return $codigo;
        }
    }
    return null;
}

function acCargaAlertasFingerprint(array $r): string
{
    $campos = [
        trim((string)($r['sim'] ?? '')),
        acTerritorioNormalizarClave((string)($r['regional_canonica'] ?? $r['regional_archivo'] ?? '')),
        acTerritorioNormalizarClave((string)($r['punto_canonico'] ?? $r['punto_archivo'] ?? '')),
        acTerritorioNormalizarClave((string)($r['categoria'] ?? '')),
        acTerritorioNormalizarClave((string)($r['subcategoria'] ?? '')),
        trim((string)($r['descripcion'] ?? '')),
        acTerritorioNormalizarClave((string)($r['afecta_linea_tecnica'] ?? '')),
        trim((string)($r['justificacion'] ?? '')),
        trim((string)($r['observacion'] ?? '')),
        trim((string)($r['fecha_atencion'] ?? '')),
        trim((string)($r['fecha_remision_dsya'] ?? '')),
        trim((string)($r['descripcion_inicial'] ?? '')),
        acTerritorioNormalizarClave((string)($r['agente_registra'] ?? '')),
        trim((string)($r['fecha_marcacion'] ?? '')),
    ];

    return hash('sha256', implode("\x1F", $campos));
}

function acCargaAlertasEstadoEtiqueta(string $estado): string
{
    return match ($estado) {
        'LISTA' => 'Lista para crear',
        'CREADA' => 'Caso creado',
        'DUPLICADA' => 'Duplicada / ya existe',
        'ERROR_TERRITORIO' => 'Territorio no reconocido',
        'ERROR_DATOS' => 'Datos incompletos o inválidos',
        default => $estado,
    };
}

function acCargaAlertasEstadoClase(string $estado): string
{
    return match ($estado) {
        'LISTA', 'CREADA' => 'success',
        'DUPLICADA' => 'secondary',
        'ERROR_TERRITORIO' => 'warning',
        'ERROR_DATOS' => 'danger',
        default => 'secondary',
    };
}

function acCargaAlertasParsear(mysqli $db, string $ruta, string $nombreArchivo, string $prioridadDefecto = 'MEDIA'): array
{
    if (strtolower(pathinfo($nombreArchivo, PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new RuntimeException('El archivo de alertas debe estar en formato XLSX.');
    }

    $hojas = acImportacionLeerXlsx($ruta);
    $filas = null;
    $nombreHoja = '';

    $requeridos = [
        ['SIM_ASOCIADO', 'SIM'],
        ['REGIONAL_AFECTADA_POR_LA_ALERTA', 'REGIONAL'],
        ['PUNTO_DE_ATENCION_AFECTADO_POR_LA_ALERTA_REGIONAL_O_CENTRO_ZONAL', 'PUNTO_DE_ATENCION', 'CENTRO_ZONAL'],
        ['DESCRIPCION_DE_LA_ALERTA', 'DESCRIPCION'],
    ];

    foreach ($hojas as $nombre => $rows) {
        $h = acImportacionBuscarFilaEncabezado($rows, $requeridos, 30);
        if ($h) {
            $filas = $rows;
            $nombreHoja = (string)$nombre;
            break;
        }
    }

    if ($filas === null) {
        throw new RuntimeException(
            'No fue posible identificar el formato de alertas. El archivo debe contener SIM asociado, Regional afectada, Punto de atención afectado y Descripción de la alerta.'
        );
    }

    $header = acImportacionBuscarFilaEncabezado($filas, $requeridos, 30);
    if (!$header) {
        throw new RuntimeException('No fue posible identificar los encabezados obligatorios del archivo.');
    }

    $map = $header['map'];
    $salida = [];
    $vistosArchivo = [];
    $totalNoVacias = 0;

    $dupStmt = $db->prepare(
        "SELECT acc_id, acc_radicado
         FROM tb_alerta_correo_caso
         WHERE acc_origen='CARGA_EXCEL' AND acc_origen_referencia=? AND acc_activo=1
         LIMIT 1"
    );

    for ($i = $header['index'] + 1, $n = count($filas); $i < $n; $i++) {
        $row = $filas[$i];
        $filaExcel = $i + 1;

        $sim = trim(acImportacionValor($row, $map, ['SIM_ASOCIADO', 'SIM']));
        $regionalArchivo = trim(acImportacionValor($row, $map, ['REGIONAL_AFECTADA_POR_LA_ALERTA', 'REGIONAL']));
        $puntoArchivo = trim(acImportacionValor($row, $map, [
            'PUNTO_DE_ATENCION_AFECTADO_POR_LA_ALERTA_REGIONAL_O_CENTRO_ZONAL',
            'PUNTO_DE_ATENCION',
            'CENTRO_ZONAL',
        ]));
        $descripcion = trim(acImportacionValor($row, $map, ['DESCRIPCION_DE_LA_ALERTA', 'DESCRIPCION']));

        if ($sim === '' && $regionalArchivo === '' && $puntoArchivo === '' && $descripcion === '') {
            continue;
        }
        $totalNoVacias++;
        if ($totalNoVacias > 5000) {
            $dupStmt->close();
            throw new RuntimeException('El archivo supera el máximo de 5.000 filas de datos permitido por carga.');
        }

        $categoria = trim(acImportacionValor($row, $map, ['CATEGORIA_DE_AFECTACION', 'CATEGORIA']));
        $subcategoria = trim(acImportacionValor($row, $map, ['SUBCATEGORIA_DE_LA_ALERTA', 'SUBCATEGORIA', 'SUB_CATEGORIA']));
        $afecta = acCargaAlertasNormalizarAfecta(acImportacionValor($row, $map, ['AFECTA_LA_LINEA_TECNICA']));
        $justificacion = trim(acImportacionValor($row, $map, ['JUSTIFICACION_ALERTA', 'JUSTIFICACION']));
        $descripcionInicial = trim(acImportacionValor($row, $map, ['DESCRIPCION_DE_LA_ALERTA_INICIAL']));
        $observacion = trim(acImportacionValor($row, $map, ['OBSERVACIONES', 'OBSERVACION']));
        $agente = trim(acImportacionValor($row, $map, ['AGENTE_QUE_REGISTRA', 'AGENTE_REGISTRA']));

        $fechaAtencion = acSimFuenteExcelFecha(
            acImportacionValor($row, $map, ['FECHA_ATENCION', 'FECHA_DE_ATENCION_AL_CIUDADANO']),
            true
        );
        $fechaRemisionDsya = acSimFuenteExcelFecha(
            acImportacionValor($row, $map, ['FECHA_DE_REMISION_A_LA_DSYA', 'FECHA_DE_REMISION_AL_AGENTE_ESPECIALIZADO']),
            true
        );
        $fechaMarcacion = acSimFuenteExcelFecha(
            acImportacionValor($row, $map, ['FECHA_MARCACION']),
            true
        );
        $fechaAlerta = acSimFuenteExcelFecha(
            acImportacionValor($row, $map, ['FECHA_DE_ALERTA', 'FECHA_MARCACION']),
            false
        );

        $prioridad = acCargaAlertasNormalizarPrioridad(
            acImportacionValor($row, $map, ['TIPO_DE_ALERTA', 'PRIORIDAD', 'SEVERIDAD']),
            $prioridadDefecto
        );

        // Regla de negocio 2026: las alertas masivas de "Tiempos de espera muy largos"
        // son informativas y nunca generan correo territorial.
        $esInformativa = acAlertaEsCategoriaTiempoEspera($categoria);
        $tipoGestion = $esInformativa ? 'INFORMATIVA' : 'NOTIFICABLE';
        $enviaCorreo = $esInformativa ? 0 : 1;
        $rangoEspera = null;
        $minutosEspera = null;
        $fuenteRango = '';

        if ($esInformativa) {
            $rangoExplicito = acCargaAlertasRangoEsperaExplicito($row, $map);
            $clasificacionTexto = acAlertaClasificarTiempoEspera($justificacion, $descripcion);
            $minutosEspera = $clasificacionTexto['minutos'];
            if ($rangoExplicito !== null) {
                $rangoEspera = $rangoExplicito;
                $fuenteRango = 'MATRIZ_EXPLICITA';
            } else {
                $rangoEspera = $clasificacionTexto['rango'];
                $fuenteRango = (string)$clasificacionTexto['fuente'];
            }
        }

        $erroresDatos = [];
        if ($sim === '') {
            $erroresDatos[] = 'Falta el SIM asociado.';
        } elseif (mb_strlen($sim, 'UTF-8') > 50) {
            $erroresDatos[] = 'El SIM supera 50 caracteres.';
        }
        if ($regionalArchivo === '') {
            $erroresDatos[] = 'Falta la Regional afectada.';
        }
        if ($puntoArchivo === '') {
            $erroresDatos[] = 'Falta el Punto de atención afectado.';
        }
        if ($descripcion === '') {
            $erroresDatos[] = 'Falta la Descripción de la alerta.';
        } elseif (mb_strlen($descripcion, 'UTF-8') > 20000) {
            $erroresDatos[] = 'La descripción supera 20.000 caracteres.';
        }
        if (mb_strlen($categoria, 'UTF-8') > 255) {
            $erroresDatos[] = 'La categoría supera 255 caracteres.';
        }
        if (mb_strlen($subcategoria, 'UTF-8') > 255) {
            $erroresDatos[] = 'La subcategoría supera 255 caracteres.';
        }
        if (mb_strlen($afecta, 'UTF-8') > 100) {
            $erroresDatos[] = 'El campo Afecta la línea técnica supera 100 caracteres.';
        }
        if (mb_strlen($agente, 'UTF-8') > 200) {
            $erroresDatos[] = 'El agente que registra supera 200 caracteres.';
        }

        $regionalId = 0;
        $puntoId = 0;
        $regionalCanonica = '';
        $puntoCanonico = '';
        $erroresTerritorio = [];

        if ($regionalArchivo !== '' && $puntoArchivo !== '') {
            $mapeo = acSimFuenteMapearTerritorio($db, $regionalArchivo, $puntoArchivo);
            $regionalId = (int)($mapeo['regional_id'] ?? 0);
            $puntoId = (int)($mapeo['punto_id'] ?? 0);

            if ($regionalId <= 0) {
                $erroresTerritorio[] = 'La Regional no existe en la maestra territorial.';
            } elseif ($puntoId <= 0) {
                $erroresTerritorio[] = 'El Punto de atención no coincide con un Centro Zonal activo de esa Regional.';
            } else {
                $territorio = acTerritorioValidarSeleccion($db, $regionalId, $puntoId);
                if (!$territorio) {
                    $erroresTerritorio[] = 'La relación Regional / Punto de atención no es válida o está inactiva.';
                    $regionalId = 0;
                    $puntoId = 0;
                } else {
                    $regionalCanonica = (string)$territorio['regional']['acp_nombre'];
                    $puntoCanonico = (string)$territorio['punto']['acp_nombre'];
                }
            }
        }

        $registro = [
            'fila_excel' => $filaExcel,
            'sim' => $sim,
            'regional_archivo' => $regionalArchivo,
            'punto_archivo' => $puntoArchivo,
            'regional_id' => $regionalId,
            'punto_atencion_id' => $puntoId,
            'regional_canonica' => $regionalCanonica,
            'punto_canonico' => $puntoCanonico,
            'tipo_alerta' => $prioridad,
            'fecha_alerta' => $fechaAlerta ?? '',
            'fecha_atencion' => $fechaAtencion ?? '',
            'categoria' => $categoria,
            'subcategoria' => $subcategoria,
            'descripcion' => $descripcion,
            'afecta_linea_tecnica' => $afecta,
            'justificacion' => $justificacion,
            'observacion' => $observacion,
            'fecha_remision_dsya' => $fechaRemisionDsya ?? '',
            'descripcion_inicial' => $descripcionInicial,
            'agente_registra' => $agente,
            'fecha_marcacion' => $fechaMarcacion ?? '',
            'tipo_gestion' => $tipoGestion,
            'envia_correo' => $enviaCorreo,
            'tiempo_espera_minutos' => $minutosEspera,
            'tiempo_espera_rango' => $rangoEspera ?? '',
            'tiempo_espera_fuente' => $fuenteRango,
        ];
        $registro['fingerprint'] = acCargaAlertasFingerprint($registro);

        if ($erroresDatos) {
            $registro['estado'] = 'ERROR_DATOS';
            $registro['mensaje'] = implode(' ', $erroresDatos);
        } elseif ($erroresTerritorio) {
            $registro['estado'] = 'ERROR_TERRITORIO';
            $registro['mensaje'] = implode(' ', $erroresTerritorio);
        } elseif (isset($vistosArchivo[$registro['fingerprint']])) {
            $registro['estado'] = 'DUPLICADA';
            $registro['mensaje'] = 'La misma alerta aparece más de una vez dentro del archivo.';
        } else {
            $fingerprint = (string)$registro['fingerprint'];
            $dupStmt->bind_param('s', $fingerprint);
            $dupStmt->execute();
            $existente = $dupStmt->get_result()->fetch_assoc() ?: null;
            if ($existente) {
                $registro['estado'] = 'DUPLICADA';
                $registro['mensaje'] = 'Esta alerta ya fue creada anteriormente (' . (string)$existente['acc_radicado'] . ').';
                $registro['caso_existente_id'] = (int)$existente['acc_id'];
            } else {
                $registro['estado'] = 'LISTA';
                if ($esInformativa) {
                    $registro['mensaje'] = 'Alerta informativa: no enviará correo al aprobar. Clasificación de espera: '
                        . acAlertaTiempoRangoLabel($rangoEspera) . '.';
                } else {
                    $registro['mensaje'] = 'Regional y Punto de atención reconocidos. La fila puede convertirse en caso notificable.';
                }
            }
        }

        $vistosArchivo[$registro['fingerprint']] = true;
        $salida[] = $registro;
    }

    $dupStmt->close();

    if (!$salida) {
        throw new RuntimeException('El archivo no contiene filas de alertas para procesar.');
    }

    return [
        'hoja' => $nombreHoja,
        'filas' => $salida,
    ];
}

function acCargaAlertasPreparar(mysqli $db, string $ruta, string $nombreArchivo, string $prioridadDefecto = 'MEDIA'): array
{
    $parseado = acCargaAlertasParsear($db, $ruta, $nombreArchivo, $prioridadDefecto);
    $resumen = [
        'total' => count($parseado['filas']),
        'listas' => 0,
        'duplicadas' => 0,
        'errores_territorio' => 0,
        'errores_datos' => 0,
        'informativas' => 0,
        'informativas_sin_rango' => 0,
    ];

    foreach ($parseado['filas'] as $fila) {
        if ((string)($fila['tipo_gestion'] ?? '') === 'INFORMATIVA') {
            $resumen['informativas']++;
            if (trim((string)($fila['tiempo_espera_rango'] ?? '')) === '') {
                $resumen['informativas_sin_rango']++;
            }
        }
        switch ((string)$fila['estado']) {
            case 'LISTA':
                $resumen['listas']++;
                break;
            case 'DUPLICADA':
                $resumen['duplicadas']++;
                break;
            case 'ERROR_TERRITORIO':
                $resumen['errores_territorio']++;
                break;
            case 'ERROR_DATOS':
                $resumen['errores_datos']++;
                break;
        }
    }

    return [
        'nombre_archivo' => $nombreArchivo,
        'sha256' => hash_file('sha256', $ruta) ?: '',
        'hoja' => $parseado['hoja'],
        'prioridad_defecto' => acCargaAlertasNormalizarPrioridad('', $prioridadDefecto),
        'filas' => $parseado['filas'],
        'resumen' => $resumen,
    ];
}

function acCargaAlertasCrearCaso(mysqli $db, array $r, int $cargaId, string $usuario): int
{
    $radicadoTemporal = 'TMP-' . strtoupper(bin2hex(random_bytes(12)));
    $origen = 'CARGA_EXCEL';
    $origenReferencia = (string)$r['fingerprint'];

    $sim = trim((string)$r['sim']);
    $tipo = (string)$r['tipo_alerta'];
    $fechaAlerta = trim((string)$r['fecha_alerta']);
    $fechaAtencion = trim((string)$r['fecha_atencion']);
    $regional = (string)$r['regional_canonica'];
    $punto = (string)$r['punto_canonico'];
    $categoria = trim((string)$r['categoria']);
    $subcategoria = trim((string)$r['subcategoria']);
    $descripcion = trim((string)$r['descripcion']);
    $afecta = trim((string)$r['afecta_linea_tecnica']);
    $justificacion = trim((string)$r['justificacion']);
    $observacion = trim((string)$r['observacion']);
    $regionalId = (int)$r['regional_id'];
    $puntoId = (int)$r['punto_atencion_id'];
    $filaExcel = (int)$r['fila_excel'];
    $fechaRemision = trim((string)$r['fecha_remision_dsya']);
    $descripcionInicial = trim((string)$r['descripcion_inicial']);
    $agente = trim((string)$r['agente_registra']);
    $fechaMarcacion = trim((string)$r['fecha_marcacion']);
    $tipoGestion = strtoupper(trim((string)($r['tipo_gestion'] ?? 'NOTIFICABLE')));
    $enviaCorreo = (int)($r['envia_correo'] ?? 1);
    $tiempoEsperaMinutos = ($r['tiempo_espera_minutos'] ?? null) === null ? 0 : (int)$r['tiempo_espera_minutos'];
    $tiempoEsperaRango = trim((string)($r['tiempo_espera_rango'] ?? ''));
    $tiempoEsperaFuente = trim((string)($r['tiempo_espera_fuente'] ?? ''));

    $sql = "INSERT INTO tb_alerta_correo_caso (
                acc_radicado, acc_sim, acc_origen, acc_origen_referencia, acc_tipo_alerta, acc_estado,
                acc_fecha_alerta, acc_fecha_atencion, acc_regional, acc_centro_zonal,
                acc_categoria, acc_subcategoria, acc_descripcion, acc_afecta_linea_tecnica,
                acc_justificacion, acc_observacion, acc_usuario_creador, acc_usuario_ultima_actualizacion,
                acc_regional_id, acc_punto_atencion_id, acc_carga_id, acc_fila_origen,
                acc_fecha_remision_dsya, acc_descripcion_inicial, acc_agente_registra, acc_fecha_marcacion,
                acc_tipo_gestion, acc_envia_correo, acc_tiempo_espera_minutos, acc_tiempo_espera_rango, acc_tiempo_espera_fuente
            ) VALUES (
                ?, NULLIF(?, ''), ?, ?, ?, 'PENDIENTE_REVISION',
                NULLIF(?, ''), NULLIF(?, ''), ?, ?,
                NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''),
                NULLIF(?, ''), NULLIF(?, ''), ?, ?,
                ?, ?, ?, ?,
                NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                ?, ?, NULLIF(?,0), NULLIF(?,''), NULLIF(?, '')
            )";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        'sssssssssssssssssiiiisssssiiss',
        $radicadoTemporal,
        $sim,
        $origen,
        $origenReferencia,
        $tipo,
        $fechaAlerta,
        $fechaAtencion,
        $regional,
        $punto,
        $categoria,
        $subcategoria,
        $descripcion,
        $afecta,
        $justificacion,
        $observacion,
        $usuario,
        $usuario,
        $regionalId,
        $puntoId,
        $cargaId,
        $filaExcel,
        $fechaRemision,
        $descripcionInicial,
        $agente,
        $fechaMarcacion,
        $tipoGestion,
        $enviaCorreo,
        $tiempoEsperaMinutos,
        $tiempoEsperaRango,
        $tiempoEsperaFuente
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    if ($id <= 0) {
        throw new RuntimeException('No fue posible obtener el identificador del caso creado desde Carga Masiva.');
    }

    $radicado = sprintf('AC-%s-%06d', date('Ymd'), $id);
    $upd = $db->prepare('UPDATE tb_alerta_correo_caso SET acc_radicado=? WHERE acc_id=?');
    $upd->bind_param('si', $radicado, $id);
    $upd->execute();
    $upd->close();

    return $id;
}

function acCargaAlertasInsertarDetalle(mysqli $db, int $cargaId, array $fila, string $estado, ?int $casoId = null, ?string $mensaje = null): void
{
    $filaExcel = (int)$fila['fila_excel'];
    $sim = trim((string)$fila['sim']);
    $regional = trim((string)$fila['regional_archivo']);
    $punto = trim((string)$fila['punto_archivo']);
    $regionalId = (int)($fila['regional_id'] ?? 0);
    $puntoId = (int)($fila['punto_atencion_id'] ?? 0);
    $mensajeFinal = trim((string)($mensaje ?? $fila['mensaje'] ?? ''));
    $fingerprint = (string)$fila['fingerprint'];
    $tipoGestion = strtoupper(trim((string)($fila['tipo_gestion'] ?? 'NOTIFICABLE')));
    $rangoEspera = trim((string)($fila['tiempo_espera_rango'] ?? ''));

    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_carga_detalle
         (acd_carga_id, acd_fila_excel, acd_sim, acd_regional_origen, acd_punto_origen,
          acd_regional_id, acd_punto_atencion_id, acd_estado, acd_mensaje, acd_fingerprint, acd_caso_id,
          acd_tipo_gestion, acd_tiempo_espera_rango)
         VALUES (?, ?, NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,\'\'), NULLIF(?,0), NULLIF(?,0), ?, NULLIF(?,\'\'), ?, ?, ?, NULLIF(?,\'\'))'
    );
    $stmt->bind_param(
        'iisssiisssiss',
        $cargaId,
        $filaExcel,
        $sim,
        $regional,
        $punto,
        $regionalId,
        $puntoId,
        $estado,
        $mensajeFinal,
        $fingerprint,
        $casoId,
        $tipoGestion,
        $rangoEspera
    );
    $stmt->execute();
    $stmt->close();
}

function acCargaAlertasAplicar(mysqli $db, array $preview, string $usuario): array
{
    $resumen = $preview['resumen'];
    $total = (int)$resumen['total'];
    $validos = (int)$resumen['listas'] + (int)$resumen['duplicadas'];
    $invalidos = (int)$resumen['errores_territorio'] + (int)$resumen['errores_datos'];
    $creadosInicial = 0;
    $duplicadosInicial = (int)$resumen['duplicadas'];
    $informativasInicial = 0;
    $informativasSinRangoInicial = 0;
    $tipo = 'CASOS_ALERTAS_EXCEL';
    $nombre = (string)$preview['nombre_archivo'];
    $sha = (string)$preview['sha256'];
    $estado = 'PROCESANDO';
    $detalle = 'Pendiente de creación de casos';

    $stmt = $db->prepare(
        'INSERT INTO tb_alerta_correo_carga
         (acg_tipo, acg_archivo_nombre, acg_archivo_sha256, acg_total_registros,
          acg_registros_validos, acg_registros_invalidos, acg_registros_creados,
          acg_registros_duplicados, acg_registros_informativos, acg_registros_informativos_sin_rango,
          acg_estado, acg_detalle_error, acg_usuario)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param(
        'sssiiiiiiisss',
        $tipo,
        $nombre,
        $sha,
        $total,
        $validos,
        $invalidos,
        $creadosInicial,
        $duplicadosInicial,
        $informativasInicial,
        $informativasSinRangoInicial,
        $estado,
        $detalle,
        $usuario
    );
    $stmt->execute();
    $cargaId = (int)$db->insert_id;
    $stmt->close();

    if ($cargaId <= 0) {
        throw new RuntimeException('No fue posible registrar la auditoría de la carga.');
    }

    $resultado = [
        'carga_id' => $cargaId,
        'creadas' => 0,
        'duplicadas' => 0,
        'errores_territorio' => 0,
        'errores_datos' => 0,
        'informativas' => 0,
        'informativas_sin_rango' => 0,
        'casos' => [],
    ];

    $dupStmt = $db->prepare(
        "SELECT acc_id, acc_radicado
         FROM tb_alerta_correo_caso
         WHERE acc_origen='CARGA_EXCEL' AND acc_origen_referencia=? AND acc_activo=1
         LIMIT 1"
    );

    foreach ($preview['filas'] as $fila) {
        $estadoFila = (string)$fila['estado'];

        if ($estadoFila === 'ERROR_TERRITORIO') {
            $resultado['errores_territorio']++;
            acCargaAlertasInsertarDetalle($db, $cargaId, $fila, 'ERROR_TERRITORIO');
            continue;
        }
        if ($estadoFila === 'ERROR_DATOS') {
            $resultado['errores_datos']++;
            acCargaAlertasInsertarDetalle($db, $cargaId, $fila, 'ERROR_DATOS');
            continue;
        }
        if ($estadoFila === 'DUPLICADA') {
            $resultado['duplicadas']++;
            $casoExistente = isset($fila['caso_existente_id']) ? (int)$fila['caso_existente_id'] : null;
            acCargaAlertasInsertarDetalle($db, $cargaId, $fila, 'DUPLICADA', $casoExistente);
            continue;
        }

        $fingerprint = (string)$fila['fingerprint'];
        $dupStmt->bind_param('s', $fingerprint);
        $dupStmt->execute();
        $existente = $dupStmt->get_result()->fetch_assoc() ?: null;
        if ($existente) {
            $resultado['duplicadas']++;
            acCargaAlertasInsertarDetalle(
                $db,
                $cargaId,
                $fila,
                'DUPLICADA',
                (int)$existente['acc_id'],
                'La alerta fue creada por otra carga antes de confirmar esta previsualización (' . (string)$existente['acc_radicado'] . ').'
            );
            continue;
        }

        $casoId = acCargaAlertasCrearCaso($db, $fila, $cargaId, $usuario);
        $resultado['creadas']++;
        $resultado['casos'][] = $casoId;

        $esInformativa = strtoupper(trim((string)($fila['tipo_gestion'] ?? ''))) === 'INFORMATIVA';
        if ($esInformativa) {
            $resultado['informativas']++;
            if (trim((string)($fila['tiempo_espera_rango'] ?? '')) === '') {
                $resultado['informativas_sin_rango']++;
            }
        }

        $comentarioHistorial = $esInformativa
            ? 'Caso informativo creado automáticamente desde Carga Masiva. Pendiente de revisión; por regla de negocio no generará correo electrónico.'
            : 'Caso notificable creado automáticamente desde Carga Masiva. Pendiente de revisión; todavía no se envió correo.';

        acRegistrarHistorial(
            $db,
            $casoId,
            null,
            'PENDIENTE_REVISION',
            'CREAR_CARGA_EXCEL',
            $comentarioHistorial,
            [
                'carga_id' => $cargaId,
                'fila_excel' => (int)$fila['fila_excel'],
                'archivo' => (string)$preview['nombre_archivo'],
                'tipo_gestion' => (string)($fila['tipo_gestion'] ?? 'NOTIFICABLE'),
                'envia_correo' => (int)($fila['envia_correo'] ?? 1),
                'tiempo_espera_rango' => (string)($fila['tiempo_espera_rango'] ?? ''),
                'tiempo_espera_minutos' => $fila['tiempo_espera_minutos'] ?? null,
                'tiempo_espera_fuente' => (string)($fila['tiempo_espera_fuente'] ?? ''),
            ]
        );

        $mensajeDetalle = $esInformativa
            ? 'Caso informativo creado. Quedó pendiente de revisión y no enviará correo al ser aprobado. Rango: ' . acAlertaTiempoRangoLabel((string)($fila['tiempo_espera_rango'] ?? '')) . '.'
            : 'Caso notificable creado correctamente y enviado a la bandeja de revisión.';
        acCargaAlertasInsertarDetalle($db, $cargaId, $fila, 'CREADA', $casoId, $mensajeDetalle);
    }

    $dupStmt->close();

    $estadoFinal = 'COMPLETADA';
    $detalleFinal = 'Creadas=' . $resultado['creadas']
        . '; Duplicadas=' . $resultado['duplicadas']
        . '; Informativas sin correo=' . $resultado['informativas']
        . '; Informativas sin rango=' . $resultado['informativas_sin_rango']
        . '; Error territorio=' . $resultado['errores_territorio']
        . '; Error datos=' . $resultado['errores_datos']
        . '; Hoja=' . (string)$preview['hoja'];

    $upd = $db->prepare(
        'UPDATE tb_alerta_correo_carga
         SET acg_registros_creados=?, acg_registros_duplicados=?,
             acg_registros_informativos=?, acg_registros_informativos_sin_rango=?,
             acg_estado=?, acg_detalle_error=?
         WHERE acg_id=?'
    );
    $creadas = (int)$resultado['creadas'];
    $duplicadas = (int)$resultado['duplicadas'];
    $informativas = (int)$resultado['informativas'];
    $informativasSinRango = (int)$resultado['informativas_sin_rango'];
    $upd->bind_param('iiiissi', $creadas, $duplicadas, $informativas, $informativasSinRango, $estadoFinal, $detalleFinal, $cargaId);
    $upd->execute();
    $upd->close();

    return $resultado;
}

