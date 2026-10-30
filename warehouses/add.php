<?php

require_once '../includes/functions.php';
requireLogin();

$userId = currentUserId();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_warehouse') {
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $pricePerUnit = filter_var($_POST['price_per_unit'] ?? null, FILTER_VALIDATE_FLOAT);
        $totalUnits = filter_var($_POST['total_units'] ?? null, FILTER_VALIDATE_INT);

        if (
            $name === '' ||
            $location === '' ||
            $pricePerUnit === false || $pricePerUnit === null || $pricePerUnit < 0 ||
            $totalUnits === false || $totalUnits === null || $totalUnits < 1
        ) {
            $error = 'Please enter valid warehouse details, pricing, and unit capacity.';
        } else {
            $imagePath = null;

            try {
                $imagePath = storeWarehouseImage($_FILES['image'] ?? []);
                $stmt = $pdo->prepare(
                    "INSERT INTO warehouses 
                    (user_id, name, location, description, image_path, price_per_unit, total_units, available_units, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                );
                
                $stmt->execute([
                    $userId,
                    $name,
                    $location,
                    $description !== '' ? $description : null,
                    $imagePath,
                    $pricePerUnit,
                    $totalUnits,
                    $totalUnits // Sets initial available units equal to total capacity
                ]);

                $success = 'Warehouse submitted successfully and is pending administrator approval.';
            } catch (Throwable $e) {
                deleteWarehouseImage($imagePath);
                $error = 'Unable to add warehouse. Please try again.';
                error_log($e->getMessage());
            }
        }
    }
}

$pageTitle = 'Add Warehouse';
include '../includes/header.php';
include '../includes/sidebar.php';

?>

<main class="main-content">

    <h1>Add a New Warehouse</h1>
    <p>Register your warehouse storage space to list it on the LocalLink marketplace.</p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Warehouse Details</h2>

        <form method="POST" class="form-grid" enctype="multipart/form-data">

            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_warehouse">

            <div>
                <label>Warehouse Name</label>
                <input type="text" name="name" required placeholder="e.g. Lusaka Central Depot">
            </div>

            <div>
                <label>Location / Address</label>
                <input type="text" name="location" required placeholder="e.g. Cairo Road, Lusaka">
            </div>

            <div>
                <label>Price per Unit / Day (K)</label>
                <input type="number" step="0.01" min="0" name="price_per_unit" required placeholder="0.00">
            </div>

            <div>
                <label>Total Units / Capacity</label>
                <input type="number" min="1" name="total_units" required placeholder="100">
            </div>

            <div>
                <label>Description / Facilities</label>
                <textarea name="description" placeholder="Describe security features, accessibility, or amenities..."></textarea>
            </div>

            <div>
                <label for="warehouse-image">Warehouse Photo</label>
                <input id="warehouse-image" type="file" name="image"
                       accept="image/jpeg,image/png,image/webp">
                <small class="muted">Optional. JPG, PNG, or WebP, up to 5 MB.</small>
            </div>

            <div>
                <button class="btn primary">Submit Warehouse</button>
            </div>

        </form>
    </section>

</main>

<?php include '../includes/footer.php'; ?>