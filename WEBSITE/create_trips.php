<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'company_admin' || !isset($_SESSION['company_id'])) {
    header("Location: login.php"); exit();
}
$company_id = (int)$_SESSION['company_id'];

// TR saati
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
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $dep = trim($_POST['departure_city'] ?? '');
    $dst = trim($_POST['destination_city'] ?? '');
    $dt  = trim($_POST['departure_time'] ?? '');   // HTML datetime-local => "YYYY-MM-DDTHH:MM"
    $at  = trim($_POST['arrival_time'] ?? '');
    $pr  = trim($_POST['price'] ?? '');
    $cap = trim($_POST['capacity'] ?? '');

    try {
        // Zorunlu alanlar
        if ($dep===''||$dst===''||$dt===''||$pr===''||$cap==='') throw new Exception("Zorunlu alanları doldurun.");
        if (!is_numeric($pr) || (float)$pr<0) throw new Exception("Geçersiz fiyat.");
        if (!ctype_digit($cap) || (int)$cap<=0) throw new Exception("Geçersiz kapasite.");

        // datetime-local -> "YYYY-MM-DD HH:MM:SS"
        $dt_db = str_replace('T',' ',$dt);
        $at_db = $at!=='' ? str_replace('T',' ',$at) : null;

        //ZAMAN KONTROLLERİ 
        $now   = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
        $depDT = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dt_db, new DateTimeZone('Europe/Istanbul'))
                 ?: new DateTimeImmutable($dt_db, new DateTimeZone('Europe/Istanbul'));

        if ($depDT <= $now) {
            throw new Exception("Kalkış zamanı geçmiş olamaz. (Geçmiş tarihli sefer eklenemez)");
        }

        if ($at_db !== null && $at_db !== '') {
            $arrDT = DateTimeImmutable::createFromFormat('Y-m-d H:i', $at_db, new DateTimeZone('Europe/Istanbul'))
                     ?: new DateTimeImmutable($at_db, new DateTimeZone('Europe/Istanbul'));
            if ($arrDT <= $depDT) {
                throw new Exception("Varış zamanı, kalkıştan sonra olmalıdır.");
            }
        }

        // Aynı şehir kontrolü
        if (mb_strtolower($dep,'UTF-8') === mb_strtolower($dst,'UTF-8')) {
            throw new Exception("Kalkış ve varış şehirleri farklı olmalıdır.");
        }

        // Kayıt
        $ins = $db->prepare("
            INSERT INTO trips (company_id, departure_city, destination_city, departure_time, arrival_time, price, capacity)
            VALUES (:cid,:dep,:dst,:dt,:at,:pr,:cap)
        ");
        $ins->execute([
            ':cid'=>$company_id,
            ':dep'=>$dep,
            ':dst'=>$dst,
            ':dt'=>$depDT->format('Y-m-d H:i:s'),
            ':at'=>$at_db ? (new DateTimeImmutable($at_db, new DateTimeZone('Europe/Istanbul')))->format('Y-m-d H:i:s') : null,
            ':pr'=>(float)$pr,
            ':cap'=>(int)$cap
        ]);

        $success="Sefer eklendi.";
        $_POST=[];
    } catch (Exception $e) { $error=$e->getMessage(); }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Yeni Sefer — <?php echo htmlspecialchars($firmRow['name']);?></title>
<style>
body{font-family:'Segoe UI', Tahoma, Verdana, sans-serif;background:#f4f6f8;margin:0}
.nav{background:#007bff;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:12px 30px}
.nav a{color:#fff;text-decoration:none;font-weight:bold}
.wrap{max-width:800px;margin:24px auto;background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:20px}
label{display:block;margin-top:10px;font-weight:600}
input{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
.btn{margin-top:12px;background:#007bff;color:#fff;border:none;padding:10px 14px;border-radius:8px;cursor:pointer}
.msg{margin:10px 0;padding:10px;border-radius:8px}
.ok{background:#e7f7ed;color:#1e7e34;border:1px solid #a3d9ad}
.err{background:#ffe8e8;color:#b02a37;border:1px solid #f5c2c7}
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
  <h2>Yeni Sefer</h2>
  <form method="POST">
    <label>Kalkış Şehri *</label>
    <input name="departure_city" value="<?php echo htmlspecialchars($_POST['departure_city']??'');?>" required>

    <label>Varış Şehri *</label>
    <input name="destination_city" value="<?php echo htmlspecialchars($_POST['destination_city']??'');?>" required>

    <label>Kalkış Zamanı *</label>
    <input type="datetime-local" name="departure_time" value="<?php echo htmlspecialchars($_POST['departure_time']??'');?>" required>

    <label>Varış Zamanı</label>
    <input type="datetime-local" name="arrival_time" value="<?php echo htmlspecialchars($_POST['arrival_time']??'');?>">

    <label>Fiyat (₺) *</label>
    <input type="number" min="0" step="0.01" name="price" value="<?php echo htmlspecialchars($_POST['price']??'');?>" required>

    <label>Kapasite *</label>
    <input type="number" min="1" step="1" name="capacity" value="<?php echo htmlspecialchars($_POST['capacity']??'');?>" required>

    <button class="btn" type="submit">Kaydet</button>
  </form>
</div>
</body>
</html>
