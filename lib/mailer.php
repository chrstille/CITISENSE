<?php
/*
 * Small dependency-free SMTP mailer for CitiSense.
 * Supports STARTTLS (port 587), SSL (port 465) and AUTH LOGIN,
 * which covers Gmail, Outlook and most hosting providers.
 *
 * Usage:
 *   [$ok, $error] = citisenseSendMail($to, $toName, $subject, $html, $text);
 */

function citisenseMailConfig(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . "/../config/mail.php";
    }

    return $config;
}

function citisenseSendMail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    $config = citisenseMailConfig();

    $fromEmail = $config["from_email"] ?: $config["username"];
    $fromName = $config["from_name"] ?: "CitiSense";

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, "Invalid recipient address."];
    }

    $boundary = "cs_" . bin2hex(random_bytes(12));

    $headers = [
        "Date: " . date("r"),
        "From: " . citisenseEncodeHeader($fromName) . " <" . $fromEmail . ">",
        "To: " . citisenseEncodeHeader($toName) . " <" . $toEmail . ">",
        "Subject: " . citisenseEncodeHeader($subject),
        "Message-ID: <" . bin2hex(random_bytes(16)) . "@" . (explode("@", $fromEmail)[1] ?? "citisense.local") . ">",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"",
    ];

    $body =
        "--" . $boundary . "\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        chunk_split(base64_encode($textBody)) .
        "--" . $boundary . "\r\n" .
        "Content-Type: text/html; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: base64\r\n\r\n" .
        chunk_split(base64_encode($htmlBody)) .
        "--" . $boundary . "--\r\n";

    /* No SMTP host configured: use PHP mail() */
    if (trim((string) $config["host"]) === "") {
        $mailHeaders = array_filter($headers, static function ($line) {
            return stripos($line, "To:") !== 0 && stripos($line, "Subject:") !== 0;
        });

        $sent = @mail(
            $toEmail,
            citisenseEncodeHeader($subject),
            $body,
            implode("\r\n", $mailHeaders)
        );

        return $sent
            ? [true, ""]
            : [false, "PHP mail() failed. Configure SMTP in config/mail.php."];
    }

    if ($config["username"] === "" || $config["password"] === "") {
        return [false, "SMTP username/password are not set in config/mail.php."];
    }

    try {
        citisenseSmtpSend(
            $config,
            $fromEmail,
            $toEmail,
            implode("\r\n", $headers) . "\r\n\r\n" . $body
        );
    } catch (Throwable $error) {
        return [false, $error->getMessage()];
    }

    return [true, ""];
}

function citisenseEncodeHeader(string $value): string
{
    return preg_match('/[^\x20-\x7E]/', $value)
        ? "=?UTF-8?B?" . base64_encode($value) . "?="
        : $value;
}

function citisenseSmtpSend(
    array $config,
    string $from,
    string $to,
    string $message
): void {
    $host = $config["host"];
    $port = (int) $config["port"];
    $encryption = strtolower((string) $config["encryption"]);

    $remote = ($encryption === "ssl" ? "ssl://" : "tcp://") . $host . ":" . $port;

    $context = stream_context_create([
        "ssl" => [
            "verify_peer" => true,
            "verify_peer_name" => true,
        ],
    ]);

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException("Could not connect to SMTP server: " . $errstr);
    }

    stream_set_timeout($socket, 15);

    $read = static function () use ($socket): string {
        $response = "";

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            // Last line of a reply has a space after the code
            if (isset($line[3]) && $line[3] === " ") {
                break;
            }
        }

        return $response;
    };

    $command = static function (
    string $line,
    array $expect,
    bool $sensitive = false
    ) use ($socket, $read): string {

        fwrite($socket, $line . "\r\n");
        $response = $read();
        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expect, true)) {
            $safeLine = $sensitive || stripos($line, "AUTH") === 0 || strlen($line) > 60
            ? "[hidden]"
            : $line;

            throw new RuntimeException(
                "SMTP error after " . $safeLine . ": " . trim($response)
            );
        }

        return $response;
    };

    $greeting = $read();

    if ((int) substr($greeting, 0, 3) !== 220) {
        throw new RuntimeException("Unexpected SMTP greeting: " . trim($greeting));
    }

    $hostname = gethostname() ?: "localhost";

    $command("EHLO " . $hostname, [250]);

    if ($encryption === "tls") {
        $command("STARTTLS", [220]);

        $cryptoOk = stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );

        if (!$cryptoOk) {
            throw new RuntimeException("Could not start TLS encryption.");
        }

        $command("EHLO " . $hostname, [250]);
    }

    $command("AUTH LOGIN", [334]);
    $command(base64_encode($config["username"]), [334], true);
    $command(base64_encode($config["password"]), [235], true);

    $command("MAIL FROM:<" . $from . ">", [250]);
    $command("RCPT TO:<" . $to . ">", [250, 251]);
    $command("DATA", [354]);

    // Dot-stuffing: lines that begin with "." must be doubled
    $message = preg_replace('/^\./m', '..', $message);

    $command($message . "\r\n.", [250]);
    $command("QUIT", [221]);

    fclose($socket);
}
