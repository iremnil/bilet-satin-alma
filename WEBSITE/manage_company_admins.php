<?php
session_start();

// Yalnızca admin erişimi
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

// ✅ DB: Docker + lokal uyumlu yol
date_default_timezone_set('Europe/Istanbul');
$sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
$db = new PDO('sqlite:' . $sqlitePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// FK'ler açıksa 
$db->exec("PRAGMA foreign_keys = ON;");
$db->exec("PRAGMA busy_timeout = 3000;");


$success = $error = "";

// Firmaları çek (dropdownlar için)
$firms = $db->query("SELECT id, name FROM firms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$firmsById = [];
foreach ($firms as $f) { $firmsById[(int)$f['id']] = $f['name']; }

// Firma değiştir (update company_id)
if (isset($_POST['action']) && $_POST['action'] === 'reassign') {
    $uid  = (int)($_POST['user_id'] ?? 0);
    $cid  = (int)($_POST['company_id'] ?? 0);

    try {
        if ($uid <= 0 || $cid <= 0) {
            throw new Exception("Geçersiz kullanıcı ya da firma.");
        }

        // Kullanıcı company_admin mı kontrol edioz
        $chk = $db->prepare("SELECT COUNT(1) FROM users WHERE id = ? AND role = 'company_admin'");
        $chk->execute([$uid]);
        if ((int)$chk->fetchColumn() === 0) {
            throw new Exception("Kullanıcı bulunamadı ya da company_admin değil.");
        }

        // Firma gerçekten var mı?
        $chkF = $db->prepare("SELECT COUNT(1) FROM firms WHERE id = ?");
        $chkF->execute([$cid]);
        if ((int)$chkF->fetchColumn() === 0) {
            throw new Exception("Seçilen firma bulunamadı.");
        }

        $upd = $db->prepare("UPDATE users SET company_id = :cid WHERE id = :uid");
        $upd->execute([':cid' => $cid, ':uid' => $uid]);

        $success = "Firma admininin firması güncellendi.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Firma admin sil
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $uid = (int)($_POST['user_id'] ?? 0);
    try {
        if ($uid <= 0) throw new Exception("Geçersiz kullanıcı.");

        // Sadece company_admin silinsin
        $chk = $db->prepare("SELECT role FROM users WHERE id = ?");
        $chk->execute([$uid]);
        $role = $chk->fetchColumn();
        if ($role !== 'company_admin') {
            throw new Exception("Sadece company_admin kullanıcıları silebilirsiniz.");
        }

        $del = $db->prepare("DELETE FROM users WHERE id = ?");
        $del->execute([$uid]);

        $success = "Firma admini başarıyla silindi.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

//  Arama filtresi
$search = trim($_GET['q'] ?? '');

// Liste: company_admin’lar
$sql = "
    SELECT u.id, u.full_name, u.email, u.company_id, u.created_at,
           f.name AS firm_name
    FROM users u
    LEFT JOIN firms f ON u.company_id = f.id
    WHERE u.role = 'company_admin'
";
$params = [];
if ($search !== '') {
    $sql .= " AND (LOWER(u.full_name) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(f.name) LIKE :q)";
    $params[':q'] = '%'.mb_strtolower($search, 'UTF-8').'%';
}
$sql .= " ORDER BY u.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Firma Adminlerini Yönet</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0; }
    .navbar { background-color:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center; padding:12px 30px; }
    .navbar a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold; }
    .navbar a:hover { text-decoration:underline; }

    .wrap { width:95%; max-width:1100px; margin:24px auto; }
    h1 { color:#333; margin: 0 0 16px; }
    .msg { margin-bottom:12px; padding:10px 12px; border-radius:8px; }
    .ok  { background:#e7f7ed; color:#1e7e34; border:1px solid #a3d9ad; }
    .err { background:#ffe8e8; color:#b02a37; border:1px solid #f5c2c7; }

    .toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
    .toolbar form { display:flex; gap:8px; }
    .toolbar input[type="text"] { padding:8px 10px; border:1px solid #ccc; border-radius:8px; min-width:260px; }
    .toolbar button, .btn { background:#007bff; color:#fff; border:none; padding:8px 12px; border-radius:8px; cursor:pointer; font-weight:600; }
    .toolbar button:hover, .btn:hover { background:#0056b3; }
    .btn-danger { background:#dc3545; }
    .btn-danger:hover { background:#b02a37; }
    .btn-secondary { background:#6c757d; }
    .btn-secondary:hover { background:#5c636a; }

    table { width:100%; border-collapse:collapse; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.08); border-radius:10px; overflow:hidden; }
    th, td { padding:12px 14px; border-bottom:1px solid #eee; text-align:center; }
    th { background:#007bff; color:#fff; }
    tr:hover { background:#f8f9fa; }

    select, input[type="email"], input[type="text"] { padding:8px 10px; border:1px solid #ccc; border-radius:8px; }
    .actions { display:flex; gap:8px; justify-content:center; align-items:center; flex-wrap:wrap; }
    .firm-select-form { display:flex; gap:8px; align-items:center; justify-content:center; }
</style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="admin_panel.php">Admin Paneli</a>
            <a href="create_company.php">Yeni Firma</a>
            <a href="create_company_admin.php">Yeni Firma Admin</a>
        </div>
        <div class="right">
            Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Çıkış Yap</a>
        </div>
    </div>

    <div class="wrap">
        <h1>Firma Adminlerini Yönet</h1>

        <?php if ($success): ?><div class="msg ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="msg err"><?php echo htmlspecialchars($error);   ?></div><?php endif; ?>

        <div class="toolbar">
            <form method="GET">
                <input type="text" name="q" placeholder="İsim, e-posta veya firma ara…" value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit">Ara</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-secondary" href="manage_company_admins.php">Temizle</a>
                <?php endif; ?>
            </form>
            <div>
                <a class="btn" href="create_company_admin.php">+ Yeni Firma Admin</a>
            </div>
        </div>

        <table>
            <tr>
                <th>ID</th>
                <th>Ad Soyad</th>
                <th>E-posta</th>
                <th>Firma</th>
                <th>Oluşturulma</th>
                <th>İşlemler</th>
            </tr>
            <?php if (count($admins) === 0): ?>
                <tr><td colspan="6">Kayıt bulunamadı.</td></tr>
            <?php else: ?>
                <?php foreach ($admins as $a): ?>
                    <tr>
                        <td><?php echo (int)$a['id']; ?></td>
                        <td><?php echo htmlspecialchars($a['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($a['email']); ?></td>
                        <td>
                            <form method="POST" class="firm-select-form">
                                <input type="hidden" name="action" value="reassign">
                                <input type="hidden" name="user_id" value="<?php echo (int)$a['id']; ?>">
                                <select name="company_id" required>
                                    <option value="">-- Firma Seç --</option>
                                    <?php foreach ($firms as $f): ?>
                                        <option value="<?php echo (int)$f['id']; ?>"
                                            <?php echo ((int)$a['company_id'] === (int)$f['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($f['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn">Kaydet</button>
                            </form>
                        </td>
                        <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                        <td class="actions">
                            <form method="POST" onsubmit="return confirm('Bu firma adminini silmek istediğinize emin misiniz?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo (int)$a['id']; ?>">
                                <button type="submit" class="btn-danger">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>
