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
// Firmalar
$firms = $db->query("SELECT id, name FROM firms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Silme -
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $coupon_id = (int)($_POST['coupon_id'] ?? 0);
    try {
        $del = $db->prepare("DELETE FROM coupons WHERE id = :id");
        $del->execute([':id' => $coupon_id]);

        if ($del->rowCount() > 0) $success = "Kupon silindi.";
        else $error = "Kupon bulunamadı veya silinemedi.";
    } catch (Exception $e) {
        $error = "Silme hatası: " . $e->getMessage();
    }
}
// --- Düzenleme kaydetme ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $coupon_id  = (int)($_POST['coupon_id'] ?? 0);
    $code       = strtoupper(trim($_POST['code'] ?? ''));
    $discount   = trim($_POST['discount'] ?? '');
    $company_id = trim($_POST['company_id'] ?? '');
    $usage_limit= trim($_POST['usage_limit'] ?? '');
    $expire_date= trim($_POST['expire_date'] ?? '');

    try {
        if ($coupon_id <= 0) { throw new Exception("Geçersiz kupon."); }
        if ($code === '' || $discount === '' || $expire_date === '') {
            throw new Exception("Lütfen *Kod*, *İndirim* ve *Son Kullanma Tarihi* alanlarını doldurun.");
        }
        if (!is_numeric($discount) || (float)$discount <= 0) {
            throw new Exception("İndirim değeri pozitif bir sayı olmalıdır.");
        }
        $discount = (float)$discount;
        // usage_limit: kullanıcı başı limit (NULL = sınırsız)
        if ($usage_limit === '') {
            $usage_limit_db = null;
        } else {
            if (!ctype_digit($usage_limit) || (int)$usage_limit <= 0) {
                throw new Exception("Kullanıcı başı limit pozitif bir tam sayı olmalıdır.");
            }
            $usage_limit_db = (int)$usage_limit;
        }
        // company_id NULL veya geçerli olmalı
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

        // expire_date format kontrol
        $dt = DateTime::createFromFormat('Y-m-d', $expire_date);
        $validDate = $dt && $dt->format('Y-m-d') === $expire_date;
        if (!$validDate) {
            throw new Exception("Son kullanma tarihi YYYY-MM-DD biçiminde olmalıdır.");
        }
        // CODE benzersiz (kendisi hariç)
        $ex = $db->prepare("SELECT COUNT(1) FROM coupons WHERE code = ? AND id <> ?");
        $ex->execute([$code, $coupon_id]);
        if ((int)$ex->fetchColumn() > 0) {
            throw new Exception("Bu kupon kodu başka bir kayıtla çakışıyor.");
        }
        $upd = $db->prepare("
            UPDATE coupons
            SET code = :code,
                discount = :discount,
                company_id = :company_id,
                usage_limit = :usage_limit,
                expire_date = :expire_date
            WHERE id = :id
        ");
        $upd->bindValue(':code', $code, PDO::PARAM_STR);
        $upd->bindValue(':discount', $discount);
        if ($company_id_db === null) { $upd->bindValue(':company_id', null, PDO::PARAM_NULL); }
        else                         { $upd->bindValue(':company_id', $company_id_db, PDO::PARAM_INT); }
        if ($usage_limit_db === null){ $upd->bindValue(':usage_limit', null, PDO::PARAM_NULL); }
        else                         { $upd->bindValue(':usage_limit', $usage_limit_db, PDO::PARAM_INT); }
        $upd->bindValue(':expire_date', $expire_date, PDO::PARAM_STR);
        $upd->bindValue(':id', $coupon_id, PDO::PARAM_INT);

        $upd->execute();
        if ($upd->rowCount() >= 0) { $success = "Kupon güncellendi."; } // rowCount 0 olabilir: veri aynı
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// --- Düzenleme formu istendi mi? ---
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $e = $db->prepare("SELECT * FROM coupons WHERE id = ?");
    $e->execute([$editId]);
    $editRow = $e->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $error = "Kupon bulunamadı.";
        $editId = 0;
    }
}
// --- Liste ---
$list = $db->query("
    SELECT c.id, c.code, c.discount, c.company_id, c.usage_limit, c.expire_date, c.created_at,
           f.name AS firm_name
    FROM coupons c
    LEFT JOIN firms f ON c.company_id = f.id
    ORDER BY c.created_at DESC, c.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Kuponları Yönet</title>
<style>
    body { font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0; }
    .navbar { background:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center; padding:12px 30px; }
    .navbar a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold; }
    .navbar a:hover { text-decoration:underline; }

    .wrap { width:96%; max-width:980px; margin:24px auto; }
    h1 { color:#333; margin:0 0 16px; }
    .card { background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.08); padding:20px; }

    .msg { margin:12px 0; padding:10px 12px; border-radius:8px; }
    .ok  { background:#e7f7ed; color:#1e7e34; border:1px solid #a3d9ad; }
    .err { background:#ffe8e8; color:#b02a37; border:1px solid #f5c2c7; }

    table { width:100%; border-collapse:collapse; margin-top:10px; background:#fff; }
    th, td { padding:10px; border-bottom:1px solid #eee; text-align:left; }
    th { background:#007bff; color:#fff; position:sticky; top:0; }
    tr:hover { background:#f8fbff; }

    .row { display:flex; gap:14px; }
    .col { flex:1; }
    label { display:block; font-weight:600; margin-bottom:6px; color:#333; }
    input[type="text"], input[type="number"], input[type="date"], select {
        width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:8px;
    }
    .actions { display:flex; gap:6px; }
    .btn { background:#007bff; color:#fff; border:none; padding:8px 12px; border-radius:8px; cursor:pointer; font-weight:600; text-decoration:none; display:inline-block; }
    .btn:hover { background:#0056b3; }
    .btn-danger { background:#dc3545; }
    .btn-danger:hover { background:#b02a37; }
    .btn-secondary { background:#6c757d; }
    .btn-secondary:hover { background:#5c636a; }
    .help { font-size:12px; color:#666; margin-top:6px; }
</style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="admin_panel.php">Admin Paneli</a>
            <a href="create_coupon.php">Yeni Kupon Oluştur</a>
            <a href="manage_coupons.php">Kuponları Yönet</a>
        </div>
        <div class="right">
            Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Çıkış Yap</a>
        </div>
    </div>

    <div class="wrap">
        <h1>Kuponları Yönet</h1>

        <?php if ($success): ?><div class="msg ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="msg err"><?php echo htmlspecialchars($error);   ?></div><?php endif; ?>

        <?php if ($editId > 0 && $editRow): ?>
            <div class="card" style="margin-bottom:16px;">
                <h3>Kuponu Düzenle (#<?php echo (int)$editRow['id']; ?>)</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="coupon_id" value="<?php echo (int)$editRow['id']; ?>">

                    <div class="row">
                        <div class="col">
                            <label for="code">Kupon Kodu *</label>
                            <input type="text" id="code" name="code" value="<?php echo htmlspecialchars($editRow['code']); ?>" required>
                            <div class="help">Benzersiz olmalı. Otomatik olarak BÜYÜK harfe çevrilir.</div>
                        </div>
                        <div class="col">
                            <label for="discount">İndirim (Sayı) *</label>
                            <input type="number" step="0.01" min="0.01" id="discount" name="discount"
                                   value="<?php echo htmlspecialchars((float)$editRow['discount']); ?>" required>
                        </div>
                    </div>

                    <div class="row" style="margin-top:14px;">
                        <div class="col">
                            <label for="company_id">Firma (opsiyonel)</label>
                            <select id="company_id" name="company_id">
                                <option value="">Tüm Firmalar</option>
                                <?php foreach ($firms as $f): ?>
                                    <option value="<?php echo (int)$f['id']; ?>"
                                        <?php echo ($editRow['company_id'] !== null && (int)$editRow['company_id'] === (int)$f['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($f['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">Seçilmezse kupon tüm firmalarda geçerli olur.</div>
                        </div>
                        <div class="col">
                            <label for="usage_limit">Kullanıcı Başı Limit (opsiyonel)</label>
                            <input type="number" min="1" id="usage_limit" name="usage_limit"
                                   value="<?php echo htmlspecialchars($editRow['usage_limit'] ?? ''); ?>">
                            <div class="help">Boş bırakılırsa sınırsız. Doluysa her kullanıcı en fazla bu kadar kez kullanır.</div>
                        </div>
                    </div>

                    <div class="row" style="margin-top:14px;">
                        <div class="col">
                            <label for="expire_date">Son Kullanma Tarihi *</label>
                            <input type="date" id="expire_date" name="expire_date"
                                   value="<?php echo htmlspecialchars($editRow['expire_date']); ?>" required>
                        </div>
                    </div>

                    <div class="actions" style="margin-top:14px;">
                        <button type="submit" class="btn">Kaydet</button>
                        <a href="manage_coupons.php" class="btn btn-secondary">Vazgeç</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Kupon Listesi</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Kod</th>
                        <th>İndirim</th>
                        <th>Firma</th>
                        <th>Kullanıcı Başı Limit</th>
                        <th>Son Tarih</th>
                        <th>Oluşturma</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($list) === 0): ?>
                    <tr><td colspan="8">Hiç kupon yok.</td></tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['code']); ?></td>
                            <td><?php echo number_format((float)$row['discount'], 2); ?> ₺</td>
                            <td><?php echo $row['firm_name'] ? htmlspecialchars($row['firm_name']) : 'Tümü'; ?></td>
                            <td><?php echo $row['usage_limit'] !== null ? (int)$row['usage_limit'] : 'Sınırsız'; ?></td>
                            <td><?php echo htmlspecialchars($row['expire_date']); ?></td>
                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                            <td class="actions">
                                <a class="btn" href="manage_coupons.php?edit=<?php echo (int)$row['id']; ?>">Düzenle</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Bu kuponu silmek istediğinize emin misiniz?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="coupon_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="btn btn-danger">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
