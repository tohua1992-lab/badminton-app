<?php
$servername = "mysql-3c964605-badmintonappver2.h.aivencloud.com"; 
$username = "avnadmin";      
$password = "AVNS_gUxQuakKKnm2wAMNbOW";   
$dbname = "defaultdb";
$port = 20789;

$conn = mysqli_init();
$conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
$conn->real_connect($servername, $username, $password, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);

if ($conn->connect_error) {
    die("❌ Kết nối thất bại: " . $conn->connect_error);
}

echo "<h2>⚙️ HỆ THỐNG ĐANG TỰ ĐỘNG SỬA LỖI DATABASE...</h2>";

// Danh sách các cột cần thêm để vá lỗi
$queries = [
    "ALTER TABLE matches MODIFY COLUMN id BIGINT",
    "ALTER TABLE matches ADD COLUMN match_time VARCHAR(20) DEFAULT NULL",
    "ALTER TABLE matches ADD COLUMN team1 TEXT DEFAULT NULL",
    "ALTER TABLE matches ADD COLUMN team2 TEXT DEFAULT NULL",
    "ALTER TABLE matches ADD COLUMN winner VARCHAR(20) DEFAULT NULL",
    "ALTER TABLE matches ADD COLUMN bet INT DEFAULT 1",
    "ALTER TABLE matches ADD COLUMN water INT DEFAULT 0",
    "ALTER TABLE matches ADD COLUMN score VARCHAR(50) DEFAULT NULL"
];

foreach ($queries as $q) {
    if ($conn->query($q)) {
        echo "<p style='color: green;'>✅ Thành công: Thêm thành phần mới vào Database.</p>";
    } else {
        // Cố tình bỏ qua nếu cột đã tồn tại (Duplicate column)
        if (strpos($conn->error, 'Duplicate column') !== false) {
             echo "<p style='color: blue;'>✅ Cột này đã có sẵn, bỏ qua.</p>";
        } else {
             echo "<p style='color: red;'>⚠️ Lỗi: " . $conn->error . "</p>";
        }
    }
}

echo "<h3>🎉 HOÀN TẤT! DATABASE CỦA BẠN ĐÃ CHUẨN 100%.</h3>";
echo "<p>Bạn có thể tắt trang này, quay lại ứng dụng và lưu trận đấu bình thường!</p>";
?>