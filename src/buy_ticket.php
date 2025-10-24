<?php
// src/buy_ticket.php - Bilet Satın Alma (Ticket Purchase)
session_start();
require 'db.php'; 

// === 1. SECURITY & ACCESS CHECK ===
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['user_role'] !== 'user') {
    $_SESSION['login_error'] = "Lütfen Giriş Yapın. Bilet satın alma işlemi için kullanıcı girişi gereklidir.";
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$trip_id = $_GET['trip_id'] ?? ($_POST['trip_id'] ?? null);
$status_message = '';
$error = false;
$trip = null;
$booked_seats = []; 
$final_price = 0;
$coupon_discount_applied = 0;
$coupon_code_used = '';

function generate_random_id() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return md5(uniqid(mt_rand(), true)); 
    }
}

// === 2. Fetch Trip and Seat Data ===
if ($trip_id) {
    try {
        // Fetch Trip Details
        $stmt_trip = $pdo->prepare("
            SELECT T.*, BC.name AS company_name 
            FROM Trips T 
            JOIN Bus_Company BC ON T.company_id = BC.id 
            WHERE T.id = ?
        ");
        $stmt_trip->execute([$trip_id]);
        $trip = $stmt_trip->fetch(PDO::FETCH_ASSOC);

        if (!$trip) {
            $status_message = "Sefer bulunamadı.";
            $error = true;
        } else {
            $final_price = $trip['price']; // Default price

            // FETCH BOOKED SEATS (Using the required JOIN)
            // We join Booked_Seats with Tickets to find the trip_id
            $stmt_seats = $pdo->prepare("
                SELECT bs.seat_number 
                FROM Booked_Seats bs
                JOIN Tickets t ON bs.ticket_id = t.id
                WHERE t.trip_id = ?
            ");
            $stmt_seats->execute([$trip_id]);
            $booked_seats = $stmt_seats->fetchAll(PDO::FETCH_COLUMN, 0); 
        }

    } catch (PDOException $e) {
        $status_message = "Veritabanı hatası: " . $e->getMessage();
        $error = true;
    }
}

// === 3. HANDLE POST REQUEST (Purchase and Coupon Application) ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['seats']) && !$error) {
    
    $selected_seats = $_POST['seats'];
    $coupon_code = trim(strtoupper($_POST['coupon_code'] ?? ''));
    $total_seats = count($selected_seats);
    $unit_price = $trip['price'];
    $subtotal = $unit_price * $total_seats;
    $final_price = $subtotal;
    
    try {
        // Fetch current user balance (sanal kredi)
        $stmt_user = $pdo->prepare("SELECT balance, id FROM User WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        $user_balance = $user_data['balance'];
        
        // --- A. Coupon Validation ---
        if (!empty($coupon_code)) {
            $stmt_coupon = $pdo->prepare("
                SELECT id, discount, usage_limit, expire_date, company_id FROM Coupons 
                WHERE code = ? AND (company_id IS NULL OR company_id = ?) AND expire_date >= DATETIME('now')
            ");
            $stmt_coupon->execute([$coupon_code, $trip['company_id']]);
            $coupon = $stmt_coupon->fetch(PDO::FETCH_ASSOC);

            if ($coupon) {
                // Check usage limit
                $stmt_used = $pdo->prepare("SELECT COUNT(*) FROM User_Coupons WHERE user_id = ? AND coupon_id = ?");
                $stmt_used->execute([$user_id, $coupon['id']]);
                $user_coupon_count = $stmt_used->fetchColumn();

                if ($user_coupon_count >= $coupon['usage_limit']) {
                    $status_message = "Kupon kodu kullanım limitini aşmıştır.";
                    $error = true;
                } else {
                    $coupon_discount_applied = round($subtotal * $coupon['discount']);
                    $final_price = $subtotal - $coupon_discount_applied;
                    $coupon_code_used = $coupon_code;
                    $status_message = "Kupon ({$coupon_code}) başarıyla uygulandı! İndirim: {$coupon_discount_applied} TL";
                }
            } else {
                $status_message = "Kupon kodu geçersiz, süresi dolmuş veya bu firmaya ait değil.";
                $error = true;
            }
        }
        
        if ($error) throw new Exception($status_message);

        // --- B. Final Balance Check ---
        if ($final_price > $user_balance) {
            throw new Exception("Yetersiz Sanal Kredi. Bakiyeniz: {$user_balance} TL, Gerekli: {$final_price} TL.");
        }

        // --- C. Atomic Transaction (Purchase) ---
        $pdo->beginTransaction();
        
        // 1. Create Ticket Record
        $ticket_id = generate_random_id(); 
        // Using 'ACTIVE' status from your initdb.sql schema
        $sql_ticket = "INSERT INTO Tickets (id, trip_id, user_id, status, total_price) VALUES (?, ?, ?, 'ACTIVE', ?)"; 
        $stmt_ticket = $pdo->prepare($sql_ticket);
        $stmt_ticket->execute([$ticket_id, $trip_id, $user_id, $final_price]);

        // 2. Book Seats (Removing trip_id, adding 'id' for Booked_Seats)
        $sql_seats = "INSERT INTO Booked_Seats (id, ticket_id, seat_number) VALUES (?, ?, ?)";
        $stmt_seats = $pdo->prepare($sql_seats);
        foreach ($selected_seats as $seat_number) {
            if (in_array($seat_number, $booked_seats)) {
                 $pdo->rollBack();
                 throw new Exception("Koltuk #{$seat_number} az önce satıldı. Lütfen tekrar deneyin.");
            }
            // Insert data using the new schema structure
            $booked_seat_id = generate_random_id();
            $stmt_seats->execute([$booked_seat_id, $ticket_id, $seat_number]);
        }

        // 3. Deduct Balance (Virtual Credit)
        $sql_deduct = "UPDATE User SET balance = balance - ? WHERE id = ?";
        $pdo->prepare($sql_deduct)->execute([$final_price, $user_id]);

        // 4. Record Coupon Usage (Adding 'id' for User_Coupons)
        if (!empty($coupon_code_used)) {
            $coupon_id = $coupon['id']; 
            $user_coupon_id = generate_random_id();
            $sql_user_coupon = "INSERT INTO User_Coupons (id, coupon_id, user_id) VALUES (?, ?, ?)";
            $pdo->prepare($sql_user_coupon)->execute([$user_coupon_id, $coupon_id, $user_id]);
        }
        
        $pdo->commit();
        header("Location: profile.php?msg=" . urlencode("Tebrikler! Bilet alımı başarılı. Toplam tutar: {$final_price} TL"));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status_message = "Bilet Alımı Başarısız: " . $e->getMessage();
        $error = true;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Bilet Satın Alma</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .trip-info { border: 1px solid #333; padding: 15px; margin-bottom: 20px; background-color: #e0f0ff; }
        .seat-map { display: flex; flex-wrap: wrap; width: 300px; margin-bottom: 20px; }
        .seat { width: 40px; height: 40px; margin: 5px; border: 1px solid #ccc; display: flex; justify-content: center; align-items: center; cursor: pointer; }
        .available { background-color: #cfc; }
        .unavailable { background-color: #fcc; cursor: not-allowed; }
        .selected { background-color: #ffc; border: 2px solid #333; }
        .coupon-box { border: 1px solid #aaa; padding: 10px; margin-top: 15px; }
        .price-box { border: 2px solid green; padding: 10px; margin-top: 15px; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        input[type="checkbox"] { display: none; }
        input[type="checkbox"]:checked + label.seat { background-color: #ffc; border: 2px solid #333; }
    </style>
</head>
<body>
    <h1>Bilet Satın Alma Sayfası</h1>
    <p><a href="index.php">Sefer Aramaya Dön</a> | <a href="profile.php">Hesabım</a></p>

    <?php if ($status_message): ?>
        <p class="<?php echo $error ? 'error' : 'price-box'; ?>">
            <?php echo htmlspecialchars($status_message); ?>
        </p>
    <?php endif; ?>

    <?php if (!$trip): ?>
        <p class="error">Geçersiz Sefer Seçimi.</p>
    <?php else: ?>
        <div class="trip-info">
            <h2>Sefer Detayları</h2>
            <p><strong>Firma:</strong> <?php echo htmlspecialchars($trip['company_name']); ?></p>
            <p><strong>Rota:</strong> <?php echo htmlspecialchars($trip['departure_city']); ?> &rarr; <?php echo htmlspecialchars($trip['destination_city']); ?></p>
            <p><strong>Kalkış:</strong> <?php echo date('Y-m-d H:i', strtotime($trip['departure_time'])); ?></p>
            <p><strong>Bilet Fiyatı:</strong> <?php echo number_format($trip['price'], 2); ?> TL</p>
        </div>

        <form action="buy_ticket.php?trip_id=<?php echo $trip_id; ?>" method="POST">
            <input type="hidden" name="trip_id" value="<?php echo $trip_id; ?>">
            
            <h2>Koltuk Seçimi (Kapasite: <?php echo $trip['capacity']; ?>)</h2>
            <p>Dolu koltuklar *disabled* olarak gösterilmiştir.</p>
            <div class="seat-map">
                <?php 
                for ($i = 1; $i <= $trip['capacity']; $i++): 
                    $is_booked = in_array((string)$i, $booked_seats);
                    $seat_class = $is_booked ? 'unavailable' : 'available';
                ?>
                    <input type="checkbox" id="seat_<?php echo $i; ?>" name="seats[]" value="<?php echo $i; ?>" <?php echo $is_booked ? 'disabled' : ''; ?>>
                    <label for="seat_<?php echo $i; ?>" class="seat <?php echo $seat_class; ?>">
                        <?php echo $i; ?>
                    </label>
                <?php endfor; ?>
            </div>
            
            <div class="coupon-box">
                <label for="coupon_code">Kupon Kodu Uygula (Opsiyonel):</label>
                <input type="text" id="coupon_code" name="coupon_code" value="<?php echo htmlspecialchars($coupon_code_used); ?>">
                <button type="submit" name="action_type" value="apply_coupon">Kuponu Uygula/Kontrol Et</button>
            </div>
            
            <div class="price-box">
                <span>Seçilen Koltuk Sayısı: <span id="seat_count">0</span></span><br>
                <span>Ara Toplam: <span id="subtotal_price">0.00</span> TL</span><br>
                <span>İndirim: <span id="discount_amount"><?php echo number_format($coupon_discount_applied, 2); ?></span> TL</span><br>
                <strong>Ödenecek Toplam Tutar: <span id="final_price"><?php echo number_format($final_price, 2); ?></span> TL</strong>
            </div>

            <input type="submit" name="action_type" value="Satın Alımı Tamamla (Kredi ile)">
        </form>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const unitPrice = <?php echo $trip['price'] ?? 0; ?>;
            const seatInputs = document.querySelectorAll('input[name="seats[]"]');
            const seatCountSpan = document.getElementById('seat_count');
            const subtotalSpan = document.getElementById('subtotal_price');
            const finalPriceSpan = document.getElementById('final_price');
            const discountSpan = document.getElementById('discount_amount');
            const currentDiscount = <?php echo $coupon_discount_applied; ?>;

            function updatePrice() {
                let count = 0;
                seatInputs.forEach(input => {
                    if (input.checked) {
                        count++;
                    }
                });

                const subtotal = count * unitPrice;
                const finalPrice = subtotal - currentDiscount;

                seatCountSpan.textContent = count;
                subtotalSpan.textContent = subtotal.toFixed(2);
                
                if (currentDiscount > 0) {
                     finalPriceSpan.textContent = finalPrice.toFixed(2);
                } else {
                     finalPriceSpan.textContent = subtotal.toFixed(2);
                }
            }

            seatInputs.forEach(input => {
                input.addEventListener('change', updatePrice);
            });

            // Initial update
            updatePrice();
        });
    </script>
</body>
</html>