<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $notificationId = filter_var(
        $_POST['notification_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($notificationId) {
        $stmt = $pdo->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE id = ? AND user_id = ?"
        );

        $stmt->execute([$notificationId, $userId]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE user_id = ?"
        );

        $stmt->execute([$userId]);
    }
}

$stmt = $pdo->prepare(
    "SELECT * FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC"
);

$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

$pageTitle = 'Notifications';
include '../includes/header.php';
include '../includes/sidebar.php';

?>

<main class="main-content">

    <h1>Notifications</h1>

    <p>Updates about your LocalLink activities.</p>

    <form method="POST" class="mb-20">
        <input type="hidden"
               name="csrf_token"
               value="<?= e(csrfToken()) ?>">

        <button class="btn secondary">
            Mark All as Read
        </button>
    </form>

    <section class="panel">

        <?php foreach ($notifications as $notification): ?>

            <div class="notification-item
                <?= $notification['is_read'] ? '' : 'unread' ?>">

                <div>
                    <p><?= e($notification['message']) ?></p>

                    <small>
                        <?= e($notification['created_at']) ?>
                    </small>
                </div>

                <?php if (!$notification['is_read']): ?>

                    <form method="POST">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= e(csrfToken()) ?>">

                        <input type="hidden"
                               name="notification_id"
                               value="<?= (int) $notification['id'] ?>">

                        <button class="btn small secondary">
                            Mark Read
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

        <?php if (!$notifications): ?>
            <p>You have no notifications.</p>
        <?php endif; ?>

    </section>

</main>

<?php include '../includes/footer.php'; ?>