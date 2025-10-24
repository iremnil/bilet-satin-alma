<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
$db = new PDO('sqlite:' . $sqlitePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA foreign_keys = ON");
$db->exec("PRAGMA busy_timeout = 3000");


if (!isset($_GET['id'])) {
    echo "Geçersiz istek.";
    exit();
}

$trip_id = (int)$_GET['id'];

$stmt = $db->prepare("
    SELECT 
        trips.*, 
        firms.name AS company_name,
        trips.capacity - COALESCE((
            SELECT COUNT(bs.id)
            FROM tickets t
            JOIN booked_seats bs ON bs.ticket_id = t.id
            WHERE t.trip_id = trips.id AND t.status = 'ACTIVE'
        ), 0) AS available_seats
    FROM trips
    JOIN firms ON trips.company_id = firms.id
    WHERE trips.id = ?
");
$stmt->execute([$trip_id]);
$trip = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trip) {
    echo "Sefer bulunamadı.";
    exit();
}

/*İstanbul saatine göre geçmiş mi konreol ettık*/
date_default_timezone_set('Europe/Istanbul');
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
$dep = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trip['departure_time'], new DateTimeZone('Europe/Istanbul'));
if (!$dep) { // olası farklı formatlar için
    $dep = new DateTimeImmutable($trip['departure_time'], new DateTimeZone('Europe/Istanbul'));
}
$isPast = ($dep <= $now);

/*Kalan koltuk sayısı */
$available = max(0, (int)$trip['available_seats']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Bilet Satın Al</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f2f5;
            text-align: center;
            padding: 20px;
        }
        .ticket-box {
            display: inline-block;
            background-color: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        button {
            padding: 10px 16px;
            font-size: 14px;
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
            margin-top: 15px;
            border-radius: 5px;
        }
        button:hover { background-color: #0056b3; }
        h2 { color: #333; }
        .warn { color:#b02a37; font-weight:600; margin-top:12px; }
    </style>
</head>
<body>
    <div class="ticket-box">
        <h2><?php echo htmlspecialchars($trip['company_name']); ?></h2>
        <p><b><?php echo htmlspecialchars($trip['departure_city']); ?></b> → <b><?php echo htmlspecialchars($trip['destination_city']); ?></b></p>
        <p>Kalkış: <?php echo htmlspecialchars($trip['departure_time']); ?></p>
        <p>Varış: <?php echo htmlspecialchars($trip['arrival_time']); ?></p>
        <p>Fiyat: <?php echo number_format((float)$trip['price'], 2); ?> ₺</p>
        <p>Kalan Koltuk: <?php echo (int)$available; ?></p>

        <?php if ($isPast): ?>
            <div class="warn">Sefer tarihi geçmiş.</div>
        <?php elseif ($available <= 0): ?>
            <div class="warn">Dolu sefer.</div>
        <?php else: ?>
            <form method="GET" action="seat_selection.php">
                <input type="hidden" name="trip_id" value="<?php echo (int)$trip['id']; ?>">
                <button type="submit">Koltuk Seç ve Devam Et</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
