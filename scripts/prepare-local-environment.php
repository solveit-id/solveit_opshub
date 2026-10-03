<?php

use Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$environmentPath = dirname(__DIR__).'/.env';

if (! file_exists($environmentPath)) {
    copy(dirname(__DIR__).'/.env.example', $environmentPath);
}

$contents = file_get_contents($environmentPath);
$values = Dotenv::parse($contents);

if (($values['APP_ENV'] ?? 'local') !== 'local'
    || ($values['DB_CONNECTION'] ?? 'mysql') !== 'mysql'
    || ! in_array($values['DB_HOST'] ?? '127.0.0.1', ['127.0.0.1', 'localhost'], true)
    || ! empty($values['DB_URL'])
    || ($values['DB_USERNAME'] ?? 'opshub') === 'root') {
    throw new RuntimeException('composer db:up requires local MySQL settings, a non-root account, and no DB_URL.');
}

foreach (['APP_KEY', 'DB_PASSWORD', 'MYSQL_ROOT_PASSWORD'] as $key) {
    if (! empty($values[$key])) {
        continue;
    }

    $value = $key === 'APP_KEY' ? 'base64:'.base64_encode(random_bytes(32)) : bin2hex(random_bytes(24));
    $line = $key.'='.$value;

    if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents)) {
        $contents = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
    } else {
        $contents = rtrim($contents)."\n".$line."\n";
    }
}

file_put_contents($environmentPath, $contents, LOCK_EX);

echo "Local MySQL environment prepared; existing application key and credentials preserved.\n";
