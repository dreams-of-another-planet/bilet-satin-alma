<?php
session_start();
require 'db.php'; 

$error_message = '';
$full_name = ''; 
$email = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim(htmlspecialchars($_POST['full_name']));
    $email = trim(strtolower($_POST['email']));
    $password = $_POST['password'];

    if (empty($full_name) || empty($email) || empty($password)) {
        $error_message = "Error: All fields are required.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO User (id, full_name, email, role, password, company_id, balance) 
                VALUES (:id, :full_name, :email, 'user', :password, NULL, 800)";

        $id = bin2hex(random_bytes(16));
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashed_password);
            
            $stmt->execute(); // if this executes we created a user!
            
            header("Location: login.php");
            exit;

        } catch (PDOException $e) {
            // Check for UNIQUE constraint violation (email already exists)
            if ($e->getCode() == '23000') {
                $error_message = "That email address is already registered.";
            } else {
                $error_message = "Registration error: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
</head>
<body>
    <h1>User Registration</h1>

    <?php if ($error_message): ?>
        <p style="color: red;"><?php echo $error_message; ?></p>
    <?php endif; ?>

    <form action="signup.php" method="POST">
        <label for="full_name">Full Name:</label>
        <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>" required><br><br>

        <label for="email">Email:</label>
        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required><br><br>

        <label for="password">Password:</label>
        <input type="password" id="password" name="password" required><br><br>

        <input type="submit" value="Register">
    </form>
    <p>Already have an account? <a href="login.php">Login here</a></p>
</body>
</html>