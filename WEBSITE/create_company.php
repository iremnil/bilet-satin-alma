<?php
session_start();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = trim($_POST['company_name'] ?? '');
    $logo_path    = trim($_POST['logo_path'] ?? '');

    $admin_name   = trim($_POST['admin_full_name'] ?? '');
    $admin_email  = trim($_POST['admin_email'] ?? '');
    $admin_pass   = $_POST['admin_password'] ?? '';

    if ($company_name === '') {
        $error = "Firma adı zorunludur.";
    } else {
        try {
            // Firma adı kontrolü
            $q = $db->prepare("SELECT COUNT(1) FROM firms WHERE LOWER(name)=LOWER(?)");
            $q->execute([$company_name]);
            if ((int)$q->fetchColumn() > 0) {
                throw new Exception("Bu firma adı zaten mevcut.");
            }

            $db->beginTransaction();

            // 1) Firma ekle
            $stmtFirm = $db->prepare("
                INSERT INTO firms (name, logo_path, created_at)
                VALUES (:n, :l, datetime('now','localtime'))
            ");
            $stmtFirm->execute([
                ':n' => $company_name,
                ':l' => $logo_path !== '' ? $logo_path : null
            ]);
            $company_id = (int)$db->lastInsertId();

            // 2) Eğer admin bilgileri doluysa firma admini oluştur
            if ($admin_name !== '' && $admin_email !== '' && $admin_pass !== '') {
                // E-posta kontrolü
                $check = $db->prepare("SELECT COUNT(1) FROM users WHERE LOWER(email)=LOWER(?)");
                $check->execute([$admin_email]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new Exception("Bu e-posta zaten kayıtlı.");
                }

                $hash = password_hash($admin_pass, PASSWORD_DEFAULT);

                $stmtUser = $db->prepare("
                    INSERT INTO users (full_name, email, role, password, company_id, balance, created_at)
                    VALUES (:fn, :em, 'company_admin', :pw, :cid, 800, datetime('now','localtime'))
                ");
                $stmtUser->execute([
                    ':fn' => $admin_name,
                    ':em' => $admin_email,
                    ':pw' => $hash,
                    ':cid'=> $company_id
                ]);
            }

            $db->commit();
            $success = "Firma başarıyla oluşturuldu" . 
                       ($admin_name !== '' ? " ve firma admini eklendi." : ".");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Hata: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Yeni Firma Oluştur</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0; }
        .navbar { background-color:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center; padding:12px 30px; }
        .navbar a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold; }
        .navbar a:hover { text-decoration:underline; }

        .wrap { max-width:800px; margin:24px auto; background:#fff; padding:24px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); }
        h1 { margin-top:0; color:#333; }
        fieldset { border:1px solid #e9ecef; border-radius:10px; margin-bottom:16px; }
        legend { padding:0 8px; color:#555; font-weight:600; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
        label { font-size:13px; color:#444; display:block; margin-bottom:6px; }
        input[type="text"], input[type="email"], input[type="password"] { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:8px; font-size:14px; }
        .actions { display:flex; gap:10px; margin-top:10px; }
        button { background:#007bff; color:#fff; border:none; padding:10px 16px; border-radius:8px; cursor:pointer; font-weight:600; }
        button:hover { background:#0056b3; }
        .msg { margin-bottom:12px; padding:10px 12px; border-radius:8px; }
        .ok { background:#e7f7ed; color:#1e7e34; border:1px solid #a3d9ad; }
        .err { background:#ffe8e8; color:#b02a37; border:1px solid #f5c2c7; }
        small { color:#666; font-size:12px; }
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

    <div class="wrap">
        <h1>Yeni Firma Oluştur</h1>

        <?php if ($success): ?><div class="msg ok"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="msg err"><?php echo $error; ?></div><?php endif; ?>

        <form method="POST">
            <fieldset>
                <legend>Firma Bilgileri</legend>
                <div class="grid">
                    <div>
                        <label>Firma Adı *</label>
                        <input type="text" name="company_name" required>
                    </div>
                    <div>
                        <label>Logo Yolu (opsiyonel)</label>
                        <input type="text" name="logo_path" placeholder="/assets/logos/logo.png">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Firma Admin Bilgileri (İsteğe Bağlı)</legend>
                <small>Bu alanları boş bırakırsanız firma admini daha sonra oluşturulabilir.</small>
                <div class="grid">
                    <div>
                        <label>Ad Soyad</label>
                        <input type="text" name="admin_full_name">
                    </div>
                    <div>
                        <label>E-posta</label>
                        <input type="email" name="admin_email">
                    </div>
                    <div>
                        <label>Şifre</label>
                        <input type="password" name="admin_password">
                    </div>
                </div>
            </fieldset>

            <div class="actions">
                <button type="submit">Oluştur</button>
                <a href="admin_panel.php"><button type="button" style="background:#6c757d;">Vazgeç</button></a>
            </div>
        </form>
    </div>
</body>
</html>
