<?php
declare(strict_types=1);

const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

$images = array_values(array_filter(scandir(__DIR__) ?: [], function (string $file): bool {
    if (!is_file(__DIR__ . '/' . $file)) {
        return false;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return in_array($ext, ALLOWED_EXTENSIONS, true);
}));

// Newest uploads first
usort($images, fn(string $a, string $b) => filemtime(__DIR__ . '/' . $b) <=> filemtime(__DIR__ . '/' . $a));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Uploaded Images</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f5f5f5; margin: 0; padding: 2rem; }
  h1 { margin-bottom: 0.25rem; }
  p.count { color: #666; margin-top: 0; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
  .card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
  .card img { width: 100%; height: 160px; object-fit: cover; display: block; }
  .card .meta { padding: 0.5rem; font-size: 0.8rem; color: #333; word-break: break-all; }
  .empty { color: #888; margin-top: 2rem; }
  form { margin: 1.5rem 0; }
</style>
</head>
<body>

<h1>Uploaded Images</h1>
<p class="count"><?= count($images) ?> image<?= count($images) === 1 ? '' : 's' ?></p>

<form action="upload.php" method="post" enctype="multipart/form-data">
  <input type="file" name="image" accept="image/*" required>
  <button type="submit">Upload</button>
</form>

<?php if (empty($images)): ?>
  <p class="empty">No images uploaded yet.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($images as $image): ?>
      <div class="card">
        <img src="<?= htmlspecialchars($image, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($image, ENT_QUOTES) ?>">
        <div class="meta"><?= htmlspecialchars($image, ENT_QUOTES) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</body>
</html>
