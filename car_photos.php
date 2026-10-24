<?php
session_start();
require 'database.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($dbc, 'SELECT role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$user || $user['role'] !== 'staff') {
    http_response_code(403);
    exit('Access denied. Staff only.');
}

$carId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$carId || $carId < 1) {
    http_response_code(400);
    exit('Invalid car ID.');
}
$stmt = mysqli_prepare($dbc, 'SELECT id, brand, model FROM cars WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $carId);
mysqli_stmt_execute($stmt);
$car = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$car) {
    http_response_code(404);
    exit('Car not found.');
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid form token.');
    }
    $action = $_POST['action'] ?? 'upload';

    if ($action === 'set_primary' || $action === 'delete_photo') {
        $photoId = filter_var($_POST['photo_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$photoId || $photoId < 1) {
            $error = 'Invalid photo ID.';
        } else {
            $deletedPath = null;
            try {
                mysqli_begin_transaction($dbc);
                // Lock the parent car row: serialize concurrent photo changes.
                $lock = mysqli_prepare($dbc, 'SELECT id FROM cars WHERE id = ? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 'i', $carId);
                mysqli_stmt_execute($lock);
                if (!mysqli_fetch_assoc(mysqli_stmt_get_result($lock))) {
                    throw new RuntimeException('Car no longer exists.');
                }

                $select = mysqli_prepare($dbc,
                    'SELECT id, image_path, is_primary FROM car_images WHERE id = ? AND car_id = ? FOR UPDATE');
                mysqli_stmt_bind_param($select, 'ii', $photoId, $carId);
                mysqli_stmt_execute($select);
                $chosen = mysqli_fetch_assoc(mysqli_stmt_get_result($select));
                if (!$chosen) {
                    throw new RuntimeException('Photo does not belong to this car.');
                }

                if ($action === 'set_primary') {
                    $clear = mysqli_prepare($dbc, 'UPDATE car_images SET is_primary = 0 WHERE car_id = ?');
                    mysqli_stmt_bind_param($clear, 'i', $carId);
                    mysqli_stmt_execute($clear);
                    $mark = mysqli_prepare($dbc,
                        'UPDATE car_images SET is_primary = 1 WHERE id = ? AND car_id = ?');
                    mysqli_stmt_bind_param($mark, 'ii', $photoId, $carId);
                    mysqli_stmt_execute($mark);
                    $notice = 'primary';
                } else {
                    $remove = mysqli_prepare($dbc,
                        'DELETE FROM car_images WHERE id = ? AND car_id = ?');
                    mysqli_stmt_bind_param($remove, 'ii', $photoId, $carId);
                    mysqli_stmt_execute($remove);
                    if (mysqli_stmt_affected_rows($remove) !== 1) {
                        throw new RuntimeException('Photo could not be deleted.');
                    }
                    // Ensure precisely one remaining primary image, if any.
                    $next = mysqli_prepare($dbc,
                        'SELECT id FROM car_images WHERE car_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1');
                    mysqli_stmt_bind_param($next, 'i', $carId);
                    mysqli_stmt_execute($next);
                    $replacementPhoto = mysqli_fetch_assoc(mysqli_stmt_get_result($next));
                    $clear = mysqli_prepare($dbc, 'UPDATE car_images SET is_primary = 0 WHERE car_id = ?');
                    mysqli_stmt_bind_param($clear, 'i', $carId);
                    mysqli_stmt_execute($clear);
                    if ($replacementPhoto) {
                        $replacementId = (int)$replacementPhoto['id'];
                        $mark = mysqli_prepare($dbc,
                            'UPDATE car_images SET is_primary = 1 WHERE id = ? AND car_id = ?');
                        mysqli_stmt_bind_param($mark, 'ii', $replacementId, $carId);
                        mysqli_stmt_execute($mark);
                    }
                    $deletedPath = $chosen['image_path'];
                    $notice = 'deleted';
                }

                mysqli_commit($dbc);
                // Only ever unlink locally generated filenames inside uploads/cars.
                // This intentionally leaves a file untouched if its stored path is unusual.
                if ($deletedPath !== null && preg_match(
                    '~^uploads/cars/[a-f0-9]{32}\.(?:jpg|png|webp)$~D', $deletedPath
                )) {
                    $target = __DIR__ . '/' . $deletedPath;
                    if (is_file($target) && !is_link($target) && !@unlink($target)) {
                        error_log('Car photo file could not be removed: ' . $target);
                    }
                }
                header('Location: car_photos.php?id=' . $carId . '&' . $notice . '=1');
                exit;
            } catch (Throwable $ex) {
                mysqli_rollback($dbc);
                error_log($ex->getMessage());
                $error = 'Could not update the photo. Please try again.';
            }
        }
    } elseif ($action === 'upload') {
    $files = $_FILES['photos'] ?? null;
    if (!$files || !isset($files['name']) || !is_array($files['name'])) {
        $error = 'Please choose image files.';
    } else {
        $count = count($files['name']);
        $stmt = mysqli_prepare($dbc, 'SELECT COUNT(*) AS total, COALESCE(SUM(is_primary), 0) AS primary_count FROM car_images WHERE car_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $carId);
        mysqli_stmt_execute($stmt);
        $stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $existing = (int)$stats['total'];
        $hasPrimary = (int)$stats['primary_count'] > 0;
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $validated = [];
        if ($count < 1 || $count > 8 || $existing + $count > 8) {
            $error = 'Choose up to 8 images total per car.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            for ($i = 0; $i < $count; $i++) {
                $tmp = $files['tmp_name'][$i] ?? '';
                $size = (int)($files['size'][$i] ?? 0);
                $uploadError = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                    $error = 'One or more uploads failed.';
                    break;
                }
                if ($size <= 0 || $size > 5 * 1024 * 1024) {
                    $error = 'Each image must be 5 MB or smaller.';
                    break;
                }
                $mime = $finfo->file($tmp);
                $dimensions = @getimagesize($tmp);
                if (!isset($allowed[$mime]) || !$dimensions || ($dimensions['mime'] ?? '') !== $mime) {
                    $error = 'Only valid JPG, PNG or WebP images are allowed.';
                    break;
                }
                $validated[] = ['tmp' => $tmp, 'ext' => $allowed[$mime]];
            }
        }
        if ($error === '') {
            $directory = __DIR__ . '/uploads/cars';
            if (!is_dir($directory) || !is_writable($directory)) {
                $error = 'The uploads/cars folder is missing or not writable.';
            } else {
                $moved = [];
                try {
                    mysqli_begin_transaction($dbc);
                    // Lock the car before checking the photo count, so two staff
                    // members cannot exceed the eight-photo limit concurrently.
                    $carLock = mysqli_prepare($dbc,
                        'SELECT id FROM cars WHERE id = ? FOR UPDATE');
                    mysqli_stmt_bind_param($carLock, 'i', $carId);
                    mysqli_stmt_execute($carLock);
                    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($carLock))) {
                        throw new RuntimeException('Car no longer exists.');
                    }
                    mysqli_stmt_close($carLock);
                    $countStmt = mysqli_prepare($dbc,
                        'SELECT COUNT(*) AS total, COALESCE(SUM(is_primary),0) AS primary_count
                         FROM car_images WHERE car_id = ?');
                    mysqli_stmt_bind_param($countStmt, 'i', $carId);
                    mysqli_stmt_execute($countStmt);
                    $currentStats = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt));
                    mysqli_stmt_close($countStmt);
                    $existing = (int)$currentStats['total'];
                    $hasPrimary = (int)$currentStats['primary_count'] > 0;
                    if ($existing + count($validated) > 8) {
                        throw new RuntimeException('Photo limit reached; reload and try again.');
                    }
                    $insert = mysqli_prepare($dbc,
                        'INSERT INTO car_images (car_id, image_path, is_primary, sort_order) VALUES (?, ?, ?, ?)');
                    foreach ($validated as $index => $photo) {
                        $filename = bin2hex(random_bytes(16)) . '.' . $photo['ext'];
                        $destination = $directory . '/' . $filename;
                        if (!move_uploaded_file($photo['tmp'], $destination)) {
                            throw new RuntimeException('Could not save uploaded image.');
                        }
                        $moved[] = $destination;
                        $path = 'uploads/cars/' . $filename;
                        $primary = (!$hasPrimary && $index === 0) ? 1 : 0;
                        $sortOrder = $existing + $index;
                        mysqli_stmt_bind_param($insert, 'isii', $carId, $path, $primary, $sortOrder);
                        if (!mysqli_stmt_execute($insert)) {
                            throw new RuntimeException('Could not save image record.');
                        }
                    }
                    mysqli_commit($dbc);
                    header('Location: car_photos.php?id=' . $carId . '&uploaded=1');
                    exit;
                } catch (Throwable $ex) {
                    mysqli_rollback($dbc);
                    foreach ($moved as $file) {
                        @unlink($file);
                    }
                    error_log($ex->getMessage());
                    $error = 'Upload could not be completed. Check the folder permissions and try again.';
                }
            }
        }
    }
    } else {
        $error = 'Unknown action.';
    }
}

$stmt = mysqli_prepare($dbc,
    'SELECT id, image_path, is_primary FROM car_images WHERE car_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC');
mysqli_stmt_bind_param($stmt, 'i', $carId);
mysqli_stmt_execute($stmt);
$images = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Car Photos</title>
<style>
body {font-family:Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 16px;color:#172b4d}
a {color:#1557c0} .panel {padding:20px;background:#f3f6fa;border-radius:10px;margin-top:20px}
input,button {margin-top:14px} button {padding:11px 18px;background:#175cd3;color:white;border:0;border-radius:6px;cursor:pointer}
.gallery {display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:20px}
.photo {border:1px solid #ddd;border-radius:9px;overflow:hidden;background:#fff}
.photo img {width:100%;height:160px;object-fit:cover;display:block}.photo p {padding:0 12px}
.error {color:#a00}.success {color:#176b35}.photo-actions {display:flex;gap:8px;flex-wrap:wrap;padding:0 12px 12px}.photo-actions form {flex:1}.photo-actions button {font-size:12px;padding:9px 10px;margin:0;width:100%}.photo-actions .danger {background:#ae2635}.primary-label {font-weight:bold;color:#176b35}
</style>
</head>
<body>
<p><a href="manage_cars.php">← Back to Manage Cars</a></p>
<h1>Photos — <?= e($car['brand']) ?> <?= e($car['model']) ?></h1>
<p>Upload up to 8 images per car (JPG, PNG or WebP; maximum 5 MB each). The first uploaded image becomes the primary photo.</p>
<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['uploaded'])): ?><p class="success">Photos uploaded successfully.</p><?php endif; ?>
<?php if (isset($_GET['primary'])): ?><p class="success">Primary photo updated.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="success">Photo removed from this car.</p><?php endif; ?>
<div class="panel">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="upload">
<label for="photos"><strong>Select car photographs</strong></label>
<p><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></p>
<button type="submit">Upload Photos</button>
</form>
</div>
<h2>Uploaded photographs</h2>
<div class="gallery">
<?php while ($image = mysqli_fetch_assoc($images)): ?>
<div class="photo">
<img src="<?= e($image['image_path']) ?>" alt="<?= e($car['brand'] . ' ' . $car['model']) ?>">
<p class="<?= (int)$image['is_primary'] === 1 ? 'primary-label' : '' ?>"><?= (int)$image['is_primary'] === 1 ? 'Primary photo' : 'Additional photo' ?></p>
<div class="photo-actions">
<?php if ((int)$image['is_primary'] !== 1): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="set_primary">
<input type="hidden" name="photo_id" value="<?= (int)$image['id'] ?>">
<button type="submit">Set Primary</button>
</form>
<?php endif; ?>
<form method="post" onsubmit="return confirm('Delete this photo? This cannot be undone.');">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="delete_photo">
<input type="hidden" name="photo_id" value="<?= (int)$image['id'] ?>">
<button type="submit" class="danger">Delete</button>
</form>
</div>
</div>
<?php endwhile; ?>
</div>
<?php if (mysqli_num_rows($images) === 0): ?><p>No photographs uploaded yet.</p><?php endif; ?>
</body>
</html>
<?php mysqli_close($dbc); ?>
