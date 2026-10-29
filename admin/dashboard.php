<?php

require_once '../includes/functions.php';
requireAdmin();

$error = '';
$success = '';

$warehouseStatuses = ['approved', 'rejected'];
$deliveryStatuses = [
    'requested',
    'assigned',
    'picked_up',
    'in_transit',
    'delivered',
    'cancelled'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    if ($action === 'warehouse_status') {
        $warehouseId = sanitizePositiveInt($_POST['warehouse_id'] ?? null);
        $status = sanitizeAllowedStatus($_POST['status'] ?? '', $warehouseStatuses);

        if ($warehouseId !== null && $status !== null) {
            $stmt = $pdo->prepare(
                "UPDATE warehouses SET status = ?
                 WHERE id = ?"
            );

            $stmt->execute([$status, $warehouseId]);
            $success = 'Warehouse status updated.';
        } else {
            $error = 'Invalid warehouse update request.';
        }
    } elseif ($action === 'delivery_status') {
        $deliveryId = sanitizePositiveInt($_POST['delivery_id'] ?? null);
        $status = sanitizeAllowedStatus($_POST['status'] ?? '', $deliveryStatuses);

        if ($deliveryId !== null && $status !== null) {
            $stmt = $pdo->prepare(
                "UPDATE deliveries SET status = ?
                 WHERE id = ?"
            );

            $stmt->execute([$status, $deliveryId]);

            $stmt = $pdo->prepare(
                "SELECT user_id FROM deliveries WHERE id = ?"
            );
            $stmt->execute([$deliveryId]);
            $delivery = $stmt->fetch();

            if ($delivery) {
                notifyUser(
                    (int) $delivery['user_id'],
                    "Your delivery status has changed to: $status."
                );
            }

            $success = 'Delivery status updated.';
        } else {
            $error = 'Invalid delivery update request.';
        }
    }
}

$userCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users"
)->fetchColumn();

$warehouseCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM warehouses"
)->fetchColumn();

$approvedWarehouseCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM warehouses WHERE status = 'approved'"
)->fetchColumn();

$pendingWarehouseCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM warehouses WHERE status = 'pending'"
)->fetchColumn();

$bookingCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM warehouse_bookings"
)->fetchColumn();

$deliveryCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM deliveries"
)->fetchColumn();

$revenue = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE status = 'successful'"
)->fetchColumn();

// Chart Analytics Data Queries for Delivery Statuses
$deliveryStatusCounts = [];
foreach ($deliveryStatuses as $status) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM deliveries WHERE status = ?"
    );
    $stmt->execute([$status]);
    $deliveryStatusCounts[$status] = (int) $stmt->fetchColumn();
}

$warehouseApprovalBreakdown = [
    'approved' => $approvedWarehouseCount,
    'pending' => $pendingWarehouseCount,
    'bookings' => $bookingCount,
    'deliveries' => $deliveryCount,
];

$warehouses = $pdo->query(
    "SELECT * FROM warehouses
     ORDER BY created_at DESC"
)->fetchAll();

$deliveries = $pdo->query(
    "SELECT d.*, u.name AS customer_name
     FROM deliveries d
     JOIN users u ON u.id = d.user_id
     ORDER BY d.created_at DESC"
)->fetchAll();

// Additional Analytics / Audit Logs for Expanded Admin Overview
$stmtPayments = $pdo->query(
    "SELECT p.*, u.name AS user_name 
     FROM payments p 
     JOIN users u ON p.user_id = u.id 
     ORDER BY p.created_at DESC LIMIT 5"
);
$recentPayments = $stmtPayments->fetchAll();

$pageTitle = 'Admin Dashboard';
include '../includes/header.php';
include '../includes/sidebar.php';

?>

<!-- Include Chart.js via CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<main class="main-content">

    <h1>Administrator Dashboard & Analysis</h1>

    <p>Monitor LocalLink's users, financial metrics, and operational workflows.</p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card stat-success">
            <p>Total Platform Revenue</p>
            <h2><?= money($revenue) ?></h2>
        </div>

        <div class="stat-card stat-primary">
            <p>Registered Users</p>
            <h2><?= $userCount ?></h2>
        </div>

        <div class="stat-card stat-warning">
            <p>Warehouses</p>
            <h2><?= $warehouseCount ?> <small>(<?= $approvedWarehouseCount ?> approved, <?= $pendingWarehouseCount ?> pending)</small></h2>
        </div>

        <div class="stat-card stat-danger">
            <p>Bookings / Deliveries</p>
            <h2><?= $bookingCount ?> / <?= $deliveryCount ?></h2>
        </div>
    </div>

    <section class="panel analytics-panel">
        <h2>System Performance & Logistics Analytics</h2>
        <div class="analytics-grid">
            <div class="chart-card">
                <h3>Warehouse & Booking Distribution</h3>
                <div class="chart-wrap">
                    <canvas id="operationsChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <h3>Delivery Fulfillment Breakdown</h3>
                <div class="chart-wrap">
                    <canvas id="deliveryChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- Warehouse Approval Panel -->
    <section class="panel">

        <h2>Warehouse Approval</h2>

        <div class="table-wrapper">
            <table>

                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Available Units</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($warehouses as $warehouse): ?>

                    <tr>

                        <td><?= e($warehouse['name']) ?></td>

                        <td><?= e($warehouse['location']) ?></td>

                        <td>
                            <?= (int) $warehouse['available_units'] ?>
                        </td>

                        <td><?= renderStatusBadge($warehouse['status']) ?></td>

                        <td>

                            <?php if ($warehouse['status'] !== 'approved'): ?>

                                <form method="POST" class="inline-form">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= e(csrfToken()) ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="warehouse_status">

                                    <input type="hidden"
                                           name="warehouse_id"
                                           value="<?= (int) $warehouse['id'] ?>">

                                    <input type="hidden"
                                           name="status"
                                           value="approved">

                                    <button class="btn small primary">
                                        Approve
                                    </button>

                                </form>

                            <?php endif; ?>

                            <?php if ($warehouse['status'] !== 'rejected'): ?>

                                <form method="POST" class="inline-form">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= e(csrfToken()) ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="warehouse_status">

                                    <input type="hidden"
                                           name="warehouse_id"
                                           value="<?= (int) $warehouse['id'] ?>">

                                    <input type="hidden"
                                           name="status"
                                           value="rejected">

                                    <button class="btn small danger-btn">
                                        Reject
                                    </button>

                                </form>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>
        </div>

    </section>

    <!-- Delivery Coordination Panel -->
    <section class="panel">

        <h2>Delivery Coordination</h2>

        <div class="table-wrapper">
            <table>

                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Pickup</th>
                        <th>Destination</th>
                        <th>Status</th>
                        <th>Update Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($deliveries as $delivery): ?>

                    <tr>

                        <td><?= e($delivery['customer_name']) ?></td>

                        <td><?= e($delivery['pickup_location']) ?></td>

                        <td><?= e($delivery['destination']) ?></td>

                        <td><?= renderStatusBadge($delivery['status']) ?></td>

                        <td>

                            <form method="POST" class="inline-form">

                                <input type="hidden"
                                       name="csrf_token"
                                       value="<?= e(csrfToken()) ?>">

                                <input type="hidden"
                                       name="action"
                                       value="delivery_status">

                                <input type="hidden"
                                       name="delivery_id"
                                       value="<?= (int) $delivery['id'] ?>">

                                <select name="status" class="form-control compact-select">

                                    <?php foreach ([
                                        'requested',
                                        'assigned',
                                        'picked_up',
                                        'in_transit',
                                        'delivered',
                                        'cancelled'
                                    ] as $status): ?>

                                        <option value="<?= e($status) ?>"
                                            <?= $delivery['status'] === $status
                                                ? 'selected'
                                                : '' ?>>
                                            <?= e(ucwords(str_replace(
                                                '_',
                                                ' ',
                                                $status
                                            ))) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                                <button class="btn small primary">
                                    Update
                                </button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>
        </div>

    </section>

    <!-- Recent Platform Financial Transactions Audit Log -->
    <section class="panel">
        <h2>Recent Platform Transactions Audit Log</h2>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>User</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentPayments as $p): ?>
                        <tr>
                            <td><?= e($p['transaction_reference']) ?></td>
                            <td><?= e($p['user_name']) ?></td>
                            <td><?= money($p['amount']) ?></td>
                            <td><?= e($p['payment_method']) ?></td>
                            <td><?= renderStatusBadge($p['status']) ?></td>
                            <td><?= e($p['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentPayments)): ?>
                        <tr><td colspan="6">No recent transactions recorded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<script>
window.addEventListener('load', function () {
    const ctxOps = document.getElementById('operationsChart');
    if (ctxOps) {
        new Chart(ctxOps.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Approved', 'Pending', 'Bookings', 'Deliveries'],
                datasets: [{
                    label: 'System Totals',
                    data: [
                        <?= $warehouseApprovalBreakdown['approved'] ?>,
                        <?= $warehouseApprovalBreakdown['pending'] ?>,
                        <?= $warehouseApprovalBreakdown['bookings'] ?>,
                        <?= $warehouseApprovalBreakdown['deliveries'] ?>
                    ],
                    backgroundColor: ['#2ecc71', '#e67e22', '#3498db', '#9b59b6']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    const ctxDel = document.getElementById('deliveryChart');
    if (ctxDel) {
        new Chart(ctxDel.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Requested', 'Assigned', 'Picked Up', 'In Transit', 'Delivered', 'Cancelled'],
                datasets: [{
                    data: [
                        <?= $deliveryStatusCounts['requested'] ?>,
                        <?= $deliveryStatusCounts['assigned'] ?>,
                        <?= $deliveryStatusCounts['picked_up'] ?>,
                        <?= $deliveryStatusCounts['in_transit'] ?>,
                        <?= $deliveryStatusCounts['delivered'] ?>,
                        <?= $deliveryStatusCounts['cancelled'] ?>
                    ],
                    backgroundColor: ['#f39c12', '#3498db', '#f1c40f', '#9b59b6', '#2ecc71', '#e74c3c']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>