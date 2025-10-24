<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'company_admin' || !isset($_SESSION['company_id'])) {
    header("Location: login.php"); exit();
}
$company_id = (int)$_SESSION['company_id'];

try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }


$firm = $db->prepare("SELECT id,name FROM firms WHERE id=?");
$firm->execute([$company_id]);
$firmRow = $firm->fetch(PDO::FETCH_ASSOC) ?: die("Firma bulunamadı.");

$start = $_GET['start'] ?? '';
$end   = $_GET['end'] ?? '';
$params = [':cid'=>$company_id];
$where = "WHERE tr.company_id = :cid";
if ($start!==''){ $where.=" AND date(t.created_at)>=date(:s)"; $params[':s']=$start; }
if ($end  !==''){ $where.=" AND date(t.created_at)<=date(:e)"; $params[':e']=$end; }

$q = $db->prepare("
  SELECT t.id AS ticket_id, u.full_name AS user_name,
         tr.departure_city, tr.destination_city, tr.departure_time,
         t.status, t.total_price, t.created_at
  FROM tickets t
  JOIN trips tr ON tr.id=t.trip_id
  JOIN users u ON u.id=t.user_id
  $where
  ORDER BY datetime(t.created_at) DESC
");
$q->execute($params);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

// koltuk map
$ids = array_map(fn($r)=>(int)$r['ticket_id'],$rows);
$seatMap=[];
if($ids){
  $in = implode(',', array_fill(0,count($ids),'?'));
  $st = $db->prepare("SELECT ticket_id, seat_number FROM booked_seats WHERE ticket_id IN ($in)");
  $st->execute($ids);
  while($s=$st->fetch(PDO::FETCH_ASSOC)){ $seatMap[(int)$s['ticket_id']][]=$s['seat_number']; }
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Biletler — <?php echo htmlspecialchars($firmRow['name']);?></title>
<style>
body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;background:#f4f6f8;margin:0}
.nav{background:#007bff;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:12px 30px}
.nav a{color:#fff;text-decoration:none;font-weight:bold}
.wrap{max-width:1100px;margin:24px auto;padding:0 16px}
.card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:16px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
th{background:#007bff;color:#fff}
.controls{display:flex;gap:8px;align-items:center;margin-bottom:10px}
input[type="date"]{padding:8px;border:1px solid #ccc;border-radius:8px}
.btn{background:#007bff;color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;text-decoration:none}
.btn:hover{background:#0056b3}
.badge{padding:4px 8px;border-radius:8px;font-weight:600}
.ok{background:#e7f7ed;color:#1e7e34}
.cancel{background:#ffe8e8;color:#b02a37}
</style></head><body>
<div class="nav">
  <div>Firma Paneli — <?php echo htmlspecialchars($firmRow['name']);?></div>
  <div><a href="company_admin_panel.php">Geri</a></div>
</div>
<div class="wrap">
  <div class="card">
    <div class="controls">
      <form method="GET" style="display:flex;gap:8px;align-items:center">
        <label>Başlangıç:</label><input type="date" name="start" value="<?php echo htmlspecialchars($start);?>">
        <label>Bitiş:</label><input type="date" name="end" value="<?php echo htmlspecialchars($end);?>">
        <button class="btn" type="submit">Filtrele</button>
      </form>
    </div>

    <table>
      <thead><tr>
        <th>Ticket#</th><th>Kullanıcı</th><th>Rota</th><th>Kalkış</th><th>Koltuk(lar)</th><th>Tutar</th><th>Durum</th><th>Alım Zamanı</th>
      </tr></thead>
      <tbody>
        <?php if(!$rows):?><tr><td colspan="8">Kayıt yok.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['ticket_id'];?></td>
            <td><?php echo htmlspecialchars($r['user_name']);?></td>
            <td><?php echo htmlspecialchars($r['departure_city']." → ".$r['destination_city']);?></td>
            <td><?php echo htmlspecialchars($r['departure_time']);?></td>
            <td><?php $s=$seatMap[(int)$r['ticket_id']]??[]; echo $s?implode(', ',$s):'-';?></td>
            <td><?php echo number_format((float)$r['total_price'],2);?> ₺</td>
            <td><?php echo $r['status']==='ACTIVE' ? '<span class="badge ok">ACTIVE</span>' : '<span class="badge cancel">CANCELLED</span>';?></td>
            <td><?php echo htmlspecialchars($r['created_at']);?></td>
          </tr>
        <?php endforeach; endif;?>
      </tbody>
    </table>
  </div>
</div>
</body></html>
