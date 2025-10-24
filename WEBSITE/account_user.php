<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
date_default_timezone_set('Europe/Istanbul'); //Zamana ıstanbulda gore cek

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

$u = $db->prepare("SELECT full_name, email, balance, created_at FROM users WHERE id=?");
$u->execute([$user_id]);
$user = $u->fetch(PDO::FETCH_ASSOC) ?: die("Kullanıcı bulunamadı.");

$stats = $db->prepare("
  SELECT
    SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN status='CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count,
    COUNT(*) AS total_count
  FROM tickets WHERE user_id = ?
");
$stats->execute([$user_id]);
$st = $stats->fetch(PDO::FETCH_ASSOC);

$last = $db->prepare("
  SELECT t.id AS ticket_id, t.status, t.total_price, tr.departure_city, tr.destination_city, tr.departure_time
  FROM tickets t
  JOIN trips tr ON tr.id = t.trip_id
  WHERE t.user_id = ?
  ORDER BY datetime(t.created_at) DESC
  LIMIT 5
");
$last->execute([$user_id]);
$rows = $last->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Hesabım</title>
<style>
body {
    font-family:'Segoe UI',Tahoma,Verdana,sans-serif;
    background:#f4f6f8;
    margin:0;
}
.nav {
    background:#007bff;
    color:#fff;
    display:flex;
    justify-content:space-between;  /* Sağ-sol hizalamak ıcın */
    align-items:center;
    padding:12px 30px;
}
.nav .left {
    display:flex;
    gap:25px;  /* Linkler arası boşluk */
}
.nav a {
    color:#fff;
    text-decoration:none;
    font-weight:bold;
}
.nav a:hover {
    text-decoration:underline;
}
.nav .logout:hover {
    background:#b02a37;
    text-decoration:none;
}

.wrap{max-width:1000px;margin:24px auto;padding:0 16px}
.card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:16px;margin-bottom:16px}
h2{margin:0 0 10px}
table{width:100%;border-collapse:collapse}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
th{background:#007bff;color:#fff}
.badge{padding:4px 8px;border-radius:8px;font-weight:600}
.ok{background:#e7f7ed;color:#1e7e34}
.cancel{background:#ffe8e8;color:#b02a37}
</style>
</head>
<body>
<div class="nav">
  <div class="left">
    <a href="index.php">Ana Sayfa</a>
    <a href="my_tickets.php">Biletlerim</a>
    <a href="account_user.php">Hesabım</a>
  </div>
  <div class="right">
    <a href="logout.php" class="logout">Çıkış Yap</a>
  </div>
</div>


<div class="wrap">
  <div class="card">
    <h2>Profil</h2>
    <p><b>Kullanıcı Adı:</b> <?php echo htmlspecialchars($user['full_name']); ?></p>
    <p><b>E-posta:</b> <?php echo htmlspecialchars($user['email']); ?></p>
    <p><b>Bakiye:</b> <?php echo number_format((float)$user['balance'],2); ?> ₺</p>
    <p><b>Üyelik:</b> <?php echo htmlspecialchars($user['created_at']); ?></p>
  </div>

  <div class="card">
    <h2>Özet</h2>
    <p><b>Toplam:</b> <?php echo (int)$st['total_count']; ?> |
       <b>Aktif:</b> <?php echo (int)$st['active_count']; ?> |
       <b>İptal:</b> <?php echo (int)$st['cancelled_count']; ?></p>
  </div>

  <div class="card">
    <h2>Son 5 Bilet</h2>
    <?php if(!$rows): ?>
      <p>Kayıt yok.</p>
    <?php else: ?>
      <table>
        <tr><th>#</th><th>Rota</th><th>Kalkış</th><th>Tutar</th><th>Durum</th></tr>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['ticket_id']; ?></td>
          <td><?php echo htmlspecialchars($r['departure_city']." → ".$r['destination_city']); ?></td>
          <td><?php echo htmlspecialchars($r['departure_time']); ?></td>
          <td><?php echo number_format((float)$r['total_price'],2); ?> ₺</td>
          <td><?php echo $r['status']==='ACTIVE' ? '<span class="badge ok">ACTIVE</span>' : '<span class="badge cancel">CANCELLED</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
