<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'company_admin' || !isset($_SESSION['company_id'])) {
    header("Location: login.php"); exit();
}
$company_id=(int)$_SESSION['company_id'];

try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }

$firm=$db->prepare("SELECT id,name FROM firms WHERE id=?");
$firm->execute([$company_id]);
$firmRow=$firm->fetch(PDO::FETCH_ASSOC) ?: die("Firma bulunamadı.");

$success=$error="";
if($_SERVER['REQUEST_METHOD']==='POST'){
  $code=strtoupper(trim($_POST['code']??'')); $discount=trim($_POST['discount']??'');
  $usage=trim($_POST['usage_limit']??'');     $exp=trim($_POST['expire_date']??'');
  try{
    if($code===''||$discount===''||$exp==='') throw new Exception("Kod, indirim, son tarih zorunlu.");
    if(!is_numeric($discount)||(float)$discount<=0) throw new Exception("Geçerli indirim gir.");
    $discount=(float)$discount;
    if($usage==='') $usage_db=null; else{
      if(!ctype_digit($usage)||(int)$usage<=0) throw new Exception("Limit pozitif tam sayı.");
      $usage_db=(int)$usage;
    }
    $dt=DateTime::createFromFormat('Y-m-d',$exp);
    if(!$dt||$dt->format('Y-m-d')!==$exp) throw new Exception("Tarih formatı YYYY-MM-DD olmalı.");

    $ex=$db->prepare("SELECT COUNT(1) FROM coupons WHERE code=?");
    $ex->execute([$code]);
    if((int)$ex->fetchColumn()>0) throw new Exception("Bu kod zaten var.");

    $ins=$db->prepare("INSERT INTO coupons (code,discount,company_id,usage_limit,expire_date,created_at)
                       VALUES (:c,:d,:cid,:u,:e,datetime('now','localtime'))");
    $ins->bindValue(':c',$code); $ins->bindValue(':d',$discount);
    $ins->bindValue(':cid',$company_id,PDO::PARAM_INT);
    if($usage_db===null) $ins->bindValue(':u',null,PDO::PARAM_NULL); else $ins->bindValue(':u',$usage_db,PDO::PARAM_INT);
    $ins->bindValue(':e',$exp);
    $ins->execute();
    $success="Kupon eklendi."; $_POST=[];
  }catch(Exception $e){ $error=$e->getMessage(); }
}
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Yeni Kupon — <?php echo htmlspecialchars($firmRow['name']);?></title>
<style>body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;background:#f4f6f8;margin:0}
.nav{background:#007bff;color:#fff;display:flex;justify-content:space-between;align-items:center;padding:12px 30px}
.nav a{color:#fff;text-decoration:none;font-weight:bold}
.wrap{max-width:720px;margin:24px auto;background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1);padding:16px}
label{display:block;margin-top:10px;font-weight:600}
input{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px}
.btn{margin-top:12px;background:#007bff;color:#fff;border:none;padding:10px 14px;border-radius:8px;cursor:pointer}
.msg{margin:10px 0;padding:10px;border-radius:8px}.ok{background:#e7f7ed;color:#1e7e34;border:1px solid #a3d9ad}.err{background:#ffe8e8;color:#b02a37;border:1px solid #f5c2c7}
</style></head><body>
<div class="nav"><div>Firma Paneli — <?php echo htmlspecialchars($firmRow['name']);?></div><div><a href="company_admin_panel.php">Geri</a></div></div>
<div class="wrap">
  <?php if($success):?><div class="msg ok"><?php echo htmlspecialchars($success);?></div><?php endif;?>
  <?php if($error):?><div class="msg err"><?php echo htmlspecialchars($error);?></div><?php endif;?>
  <h3>Yeni Kupon</h3>
  <form method="POST">
    <label>Kod *</label><input name="code" value="<?php echo htmlspecialchars($_POST['code']??'');?>" required>
    <label>İndirim (₺) *</label><input type="number" min="0.01" step="0.01" name="discount" value="<?php echo htmlspecialchars($_POST['discount']??'');?>" required>
    <label>Kullanıcı Başı Limit</label><input type="number" min="1" step="1" name="usage_limit" value="<?php echo htmlspecialchars($_POST['usage_limit']??'');?>">
    <label>Son Kullanma Tarihi *</label><input type="date" name="expire_date" value="<?php echo htmlspecialchars($_POST['expire_date']??'');?>" required>
    <button class="btn" type="submit">Kaydet</button>
  </form>
</div>
</body></html>
