<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
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

// Rol kontrol yetkısız erısımı engellemek ıcın yaptım
$role = $_SESSION['role'] ?? null;
if (!$role) {
  $rr = $db->prepare("SELECT role FROM users WHERE id=?");
  $rr->execute([ (int)$_SESSION['user_id'] ]);
  $role = $rr->fetchColumn();
  $_SESSION['role'] = $role;
}
if ($role !== 'company_admin') { header("Location: index.php"); exit; }

// Firma admin bilgilerinı cekıyorum
$u = $db->prepare("SELECT full_name, email, company_id FROM users WHERE id=?");
$u->execute([ (int)$_SESSION['user_id'] ]);
$user = $u->fetch(PDO::FETCH_ASSOC) ?: die("Kullanıcı bulunamadı.");
$company_id = (int)$user['company_id'];

// Firma bilgileri bulunmazsa mesaj
$f = $db->prepare("SELECT id, name, created_at FROM firms WHERE id=?");
$f->execute([$company_id]);
$firm = $f->fetch(PDO::FETCH_ASSOC) ?: die("Firma bulunamadı.");

// Firma ozetii
$stats = $db->prepare("
  SELECT
    (SELECT COUNT(*) FROM trips WHERE company_id = :cid) AS trip_count,
    (SELECT COUNT(*) FROM coupons WHERE company_id = :cid) AS coupon_count,
    (SELECT COUNT(*) FROM tickets t JOIN trips tr ON tr.id = t.trip_id WHERE tr.company_id = :cid AND t.status='ACTIVE') AS active_tickets
");
$stats->execute([':cid'=>$company_id]);
$st = $stats->fetch(PDO::FETCH_ASSOC);

// Yaklaşan 5 sefer lısteledım
$upcoming = $db->prepare("
  SELECT id, departure_city, destination_city, departure_time, price, capacity
  FROM trips
  WHERE company_id = :cid
  ORDER BY datetime(departure_time) ASC
  LIMIT 5
");
$upcoming->execute([':cid'=>$company_id]);
$rows = $upcoming->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Hesabım (Firma Admin)</title>
<style>
body{font-family:'Segoe UI',Tahoma,Verdana,sans-serif;background:#f4f6f8;margin:0}
.nav{background:#007bff;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:12px 30px}
.nav a{color:#fff;text-decoration:none;font-weight:bold}
.nav .left {display:flex;gap:25px;  /* 🧭 Linkler arası boşluk icin kullan obur turlu cok yakınlar*/}
.wrap{max-width:1100px;margin:24px auto;padding:0 16px}
.card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:16px;margin-bottom:16px}
h2{margin:0 0 10px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
th{background:#007bff;color:#fff}
.small{color:#666}
</style>
</head>
<body>
<div class="nav">
  <div class="left">
    <a href="company_admin_panel.php">Firma Admin Paneli</a>
    <a href="account_company.php">Hesabım</a>
  </div>
  <div class="right">
    <a href="logout.php" class="logout">Çıkış Yap</a>
  </div>
</div>

<div class="wrap">
  <div class="card">
    <h2>Profil</h2>
    <p><b>Ad Soyad:</b> <?php echo htmlspecialchars($user['full_name']); ?></p>
    <p><b>E-posta:</b> <?php echo htmlspecialchars($user['email']); ?></p>
    <p class="small"><b>Firma:</b> <?php echo htmlspecialchars($firm['name']); ?> (ID: <?php echo (int)$firm['id']; ?>)</p>
  </div>

  <div class="card">
    <h2>Firma Özeti</h2>
    <p><b>Toplam Sefer:</b> <?php echo (int)$st['trip_count']; ?> |
       <b>Aktif Bilet:</b> <?php echo (int)$st['active_tickets']; ?> |
       <b>Kupon:</b> <?php echo (int)$st['coupon_count']; ?></p>
  </div>

  <div class="card">
    <h2>Yaklaşan 5 Sefer</h2>
    <?php if(!$rows): ?>
      <p>Kayıt yok.</p>
    <?php else: ?>
      <table>
        <tr><th>#</th><th>Rota</th><th>Kalkış</th><th>Fiyat</th><th>Kapasite</th></tr>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo htmlspecialchars($r['departure_city']." → ".$r['destination_city']); ?></td>
          <td><?php echo htmlspecialchars($r['departure_time']); ?></td>
          <td><?php echo number_format((float)$r['price'],2); ?> ₺</td>
          <td><?php echo (int)$r['capacity']; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
