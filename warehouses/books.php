<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();
$warehouseId = (int)($_GET['id'] ?? $_POST['warehouse_id'] ?? 0);
$error = '';
$message = '';

$stmt = $pdo->prepare("SELECT * FROM warehouses WHERE id = ?");
$stmt->execute([$warehouseId]);
$warehouse = $stmt->fetch();

if (!$warehouse) {
    redirect('/locallink/warehouses/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $unitsRequested = (int)($_POST['units_booked'] ?? 0);
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';

    if ($unitsRequested <= 0 || empty($startDate) || empty($endDate)) {
        $error = 'Please provide valid units and booking dates.';
    } elseif ($startDate > $endDate) {
        $error = 'Start date cannot be after end date.';
    } else {
        try {
            $pdo->beginTransaction();

            // Enforce Server-Side Capacity & Date Overlap Check with row locking
            $lockStmt = $pdo->prepare("SELECT * FROM warehouses WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$warehouseId]);
            
            $isAvailable = isWarehouseCapacityAvailable($pdo, $warehouseId, $unitsRequested, $startDate, $endDate);

            if (!$isAvailable) {
                throw new RuntimeException('Capacity error: The requested storage units are unavailable for the selected dates due to existing bookings.');
            }

            // Calculate total price accurately: Days * Units * Price Per Unit
            $days = max(1, (strtotime($endDate) - strtotime($startDate)) / 86400);
            $pricePerUnit = (float)($warehouse['price_per_unit'] ?? 10);
            $totalPrice = $days * $unitsRequested * $pricePerUnit;

            $stmtBook = $pdo->prepare(
                "INSERT INTO warehouse_bookings (user_id, warehouse_id, units_booked, start_date, end_date, total_price, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmtBook->execute([$userId, $warehouseId, $unitsRequested, $startDate, $endDate, $totalPrice]);

            // --- Automatic Random Driver Assignment ---
            $driverStmt = $pdo->query("SELECT id FROM users WHERE role = 'driver' OR role = 'delivery_provider'");
            $drivers = $driverStmt->fetchAll(PDO::FETCH_COLUMN);

            $assignedProviderId = null;
            if (!empty($drivers)) {
                $assignedProviderId = $drivers[array_rand($drivers)];
            }
            $deliveryStatus = $assignedProviderId ? 'assigned' : 'requested';

            $insertDelivery = $pdo->prepare("
                INSERT INTO deliveries (user_id, provider_id, pickup_address, dropoff_address, status, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $insertDelivery->execute([
                $userId,
                $assignedProviderId,
                $warehouse['name'] . ' (' . ($warehouse['location'] ?? 'Lusaka') . ')',
                'Client Delivery Destination',
                $deliveryStatus
            ]);

            if ($assignedProviderId && function_exists('notifyUser')) {
                notifyUser(
                    $assignedProviderId, 
                    "A new delivery has been automatically assigned to your dashboard."
                );
            }
            // ------------------------------------------

            $pdo->commit();

            $message = 'Booking request submitted and delivery dispatched successfully! Your booking is pending approval.';

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to process your booking. Please try again.';

            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    }
}

$pageTitle = 'Book Storage Space';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main-content">
    <h1>Book Storage Space</h1>
    <p>Reserve capacity at <strong><?= e($warehouse['name']) ?></strong></p>

    <?php if ($message): ?>
        <div class="alert success"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel booking-panel">
        <p><strong>Location:</strong> <?= e($warehouse['location'] ?? 'Lusaka') ?></p>
        <p><strong>Rate per unit/day:</strong> <?= money($warehouse['price_per_unit'] ?? 10) ?></p>
        <p><strong>Total Capacity:</strong> <?= (int)($warehouse['capacity'] ?? $warehouse['total_units'] ?? 100) ?> units</p>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="warehouse_id" value="<?= e($warehouseId) ?>">

            <div class="form-group">
                <label>Units Required</label>
                <input type="number" name="units_booked" min="1" value="10" required class="form-control">
            </div>

            <div class="form-group">
                <label>Start Date</label>
                <input type="date" name="start_date" min="<?= date('Y-m-d') ?>" required class="form-control">
            </div>

            <div class="form-group">
                <label>End Date</label>
                <input type="date" name="end_date" min="<?= date('Y-m-d') ?>" required class="form-control">
            </div>

            <button type="submit" class="btn primary full-width">Submit Booking Request</button>
        </form>
    </section>
</main>

<?php include '../includes/footer.php'; ?>