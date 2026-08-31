<?php
declare(strict_types=1);

/**
 * Minimal SMTP client for Bar Tadeo password-recovery email.
 *
 * Secrets are read from public/includes/config.local.php through constants.
 * Nothing sensitive is stored in this tracked file.
 */

function smtp_config_value(string $name, string $default = ''): string
{
    if (!defined($name)) {
        return $default;
    }

    return trim((string)constant($name));
}

function smtp_port(): int
{
    if (!defined('SMTP_PORT')) {
        return 587;
    }

    $port = (int)constant('SMTP_PORT');

    return $port > 0 ? $port : 587;
}

function smtp_is_configured(): bool
{
    return smtp_config_value('SMTP_HOST', 'smtp.gmail.com') !== ''
        && smtp_config_value('SMTP_USERNAME') !== ''
        && smtp_config_value('SMTP_PASSWORD') !== '';
}

function smtp_read_response($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTP serveri nuk dha përgjigje.');
    }

    return $response;
}

function smtp_response_code(string $response): int
{
    return (int)substr($response, 0, 3);
}

function smtp_expect(string $response, array $allowedCodes): void
{
    $code = smtp_response_code($response);

    if (!in_array($code, $allowedCodes, true)) {
        throw new RuntimeException('SMTP serveri refuzoi kërkesën me kodin ' . $code . '.');
    }
}

function smtp_write_line($socket, string $line): void
{
    $written = fwrite($socket, $line . "\r\n");

    if ($written === false) {
        throw new RuntimeException('Nuk u dërgua dot komanda SMTP.');
    }
}

function smtp_command($socket, string $command, array $allowedCodes): string
{
    smtp_write_line($socket, $command);
    $response = smtp_read_response($socket);
    smtp_expect($response, $allowedCodes);

    return $response;
}

function smtp_encoded_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_normalize_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);

    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);

    return implode("\r\n", $lines);
}

function smtp_send_text_email(string $recipient, string $subject, string $body): void
{
    $recipient = trim($recipient);

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Adresa e email-it marrës nuk është e vlefshme.');
    }

    if (!smtp_is_configured()) {
        throw new RuntimeException('SMTP nuk është konfiguruar ende.');
    }

    $host = smtp_config_value('SMTP_HOST', 'smtp.gmail.com');
    $port = smtp_port();
    $username = smtp_config_value('SMTP_USERNAME');
    $password = str_replace(' ', '', smtp_config_value('SMTP_PASSWORD'));
    $fromEmail = smtp_config_value('SMTP_FROM_EMAIL', $username);
    $fromName = smtp_config_value('SMTP_FROM_NAME', 'Bar Tadeo');

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP sender email nuk është i vlefshëm.');
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        throw new RuntimeException('Nuk u lidh dot me SMTP serverin.');
    }

    stream_set_timeout($socket, 15);

    try {
        smtp_expect(smtp_read_response($socket), [220]);
        smtp_command($socket, 'EHLO tadeobar.gt.tc', [250]);
        smtp_command($socket, 'STARTTLS', [220]);

        $cryptoEnabled = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($cryptoEnabled !== true) {
            throw new RuntimeException('Lidhja TLS me SMTP serverin dështoi.');
        }

        smtp_command($socket, 'EHLO tadeobar.gt.tc', [250]);
        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($username), [334]);
        smtp_command($socket, base64_encode($password), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'From: ' . smtp_encoded_header($fromName) . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . smtp_encoded_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@tadeobar.gt.tc>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $payload = implode("\r\n", $headers)
            . "\r\n\r\n"
            . smtp_normalize_body($body)
            . "\r\n.\r\n";

        if (fwrite($socket, $payload) === false) {
            throw new RuntimeException('Email-i nuk u dërgua dot te SMTP serveri.');
        }

        smtp_expect(smtp_read_response($socket), [250]);

        try {
            smtp_command($socket, 'QUIT', [221]);
        } catch (Throwable $e) {
            // Message was already accepted; QUIT failure is not fatal.
        }
    } finally {
        fclose($socket);
    }
}
