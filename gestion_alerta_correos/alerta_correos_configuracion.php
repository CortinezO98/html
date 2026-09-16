<?php
declare(strict_types=1);

$modulo_plataforma = 'Alertas Correos';

require_once '../config/validaciones_seguridad.php';
require_once '../config/conexion_db.php';
require_once __DIR__ . '/lib/alerta_correos_seguridad.php';

acExigirPerfil(['Administrador']);

$errores = [];
$exito = '';

$stmt = $enlace_db->prepare(
    "SELECT
        accf_valor,
        accf_descripcion,
        accf_usuario_actualizacion,
        accf_fecha_actualizacion
     FROM tb_alerta_correo_configuracion
     WHERE accf_clave='CC_APROBACION'
     LIMIT 1"
);

if (!$stmt) {
    throw new RuntimeException(
        'No fue posible consultar la configuración: ' . $enlace_db->error
    );
}

$stmt->execute();
$config = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$correoCc = trim((string)($config['accf_valor'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    acValidarCsrfPost();

    $correoCc = strtolower(trim((string)($_POST['correo_cc_aprobacion'] ?? '')));

    if ($correoCc === '') {
        $errores[] = 'Debe registrar un correo para la copia de aprobación.';
    } elseif (!filter_var($correoCc, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo ingresado no tiene un formato válido.';
    } elseif (strlen($correoCc) > 190) {
        $errores[] = 'El correo ingresado supera la longitud permitida.';
    }

    if (!$errores) {
        $usuario = acUsuarioActual();

        $stmt = $enlace_db->prepare(
            "INSERT INTO tb_alerta_correo_configuracion
            (
                accf_clave,
                accf_valor,
                accf_descripcion,
                accf_usuario_actualizacion,
                accf_fecha_actualizacion
            )
            VALUES
            (
                'CC_APROBACION',
                ?,
                'Correo que siempre recibe copia al aprobar y notificar una alerta',
                ?,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                accf_valor=VALUES(accf_valor),
                accf_descripcion=VALUES(accf_descripcion),
                accf_usuario_actualizacion=VALUES(accf_usuario_actualizacion),
                accf_fecha_actualizacion=NOW()"
        );

        if (!$stmt) {
            $errores[] = 'No fue posible preparar la actualización de configuración.';
        } else {
            $stmt->bind_param('ss', $correoCc, $usuario);
            $stmt->execute();
            $stmt->close();

            $exito = 'La configuración fue actualizada correctamente.';

            $stmt = $enlace_db->prepare(
                "SELECT
                    accf_valor,
                    accf_descripcion,
                    accf_usuario_actualizacion,
                    accf_fecha_actualizacion
                 FROM tb_alerta_correo_configuracion
                 WHERE accf_clave='CC_APROBACION'
                 LIMIT 1"
            );

            $stmt->execute();
            $config = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            $correoCc = trim((string)($config['accf_valor'] ?? ''));
        }
    }
}

$titulo_header = 'Alertas Correos | Configuración';
?>
<!DOCTYPE html>
<html lang="ES">
<head>
    <?php include '../config/configuracion_estilos.php'; ?>

    <link
        rel="stylesheet"
        href="assets/alerta_correos.css?v=20260909"
    >

    
    <style>
        .acc-config-page {
            padding: 82px 20px 90px;
        }

        .acc-config-wrap {
            max-width: 980px;
            margin: 0 auto;
        }

        .acc-config-topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
        }

        .acc-config-topbar__title {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            color: #263238;
        }

        .acc-config-topbar__subtitle {
            margin: 5px 0 0;
            font-size: 13px;
            color: #607d8b;
        }

        .acc-config-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 8px 15px;
            border: 1.5px solid #dc3545;
            border-radius: 8px;
            background: #fff;
            color: #dc3545 !important;
            font-weight: 700;
            text-decoration: none !important;
            transition: all .18s ease;
            white-space: nowrap;
        }

        .acc-config-back:hover,
        .acc-config-back:focus {
            background: #dc3545;
            border-color: #dc3545;
            color: #fff !important;
            box-shadow: 0 3px 10px rgba(220, 53, 69, .18);
        }

        .acc-config-card {
            background: #fff;
            border: 1px solid #dfe7e2;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.06);
            overflow: hidden;
        }

        .acc-config-card__head {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #4CAF50;
            color: #fff;
            padding: 18px 22px;
        }

        .acc-config-card__head h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
        }

        .acc-config-card__body {
            padding: 24px;
        }

        .acc-config-intro {
            margin-bottom: 20px;
            padding: 12px 14px;
            border-left: 4px solid #4CAF50;
            background: #f6faf7;
            color: #455a64;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.6;
        }

        .acc-config-label {
            font-weight: 700;
            color: #263238;
            margin-bottom: 7px;
        }

        .acc-config-input {
            min-height: 42px;
            border-radius: 7px;
        }

        .acc-config-help {
            font-size: 12px;
            color: #78909c;
            margin-top: 7px;
            line-height: 1.5;
        }

        .acc-config-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            margin-top: 8px;
        }

        .acc-config-save {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 240px;
            min-height: 42px;
            margin: 20px auto 0;
            padding: 9px 20px;
            background: #ffffff !important;
            color: #4CAF50 !important;
            border: 1.5px solid #4CAF50 !important;
            border-radius: 8px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: none;
            cursor: pointer;
            transition: background-color .18s ease,
                        color .18s ease,
                        border-color .18s ease,
                        box-shadow .18s ease;
        }

        .acc-config-meta {
            margin-top: 22px;
            padding: 14px 16px;
            background: #f8faf9;
            border: 1px solid #e1e8e4;
            border-left: 4px solid #4CAF50;
            border-radius: 7px;
            font-size: 12px;
            line-height: 1.7;
            color: #455a64;
        }

        .acc-config-meta strong {
            color: #263238;
        }

        @media (max-width: 767.98px) {
            .acc-config-page {
                padding: 16px 12px 100px;
            }

            .acc-config-topbar {
                flex-direction: column;
                align-items: stretch;
            }

            .acc-config-back {
                align-self: flex-end;
            }

            .acc-config-card__body {
                padding: 18px 16px;
            }

            .acc-config-topbar__title {
                font-size: 21px;
            }
        }
    
        .acc-config-save:hover,
        .acc-config-save:focus,
        .acc-config-save:active {
            background: #4CAF50 !important;
            color: #ffffff !important;
            border-color: #4CAF50 !important;
            box-shadow: 0 3px 10px rgba(76, 175, 80, .20) !important;
            outline: none;
        }

        .acc-config-save .fas {
            margin-right: 8px;
        }

    </style>

</head>

<body>

<?php
include '../menu_principal.php';
include '../menu_header.php';
?>

<div class="contenido ac-module acc-config-page">

    <div class="acc-config-wrap">

        <div class="acc-config-topbar">
            <div>
                <h1 class="acc-config-topbar__title">
                    <span class="fas fa-cog"></span>
                    Configuración de Alertas Correos
                </h1>

                <div class="acc-config-topbar__subtitle">
                    Parámetros generales de notificación del módulo.
                </div>
            </div>

            <a
                href="alerta_correos.php"
                class="acc-config-back"
            >
                <span class="fas fa-arrow-left"></span>
                Volver
            </a>
        </div>

        <?php if ($exito !== ''): ?>
            <div class="alert alert-success">
                <span class="fas fa-check-circle mr-1"></span>
                <?php echo acEscape($exito); ?>
            </div>
        <?php endif; ?>

        <?php if ($errores): ?>
            <div class="alert alert-danger">
                <strong>No fue posible guardar:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errores as $error): ?>
                        <li><?php echo acEscape($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="acc-config-card">

            <div class="acc-config-card__head">
                <h2>
                    <span class="fas fa-envelope-open-text mr-1"></span>
                    Copia obligatoria en aprobaciones
                </h2>
            </div>

            <div class="acc-config-card__body">

                <div class="acc-config-intro">
                    Este correo recibirá una copia cada vez que un caso sea procesado mediante
                    <strong>Aprobar y notificar</strong>.
                    Puede actualizarse únicamente desde el perfil Administrador.
                </div>

                <form method="post" autocomplete="off">

                    <input
                        type="hidden"
                        name="_csrf"
                        value="<?php echo acEscape(acCsrfToken()); ?>"
                    >

                    <div class="form-group">

                        <label for="correo_cc_aprobacion" class="acc-config-label">
                            <strong>Correo en copia (CC)</strong>
                        </label>

                        <input
                            type="email"
                            class="form-control acc-config-input"
                            id="correo_cc_aprobacion"
                            name="correo_cc_aprobacion"
                            maxlength="190"
                            required
                            value="<?php echo acEscape($correoCc); ?>"
                            placeholder="correo@icbf.gov.co"
                        >

                        <div class="acc-config-help">
                            El correo se agregará al CC de todas las nuevas
                            aprobaciones. Los correos ya enviados no serán
                            modificados.
                        </div>

                    </div>

                    <div class="acc-config-actions">
                    <button
                        type="submit"
                        class="acc-config-save"
                    >
                        <span class="fas fa-save mr-1"></span>
                        Guardar configuración
                    </button>
                    </div>

                </form>

                <div class="acc-config-meta">

                    <div>
                        <strong>Configuración actual:</strong>
                        <?php echo acEscape($correoCc); ?>
                    </div>

                    <?php if (!empty($config['accf_usuario_actualizacion'])): ?>
                        <div class="mt-1">
                            <strong>Última modificación:</strong>
                            <?php echo acEscape(
                                (string)$config['accf_usuario_actualizacion']
                            ); ?>
                            ·
                            <?php echo acEscape(
                                (string)$config['accf_fecha_actualizacion']
                            ); ?>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </div>

    </div>

</div>

<?php
include '../footer.php';
include '../config/configuracion_js.php';
?>

</body>
</html>
