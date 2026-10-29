<?php

require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    redirect(
        ($_SESSION['role'] ?? '') === 'admin'
            ? 'admin/dashboard.php'
            : 'dashboard.php'
    );
}

redirect('login.php');