<?php

require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name = sanitizeText($_POST['name'] ?? '');
    $email = normalizeEmail($_POST['email'] ?? '');
    $phone = sanitizeText($_POST['phone'] ?? '', true);
    $businessName = sanitizeText($_POST['business_name'] ?? '');
    $address = sanitizeText($_POST['address'] ?? '', true);
    $password = $_POST['password'] ?? '';

    if (
        $name === null ||
        $businessName === null ||
        !isValidEmail($email) ||
        !isStrongPassword($password)
    ) {
        $error = 'Enter valid details. Your password must contain at least 8 characters.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO users
                (name, email, phone, password, role)
                VALUES (?, ?, ?, ?, 'business')"
            );

            $stmt->execute([
                $name,
                $email,
                $phone,
                password_hash($password, PASSWORD_DEFAULT)
            ]);

            $userId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO businesses
                (user_id, business_name, address)
                VALUES (?, ?, ?)"
            );

            $stmt->execute([
                $userId,
                $businessName,
                $address
            ]);

            $pdo->commit();

            notifyUser(
                $userId,
                'Welcome to LocalLink! Your business account has been created.'
            );

            redirect('login.php?registered=1');

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === '23000') {
                $error = 'This email address is already registered.';
            } else {
                error_log($e->getMessage());
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create Account';
include 'includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h1>Create your account</h1>
        <p>Join LocalLink and manage your business logistics.</p>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(csrfToken()) ?>">

            <label>Full Name</label>
            <input type="text" name="name" required
                   value="<?= e($_POST['name'] ?? '') ?>">

            <label>Email Address</label>
            <input type="email" name="email" required
                   value="<?= e($_POST['email'] ?? '') ?>">

            <label>Phone Number</label>
            <input type="text" name="phone"
                   value="<?= e($_POST['phone'] ?? '') ?>">

            <label>Business Name</label>
            <input type="text" name="business_name" required
                   value="<?= e($_POST['business_name'] ?? '') ?>">

            <label>Business Address</label>
            <input type="text" name="address"
                   value="<?= e($_POST['address'] ?? '') ?>">

            <label>Password</label>
            <input type="password" name="password"
                   minlength="8" required>

            <button class="btn primary full-width">
                Create Account
            </button>
        </form>

        <p class="auth-link">
            Already registered?
            <a href="login.php">Log in</a>
        </p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>