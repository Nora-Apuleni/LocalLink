<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    if ($action === 'create') {
        $pickup = sanitizeText($_POST['pickup_location'] ?? '');
        $destination = sanitizeText($_POST['destination'] ?? '');
        $description = sanitizeText($_POST['description'] ?? '', true);
        $productId = sanitizePositiveInt($_POST['product_id'] ?? null);
        $deliveryUnits = sanitizePositiveInt($_POST['delivery_units'] ?? 1);
        $fee = sanitizePositiveFloat($_POST['delivery_fee'] ?? null, 0.0);

        if (
            $pickup === null ||
            $destination === null ||
            $fee === null
        ) {
            $error = 'Enter valid delivery details.';
        } else {
            try {
                $pdo->beginTransaction();

                // If a product is attached, check stock and deduct it
                if ($productId !== null) {
                    $stmtProd = $pdo->prepare("SELECT id, product_name, quantity FROM products WHERE id = ? AND user_id = ? FOR UPDATE");
                    $stmtProd->execute([$productId, $userId]);
                    $product = $stmtProd->fetch();

                    if (!$product) {
                        throw new RuntimeException('Selected product not found.');
                    }

                    if ($deliveryUnits > (int)$product['quantity']) {
                        throw new RuntimeException("Insufficient stock for {$product['product_name']}. Only {$product['quantity']} available.");
                    }

                    $newQty = (int) $product['quantity'] - $deliveryUnits;
                    $stmtUp = $pdo->prepare("UPDATE products SET quantity = ? WHERE id = ?");
                    $stmtUp->execute([$newQty, $productId]);

                    $stmtLog = $pdo->prepare(
                        "INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, reason)
                         VALUES (?, ?, 'OUT', ?, ?)"
                    );
                    $stmtLog->execute([$productId, $userId, $deliveryUnits, "Dispatched for delivery to $destination"]);
                }

                // Insert Delivery Request
                $stmt = $pdo->prepare(
                    "INSERT INTO deliveries
                    (user_id, pickup_location, destination,
                     delivery_description, delivery_fee, status)
                    VALUES (?, ?, ?, ?, ?, 'requested')"
                );

                $deliveryDescription = ($description !== '' ? $description : 'Delivery shipment');
                if ($productId !== null && isset($product)) {
                    $deliveryDescription .= " (Item: {$product['product_name']} x{$deliveryUnits})";
                }

                $stmt->execute([
                    $userId,
                    $pickup,
                    $destination,
                    $deliveryDescription,
                    $fee
                ]);

                $pdo->commit();

                notifyUser(
                    $userId,
                    'Your delivery request has been submitted and inventory stock updated.'
                );

                $success = 'Delivery request submitted and stock dispatched successfully.';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to process delivery request.';
            }
        }
    }

    if ($action === 'cancel') {
        $deliveryId = filter_var(
            $_POST['delivery_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($deliveryId) {
            $stmt = $pdo->prepare(
                "UPDATE deliveries
                 SET status = 'cancelled'
                 WHERE id = ? AND user_id = ? AND status = 'requested'"
            );

            $stmt->execute([$deliveryId, $userId]);

            if ($stmt->rowCount() > 0) {
                notifyUser(
                    $userId,
                    "Delivery request #$deliveryId has been cancelled."
                );
                $success = 'Delivery request cancelled successfully.';
            } else {
                $error = 'This delivery can no longer be cancelled.';
            }
        }
    }
}

// Fetch user products for dropdown selection
$stmtProds = $pdo->prepare("SELECT id, product_name, quantity FROM products WHERE user_id = ? ORDER BY product_name");
$stmtProds->execute([$userId]);
$myProducts = $stmtProds->fetchAll();

$stmt = $pdo->prepare(
    "SELECT * FROM deliveries
     WHERE user_id = ?
     ORDER BY created_at DESC"
);

$stmt->execute([$userId]);
$deliveries = $stmt->fetchAll();

$pageTitle = 'Deliveries & Inventory Connection';
include '../includes/header.php';
include '../includes/sidebar.php';

?>

<main class="main-content">

    <h1>Delivery Management & Inventory Integration</h1>

    <p>
        Request local deliveries, link specific stock items, and automatically log inventory movements.
    </p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <section class="panel">

        <h2>Request a Delivery & Dispatch Stock</h2>

        <form method="POST" class="form-grid">

            <input type="hidden" name="csrf_token"
                   value="<?= e(csrfToken()) ?>">

            <input type="hidden" name="action" value="create">

            <div>
                <label>Pickup Location (e.g. Warehouse)</label>
                <input type="text" name="pickup_location"
                       placeholder="Warehouse or Store address" required>
            </div>

            <div>
                <label>Delivery Destination</label>
                <input type="text" name="destination"
                       placeholder="Customer address" required>
            </div>

            <div>
                <label>Link Inventory Item (Optional)</label>
                <select name="product_id" class="form-control compact-select full-width">
                    <option value="">-- None (General Shipment) --</option>
                    <?php foreach ($myProducts as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['product_name']) ?> (Stock: <?= (int)$p['quantity'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Units to Dispatch</label>
                <input type="number" name="delivery_units" min="1" value="1">
            </div>

            <div>
                <label>Delivery Description</label>
                <input type="text" name="description"
                       placeholder="e.g. Fragile items">
            </div>

            <div>
                <label>Delivery Fee (K)</label>
                <input type="number" name="delivery_fee"
                       min="0" step="0.01" value="0"
                       required>
            </div>

            <div>
                <button class="btn primary">
                    Submit Request & Dispatch Stock
                </button>
            </div>

        </form>

    </section>

    <section class="panel">

        <h2>My Deliveries</h2>

        <div class="table-wrapper">
            <table>

                <thead>
                    <tr>
                        <th>Pickup / Destination</th>
                        <th>Shipment Details</th>
                        <th>Fee</th>
                        <th>Status</th>
                        <th>Date Requested</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($deliveries as $delivery): ?>

                        <tr>
                            <td>
                                <strong>From:</strong> <?= e($delivery['pickup_location']) ?><br>
                                <strong>To:</strong> <?= e($delivery['destination']) ?>
                            </td>

                            <td>
                                <?= e($delivery['delivery_description'] ?? '—') ?>
                            </td>

                            <td>
                                <?= money($delivery['delivery_fee']) ?>
                            </td>

                            <td>
                                <?= renderStatusBadge($delivery['status']) ?>
                            </td>

                            <td>
                                <?= e($delivery['created_at']) ?>
                            </td>

                            <td>
                                <?php if ($delivery['status'] === 'requested'): ?>
                                    <form method="POST" data-confirm="Cancel this delivery request?">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="delivery_id" value="<?= (int) $delivery['id'] ?>">
                                        <button class="btn small danger-btn">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$deliveries): ?>
                        <tr>
                            <td colspan="6">
                                No delivery requests yet.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>
        </div>

    </section>

</main>

<?php include '../includes/footer.php'; ?>