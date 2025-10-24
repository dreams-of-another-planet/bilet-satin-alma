<?php
// src/index.php - Sefer Arama ve Listeleme (Trip Search and Listing)
session_start();
require 'db.php'; 

$is_logged_in = $_SESSION['loggedin'] ?? false;
$user_role = $_SESSION['user_role'] ?? 'guest';

$search_results = [];
$status_message = '';

$departure_city = $_GET['departure_city'] ?? '';
$destination_city = $_GET['destination_city'] ?? '';
$trip_date = $_GET['trip_date'] ?? date('Y-m-d');

// === 1. Fetch Search Results ===
if (!empty($departure_city) && !empty($destination_city)) {
    try {
        $sql = "
            SELECT 
                T.id, T.departure_city, T.destination_city, T.departure_time, T.arrival_time, 
                T.price, T.capacity, BC.name AS company_name
            FROM Trips T
            JOIN Bus_Company BC ON T.company_id = BC.id
            WHERE 
                T.departure_city = :dep_city AND 
                T.destination_city = :dest_city AND
                DATE(T.departure_time) = :trip_date
            ORDER BY T.departure_time ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':dep_city' => $departure_city,
            ':dest_city' => $destination_city,
            ':trip_date' => $trip_date
        ]);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($search_results)) {
            $status_message = "No trips found for the selected route on {$trip_date}.";
        }
    } catch (PDOException $e) {
        $status_message = "Database error during search: " . $e->getMessage();
    }
} else {
    $status_message = "Please select departure and destination cities to search.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bilet Satın Alma Platformu</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .search-form-container { margin-bottom: 30px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>Sefer Arama ve Bilet Satış Platformu</h1>
    
    <p>
        <?php if ($is_logged_in): ?>
            Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 
            (<a href="profile.php">My Account / Tickets</a> | 
            <a href="logout.php">Logout</a>)
        <?php else: ?>
            <a href="login.php">Giriş Yap</a> | 
            <a href="signup.php">Kayıt Ol</a>
        <?php endif; ?>
    </p>

    <div class="search-form-container">
        <h2>Sefer Arama Formu</h2>
        <form action="index.php" method="GET">
            <label for="departure_city">Kalkış Noktası:</label>
            <input type="text" name="departure_city" required value="<?php echo htmlspecialchars($departure_city); ?>"><br><br>
            
            <label for="destination_city">Varış Noktası:</label>
            <input type="text" name="destination_city" required value="<?php echo htmlspecialchars($destination_city); ?>"><br><br>

            <label for="trip_date">Tarih:</label>
            <input type="date" name="trip_date" required value="<?php echo htmlspecialchars($trip_date); ?>"><br><br>

            <input type="submit" value="Seferleri Ara">
        </form>
    </div>

    <h2>Arama Sonuçları</h2>
    <?php if ($status_message && empty($search_results)): ?>
        <p class="error"><?php echo htmlspecialchars($status_message); ?></p>
    <?php endif; ?>

    <?php if (!empty($search_results)): ?>
        <table>
            <thead>
                <tr>
                    <th>Firma</th>
                    <th>Kalkış</th>
                    <th>Varış</th>
                    <th>Kalkış Saati</th>
                    <th>Varış Saati</th>
                    <th>Fiyat</th>
                    <th>Kalan Koltuk</th>
                    <th>İşlem</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($search_results as $trip): ?>
                <tr>
                    <td><?php echo htmlspecialchars($trip['company_name']); ?></td>
                    <td><?php echo htmlspecialchars($trip['departure_city']); ?></td>
                    <td><?php echo htmlspecialchars($trip['destination_city']); ?></td>
                    <td><?php echo date('H:i', strtotime($trip['departure_time'])); ?></td>
                    <td><?php echo date('H:i', strtotime($trip['arrival_time'])); ?></td>
                    <td><?php echo number_format($trip['price'], 2); ?> TL</td>
                    <td><?php echo $trip['capacity']; ?></td>
                    <td>
                        <?php if ($is_logged_in): ?>
                            <a href="buy_ticket.php?trip_id=<?php echo $trip['id']; ?>">Bilet Satın Al</a>
                        <?php else: ?>
                            <a href="login.php" onclick="alert('Lütfen Giriş Yapın'); return true;">Bilet Satın Al (Giriş Yapın)</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>