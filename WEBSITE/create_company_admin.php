<?php
session_start();

// Yalnızca admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }

$success = $error = "";

// Firmaları çek
$firms = $db->query("SELECT id, name FROM firms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name  = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $company_id = (int)($_POST['company_id'] ?? 0);

    try {
        if ($full_name === '' || $email === '' || $password === '' || $company_id <= 0) {
            throw new Exception("Tüm alanları doldurun ve firma seçin.");
        }

        // Firma gerçekten var mı kontrol edelım
        $chkFirm = $db->prepare("SELECT COUNT(1) FROM firms WHERE id = ?");
        $chkFirm->execute([$company_id]);
        if ((int)$chkFirm->fetchColumn() === 0) {
            throw new Exception("Seçilen firma bulunamadı.");
        }

        // E-posta benzersiz mi
        $chk = $db->prepare("SELECT COUNT(1) FROM users WHERE LOWER(email) = LOWER(?)");
        $chk->execute([$email]);
        if ((int)$chk->fetchColumn() > 0) {
            throw new Exception("Bu e-posta adresi zaten kayıtlı.");
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $ins = $db->prepare("
            INSERT INTO users (full_name, email, role, password, company_id, balance, created_at)
            VALUES (:fn, :em, 'company_admin', :pw, :cid, 800, datetime('now','localtime'))
        ");
        $ins->execute([
            ':fn'  => $full_name,
            ':em'  => $email,
            ':pw'  => $hash,
            ':cid' => $company_id
        ]);

        $success = "Firma admini başarıyla oluşturuldu.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Firma Admin Oluştur</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0; }
        .navbar {
            background-color:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center;
            padding:12px 30px;
        }
        .navbar a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold; }
        .navbar a:hover { text-decoration:underline; }

        .wrap {
            max-width:720px; margin:24px auto; background:#fff; padding:24px;
            border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08);
        }
        h1 { margin:0 0 16px; color:#333; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
        label { font-size:13px; color:#444; display:block; margin-bottom:6px; }
        input[type="text"], input[type="email"], input[type="password"], select {
            width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:8px; font-size:14px;
        }
        .actions { display:flex; gap:10px; margin-top:16px; }
        button {
            background:#007bff; color:#fff; border:none; padding:10px 16px; border-radius:8px; cursor:pointer; font-weight:600;
        }
        button:hover { background:#0056b3; }
        .btn-secondary { background:#6c757d; }
        .btn-secondary:hover { background:#5c636a; }
        .msg { margin-bottom:12px; padding:10px 12px; border-radius:8px; }
        .ok  { background:#e7f7ed; color:#1e7e34; border:1px solid #a3d9ad; }
        .err { background:#ffe8e8; color:#b02a37; border:1px solid #f5c2c7; }
        small { color:#666; font-size:12px; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="admin_panel.php">Admin Paneli</a>
            <a href="manage_companies.php">Firmaları Yönet</a>
        </div>
        <div class="right">
            Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Çıkış Yap</a>
        </div>
    </div>

    <div class="wrap">
        <h1>Firma Admin Oluştur</h1>

        <?php if ($success): ?><div class="msg ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="msg err"><?php echo htmlspecialchars($error);   ?></div><?php endif; ?>

        <form method="POST">
            <div class="grid">
                <div>
                    <label>Ad Soyad *</label>
                    <input type="text" name="full_name" required>
                </div>
                <div>
                    <label>E-posta *</label>
                    <input type="email" name="email" required>
                </div>
                <div>
                    <label>Şifre *</label>
                    <input type="password" name="password" required>
                </div>
                <div>
                    <label>Firma Seçin *</label>
                    <select name="company_id" required>
                        <option value="">-- Seçiniz --</option>
                        <?php foreach ($firms as $f): ?>
                            <option value="<?php echo (int)$f['id']; ?>">
                                <?php echo htmlspecialchars($f['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Listede firma yoksa önce “Yeni Firma Oluştur” sayfasından ekleyin.</small>
                </div>
            </div>

            <div class="actions">
                <button type="submit">Oluştur</button>
                <a href="admin_panel.php"><button type="button" class="btn-secondary">Vazgeç</button></a>
            </div>
        </form>
    </div>
</body>
</html>
