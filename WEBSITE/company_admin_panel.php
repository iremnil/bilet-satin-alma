<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'company_admin') {
    header("Location: login.php");
    exit();
}
if (!isset($_SESSION['company_id'])) {
    header("Location: login.php");
    exit();
}
$company_id = (int)$_SESSION['company_id'];

try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }

// Firmanın adını header’da göstermek için:
$firm = $db->prepare("SELECT id, name FROM firms WHERE id = ?");
$firm->execute([$company_id]);
$firmRow = $firm->fetch(PDO::FETCH_ASSOC);
if (!$firmRow) {
    die("Firma bulunamadı.");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Firma Admin Paneli</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f8;
            margin: 0;
            padding: 0;
        }
        .navbar {
            background-color: #007bff;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 30px;
        }
        .navbar a {
            color: white;
            text-decoration: none;
            margin: 0 10px;
            font-weight: bold;
        }
        .navbar a:hover { text-decoration: underline; }

        h1 {
            text-align: center;
            color: #333;
            margin-top: 20px;
        }
        .panel {
            width: 90%;
            margin: 20px auto;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .panel h2 { margin-top: 0; }
        .panel a.btn, .panel button.btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 36px;
            padding: 0 14px;
            margin: 10px 8px 0 0;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            color: #fff;
            background-color: #007bff;
            transition: background .15s ease;
        }
        .panel a.btn:hover, .panel button.btn:hover { background-color: #0056b3; }
        .panel .btn-danger { background-color: #dc3545; }
        .panel .btn-danger:hover { background-color: #b02a37; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="company_admin_panel.php">Firma Admin Paneli — <?php echo htmlspecialchars($firmRow['name']); ?></a>
            <a href="account_company.php">Hesabım</a>
        </div>
        <div class="right">
            Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Çıkış Yap</a>
        </div>
    </div>

    <h1>Firma Admin Paneli</h1>

    <div class="panel">
        <h2>Seferler</h2>
        <a href="create_trips.php" class="btn">Yeni Sefer Oluştur</a>
        <a href="manage_trips.php" class="btn">Sefer Düzenle/Sil</a>
    </div>

    <div class="panel">
        <h2>Biletler</h2>
        <a href="manage_company_firma.php" class="btn">Biletleri Görüntüle</a>
        <a href="company_cancel_tickets.php" class="btn btn-danger">Bilet İptal</a>
    </div>
    <div class="panel">
        <h2>Kupon Yönetimi</h2>
        <a href="create_coupon_firma.php" class="btn">Yeni Kupon Oluştur</a>
        <a href="manage_coupons_firma.php" class="btn">Kuponları Düzenle/Sil</a>
    </div>
</body>
</html>
