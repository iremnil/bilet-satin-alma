<?php
try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }


$success = $error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if ($full_name && $email && $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (full_name, email, password, role, company_id, balance, created_at) 
                              VALUES (:full_name, :email, :password, 'user', NULL, 800, CURRENT_TIMESTAMP)");
        try {
            $stmt->execute([':full_name' => $full_name, ':email' => $email, ':password' => $hash]);
            $success = "✅ Hesap oluşturuldu! <a href='login.php'>Giriş yap</a>";
        } catch (PDOException $e) {
            $error = "❌ Bu e-posta zaten kayıtlı!";
        }
    } else {
        $error = "⚠️ Lütfen tüm alanları doldurun!";
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kayıt Ol</title>
    <style>
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #4a90e2, #50c9c3);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        form {
            background: #fff;
            padding: 25px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            width: 350px;
        }

        form h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }

        form input {
            width: 100%;
            padding: 10px 12px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
            transition: 0.3s;
        }

        form input:focus {
            border-color: #4a90e2;
            outline: none;
            box-shadow: 0 0 5px rgba(74, 144, 226, 0.5);
        }

        form button {
            width: 100%;
            padding: 12px;
            background: #4a90e2;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
            transition: background 0.3s;
        }

        form button:hover {
            background: #3a78bf;
        }

        .message {
            text-align: center;
            font-size: 14px;
            margin-top: 15px;
            margin-bottom: 5px;
        }

        .message.success {
            color: green;
        }

        .message.error {
            color: red;
        }

        .message a {
            color: #4a90e2;
            text-decoration: none;
        }

        .message a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <form method="POST">
        <h2>Kayıt Ol</h2>
        <input type="text" name="full_name" placeholder="Ad Soyad" required>
        <input type="email" name="email" placeholder="E-posta" required>
        <input type="password" name="password" placeholder="Şifre" required>
        <button type="submit">Kayıt Ol</button>
        <?php if (!empty($success)) echo "<p class='message success'>$success</p>"; ?>
        <?php if (!empty($error)) echo "<p class='message error'>$error</p>"; ?>
    </form>
</body>
</html>
