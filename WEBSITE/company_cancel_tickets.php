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

// Firma adı
$firm = $db->prepare("SELECT id,name FROM firms WHERE id = ?");
$firm->execute([$company_id]);
$firmRow = $firm->fetch(PDO::FETCH_ASSOC) ?: die("Firma bulunamadı.");

$success = $error = "";

/* BİLET İPTAL İŞLEMİ (POST)*/
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cancel_ticket'])) {
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);

    try {
        $db->beginTransaction();

        // 1) Bu bilet gerçekten BU firmanın seferinden mi, ACTIVE mi ve kalkış zamanı geçmedi mi?
        $check = $db->prepare("
            SELECT t.id, t.user_id, t.total_price, t.status,
                   tr.company_id, tr.departure_time
            FROM tickets t
            JOIN trips tr ON tr.id = t.trip_id
            WHERE t.id = :tid
              AND tr.company_id = :cid
              AND t.status = 'ACTIVE'
              AND datetime(tr.departure_time) > datetime('now','localtime')
            LIMIT 1
        ");
        $check->execute([':tid' => $ticket_id, ':cid' => $company_id]);
        $ticket = $check->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $db->rollBack();
            $error = "Bu bilet iptal edilemez (zamanı geçmiş olabilir veya size ait değildir)."; //Zamanı gecmıs te olabılırdı
        } else {
            // 2) Bileti CANCELLED yap (yarış durumları için WHERE status='ACTIVE')
            $up = $db->prepare("UPDATE tickets SET status='CANCELLED' WHERE id=:tid AND status='ACTIVE'");
            $up->execute([':tid' => $ticket_id]);

            if ($up->rowCount() === 0) {
                $db->rollBack();
                $error = "Bilet durumu değiştirilemedi (başka bir işlem tarafından güncellenmiş olabilir).";
            } else {
                // 3) Koltukları boşalt
                $delSeats = $db->prepare("DELETE FROM booked_seats WHERE ticket_id = :tid");
                $delSeats->execute([':tid' => $ticket_id]);

                // 4) Kupon kullanımını geri al
                $delCouponUse = $db->prepare("DELETE FROM user_coupons WHERE ticket_id = :tid");
                $delCouponUse->execute([':tid' => $ticket_id]);

                // 5) Ücreti kullanıcıya iade edelim
                $refund = (float)$ticket['total_price'];
                $refundUser = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
                $refundUser->execute([':amt' => $refund, ':uid' => (int)$ticket['user_id']]);

                $db->commit();
                $success = "Bilet iptal edildi ve kullanıcıya " . number_format($refund, 2) . " ₺ iade edildi.";
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = "İptal sırasında hata: " . htmlspecialchars($e->getMessage());
    }
}

/* LİSTELEME + FİLTRE*/
 
$start = $_GET['start'] ?? '';
$end   = $_GET['end'] ?? '';
$params = [':cid' => $company_id];
$where  = "WHERE tr.company_id = :cid";

if ($start !== '') { $where .= " AND date(t.created_at) >= date(:s)"; $params[':s'] = $start; }
if ($end   !== '') { $where .= " AND date(t.created_at) <= date(:e)"; $params[':e'] = $end; }

$q = $db->prepare("
    SELECT t.id AS ticket_id, t.user_id, u.full_name AS user_name,
           t.status, t.total_price, t.created_at,
           tr.departure_city, tr.destination_city, tr.departure_time
    FROM tickets t
    JOIN trips tr ON tr.id = t.trip_id
    JOIN users u  ON u.id = t.user_id
    $where
    ORDER BY datetime(t.created_at) DESC
");
$q->execute($params);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

// Koltukları toplu çekelim
$ids = array_map(fn($r)=>(int)$r['ticket_id'], $rows);
$seatMap = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT ticket_id, seat_number FROM booked_seats WHERE ticket_id IN ($in)");
    $st->execute($ids);
    while($s = $st->fetch(PDO::FETCH_ASSOC)) {
        $seatMap[(int)$s['ticket_id']][] = $s['seat_number'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Bilet Görüntüle & İptal — <?php echo htmlspecialchars($firmRow['name']); ?></title>
<style>
body{font-family:'Segoe UI', Tahoma, Verdana, sans-serif;background:#f4f6f8;margin:0}
.nav{background:#007bff;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:12px 30px}
.nav a{color:#fff;text-decoration:none;font-weight:bold}
.wrap{max-width:1100px;margin:24px auto;padding:0 16px}
.card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:16px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
th{background:#007bff;color:#fff}
label{font-weight:600;margin-right:6px}
input[type="date"]{padding:8px;border:1px solid #ccc;border-radius:8px}
a.btn, button.btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 14px;border-radius:8px;border:none;cursor:pointer;text-decoration:none;font-weight:600;line-height:1}
a.btn{background:#007bff;color:#fff}
a.btn:hover{background:#0056b3}
button.btn{background:#007bff;color:#fff}
button.btn:hover{background:#0056b3}
.btn-danger{background:#dc3545 !important}
.btn-danger:hover{background:#b02a37 !important}
.badge{padding:4px 8px;border-radius:8px;font-weight:600}
.ok{background:#e7f7ed;color:#1e7e34}
.cancel{background:#ffe8e8;color:#b02a37}
.controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.msg{margin:10px 0;padding:10px;border-radius:8px;border:1px solid transparent}
.msg.ok{background:#e7f7ed;border-color:#a3d9ad;color:#1e7e34}
.msg.err{background:#ffe8e8;border-color:#f5c2c7;color:#b02a37}
</style>
</head>
<body>
<div class="nav">
  <div>Firma Paneli — <?php echo htmlspecialchars($firmRow['name']); ?></div>
  <div>
    <a href="company_admin_panel.php">Ana Sayfa</a>
  </div>
</div>

<div class="wrap">
  <?php if($success):?><div class="msg ok"><?php echo htmlspecialchars($success);?></div><?php endif;?>
  <?php if($error):?><div class="msg err"><?php echo htmlspecialchars($error);?></div><?php endif;?>

  <div class="card">
    <h3>Biletler</h3>
    <div class="controls" style="margin-bottom:10px">
      <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <label>Başlangıç:</label><input type="date" name="start" value="<?php echo htmlspecialchars($start);?>">
        <label>Bitiş:</label><input type="date" name="end" value="<?php echo htmlspecialchars($end);?>">
        <button class="btn" type="submit">Filtrele</button>
      </form>
    </div>

    <table>
      <thead>
        <tr>
          <th>Ticket#</th>
          <th>Kullanıcı</th>
          <th>Rota</th>
          <th>Kalkış</th>
          <th>Koltuk(lar)</th>
          <th>Tutar</th>
          <th>Durum</th>
          <th>İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8">Kayıt yok.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['ticket_id']; ?></td>
            <td><?php echo htmlspecialchars($r['user_name']); ?></td>
            <td><?php echo htmlspecialchars($r['departure_city']." → ".$r['destination_city']); ?></td>
            <td><?php echo htmlspecialchars($r['departure_time']); ?></td>
            <td><?php $s=$seatMap[(int)$r['ticket_id']]??[]; echo $s?implode(', ',$s):'-'; ?></td>
            <td><?php echo number_format((float)$r['total_price'],2); ?> ₺</td>
            <td>
              <?php if ($r['status']==='ACTIVE'): ?>
                <span class="badge ok">ACTIVE</span>
              <?php else: ?>
                <span class="badge cancel">CANCELLED</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['status']==='ACTIVE'): ?>
                <form method="POST" onsubmit="return confirm('Bu bileti iptal etmek istiyor musunuz? Kullanıcıya ücret iadesi yapılacak.');" style="display:inline">
                  <input type="hidden" name="ticket_id" value="<?php echo (int)$r['ticket_id']; ?>">
                  <button class="btn btn-danger" type="submit" name="cancel_ticket">İptal Et</button>
                </form>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
