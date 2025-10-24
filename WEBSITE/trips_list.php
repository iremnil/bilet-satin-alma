<?php
session_start();

// Sadece admin erişebilsin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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


// Seferleri firma adı ile getir; satılan koltuk sayısını (aktif biletler) ve kalan koltukları hesapla
$sql = "
SELECT 
  tr.id,
  f.name AS firm,
  tr.departure_city,
  tr.destination_city,
  tr.departure_time,
  tr.arrival_time,
  tr.price,
  tr.capacity,
  COALESCE((
    SELECT COUNT(bs.id)
    FROM tickets t
    JOIN booked_seats bs ON bs.ticket_id = t.id
    WHERE t.trip_id = tr.id AND t.status = 'ACTIVE'
  ), 0) AS sold_seats
FROM trips tr
JOIN firms f ON tr.company_id = f.id
ORDER BY datetime(tr.departure_time) DESC, tr.id DESC
";

$stmt = $db->query($sql);
$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Sefer Listesi (Admin)</title>
  <style>
    body { font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0; }
    .navbar {
      background:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center; padding:12px 30px;
    }
    .navbar a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold; }
    .navbar a:hover { text-decoration:underline; }
    h1 { text-align:center; color:#333; margin:20px 0; }

    .wrap { width:92%; margin:0 auto 30px; }
    .summary { margin: 0 0 10px 0; color:#555; text-align:right; }

    table { width:100%; background:#fff; border-collapse:collapse; box-shadow:0 2px 10px rgba(0,0,0,.1); }
    th, td { padding:12px 14px; text-align:center; border-bottom:1px solid #eee; }
    th { background:#007bff; color:#fff; position:sticky; top:0; }
    tr:hover { background:#f7f9fc; }
    .pill {
      display:inline-block; padding:4px 8px; border-radius:14px; font-size:12px; font-weight:600;
    }
    .pill-cap { background:#e9ecef; color:#333; }
    .pill-sold { background:#ffe8e8; color:#b02a37; }
    .pill-left { background:#e7f7ed; color:#1e7e34; }
    .back { display:inline-block; margin:16px 0; padding:8px 12px; background:#6c757d; color:#fff; text-decoration:none; border-radius:6px; }
    .back:hover { background:#5a6268; }
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

  <h1>Tüm Seferler</h1>

  <div class="wrap">
    <div class="summary">Toplam sefer: <strong><?php echo count($trips); ?></strong></div>

    <table>
      <tr>
        <th>ID</th>
        <th>Firma</th>
        <th>Kalkış → Varış</th>
        <th>Kalkış Zamanı</th>
        <th>Varış Zamanı</th>
        <th>Fiyat</th>
        <th>Kapasite</th>
        <th>Satılan</th>
        <th>Kalan</th>
      </tr>
      <?php foreach ($trips as $t): 
          $left = max(0, (int)$t['capacity'] - (int)$t['sold_seats']);
      ?>
        <tr>
          <td><?php echo (int)$t['id']; ?></td>
          <td><?php echo htmlspecialchars($t['firm']); ?></td>
          <td><?php echo htmlspecialchars($t['departure_city'] . " → " . $t['destination_city']); ?></td>
          <td><?php echo htmlspecialchars($t['departure_time']); ?></td>
          <td><?php echo htmlspecialchars($t['arrival_time']); ?></td>
          <td><?php echo number_format((float)$t['price'], 2); ?> ₺</td>
          <td><span class="pill pill-cap"><?php echo (int)$t['capacity']; ?></span></td>
          <td><span class="pill pill-sold"><?php echo (int)$t['sold_seats']; ?></span></td>
          <td><span class="pill pill-left"><?php echo $left; ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <a class="back" href="admin_panel.php">← Admin Paneline Dön</a>
  </div>
</body>
</html>
