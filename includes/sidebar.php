<?php

$basePath = '/locallink';
$role = currentUserRole();

?>

<aside class="sidebar">
    <p class="sidebar-label">MAIN MENU</p>

    <a href="<?= $basePath ?>/dashboard.php">
        Dashboard
    </a>

    <a href="<?= $basePath ?>/warehouses/index.php">
        Warehouses
    </a>

    <a href="<?= $basePath ?>/inventory/index.php">
        Inventory
    </a>

    <a href="<?= $basePath ?>/deliveries/index.php">
        Deliveries
    </a>

    <a href="<?= $basePath ?>/payments/index.php">
        Payments
    </a>

    <a href="<?= $basePath ?>/notifications/index.php">
        Notifications
    </a>

    <?php if ($role === 'warehouse_manager' || $role === 'admin'): ?>
        <p class="sidebar-label">WAREHOUSE MANAGEMENT</p>

        <a href="<?= $basePath ?>/warehouses/manager_dashboard.php">
            Manager Dashboard
        </a>
    <?php endif; ?>

    <?php if ($role === 'delivery_provider' || $role === 'admin'): ?>
        <p class="sidebar-label">LOGISTICS</p>

        <a href="<?= $basePath ?>/deliveries/provider_dashboard.php">
            Assigned Deliveries
        </a>
    <?php endif; ?>

    <?php if ($role === 'admin'): ?>
        <p class="sidebar-label">ADMINISTRATION</p>

        <a href="<?= $basePath ?>/admin/dashboard.php">
            Admin Dashboard
        </a>
    <?php endif; ?>
</aside>