<?php

require_once '../includes/functions.php';
requireRole(['warehouse_manager', 'admin']);

$userId = currentUserId();
$role = currentUserRole();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $action = $_POST['action'];

    // Handle Booking Confirm / Reject Actions
    if (in_array($action, ['confirm', 'reject'], true)) {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $newStatus = ($action === 'confirm') ? 'confirmed' : 'rejected';

        // Security check: Ensure the booking belongs to a warehouse managed by this user (unless admin)
        $checkSql = "SELECT wb.id FROM warehouse_bookings wb 
                     JOIN warehouses w ON wb.warehouse_id = w.id 
                     WHERE wb.id = ?";
        $params = [$bookingId];

        if ($role !== 'admin') {
            $checkSql .= " AND w.user_id = ?";
            $params[] = $userId;
        }

        $stmtCheck = $pdo->prepare($checkSql);
        $stmtCheck->execute($params);

        if ($stmtCheck->fetch()) {
            $stmt = $pdo->prepare("UPDATE warehouse_bookings SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $bookingId]);

            if ($stmt->rowCount() > 0) {
                $message = "Booking #{$bookingId} has been {$newStatus}.";
            } else {
                $error = "Unable to update booking status.";
            }
        } else {
            $error = "Unauthorized action or invalid booking record.";
        }
    }
}

// Fetch warehouses scoped to the current user (or all if admin)
$whSql = "SELECT * FROM warehouses";
$whParams = [];
if ($role !== 'admin') {
    $whSql .= " WHERE user_id = ?";
    $whParams[] = $userId;
}
$whSql .= " ORDER BY id DESC";

$stmtWh = $pdo->prepare($whSql);
$stmtWh->execute($whParams);
$myWarehouses = $stmtWh->fetchAll();

// Fetch incoming bookings scoped to the current user's warehouses (or all if admin)
$bookingSql = "SELECT wb.id, wb.units_booked, wb.start_date, wb.end_date, wb.status,
                     COALESCE(NULLIF(wb.total_price, 0), wb.total_cost, 0) AS total_price,
                     w.name AS warehouse_name, u.name AS client_name, u.email AS client_email
               FROM warehouse_bookings wb
               JOIN warehouses w ON wb.warehouse_id = w.id
               JOIN users u ON wb.user_id = u.id";

$bookingParams = [];
if ($role !== 'admin') {
    $bookingSql .= " WHERE w.user_id = ?";
    $bookingParams[] = $userId;
}
$bookingSql .= " ORDER BY wb.created_at DESC";

$stmtBookings = $pdo->prepare($bookingSql);
$stmtBookings->execute($bookingParams);
$incomingBookings = $stmtBookings->fetchAll();

$pageTitle = 'Warehouse Manager Dashboard';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main-content">

    <h1>Warehouse Manager Dashboard</h1>
    <p>Manage your facilities, review capacity, and approve incoming booking requests.</p>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="section-header">
            <h2>Storage Facilities Directory</h2>
            <a href="add.php" class="btn primary">+ Add New Warehouse</a>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Facility Name</th>
                        <th>Total Capacity</th>
                        <th>Available Capacity</th>
                        <th>Rate / Unit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myWarehouses as $wh): ?>
                        <tr>
                            <td><?= e($wh['name']) ?></td>
                            <td><?= e($wh['total_units'] ?? $wh['capacity'] ?? 100) ?> units</td>
                            <td><?= e($wh['available_units'] ?? 50) ?> units</td>
                            <td><?= money($wh['price_per_unit'] ?? $wh['price'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($myWarehouses)): ?>
                        <tr><td colspan="4">No registered warehouses found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Booking Requests</h2>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Warehouse</th>
                        <th>Client</th>
                        <th>Units</th>
                        <th>Dates</th>
                        <th>Total Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incomingBookings as $b): ?>
                        <tr>
                            <td>#<?= e($b['id']) ?></td>
                            <td><?= e($b['warehouse_name']) ?></td>
                            <td><?= e($b['client_name']) ?><br><small><?= e($b['client_email']) ?></small></td>
                            <td><?= e($b['units_booked'] ?? 1) ?></td>
                            <td><?= e($b['start_date'] ?? 'N/A') ?> to <?= e($b['end_date'] ?? 'N/A') ?></td>
                            <td><?= money($b['total_price'] ?? 0) ?></td>
                            <td><?= renderStatusBadge($b['status']) ?></td>
                            <td>
                                <?php if ($b['status'] === 'pending'): ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="booking_id" value="<?= e($b['id']) ?>">
                                        <button name="action" value="confirm" class="btn small primary">Confirm</button>
                                        <button name="action" value="reject" class="btn small danger-btn">Reject</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">No actions</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($incomingBookings)): ?>
                        <tr><td colspan="8">No booking requests available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<?php include '../includes/footer.php'; ?>