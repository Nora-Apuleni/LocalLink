<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();
$error = '';
$success = '';

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $deliveryId = filter_var($_POST['delivery_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = trim($_POST['status'] ?? '');

        // Define allowed workflow statuses
        $allowedStatuses = ['assigned', 'picked_up', 'in_transit', 'delivered', 'cancelled'];

        if (!$deliveryId || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'Invalid delivery ID or status selected.';
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Verify that the delivery is assigned to this provider and lock the row
                $stmt = $pdo->prepare(
                    "SELECT * FROM deliveries 
                     WHERE id = ? AND provider_id = ? 
                     FOR UPDATE"
                );
                $stmt->execute([$deliveryId, $userId]);
                $delivery = $stmt->fetch();

                if (!$delivery) {
                    throw new RuntimeException('Delivery not found or not assigned to you.');
                }

                $currentStatus = $delivery['status'];

                // 2. Enforce Valid Status Transitions
                if ($currentStatus === 'assigned' && in_array($newStatus, ['delivered'], true)) {
                    throw new RuntimeException('Invalid transition: Delivery must be picked up or placed in transit before being marked as delivered.');
                }

                if (in_array($currentStatus, ['delivered', 'cancelled'], true)) {
                    throw new RuntimeException('Cannot update a delivery that is already completed or cancelled.');
                }

                // 3. Perform the status update
                $updateStmt = $pdo->prepare("UPDATE deliveries SET status = ? WHERE id = ?");
                $updateStmt->execute([$newStatus, $deliveryId]);

                $pdo->commit();

                // 4. Notify the customer about the status change
                if (function_exists('notifyUser') && !empty($delivery['user_id'])) {
                    $formattedStatus = ucwords(str_replace('_', ' ', $newStatus));
                    notifyUser(
                        $delivery['user_id'],
                        "Your delivery status has been updated to: {$formattedStatus}."
                    );
                }

                $success = 'Delivery status successfully updated and customer notified.';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to update delivery status. Please try again.';

                if (!($e instanceof RuntimeException)) {
                    error_log($e->getMessage());
                }
            }
        }
    }
}

// Fetch deliveries assigned to this provider
$stmt = $pdo->prepare(
    "SELECT d.*, u.name AS customer_name 
     FROM deliveries d
     LEFT JOIN users u ON u.id = d.user_id
     WHERE d.provider_id = ?
     ORDER BY d.created_at DESC"
);
$stmt->execute([$userId]);
$deliveries = $stmt->fetchAll();

$pageTitle = 'Provider Dashboard';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<main class="main-content">

    <h1>Delivery Provider Dashboard</h1>
    <p>Manage your assigned logistics jobs, update statuses, and track fulfillment.</p>

    <?php if (!empty($success)): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Assigned Deliveries</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Pickup / Dropoff</th>
                        <th>Current Status</th>
                        <th>Action / Update Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deliveries as $item): ?>
                        <tr>
                            <td>#<?= (int) $item['id'] ?></td>
                            <td><?= e($item['customer_name'] ?? 'Guest/Client') ?></td>
                            <td>
                                <strong>From:</strong> <?= e($item['pickup_location'] ?? 'N/A') ?><br>
                                <strong>To:</strong> <?= e($item['destination'] ?? 'N/A') ?>
                            </td>
                            <td>
                                <span class="status <?= $item['status'] === 'delivered' ? 'success' : '' ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $item['status']))) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!in_array($item['status'], ['delivered', 'cancelled'])): ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="delivery_id" value="<?= (int) $item['id'] ?>">

                                        <select name="status" required>
                                            <option value="">Select Next Status</option>
                                            <?php if ($item['status'] === 'assigned'): ?>
                                                <option value="picked_up">Picked Up</option>
                                                <option value="in_transit">In Transit</option>
                                            <?php endif; ?>
                                            <option value="in_transit">In Transit</option>
                                            <option value="delivered">Delivered</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>

                                        <button class="btn small primary">Update</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Locked (<?= e($item['status']) ?>)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$deliveries): ?>
                        <tr>
                            <td colspan="5">No deliveries currently assigned to you.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<?php include '../includes/footer.php'; ?>