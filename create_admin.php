<?php

require_once 'includes/functions.php';

$adminName = 'LocalLink Administrator';
$adminEmail = 'admin@locallink.local';
$adminPassword = 'ChangeThisAdminPassword123!';

if (!isStrongPassword($adminPassword)) {
    exit('Administrator password is too weak for this setup.');
}

$stmt = $pdo->prepare(
    "SELECT id FROM users WHERE email = ?"
);

$stmt->execute([$adminEmail]);

if ($stmt->fetch()) {
    exit('Administrator account already exists.');
}

$stmt = $pdo->prepare(
    "INSERT INTO users (name, email, password, role)
     VALUES (?, ?, ?, 'admin')"
);

$stmt->execute([
    $adminName,
    $adminEmail,
    password_hash($adminPassword, PASSWORD_DEFAULT)
]);

echo 'Administrator created successfully.<br>';
echo 'Email: ' . e($adminEmail) . '<br>';
echo 'Password: ' . e($adminPassword) . '<br>';
echo 'Delete create_admin.php immediately after use.';