<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $bookingId = sanitizePositiveInt($_POST['booking_id'] ?? null);

    if ($bookingId === null) {
        $error = 'Invalid booking.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, COALESCE(NULLIF(total_price, 0), total_cost, 0) AS total_amount, status
                 FROM warehouse_bookings
                 WHERE id = ? AND user_id = ?
                 FOR UPDATE"
            );

            $stmt->execute([$bookingId, $userId]);
            $booking = $stmt->fetch();

            if (!$booking || $booking['status'] === 'cancelled') {
                throw new RuntimeException(
                    'This booking cannot be paid.'
                );
            }

            $stmt = $pdo->prepare(
                "SELECT id FROM payments
                 WHERE booking_id = ?
                 AND user_id = ?
                 AND status = 'successful'
                 LIMIT 1"
            );

            $stmt->execute([$bookingId, $userId]);

            if ($stmt->fetch()) {
                throw new RuntimeException(
                    'This booking has already been paid.'
                );
            }

            $reference = 'LLK-' . bin2hex(random_bytes(8));

            $stmt = $pdo->prepare(
                "INSERT INTO payments
                (user_id, booking_id, amount,
                 payment_method, transaction_reference, status)
                VALUES (?, ?, ?, 'Demo Payment', ?, 'successful')"
            );

            $stmt->execute([
                $userId,
                $bookingId,
                (float) $booking['total_amount'],
                $reference
            ]);

            if ($booking['status'] === 'pending') {
                $stmt = $pdo->prepare(
                    "UPDATE warehouse_bookings
                     SET status = 'confirmed'
                     WHERE id = ? AND user_id = ?"
                );

                $stmt->execute([$bookingId, $userId]);
            }

            $pdo->commit();

            notifyUser(
                $userId,
                "Demo payment successful. Reference: $reference"
            );

            $success = "Demo payment successful. Reference: $reference";

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Payment failed. Please try again.';

            if (!($e instanceof RuntimeException)) {
                error_log($e->getMessage());
            }
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT b.*,
            COALESCE(NULLIF(b.total_price, 0), b.total_cost, 0) AS total_amount,
            w.name AS warehouse_name,
            EXISTS (
                SELECT 1 FROM payments p
                WHERE p.booking_id = b.id
                AND p.user_id = b.user_id
                AND p.status = 'successful'
            ) AS is_paid
     FROM warehouse_bookings b
     JOIN warehouses w ON w.id = b.warehouse_id
     WHERE b.user_id = ?
     AND b.status != 'cancelled'
     ORDER BY b.created_at DESC"
);

$stmt->execute([$userId]);
$bookings = $stmt->fetchAll();
$amountDue = 0.0;
$unpaidCount = 0;

foreach ($bookings as $booking) {
    if (!(int) $booking['is_paid']) {
        $amountDue += (float) $booking['total_amount'];
        $unpaidCount++;
    }
}

$stmt = $pdo->prepare(
    "SELECT * FROM payments
     WHERE user_id = ?
     ORDER BY created_at DESC"
);

$stmt->execute([$userId]);
$payments = $stmt->fetchAll();

$pageTitle = 'Payments';
include '../includes/header.php';
include '../includes/sidebar.php';

?>

<main class="main-content">

    <div class="page-heading">
        <div>
            <h1>Payments</h1>
            <p>Review booking balances and your recent payment activity.</p>
        </div>
    </div>

    <div class="alert warning">
        Demo mode: payments are simulated; no money will be transferred.
    </div>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="stats-grid payment-summary">
        <article class="stat-card stat-warning">
            <p>Amount due</p>
            <h2><?= money($amountDue) ?></h2>
        </article>

        <article class="stat-card stat-primary">
            <p>Bookings awaiting payment</p>
            <h2><?= $unpaidCount ?></h2>
        </article>
    </div>

    <section class="panel">

        <h2>Your Bookings</h2>

        <div class="table-wrapper">
            <table>

                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Warehouse</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($bookings as $booking): ?>
                        <?php $isPaid = (int) $booking['is_paid'] > 0; ?>

                        <tr>

                            <td>#<?= (int) $booking['id'] ?></td>

                            <td><?= e($booking['warehouse_name']) ?></td>

                            <td><?= money($booking['total_amount']) ?></td>

                            <td>
                                <span class="status <?= $isPaid ? 'success' : 'warning' ?>">
                                    <?= $isPaid ? 'Paid' : 'Payment due' ?>
                                </span>
                            </td>

                            <td>

                                <?php if (!$isPaid): ?>

                                    <form method="POST">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e(csrfToken()) ?>">

                                        <input type="hidden"
                                               name="booking_id"
                                               value="<?= (int) $booking['id'] ?>">

                                        <button class="btn small primary">
                                            Simulate Payment
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="status success">
                                        Completed
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$bookings): ?>
                        <tr>
                            <td colspan="5">
                                No active bookings yet.
                            </td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>
        </div>

    </section>

    <section class="panel">

        <h2>Transaction History</h2>

        <div class="table-wrapper">
            <table>

                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($payments as $payment): ?>

                        <tr>
                            <td>
                                <?= e($payment['transaction_reference']) ?>
                            </td>

                            <td><?= money($payment['amount']) ?></td>

                            <td><?= e($payment['payment_method']) ?></td>

                            <td><?= renderStatusBadge($payment['status']) ?></td>

                            <td><?= e(date('M j, Y, g:i a', strtotime($payment['created_at']))) ?></td>
                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$payments): ?>
                        <tr>
                            <td colspan="5">No transactions yet.</td>
                        </tr>
                    <?php endif; ?>

                </tbody>

            </table>
        </div>

    </section>

</main>

<?php include '../includes/footer.php'; ?>