<!DOCTYPE html>
<html>
<head>
    <title>Email Verification</title>
    <style>
        body {
            font-family: Arial;
            background: #f4f6f9;
            text-align: center;
            padding-top: 80px;
        }
        .card {
            background: white;
            padding: 40px;
            width: 420px;
            margin: auto;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .success { color: #28a745; font-size: 22px; }
        .info    { color: #17a2b8; font-size: 22px; }
        .error   { color: #dc3545; font-size: 22px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 class="<?= esc($type) ?>">
            <?= esc($title) ?>
        </h2>
        <p><?= esc($message) ?></p>
    </div>
</body>
</html>
