<?php
// src/companies.php - Admin Bus Company Management Panel
session_start();
require 'db.php'; 

// === 1. SECURITY CHECK: ADMIN ACCESS REQUIRED ===
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied. Only administrators can manage bus companies.");
}

// Global variables
$status_message = '';
$error = false;
$edit_company = null; 
$upload_dir_relative = '/logos/'; // Relative path for database storage
$upload_dir_absolute = __DIR__ . '/logos/'; // Absolute path for file storage

// Helper function using the requested cryptographically secure random bytes
function generate_random_id() {
    // Generates a 32-character hexadecimal string (16 bytes * 2 hex chars/byte)
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        // Fallback for extreme cases where random_bytes fails
        return md5(uniqid(mt_rand(), true)); 
    }
}

// === 2. HANDLE CUD OPERATIONS ===

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    
    // Common input validation and sanitization
    $id = trim($_POST['id'] ?? '');
    $name = trim(htmlspecialchars($_POST['name']));
    $current_logo_path = trim($_POST['current_logo_path'] ?? ''); // Used to preserve current path on update
    $logo_path_to_db = $current_logo_path;

    if (empty($name)) {
        $status_message = "Company name is required.";
        $error = true;
    } else {
        try {
            // --- A. Handle File Upload (If a new file is provided) ---
            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_name = $_FILES['logo_file']['tmp_name'];
                $file_extension = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                
                // CRITICAL: Generate a secure, unique filename for the logo
                $logo_filename = generate_random_id() . '.' . $file_extension;
                $destination = $upload_dir_absolute . $logo_filename;
                
                // Ensure upload directory exists
                if (!is_dir($upload_dir_absolute)) {
                    mkdir($upload_dir_absolute, 0777, true);
                }

                if (move_uploaded_file($file_tmp_name, $destination)) {
                    // Set the database path (relative URL path)
                    $logo_path_to_db = $upload_dir_relative . $logo_filename; 

                    // OPTIONAL: Delete the old logo file if updating
                    if ($action === 'update' && $current_logo_path && file_exists(__DIR__ . $current_logo_path)) {
                        unlink(__DIR__ . $current_logo_path);
                    }
                } else {
                    $status_message = "File upload failed to move file to: " . $destination;
                    $error = true;
                }
            }
            
            // --- B. Database Operations (C & U) ---
            if (!$error) {
                if ($action === 'add') {
                    // C: Create (Add New Bus Company)
                    $new_id = generate_random_id();
                    $sql = "INSERT INTO Bus_Company (id, name, logo_path) VALUES (?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$new_id, $name, $logo_path_to_db]);
                    $status_message = "Bus Company '{$name}' added successfully!";
                    
                } elseif ($action === 'update' && !empty($id)) {
                    // U: Update Existing Bus Company
                    $sql = "UPDATE Bus_Company SET name=?, logo_path=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$name, $logo_path_to_db, $id]);
                    $status_message = "Bus Company ID {$id} updated successfully!";
                }
            }

        } catch (PDOException $e) {
            $status_message = "Database error: " . ($e->getCode() == '23000' ? "Company name already exists." : $e->getMessage());
            $error = true;
        } catch (Exception $e) {
             $status_message = "General Error: " . $e->getMessage();
             $error = true;
        }
    }

} elseif (isset($_GET['action']) && isset($_GET['id'])) {
    // Handle Delete and Edit View preparation (GET)
    $action = $_GET['action'];
    $company_id = $_GET['id'];

    if ($action === 'delete') {
        // D: Delete Bus Company
        try {
            // First, fetch the logo path to delete the file
            $stmt = $pdo->prepare("SELECT logo_path FROM Bus_Company WHERE id = ?");
            $stmt->execute([$company_id]);
            $company_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("DELETE FROM Bus_Company WHERE id = ?");
            $stmt->execute([$company_id]);
            
            // Delete the associated file
            if ($company_to_delete && $company_to_delete['logo_path'] && file_exists(__DIR__ . $company_to_delete['logo_path'])) {
                unlink(__DIR__ . $company_to_delete['logo_path']);
            }

            $status_message = "Bus Company ID {$company_id} deleted successfully!";
            header("Location: companies.php");
            exit;
        } catch (PDOException $e) {
            $status_message = "Deletion failed (Database error): Check for linked Users/Coupons/Trips.";
            $error = true;
        }
    } elseif ($action === 'edit') {
        // Prepare view for U: Update
        $stmt = $pdo->prepare("SELECT id, name, logo_path FROM Bus_Company WHERE id = ?");
        $stmt->execute([$company_id]);
        $edit_company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_company) {
            $status_message = "Bus Company not found.";
            $error = true;
        }
    }
}

// === 3. R (Read) - Fetch all companies for display ===
try {
    $stmt = $pdo->query("SELECT id, name, logo_path, created_at FROM Bus_Company ORDER BY name ASC");
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_message = "Error fetching companies: " . $e->getMessage();
    $error = true;
    $companies = [];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel - Bus Companies</title>
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
        .logo-preview { max-width: 50px; height: auto; }
    </style>
</head>
<body>
    <h1>Bus Company Management Panel</h1>
    <p>Welcome, Admin! (<a href="users.php">Manage Users</a> | <a href="logout.php">Logout</a>)</p>

    <?php if ($status_message): ?>
        <p class="<?php echo $error ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($status_message); ?>
        </p>
    <?php endif; ?>

    <div class="form-container">
        <h2><?php echo $edit_company ? 'Edit Company: ' . htmlspecialchars($edit_company['name']) : 'Add New Company'; ?></h2>
        
        <form action="companies.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $edit_company ? 'update' : 'add'; ?>">
            <?php if ($edit_company): ?>
                <input type="hidden" name="id" value="<?php echo $edit_company['id']; ?>">
                <input type="hidden" name="current_logo_path" value="<?php echo htmlspecialchars($edit_company['logo_path']); ?>">
            <?php endif; ?>

            <label>Company Name:</label>
            <input type="text" name="name" required 
                   value="<?php echo htmlspecialchars($edit_company['name'] ?? ''); ?>"><br><br>

            <label>Logo File:</label>
            <input type="file" name="logo_file" accept="image/png, image/jpeg"><br>
            <?php if ($edit_company && $edit_company['logo_path']): ?>
                <p>Current Logo: <img src="<?php echo htmlspecialchars($edit_company['logo_path']); ?>" class="logo-preview"></p>
                <p>Path: <code><?php echo htmlspecialchars($edit_company['logo_path']); ?></code></p>
            <?php endif; ?>
            <br>

            <input type="submit" value="<?php echo $edit_company ? 'Update Company' : 'Add Company'; ?>">
            <?php if ($edit_company): ?>
                <a href="companies.php">Cancel Edit</a>
            <?php endif; ?>
        </form>
    </div>

    <h2>Current Bus Companies (<?php echo count($companies); ?>)</h2>
    <table>
        <thead>
            <tr>
                <th>Logo</th>
                <th>ID</th>
                <th>Name</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($companies as $company): ?>
            <tr>
                <td>
                    <?php if ($company['logo_path']): ?>
                        <img src="<?php echo htmlspecialchars($company['logo_path']); ?>" class="logo-preview">
                    <?php else: ?>
                        No Logo
                    <?php endif; ?>
                </td>
                <td><?php echo $company['id']; ?></td>
                <td><?php echo htmlspecialchars($company['name']); ?></td>
                <td><?php echo $company['created_at']; ?></td>
                <td>
                    <a href="companies.php?action=edit&id=<?php echo $company['id']; ?>">Edit</a> | 
                    <a href="companies.php?action=delete&id=<?php echo $company['id']; ?>" 
                       onclick="return confirm('WARNING! Deleting this company will likely fail due to linked Users/Trips/Coupons. Continue?');">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>