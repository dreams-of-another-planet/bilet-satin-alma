<?php
// src/tickets.php - Admin Tickets Management Panel
session_start();
require 'db.php'; 

// === 1. SECURITY CHECK ===
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied. Admins only."); 
}

// Global variables
$status_message = '';
$error = false;
$edit_ticket = null; 
// UPDATED: Using 3 core statuses as requested.
$allowed_statuses = ['paid', 'canceled', 'expired']; 

function generate_random_id() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return md5(uniqid(mt_rand(), true)); 
    }
}

// Fetch lists for foreign keys (Trips and Users)
try {
    $trips = $pdo->query("SELECT T.id, T.destination_city, T.departure_time, BC.name AS company_name FROM Trips T JOIN Bus_Company BC ON T.company_id = BC.id")->fetchAll(PDO::FETCH_ASSOC);
    $users = $pdo->query("SELECT id, full_name, email FROM User")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_message = "Error fetching FK lists: " . $e->getMessage();
    $error = true;
    $trips = [];
    $users = [];
}


// === 2. HANDLE CUD OPERATIONS ===

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $id = trim($_POST['id'] ?? '');
    $trip_id = trim($_POST['trip_id'] ?? '');
    $user_id = trim($_POST['user_id'] ?? '');
    $status = $_POST['status'] ?? 'paid'; // Defaulting to paid for manual admin creation
    $total_price = (int)($_POST['total_price'] ?? 0);
    $total_quantity = (int)($_POST['total_quantity'] ?? 1);

    if (empty($trip_id) || empty($user_id) || $total_price <= 0 || $total_quantity <= 0 || !in_array($status, $allowed_statuses)) {
        $status_message = "Invalid or missing required fields.";
        $error = true;
    } else {
        try {
            if ($action === 'add') {
                // C: Create (Add New Ticket)
                $new_id = generate_random_id();
                $sql = "INSERT INTO Tickets (id, trip_id, user_id, status, total_price, total_quantity) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$new_id, $trip_id, $user_id, $status, $total_price, $total_quantity]);
                $status_message = "Ticket created successfully! ID: " . $new_id;
                
            } elseif ($action === 'update' && !empty($id)) {
                // U: Update Existing Ticket
                $sql = "UPDATE Tickets SET trip_id=?, user_id=?, status=?, total_price=?, total_quantity=? WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$trip_id, $user_id, $status, $total_price, $total_quantity, $id]);
                $status_message = "Ticket ID {$id} updated successfully!";
            }
        } catch (PDOException $e) {
            $status_message = "Database error: " . $e->getMessage();
            $error = true;
        }
    }

} elseif (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $ticket_id = $_GET['id'];

    if ($action === 'delete') {
        // D: Delete/Cancel Ticket (Applying task rules: 1-hour rule and refund)
        try {
            // 1. Fetch ticket and trip details for cancellation check and refund
            $stmt = $pdo->prepare("
                SELECT T.total_price, T.user_id, T.status, TR.departure_time 
                FROM Tickets T JOIN Trips TR ON T.trip_id = TR.id 
                WHERE T.id = ?
            ");
            $stmt->execute([$ticket_id]);
            $ticket_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket_data) {
                throw new Exception("Ticket not found.");
            }
            if ($ticket_data['status'] !== 'paid') {
                 throw new Exception("Only PAID tickets can be canceled. Current status: " . ucfirst($ticket_data['status']));
            }
            
            $departure_time = strtotime($ticket_data['departure_time']);
            $cancellation_deadline = $departure_time - (60 * 60); // 1 hour before departure
            
            // 2. Check Cancellation Deadline
            if (time() > $cancellation_deadline) {
                throw new Exception("Cancellation denied. Departure time is less than 1 hour away.");
            }

            // 3. Begin Transaction for Atomic Cancellation and Refund
            $pdo->beginTransaction();
            
            // Update Ticket Status to Canceled
            $stmt_cancel = $pdo->prepare("UPDATE Tickets SET status = 'canceled' WHERE id = ?");
            $stmt_cancel->execute([$ticket_id]);
            
            // Process Refund to User's Balance
            $refund_amount = $ticket_data['total_price'];
            $stmt_refund = $pdo->prepare("UPDATE User SET balance = balance + ? WHERE id = ?");
            $stmt_refund->execute([$refund_amount, $ticket_data['user_id']]);

            // Commit transaction
            $pdo->commit();

            $status_message = "Ticket ID {$ticket_id} CANCELED successfully, and {$refund_amount} credited to user's account!";
            header("Location: tickets.php");
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $status_message = "Cancellation failed: " . $e->getMessage();
            $error = true;
        }
    } elseif ($action === 'edit') {
        // Prepare view for U: Update
        $stmt = $pdo->prepare("SELECT * FROM Tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $edit_ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_ticket) {
            $status_message = "Ticket not found.";
            $error = true;
        }
    }
}

// === 3. R (Read) - Fetch all tickets for display (with joins for names) ===
try {
    $sql_read = "
        SELECT T.id, U.full_name AS user_name, TR.destination_city, T.status, T.total_price, T.total_quantity, T.created_at, T.trip_id
        FROM Tickets T
        JOIN User U ON T.user_id = U.id
        JOIN Trips TR ON T.trip_id = TR.id
        ORDER BY T.created_at DESC
    ";
    $tickets = $pdo->query($sql_read)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $status_message = "Error fetching tickets: " . $e->getMessage();
    $error = true;
    $tickets = [];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel - Tickets</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .form-container { margin-bottom: 30px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; }
        .canceled { background-color: #fdd; }
        a { text-decoration: none; }
    </style>
</head>
<body>
    <h1>Ticket Management Panel</h1>
    <p>Welcome, Admin! (<a href="profile.php">My Profile</a> | <a href="dashboard.php">Dashboard</a> | <a href="logout.php">Logout</a>)</p>

    <?php if ($status_message): ?><p class="<?php echo $error ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($status_message); ?></p><?php endif; ?>

    <div class="form-container">
        <h2><?php echo $edit_ticket ? 'Edit Ticket (ID: ' . $edit_ticket['id'] . ')' : 'Add New Ticket'; ?></h2>
        
        <form action="tickets.php" method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_ticket ? 'update' : 'add'; ?>">
            <?php if ($edit_ticket): ?><input type="hidden" name="id" value="<?php echo $edit_ticket['id']; ?>"><?php endif; ?>

            <label>Trip ID (Destination):</label>
            <select name="trip_id" required>
                <?php foreach ($trips as $trip): ?>
                    <option value="<?php echo $trip['id']; ?>" <?php if (($edit_ticket['trip_id'] ?? '') === $trip['id']) echo 'selected'; ?>>
                        <?php echo "ID: " . substr($trip['id'], 0, 8) . " - {$trip['destination_city']} ({$trip['company_name']})"; ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>

            <label>User ID (Purchaser):</label>
            <select name="user_id" required>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php if (($edit_ticket['user_id'] ?? '') === $user['id']) echo 'selected'; ?>>
                        <?php echo "ID: {$user['id']} - {$user['full_name']} ({$user['email']})"; ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>
            
            <label>Status:</label>
            <select name="status" required>
                <?php foreach ($allowed_statuses as $s): ?>
                    <option value="<?php echo $s; ?>" <?php if (($edit_ticket['status'] ?? 'paid') === $s) echo 'selected'; ?>><?php echo ucfirst($s); ?></option>
                <?php endforeach; ?>
            </select><br><br>

            <label>Total Price:</label>
            <input type="number" name="total_price" required value="<?php echo htmlspecialchars($edit_ticket['total_price'] ?? 0); ?>"><br><br>
            
            <label>Quantity (Seats):</label>
            <input type="number" name="total_quantity" required value="<?php echo htmlspecialchars($edit_ticket['total_quantity'] ?? 1); ?>"><br><br>

            <input type="submit" value="<?php echo $edit_ticket ? 'Update Ticket' : 'Add Ticket'; ?>">
            <?php if ($edit_ticket): ?><a href="tickets.php">Cancel Edit</a><?php endif; ?>
        </form>
    </div>

    <h2>Current Tickets (<?php echo count($tickets); ?>)</h2>
    <table>
        <thead><tr><th>ID</th><th>User</th><th>Trip (Dest/Comp)</th><th>Qty</th><th>Price</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($tickets as $ticket): ?>
            <tr class="<?php echo $ticket['status'] === 'canceled' ? 'canceled' : ''; ?>">
                <td><?php echo substr($ticket['id'], 0, 8); ?>...</td>
                <td><?php echo htmlspecialchars($ticket['user_name']); ?></td>
                <td><?php echo htmlspecialchars($ticket['destination_city']); ?> (ID: <?php echo substr($ticket['trip_id'], 0, 4); ?>...)</td>
                <td><?php echo $ticket['total_quantity']; ?></td>
                <td>$<?php echo number_format($ticket['total_price'], 2); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($ticket['status'])); ?></td>
                <td><?php echo $ticket['created_at']; ?></td>
                <td>
                    <?php if ($ticket['status'] === 'paid'): ?>
                        <a href="tickets.php?action=delete&id=<?php echo $ticket['id']; ?>" 
                           onclick="return confirm('Attempt CANCELLATION for ticket <?php echo $ticket['id']; ?>? (1-hour rule applies)');">Cancel/Refund</a>
                    <?php else: ?>
                        <?php echo ucfirst($ticket['status']); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>