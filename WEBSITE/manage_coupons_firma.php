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

// Sil
if (($_SERVER['REQUEST_METHOD']??'')==='POST' && ($_POST['action']??'')==='delete'){
  $id=(int)($_POST['coupon_id']??0);
  try{
    // Sadece kendi kuponunu silebilsin
    $del=$db->prepare("DELETE FROM coupons WHERE id=:id AND company_id=:cid");
    $del->execute([':id'=>$id,':cid'=>$company_id]);
    $success = $del->rowCount()>0 ? "Kupon silindi." : "Kupon bulunamadı.";
  }catch(Exception $e){ $error="Silme hatası: ".$e->getMessage(); }
}

// Güncelle
if (($_SERVER['REQUEST_METHOD']??'')==='POST' && ($_POST['action']??'')==='update'){
  $id=(int)($_POST['coupon_id']??0);
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

    // kod benzersiz (sadece kendi kayıtları içinde değil, )
    $ex=$db->prepare("SELECT COUNT(1) FROM coupons WHERE code=? AND id<>?");
    $ex->execute([$code,$id]);
    if((int)$ex->fetchColumn()>0) throw new Exception("Bu kod başka kuponla çakışıyor.");

    $upd=$db->prepare("
      UPDATE coupons
      SET code=:c, discount=:d, usage_limit=:u, expire_date=:e
      WHERE id=:id AND company_id=:cid
    ");
    $upd->bindValue(':c',$code); $upd->bindValue(':d',$discount);
    if($usage_db===null) $upd->bindValue(':u',null,PDO::PARAM_NULL); else $upd->bindValue(':u',$usage_db,PDO::PARAM_INT);
    $upd->bindValue(':e',$exp);
    $upd->bindValue(':id',$id,PDO::PARAM_INT);
    $upd->bindValue(':cid',$company_id,PDO::PARAM_INT);
    $upd->execute();
    $success="Kupon güncellendi.";
  }catch(Exception $e){ $error=$e->getMessage(); }
}

// Düzenlenecek kupon
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editId>0){
  $e=$db->prepare("SELECT * FROM coupons WHERE id=? AND company_id=?");
  $e->execute([$editId,$company_id]);
  $editRow=$e->fetch(PDO::FETCH_ASSOC);
  if(!$editRow){ $error="Kupon bulunamadı."; $editId=0; }
}

// Liste (yalnızca BU firmanın kuponları)
$list=$db->prepare("
  SELECT id, code, discount, usage_limit, expire_date, created_at
  FROM coupons
  WHERE company_id=?
  ORDER BY datetime(created_at) DESC
");
$list->execute([$company_id]);
$rows=$list->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Kupon Yönetimi — <?php echo htmlspecialchars($firmRow['name']);?></title>
<style>
body {
  font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
  background: #f4f6f8;
  margin: 0;
}

.nav {
  background: #007bff;
  color: #fff;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 30px;
}
.nav a {
  color: #fff;
  text-decoration: none;
  font-weight: bold;
}
.nav a:hover {
  text-decoration: underline;
}
.wrap {
  max-width: 1000px;
  margin: 24px auto;
  padding: 0 16px;
}
.card {
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, .1);
  padding: 16px;
  margin-bottom: 16px;
}
input, select, textarea {
  width: 100%;
  padding: 10px;
  border: 1px solid #ccc;
  border-radius: 8px;
  font-size: 14px;
}
input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: #007bff;
  box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.2);
}
a.btn, button.btn, input[type="submit"].btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 36px;                 
  padding: 0 14px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 8px;
  text-decoration: none;
  line-height: 1;
  cursor: pointer;
  background: #007bff;
  color: #fff;
  border: none;
  transition: background 0.15s ease, transform 0.05s ease;
}

a.btn:hover, button.btn:hover, input[type="submit"].btn:hover {
  background: #0056b3;
}
a.btn:active, button.btn:active, input[type="submit"].btn:active {
  transform: translateY(0.5px);
}
button.btn.btn-danger, a.btn.btn-danger {
  background: #dc3545;
}
button.btn.btn-danger:hover, a.btn.btn-danger:hover {
  background: #b02a37;
}
table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
}
th, td {
  padding: 10px;
  border-bottom: 1px solid #eee;
  text-align: left;
}
th {
  background: #007bff;
  color: #fff;
}
.msg {
  margin: 10px 0;
  padding: 10px;
  border-radius: 8px;
}
.ok {
  background: #e7f7ed;
  color: #1e7e34;
  border: 1px solid #a3d9ad;
}
.err {
  background: #ffe8e8;
  color: #b02a37;
  border: 1px solid #f5c2c7;
}
@media (max-width: 640px) {
  .nav {
    padding: 10px 16px;
  }
  .wrap {
    padding: 0 12px;
  }
  a.btn, button.btn, input[type="submit"].btn {
    height: 34px;
    padding: 0 10px;
    font-size: 13px;
  }
  th, td {
    padding: 8px;
  }
}
</style>

<div class="nav"><div>Firma Paneli — <?php echo htmlspecialchars($firmRow['name']);?></div><div><a href="company_admin_panel.php">Geri</a></div></div>
<div class="wrap">
  <?php if($success):?><div class="msg ok"><?php echo htmlspecialchars($success);?></div><?php endif;?>
  <?php if($error):?><div class="msg err"><?php echo htmlspecialchars($error);?></div><?php endif;?>

  <?php if($editId>0 && $editRow):?>
  <div class="card">
    <h3>Kuponu Düzenle (#<?php echo (int)$editRow['id'];?>)</h3>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="coupon_id" value="<?php echo (int)$editRow['id'];?>">
      <label>Kod *</label><input name="code" value="<?php echo htmlspecialchars($editRow['code']);?>" required>
      <label>İndirim (₺) *</label><input type="number" min="0.01" step="0.01" name="discount" value="<?php echo htmlspecialchars($editRow['discount']);?>" required>
      <label>Kullanıcı Başı Limit</label><input type="number" min="1" step="1" name="usage_limit" value="<?php echo htmlspecialchars($editRow['usage_limit'] ?? '');?>">
      <label>Son Tarih *</label><input type="date" name="expire_date" value="<?php echo htmlspecialchars($editRow['expire_date']);?>" required>
      <div style="margin-top:10px"><button class="btn" type="submit">Kaydet</button> <a class="btn" href="manage_coupons_firma.php">Vazgeç</a></div>
    </form>
  </div>
  <?php endif;?>

  <div class="card">
    <h3>Kupon Listesi</h3>
    <table>
      <thead><tr><th>ID</th><th>Kod</th><th>İndirim</th><th>Limit</th><th>Son Tarih</th><th>Oluşturma</th><th>İşlem</th></tr></thead>
      <tbody>
        <?php if(!$rows):?><tr><td colspan="7">Kayıt yok.</td></tr>
        <?php else: foreach($rows as $r):?>
          <tr>
            <td><?php echo (int)$r['id'];?></td>
            <td><?php echo htmlspecialchars($r['code']);?></td>
            <td><?php echo number_format((float)$r['discount'],2);?> ₺</td>
            <td><?php echo $r['usage_limit']!==null ? (int)$r['usage_limit'] : 'Sınırsız';?></td>
            <td><?php echo htmlspecialchars($r['expire_date']);?></td>
            <td><?php echo htmlspecialchars($r['created_at']);?></td>
            <td>
              <a class="btn" href="manage_coupons_firma.php?edit=<?php echo (int)$r['id'];?>">Düzenle</a>
              <form method="POST" style="display:inline" onsubmit="return confirm('Bu kuponu silmek istiyor musunuz?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="coupon_id" value="<?php echo (int)$r['id'];?>">
                <button class="btn btn-danger" type="submit">Sil</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif;?>
      </tbody>
    </table>
  </div>
</div>
</body></html>
