<?php

$pageTitle = $pageTitle ?? 'LocalLink';
$loggedIn = isset($_SESSION['user_id']);

$basePath = '/locallink';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> | LocalLink</title>

    <link rel="stylesheet"
          href="<?= $basePath ?>/assets/css/style.css">
</head>

<body>

<header class="topbar">
    <a href="<?= $basePath ?>/index.php" class="brand">
        Local<span>Link</span>
    </a>

    <div class="topbar-right">
        <?php if ($loggedIn): ?>
            <span class="user-name">
                <?= e($_SESSION['name']) ?>
            </span>

            <a href="<?= $basePath ?>/logout.php"
               class="logout-link">
                Log Out
            </a>
        <?php else: ?>
            <a href="<?= $basePath ?>/login.php">Login</a>
            <a href="<?= $basePath ?>/register.php"
               class="btn small primary">
                Get Started
            </a>
        <?php endif; ?>
    </div>
</header>

<div class="app-layout">