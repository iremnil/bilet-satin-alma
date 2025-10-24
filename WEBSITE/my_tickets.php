<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
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

$user_id = (int)$_SESSION['user_id'];
date_default_timezone_set('Europe/Istanbul');
$nowTr = (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d H:i:s');

/*BİLET İPTAL İŞLEMİ (POST)*/
if (isset($_POST['cancel_ticket'])) {
    $ticket_id = (int)$_POST['ticket_id'];

    try {
        $db->beginTransaction();
        // 1) Bilet gerçekten bu kullanıcıya ait mi ve ACTIVE mi? (iade tutarı için okuyoruz)
        $check = $db->prepare("
            SELECT id, user_id, total_price, status
            FROM tickets
            WHERE id = :id AND user_id = :uid AND status = 'ACTIVE'
            LIMIT 1
        ");
        $check->execute([':id' => $ticket_id, ':uid' => $user_id]);
        $ticket = $check->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            $db->rollBack();
            $message = "Bu bilet iptal edilemez.";
        } else {
            // 2) İPTAL KARARINI TEK UPDATE İÇİNDE VER:
            //    - size ait
            //    - ACTIVE
            //    - kalkışa en az 3600 sn (1 saat) var  --> trips.departure_time - now(TR) >= 3600
            $update = $db->prepare("
                UPDATE tickets
                SET status = 'CANCELLED'
                WHERE id = :id
                  AND user_id = :uid
                  AND status = 'ACTIVE'
                  AND (
                        SELECT strftime('%s', tr.departure_time) - strftime('%s', :now_tr)
                        FROM trips tr
                        WHERE tr.id = tickets.trip_id
                      ) >= 3600
            ");
            $update->execute([':id' => $ticket_id, ':uid' => $user_id, ':now_tr' => $nowTr]);

            if ($update->rowCount() === 0) {
                $db->rollBack();
                $message = "Kalkışa 1 saatten az kaldı veya sefer geçmiş; iptal edilemez.";
            } else {
                // 3) Koltuk kayıtlarını sil (koltuk boşalsın)
                $deleteSeats = $db->prepare("DELETE FROM booked_seats WHERE ticket_id = :tid");
                $deleteSeats->execute([':tid' => $ticket_id]);

                // 4) Kupon kullanımını geri al (kupon yeniden kullanılabilir olsun)
                $delCouponUse = $db->prepare("DELETE FROM user_coupons WHERE ticket_id = :tid");
                $delCouponUse->execute([':tid' => $ticket_id]);

                // 5) Ücreti iade et (total_price indirim sonrası NET tutar)
                $refund = (float)$ticket['total_price'];
                $updBal = $db->prepare("UPDATE users SET balance = balance + :refund WHERE id = :uid");
                $updBal->execute([':refund' => $refund, ':uid' => $user_id]);

                // 6) Commit
                $db->commit();
                $message = "Bilet başarıyla iptal edildi ve " . number_format($refund, 2) . " ₺ iade edildi.";
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $message = "İptal sırasında hata: " . htmlspecialchars($e->getMessage());
    }
}
$query = "
    SELECT t.id AS ticket_id, 
           t.total_price, 
           t.status, 
           t.created_at,
           tr.departure_city, 
           tr.destination_city, 
           tr.departure_time, 
           tr.arrival_time, 
           f.name AS firm
    FROM tickets t
    JOIN trips tr ON t.trip_id = tr.id
    JOIN firms f ON tr.company_id = f.id
    WHERE t.user_id = :user_id
    ORDER BY t.created_at DESC
";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Biletlerim</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; }
        h1 { text-align: center; color: #333; }
        .navbar { background-color: #007bff; color: white; display: flex; justify-content: space-between; align-items: center; padding: 12px 30px; }
        .navbar .left a, .navbar .right a { color: white; text-decoration: none; margin: 0 10px; font-weight: bold; }
        .navbar .left a:hover, .navbar .right a:hover { text-decoration: underline; }
        table { width: 90%; margin: 0 auto 24px auto; border-collapse: collapse; background-color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; text-align: center; border-bottom: 1px solid #eee; vertical-align: middle; }
        th { background-color: #007bff; color: white; }
        tr:hover { background-color: #f8fafc; }
        p { text-align: center; color: #555; font-size: 16px; }
        .message { width: 90%; margin: 12px auto; text-align: center; color: green; font-weight: bold; background: #e7f7ed; border: 1px solid #a3d9ad; padding: 10px 12px; border-radius: 8px; }
        .cancel-btn { background-color: #dc3545; color: white; border: none; padding: 6px 10px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .cancel-btn:hover { background-color: #b02a37; }
        .pdf-btn { display:inline-block; background:#0ea5e9; color:#fff; padding:6px 10px; border-radius:5px; text-decoration:none; font-weight:600; }
        .pdf-btn:hover { background:#0284c7; }
        .actions { display:flex; gap:8px; justify-content:center; align-items:center; flex-wrap:wrap; }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="left">
            <a href="index.php">Ana Sayfa</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="my_tickets.php">Biletlerim</a>
                <a href="account_user.php">Hesabım</a>
            <?php endif; ?>
        </div>

        <div class="right">
            <?php if (isset($_SESSION['username'])): ?>
                Hoşgeldiniz, <?php echo htmlspecialchars($_SESSION['username']); ?> |
                <a href="logout.php">Çıkış Yap</a>
            <?php else: ?>
                <a href="login.php">Giriş Yap</a> | 
                <a href="register.php">Kayıt Ol</a>
            <?php endif; ?>
        </div>
    </div>

    <h1>Biletlerim</h1>

    <?php if (isset($message)) echo "<div class='message'>".htmlspecialchars($message)."</div>"; ?>

    <?php if ($tickets && count($tickets) > 0): ?>
        <table>
            <tr>
                <th>Firma</th>
                <th>Kalkış</th>
                <th>Varış</th>
                <th>Kalkış</th>
                <th>Varış</th>
                <th>Ücret</th>
                <th>Durum</th>
                <th>İşlem</th>
                <th>Bilet PDF</th>
            </tr>
            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td><?php echo htmlspecialchars($t['firm']); ?></td>
                    <td><?php echo htmlspecialchars($t['departure_city']); ?></td>
                    <td><?php echo htmlspecialchars($t['destination_city']); ?></td>
                    <td><?php echo htmlspecialchars($t['departure_time']); ?></td>
                    <td><?php echo htmlspecialchars($t['arrival_time']); ?></td>
                    <td><?php echo number_format((float)$t['total_price'], 2); ?> ₺</td>
                    <td><?php echo htmlspecialchars($t['status']); ?></td>
                    <td>
                        <?php if ($t['status'] === 'ACTIVE'): ?>
                            <form method="POST" class="actions" onsubmit="return confirm('Bu bileti iptal etmek istediğinize emin misiniz?');">
                                <input type="hidden" name="ticket_id" value="<?php echo (int)$t['ticket_id']; ?>">
                                <button type="submit" name="cancel_ticket" class="cancel-btn">İptal Et</button>
                            </form>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="pdf-btn" target="_blank" href="ticket_print.php?ticket_id=<?php echo (int)$t['ticket_id']; ?>">PDF</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>Henüz hiç biletiniz yok.</p>
    <?php endif; ?>
</body>
</html>
