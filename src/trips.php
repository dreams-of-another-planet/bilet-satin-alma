<?php
// src/trips.php - Admin/Firma Admin Trip Management Panel
session_start();
require 'db.php'; 

// === 1. SECURITY CHECK: Must be Admin OR Firma Admin (company) ===
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || 
    !in_array($_SESSION['user_role'], ['admin', 'company'])) {
    http_response_code(403);
    die("Access Denied. Only Admins and Firma Admins can view this page.");
}

// Global variables
$status_message = '';
$error = false;
$edit_trip = null; 
$user_role = $_SESSION['user_role'];
$user_company_id = $_SESSION['company_id'] ?? null; // Null for system Admin

function generate_random_id() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return md5(uniqid(mt_rand(), true)); 
    }
}

// Fetch lists for foreign keys (Bus Companies)
try {
    // Admins see all companies; Firma Admins only see their own.
    $company_filter_sql = ($user_role === 'company' && $user_company_id) ? " WHERE id = ?" : "";
    $company_filter_params = ($user_role === 'company' && $user_company_id) ? [$user_company_id] : [];
    
    $stmt_companies = $pdo->prepare("SELECT id, name FROM Bus_Company" . $company_filter_sql . " ORDER BY name ASC");
    $stmt_companies->execute($company_filter_params);
    $companies = $stmt_companies->fetchAll(PDO::FETCH_ASSOC);

    // Stop if a Firma Admin doesn't have a company assigned
    if ($user_role === 'company' && empty($companies)) {
        die("Error: Your Firma Admin account is not correctly assigned to a company.");
    }
    
} catch (PDOException $e) {
    $status_message = "Error fetching company list: " . $e->getMessage();
    $error = true;
    $companies = [];
}

// === 2. HANDLE CUD OPERATIONS ===

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $id = trim($_POST['id'] ?? '');
    $company_id = trim($_POST['company_id'] ?? $user_company_id);
    $destination_city = trim(htmlspecialchars($_POST['destination_city']));
    // CRITICAL: Retrieve departure_city
    $departure_city = trim(htmlspecialchars($_POST['departure_city'])); 
    $arrival_time = trim($_POST['arrival_time'] ?? '');
    $departure_time = trim($_POST['departure_time'] ?? '');
    $price = (int)($_POST['price'] ?? 0);
    $capacity = (int)($_POST['capacity'] ?? 0);

    // Core validation (now includes departure_city)
    if (empty($company_id) || empty($destination_city) || empty($departure_city) || empty($departure_time) || empty($arrival_time) || $price <= 0 || $capacity <= 0) {
        $status_message = "Invalid or missing required trip details.";
        $error = true;
    } 
    // Additional security check: Firma Admin cannot manage other companies' trips
    elseif ($user_role === 'company' && $company_id !== $user_company_id) {
        $status_message = "Security Error: You can only manage trips for your assigned company.";
        $error = true;
    }
    
    else {
        try {
            if ($action === 'add') {
                // C: Create (Add New Trip)
                $new_id = generate_random_id();
                // CRITICAL: Add departure_city to column list
                $sql = "INSERT INTO Trips (id, company_id, destination_city, departure_city, arrival_time, departure_time, price, capacity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"; 
                $stmt = $pdo->prepare($sql);
                // CRITICAL: Add $departure_city to parameter list
                $stmt->execute([$new_id, $company_id, $destination_city, $departure_city, $arrival_time, $departure_time, $price, $capacity]);
                $status_message = "Trip created successfully! ID: " . $new_id;
                
            } elseif ($action === 'update' && !empty($id)) {
                // U: Update Existing Trip
                // CRITICAL: Add departure_city to column list
                $sql = "UPDATE Trips SET company_id=?, destination_city=?, departure_city=?, arrival_time=?, departure_time=?, price=?, capacity=? WHERE id=?"; 
                $stmt = $pdo->prepare($sql);
                // CRITICAL: Add $departure_city to parameter list
                $stmt->execute([$company_id, $destination_city, $departure_city, $arrival_time, $departure_time, $price, $capacity, $id]);
                $status_message = "Trip ID {$id} updated successfully!";
            }
        } catch (PDOException $e) {
            $status_message = "Database error: " . $e->getMessage();
            $error = true;
        }
    }

} elseif (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $trip_id = $_GET['id'];

    // Fetch the trip to check for ownership before processing delete/edit
    $stmt_check = $pdo->prepare("SELECT company_id FROM Trips WHERE id = ?");
    $stmt_check->execute([$trip_id]);
    $check_trip = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$check_trip) {
         $status_message = "Trip not found.";
         $error = true;
    }
    // Security check: If Firma Admin, ensure trip belongs to them.
    elseif ($user_role === 'company' && $check_trip['company_id'] !== $user_company_id) {
        $status_message = "Security Error: You cannot manage trips belonging to another company.";
        $error = true;
    }
    
    elseif ($action === 'delete') {
        // D: Delete Trip
        try {
            $stmt = $pdo->prepare("DELETE FROM Trips WHERE id = ?");
            $stmt->execute([$trip_id]);
            $status_message = "Trip ID {$trip_id} deleted successfully!";
            header("Location: trips.php");
            exit;
        } catch (PDOException $e) {
            $status_message = "Deletion failed (Database error): Check for linked Tickets.";
            $error = true;
        }
    } elseif ($action === 'edit') {
        // Prepare view for U: Update
        $stmt = $pdo->prepare("SELECT * FROM Trips WHERE id = ?");
        $stmt->execute([$trip_id]);
        $edit_trip = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// === 3. R (Read) - Fetch all accessible trips for display ===
try {
    $read_filter_sql = ($user_role === 'company' && $user_company_id) ? " WHERE T.company_id = ?" : "";
    $read_filter_params = ($user_role === 'company' && $user_company_id) ? [$user_company_id] : [];
    
    $sql_read = "
        SELECT T.id, T.destination_city, T.departure_city, T.departure_time, T.price, T.capacity, BC.name AS company_name
        FROM Trips T
        JOIN Bus_Company BC ON T.company_id = BC.id
        " . $read_filter_sql . "
        ORDER BY T.departure_time DESC
    ";
    $stmt_read = $pdo->prepare($sql_read);
    $stmt_read->execute($read_filter_params);
    $trips = $stmt_read->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_message = "Error fetching trips: " . $e->getMessage();
    $error = true;
    $trips = [];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo $user_role === 'admin' ? 'Admin Panel' : 'Firma Admin Panel'; ?> - Trips</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .form-container { margin-bottom: 30px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; }
        a { text-decoration: none; }
    </style>
</head>
<body>
    <h1><?php echo $user_role === 'admin' ? 'Admin' : 'Firma Admin'; ?> - Trip Management Panel</h1>
    <p>Welcome, <?php echo ucfirst($user_role); ?>! 
        (<a href="profile.php">My Profile</a> | 
        <a href="dashboard.php">Dashboard</a> | 
        <a href="logout.php">Logout</a>)
    </p>
    <?php if ($user_role === 'company'): ?>
        <p>Managing trips for: **<?php echo htmlspecialchars($companies[0]['name'] ?? 'N/A'); ?>**</p>
    <?php endif; ?>

    <?php if ($status_message): ?><p class="<?php echo $error ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($status_message); ?></p><?php endif; ?>

    <div class="form-container">
        <h2><?php echo $edit_trip ? 'Edit Trip (ID: ' . substr($edit_trip['id'], 0, 8) . '...)' : 'Add New Trip'; ?></h2>
        
        <form action="trips.php" method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_trip ? 'update' : 'add'; ?>">
            <?php if ($edit_trip): ?><input type="hidden" name="id" value="<?php echo $edit_trip['id']; ?>"><?php endif; ?>

            <label>Company:</label>
            <select name="company_id" required <?php echo $user_role === 'company' ? 'readonly' : ''; ?>>
                <?php foreach ($companies as $company): ?>
                    <option value="<?php echo $company['id']; ?>" 
                            <?php if (($edit_trip['company_id'] ?? $user_company_id) === $company['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($company['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>

            <label>Departure City:</label>
            <input type="text" name="departure_city" required 
                   value="<?php echo htmlspecialchars($edit_trip['departure_city'] ?? ''); ?>"><br><br>
                   
            <label>Destination City:</label>
            <input type="text" name="destination_city" required 
                   value="<?php echo htmlspecialchars($edit_trip['destination_city'] ?? ''); ?>"><br><br>

            <label>Departure Time (DATETIME):</label>
            <input type="datetime-local" name="departure_time" required 
                   value="<?php echo htmlspecialchars(str_replace(' ', 'T', $edit_trip['departure_time'] ?? '')); ?>"><br><br>
            
            <label>Arrival Time (DATETIME):</label>
            <input type="datetime-local" name="arrival_time" required 
                   value="<?php echo htmlspecialchars(str_replace(' ', 'T', $edit_trip['arrival_time'] ?? '')); ?>"><br><br>

            <label>Price:</label>
            <input type="number" name="price" required 
                   value="<?php echo htmlspecialchars($edit_trip['price'] ?? 0); ?>"><br><br>
            
            <label>Capacity (Total Seats):</label>
            <input type="number" name="capacity" required 
                   value="<?php echo htmlspecialchars($edit_trip['capacity'] ?? 40); ?>"><br><br>

            <input type="submit" value="<?php echo $edit_trip ? 'Update Trip' : 'Add Trip'; ?>">
            <?php if ($edit_trip): ?><a href="trips.php">Cancel Edit</a><?php endif; ?>
        </form>
    </div>

    <h2>Current Trips (<?php echo count($trips); ?>)</h2>
    <table>
        <thead><tr><th>ID</th><th>Company</th><th>Route</th><th>Departure</th><th>Price</th><th>Capacity</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($trips as $trip): ?>
            <tr>
                <td><?php echo substr($trip['id'], 0, 8); ?>...</td>
                <td><?php echo htmlspecialchars($trip['company_name']); ?></td>
                <td><?php echo htmlspecialchars($trip['departure_city']); ?> &rarr; <?php echo htmlspecialchars($trip['destination_city']); ?></td>
                <td><?php echo $trip['departure_time']; ?></td>
                <td>$<?php echo number_format($trip['price'], 0); ?></td>
                <td><?php echo $trip['capacity']; ?></td>
                <td>
                    <a href="trips.php?action=edit&id=<?php echo $trip['id']; ?>">Edit</a> | 
                    <a href="trips.php?action=delete&id=<?php echo $trip['id']; ?>" 
                       onclick="return confirm('WARNING! Delete trip <?php echo $trip['id']; ?>? This will fail if tickets are linked.');">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>