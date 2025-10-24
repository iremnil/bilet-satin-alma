<?php
session_start();

// Sadece admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();}
// ✅ DB: Docker + lokal uyumlu yol
date_default_timezone_set('Europe/Istanbul');
$sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
$db = new PDO('sqlite:' . $sqlitePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// FK'ler açıksa 
$db->exec("PRAGMA foreign_keys = ON;");
$db->exec("PRAGMA busy_timeout = 3000;");

$success = $error = "";
/* ---- POST İşlemleri ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update') {
            $id        = (int)($_POST['id'] ?? 0);
            $name      = trim($_POST['name'] ?? '');
            $logo_path = trim($_POST['logo_path'] ?? '');
            if ($id <= 0 || $name === '') {
                throw new Exception("Geçersiz veri: Firma adı zorunludur.");
            }
            //Firma adı benzersiz kontrolü
            $q = $db->prepare("SELECT COUNT(1) FROM firms WHERE LOWER(name)=LOWER(?) AND id<>?");
            $q->execute([$name, $id]);
            if ((int)$q->fetchColumn() > 0) {
                throw new Exception("Bu firma adı zaten kayıtlı.");
            }

            $stmt = $db->prepare("
                UPDATE firms
                   SET name = :n,
                       logo_path = :l
                 WHERE id = :id
            ");
            $stmt->execute([
                ':n'  => $name,
                ':l'  => $logo_path !== '' ? $logo_path : null,
                ':id' => $id
            ]);

            $success = "Firma güncellendi.";
        }

        if ($action === 'delete') {
            $companyId = (int)($_POST['id'] ?? 0);
            if ($companyId <= 0) throw new Exception("Geçersiz firma.");

            // --- Güvenli işlem: tek transaction ---
            $db->beginTransaction();

            // 1) Bu firmaya ait seferler
            $tripIdsStmt = $db->prepare("SELECT id FROM trips WHERE company_id = ?");
            $tripIdsStmt->execute([$companyId]);
            $tripIds = $tripIdsStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($tripIds)) {
                $inTrips = implode(',', array_map('intval', $tripIds));

                // --- İADE: ACTIVE biletleri CANCELLED yap + users.balance'a ekle ---
                // Kullanıcı başına toplam iade tutarı
                $refundStmt = $db->query("
                    SELECT user_id, SUM(total_price) AS refund_amount
                      FROM tickets
                     WHERE trip_id IN ($inTrips) AND status='ACTIVE'
                     GROUP BY user_id
                ");
                $refunds = $refundStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($refunds)) {
                    // Bakiye güncelle
                    $updBal = $db->prepare("
                        UPDATE users
                           SET balance = COALESCE(balance, 0) + :amt
                         WHERE id = :uid
                    ");

                    // Biletleri ACTIVE -> CANCELLED
                    $cancelTickets = $db->prepare("
                        UPDATE tickets
                           SET status='CANCELLED'
                         WHERE user_id = :uid
                           AND trip_id IN ($inTrips)
                           AND status='ACTIVE'
                    ");

                    foreach ($refunds as $r) {
                        $uid = (int)$r['user_id'];
                        $amt = (float)$r['refund_amount'];
                        if ($uid > 0 && $amt > 0) {
                            $updBal->execute([':amt' => $amt, ':uid' => $uid]);
                            $cancelTickets->execute([':uid' => $uid]);
                        }
                    }
                }

                // --- Silme akışı 
                // booked_seats -> tickets -> trips
                $db->exec("
                    DELETE FROM booked_seats 
                     WHERE ticket_id IN (
                        SELECT id FROM tickets WHERE trip_id IN ($inTrips)
                     )
                ");
                $db->exec("DELETE FROM tickets WHERE trip_id IN ($inTrips)");

                $delTrips = $db->prepare("DELETE FROM trips WHERE company_id = ?");
                $delTrips->execute([$companyId]);
            }

            // 2) Bu firmaya bağlı company_admin kullanıcılarının company_id'sini NULL yap
            $unsetAdmins = $db->prepare("
                UPDATE users 
                   SET company_id = NULL 
                 WHERE role = 'company_admin' AND company_id = ?
            ");
            $unsetAdmins->execute([$companyId]);

            // 3) Firma sil
            $delFirm = $db->prepare("DELETE FROM firms WHERE id = ?");
            $delFirm->execute([$companyId]);

            $db->commit();
            $success = "İadeler tamamlandı, bağlı kayıtlar temizlendi ve firma silindi.";
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

/* ---- Liste ---- */
$rows = $db->query("
    SELECT 
        f.id,
        f.name,
        f.logo_path,
        f.created_at,
        COALESCE(t.c, 0)  AS trip_count,
        COALESCE(u.c, 0)  AS admin_count
    FROM firms f
    LEFT JOIN (
        SELECT company_id, COUNT(*) c
        FROM trips
        GROUP BY company_id
    ) t ON t.company_id = f.id
    LEFT JOIN (
        SELECT company_id, COUNT(*) c
        FROM users
        WHERE role='company_admin'
        GROUP BY company_id
    ) u ON u.company_id = f.id
    ORDER BY f.created_at DESC, f.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Firmaları Yönet</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0; }
        .navbar {
            background-color:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center;
            padding:12px 30px;
        }
        .navbar a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold; }
        .navbar a:hover { text-decoration:underline; }

        .wrap { width:92%; max-width:1100px; margin:22px auto; }
        h1 { color:#333; margin:0 0 14px; }

        .msg { margin:10px 0; padding:10px 12px; border-radius:8px; }
        .ok  { background:#e7f7ed; color:#1e7e34; border:1px solid #a3d9ad; }
        .err { background:#ffe8e8; color:#b02a37; border:1px solid #f5c2c7; }

        table {
            width:100%; border-collapse:collapse; background:#fff;
            box-shadow:0 2px 10px rgba(0,0,0,.08); border-radius:10px; overflow:hidden;
        }
        th, td { padding:12px 14px; text-align:center; border-bottom:1px solid #eee; }
        th { background:#007bff; color:#fff; }
        tr:hover { background:#f8f9fa; }
        .actions { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
        input[type="text"] {
            width: 100%; max-width: 280px; padding:8px 10px; border:1px solid #ccd; border-radius:6px; font-size:14px;
        }
        .btn {
            padding:8px 12px; border:none; border-radius:6px; cursor:pointer; font-weight:600; color:#fff;
        }
        .btn-save { background:#198754; }
        .btn-save:hover { background:#146c43; }
        .btn-del { background:#dc3545; }
        .btn-del:hover { background:#b02a37; }
        .pill { display:inline-block; padding:2px 8px; border-radius:12px; font-size:12px; font-weight:700; }
        .pill-trips { background:#e8f3ff; color:#0b5ed7; }
        .pill-admin { background:#fff3cd; color:#664d03; }
        .logo-cell { max-width:340px; }
        .muted { color:#666; font-size:12px; }
        @media (max-width: 860px) {
            td.logo-cell input { max-width: 200px; }
        }
        .inline { display:inline; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="admin_panel.php">Admin Paneli</a>
            <a href="create_company.php">Yeni Firma Oluştur</a>
        </div>
        <div class="right">
            Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
            <a href="logout.php">Çıkış Yap</a>
        </div>
    </div>

    <div class="wrap">
        <h1>Firmaları Yönet</h1>

        <?php if ($success): ?><div class="msg ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <?php if (count($rows) === 0): ?>
            <div class="msg">Kayıtlı firma bulunamadı. <a href="create_company.php">Yeni firma ekle</a></div>
        <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Firma Adı</th>
                <th>Logo Yolu</th>
                <th>Oluşturulma</th>
                <th>Sefer</th>
                <th>Firma Admin</th>
                <th>İşlem</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td>
                        <form method="post" class="inline">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <input type="text" name="name" value="<?php echo htmlspecialchars($r['name']); ?>" required>
                    </td>
                    <td class="logo-cell">
                            <input type="text" name="logo_path" placeholder="örn. /assets/logos/aaa.png"
                                   value="<?php echo htmlspecialchars($r['logo_path'] ?? ''); ?>">
                    </td>
                    <td>
                        <div><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></div>
                        <div class="muted">(#<?php echo (int)$r['id']; ?>)</div>
                    </td>
                    <td><span class="pill pill-trips"><?php echo (int)$r['trip_count']; ?></span></td>
                    <td><span class="pill pill-admin"><?php echo (int)$r['admin_count']; ?></span></td>
                    <td>
                        <div class="actions">
                            <button type="submit" class="btn btn-save">Kaydet</button>
                        </form>
                            <form method="post" class="inline" onsubmit="
                                return confirm(
                                    'Bu firmayı silmek üzeresiniz.\n' +
                                    'Bağlı sefer sayısı: <?php echo (int)$r['trip_count']; ?>\n' +
                                    'Bağlı firma admin sayısı: <?php echo (int)$r['admin_count']; ?>\n\n' +
                                    'Devam ederseniz, bu firmaya ait tüm seferlerin biletleri ve koltuk kayıtları da silinir.\n' +
                                    'Silmeden önce ACTIVE biletler CANCELLED yapılacak ve kullanıcı bakiyelerine iade eklenecektir.\n' +
                                    'Firma adminlerinin company_id alanı boşaltılacaktır.\n\n' +
                                    'Emin misiniz?'
                                );
                            ">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" class="btn btn-del">Sil</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>
