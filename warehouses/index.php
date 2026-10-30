<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    if ($action === 'book') {
        $warehouseId = sanitizePositiveInt($_POST['warehouse_id'] ?? null);
        $units = sanitizePositiveInt($_POST['units'] ?? null);
        $startDate = sanitizeDateString($_POST['start_date'] ?? '');
        $endDate = sanitizeDateString($_POST['end_date'] ?? '');

        $start = $startDate ? new DateTimeImmutable($startDate) : null;
        $end = $endDate ? new DateTimeImmutable($endDate) : null;

        $validDates = $start
            && $end
            && $end > $start
            && $start >= new DateTimeImmutable('today');

        if (
            $warehouseId === null ||
            $units === null ||
            $units < 1 ||
            !$validDates
        ) {
            $error = 'Enter valid storage units and dates.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "SELECT * FROM warehouses
                     WHERE id = ? AND status = 'approved'
                     FOR UPDATE"
                );

                $stmt->execute([$warehouseId]);
                $warehouse = $stmt->fetch();

                if (
                    !$warehouse ||
                    $units > (int) $warehouse['available_units']
                ) {
                    throw new RuntimeException(
                        'The warehouse does not have enough available units.'
                    );
                }

                // Enforce Server-Side Overlap & Capacity Check
                if (function_exists('isWarehouseCapacityAvailable')) {
                    $isAvailable = isWarehouseCapacityAvailable($pdo, $warehouseId, $units, $startDate, $endDate);
                    if (!$isAvailable) {
                        throw new RuntimeException(
                            'Capacity error: The requested storage units are unavailable for the selected dates due to overlapping bookings.'
                        );
                    }
                }

                $days = max(1, (int) $start->diff($end)->days);

                $totalCost =
                    (float) $warehouse['price_per_unit']
                    * $units
                    * $days;

                $insert = $pdo->prepare(
                    "INSERT INTO warehouse_bookings
                    (user_id, warehouse_id, units_booked,
                     start_date, end_date, total_price, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                );

                $insert->execute([
                    $userId,
                    $warehouseId,
                    $units,
                    $startDate,
                    $endDate,
                    $totalCost
                ]);

                // --- Automatic Random Driver Assignment ---
                $driverStmt = $pdo->query("SELECT id FROM users WHERE role = 'driver' OR role = 'delivery_provider'");
                $drivers = $driverStmt->fetchAll(PDO::FETCH_COLUMN);

                $assignedProviderId = null;
                if (!empty($drivers)) {
                    $assignedProviderId = $drivers[array_rand($drivers)];
                }
                $deliveryStatus = $assignedProviderId ? 'assigned' : 'requested';

                $insertDelivery = $pdo->prepare("
                    INSERT INTO deliveries (user_id, provider_id, pickup_location, destination, status, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $insertDelivery->execute([
                    $userId,
                    $assignedProviderId,
                    $warehouse['name'] . ' (' . $warehouse['location'] . ')',
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

                if (function_exists('notifyUser')) {
                    notifyUser(
                        $userId,
                        'Your warehouse booking request has been submitted.'
                    );
                }

                $success = 'Booking request submitted and delivery dispatched successfully.';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to create the booking. Please try again.';

                if (!($e instanceof RuntimeException)) {
                    error_log($e->getMessage());
                }
            }
        }
    }
}

// Handle Search and Filter inputs (Strict Filtering)
$searchLocation = trim($_GET['location'] ?? '');
$maxPriceInput = $_GET['max_price'] ?? '';
$maxPrice = is_numeric($maxPriceInput) && $maxPriceInput !== ''
    ? max(0, (float) $maxPriceInput)
    : null;

// Build strict filtering query
$sql = "SELECT * FROM warehouses WHERE status = 'approved'";
$params = [];

if ($searchLocation !== '') {
    $sql .= " AND location LIKE ?";
    $params[] = '%' . $searchLocation . '%';
}

if ($maxPrice !== null) {
    $sql .= " AND price_per_unit <= ?";
    $params[] = $maxPrice;
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$warehouses = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT b.*, w.name AS warehouse_name
     FROM warehouse_bookings b
     JOIN warehouses w ON w.id = b.warehouse_id
     WHERE b.user_id = ?
     ORDER BY b.created_at DESC"
);

$stmt->execute([$userId]);
$bookings = $stmt->fetchAll();

$pageTitle = 'Warehouses';
include '../includes/header.php';
include '../includes/sidebar.php';

?>

<main class="main-content">

    <h1>Warehouse Catalogue</h1>

    <p>
        Find storage space that suits your business.
        Prices are displayed per storage unit, per day.
    </p>

    <?php if (!empty($success)): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Filter Warehouses</h2>

        <form method="GET" class="filter-form">
            <div>
                <label>Preferred Location</label>
                <input type="text" name="location"
                       value="<?= e($searchLocation) ?>"
                       placeholder="e.g. Lusaka">
            </div>

            <div>
                <label>Maximum Price per Unit/Day (K)</label>
                <input type="number" step="0.01" min="0"
                       name="max_price"
                       value="<?= e($maxPriceInput) ?>">
            </div>

            <button class="btn primary">Find Warehouses</button>
        </form>
    </section>

    <div class="warehouse-grid">

        <?php foreach ($warehouses as $warehouse): ?>

            <article class="warehouse-card">

                <div class="warehouse-card-image">
                    <?php if (!empty($warehouse['image_path'])): ?>
                        <img src="../<?= e($warehouse['image_path']) ?>"
                             alt="<?= e($warehouse['name']) ?> warehouse"
                             loading="lazy">
                    <?php else: ?>
                        <span>Photo not available</span>
                    <?php endif; ?>
                </div>

                <div class="warehouse-card-top">
                    <span class="badge">
                        <?= (int) $warehouse['available_units'] ?>
                        units available
                    </span>
                </div>

                <h3><?= e($warehouse['name']) ?></h3>

                <p class="muted">
                    <?= e($warehouse['location']) ?>
                </p>

                <p><?= e($warehouse['description']) ?></p>

                <h2>
                    <?= money($warehouse['price_per_unit']) ?>
                    <small>/unit/day</small>
                </h2>

                <?php if ((int) $warehouse['available_units'] > 0): ?>

                    <form method="POST" class="booking-form">

                        <input type="hidden" name="csrf_token"
                               value="<?= e(csrfToken()) ?>">

                        <input type="hidden" name="action"
                               value="book">

                        <input type="hidden" name="warehouse_id"
                               value="<?= (int) $warehouse['id'] ?>">

                        <label>Storage Units</label>
                        <input type="number" name="units"
                               min="1"
                               max="<?= (int) $warehouse['available_units'] ?>"
                               value="1" required>

                        <label>Start Date</label>
                        <input type="date" name="start_date"
                               min="<?= date('Y-m-d') ?>"
                               required>

                        <label>End Date</label>
                        <input type="date" name="end_date"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                               required>

                        <button class="btn primary full-width">
                            Request Booking
                        </button>

                    </form>

                <?php else: ?>

                    <p class="muted">Currently unavailable.</p>

                <?php endif; ?>

            </article>

        <?php endforeach; ?>

        <?php if (empty($warehouses)): ?>
            <p class="muted">No warehouses found matching your criteria.</p>
        <?php endif; ?>

    </div>

    <section class="panel">
        <h2>My Bookings</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Warehouse</th>
                        <th>Units</th>
                        <th>Dates</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td><?= e($booking['warehouse_name']) ?></td>
                            <td><?= (int) ($booking['units'] ?? $booking['units_booked']) ?></td>
                            <td>
                                <?= e($booking['start_date']) ?>
                                to
                                <?= e($booking['end_date']) ?>
                            </td>
                            <td><?= money($booking['total_price'] ?: $booking['total_cost']) ?></td>
                            <td>
                                <?= renderStatusBadge($booking['status']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$bookings): ?>
                        <tr>
                            <td colspan="5">No bookings yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<?php include '../includes/footer.php'; ?>