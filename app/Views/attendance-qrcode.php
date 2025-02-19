<!-- app/Views/attendance-qrcode.php -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance QR Code Generator</title>
    <style>
        body, html {
            height: 100%;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        body {
            text-align: center;
        }

        img {
            max-width: 100%;
            max-height: 100%;
        }
    </style>
</head>
<body>
    <?php if (isset($qrCodeImage)): ?>
        <h2>Generated QR Code:</h2>
        <img src="<?= $qrCodeImage ?>" alt="QR Code">
    <?php endif; ?>
</body>
</html>