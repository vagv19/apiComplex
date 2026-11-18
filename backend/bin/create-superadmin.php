<?php

declare(strict_types=1);

use App\Config\Database;

require_once __DIR__ . '/../src/Helpers/functions.php';
require_once __DIR__ . '/../src/Config/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde CLI.\n");
    exit(1);
}

loadEnvFile(dirname(__DIR__) . '/.env');
$email = filter_var($argv[1] ?? '', FILTER_VALIDATE_EMAIL);
$name = trim((string) ($argv[2] ?? ''));
$password = getenv('SUPERADMIN_BOOTSTRAP_PASSWORD');
if (
    $email === false
    || strlen((string) $email) > 150
    || $name === ''
    || strlen($name) > 120
    || !is_string($password)
    || strlen($password) < 12
) {
    fwrite(STDERR, "Uso: php bin/create-superadmin.php correo nombre\n");
    fwrite(STDERR, "Define SUPERADMIN_BOOTSTRAP_PASSWORD en el entorno (mínimo 12 caracteres).\n");
    exit(1);
}

try {
    $pdo = Database::getConnection();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('El bootstrap de producción requiere MySQL.');
    }
    if ((int) $pdo->query("SELECT GET_LOCK('football_tournament_superadmin_bootstrap', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('No se pudo obtener el bloqueo para el bootstrap.');
    }
    $pdo->beginTransaction();
    $count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND is_active = 1")->fetchColumn();
    if ((int) $count > 0) {
        throw new RuntimeException('Ya existe una cuenta superadmin activa.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, is_active, auth_version)
         VALUES (:name, :email, :password_hash, :role, 1, 0)',
    );
    $insert->execute([
        ':name' => $name,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':role' => 'superadmin',
    ]);
    $pdo->commit();
    $pdo->query("SELECT RELEASE_LOCK('football_tournament_superadmin_bootstrap')");
    fwrite(STDOUT, "Cuenta superadmin creada.\n");
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($pdo) && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->query("SELECT RELEASE_LOCK('football_tournament_superadmin_bootstrap')");
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    putenv('SUPERADMIN_BOOTSTRAP_PASSWORD');
}
