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


if (!isset($_GET['trip_id'])) {
    echo "Geçersiz istek.";
    exit();
}

$trip_id = (int)$_GET['trip_id'];

// Sefer bilgilerini çek
$stmt = $db->prepare("
    SELECT trips.*, firms.name AS company_name
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
$company_id = (int)$trip['company_id'];

// Kullanıcı bakiyesini çek
$userStmt = $db->prepare("SELECT balance FROM users WHERE id = ?");
$userStmt->execute([$_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$balance = $user ? (float)$user['balance'] : 0.0;

// Dolu koltukları çek (SADECE ACTIVE biletler)
$booked = $db->prepare("
    SELECT bs.seat_number
    FROM booked_seats bs
    JOIN tickets t ON bs.ticket_id = t.id
    WHERE t.trip_id = ? AND t.status = 'ACTIVE'
");
$booked->execute([$trip_id]);
$bookedSeats = $booked->fetchAll(PDO::FETCH_COLUMN);

// Kalan koltuk hesabı
$capacity   = (int)$trip['capacity'];
$available  = max(0, $capacity - count($bookedSeats));

// Geçmiş mi? (İstanbul saatine göre)
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
$dep = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trip['departure_time'], new DateTimeZone('Europe/Istanbul'));
if (!$dep) { $dep = new DateTimeImmutable($trip['departure_time'], new DateTimeZone('Europe/Istanbul')); }
$isPast = ($dep <= $now);

// Firmanın kuponlarını çek (süresi geçmemiş)
$couponStmt = $db->prepare("
    SELECT id, code, discount
    FROM coupons
    WHERE (company_id = :company_id OR company_id IS NULL)
      AND date(expire_date) >= date('now','localtime')
");
$couponStmt->execute([':company_id' => $company_id]);
$coupons = $couponStmt->fetchAll(PDO::FETCH_ASSOC);

// Koltuk listesi
$seats = range(1, $capacity);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Koltuk Seçimi</title>
<style>
body { font-family: Arial; text-align: center; background: #f2f2f2; }
.bus-layout { display: grid; grid-template-columns: repeat(4, 50px); gap: 10px; justify-content: center; margin-top: 30px; }
.seat { width: 50px; height: 50px; border-radius: 6px; cursor: pointer; line-height: 50px; color: white; font-weight: bold; }
.available { background: #28a745; }
.booked { background: #6c757d; cursor: not-allowed; }
.selected { background: #007bff; }
button { margin-top: 20px; padding: 10px 20px; font-size: 16px; cursor: pointer; }
.warn { color:#b02a37; font-weight:700; margin-top:12px; }
</style>
</head>
<body>

<h2><?php echo htmlspecialchars($trip['company_name']); ?> - <?php echo htmlspecialchars($trip['departure_city'] . " → " . $trip['destination_city']); ?></h2>
<p>Tarih: <?php echo htmlspecialchars($trip['departure_time']); ?> | Fiyat: <?php echo number_format((float)$trip['price'],2); ?> ₺</p>
<p>Bakiyeniz: <?php echo number_format($balance,2); ?> ₺</p>

<?php if ($isPast): ?>
  <div class="warn">Sefer tarihi geçmiş. Satın alma kapalı.</div>
<?php elseif ($available <= 0): ?>
  <div class="warn">Dolu sefer. Satın alma kapalı.</div>
<?php else: ?>

<form method="POST" action="purchase_ticket.php" id="seatForm">
    <input type="hidden" name="trip_id" value="<?php echo (int)$trip_id; ?>">
    <input type="hidden" name="selected_seat" id="selected_seat">

    <div class="bus-layout">
        <?php foreach ($seats as $seat): 
            $isBooked = in_array($seat, $bookedSeats, true);
            $class = $isBooked ? 'booked' : 'available';
        ?>
            <div class="seat <?php echo $class; ?>" data-seat="<?php echo (int)$seat; ?>">
                <?php echo (int)$seat; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Kupon dropdown -->
    <div style="margin-top:20px;">
      <label for="coupon_id">Kupon Seç (opsiyonel): </label>
      <select name="coupon_id" id="coupon_id">
          <option value="">Kupon kullanma</option>
          <?php foreach ($coupons as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" data-discount="<?php echo (float)$c['discount']; ?>">
                  <?php echo htmlspecialchars($c['code'])." - ".number_format((float)$c['discount'],2)." ₺ indirim"; ?>
              </option>
          <?php endforeach; ?>
      </select>
    </div>

    <button type="submit">Devam Et</button>
</form>

<?php endif; // past/dolu değilse formu göster ?>

<script>
// Koltuk seçimi 
document.querySelectorAll('.seat.available').forEach(seat => {
    seat.addEventListener('click', () => {
        document.querySelectorAll('.seat').forEach(s => s.classList.remove('selected'));
        seat.classList.add('selected');
        document.getElementById('selected_seat').value = seat.dataset.seat;
    });
});

// Form gönderiminde kontrol ve confirm
const form = document.getElementById('seatForm');
if (form) {
  form.addEventListener('submit', function(e) {
      const selectedSeat = document.getElementById('selected_seat').value;
      if (!selectedSeat) {
          alert("Lütfen önce bir koltuk seçin!");
          e.preventDefault();
          return false;
      }

      const price = <?php echo (float)$trip['price']; ?>;
      const balance = <?php echo (float)$balance; ?>;

      // Kupon indirimi
      const couponSelect = document.getElementById('coupon_id');
      let discount = 0;
      if (couponSelect && couponSelect.value) {
          const selectedOption = couponSelect.options[couponSelect.selectedIndex];
          discount = parseFloat(selectedOption.getAttribute('data-discount')) || 0;
      }

      const finalPrice = Math.max(0, price - discount);

      if (balance < finalPrice) {
          alert("Yeterli bakiyeniz yok!");
          e.preventDefault();
          return false;
      }

      let confirmMsg = `Seçilen koltuk: ${selectedSeat}\nFiyat: ${price} ₺\n`;
      if (discount > 0) { confirmMsg += `Kupon indirimi: -${discount} ₺\n`; }
      confirmMsg += `Ödenecek tutar: ${finalPrice} ₺\nBakiyeniz: ${balance} ₺\nBilet almak istediğinizden emin misiniz?`;

      if (!confirm(confirmMsg)) {
          e.preventDefault();
          return false;
      }
  });
}
</script>

</body>
</html>
