<?php

/*
 * MailWatch for MailScanner
 * Copyright (C) 2003-2026 MailWatch Team
 *
 * Lightweight, self-contained secure CAPTCHA generator for login protection.
 */

require_once __DIR__ . '/functions.php';

disableBrowserCache();
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Generate 5-character readable code (excluding ambiguous 0, O, 1, I, l)
$chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$length = 5;
$code = '';
for ($i = 0; $i < $length; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}

$_SESSION['login_captcha_code'] = strtolower($code);

$width = 140;
$height = 42;

if (function_exists('imagecreatetruecolor')) {
    $image = imagecreatetruecolor($width, $height);

    // Background color: soft slate-blue
    $bgColor = imagecolorallocate($image, 241, 245, 249);
    imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);

    // Subtle decorative grid lines
    $lineColor = imagecolorallocate($image, 226, 232, 240);
    for ($i = 0; $i < 6; $i++) {
        imageline($image, 0, random_int(0, $height), $width, random_int(0, $height), $lineColor);
    }
    for ($i = 0; $i < 4; $i++) {
        imageline($image, random_int(0, $width), 0, random_int(0, $width), $height, $lineColor);
    }

    // Text colors (deep corporate blues)
    $textColors = [
        imagecolorallocate($image, 31, 108, 176),
        imagecolorallocate($image, 15, 59, 96),
        imagecolorallocate($image, 2, 132, 199),
        imagecolorallocate($image, 30, 41, 59),
    ];

    // Draw characters
    $spacing = (int)(($width - 24) / $length);
    for ($i = 0; $i < $length; $i++) {
        $char = $code[$i];
        $color = $textColors[$i % count($textColors)];
        $x = 12 + ($i * $spacing) + random_int(-2, 2);
        $y = random_int(10, 15);

        // Built-in GD font 5
        imagechar($image, 5, $x, $y, $char, $color);
    }

    // Anti-OCR random noise dots
    for ($i = 0; $i < 60; $i++) {
        $dotColor = imagecolorallocate($image, random_int(160, 200), random_int(180, 220), random_int(200, 240));
        imagesetpixel($image, random_int(0, $width), random_int(0, $height), $dotColor);
    }

    // Subtle border
    $borderColor = imagecolorallocate($image, 203, 213, 225);
    imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

    imagepng($image);
    imagedestroy($image);
} else {
    // Fallback simple SVG
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="42" viewBox="0 0 140 42">
        <rect width="140" height="42" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="1" rx="4"/>
        <text x="50%" y="58%" font-family="monospace" font-size="20" font-weight="bold" fill="#1f6cb0" text-anchor="middle" dominant-baseline="middle" letter-spacing="4">' . htmlspecialchars($code) . '</text>
    </svg>';
}
