<?php
// ticket_print.php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$ticket_id = isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
if ($ticket_id <= 0) { die("Geçersiz istek."); }

try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }


// Bilet, kullanıcıya ait mi? Tüm detayları çek
$q = $db->prepare("
    SELECT t.id AS ticket_id, t.total_price, t.status, t.created_at,
           u.full_name, u.email,
           f.name AS firm,
           tr.departure_city, tr.destination_city,
           tr.departure_time, tr.arrival_time
    FROM tickets t
    JOIN users u  ON u.id  = t.user_id
    JOIN trips tr ON tr.id = t.trip_id
    JOIN firms f  ON f.id  = tr.company_id
    WHERE t.id = :tid AND t.user_id = :uid
    LIMIT 1
");
$q->execute([':tid'=>$ticket_id, ':uid'=>$_SESSION['user_id']]);
$T = $q->fetch(PDO::FETCH_ASSOC);
if (!$T) { die("Bilet bulunamadı veya yetkiniz yok."); }

// Koltukları çek
$s = $db->prepare("SELECT seat_number FROM booked_seats WHERE ticket_id = ?");
$s->execute([$ticket_id]);
$seats = $s->fetchAll(PDO::FETCH_COLUMN);
$seat_str = $seats ? implode(', ', $seats) : '-';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Bilet #<?php echo (int)$T['ticket_id']; ?> — PDF</title>
<style>
  :root{
    --text:#111827; --muted:#6b7280; --border:#e5e7eb;
    --primary:#111827; --accent:#0ea5e9; --ok:#16a34a; --cancel:#dc2626;
  }
  *{box-sizing:border-box}
  body{font-family:'Segoe UI', Tahoma, Verdana, sans-serif; color:var(--text); margin:0; background:#f8fafc}
  .sheet{max-width:700px; margin:24px auto; background:#fff; border:1px solid var(--border); border-radius:10px; padding:24px}
  .top{display:flex; justify-content:space-between; align-items:flex-start; gap:16px; border-bottom:1px solid var(--border); padding-bottom:14px}
  .brand{font-size:18px; font-weight:800; letter-spacing:.3px}
  .meta {text-align:right; color:var(--muted); font-size:13px}
  h2{margin:18px 0 8px; font-size:20px}
  .grid{display:grid; grid-template-columns: 1fr 1fr; gap:12px}
  .card{border:1px solid var(--border); border-radius:10px; padding:12px}
  .label{font-size:12px; color:var(--muted); margin-bottom:4px}
  .value{font-weight:600}
  .badge{display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; color:#fff}
  .badge.ok{background:var(--ok)}
  .badge.cancel{background:var(--cancel)}
  .total{font-size:18px; font-weight:800}
  .footer{margin-top:18px; color:var(--muted); font-size:12px; display:flex; justify-content:space-between; align-items:center}
  .qr{width:96px; height:96px; border:1px solid var(--border); border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:12px}
  .print-actions{display:flex; justify-content:flex-end; gap:8px; margin:12px auto 0; max-width:700px}
  .btn{background:var(--accent); color:#fff; border:none; border-radius:8px; padding:8px 12px; cursor:pointer; font-weight:700}
  @media print{
    .print-actions{display:none}
    body{background:#fff}
    .sheet{border:none; box-shadow:none; margin:0; border-radius:0}
  }
</style>
</head>
<body>

<div class="print-actions">
  <button class="btn" onclick="window.print()">Yazdır / PDF İndir</button>
  <button class="btn" onclick="window.close()">Kapat</button>
</div>

<div class="sheet">
  <div class="top">
    <div class="brand"><?php echo htmlspecialchars($T['firm']); ?> — Bilet</div>
    <div class="meta">
      <div>Bilet No: <b>#<?php echo (int)$T['ticket_id']; ?></b></div>
      <div>Oluşturma: <?php echo htmlspecialchars($T['created_at']); ?></div>
      <div>Durum:
        <?php if ($T['status']==='ACTIVE'): ?>
          <span class="badge ok">ACTIVE</span>
        <?php else: ?>
          <span class="badge cancel">CANCELLED</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <h2>Yolculuk Bilgileri</h2>
  <div class="grid">
    <div class="card">
      <div class="label">Kalkış</div>
      <div class="value"><?php echo htmlspecialchars($T['departure_city']); ?></div>
      <div class="label" style="margin-top:8px">Zaman</div>
      <div class="value"><?php echo htmlspecialchars($T['departure_time']); ?></div>
    </div>
    <div class="card">
      <div class="label">Varış</div>
      <div class="value"><?php echo htmlspecialchars($T['destination_city']); ?></div>
      <div class="label" style="margin-top:8px">Tahmini Varış</div>
      <div class="value"><?php echo htmlspecialchars($T['arrival_time'] ?? '-'); ?></div>
    </div>
    <div class="card">
      <div class="label">Koltuk(lar)</div>
      <div class="value"><?php echo htmlspecialchars($seat_str); ?></div>
    </div>
    <div class="card">
      <div class="label">Ücret (Net)</div>
      <div class="value total"><?php echo number_format((float)$T['total_price'],2); ?> ₺</div>
    </div>
  </div>

  <h2>Yolcu</h2>
  <div class="grid">
    <div class="card">
      <div class="label">Ad Soyad</div>
      <div class="value"><?php echo htmlspecialchars($T['full_name']); ?></div>
    </div>
    <div class="card">
      <div class="label">E-posta</div>
      <div class="value"><?php echo htmlspecialchars($T['email']); ?></div>
    </div>
  </div>

  
</div>
</div>

<script>
// Sayfa açıldığında otomatik yazdırmayı istersen aç:
window.addEventListener('load', function(){
  // setTimeout ile 300ms sonra tetiklemek bazen daha stabil:
  setTimeout(()=>window.print(), 300);
});
</script>
</body>
</html>
