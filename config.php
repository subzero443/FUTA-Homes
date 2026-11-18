<?php
declare(strict_types=1);

$localConfigPath = __DIR__.'/config.local.php';
if (!is_file($localConfigPath)) {
    throw new RuntimeException('Database configuration is missing. Follow the secure database setup in README.md.');
}

$config = require $localConfigPath;
$required = ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'paystack_secret', 'app_name'];
if (!is_array($config)) {
    throw new RuntimeException('Local database configuration must return an array.');
}
foreach ($required as $key) {
    if (!isset($config[$key]) || !is_string($config[$key])) {
        throw new RuntimeException('Local database configuration is incomplete.');
    }
}
if ($config['db_host'] === '' || $config['db_port'] === '' || $config['db_name'] === '' ||
    $config['db_user'] === '' || $config['db_pass'] === '') {
    throw new RuntimeException('Database host, port, name, user, and password must be configured.');
}

return $config;
