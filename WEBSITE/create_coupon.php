<?php
session_start();

// Yalnızca admin erişimi
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

// Firmaları dropdown için çek
$firms = $db->query("SELECT id, name FROM firms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Alanları al
    $code        = strtoupper(trim($_POST['code'] ?? ''));
    $discount    = trim($_POST['discount'] ?? '');
    $company_id  = trim($_POST['company_id'] ?? '');
    $usage_limit = trim($_POST['usage_limit'] ?? ''); // <= KULLANICI BAŞI LİMİT
    $expire_date = trim($_POST['expire_date'] ?? '');

    try {
        if ($code === '' || $discount === '' || $expire_date === '') {
            throw new Exception("Lütfen *Kod*, *İndirim* ve *Son Kullanma Tarihi* alanlarını doldurun.");
        }

        if (!is_numeric($discount) || (float)$discount <= 0) {
            throw new Exception("İndirim değeri pozitif bir sayı olmalıdır.");
        }
        $discount = (float)$discount;

        // usage_limit: kullanıcı başı limit (boş = sınırsız, doluysa 1+ tam sayı olmalı)
        if ($usage_limit === '') {
            $usage_limit_db = null;
        } else {
            if (!ctype_digit($usage_limit) || (int)$usage_limit <= 0) {
                throw new Exception("Kullanıcı başı limit pozitif bir tam sayı olmalıdır.");
            }
            $usage_limit_db = (int)$usage_limit;
        }

        // company_id boşsa NULL; doluysa geçerli firma mı?
        if ($company_id === '') {
            $company_id_db = null;
        } else {
            if (!ctype_digit($company_id)) {
                throw new Exception("Geçersiz firma seçimi.");
            }
            $company_id_db = (int)$company_id;

            $chk = $db->prepare("SELECT COUNT(1) FROM firms WHERE id = ?");
            $chk->execute([$company_id_db]);
            if ((int)$chk->fetchColumn() === 0) {
                throw new Exception("Seçilen firma bulunamadı.");
            }
        }

        // expire_date doğrulaması 
        $dt = DateTime::createFromFormat('Y-m-d', $expire_date);
        $validDate = $dt && $dt->format('Y-m-d') === $expire_date;
        if (!$validDate) {
            throw new Exception("Son kullanma tarihi YYYY-MM-DD biçiminde olmalıdır.");
        }

        // Kupon code UNIQUE kontrolü
        $exists = $db->prepare("SELECT COUNT(1) FROM coupons WHERE code = ?");
        $exists->execute([$code]);
        if ((int)$exists->fetchColumn() > 0) {
            throw new Exception("Bu kupon kodu zaten mevcut.");
        }

        // Ekle
        // usage_limit alanı "Kullanıcı başı limit" olarak kullanılacakmıs (global limit DEĞİL) bunu degıstırdım.
        $ins = $db->prepare("
            INSERT INTO coupons (code, discount, company_id, usage_limit, expire_date, created_at)
            VALUES (:code, :discount, :company_id, :usage_limit, :expire_date, datetime('now','localtime'))
        ");
        $ins->bindValue(':code', $code, PDO::PARAM_STR);
        $ins->bindValue(':discount', $discount);
        if ($company_id_db === null) { $ins->bindValue(':company_id', null, PDO::PARAM_NULL); }
        else                         { $ins->bindValue(':company_id', $company_id_db, PDO::PARAM_INT); }
        if ($usage_limit_db === null){ $ins->bindValue(':usage_limit', null, PDO::PARAM_NULL); }
        else                         { $ins->bindValue(':usage_limit', $usage_limit_db, PDO::PARAM_INT); }
        $ins->bindValue(':expire_date', $expire_date, PDO::PARAM_STR);

        $ins->execute();
        $success = "Kupon başarıyla oluşturuldu.";
        // Formu temizlemek için POST’u sıfırlamak lazım
        $_POST = [];

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Yeni Kupon Oluştur</title>
<style>
    body { font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0; }
    .navbar { background:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center; padding:12px 30px; }
    .navbar a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold; }
    .navbar a:hover { text-decoration:underline; }

    .wrap { width:95%; max-width:720px; margin:24px auto; }
    h1 { color:#333; margin:0 0 16px; }
    .card { background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.08); padding:20px; }
    .row { display:flex; gap:14px; }
    .col { flex:1; }
    label { display:block; font-weight:600; margin-bottom:6px; color:#333; }
    input[type="text"], input[type="number"], input[type="date"], select {
        width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:8px;
    }
    .help { font-size:12px; color:#666; margin-top:6px; }
    .actions { margin-top:18px; display:flex; gap:10px; }
    .btn { background:#007bff; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600; }
    .btn:hover { background:#0056b3; }
    .btn-secondary { background:#6c757d; }
    .btn-secondary:hover { background:#5c636a; }
    .msg { margin:12px 0; padding:10px 12px; border-radius:8px; }
    .ok  { background:#e7f7ed; color:#1e7e34; border:1px solid #a3d9ad; }
    .err { background:#ffe8e8; color:#b02a37; border:1px solid #f5c2c7; }
</style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="admin_panel.php">Admin Paneli</a>
            <a href="manage_coupons.php">Kuponları Yönet</a>
        </div>
        <div class="right">
            Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Çıkış Yap</a>
        </div>
    </div>

    <div class="wrap">
        <h1>Yeni Kupon Oluştur</h1>

        <?php if ($success): ?><div class="msg ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="msg err"><?php echo htmlspecialchars($error);   ?></div><?php endif; ?>

        <div class="card">
            <form method="POST">
                <div class="row">
                    <div class="col">
                        <label for="code">Kupon Kodu *</label>
                        <input type="text" id="code" name="code" placeholder="Örn: HOSGELDIN20"
                               value="<?php echo htmlspecialchars($_POST['code'] ?? ''); ?>" required>
                        <div class="help">Benzersiz olmalı. Otomatik olarak BÜYÜK harfe çevrilir.</div>
                    </div>
                    <div class="col">
                        <label for="discount">İndirim (Sayı) *</label>
                        <input type="number" step="0.01" min="0.01" id="discount" name="discount" placeholder="Örn: 20"
                               value="<?php echo htmlspecialchars($_POST['discount'] ?? ''); ?>" required>
                        <div class="help">Uygulamada TL veya % olarak yorumlanabilir; şimdilik pozitif sayı olarak kaydedilir.</div>
                    </div>
                </div>

                <div class="row" style="margin-top:14px;">
                    <div class="col">
                        <label for="company_id">Firma (opsiyonel)</label>
                        <select id="company_id" name="company_id">
                            <option value="">Tüm Firmalar</option>
                            <?php foreach ($firms as $f): ?>
                                <option value="<?php echo (int)$f['id']; ?>"
                                    <?php echo (isset($_POST['company_id']) && $_POST['company_id'] !== '' && (int)$_POST['company_id'] === (int)$f['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($f['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Seçilmezse kupon tüm firmalarda geçerli olur.</div>
                    </div>
                    <div class="col">
                        <!-- ÖNEMLİ: Kullanıcı başı limit olarak metin ve min güncellendi -->
                        <label for="usage_limit">Kullanıcı Başı Limit (opsiyonel)</label>
                        <input type="number" min="1" id="usage_limit" name="usage_limit" placeholder="Örn: 1 veya 2"
                               value="<?php echo htmlspecialchars($_POST['usage_limit'] ?? ''); ?>">
                        <div class="help">Boş bırakılırsa sınırsız. Doluysa her kullanıcı en fazla bu kadar kez kullanır.</div>
                    </div>
                </div>

                <div class="row" style="margin-top:14px;">
                    <div class="col">
                        <label for="expire_date">Son Kullanma Tarihi *</label>
                        <input type="date" id="expire_date" name="expire_date"
                               value="<?php echo htmlspecialchars($_POST['expire_date'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn">Kaydet</button>
                    <a href="admin_panel.php" class="btn btn-secondary">İptal</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
