<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$config = require __DIR__.'/config.php';
$drivers = PDO::getAvailableDrivers();
if (!in_array('mysql', $drivers, true)) {
    fwrite(STDERR, "MariaDB PDO driver is not loaded. Enable pdo_mysql in php.ini.\n");
    exit(1);
}

function assertTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function executeTest(PDO $db, string $sql, array $params = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

try {
    $db = new PDO(
        "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $db->exec("SET time_zone = '+01:00'");

    $requiredTables = [
        'users', 'agent_profiles', 'properties', 'property_media', 'favorites',
        'bookings', 'enquiries', 'agent_reviews', 'property_views', 'payments',
        'support_tickets', 'notifications',
        'conversations', 'direct_messages', 'property_questions', 'property_reviews',
    ];
    $tableQuery = $db->prepare(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE'"
    );
    $tableQuery->execute();
    $tables = $tableQuery->fetchAll(PDO::FETCH_COLUMN);
    assertTest(
        count(array_diff($requiredTables, $tables)) === 0,
        'Schema is incomplete; run database/futa_homes.sql before this test.'
    );
    echo "PASS: MariaDB connection and application schema\n";

    $db->beginTransaction();
    try {
        $baseId = -random_int(1_000_000, 2_000_000_000);
        $agentId = $baseId;
        $userId = $baseId - 1;
        $propertyId = $baseId - 2;
        $testEmail = 'mariadb-integration-'.bin2hex(random_bytes(6)).'@example.invalid';

        executeTest(
            $db,
            "INSERT INTO users (id,name,email,password_hash,role)
             VALUES (?, 'Integration Agent', ?, 'test-hash', 'agent')",
            [$agentId, $testEmail]
        );
        executeTest(
            $db,
            "INSERT INTO users (id,name,email,password_hash,role)
             VALUES (?, 'Integration User', ?, 'test-hash', 'user')",
            [$userId, 'user-'.$testEmail]
        );
        executeTest(
            $db,
            "INSERT INTO agent_profiles (user_id,business_name,verification_status,plan,plan_expires_at)
             VALUES (?, 'Integration Homes', 'approved', 'pro', CURRENT_TIMESTAMP+INTERVAL 1 MONTH)",
            [$agentId]
        );
        executeTest(
            $db,
            "INSERT INTO properties (id,agent_id,title,description,address,location,price,moderation_status)
             VALUES (?, ?, 'MariaDB integration listing', 'Test listing description',
                     '1 Test Street', 'Akure', 125000, 'approved')",
            [$propertyId, $agentId]
        );
        executeTest(
            $db,
            "INSERT INTO property_media (property_id,file_path,media_type)
             VALUES (?, '/media/integration.jpg', 'image')",
            [$propertyId]
        );
        executeTest($db, 'INSERT INTO favorites (user_id,property_id) VALUES (?,?)', [$userId, $propertyId]);
        executeTest(
            $db,
            "INSERT INTO bookings (property_id,user_id,agent_id,viewing_at,phone)
             VALUES (?,?,?,CURRENT_TIMESTAMP+INTERVAL 1 DAY,'07000000000')",
            [$propertyId, $userId, $agentId]
        );
        executeTest(
            $db,
            "INSERT INTO enquiries (reference,property_id,user_id,agent_id,message)
             VALUES (?,?,?,?, 'Integration test enquiry')",
            ['TEST-'.bin2hex(random_bytes(8)), $propertyId, $userId, $agentId]
        );
        executeTest(
            $db,
            "INSERT INTO agent_reviews (agent_id,user_id,rating,comment,status)
             VALUES (?,?,5,'Integration test review','approved')",
            [$agentId, $userId]
        );
        executeTest(
            $db,
            'INSERT INTO property_views (property_id,user_id) VALUES (?,?)',
            [$propertyId, $userId]
        );
        executeTest(
            $db,
            "INSERT INTO payments (agent_id,reference,plan,amount_kobo)
             VALUES (?,?,'pro',1000000)",
            [$agentId, 'TEST-'.bin2hex(random_bytes(8))]
        );
        executeTest(
            $db,
            "INSERT INTO support_tickets (user_id,subject,message)
             VALUES (?,'Integration test','Testing MariaDB persistence')",
            [$userId]
        );
        executeTest(
            $db,
            "INSERT INTO notifications (user_id,content) VALUES (?,'Integration test notice')",
            [$agentId]
        );
        $convStmt = executeTest(
            $db,
            "INSERT INTO conversations (property_id,user_id,agent_id,last_message) VALUES (?,?,?,'Hello agent')",
            [$propertyId, $userId, $agentId]
        );
        $convId = (int)$db->lastInsertId();
        executeTest(
            $db,
            "INSERT INTO direct_messages (conversation_id,sender_id,message) VALUES (?,?,'Hello agent')",
            [$convId, $userId]
        );
        executeTest(
            $db,
            "INSERT INTO property_questions (property_id,user_id,question,answer,answered_by) VALUES (?,?,'Is borehole water constant?','Yes, daily.',?)",
            [$propertyId, $userId, $agentId]
        );
        executeTest(
            $db,
            "INSERT INTO property_reviews (property_id,user_id,rating,comment,category_water,category_power,category_security) VALUES (?,?,5,'Great place!','Constant','Stable','Very safe')",
            [$propertyId, $userId]
        );

        $listing = executeTest(
            $db,
            "SELECT p.id, m.file_path,
                    (SELECT COUNT(*) FROM property_views WHERE property_id=p.id) AS views
             FROM properties p
             JOIN users u ON u.id=p.agent_id
             JOIN agent_profiles a ON a.user_id=u.id
             LEFT JOIN property_media m ON m.property_id=p.id
             WHERE LOWER(p.title) LIKE LOWER(?) AND p.moderation_status='approved'
               AND p.availability='available' AND u.is_suspended=FALSE
               AND a.plan_expires_at>CURRENT_TIMESTAMP
             ORDER BY CASE WHEN a.plan IN ('pro','premium') THEN 0 ELSE 1 END,
                      p.created_at DESC
             LIMIT 1",
            ['%MARIADB INTEGRATION%']
        )->fetch();
        assertTest($listing && (int)$listing['id'] === $propertyId, 'Listing search/join query failed.');
        assertTest((int)$listing['views'] === 1, 'Property view count query failed.');

        $summary = executeTest(
            $db,
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending
             FROM bookings WHERE agent_id=?",
            [$agentId]
        )->fetch();
        assertTest((int)$summary['total'] === 1 && (int)$summary['pending'] === 1, 'Dashboard aggregate query failed.');

        $daily = executeTest(
            $db,
            "SELECT DATE(viewed_at) AS day,COUNT(*) AS total
             FROM property_views
             WHERE property_id=? AND viewed_at>=CURRENT_TIMESTAMP-INTERVAL 30 DAY
             GROUP BY DATE(viewed_at)",
            [$propertyId]
        )->fetch();
        assertTest($daily && (int)$daily['total'] === 1, 'Analytics date/group query failed.');

        executeTest($db, 'UPDATE notifications SET is_read=TRUE WHERE user_id=?', [$agentId]);
        $read = executeTest($db, 'SELECT is_read FROM notifications WHERE user_id=?', [$agentId])->fetchColumn();
        assertTest(in_array($read, [true, 1, '1'], true), 'MariaDB boolean update/read failed.');
        echo "PASS: CRUD, explicit-ID inserts, joins, case-insensitive search, aggregates, date arithmetic, and booleans\n";
    } finally {
        $db->rollBack();
    }

    echo "PASS: Test data rolled back; database left unchanged\n";
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "FAIL: ".$error->getMessage()."\n");
    exit(1);
}
