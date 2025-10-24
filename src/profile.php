<?php
// src/profile.php - Hesabım / Biletler / Dashboard (Merged)
session_start();
require 'db.php'; 

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$profile_data = [];
$tickets = [];
$status_message = '';
$user_company_id = $_SESSION['company_id'] ?? null;

// Handle cancellation request first
if (isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['ticket_id'])) {
    $ticket_id = $_GET['ticket_id'];
    
    // Cancellation logic (only available to User role for their tickets)
    try {
        if ($user_role !== 'user') {
            throw new Exception("Cancellation is only available to the User (Yolcu) role.");
        }
        
        // 1. Fetch ticket details (price, status, departure time)
        $stmt_check = $pdo->prepare("
            SELECT T.total_price, T.user_id, T.status, TR.departure_time 
            FROM Tickets T JOIN Trips TR ON T.trip_id = TR.id 
            WHERE T.id = ? AND T.user_id = ?
        ");
        $stmt_check->execute([$ticket_id, $user_id]);
        $ticket_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$ticket_data) { throw new Exception("Ticket not found or does not belong to you."); }
        if ($ticket_data['status'] !== 'paid') { throw new Exception("Only PAID tickets can be canceled."); }
        
        $departure_time = strtotime($ticket_data['departure_time']);
        $cancellation_deadline = $departure_time - (60 * 60); // 1 hour rule
        
        if (time() > $cancellation_deadline) {
            throw new Exception("Bilet iptaline izin verilmez. Kalkış saatine son 1 saatten daha az süre kaldı.");
        }

        // 2. Transaction for Cancellation and Refund
        $pdo->beginTransaction();
        
        // Update ticket status
        $pdo->prepare("UPDATE Tickets SET status = 'canceled' WHERE id = ?")->execute([$ticket_id]);
        
        // Process refund
        $refund_amount = $ticket_data['total_price'];
        $pdo->prepare("UPDATE User SET balance = balance + ? WHERE id = ?")->execute([$refund_amount, $user_id]);
        $pdo->commit();
        
        $status_message = "Bilet başarıyla iptal edildi ve {$refund_amount} TL hesabınıza iade edildi.";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $status_message = "İptal başarısız: " . $e->getMessage();
    }
    // Redirect to clear GET variables
    header("Location: profile.php?msg=" . urlencode($status_message));
    exit;
}

// === Fetch User Profile and Balance ===
try {
    $stmt = $pdo->prepare("SELECT full_name, email, balance, company_id, created_at FROM User WHERE id = ?");
    $stmt->execute([$user_id]);
    $profile_data = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch User Tickets (for 'user' role)
    if ($user_role === 'user') {
        $sql_tickets = "
            SELECT 
                T.id, TR.departure_city, TR.destination_city, TR.departure_time, T.status, 
                T.total_price
                -- T.total_quantity removed as per schema correction
            FROM Tickets T
            JOIN Trips TR ON T.trip_id = TR.id
            WHERE T.user_id = ?
            ORDER BY TR.departure_time DESC
        ";
        $stmt_tickets = $pdo->prepare($sql_tickets);
        $stmt_tickets->execute([$user_id]);
        $tickets = $stmt_tickets->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    // Note: If other columns are missing, this will catch the error.
    $status_message = "Error fetching user data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Hesabım / Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .info-box { border: 1px solid #0056b3; padding: 15px; background-color: #e6f2ff; margin-bottom: 20px; }
        .admin-links, .firm-links { border: 1px solid #ccc; padding: 15px; background-color: #f0fff0; margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .canceled { background-color: #fdd; }
    </style>
</head>
<body>
    <h1>Hesabım ve Yönetim Paneli Merkezi</h1>
    <p>Oturum Rolünüz: **<?php echo ucfirst($user_role); ?>** | <a href="index.php">Sefer Arama</a> | <a href="logout.php">Çıkış Yap</a></p>

    <?php if ($status_message): ?><p style="color: red; font-weight: bold;"><?php echo htmlspecialchars($status_message); ?></p><?php endif; ?>
    <?php if (isset($_GET['msg'])): ?><p style="color: green; font-weight: bold;"><?php echo htmlspecialchars($_GET['msg']); ?></p><?php endif; ?>
    <?php if (isset($_GET['registered'])): ?><p style="color: green; font-weight: bold;">Kayıt başarılı! Lütfen giriş yapın.</p><?php endif; ?>

    <div class="info-box">
        <h2>Profil Bilgileri</h2>
        <p><strong>Ad Soyad:</strong> <?php echo htmlspecialchars($profile_data['full_name'] ?? ''); ?></p>
        <p><strong>E-posta:</strong> <?php echo htmlspecialchars($profile_data['email'] ?? ''); ?></p>
        <p><strong>Bakiye (Sanal Kredi):</strong> **<?php echo number_format($profile_data['balance'] ?? 0, 2); ?> TL**</p>
        <?php if ($user_role === 'company' && $profile_data['company_id']): ?>
            <p><strong>Atanmış Firma ID:</strong> <?php echo htmlspecialchars($profile_data['company_id']); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($user_role === 'admin' || $user_role === 'company'): ?>
        <div class="<?php echo $user_role === 'admin' ? 'admin-links' : 'firm-links'; ?>">
            <h2><?php echo $user_role === 'admin' ? 'Sistem Yöneticisi Paneli' : 'Firma Yönetici Paneli'; ?></h2>
            
            <?php if ($user_role === 'admin'): ?>
                <ul>
                    <li><a href="users.php">Kullanıcı & Firma Admin Yönetimi (Oluşturma/Atama)</a></li>
                    <li><a href="companies.php">Otobüs Firma Yönetimi (CRUD)</a></li>
                    <li><a href="trips.php">Tüm Sefer Yönetimi (CRUD)</a></li>
                    <li><a href="coupons.php">Tüm Kupon Yönetimi (Sistem/Firma)</a></li>
                    <li><a href="tickets.php">Bilet İptal/İade Yönetimi</a></li>
                </ul>
            <?php elseif ($user_role === 'company'): ?>
                <ul>
                    <li><a href="trips.php">Kendi Sefer Yönetimi (CRUD)</a></li>
                    <li><a href="coupons.php">Firma Kupon Yönetimi (Oluşturma/Düzenleme)</a></li>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($user_role === 'user'): ?>
        <h2>Satın Alınan Biletler (Yolcu)</h2>
        <?php if (empty($tickets)): ?>
            <p>Henüz satın alınmış biletiniz bulunmamaktadır.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Gidiş</th>
                        <th>Dönüş</th>
                        <th>Kalkış Saati</th>
                        <th>Fiyat</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                    <tr class="<?php echo $ticket['status'] === 'canceled' ? 'canceled' : ''; ?>">
                        <td><?php echo htmlspecialchars($ticket['departure_city']); ?></td>
                        <td><?php echo htmlspecialchars($ticket['destination_city']); ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($ticket['departure_time'])); ?></td>
                        <td><?php echo number_format($ticket['total_price'], 2); ?> TL</td>
                        <td><?php echo htmlspecialchars(ucfirst($ticket['status'])); ?></td>
                        <td>
                            <?php if ($ticket['status'] === 'paid'): ?>
                                <a href="profile.php?action=cancel&ticket_id=<?php echo $ticket['id']; ?>" 
                                   onclick="return confirm('Bileti iptal etmek istediğinizden emin misiniz? (Son 1 saat kuralı geçerlidir)');">Bilet İptal Et</a> |
                                <a href="generate_pdf.php?ticket_id=<?php echo $ticket['id']; ?>">PDF İndir</a>
                            <?php else: ?>
                                <?php echo ucfirst($ticket['status']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>