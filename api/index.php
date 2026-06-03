<?php
error_reporting(0); // Tắt cảnh báo để không hỏng luồng JSON
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');

// THÔNG TIN KẾT NỐI DATABASE MỚI
$servername = "sql100.infinityfree.com"; 
$username = "if0_41380849";      
$password = "BaoKhang71";   
$dbname = "if0_41380849_badbattle";   

// HÀM TRẢ VỀ JSON CHUẨN
function sendResponse($data) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$conn = new mysqli($servername, $username, $password, $dbname);

if (!$conn->connect_error) {
    $conn->set_charset("utf8mb4");
    mysqli_report(MYSQLI_REPORT_OFF); // Fix lỗi 500

    // 1. TẠO BẢNG TÀI KHOẢN NHÓM
    $conn->query("CREATE TABLE IF NOT EXISTS groups_account (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        raw_password VARCHAR(255) DEFAULT NULL,
        group_name VARCHAR(100) NOT NULL,
        expire_date DATE DEFAULT '2030-12-31',
        banner_data MEDIUMTEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Lệnh nâng cấp bảng ngầm
    $conn->query("ALTER TABLE groups_account ADD COLUMN raw_password VARCHAR(255) DEFAULT NULL AFTER password");
    $conn->query("ALTER TABLE groups_account ADD COLUMN expire_date DATE DEFAULT '2030-12-31' AFTER group_name");
    $conn->query("ALTER TABLE groups_account ADD COLUMN banner_data MEDIUMTEXT DEFAULT NULL AFTER expire_date");

    // 2. TẠO BẢNG TRẬN ĐẤU
    $conn->query("CREATE TABLE IF NOT EXISTS matches (
        id BIGINT PRIMARY KEY,
        group_id INT NOT NULL,
        match_date DATE,
        match_time VARCHAR(20),
        team1 JSON,
        team2 JSON,
        bet INT,
        water INT DEFAULT 0,
        score VARCHAR(20) DEFAULT NULL,
        winner VARCHAR(10)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Lệnh này sẽ tự động thêm cột Độ Nước vào DB của bạn mà không làm mất dữ liệu cũ
    $conn->query("ALTER TABLE matches ADD COLUMN water INT DEFAULT 0 AFTER bet");
    // THÊM DÒNG NÀY ĐỂ TẠO CỘT TỈ SỐ
    $conn->query("ALTER TABLE matches ADD COLUMN score VARCHAR(20) DEFAULT NULL AFTER water");

    // 3. TẠO BẢNG AVATAR
    $conn->query("CREATE TABLE IF NOT EXISTS avatars (
        group_id INT NOT NULL,
        player_name VARCHAR(255) NOT NULL,
        image_data MEDIUMTEXT,
        PRIMARY KEY (group_id, player_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ==========================================
    // XỬ LÝ CÁC YÊU CẦU TỪ TRÌNH DUYỆT (API)
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        // --- TỰ ĐĂNG KÝ TRẢI NGHIỆM 1 THÁNG ---
        if ($action === 'register_group') {
            $u = $conn->real_escape_string($input['new_user']);
            $raw_p = $input['new_pass'];
            $p = password_hash($raw_p, PASSWORD_DEFAULT);
            $n = $conn->real_escape_string($input['new_name']);
            // HSD chính xác 1 tháng kể từ hôm nay
            $exp = date('Y-m-d', strtotime('+1 month'));
            
            $stmt = $conn->prepare("INSERT INTO groups_account (username, password, raw_password, group_name, expire_date) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssss", $u, $p, $raw_p, $n, $exp);
                if($stmt->execute()) { 
                    sendResponse(['status' => 'success', 'message' => "🎉 Đăng ký thành công!\nChào mừng CLB '{$n}' đến với hệ thống.\nBạn có 1 tháng trải nghiệm miễn phí (HSD: ".date('d/m/Y', strtotime($exp))."). Vui lòng Đăng nhập."]); 
                } 
                else { 
                    sendResponse(['status' => 'error', 'message' => '❌ Tên tài khoản (ID) này đã có người sử dụng! Vui lòng chọn tên khác.']); 
                }
            } else {
                sendResponse(['status' => 'error', 'message' => 'Lỗi hệ thống! Vui lòng thử lại.']);
            }
        }
        
        // --- ĐĂNG NHẬP ---
        if ($action === 'login') {
            $user = $conn->real_escape_string($input['username']);
            $pass = $input['password'];

            if ($user === 'superadmin' && $pass === 'Baokhang@2026') {
                $_SESSION['role'] = 'superadmin';
                sendResponse(['status' => 'success', 'role' => 'superadmin']);
            }

            $res = $conn->query("SELECT * FROM groups_account WHERE username = '$user'");
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                if (password_verify($pass, $row['password'])) {
                    $today = date('Y-m-d');
                    if (!empty($row['expire_date']) && $row['expire_date'] < $today) {
                        sendResponse(['status' => 'error', 'message' => '❌ Tài khoản nhóm này đã hết hạn License. Vui lòng liên hệ Admin gia hạn!']);
                    }
                    $_SESSION['group_id'] = $row['id'];
                    $_SESSION['group_name'] = $row['group_name'];
                    $_SESSION['role'] = 'admin';
                    $_SESSION['expire_date'] = date('d/m/Y', strtotime($row['expire_date']));
                    
                    sendResponse(['status' => 'success', 'role' => 'admin', 'group_name' => $row['group_name'], 'expire_date' => $_SESSION['expire_date']]);
                }
            }
            sendResponse(['status' => 'error', 'message' => 'Sai tài khoản hoặc mật khẩu!']);
        }

        // --- ĐĂNG NHẬP KHÁCH XEM ---
        if ($action === 'guest_login') {
            $user = $conn->real_escape_string($input['username']);
            $res = $conn->query("SELECT * FROM groups_account WHERE username = '$user'");
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $today = date('Y-m-d');
                if (!empty($row['expire_date']) && $row['expire_date'] < $today) {
                    sendResponse(['status' => 'error', 'message' => '❌ Nhóm này đã hết hạn dịch vụ. Không thể xem!']);
                }
                $_SESSION['group_id'] = $row['id'];
                $_SESSION['group_name'] = $row['group_name'];
                $_SESSION['role'] = 'guest';
                $_SESSION['expire_date'] = date('d/m/Y', strtotime($row['expire_date']));
                
                sendResponse(['status' => 'success', 'role' => 'guest', 'group_name' => $row['group_name'], 'expire_date' => $_SESSION['expire_date']]);
            }
            sendResponse(['status' => 'error', 'message' => 'Không tìm thấy ID Nhóm này!']);
        }

        if ($action === 'check_auth') {
            if (isset($_SESSION['role'])) {
                sendResponse([
                    'status' => 'success', 
                    'role' => $_SESSION['role'], 
                    'group_name' => $_SESSION['group_name'] ?? '',
                    'expire_date' => $_SESSION['expire_date'] ?? ''
                ]);
            }
            sendResponse(['status' => 'error']);
        }

        if ($action === 'logout') {
            session_destroy();
            sendResponse(['status' => 'success']);
        }

        // --- SUPER ADMIN ACTIONS ---
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin') {
            if ($action === 'create_group') {
                $u = $conn->real_escape_string($input['new_user']);
                $raw_p = $input['new_pass'];
                $p = password_hash($raw_p, PASSWORD_DEFAULT);
                $n = $conn->real_escape_string($input['new_name']);
                $exp = $conn->real_escape_string($input['expire_date']);
                
                $stmt = $conn->prepare("INSERT INTO groups_account (username, password, raw_password, group_name, expire_date) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("sssss", $u, $p, $raw_p, $n, $exp);
                    if($stmt->execute()) { sendResponse(['status' => 'success']); } 
                    else { sendResponse(['status' => 'error', 'message' => 'Tên tài khoản này đã tồn tại! Vui lòng chọn tên khác.']); }
                } else {
                    sendResponse(['status' => 'error', 'message' => 'Lỗi tạo dữ liệu! Vui lòng thử lại.']);
                }
            }

            if ($action === 'fetch_accounts') {
                $res = $conn->query("SELECT id, username, raw_password, group_name, expire_date FROM groups_account ORDER BY id DESC");
                $accounts = [];
                if ($res) { while($r = $res->fetch_assoc()) $accounts[] = $r; }
                sendResponse(['status' => 'success', 'data' => $accounts]);
            }

            if ($action === 'edit_account') {
                $id = (int)$input['id'];
                $u = $conn->real_escape_string($input['username']);
                $raw_p = $input['password'];
                $p = password_hash($raw_p, PASSWORD_DEFAULT);
                $n = $conn->real_escape_string($input['group_name']);
                $exp = $conn->real_escape_string($input['expire_date']);

                $stmt = $conn->prepare("UPDATE groups_account SET username=?, password=?, raw_password=?, group_name=?, expire_date=? WHERE id=?");
                if ($stmt) {
                    $stmt->bind_param("sssssi", $u, $p, $raw_p, $n, $exp, $id);
                    $stmt->execute();
                    sendResponse(['status' => 'success']);
                }
                sendResponse(['status' => 'error']);
            }

            if ($action === 'delete_account') {
                $id = (int)$input['id'];
                
                $resAva = $conn->query("SELECT image_data FROM avatars WHERE group_id=$id");
                while ($row = $resAva->fetch_assoc()) {
                    if (file_exists($row['image_data']) && strpos($row['image_data'], 'uploads/') === 0) unlink($row['image_data']);
                }
                
                $resBan = $conn->query("SELECT banner_data FROM groups_account WHERE id=$id");
                if ($row = $resBan->fetch_assoc()) {
                    if (file_exists($row['banner_data']) && strpos($row['banner_data'], 'uploads/') === 0) unlink($row['banner_data']);
                }

                $conn->query("DELETE FROM groups_account WHERE id=$id");
                $conn->query("DELETE FROM matches WHERE group_id=$id");
                $conn->query("DELETE FROM avatars WHERE group_id=$id");
                sendResponse(['status' => 'success']);
            }
        }

        // --- ADMIN / GUEST ACTIONS (KHÁCH THUÊ) ---
        if (isset($_SESSION['group_id']) && $_SESSION['role'] !== 'superadmin') {
            $grp_id = (int)$_SESSION['group_id'];
            $role = $_SESSION['role'];

            $checkExp = $conn->query("SELECT expire_date FROM groups_account WHERE id = $grp_id")->fetch_assoc();
            $today = date('Y-m-d');
            if (!empty($checkExp['expire_date']) && $checkExp['expire_date'] < $today) {
                 sendResponse(['status' => 'expired']);
		
            }
	// --- ĐỔI MẬT KHẨU CHO GROUP ADMIN ---
            if ($action === 'change_password' && $role === 'admin') {
                $new_raw = $input['new_password'];
                $new_hash = password_hash($new_raw, PASSWORD_DEFAULT);
                
                // Cập nhật cả password mã hoá và raw_password để Super Admin có thể xem
                $stmt = $conn->prepare("UPDATE groups_account SET password=?, raw_password=? WHERE id=?");
                $stmt->bind_param("ssi", $new_hash, $new_raw, $grp_id);
                
                if($stmt->execute()) {
                    sendResponse(['status' => 'success']);
                } else {
                    sendResponse(['status' => 'error', 'message' => 'Lỗi cập nhật mật khẩu.']);
                }
            }
            if ($action === 'add' && $role === 'admin') {
                $m = $input['match'];
                $stmt = $conn->prepare("INSERT INTO matches (id, group_id, match_date, match_time, team1, team2, bet, water, score, winner) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $t1 = json_encode($m['team1'], JSON_UNESCAPED_UNICODE); $t2 = json_encode($m['team2'], JSON_UNESCAPED_UNICODE);
                $stmt->bind_param("iissssiiss", $m['id'], $grp_id, $m['date'], $m['time'], $t1, $t2, $m['bet'], $m['water'], $m['score'], $m['winner']);
                $stmt->execute();
                sendResponse(['status' => 'success']);
            }
            
            if ($action === 'edit' && $role === 'admin') {
                $m = $input['match'];
                $stmt = $conn->prepare("UPDATE matches SET match_date=?, team1=?, team2=?, bet=?, water=?, score=?, winner=? WHERE id=? AND group_id=?");
                $t1 = json_encode($m['team1'], JSON_UNESCAPED_UNICODE); $t2 = json_encode($m['team2'], JSON_UNESCAPED_UNICODE);
                $stmt->bind_param("sssiissii", $m['date'], $t1, $t2, $m['bet'], $m['water'], $m['score'], $m['winner'], $m['id'], $grp_id);
                $stmt->execute();
                sendResponse(['status' => 'success']);
            }

            if ($action === 'delete' && $role === 'admin') {
                $stmt = $conn->prepare("DELETE FROM matches WHERE id=? AND group_id=?");
                $stmt->bind_param("ii", $input['id'], $grp_id);
                $stmt->execute();
                sendResponse(['status' => 'success']);
            }

            if ($action === 'upload_avatar' && $role === 'admin') {
                $name = $conn->real_escape_string($input['name']); 
                $base64_image = $input['image']; 

                $upload_dir = 'uploads/avatars/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

                $check = $conn->query("SELECT image_data FROM avatars WHERE group_id=$grp_id AND player_name='$name'");
                if ($check && $check->num_rows > 0) {
                    $old_file = $check->fetch_assoc()['image_data'];
                    if (file_exists($old_file) && strpos($old_file, 'uploads/') === 0) unlink($old_file);
                }

                list($type, $data) = explode(';', $base64_image);
                list(, $data)      = explode(',', $data);
                $data = base64_decode($data);
                
                $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name); 
                $filename = $upload_dir . $grp_id . '_' . $safe_name . '_' . time() . '.webp';
                file_put_contents($filename, $data);

                $stmt = $conn->prepare("INSERT INTO avatars (group_id, player_name, image_data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE image_data=VALUES(image_data)");
                $stmt->bind_param("iss", $grp_id, $name, $filename);
                $stmt->execute();
                
                sendResponse(['status' => 'success']);
            }
            
            if ($action === 'upload_banner' && $role === 'admin') {
                $base64_image = $input['image']; 
                $upload_dir = 'uploads/banners/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

                $check = $conn->query("SELECT banner_data FROM groups_account WHERE id = $grp_id");
                if ($check && $check->num_rows > 0) {
                    $old_file = $check->fetch_assoc()['banner_data'];
                    if (file_exists($old_file) && strpos($old_file, 'uploads/') === 0) unlink($old_file);
                }

                list($type, $data) = explode(';', $base64_image);
                list(, $data)      = explode(',', $data);
                $data = base64_decode($data);

                $filename = $upload_dir . 'banner_group_' . $grp_id . '_' . time() . '.webp';
                file_put_contents($filename, $data);

                $stmt = $conn->prepare("UPDATE groups_account SET banner_data = ? WHERE id = ?");
                $stmt->bind_param("si", $filename, $grp_id);
                $stmt->execute();
                
                sendResponse(['status' => 'success']);
            }

	// --- LƯU ẢNH CHỤP TỪ APP ---
        if ($action === 'save_capture') {
            $base64_image = $input['image']; 
            $upload_dir = 'uploads/captures/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

            list($type, $data) = explode(';', $base64_image);
            list(, $data)      = explode(',', $data);
            $data = base64_decode($data);

            // Tạo tên file ngẫu nhiên
            $filename = $upload_dir . 'capture_' . time() . '.jpg';
            file_put_contents($filename, $data);

            // Trả về link file thật
            sendResponse(['status' => 'success', 'url' => $filename]);
        }

            if ($action === 'fetch') {
                $matchesData = [];
                $result = $conn->query("SELECT * FROM matches WHERE group_id = $grp_id ORDER BY id ASC");
                if ($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        $matchesData[] = [
                            'id' => (int)$row['id'], 'date' => $row['match_date'], 'time' => $row['match_time'],
                            'team1' => json_decode($row['team1'], true), 'team2' => json_decode($row['team2'], true),
                            'bet' => (int)$row['bet'], 
                            'water' => isset($row['water']) ? (int)$row['water'] : 0, 
                            'score' => $row['score'], 
                            'winner' => $row['winner']
                        ];
                    }
                }
                $avatarsData = [];
                $resultAva = $conn->query("SELECT * FROM avatars WHERE group_id = $grp_id");
                if ($resultAva && $resultAva->num_rows > 0) {
                    while($row = $resultAva->fetch_assoc()) {
                        $avatarsData[$row['player_name']] = $row['image_data'];
                    }
                }
                
                $bannerQ = $conn->query("SELECT banner_data FROM groups_account WHERE id = $grp_id");
                $bannerData = $bannerQ->fetch_assoc()['banner_data'];

                sendResponse(['status' => 'success', 'data' => $matchesData, 'avatars' => $avatarsData, 'banner' => $bannerData]);
            }
        }
    }
}

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#12141c">
    <title>Hệ Thống Quản Lý - Badminton Battle</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link rel="icon" type="image/png" href="icon_512.png">
    <link rel="apple-touch-icon" href="icon_512.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <!-- CSS tách riêng - cache trình duyệt, tải nhanh hơn -->
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime('style.css'); ?>">
</head>
<body class="dark-mode">

<input type="file" id="avatar_file_input" accept="image/*" style="display: none;" onchange="handleAvatarSelection(event)">
<input type="file" id="banner_file_input" accept="image/*" style="display: none;" onchange="handleBannerSelection(event)">
<datalist id="player_list_suggestions"></datalist>

<div id="loading"><span id="loading_text">⏳ Đang xử lý 3D...</span></div>

<div id="login_screen" class="auth-screen">
    <button class="theme-btn login-theme-btn" onclick="toggleTheme()" title="Đổi giao diện">☀️ Sáng</button>
    <img src="login.png" alt="Banner Login" class="banner-login" onerror="this.src='https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?auto=format&fit=crop&w=1200&q=80'">
    <h2>Badminton Battle 3D</h2>
    
    <div id="form_login_view">
        <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 20px;">Nhập tài khoản nhóm do Admin cấp</p>
        <input type="text" id="login_user" placeholder="Tài khoản Nhóm (Ví dụ: baokhang)">
        <input type="password" id="login_pass" placeholder="Mật khẩu (Bỏ trống nếu xem tư cách Khách)">
        <div style="display:flex; gap: 15px; margin-top: 20px;">
            <button class="btn-outline" onclick="login('guest')" style="flex:1;">👁️ Xem Khách</button>
            <button class="btn-primary" onclick="login('admin')" style="flex:1;">🔐 Đăng Nhập</button>
        </div>
        <p style="margin-top: 20px; font-size: 14px;">Chưa có nhóm? <a href="javascript:void(0)" onclick="toggleAuthView('register')" style="color: var(--primary); font-weight: 900; text-decoration: none;">Đăng ký dùng thử 1 tháng</a></p>
    </div>

    <div id="form_register_view" style="display: none;">
        <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 20px;">Đăng ký tạo Nhóm/CLB mới</p>
        <input type="text" id="reg_group_name" placeholder="Tên Nhóm/CLB hiển thị">
        <input type="text" id="reg_user" placeholder="ID Đăng nhập (viết liền không dấu)">
        <input type="password" id="reg_pass" placeholder="Mật khẩu quản lý">
        <div style="font-size: 12px; color: var(--text-muted); text-align: left; background: var(--bg-input); padding: 15px; border-radius: 12px; margin-top: 15px; box-shadow: var(--shadow-inner); border: 1px solid var(--border-color);">
            <strong>📜 Điều khoản & Chính sách:</strong><br>
            Hệ thống được tạo ra với mục đích 100% giải trí và hỗ trợ ghi điểm vui vẻ cho các hội nhóm cầu lông. Chúng tôi <strong>không</strong> thu thập dữ liệu cá nhân nhạy cảm và <strong>không</strong> sử dụng dữ liệu vào mục đích thương mại. Bằng việc đăng ký, bạn đồng ý tham gia cộng đồng giải trí lành mạnh này.
        </div>
        <div style="display:flex; gap: 15px; margin-top: 20px;">
            <button class="btn-outline" onclick="toggleAuthView('login')" style="flex:1;">⬅️ Quay lại</button>
            <button class="btn-success" onclick="registerNewGroup()" style="flex:1;">🚀 Đăng Ký Ngay</button>
        </div>
    </div>
</div>

<div id="superadmin_screen" class="container" style="display:none; margin-top:30px;">
    <div class="header">
        <h1>👑 QUẢN TRỊ TỔNG 3D</h1>
        <div style="display: flex; align-items: center; gap: 15px;">
            <button class="theme-btn" onclick="toggleTheme()">☀️ Sáng</button>
            <button class="btn-danger" onclick="logout()">Đăng Xuất</button>
        </div>
    </div>
    <div class="nav-tabs">
        <button class="tab-btn active" id="btn_tab_sa_list" onclick="switchSATab('list')">📋 Danh Sách Nhóm</button>
        <button class="tab-btn" id="btn_tab_sa_add" onclick="switchSATab('add')">➕ Thêm Nhóm</button>
    </div>
    <div id="tab_sa_list" class="tab-pane active section">
        <h3 style="margin-top:0; color: var(--primary);">Danh Sách Các Nhóm Đang Thuê</h3>
        <div class="table-responsive">
            <table>
                <thead><tr><th>ID</th><th>Tên Nhóm</th><th>Tài khoản</th><th>Mật khẩu</th><th>License (Hết hạn)</th><th>Trạng thái</th><th>Hành động</th></tr></thead>
                <tbody id="accounts_tbody"></tbody>
            </table>
        </div>
        <div id="edit_account_box" class="edit-box" style="display:none;">
            <h4 style="margin-top:0; color:var(--primary);">✏️ Sửa Thông Tin & Gia Hạn</h4>
            <input type="hidden" id="edit_acc_id">
            <div style="display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap;">
                <div style="flex:1;"><label style="font-weight:bold; margin-bottom:5px; display:block;">Tên Nhóm:</label><input type="text" id="edit_acc_name"></div>
                <div style="flex:1;"><label style="font-weight:bold; margin-bottom:5px; display:block;">Tài khoản:</label><input type="text" id="edit_acc_user"></div>
                <div style="flex:1;"><label style="font-weight:bold; margin-bottom:5px; display:block;">Mật khẩu:</label><input type="text" id="edit_acc_pass"></div>
                <div style="flex:1;"><label style="font-weight:bold; margin-bottom:5px; display:block;">Ngày hết hạn:</label><input type="date" id="edit_acc_expire"></div>
            </div>
            <div style="display:flex; gap:15px;">
                <button class="btn-success" onclick="saveEditAccount()" style="flex:2;">💾 Lưu Thay Đổi</button>
                <button class="btn-outline" onclick="document.getElementById('edit_account_box').style.display='none'" style="flex:1;">❌ Hủy</button>
            </div>
        </div>
    </div>
    <div id="tab_sa_add" class="tab-pane section">
        <h3 style="margin-top:0; color: var(--primary);">Tạo Nhóm Thuê Mới</h3>
        <div style="display:flex; flex-direction:column; gap:15px; max-width:450px; margin: 0 auto;">
            <input type="text" id="new_group_name" placeholder="Tên hiển thị (Ví dụ: Sân Cầu Lông A)">
            <input type="text" id="new_group_user" placeholder="Tài khoản đăng nhập (viết liền ko dấu)">
            <input type="text" id="new_group_pass" placeholder="Mật khẩu cấp cho khách">
            <label style="font-weight:900; color:var(--text-muted); font-size:14px; margin-left:5px;">Ngày hết hạn License:</label>
            <input type="date" id="new_group_expire">
            <button class="btn-primary" onclick="createGroup()" style="padding:18px; margin-top:15px; font-size:16px;">✅ Tạo Tài Khoản Nhóm</button>
        </div>
    </div>
</div>

<div id="app_screen" class="container">
    <div class="banner-container">
        <img id="main_banner_img" src="logo.png" alt="Banner Main" class="banner-main" onerror="this.src='https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?auto=format&fit=crop&w=1200&q=80'">
        <button id="banner_upload_overlay" class="admin-only btn-primary" style="display:none; position: absolute; top: 15px; right: 15px; z-index: 10; padding: 10px 20px; border-radius: 12px; font-weight: 900; cursor: pointer; align-items: center; justify-content: center; gap: 8px;" onclick="document.getElementById('banner_file_input').click()">📷 Đổi Ảnh Bìa</button>
    </div>

    <div class="header">
        <h1 id="app_title">🏆 Bảng Phong Thần 3D</h1>
        <div style="display: flex; align-items: center; gap: 15px;">
            <span id="user_role_badge" style="font-weight: 900; color: var(--text-muted); background: var(--bg-input); padding: 8px 15px; border-radius: 12px; box-shadow: var(--shadow-inner); border: 1px solid var(--border-color);"></span>
            <button id="btn_change_pass" class="admin-only btn-warning" onclick="document.getElementById('change_pass_modal').style.display='flex'" style="padding: 10px 15px; color: #000;">🔑 Đổi Pass</button>
            <button class="theme-btn btn-outline" onclick="toggleTheme()" style="padding: 10px 15px;">☀️ Sáng</button>
            <button class="btn-danger" onclick="logout()" style="padding: 10px 15px;">Đăng Xuất</button>
        </div>
    </div>

    <div class="section" style="padding-bottom: 15px;">
        <div class="filter-bar">
            <input type="date" id="date_from" onchange="renderAll()" style="flex:1; margin-bottom:0;">
            <input type="date" id="date_to" onchange="renderAll()" style="flex:1; margin-bottom:0;">
            <button class="btn-primary" onclick="resetFilter()" style="flex:1; margin-bottom:0;">Hôm Nay</button>
            <button class="btn-warning" onclick="fetchDataFromServer(false)" style="flex:1; margin-bottom:0;">🔄 Làm Mới</button>
        </div>
        <div class="nav-tabs" style="margin-bottom:0; margin-top: 20px;">
            <button class="tab-btn active" onclick="switchTab('main')">📊 Tổng Quan</button>
            <button class="tab-btn" onclick="switchTab('stats')">📈 Thống Kê</button>
        </div>
    </div>

    <div id="tab_main" class="tab-pane active">
        <div class="section admin-only">
            <h2 id="form_title" style="text-align: center; color: var(--primary); font-weight: 900; font-size: 24px; margin-top: 0;">Thêm Trận Đấu Mới</h2>
            <input type="hidden" id="edit_match_id" value="">
            <div class="match-date-input-container">
                <label style="font-weight: 900; font-size: 15px; color: var(--primary);">📅 Ngày diễn ra:</label>
                <input type="date" id="match_date_input" style="width: auto; margin-bottom:0; padding: 10px 20px;">
            </div>
        </div>

        <div style="display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 25px; background: var(--bg-input); padding: 15px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-inner);">
            <div style="font-weight: 900; font-size: 15px; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">🏸 Nội dung:</div>
            <label style="cursor: pointer; display: flex; align-items: center; gap: 10px; margin: 0; font-weight: bold;">
                <input type="radio" name="match_type" value="doi" checked onchange="toggleMatchType()" style="margin: 0; width: 20px; height: 20px; cursor: pointer; box-shadow:none;"> <span>Đôi (2vs2)</span>
            </label>
            <label style="cursor: pointer; display: flex; align-items: center; gap: 10px; margin: 0; font-weight: bold;">
                <input type="radio" name="match_type" value="don" onchange="toggleMatchType()" style="margin: 0; width: 20px; height: 20px; cursor: pointer; box-shadow:none;"> <span>Đơn (1vs1)</span>
            </label>
        </div>

        <div class="match-form">
            <div class="team-box" id="box_team1">
                <h3>ĐỘI 1</h3>
                <div class="custom-autocomplete-container"><input type="text" id="t1_p1" placeholder="Người chơi 1" autocomplete="off"></div>
                <div class="custom-autocomplete-container"><input type="text" id="t1_p2" placeholder="Người chơi 2" autocomplete="off"></div>
                <div class="winner-selector">
                    <label><input type="radio" name="manual_winner" value="team1" onchange="highlightManualWinner()"><span>🏆 Chọn Thắng</span></label>
                </div>
            </div>
            <div class="center-col" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; z-index: 10;">
                <div style="background: var(--bg-input); padding: 10px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-inner); text-align: center; width: 100%;">
                    <label style="font-size: 11px; color: var(--text-muted); font-weight: 900; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 5px;">Tỉ Số</label>
                    <input type="text" id="match_score_input" placeholder="(Không bắt buộc)" oninput="autoHighlightWinner()" style="width: 100%; box-sizing: border-box; padding: 10px 5px; text-align: center; font-weight: 900; font-size: 14px; letter-spacing: 1px; margin: 0; box-shadow: none; background: var(--bg-panel); color: var(--success); border: 1px solid var(--border-color); border-radius: 10px;">
                </div>
                <div class="vs-badge" style="width: 45px; height: 45px; font-size: 16px; margin: 0;">VS</div>
                <button class="btn-info" type="button" onclick="autoMatchmake()" style="padding: 6px 12px; font-size: 11px; margin-bottom: 10px; box-shadow: var(--shadow-outer); border-radius: 8px;">⚖️ Xếp Kèo Tự Động</button>
                <div id="h2h_result"></div>
                <button class="btn-danger" type="button" id="btn_voice_input" style="padding: 10px 12px; font-size: 13px; margin-bottom: 10px; box-shadow: var(--shadow-outer); border-radius: 8px; transition: 0.2s; width: 100%; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; touch-action: manipulation;">🎤 Nhấn Giữ Để Nói</button>
            </div>
            <div class="team-box" id="box_team2">
                <h3 style="color: var(--text-muted);">ĐỘI 2</h3>
                <div class="custom-autocomplete-container"><input type="text" id="t2_p1" placeholder="Người chơi 1" autocomplete="off"></div>
                <div class="custom-autocomplete-container"><input type="text" id="t2_p2" placeholder="Người chơi 2" autocomplete="off"></div>
                <div class="winner-selector">
                    <label><input type="radio" name="manual_winner" value="team2" onchange="highlightManualWinner()"><span>🏆 Chọn Thắng</span></label>
                </div>
            </div>
        </div>

        <div class="form-footer" style="display: flex; flex-direction: column; gap: 20px;">
            <div style="display: flex; flex-wrap: wrap; gap: 15px; width: 100%;">
                <div style="flex: 1; background: var(--bg-input); padding: 15px; border-radius: 16px; border: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-inner);">
                    <label style="font-size: 13px; color: var(--text-muted); font-weight: 900; margin-bottom: 10px; text-align: center; text-transform: uppercase;">Điểm cược</label>
                    <input type="number" id="bet_amount" value="1" min="1" style="width: 100%; box-sizing: border-box; padding: 12px; text-align: center; font-weight: 900; font-size: 18px; margin: 0; box-shadow: none; background: var(--bg-panel); color: var(--primary);">
                </div>
                <div style="flex: 1; background: var(--bg-input); padding: 15px; border-radius: 16px; border: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-inner);">
                    <label style="font-size: 13px; color: var(--text-muted); font-weight: 900; margin-bottom: 10px; text-align: center; text-transform: uppercase;">Độ Nước</label>
                    <input type="number" id="water_amount" value="0" min="0" style="width: 100%; box-sizing: border-box; padding: 12px; text-align: center; font-weight: 900; font-size: 18px; margin: 0; box-shadow: none; background: var(--bg-panel); color: var(--secondary);">
                </div>
            </div>
            <div style="display: flex; gap: 15px; width: 100%;">
                <button class="btn-outline" onclick="cancelEdit()" id="btn_cancel" style="display: none; flex: 1; padding: 18px;">Hủy Bỏ</button>
                <button class="btn-success" onclick="saveMatch()" id="btn_save" style="flex: 2; padding: 18px; font-size: 18px;">LƯU TRẬN ĐẤU</button>
            </div>
        </div>

        <div class="section" id="capture_area">
            <div class="dashboard-header-flex" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; color: var(--primary); font-weight: 900;">📊 Bảng Phong Thần <br><span id="dashboard_date_display" class="dashboard-date"></span></h2>
                <button class="btn-info" onclick="captureDashboard()">📸 Chụp Bảng Điểm</button>
            </div>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>STT</th><th>Tên</th><th>Trận</th><th>T</th><th>B</th><th>Nước</th><th>H.Số</th><th>Điểm</th></tr></thead>
                    <tbody id="dashboard_body"></tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <h2 style="margin: 0; margin-bottom: 20px; color: var(--primary); font-weight: 900;">📜 Lịch Sử Trận Đấu <span id="history_date_display" class="dashboard-date"></span></h2>
            <div id="history_pagination" style="display: none; justify-content: center; align-items: center; gap: 20px; margin: 20px 0; background: var(--bg-input); padding: 15px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-inner);">
                <button class="btn-primary" id="btn_prev_date" onclick="changeHistoryDate(1)">⬅️ Ngày cũ</button>
                <span id="current_history_date_label" style="font-weight: 900; color: var(--text-main); font-size: 16px;"></span>
                <button class="btn-primary" id="btn_next_date" onclick="changeHistoryDate(-1)">Ngày mới ➡️</button>
            </div>
            <div class="history-grid" id="history_grid"></div>
        </div>

        <div class="section" style="background: transparent; box-shadow: none; padding: 0; border: none;">
            <div class="boards-container">
                <div class="board-half"><h3 style="color: #f1c40f;">🏆 Top 3 Lông Thủ</h3><div class="podium-container" id="top3_podium"></div></div>
                <div class="board-half"><h3 style="color: #e74c3c;">🐆 Top 3 Báo Thủ</h3><div class="podium-container" id="bottom3_podium"></div></div>
                <div class="board-half"><h3 style="color: #3498db;">🥤 Top 3 Thần Nước</h3><div class="podium-container" id="water3_podium"></div></div>
            </div>
        </div>
    </div>

    <div id="tab_stats" class="tab-pane">
        <div class="section">
            <h2 style="margin: 0; color: var(--primary); font-weight: 900;">📈 Thống Kê Chi Tiết 3D <span id="stats_date_display" class="dashboard-date"></span></h2>
            <p style="font-size: 14px; color: var(--text-muted); margin-top: 8px;">(Admin: Chạm vào vòng tròn để thay Avatar)</p>
            <div class="stats-grid-container" id="stats_container"></div>
        </div>
    </div>

    <div class="section" style="text-align: center; padding: 25px 15px; margin-top: 30px; font-size: 14px; color: var(--text-muted);">
        <div style="font-weight: 900; font-size: 20px; color: var(--primary); margin-bottom: 8px;">🏆 Badminton Battle 3D v1.0</div>
        <div style="margin-bottom: 15px;">Hệ thống ghi điểm & quản lý cầu lông nội bộ</div>
        <div style="background: var(--bg-input); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); display: inline-block; text-align: left; margin-bottom: 15px; box-shadow: var(--shadow-inner); max-width: 100%;">
            <div style="margin-bottom: 8px;">📞 <strong>Hỗ trợ & Gia hạn:</strong> <a href="https://zalo.me/0938844865" target="_blank" style="color: var(--success); text-decoration: none; font-weight: bold;">0938.844.865 (Zalo)</a></div>
            <div style="margin-bottom: 8px;">✉️ <strong>Báo lỗi & Góp ý:</strong> <a href="mailto:Badmintonbattle@gmail.com" style="color: var(--primary); text-decoration: none;">Badmintonbattle@gmail.com</a></div>
            <div>🌐 <strong>Cộng đồng:</strong> <span style="color: var(--secondary); font-weight: bold;">Facebook page: Update soon.</span></div>
        </div>
        <div style="font-size: 12px; margin-bottom: 15px; line-height: 1.6; padding: 0 10px; opacity: 0.8;">
            <strong style="color: var(--text-main);">📜 Điều khoản sử dụng:</strong> Hệ thống cung cấp công cụ lưu trữ điểm số độc lập cho các hội nhóm với mục đích giải trí lành mạnh. Chúng tôi không can thiệp vào dữ liệu nội bộ của các nhóm và không chịu trách nhiệm pháp lý về các vấn đề tranh chấp phát sinh giữa các thành viên.
        </div>
        <div style="margin-top: 15px; opacity: 0.5; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;">© 2026 Badminton Battle. All rights reserved.</div>
    </div>
</div>

<div id="stats_modal" class="stats-modal">
    <div class="modal-content-wrapper">
        <span class="close-modal" onclick="closeStatsModal()">&times;</span>
        <div id="capture_card_area"></div>
        <button class="btn-success" onclick="downloadSingleCard()" style="margin-top: 25px; width: 100%; padding: 18px; font-size: 16px;">📥 Tải Ảnh Thẻ 3D Về Máy</button>
    </div>
</div>

<div id="change_pass_modal" class="stats-modal">
    <div class="modal-content-wrapper" style="width: 350px;">
        <span class="close-modal" onclick="document.getElementById('change_pass_modal').style.display='none'">&times;</span>
        <h3 style="color: var(--primary); margin-top:0; margin-bottom: 20px;">🔑 Đổi Mật Khẩu</h3>
        <input type="password" id="cp_new_pass" placeholder="Nhập mật khẩu mới">
        <input type="password" id="cp_confirm_pass" placeholder="Nhập lại mật khẩu mới">
        <button class="btn-success" onclick="submitChangePassword()" style="width: 100%; margin-top: 15px; padding: 15px;">💾 Cập Nhật Mật Khẩu</button>
    </div>
</div>

<!-- JS tách riêng - trình duyệt cache, không tải lại mỗi lần -->
<script src="script.js?v=<?php echo filemtime('script.js'); ?>" defer></script>
</body>
</html>