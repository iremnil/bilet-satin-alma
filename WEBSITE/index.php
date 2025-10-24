<?php
session_start();

// TR saatini sabitle
date_default_timezone_set('Europe/Istanbul');
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

// DB
try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }

// Arama parametreleri
$departure = $_GET['departure'] ?? '';
$arrival   = $_GET['arrival']   ?? '';

// Dropdown verileri
$departureCities = $db->query("SELECT DISTINCT departure_city FROM trips")->fetchAll(PDO::FETCH_COLUMN);
$arrivalCities   = $db->query("SELECT DISTINCT destination_city  FROM trips")->fetchAll(PDO::FETCH_COLUMN);

// Sorgu: TÜM seferleri getir (geçmiş + gelecek), şehir filtreleri varsa uygula
$query = "
SELECT 
  tr.id,
  f.name       AS firm,
  f.logo_path  AS logo_path,      -- 👈 logo eklendi
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
WHERE 1=1
";

$params = [];

if ($departure !== '') {
  $query .= " AND LOWER(tr.departure_city) = LOWER(:departure)";
  $params[':departure'] = $departure;
}
if ($arrival !== '') {
  $query .= " AND LOWER(tr.destination_city) = LOWER(:arrival)";
  $params[':arrival'] = $arrival;
}

$query .= " ORDER BY datetime(tr.departure_time) ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Bilet Satın Alma Platformu</title>
  <style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f8; margin:0 }
    .navbar { background:#007bff; color:#fff; display:flex; justify-content:space-between; align-items:center; padding:12px 30px }
    .navbar .left a, .navbar .right a { color:#fff; text-decoration:none; margin:0 10px; font-weight:bold }
    .navbar .left a:hover, .navbar .right a:hover { text-decoration:underline }
    h1 { text-align:center; color:#333; margin:25px 0 10px }
    form { display:flex; justify-content:center; align-items:center; gap:10px; margin-bottom:20px; flex-wrap:wrap }
    select, button { padding:8px 12px; font-size:14px; border-radius:5px; border:1px solid #ccc }
    button { background:#007bff; color:#fff; border:none; cursor:pointer }
    button:hover { background:#0056b3 }
    table { width:90%; margin:0 auto; border-collapse:collapse; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.1) }
    th, td { padding:12px 15px; text-align:center; border-bottom:1px solid #eee; vertical-align:middle }
    th { background:#007bff; color:#fff }
    tr:hover { background:#f1f1f1 }
    p { text-align:center; font-size:16px; color:#555 }
    .btn-disabled { background:#9aa0a6 !important; cursor:not-allowed !important }
    .note { font-size:12px; color:#666; }
    .firm-cell { display:flex; align-items:center; justify-content:center; gap:8px; }
    .firm-cell img { height:28px; border-radius:4px; display:block; }
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

  <h1>Otobüs Seferleri</h1>

  <!-- Sefer Arama Formu -->
  <form method="GET">
    Kalkış:
    <select name="departure">
      <option value="">--Seçiniz--</option>
      <?php foreach ($departureCities as $city): ?>
        <option value="<?php echo htmlspecialchars($city); ?>" <?php if ($city === $departure) echo 'selected'; ?>>
          <?php echo htmlspecialchars($city); ?>
        </option>
      <?php endforeach; ?>
    </select>

    Varış:
    <select name="arrival">
      <option value="">--Seçiniz--</option>
      <?php foreach ($arrivalCities as $city): ?>
        <option value="<?php echo htmlspecialchars($city); ?>" <?php if ($city === $arrival) echo 'selected'; ?>>
          <?php echo htmlspecialchars($city); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit">Ara</button>
  </form>

  <hr>

  <!-- Sefer Listesi -->
  <?php if ($trips && count($trips) > 0): ?>
    <table>
      <tr>
        <th>Firma</th>
        <th>Kalkış</th>
        <th>Varış</th>
        <th>Kalkış tarihi</th>
        <th>Varış tarihi</th>
        <th>Fiyat</th>
        <th>Kalan Koltuk</th>
        <th>İşlem</th>
      </tr>
      <?php foreach ($trips as $trip):
        // kalan koltuk
        $remaining = max(0, (int)$trip['capacity'] - (int)$trip['sold_seats']);

        // geçmiş mi?
        $dep = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trip['departure_time'], new DateTimeZone('Europe/Istanbul'));
        if (!$dep) { $dep = new DateTimeImmutable($trip['departure_time'], new DateTimeZone('Europe/Istanbul')); }
        $isPast = $dep <= $now;

        // buton durumu ve mesajı
        $disabled = $isPast || $remaining <= 0;
        $disabledLabel = $isPast ? 'Sefer tarihi geçmiş' : 'Dolu sefer';

        // logo
        $logo = $trip['logo_path'] ?? '';
      ?>
        <tr>
          <td>
            <div class="firm-cell">
              <?php if (!empty($logo)): ?>
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo">
              <?php endif; ?>
              <span><?php echo htmlspecialchars($trip['firm']); ?></span>
            </div>
          </td>
          <td><?php echo htmlspecialchars($trip['departure_city']); ?></td>
          <td><?php echo htmlspecialchars($trip['destination_city']); ?></td>
          <td><?php echo htmlspecialchars($trip['departure_time']); ?></td>
          <td><?php echo htmlspecialchars($trip['arrival_time']); ?></td>
          <td><?php echo number_format((float)$trip['price'], 2); ?> ₺</td>
          <td><?php echo (int)$remaining; ?></td>
          <td>
            <?php if ($disabled): ?>
              <button type="button" class="btn-disabled" disabled><?php echo $disabledLabel; ?></button>
            <?php else: ?>
              <?php if (isset($_SESSION['user_id'])): ?>
                <form method="GET" action="buy_ticket.php" style="margin:0">
                  <input type="hidden" name="id" value="<?php echo (int)$trip['id']; ?>">
                  <button type="submit">Bilet Al</button>
                </form>
              <?php else: ?>
                <button type="button" onclick="alert('Bilet almak için lütfen giriş yapın.');">Bilet Al</button>
                <div class="note">Giriş yaptıktan sonra satın alabilirsiniz.</div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p>Sefer bulunamadı.</p>
  <?php endif; ?>
</body>
</html>
