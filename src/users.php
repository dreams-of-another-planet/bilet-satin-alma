<?php
// src/users.php - Admin User Management Panel (Admin and Firma Admin Assignment)
session_start();
require 'db.php'; 

// === 1. SECURITY CHECK: Only Admin can access this page ===
// The only system-wide user management is done by the Admin role.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied. Only System Admins can view this page.");
}

// Global variables
$status_message = '';
$error = false;
$edit_user = null; 
$allowed_roles = ['user', 'company', 'admin']; 

// Fetch companies for assignment dropdown
try {
    $companies = $pdo->query("SELECT id, name FROM Bus_Company ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_message = "Error fetching company list for assignment: " . $e->getMessage();
    $error = true;
    $companies = [];
}

// === 2. HANDLE CUD OPERATIONS ===

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    
    // Common input retrieval
    $id = $_POST['id'] ?? null;
    $full_name = trim(htmlspecialchars($_POST['full_name']));
    $email = trim(strtolower($_POST['email']));
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';
    $balance = (float)($_POST['balance'] ?? 800.0);
    $company_id_form = trim($_POST['company_id_assignment'] ?? null); // NEW: Get assignment ID

    // Determine final company_id: only applies if role is 'owner' (Firma Admin)
    $final_company_id = ($role === 'company' && $company_id_form !== 'none') ? $company_id_form : null;
    
    // Basic validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowed_roles)) {
        $status_message = "Invalid email or role provided.";
        $error = true;
    } 
    // NEW: Validation for owner role assignment
    elseif ($role === 'company' && $final_company_id === null) {
        $status_message = "Firma Admin (Owner) rolü için bir Otobüs Firması atanmalıdır.";
        $error = true;
    }
    
    else {
        try {
            if ($action === 'add') {
                // C: Create (Add New User/Owner)
                if (empty($password)) { throw new Exception("Password is required for new users."); }
                
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $id = generate_random_id(); // Use function to generate secure ID
                
                // SQL updated to include company_id
                $sql = "INSERT INTO User (id, full_name, email, password, role, balance, company_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id, $full_name, $email, $hashed_password, $role, $balance, $final_company_id]);
                $status_message = "User/Owner added successfully!";
                
            } elseif ($action === 'update' && $id) {
                // U: Update Existing User/Owner
                
                // Updated SQL to include company_id
                $sql_parts = ["full_name=?, email=?, role=?, balance=?, company_id=?"];
                $params = [$full_name, $email, $role, $balance, $final_company_id];
                
                if (!empty($password)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql_parts[] = "password=?";
                    // Add password hash to the beginning of the parameter list for correct order
                    array_unshift($params, $hashed_password); 
                }
                $params[] = $id; // Add ID to the end

                // Build the final SQL statement dynamically
                $sql = "UPDATE User SET " . implode(', ', $sql_parts) . " WHERE id=?";
                $stmt = $pdo->prepare($sql);
                // Execute using the dynamically built parameter array
                $stmt->execute($params);
                $status_message = "User ID {$id} updated successfully!";
            }
        } catch (PDOException $e) {
            $status_message = "Database error: " . $e->getMessage();
            $error = true;
        } catch (Exception $e) {
             $status_message = "Error: " . $e->getMessage();
             $error = true;
        }
    }

} elseif (isset($_GET['action']) && isset($_GET['id'])) {
    // Handle Delete and Edit View preparation (GET)
    $action = $_GET['action'];
    $user_id = $_GET['id'];

    if ($action === 'delete') {
        // D: Delete User
        try {
            $stmt = $pdo->prepare("DELETE FROM User WHERE id = ?");
            $stmt->execute([$user_id]);
            $status_message = "User ID {$user_id} deleted successfully!";
            header("Location: users.php");
            exit;
        } catch (PDOException $e) {
            $status_message = "Deletion failed: " . $e->getMessage();
            $error = true;
        }
    } elseif ($action === 'edit') {
        // Prepare view for U: Update (Fetch current user data including company_id)
        $stmt = $pdo->prepare("SELECT id, full_name, email, role, balance, company_id FROM User WHERE id = ?");
        $stmt->execute([$user_id]);
        $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_user) {
            $status_message = "User not found.";
            $error = true;
        }
    }
}

// Helper function (needed because it was used implicitly in the ADD logic)
function generate_random_id() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return md5(uniqid(mt_rand(), true)); 
    }
}


// === 3. R (Read) - Fetch all users for display (Joined with Bus_Company) ===
try {
    $sql_read = "
        SELECT 
            U.id, U.full_name, U.email, U.role, U.balance, U.created_at, 
            BC.name AS company_name
        FROM User U
        LEFT JOIN Bus_Company BC ON U.company_id = BC.id
        ORDER BY U.role, U.created_at DESC
    ";
    $users = $pdo->query($sql_read)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_message = "Error fetching users: " . $e->getMessage();
    $error = true;
    $users = [];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel - Users</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .form-container { margin-bottom: 30px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; }
        a { text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>User Management Panel</h1>
    <p>Logged in as: <b><?php echo htmlspecialchars($_SESSION['full_name']); ?></b> 
        (<a href="profile.php">My Profile</a> | 
        <a href="companies.php">Manage Firms</a> | 
        <a href="logout.php">Logout</a>)
    </p>

    <?php if ($status_message): ?>
        <p class="<?php echo $error ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($status_message); ?>
        </p>
    <?php endif; ?>

    <div class="form-container">
        <h2><?php echo $edit_user ? 'Edit User (ID: ' . $edit_user['id'] . ')' : 'Add New User/Owner'; ?></h2>
        
        <form action="users.php" method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_user ? 'update' : 'add'; ?>">
            <?php if ($edit_user): ?>
                <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
            <?php endif; ?>

            <label>Full Name:</label>
            <input type="text" name="full_name" required 
                   value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>"><br><br>

            <label>Email:</label>
            <input type="email" name="email" required 
                   value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>"><br><br>

            <label>Password: (<?php echo $edit_user ? 'Leave blank to keep current' : 'Required for new user'; ?>)</label>
            <input type="password" name="password"><br><br>

            <label>Role:</label>
            <select name="role" required>
                <?php foreach ($allowed_roles as $r): ?>
                    <option value="<?php echo $r; ?>" 
                            <?php if (($edit_user['role'] ?? '') === $r) echo 'selected'; ?>>
                        <?php echo ucfirst($r); ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>
            
            <label>Atanacak Firma ID (Yalnızca 'Owner' için):</label>
            <select name="company_id_assignment">
                <option value="none">-- Atama Yok --</option>
                <?php 
                $current_assigned_id = $edit_user['company_id'] ?? null;
                foreach ($companies as $company): ?>
                    <option value="<?php echo $company['id']; ?>" 
                            <?php if ($current_assigned_id === $company['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($company['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>


            <label>Balance:</label>
            <input type="number" step="0.01" name="balance" required 
                   value="<?php echo htmlspecialchars($edit_user['balance'] ?? 800.0); ?>"><br><br>

            <input type="submit" value="<?php echo $edit_user ? 'Update User' : 'Add User'; ?>">
            <?php if ($edit_user): ?>
                <a href="users.php">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <h2>Current Users (<?php echo count($users); ?>)</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Assigned Firm</th>
                <th>Balance</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?php echo $user['id']; ?></td>
                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                <td><?php echo htmlspecialchars($user['company_name'] ?? 'N/A'); ?></td>
                <td><?php echo number_format($user['balance'], 2); ?> TL</td>
                <td>
                    <a href="users.php?action=edit&id=<?php echo $user['id']; ?>">Edit</a> | 
                    <a href="users.php?action=delete&id=<?php echo $user['id']; ?>" 
                       onclick="return confirm('WARNING! Are you sure you want to delete user ID <?php echo $user['id']; ?> (<?php echo $user['email']; ?>)?');">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>