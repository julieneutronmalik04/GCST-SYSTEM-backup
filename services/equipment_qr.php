<?php
require_once __DIR__ . '/../modules/qr_generator.php';
function saveEquipmentQr(PDO $pdo, int $id, string $code): string {
    if ($id < 1 || trim($code) === '') throw new InvalidArgumentException('Equipment ID and code are required.');
    $filename = 'equipment-' . $id . '-' . substr(hash('sha256', $code), 0, 20);
    $path = 'qrcodes/' . $filename . '.png';
    $file = __DIR__ . '/../' . $path;
    if (!is_file($file) || @getimagesize($file) === false) {
        if (generateQR($code, $filename) === false) throw new RuntimeException('Unable to generate equipment QR code.');
    }
    $pdo->prepare('UPDATE equipment SET qr_string = ?, qr_image_path = ? WHERE id = ?')->execute([$code, $path, $id]);
    return $path;
}
