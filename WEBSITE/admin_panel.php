<?php
session_start();

// Sadece admin girebilsin kontrol sagladım
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
$db = new PDO('sqlite:' . $sqlitePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA foreign_keys = ON");
$db->exec("PRAGMA busy_timeout = 3000");

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Paneli</title>
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
        .panel a.btn {
            display: inline-block;
            margin: 10px 8px 0 0;
            padding: 8px 12px;
            background-color: #007bff;
            color: white;
            border-radius: 6px;
            text-decoration: none;
        }
        .panel a.btn:hover { background-color: #0056b3; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="admin_panel.php">Admin Paneli</a>
            
        </div>
        <div class="right">
            Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Çıkış Yap</a>
        </div>
    </div>

    <h1>Admin Paneli</h1>

    <div class="panel">
        <h2>Firma Yönetimi</h2>
        <a href="create_company.php" class="btn">Yeni Firma Oluştur</a>
        <a href="manage_companies.php" class="btn">Firmaları Düzenle/Sil</a>
    </div>

    <div class="panel">
        <h2>Firma Admin Kullanıcıları</h2>
        <a href="create_company_admin.php" class="btn">Yeni Firma Admin Oluştur</a>
        <a href="manage_company_admins.php" class="btn">Firma Adminlerini Yönet</a>
    </div>

    <div class="panel">
        <h2>Kupon Yönetimi</h2>
        <a href="create_coupon.php" class="btn">Yeni Kupon Oluştur</a>
        <a href="manage_coupons.php" class="btn">Kuponları Düzenle/Sil</a>
    </div>

    <!-- Sadece sefer görüntüleme duzenleme yapmıyoruz -->
    <div class="panel">
        <h2>Seferleri Görüntüle</h2>
        <a href="trips_list.php" class="btn">Tüm Seferleri Gör</a>
    </div>
</body>
</html>
