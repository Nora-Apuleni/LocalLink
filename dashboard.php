<?php

require_once 'includes/functions.php';
requireLogin();

$userId = currentUserId();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM warehouse_bookings
     WHERE user_id = ? AND status != 'cancelled'"
);
$stmt->execute([$userId]);
$bookingCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(quantity), 0)
     FROM products WHERE user_id = ?"
);
$stmt->execute([$userId]);
$totalStock = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM deliveries
     WHERE user_id = ? AND status NOT IN ('delivered','cancelled')"
);
$stmt->execute([$userId]);
$activeDeliveries = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE user_id = ? AND status = 'successful'"
);
$stmt->execute([$userId]);
$totalPayments = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM products
     WHERE user_id = ? AND quantity <= low_stock_threshold"
);
$stmt->execute([$userId]);
$lowStockCount = (int) $stmt->fetchColumn();

// Fetch AI Low-Stock Alerts details for smart banner messaging
$lowStockItems = getLowStockAlerts($pdo, $userId);

$stmt = $pdo->prepare(
    "SELECT id, message, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 5"
);
$stmt->execute([$userId]);
$recentNotifications = $stmt->fetchAll();

$pageTitle = 'Dashboard';
include 'includes/header.php';
include 'includes/sidebar.php';

?>

<main class="main-content">

    <div class="page-heading">
        <div>
            <h1>Welcome, <?= e($_SESSION['name']) ?>!</h1>
            <p>Here is an overview of your business operations.</p>
        </div>
    </div>

    <!-- AI Predictive Low-Stock Alert Banner -->
    <?php if (!empty($lowStockItems)): ?>
        <div class="alert warning predictive-alert">
            <strong> AI Predictive Alert: Low Stock Warning</strong>
            <p>
                The system projects stock exhaustion soon for:
                <?php 
                    $names = array_map(function($item) { return e($item['name'] ?? $item['product_name']) . " (" . $item['quantity'] . " left)"; }, $lowStockItems);
                    echo implode(', ', $names);
                ?>. Please replenish stock to avoid delivery delays.
            </p>
        </div>
    <?php endif; ?>

    <div class="stats-grid">

        <div class="stat-card">
            <p>Warehouse Bookings</p>
            <h2><?= $bookingCount ?></h2>
        </div>

        <div class="stat-card">
            <p>Inventory Units</p>
            <h2><?= $totalStock ?></h2>
        </div>

        <div class="stat-card">
            <p>Active Deliveries</p>
            <h2><?= $activeDeliveries ?></h2>
        </div>

        <div class="stat-card">
            <p>Total Successful Payments</p>
            <h2><?= money($totalPayments) ?></h2>
        </div>

    </div>

    <?php if ($lowStockCount > 0 && empty($lowStockItems)): ?>
        <div class="alert warning">
            You have <?= $lowStockCount ?>
            product(s) requiring stock attention.
            <a href="inventory/index.php">Review inventory</a>.
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Quick Actions</h2>

        <div class="action-grid">
            <a class="action-card"
               href="warehouses/index.php">
                <span>01</span>
                <h3>Find Storage</h3>
                <p>Explore warehouses and book space.</p>
            </a>

            <a class="action-card"
               href="inventory/index.php">
                <span>02</span>
                <h3>Manage Inventory</h3>
                <p>Update stock and monitor low inventory.</p>
            </a>

            <a class="action-card"
               href="deliveries/index.php">
                <span>03</span>
                <h3>Request Delivery</h3>
                <p>Arrange delivery between locations.</p>
            </a>

            <a class="action-card"
               href="payments/index.php">
                <span>04</span>
                <h3>Payments</h3>
                <p>View and simulate transactions.</p>
            </a>
        </div>
    </section>

    <section class="panel">
        <h2>Recent Notifications</h2>

        <?php if (!$recentNotifications): ?>
            <p>No notifications yet.</p>
        <?php else: ?>
            <?php foreach ($recentNotifications as $notification): ?>
                <div class="notification-item">
                    <p><?= e($notification['message']) ?></p>

                    <small>
                        <?= e($notification['created_at']) ?>
                    </small>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <a href="notifications/index.php">
            View all notifications
        </a>
    </section>

</main>

<?php include 'includes/footer.php'; ?>