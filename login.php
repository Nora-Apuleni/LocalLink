<?php

require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email = normalizeEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!isValidEmail($email) || !isStrongPassword($password)) {
        $error = 'Incorrect email address or password.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, name, email, password, role
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            redirect(redirectToRoleDashboard($user['role']));
        }

        $error = 'Incorrect email address or password.';
    }
}

$pageTitle = 'Login';
include 'includes/header.php';
?>

<div class="auth-container auth-container-login">
    <div class="auth-card">
        <h1>Welcome back</h1>
        <p>Log in to your LocalLink account.</p>

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert success">
                Account created successfully. You can now log in.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(csrfToken()) ?>">

            <label>Email Address</label>
            <input type="email" name="email" required>

            <label>Password</label>
            <input type="password" name="password" required>

            <button class="btn primary full-width">
                Log In
            </button>
        </form>

        <p class="auth-link">
            New to LocalLink?
            <a href="register.php">Create an account</a>
        </p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>