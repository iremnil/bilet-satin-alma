<?php
session_start();

try {
  // 🔁 ESKİ: $db = new PDO('sqlite:C:/sqlite/database.db');
  // ✅ YENİ (Docker + lokal uyumlu):
  $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/sqlite/database.db';
  $db = new PDO('sqlite:' . $sqlitePath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON");
  $db->exec("PRAGMA busy_timeout = 3000");
} catch (Exception $e) { die("DB hata: ".htmlspecialchars($e->getMessage())); }

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $stmt = $db->prepare("
        SELECT id, full_name, email, password, role, company_id
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);

        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['username'] = $user['full_name'];
        $_SESSION['role']     = $user['role'];

        if ($user['role'] === 'company_admin') {
            if (empty($user['company_id'])) {
                $_SESSION = []; session_destroy();
                $error = "Bu company admin hesabına firma atanmadı. Lütfen yönetici ile iletişime geçin.";
            } else {
                $_SESSION['company_id'] = (int)$user['company_id'];
                header("Location: company_admin_panel.php");
                exit();
            }
        } elseif ($user['role'] === 'admin') {
            header("Location: admin_panel.php");
            exit();
        } else {
            header("Location: index.php");
            exit();
        }
    } else {
        $error = "E-posta veya şifre hatalı.";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Giriş Yap</title>
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

        .error {
            color: red;
            text-align: center;
            margin-top: 10px;
            font-size: 14px;
        }

        .register-link {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #4a90e2;
            text-decoration: none;
        }

        .register-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <form method="POST" autocomplete="off">
        <h2>Giriş Yap</h2>
        <input type="email" name="email" placeholder="E-posta" required>
        <input type="password" name="password" placeholder="Şifre" required>
        <button type="submit">Giriş Yap</button>
        <?php if (!empty($error)) echo "<p class='error'>".htmlspecialchars($error)."</p>"; ?>
        <a href="register.php" class="register-link">Hesabın yok mu? Kayıt Ol</a>
    </form>
</body>
</html>
