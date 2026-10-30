<?php

if (session_status() === PHP_SESSION_NONE) {
    $isSecureRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

    session_name('locallink_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isSecureRequest ? '1' : '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cache_limiter', 'nocache');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecureRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once __DIR__ . '/../config/database.php';

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function storeWarehouseImage($upload)
{
    $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($uploadError !== UPLOAD_ERR_OK || (int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Choose an image smaller than 5 MB and try again.');
    }

    $temporaryPath = $upload['tmp_name'] ?? '';
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $imageInfo = @getimagesize($temporaryPath);

    if (
        !isset($extensions[$mimeType]) ||
        $imageInfo === false ||
        ($imageInfo['mime'] ?? '') !== $mimeType
    ) {
        throw new RuntimeException('Use a valid JPG, PNG, or WebP image.');
    }

    $uploadDirectory = __DIR__ . '/../assets/uploads/warehouses';

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Photo storage is unavailable. Please try again later.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    $destination = $uploadDirectory . '/' . $fileName;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('The photo could not be saved. Please try again.');
    }

    return 'assets/uploads/warehouses/' . $fileName;
}

function deleteWarehouseImage($imagePath)
{
    if (!is_string($imagePath) || strpos($imagePath, 'assets/uploads/warehouses/') !== 0) {
        return;
    }

    $uploadDirectory = realpath(__DIR__ . '/../assets/uploads/warehouses');
    $filePath = realpath(__DIR__ . '/../' . $imagePath);

    if (
        $uploadDirectory !== false &&
        $filePath !== false &&
        strpos($filePath, $uploadDirectory . DIRECTORY_SEPARATOR) === 0 &&
        is_file($filePath)
    ) {
        unlink($filePath);
    }
}

function redirect($url)
{
    header("Location: $url");
    exit;
}

function requireLogin()
{
    if (!isset($_SESSION['user_id'])) {
        redirect('/locallink/login.php');
    }
}

function currentUserRole()
{
    return $_SESSION['role'] ?? 'business_owner';
}

function normalizeEmail($value)
{
    return strtolower(trim((string) $value));
}

function isValidEmail($value)
{
    return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function isStrongPassword($password)
{
    return is_string($password) && strlen($password) >= 8;
}

function redirectToRoleDashboard($role)
{
    $role = strtolower((string) $role);

    if ($role === 'admin') {
        return 'admin/dashboard.php';
    }

    if ($role === 'warehouse_manager') {
        return 'warehouses/manager_dashboard.php';
    }

    if ($role === 'delivery_provider') {
        return 'deliveries/provider_dashboard.php';
    }

    return 'dashboard.php';
}

function requireRole($allowedRoles)
{
    requireLogin();
    $role = currentUserRole();
    if (!in_array($role, (array)$allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to view this page.');
    }
}

function requireAdmin()
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied. Administrator permission required.');
    }
}

function csrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf()
{
    $submitted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($submitted) ||
        !is_string($stored) ||
        $stored === '' ||
        !hash_equals($stored, $submitted)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please refresh and try again.');
    }
}

function notifyUser($userId, $message)
{
    global $pdo;

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, message)
         VALUES (?, ?)"
    );

    $stmt->execute([$userId, $message]);
}

function currentUserId()
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function sanitizePositiveInt($value)
{
    if (!is_scalar($value)) {
        return null;
    }

    $intValue = filter_var($value, FILTER_VALIDATE_INT);

    if ($intValue === false || (int) $intValue < 1) {
        return null;
    }

    return (int) $intValue;
}

function sanitizeAllowedStatus($value, array $allowedStatuses)
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $status = trim((string) $value);

    if ($status === '') {
        return null;
    }

    return in_array($status, $allowedStatuses, true) ? $status : null;
}

function sanitizeText($value, $allowEmpty = false)
{
    if (!is_scalar($value)) {
        return null;
    }

    $text = trim((string) $value);

    if ($text === '' && !$allowEmpty) {
        return null;
    }

    return $text;
}

function sanitizePositiveFloat($value, $min = 0.0)
{
    if (!is_scalar($value)) {
        return null;
    }

    $number = filter_var($value, FILTER_VALIDATE_FLOAT);

    if ($number === false || (float) $number < $min) {
        return null;
    }

    return (float) $number;
}

function sanitizeDateString($value)
{
    $text = sanitizeText($value, false);

    if ($text === null) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

    if (!$date || $date->format('Y-m-d') !== $text) {
        return null;
    }

    return $text;
}

function money($amount)
{
    return 'K' . number_format((float) $amount, 2);
}

function statusLabel($status)
{
    $statusLower = strtolower(trim((string) $status));
    return ucwords(str_replace('_', ' ', $statusLower));
}

/**
 * Render a standardized, color-coded status badge for any module.
 */
function renderStatusBadge($status) {
    $statusLower = strtolower(trim((string) $status));
    $label = statusLabel($statusLower);
    
    $bg = '#718096'; // default gray
    $color = '#fff';

    switch ($statusLower) {
        case 'confirmed':
        case 'successful':
        case 'delivered':
        case 'completed':
        case 'in_transit':
        case 'approved':
            $bg = '#2ecc71'; // Success Green
            break;
        case 'pending':
        case 'requested':
        case 'assigned':
        case 'picked_up':
            $bg = '#f39c12'; // Warning Orange/Yellow
            break;
        case 'rejected':
        case 'failed':
        case 'cancelled':
            $bg = '#e74c3c'; // Danger Red
            break;
    }

    return '<span style="background: ' . $bg . '; color: ' . $color . '; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.85rem; font-weight: 500; display: inline-block; text-transform: capitalize;">' . e($label) . '</span>';
}

function getRecommendedWarehouses($pdo, $userBudget = 500, $requiredUnits = 10) {
    $stmt = $pdo->query("SELECT * FROM warehouses WHERE status = 'approved' AND available_units >= $requiredUnits");
    $warehouses = $stmt->fetchAll();

    foreach ($warehouses as &$warehouse) {
        $score = 100;
        
        if ($warehouse['price_per_unit'] > $userBudget) {
            $score -= 30;
        } else {
            $score += 20; 
        }

        if ($warehouse['available_units'] >= ($requiredUnits * 2)) {
            $score += 15;
        }

        $warehouse['ai_score'] = max(10, min(100, $score));
        $warehouse['ai_reason'] = "Matches your capacity needs ($requiredUnits units) with a strong value rating.";
    }

    usort($warehouses, function($a, $b) {
        return $b['ai_score'] <=> $a['ai_score'];
    });

    return $warehouses;
}

function getLowStockAlerts($pdo, $userId) {
    $stmt = $pdo->prepare(
        "SELECT id, product_name AS name, quantity, low_stock_threshold 
         FROM products 
         WHERE user_id = ? AND quantity <= low_stock_threshold"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function isWarehouseCapacityAvailable($pdo, $warehouseId, $unitsRequested, $startDate, $endDate, $excludeBookingId = null) {
    $stmt = $pdo->prepare("SELECT total_units FROM warehouses WHERE id = ?");
    $stmt->execute([$warehouseId]);
    $warehouse = $stmt->fetch();
    if (!$warehouse) return false;
    $totalCapacity = $warehouse['total_units'];

    $sql = "SELECT SUM(units_booked) as booked_units FROM warehouse_bookings 
            WHERE warehouse_id = ? 
            AND status = 'confirmed' 
            AND NOT (end_date < ? OR start_date > ?)";
    
    $params = [$warehouseId, $startDate, $endDate];
    if ($excludeBookingId) {
        $sql .= " AND id != ?";
        $params[] = $excludeBookingId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $alreadyBooked = $result['booked_units'] ?? 0;

    return ($alreadyBooked + $unitsRequested) <= $totalCapacity;
}