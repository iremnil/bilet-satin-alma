<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

date_default_timezone_set('Europe/Istanbul');

try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }


$trip_id     = isset($_POST['trip_id']) ? (int)$_POST['trip_id'] : 0;
$seat_number = isset($_POST['selected_seat']) ? (int)$_POST['selected_seat'] : 0;
$coupon_id   = (isset($_POST['coupon_id']) && $_POST['coupon_id'] !== '') ? (int)$_POST['coupon_id'] : null;

if ($trip_id <= 0) {
    echo "Geçersiz istek.";
    exit();
}

$user_id = (int)$_SESSION['user_id'];

$userStmt = $db->prepare("SELECT full_name, balance FROM users WHERE id = ?");
$userStmt->execute([$user_id]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC);

$tripStmt = $db->prepare("
    SELECT trips.*, firms.name AS company_name
    FROM trips
    JOIN firms ON trips.company_id = firms.id
    WHERE trips.id = ?
");
$tripStmt->execute([$trip_id]);
$tripData = $tripStmt->fetch(PDO::FETCH_ASSOC);

if (!$tripData) {
    echo "Sefer bulunamadı.";
    exit();
}

if ($seat_number <= 0) {
    echo "<div class='box'>";
    echo "<h3>Lütfen önce bir koltuk seçin!</h3>";
    echo "<p>Kullanıcı: " . htmlspecialchars($userData['full_name'] ?? '-') . " | Bakiye: " . number_format((float)($userData['balance'] ?? 0), 2) . " ₺</p>";
    echo "<p>Sefer: " . htmlspecialchars($tripData['company_name']) . " - " . htmlspecialchars($tripData['departure_city'] . " → " . $tripData['destination_city']) . "</p>";
    echo "<p>Tarih: " . htmlspecialchars($tripData['departure_time']) . " | Fiyat: " . number_format((float)$tripData['price'], 2) . " ₺</p>";
    echo "<a href='seat_selection.php?trip_id={$trip_id}'>Koltuk Seçimine Dön</a>";
    echo "</div>";
    exit();
}

try {
    $db->beginTransaction();

    $nowTr = (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d H:i:s');
    $diffStmt = $db->prepare("SELECT (strftime('%s', departure_time) - strftime('%s', :now)) AS diff FROM trips WHERE id = :tid");
    $diffStmt->execute([':now' => $nowTr, ':tid' => $trip_id]);
    $diff = (int)$diffStmt->fetchColumn();
    if ($diff <= 0) {
        throw new Exception("Sefer tarihi geçmiş.");
    }

    $discountAmount = 0.0;
    $appliedCoupon  = null;

    if ($coupon_id !== null) {
        $cStmt = $db->prepare("
            SELECT id, code, discount, company_id, usage_limit, expire_date
            FROM coupons
            WHERE id = :cid
              AND date(expire_date) >= date('now','localtime')
              AND (company_id = :cmp OR company_id IS NULL)
            LIMIT 1
        ");
        $cStmt->execute([
            ':cid' => $coupon_id,
            ':cmp' => (int)$tripData['company_id']
        ]);
        $coupon = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$coupon) {
            throw new Exception("Seçtiğiniz kupon geçerli değil veya süresi dolmuş.");
        }

        if ($coupon['usage_limit'] !== null) {
            $limit = (int)$coupon['usage_limit'];
            $usedCountStmt = $db->prepare("SELECT COUNT(*) FROM user_coupons WHERE coupon_id = ? AND user_id = ?");
            $usedCountStmt->execute([(int)$coupon['id'], $user_id]);
            $usedByUser = (int)$usedCountStmt->fetchColumn();
            if ($usedByUser >= $limit) {
                throw new Exception("Bu kupon en fazla {$limit} kez kullanılabilir.");
            }
        }

        $discountAmount = max(0.0, (float)$coupon['discount']);
        $appliedCoupon  = $coupon;
    }

    $availStmt = $db->prepare("
        SELECT :cap - (
            SELECT COUNT(bs.id)
            FROM tickets t
            JOIN booked_seats bs ON bs.ticket_id = t.id
            WHERE t.trip_id = :tid AND t.status = 'ACTIVE'
        )
    ");
    $availStmt->execute([':cap' => (int)$tripData['capacity'], ':tid' => $trip_id]);
    $remaining = (int)$availStmt->fetchColumn();
    if ($remaining <= 0) {
        throw new Exception("Sefer dolu.");
    }

    $seatFreeStmt = $db->prepare("
        SELECT NOT EXISTS (
            SELECT 1
            FROM booked_seats bs
            JOIN tickets t ON t.id = bs.ticket_id
            WHERE t.trip_id = :tid
              AND t.status = 'ACTIVE'
              AND bs.seat_number = :seat
        )
    ");
    $seatFreeStmt->execute([':tid' => $trip_id, ':seat' => $seat_number]);
    $seatFree = (int)$seatFreeStmt->fetchColumn();
    if ($seatFree !== 1) {
        throw new Exception("Seçilen koltuk dolu.");
    }

    $basePrice  = (float)$tripData['price'];
    $finalPrice = $basePrice - $discountAmount;
    if ($finalPrice < 0) $finalPrice = 0.0;

    $balStmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
    $balStmt->execute([$user_id]);
    $currentBalance = (float)$balStmt->fetchColumn();

    if ($currentBalance < $finalPrice) {
        throw new Exception("Yetersiz bakiye (Gerekli: " . number_format($finalPrice,2) . " ₺).");
    }

    $updBal = $db->prepare("UPDATE users SET balance = balance - :amt WHERE id = :uid");
    $updBal->execute([':amt' => $finalPrice, ':uid' => $user_id]);

    $insTicket = $db->prepare("
        INSERT INTO tickets (trip_id, user_id, status, total_price, created_at)
        VALUES (:tid, :uid, 'ACTIVE', :price, datetime('now','localtime'))
    ");
    $insTicket->execute([
        ':tid'   => $trip_id,
        ':uid'   => $user_id,
        ':price' => $finalPrice
    ]);
    $ticket_id = (int)$db->lastInsertId();

    $insSeat = $db->prepare("
        INSERT INTO booked_seats (ticket_id, seat_number, created_at)
        VALUES (:tk, :seat, datetime('now','localtime'))
    ");
    $insSeat->execute([':tk' => $ticket_id, ':seat' => $seat_number]);

    if ($appliedCoupon !== null) {
        $ucIns = $db->prepare("
            INSERT INTO user_coupons (coupon_id, user_id, ticket_id, created_at)
            VALUES (:cid, :uid, :tk, datetime('now','localtime'))
        ");
        $ucIns->execute([
            ':cid' => (int)$appliedCoupon['id'],
            ':uid' => $user_id,
            ':tk'  => $ticket_id
        ]);
    }

    $db->commit();

    $newBalance = $currentBalance - $finalPrice;

    echo "<div class='box'>";
    echo "<h3>Bilet başarıyla satın alındı!</h3>";
    echo "<p>Sefer: " . htmlspecialchars($tripData['company_name']) . " - " . htmlspecialchars($tripData['departure_city'] . " → " . $tripData['destination_city']) . "</p>";
    echo "<p>Koltuk: " . (int)$seat_number . "</p>";
    if ($appliedCoupon !== null) {
        $d = number_format($discountAmount, 2);
        echo "<p>Kupon: " . htmlspecialchars($appliedCoupon['code']) . " (İndirim: {$d} ₺)</p>";
    }
    echo "<p>Ödenen Tutar: " . number_format($finalPrice, 2) . " ₺</p>";
    echo "<p>Kalan Bakiye: " . number_format($newBalance, 2) . " ₺</p>";
    echo "<a href='index.php'>Ana Sayfaya Dön</a>";
    echo "</div>";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo "<div class='box error'>";
    echo "<h3>Satın alma gerçekleştirilemedi.</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<a href='seat_selection.php?trip_id=" . (int)$trip_id . "'>Koltuk Seçimine Dön</a>";
    echo "</div>";
}
?>

<!-- CSS KISMI -->
<style>
    body {
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background-color: #fff;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        margin: 0;
        padding: 20px;
    }

    .box {
        max-width: 500px;
        width: 100%;
        background: #f9f9f9;
        border: 1px solid #ddd;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
    }

    .box h3 {
        margin-top: 0;
        margin-bottom: 15px;
        color: #333;
    }

    .box p {
        margin: 8px 0;
        font-size: 15px;
        color: #444;
    }

    .box a {
        display: inline-block;
        margin-top: 15px;
        padding: 10px 16px;
        background: #4a90e2;
        color: #fff;
        text-decoration: none;
        border-radius: 6px;
        transition: background 0.3s;
        font-size: 15px;
    }

    .box a:hover {
        background: #3a78bf;
    }

    .error h3 {
        color: #c0392b;
    }
</style>
