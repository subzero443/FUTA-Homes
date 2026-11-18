<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$adminConfigPath = __DIR__.'/config.admin.local.php';
$localConfigPath = __DIR__.'/config.local.php';

if (is_file($localConfigPath)) {
    try {
        $config = require __DIR__.'/config.php';
        $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4";
        $db = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->query('SELECT 1')->fetchColumn();

        $requiredTables = [
            'users', 'agent_profiles', 'properties', 'property_media', 'favorites',
            'bookings', 'enquiries', 'agent_reviews', 'property_views', 'payments',
            'support_tickets', 'notifications',
            'conversations', 'direct_messages', 'property_questions', 'property_reviews',
        ];
        $tableQuery = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type=\'BASE TABLE\' AND table_name=?'
        );
        foreach ($requiredTables as $table) {
            $tableQuery->execute([$table]);
            if ((int)$tableQuery->fetchColumn() !== 1) {
                throw new RuntimeException("Required table is missing: {$table}");
            }
        }
    } catch (Throwable $error) {
        error_log('FUTA Homes database setup verification: '.$error->getMessage());
        fwrite(STDERR, "An application config already exists, but its database connection or schema check failed. Check that MariaDB is running and verify the setup with backend/database-test.php. Existing credentials were not changed.\n");
        exit(1);
    }

    echo "FUTA Homes is already configured. The application database connection and required tables were verified; no credentials or database users were changed.\n";
    exit(0);
}

if (!is_file($adminConfigPath)) {
    fwrite(STDERR, "Create backend/config.admin.local.php with temporary database administrator credentials, then rerun this script.\n");
    exit(1);
}

$admin = require $adminConfigPath;
$user = 'futa_homes_app';
$host = '127.0.0.1';

try {
    $dsn = "mysql:host={$admin['db_host']};port={$admin['db_port']};dbname={$admin['db_name']};charset=utf8mb4";
    $db = new PDO($dsn, $admin['db_user'], $admin['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $exists = $db->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host=?');
    $exists->execute([$user, $host]);
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('The dedicated application database account already exists; inspect it before changing credentials.');
    }

    $password = bin2hex(random_bytes(32));
    $db->exec("CREATE USER '{$user}'@'{$host}' IDENTIFIED BY ".$db->quote($password));
    try {
        $db->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `futa_homes`.* TO '{$user}'@'{$host}'");
        $appConfig = [
            'db_host' => '127.0.0.1',
            'db_port' => (string)$admin['db_port'],
            'db_name' => 'futa_homes',
            'db_user' => $user,
            'db_pass' => $password,
            'paystack_secret' => (string)($admin['paystack_secret'] ?? ''),
            'app_name' => (string)($admin['app_name'] ?? 'FUTA Homes'),
        ];
        $contents = "<?php\ndeclare(strict_types=1);\n\nreturn ".var_export($appConfig, true).";\n";
        if (file_put_contents($localConfigPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Could not write backend/config.local.php.');
        }
    } catch (Throwable $error) {
        $db->exec("DROP USER '{$user}'@'{$host}'");
        throw $error;
    }
} catch (Throwable $error) {
    error_log('FUTA Homes database account setup: '.$error->getMessage());
    fwrite(STDERR, "Application database setup failed. Verify the administrator configuration and database privileges.\n");
    exit(1);
}

echo "Created the least-privilege application account and backend/config.local.php. Keep that file private.\n";
