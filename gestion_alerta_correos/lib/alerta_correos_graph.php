<?php
declare(strict_types=1);

const AC_GRAPH_ENV = '/etc/icbf-alertas-correos/graph.env';

function acGraphConfig(): array
{
    if (!is_readable(AC_GRAPH_ENV)) {
        throw new RuntimeException(
            'No se puede leer la configuración de Microsoft Graph.'
        );
    }

    $env = parse_ini_file(AC_GRAPH_ENV, false, INI_SCANNER_RAW);

    if (!is_array($env)) {
        throw new RuntimeException(
            'No fue posible leer la configuración de Microsoft Graph.'
        );
    }

    foreach (
        [
            'GRAPH_TENANT_ID',
            'GRAPH_CLIENT_ID',
            'GRAPH_CLIENT_SECRET',
            'GRAPH_MAILBOX'
        ] as $key
    ) {
        if (empty($env[$key])) {
            throw new RuntimeException(
                'Falta configuración Graph: ' . $key
            );
        }
    }

    return $env;
}

function acGraphToken(): string
{
    $config = acGraphConfig();

    $url =
        'https://login.microsoftonline.com/'
        . rawurlencode($config['GRAPH_TENANT_ID'])
        . '/oauth2/v2.0/token';

    $post = http_build_query([
        'client_id' => $config['GRAPH_CLIENT_ID'],
        'client_secret' => $config['GRAPH_CLIENT_SECRET'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ]);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
    ]);

    $response = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false || $error !== '') {
        throw new RuntimeException(
            'Error solicitando token Graph: ' . $error
        );
    }

    $data = json_decode($response, true);

    if (
        $http !== 200 ||
        !is_array($data) ||
        empty($data['access_token'])
    ) {
        $detalle = is_array($data)
            ? ($data['error_description'] ?? $data['error'] ?? 'Sin detalle')
            : 'Respuesta inválida';

        throw new RuntimeException(
            'Microsoft Graph rechazó autenticación: ' . $detalle
        );
    }

    return (string)$data['access_token'];
}

function acGraphDestinatarios(string $cadena): array
{
    $resultado = [];
    $vistos = [];

    foreach (explode(';', $cadena) as $item) {
        $item = trim($item);

        if ($item === '') {
            continue;
        }

        $partes = explode('|', $item, 2);

        $correo = strtolower(trim($partes[0] ?? ''));
        $nombre = trim($partes[1] ?? '');

        if (
            !filter_var($correo, FILTER_VALIDATE_EMAIL) ||
            isset($vistos[$correo])
        ) {
            continue;
        }

        $vistos[$correo] = true;

        $resultado[] = [
            'emailAddress' => [
                'address' => $correo,
                'name' => $nombre !== '' ? $nombre : $correo,
            ],
        ];
    }

    return $resultado;
}

function acGraphEnviarCorreo(array $notificacion): void
{
    $config = acGraphConfig();
    $token = acGraphToken();

    $to = acGraphDestinatarios(
        (string)($notificacion['acn_to'] ?? '')
    );

    $cc = acGraphDestinatarios(
        (string)($notificacion['acn_cc'] ?? '')
    );

    $bcc = acGraphDestinatarios(
        (string)($notificacion['acn_bcc'] ?? '')
    );

    if (!$to) {
        throw new RuntimeException(
            'La notificación no tiene destinatarios TO válidos.'
        );
    }

    $payload = [
        'message' => [
            'subject' => (string)$notificacion['acn_asunto'],
            'body' => [
                'contentType' => 'HTML',
                'content' => (string)$notificacion['acn_cuerpo'],
            ],
            'toRecipients' => $to,
            'ccRecipients' => $cc,
            'bccRecipients' => $bcc,
        ],
        'saveToSentItems' => true,
    ];

    $url =
        'https://graph.microsoft.com/v1.0/users/'
        . rawurlencode($config['GRAPH_MAILBOX'])
        . '/sendMail';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false || $error !== '') {
        throw new RuntimeException(
            'Error de conexión con Graph: ' . $error
        );
    }

    // sendMail correctamente aceptado por Microsoft Graph.
    if ($http === 202) {
        return;
    }

    $data = json_decode((string)$response, true);

    $detalle = is_array($data)
        ? (
            $data['error']['code'] ?? ''
        ) . ' - ' . (
            $data['error']['message'] ?? 'Sin detalle'
        )
        : substr((string)$response, 0, 1000);

    throw new RuntimeException(
        'Graph HTTP ' . $http . ': ' . trim($detalle)
    );
}
