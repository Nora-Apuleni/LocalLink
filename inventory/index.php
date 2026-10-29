<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    if ($action === 'add') {
        $name = sanitizeText($_POST['product_name'] ?? '');
        $sku = sanitizeText($_POST['sku'] ?? '', true);
        $quantity = sanitizePositiveInt($_POST['quantity'] ?? null);
        $threshold = sanitizePositiveInt($_POST['threshold'] ?? null);

        if (
            $name === null ||
            $quantity === null ||
            $threshold === null
        ) {
            $error = 'Enter valid product details.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO products (user_id, product_name, sku, quantity, low_stock_threshold)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $name, $sku !== null && $sku !== '' ? $sku : null, $quantity, $threshold]);
            $productId = (int) $pdo->lastInsertId();

            $stmtLog = $pdo->prepare(
                "INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, reason)
                 VALUES (?, ?, 'IN', ?, 'Initial stock setup')"
            );
            $stmtLog->execute([$productId, $userId, $quantity]);

            if ($quantity <= $threshold) {
                notifyUser($userId, "Low-stock alert: $name has $quantity units remaining.");
            }

            $success = 'Product added successfully.';
        }
    }

    if ($action === 'update') {
        $productId = sanitizePositiveInt($_POST['product_id'] ?? null);
        $newQuantity = sanitizePositiveInt($_POST['quantity'] ?? null);
        $threshold = sanitizePositiveInt($_POST['threshold'] ?? null);

        if (
            $productId === null ||
            $newQuantity === null ||
            $threshold === null
        ) {
            $error = 'Enter valid stock information.';
        } else {
            // Fetch old stock to calculate quantity change
            $stmtOld = $pdo->prepare("SELECT quantity, product_name FROM products WHERE id = ? AND user_id = ?");
            $stmtOld->execute([$productId, $userId]);
            $oldProduct = $stmtOld->fetch();

            if ($oldProduct) {
                $oldQuantity = (int)$oldProduct['quantity'];
                $diff = $newQuantity - $oldQuantity;

                $stmt = $pdo->prepare(
                    "UPDATE products SET quantity = ?, low_stock_threshold = ? WHERE id = ? AND user_id = ?"
                );
                $stmt->execute([$newQuantity, $threshold, $productId, $userId]);

                // Movement Log: Record change if quantity was modified
                if ($diff !== 0) {
                    $type = $diff > 0 ? 'IN' : 'OUT';
                    $stmtLog = $pdo->prepare(
                        "INSERT INTO inventory_movements (product_id, user_id, movement_type, quantity_change, reason)
                         VALUES (?, ?, ?, ?, 'Stock update from dashboard')"
                    );
                    $stmtLog->execute([$productId, $userId, $type, abs($diff)]);
                }

                if ($newQuantity <= $threshold) {
                    notifyUser($userId, "Low-stock alert: {$oldProduct['product_name']} has $newQuantity units remaining.");
                }

                $success = 'Inventory updated.';
            }
        }
    }

    if ($action === 'delete') {
        $productId = sanitizePositiveInt($_POST['product_id'] ?? null);

        if ($productId !== null) {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
            $stmt->execute([$productId, $userId]);
            $success = 'Product deleted.';
        }
    }
}

// Fetch user's inventory
$stmt = $pdo->prepare(
    "SELECT * FROM products WHERE user_id = ? ORDER BY product_name"
);
$stmt->execute([$userId]);
$products = $stmt->fetchAll();

// Fetch stock movement history
$stmtMovements = $pdo->prepare(
    "SELECT im.*, p.product_name 
     FROM inventory_movements im
     JOIN products p ON im.product_id = p.id
     WHERE im.user_id = ?
     ORDER BY im.created_at DESC LIMIT 10"
);
$stmtMovements->execute([$userId]);
$recentMovements = $stmtMovements->fetchAll();

$pageTitle = 'Inventory';
include '../includes/header.php';
include '../includes/sidebar.php';

?>

<main class="main-content">

    <h1>Inventory Management</h1>
    <p>Monitor your products and stock levels.</p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <section class="panel">

        <h2>Add Product</h2>

        <form method="POST" class="form-grid">

            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="add">

            <div>
                <label>Product Name</label>
                <input type="text" name="product_name" required>
            </div>

            <div>
                <label>SKU</label>
                <input type="text" name="sku">
            </div>

            <div>
                <label>Quantity</label>
                <input type="number" name="quantity" min="0" required>
            </div>

            <div>
                <label>Low Stock Threshold</label>
                <input type="number" name="threshold" min="0" value="5" required>
            </div>

            <div>
                <button class="btn primary">Add Product</button>
            </div>

        </form>

    </section>

    <section class="panel">

        <h2>My Products</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Current Stock</th>
                        <th>Threshold</th>
                        <th>Stock Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($products as $product): ?>

                    <tr>
                        <td><?= e($product['product_name']) ?></td>

                        <td><?= e($product['sku'] ?? '') ?></td>

                        <td>
                            <?= (int) $product['quantity'] ?>
                        </td>

                        <td>
                            <?= (int) $product['low_stock_threshold'] ?>
                        </td>

                        <td>
                            <?php if ($product['quantity'] <= $product['low_stock_threshold']): ?>
                                <span class="status danger">
                                    Low Stock
                                </span>
                            <?php else: ?>
                                <span class="status success">
                                    In Stock
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>

                            <form method="POST" class="inline-form">

                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                                <input type="number" name="quantity" min="0" value="<?= (int) $product['quantity'] ?>" required>
                                <input type="number" name="threshold" min="0" value="<?= (int) $product['low_stock_threshold'] ?>" required>

                                <button class="btn small primary">
                                    Update
                                </button>

                            </form>

                            <form method="POST" class="inline-form" data-confirm="Delete this product?">

                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                                <button class="btn small danger-btn">
                                    Delete
                                </button>

                            </form>

                        </td>
                    </tr>

                <?php endforeach; ?>

                <?php if (!$products): ?>
                    <tr>
                        <td colspan="6">No products added yet.</td>
                    </tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </section>

    <section class="panel">
        <h2>Stock Movement History</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentMovements as $m): ?>
                        <tr>
                            <td><?= e($m['created_at']) ?></td>
                            <td><?= e($m['product_name']) ?></td>
                            <td>
                                <span class="status <?= $m['movement_type'] === 'IN' ? 'success' : 'danger' ?>">
                                    <?= e($m['movement_type']) ?>
                                </span>
                            </td>
                            <td><?= e($m['quantity_change']) ?></td>
                            <td><?= e($m['reason'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$recentMovements): ?>
                        <tr>
                            <td colspan="5">No stock movement history recorded yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<?php include '../includes/footer.php'; ?>