
<?php

/*
 * CitiSense SMTP configuration
 * Credentials are read from environment variables.
 */

$gmailAddress = trim(
    getenv("CITISENSE_SMTP_USER") ?: ""
);

$appPassword = preg_replace(
    '/\s+/',
    '',
    getenv("CITISENSE_SMTP_PASSWORD") ?: ""
);

return [
    "host"       => "smtp.gmail.com",
    "port"       => 587,
    "encryption" => "tls",

    "username"   => $gmailAddress,
    "password"   => $appPassword,

    "from_email" => $gmailAddress,
    "from_name"  => "CitiSense Admin",

    "app_url"    => "http://localhost:8000/admin",
    "debug"      => false
];
