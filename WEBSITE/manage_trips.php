<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'company_admin' || !isset($_SESSION['company_id'])) {
    header("Location: login.php"); exit();
}
$company_id = (int)$_SESSION['company_id'];

/* TR saat dilimi */
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


$firm = $db->prepare("SELECT id,name FROM firms WHERE id=?");
$firm->execute([$company_id]);
$firmRow = $firm->fetch(PDO::FETCH_ASSOC) ?: die("Firma bulunamadı.");

$success=$error="";

// Sil (SEFERİ TAMAMEN SİL + ACTIVE BİLETLERE İADE)
if (($_SERVER['REQUEST_METHOD']??'')==='POST' && ($_POST['action']??'')==='delete') {
  $id = (int)($_POST['trip_id'] ?? 0);
  try {
    $db->exec("PRAGMA foreign_keys = ON");
    $db->exec("PRAGMA busy_timeout = 3000");
    $db->beginTransaction();

    // 0) Bu sefer size mi ait?
    $own = $db->prepare("SELECT 1 FROM trips WHERE id=:id AND company_id=:cid LIMIT 1");
    $own->execute([':id'=>$id, ':cid'=>$company_id]);
    if (!$own->fetchColumn()) {
      throw new Exception("Sefer bulunamadı veya yetkiniz yok.");
    }

    // 1) Sefere ait biletleri çek
    $tk = $db->prepare("SELECT id, user_id, total_price, status FROM tickets WHERE trip_id = :tid");
    $tk->execute([':tid'=>$id]);
    $tickets = $tk->fetchAll(PDO::FETCH_ASSOC);

    // 2) ACTIVE biletler için iade yap
    $refundedCount = 0;
    $refundedSum   = 0.0;
    if ($tickets) {
      $updBal = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");

      foreach ($tickets as $t) {
        if ($t['status'] === 'ACTIVE') {
          $amt = (float)$t['total_price'];
          $updBal->execute([':amt'=>$amt, ':uid'=>(int)$t['user_id']]);
          $refundedCount++;
          $refundedSum += $amt;
        }
      }

      // 3) Kupon ve koltuk kayıtlarını temizle
      $ids = array_map(fn($r)=>(int)$r['id'], $tickets);
      $in  = implode(',', array_fill(0, count($ids), '?'));

      // user_coupons
      $delUC = $db->prepare("DELETE FROM user_coupons WHERE ticket_id IN ($in)");
      $delUC->execute($ids);

      // booked_seats
      $delBS = $db->prepare("DELETE FROM booked_seats WHERE ticket_id IN ($in)");
      $delBS->execute($ids);

      // 4) Biletleri sil (aktif/iptal fark etmeksizin hepsi)
      $delT = $db->prepare("DELETE FROM tickets WHERE id IN ($in)");
      $delT->execute($ids);
    }

    // 5) Seferi sil
    $delTrip = $db->prepare("DELETE FROM trips WHERE id = :tid AND company_id = :cid");
    $delTrip->execute([':tid'=>$id, ':cid'=>$company_id]);

    if ($delTrip->rowCount() === 0) {
      throw new Exception("Sefer silinemedi (bulunamadı).");
    }

    $db->commit();

    $msg = "Sefer silindi.";
    if ($refundedCount > 0) {
      $msg .= " {$refundedCount} bilet için toplam " . number_format($refundedSum, 2) . " ₺ iade yapıldı.";
    }
    $success = $msg;

  } catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = "Silme hatası: " . htmlspecialchars($e->getMessage());
  }
}

/* ---------- Sefer Ekle/Güncelle ---------- */
if (($_SERVER['REQUEST_METHOD']??'')==='POST' && in_array($_POST['action']??'', ['create','update'], true)) {
  $dep=trim($_POST['departure_city']??'');
  $dst=trim($_POST['destination_city']??'');
  $dt =trim($_POST['departure_time']??''); // HTML datetime-local (YYYY-MM-DDTHH:MM)
  $at =trim($_POST['arrival_time']??'');
  $pr =trim($_POST['price']??'');
  $cap=trim($_POST['capacity']??'');

  try{
    /* Zorunlu alan/doğrulama */
    if($dep===''||$dst===''||$dt===''||$pr===''||$cap==='') throw new Exception("Zorunlu alanları doldurun.");
    if(!is_numeric($pr)||(float)$pr<0) throw new Exception("Geçersiz fiyat.");
    if(!ctype_digit($cap)||(int)$cap<=0) throw new Exception("Geçersiz kapasite.");
    /* datetime-local -> 'Y-m-d H:i[:s]' normalize */
    $dt_db=str_replace('T',' ',$dt);
    $at_db=$at!==''?str_replace('T',' ',$at):null;

    /* ZAMAN KONTROLLERİ (İstanbul saatiyle) */
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

    // kalkış zamanı
    $depDT = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dt_db, new DateTimeZone('Europe/Istanbul'));
    if (!$depDT) { // saniye gelirse vs.
        $depDT = new DateTimeImmutable($dt_db, new DateTimeZone('Europe/Istanbul'));
    }

    if ($depDT <= $now) {
        throw new Exception("Kalkış zamanı geçmiş olamaz. (Geçmiş tarihli sefer eklenemez/güncellenemez)");
    }
    // varış zamanı 
    $arrDT = null;
    if ($at_db !== null) {
        $arrDT = DateTimeImmutable::createFromFormat('Y-m-d H:i', $at_db, new DateTimeZone('Europe/Istanbul'));
        if (!$arrDT) { $arrDT = new DateTimeImmutable($at_db, new DateTimeZone('Europe/Istanbul')); }
        if ($arrDT <= $depDT) {
            throw new Exception("Varış zamanı, kalkıştan sonra olmalıdır.");
        }
    }

    if($_POST['action']==='create'){
      $q=$db->prepare("INSERT INTO trips (company_id,departure_city,destination_city,departure_time,arrival_time,price,capacity)
                       VALUES (:cid,:dep,:dst,:dt,:at,:pr,:cp)");
      $q->execute([
        ':cid'=>$company_id, ':dep'=>$dep, ':dst'=>$dst,
        ':dt'=>$depDT->format('Y-m-d H:i:s'),
        ':at'=>$arrDT ? $arrDT->format('Y-m-d H:i:s') : null,
        ':pr'=>(float)$pr, ':cp'=>(int)$cap
      ]);
      $success="Sefer eklendi.";
    } else {
      $id=(int)($_POST['trip_id']??0);
      $q=$db->prepare("UPDATE trips SET departure_city=:dep,destination_city=:dst,departure_time=:dt,arrival_time=:at,price=:pr,capacity=:cp
                       WHERE id=:id AND company_id=:cid");
      $q->execute([
        ':dep'=>$dep, ':dst'=>$dst,
        ':dt'=>$depDT->format('Y-m-d H:i:s'),
        ':at'=>$arrDT ? $arrDT->format('Y-m-d H:i:s') : null,
        ':pr'=>(float)$pr, ':cp'=>(int)$cap,
        ':id'=>$id, ':cid'=>$company_id
      ]);
      $success="Sefer güncellendi.";
    }
  }catch(Exception $e){ $error=$e->getMessage(); }
}

/* ---------- Düzenlenecek kayıt ---------- */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editId>0){
  $e=$db->prepare("SELECT * FROM trips WHERE id=? AND company_id=?");
  $e->execute([$editId,$company_id]);
  $editRow=$e->fetch(PDO::FETCH_ASSOC);
  if(!$editRow){ $error="Sefer bulunamadı."; $editId=0; }
}

/* ---------- Liste ---------- */
$rows = $db->prepare("SELECT * FROM trips WHERE company_id=? ORDER BY datetime(departure_time) DESC");
$rows->execute([$company_id]);
$list = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Sefer Yönetimi — <?php echo htmlspecialchars($firmRow['name']);?></title>
<style>
*{box-sizing:border-box}
:root{
  --primary:#007bff;
  --primary-dark:#0056b3;
  --danger:#dc3545;
  --danger-dark:#b02a37;
  --bg:#f4f6f8;
  --card:#fff;
  --text:#333;
  --muted:#6b7280;
  --border:#e5e7eb;
  --success-bg:#e7f7ed;
  --success-bd:#a3d9ad;
  --success-fg:#1e7e34;
  --error-bg:#ffe8e8;
  --error-bd:#f5c2c7;
  --error-fg:#b02a37;
  --radius:10px;
  --shadow:0 2px 10px rgba(0,0,0,.10);
}
html,body{height:100%}
body{
  font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  background:var(--bg);
  color:var(--text);
  margin:0;
}

/* ===== Top Nav ===== */
.nav{
  background:var(--primary);
  color:#fff;
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:12px 30px;
}
.nav a{
  color:#fff;
  text-decoration:none;
  font-weight:600;
}
.nav a:hover{ text-decoration:underline }
.wrap{ max-width:1100px; margin:24px auto; padding:0 16px }
.card{
  background:var(--card);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:16px;
  margin-bottom:16px;
}
.row{ display:flex; gap:12px; flex-wrap:wrap }
.col{ flex:1; min-width:220px }

label{ display:block; font-weight:600; margin:8px 0 6px }
input, select, textarea{
  width:100%;
  padding:10px 12px;
  border:1px solid #ccc;
  border-radius:8px;
  background:#fff;
  font:inherit;
}
input[type="datetime-local"]{ padding:8px 10px }
input:focus, select:focus, textarea:focus{
  outline:none;
  border-color:var(--primary);
  box-shadow:0 0 0 3px rgba(0,123,255,.15);
}

a.btn, button.btn, input[type="submit"].btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:38px;
  padding:0 14px;
  line-height:1;
  font-weight:600;
  font-size:14px;
  border-radius:8px;
  border:none;
  text-decoration:none;
  cursor:pointer;
  background:var(--primary);
  color:#fff;
  transition:background .15s ease, transform .02s ease;
}
a.btn:hover, button.btn:hover, input[type="submit"].btn:hover{
  background:var(--primary-dark);
}
a.btn:active, button.btn:active, input[type="submit"].btn:active{
  transform:translateY(0.5px);
}
.btn-secondary{ background:#6c757d !important; }
.btn-secondary:hover{ background:#5c636a !important; }
.btn-danger{ background:var(--danger) !important; }
.btn-danger:hover{ background:var(--danger-dark) !important; }
.btn[disabled], .btn:disabled{ opacity:.6; cursor:not-allowed; }
.msg{ margin:10px 0; padding:10px 12px; border-radius:8px; border:1px solid transparent }
.ok{ background:var(--success-bg); border-color:var(--success-bd); color:var(--success-fg) }
.err{ background:var(--error-bg);   border-color:var(--error-bd);   color:var(--error-fg) }
table{
  width:100%;
  border-collapse:collapse;
  background:#fff;
}
th, td{
  padding:10px 12px;
  border-bottom:1px solid var(--border);
  text-align:left;
  vertical-align:middle;
}
th{
  background:var(--primary);
  color:#fff;
  position:sticky;
  top:0;
  z-index:1;
}
tbody tr:hover{ background:#f8fafc }
.actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px }
.text-muted{ color:var(--muted) }
@media (max-width: 640px){
  .nav{ padding:10px 16px }
  .wrap{ padding:0 12px }
  th, td{ padding:8px }
  a.btn, button.btn, input[type="submit"].btn{ height:36px; padding:0 12px; font-size:13px }
}
</style>
</head>
<body>

<div class="nav">
  <div>Firma Paneli — <?php echo htmlspecialchars($firmRow['name']);?></div>
  <div><a href="company_admin_panel.php">Geri</a></div>
</div>

<div class="wrap">
  <?php if($success):?><div class="msg ok"><?php echo htmlspecialchars($success);?></div><?php endif;?>
  <?php if($error):?><div class="msg err"><?php echo htmlspecialchars($error);?></div><?php endif;?>

  <div class="card">
    <h3><?php echo $editId>0?'Seferi Düzenle':'Yeni Sefer Ekle';?></h3>
    <form method="POST">
      <input type="hidden" name="action" value="<?php echo $editId>0?'update':'create';?>">
      <?php if($editId>0):?><input type="hidden" name="trip_id" value="<?php echo (int)$editId;?>"><?php endif;?>
      <div class="row">
        <div class="col"><label>Kalkış *</label><input name="departure_city" value="<?php echo htmlspecialchars($editRow['departure_city']??'');?>" required></div>
        <div class="col"><label>Varış *</label><input name="destination_city" value="<?php echo htmlspecialchars($editRow['destination_city']??'');?>" required></div>
        <div class="col"><label>Kalkış Zamanı *</label><input type="datetime-local" name="departure_time" value="<?php echo isset($editRow['departure_time'])?str_replace(' ','T',$editRow['departure_time']):'';?>" required></div>
        <div class="col"><label>Varış Zamanı</label><input type="datetime-local" name="arrival_time" value="<?php echo isset($editRow['arrival_time'])?str_replace(' ','T',$editRow['arrival_time']):'';?>"></div>
        <div class="col"><label>Fiyat *</label><input type="number" min="0" step="0.01" name="price" value="<?php echo htmlspecialchars($editRow['price']??'');?>" required></div>
        <div class="col"><label>Kapasite *</label><input type="number" min="1" step="1" name="capacity" value="<?php echo htmlspecialchars($editRow['capacity']??'');?>" required></div>
      </div>
      <div style="margin-top:10px">
        <button class="btn" type="submit"><?php echo $editId>0?'Güncelle':'Ekle';?></button>
        <?php if($editId>0):?> <a class="btn" href="manage_trips.php">Yeni Ekle Modu</a><?php endif;?>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Sefer Listesi</h3>
    <table>
      <thead><tr><th>ID</th><th>Rota</th><th>Kalkış</th><th>Varış</th><th>Fiyat</th><th>Kapasite</th><th>İşlem</th></tr></thead>
      <tbody>
        <?php if(!$list):?>
          <tr><td colspan="7">Kayıt yok.</td></tr>
        <?php else: foreach($list as $r):?>
          <tr>
            <td><?php echo (int)$r['id'];?></td>
            <td><?php echo htmlspecialchars($r['departure_city']." → ".$r['destination_city']);?></td>
            <td><?php echo htmlspecialchars($r['departure_time']);?></td>
            <td><?php echo htmlspecialchars($r['arrival_time']);?></td>
            <td><?php echo number_format((float)$r['price'],2);?> ₺</td>
            <td><?php echo (int)$r['capacity'];?></td>
            <td class="actions">
              <a class="btn" href="manage_trips.php?edit=<?php echo (int)$r['id'];?>">Düzenle</a>
              <form method="POST" style="display:inline" onsubmit="return confirm('Seferi silmek istiyor musunuz?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="trip_id" value="<?php echo (int)$r['id'];?>">
                <button class="btn btn-danger" type="submit">Sil</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif;?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
