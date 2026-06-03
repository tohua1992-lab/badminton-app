<?php
// Tắt sạch báo lỗi để không làm hỏng luồng dữ liệu JSON
ini_set('display_errors', 0); 
error_reporting(0); 
date_default_timezone_set('Asia/Ho_Chi_Minh');

// THÔNG TIN KẾT NỐI AIVEN CLOUD
$servername = "mysql-3c964605-badmintonappver2.h.aivencloud.com"; 
$username = "avnadmin";      
$password = "AVNS_gUxQuakKKnm2wAMNbOW";   
$dbname = "defaultdb";
$port = 20789;

// KHÓA BÍ MẬT ĐỂ TỰ ĐỘNG MÃ HÓA TOKEN (THAY THẾ SESSION CHO VERCEL)
define('SECRET_KEY', 'BadmintonBattle3D_SecuredKey_2026');

// HÀM TẠO TOKEN KHÔNG TRẠNG THÁI (STATELESS TOKEN)
function generateToken($payload) {
    $jsonData = json_encode($payload);
    $encodedData = base64_encode($jsonData);
    $signature = hash_hmac('sha256', $encodedData, SECRET_KEY);
    return $encodedData . '.' . $signature;
}

// HÀM KIỂM TRA VÀ GIẢI MÃ TOKEN KHÔNG TRẠNG THÁI
function verifyToken($token) {
    if (empty($token)) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 2) return false;
    list($encodedData, $signature) = $parts;
    $expectedSignature = hash_hmac('sha256', $encodedData, SECRET_KEY);
    if (hash_equals($expectedSignature, $signature)) {
        return json_decode(base64_decode($encodedData), true);
    }
    return false;
}

// HÀM TRẢ VỀ JSON CHUẨN
function sendResponse($data) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// KẾT NỐI AIVEN (BẮT BUỘC PHẢI DÙNG SSL BẢO MẬT)
$conn = mysqli_init();
$conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
$conn->real_connect($servername, $username, $password, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);

if (!$conn->connect_error) {
    $conn->set_charset("utf8mb4");
    mysqli_report(MYSQLI_REPORT_OFF);
// Tự động thêm cột match_time nếu database đang bị thiếu
    // --- TỰ ĐỘNG NÂNG CẤP DATABASE ---
    $conn->query("ALTER TABLE matches MODIFY COLUMN id BIGINT");
    $conn->query("ALTER TABLE matches ADD COLUMN match_time VARCHAR(20) DEFAULT NULL");
    $conn->query("ALTER TABLE matches ADD COLUMN water INT DEFAULT 0");
    $conn->query("ALTER TABLE matches ADD COLUMN score VARCHAR(50) DEFAULT NULL");
    
    // --- CÁC CỘT MỚI BỔ SUNG ĐỂ SỬA LỖI UNKNOWN COLUMN ---
    $conn->query("ALTER TABLE matches ADD COLUMN team1 TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE matches ADD COLUMN team2 TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE matches ADD COLUMN winner VARCHAR(20) DEFAULT NULL");
    $conn->query("ALTER TABLE matches ADD COLUMN bet INT DEFAULT 1");
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        $token = $input['token'] ?? '';

        // Giải mã thông tin đăng nhập từ Token gửi lên thay vì dùng $_SESSION
        $auth_role = null;
        $auth_group_id = null;
        $auth_group_name = null;
        $auth_expire_date = null;

        if (!empty($token)) {
            $decoded = verifyToken($token);
            if ($decoded) {
                $auth_role = $decoded['role'] ?? null;
                $auth_group_id = $decoded['group_id'] ?? null;
                $auth_group_name = $decoded['group_name'] ?? null;
                $auth_expire_date = $decoded['expire_date'] ?? null;
            }
        }

        // --- TỰ ĐĂNG KÝ TRẢI NGHIỆM 1 THÁNG ---
        if ($action === 'register_group') {
            $u = $conn->real_escape_string($input['new_user']);
            $raw_p = $input['new_pass'];
            $p = password_hash($raw_p, PASSWORD_DEFAULT);
            $n = $conn->real_escape_string($input['new_name']);
            $exp = '2099-12-31';
            
            $stmt = $conn->prepare("INSERT INTO groups_account (username, password, raw_password, group_name, expire_date) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssss", $u, $p, $raw_p, $n, $exp);
                if($stmt->execute()) { 
                    sendResponse(['status' => 'success', 'message' => "🎉 Đăng ký thành công!\nChào mừng CLB '{$n}' đến với hệ thống.\nTài khoản của bạn đã được kích hoạt Miễn Phí. Vui lòng Đăng nhập."]); 
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
                $generatedToken = generateToken(['role' => 'superadmin']);
                sendResponse(['status' => 'success', 'role' => 'superadmin', 'token' => $generatedToken]);
            }

            $res = $conn->query("SELECT * FROM groups_account WHERE username = '$user'");
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                if (password_verify($pass, $row['password'])) {
                    $expire_str = date('d/m/Y', strtotime($row['expire_date']));
                    $generatedToken = generateToken([
                        'role' => 'admin',
                        'group_id' => $row['id'],
                        'group_name' => $row['group_name'],
                        'expire_date' => $expire_str
                    ]);
                    
                    sendResponse([
                        'status' => 'success', 
                        'role' => 'admin', 
                        'group_name' => $row['group_name'], 
                        'expire_date' => $expire_str,
                        'token' => $generatedToken
                    ]);
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
                $expire_str = date('d/m/Y', strtotime($row['expire_date']));
                $generatedToken = generateToken([
                    'role' => 'guest',
                    'group_id' => $row['id'],
                    'group_name' => $row['group_name'],
                    'expire_date' => $expire_str
                ]);
                
                sendResponse([
                    'status' => 'success', 
                    'role' => 'guest', 
                    'group_name' => $row['group_name'], 
                    'expire_date' => $expire_str,
                    'token' => $generatedToken
                ]);
            }
            sendResponse(['status' => 'error', 'message' => 'Không tìm thấy ID Nhóm này!']);
        }

        if ($action === 'check_auth') {
            if ($auth_role) {
                sendResponse([
                    'status' => 'success', 
                    'role' => $auth_role, 
                    'group_name' => $auth_group_name ?? '',
                    'expire_date' => $auth_expire_date ?? ''
                ]);
            }
            sendResponse(['status' => 'error']);
        }

        if ($action === 'logout') {
            sendResponse(['status' => 'success']);
        }

        // --- SUPER ADMIN ACTIONS ---
        if ($auth_role === 'superadmin') {
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
        if (isset($auth_group_id) && $auth_role !== 'superadmin') {
            $grp_id = (int)$auth_group_id;
            $role = $auth_role;

            // --- ĐỔI MẬT KHẨU CHO GROUP ADMIN ---
            if ($action === 'change_password' && $role === 'admin') {
                $new_raw = $input['new_password'];
                $new_hash = password_hash($new_raw, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("UPDATE groups_account SET password=?, raw_password=? WHERE id=?");
                $stmt->bind_param("ssi", $new_hash, $new_raw, $grp_id);
                
                if($stmt->execute()) {
                    // Tạo lại token mới chứa mật khẩu cập nhật nếu cần, ở đây trả về thành công trực tiếp
                    sendResponse(['status' => 'success']);
                } else {
                    sendResponse(['status' => 'error', 'message' => 'Lỗi cập nhật mật khẩu.']);
                }
            }
            if ($action === 'add' && $role === 'admin') {
                $m = $input['match'];
                $stmt = $conn->prepare("INSERT INTO matches (id, group_id, match_date, match_time, team1, team2, bet, water, score, winner) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                if (!$stmt) {
    sendResponse(['status' => 'error', 'message' => 'Lỗi SQL thực tế là: ' . $conn->error]);
}

                $t1 = json_encode($m['team1'], JSON_UNESCAPED_UNICODE); 
                $t2 = json_encode($m['team2'], JSON_UNESCAPED_UNICODE);
                $stmt->bind_param("sissssiiss", $m['id'], $grp_id, $m['date'], $m['time'], $t1, $t2, $m['bet'], $m['water'], $m['score'], $m['winner']);
                
                if ($stmt->execute()) {
                    sendResponse(['status' => 'success']);
                } else {
                    sendResponse(['status' => 'error', 'message' => 'Lỗi thực thi MySQL: ' . $stmt->error]);
                }
            }
            
            if ($action === 'edit' && $role === 'admin') {
                $m = $input['match'];
                $stmt = $conn->prepare("UPDATE matches SET match_date=?, team1=?, team2=?, bet=?, water=?, score=?, winner=? WHERE id=? AND group_id=?");
                $t1 = json_encode($m['team1'], JSON_UNESCAPED_UNICODE); $t2 = json_encode($m['team2'], JSON_UNESCAPED_UNICODE);
                $stmt->bind_param("sssiisssi", $m['date'], $t1, $t2, $m['bet'], $m['water'], $m['score'], $m['winner'], $m['id'], $grp_id);
                $stmt->execute();
                sendResponse(['status' => 'success']);
            }

            if ($action === 'delete' && $role === 'admin') {
                $stmt = $conn->prepare("DELETE FROM matches WHERE id=? AND group_id=?");
                $stmt->bind_param("si", $input['id'], $grp_id);
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

            if ($action === 'save_capture') {
                $base64_image = $input['image']; 
                $upload_dir = 'uploads/captures/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

                list($type, $data) = explode(';', $base64_image);
                list(, $data)      = explode(',', $data);
                $data = base64_decode($data);

                $filename = $upload_dir . 'capture_' . time() . '.jpg';
                file_put_contents($filename, $data);

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
                $bannerData = $bannerQ->fetch_assoc()['banner_data'] ?? '';

                sendResponse(['status' => 'success', 'data' => $matchesData, 'avatars' => $avatarsData, 'banner' => $bannerData]);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#12141c">
    <title>Hệ Thống Quản Lý - Badminton Battle</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="icon" type="image/png" href="icon_512.png">
    <link rel="apple-touch-icon" href="icon_512.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <style>
        /* =========================================
           HỆ THỐNG 3D NEUMORPHISM PREMIUM DARK
           ========================================= */
        :root { 
            --bg-body: #ebf0f5; 
            --bg-panel: linear-gradient(145deg, #ffffff, #e6e9ee);
            --bg-input: #f4f7fa;
            --primary: #3498db; --secondary: #2980b9; 
            --success: #27ae60; --danger: #e74c3c; 
            --text-main: #2c3e50; --text-muted: #7f8c8d; 
            --border-color: rgba(255,255,255,0.5);
            --shadow-outer: 8px 8px 16px rgba(166, 180, 200, 0.6), -8px -8px 16px rgba(255,255,255, 0.8);
            --shadow-inner: inset 4px 4px 8px rgba(166, 180, 200, 0.6), inset -4px -4px 8px rgba(255,255,255, 0.8);
            --glow-active: 0 0 15px rgba(39, 174, 96, 0.3);
        }

        .dark-mode {
            --bg-body: #12141c; 
            --bg-panel: linear-gradient(145deg, #161922, #11131a);
            --bg-input: #0a0c11;
            --primary: #4facfe; --secondary: #3498db; 
            --success: #2ecc71; --danger: #ff4757;
            --text-main: #f0f6fc; --text-muted: #8b949e; 
            --border-color: rgba(255,255,255,0.03);
            --shadow-outer: 8px 8px 16px rgba(0,0,0,0.6), -4px -4px 10px rgba(255,255,255,0.03), inset 1px 1px 2px rgba(255,255,255,0.02);
            --shadow-inner: inset 4px 4px 8px rgba(0,0,0,0.8), inset -2px -2px 6px rgba(255,255,255,0.02);
            --glow-active: 0 0 15px rgba(46, 204, 113, 0.2), inset 0 0 10px rgba(46, 204, 113, 0.1);
        }

        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 15px; transition: all 0.3s ease; }
        .container { max-width: 900px; margin: 0 auto; position: relative;}
        
        .banner-login { width: 100%; object-fit: cover; border-radius: 20px; margin-bottom: 20px; box-shadow: var(--shadow-outer); height: 140px; }
        .banner-container { position: relative; border-radius: 20px; margin-bottom: 25px; overflow: hidden; box-shadow: var(--shadow-outer); border: 1px solid var(--border-color);}
        .banner-container .banner-main { display: block; width: 100%; height: 200px; object-fit: cover; transition: 0.3s;}
        .banner-overlay-flex { width: 100%; height: 100%; background: rgba(0,0,0,0.6); color: white; display: flex; justify-content: center; align-items: center; font-size: 18px; font-weight: bold; cursor: pointer; opacity: 0; transition: 0.3s; text-shadow: 1px 1px 3px rgba(0,0,0,0.8); }
        .banner-container:hover .banner-overlay-flex { opacity: 1; }

        .auth-screen, .header, .section, .board-half, .edit-box { 
            background: var(--bg-panel); 
            padding: 20px; 
            border-radius: 20px; 
            box-shadow: var(--shadow-outer); 
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
        }
        .auth-screen { max-width: 450px; margin: 50px auto; text-align: center; }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 15px 25px; }
        h1 { color: var(--primary); margin: 0; font-size: 22px; display: flex; align-items: center; flex-wrap: wrap; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);}
        
        input[type="text"], input[type="password"], input[type="number"], input[type="date"] { 
            width: 100%; padding: 14px 15px; margin-bottom: 12px; 
            border: 1px solid var(--border-color); border-radius: 12px; 
            box-sizing: border-box; font-size: 15px; 
            background: var(--bg-input); color: var(--text-main); 
            box-shadow: var(--shadow-inner); outline: none; transition: 0.3s;
        }
        input:focus { border-color: var(--primary); box-shadow: var(--shadow-inner), 0 0 5px rgba(79, 172, 254, 0.3); }
        
        button { 
            padding: 14px 20px; border: none; border-radius: 12px; cursor: pointer; 
            font-weight: 900; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;
            color: #fff; transition: all 0.15s ease; position: relative;
        }
        .btn-primary { background: linear-gradient(145deg, #4facfe, #00f2fe); box-shadow: 0 6px 0 #0088cc, 0 10px 15px rgba(0, 136, 204, 0.4), inset 0 2px 2px rgba(255,255,255,0.4); }
        .btn-primary:active { transform: translateY(6px); box-shadow: 0 0 0 #0088cc, 0 2px 5px rgba(0, 136, 204, 0.4), inset 0 3px 5px rgba(0,0,0,0.2); }
        
        .btn-success { background: linear-gradient(145deg, #2ecc71, #24a85a); box-shadow: 0 6px 0 #1b8045, 0 10px 15px rgba(39, 174, 96, 0.4), inset 0 2px 2px rgba(255,255,255,0.4); }
        .btn-success:active { transform: translateY(6px); box-shadow: 0 0 0 #1b8045, 0 2px 5px rgba(39, 174, 96, 0.4), inset 0 3px 5px rgba(0,0,0,0.2); }
        
        .btn-danger { background: linear-gradient(145deg, #ff4757, #ff6b81); box-shadow: 0 6px 0 #c0392b, 0 10px 15px rgba(231, 76, 60, 0.4), inset 0 2px 2px rgba(255,255,255,0.4); }
        .btn-danger:active { transform: translateY(6px); box-shadow: 0 0 0 #c0392b, 0 2px 5px rgba(231, 76, 60, 0.4), inset 0 3px 5px rgba(0,0,0,0.2); }
        
        .btn-info { background: linear-gradient(145deg, #9b59b6, #8e44ad); box-shadow: 0 6px 0 #6c3483, 0 10px 15px rgba(155, 89, 182, 0.4), inset 0 2px 2px rgba(255,255,255,0.4); }
        .btn-info:active { transform: translateY(6px); box-shadow: 0 0 0 #6c3483, 0 2px 5px rgba(155, 89, 182, 0.4), inset 0 3px 5px rgba(0,0,0,0.2); }
        
        .btn-warning { background: linear-gradient(145deg, #f1c40f, #f39c12); box-shadow: 0 6px 0 #d68910, 0 10px 15px rgba(243, 156, 18, 0.4), inset 0 2px 2px rgba(255,255,255,0.4); color: #000; }
        .btn-warning:active { transform: translateY(6px); box-shadow: 0 0 0 #d68910, 0 2px 5px rgba(243, 156, 18, 0.4), inset 0 3px 5px rgba(0,0,0,0.2); }

        .btn-outline { background: var(--bg-panel); color: var(--text-main); box-shadow: var(--shadow-outer); border: 1px solid var(--border-color); }
        .btn-outline:active { box-shadow: var(--shadow-inner); transform: translateY(2px); }
        
        .nav-tabs { display: flex; gap: 15px; margin-bottom: 25px; padding: 5px;}
        .tab-btn { flex: 1; padding: 15px; background: var(--bg-panel); color: var(--text-muted); box-shadow: var(--shadow-outer); border-radius: 16px; transition: 0.3s; border: 1px solid var(--border-color); }
        .tab-btn.active { color: var(--primary); box-shadow: var(--shadow-inner); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: fadeIn 0.4s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .filter-bar { display: flex; gap: 12px; align-items: center; margin-bottom: 15px; flex-wrap: wrap; }
        .filter-bar input[type="date"] { margin-bottom: 0; }

        .match-date-input-container { text-align: center; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 15px; background: var(--bg-panel); border: 1px solid var(--border-color); padding: 15px; border-radius: 16px; box-shadow: var(--shadow-outer);}
        .match-form { display: grid; grid-template-columns: 1fr 110px 1fr; gap: 15px; align-items: center; margin-top: 15px; }
        
        .team-box { background: var(--bg-panel); padding: 15px 15px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: var(--shadow-outer); transition: all 0.3s ease; position: relative; }
        .team-box.active { border-color: rgba(46, 204, 113, 0.6); box-shadow: var(--glow-active), var(--shadow-outer); }
        .team-box h3 { text-align: center; margin-top: 0; margin-bottom: 15px; font-size: 16px; color: var(--primary); font-weight: 900;}
        
        .winner-selector { display: flex; justify-content: center; margin-top: 15px; }
        .winner-selector label { display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--bg-input); box-shadow: var(--shadow-inner); border: 1px solid var(--border-color); padding: 12px 20px; border-radius: 16px; cursor: pointer; width: 100%; transition: 0.3s; color: var(--text-muted); font-weight: bold;}
        .winner-selector input { margin: 0; cursor: pointer; width: 16px; height: 16px; display: none;}
        .winner-selector input:checked + span { color: var(--success); text-shadow: 0 0 5px rgba(39, 174, 96, 0.3); }
        .team-box.active .winner-selector label { background: rgba(46, 204, 113, 0.1); border-color: rgba(46, 204, 113, 0.5); box-shadow: none; color: var(--text-main); }

        .vs-badge { width: 55px; height: 55px; border-radius: 50%; background: radial-gradient(circle at 30% 30%, #ffe259 0%, #ffa751 100%); display: flex; justify-content: center; align-items: center; font-weight: 900; font-size: 20px; font-style: italic; color: #8e4a00; box-shadow: 0 8px 20px rgba(255, 167, 81, 0.4), inset 2px 2px 5px rgba(255,255,255,0.6), inset -2px -2px 5px rgba(0,0,0,0.3); border: 3px solid var(--bg-body); z-index: 10; margin: auto;}

        .form-footer { margin-top: 25px; display: flex; justify-content: space-between; align-items: center; background: var(--bg-panel); border: 1px solid var(--border-color); box-shadow: var(--shadow-outer); padding: 20px; border-radius: 20px; }
        
        .table-responsive { overflow-x: auto; border-radius: 16px; box-shadow: var(--shadow-outer); border: 1px solid var(--border-color); background: var(--bg-panel); margin-top: 15px;}
        table { width: 100%; border-collapse: collapse; min-width: 550px; }
        table, th, td { border: none; }
        th { background: rgba(0,0,0,0.2); color: var(--text-muted); font-weight: 900; text-transform: uppercase; letter-spacing: 1px; padding: 15px; border-bottom: 1px solid var(--border-color);}
        td { padding: 15px; text-align: center; font-size: 15px; font-weight: bold; border-bottom: 1px solid rgba(255,255,255,0.02);}
        
        .row-top1 td { color: #f1c40f !important; background: rgba(241, 196, 15, 0.05); }
        .row-top2 td { color: #bdc3c7 !important; }
        .row-top3 td { color: #e67e22 !important; }
        .row-bot1 td { color: var(--danger) !important; background: rgba(231, 76, 60, 0.05); }
        .row-bot2 td, .row-bot3 td { color: #e74c3c !important; }

        .history-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-top: 20px; }
        .match-card-v2 { background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 20px; padding: 20px 15px; padding-bottom: 40px; box-shadow: var(--shadow-outer); position: relative; overflow: hidden; transition: all 0.3s ease; }
        .match-card-v2:hover { transform: translateY(-5px); border-color: var(--primary); }
        .match-card-v2::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--primary), var(--success)); }
        
        .match-info-header { text-align: center; font-size: 11px; color: var(--text-muted); margin-bottom: 15px; letter-spacing: 1px; text-transform: uppercase; font-weight: 900;}
        .match-battle-area { display: flex; justify-content: space-between; align-items: center; position: relative; }
        .team-col { flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: 0.3s;}
        .team-col.loser .avatar-split-container, .team-col.loser .team-names-v2 { filter: grayscale(100%); opacity: 0.4; }
        
        .avatar-split-container { position: relative; width: 80px; height: 80px; margin-bottom: 10px;}
        .ava-p1, .ava-p2 { width: 50px; height: 50px; border-radius: 50%; position: absolute; background: var(--bg-body); display: flex; justify-content: center; align-items: center; font-weight: 900; overflow: hidden; z-index: 2; font-size: 18px; box-shadow: 0 4px 10px rgba(0,0,0,0.5), inset 2px 2px 4px rgba(255,255,255,0.2); border: 2px solid var(--border-color);}
        .ava-p1 img, .ava-p2 img { width: 100%; height: 100%; object-fit: cover; }
        .ava-p1 { top: -2px; left: -2px; }
        .ava-p2 { bottom: -2px; right: -2px; z-index: 1;}
        .avatar-split-container.single-mode .ava-p1 { width: 100%; height: 100%; top: 0; left: 0; border-radius: 50%; font-size: 28px;}
        
        .diagonal-line { position: absolute; top: 50%; left: 50%; width: 160%; height: 2px; background: rgba(255,255,255,0.2); transform: translate(-50%, -50%) rotate(-45deg); z-index: 1; }
        .team-names-v2 { text-align: center; font-size: 13px; line-height: 1.4; font-weight: bold; color: var(--text-main); width: 100%;}
        
        .stamp { position: absolute; top: 35%; left: 50%; transform: translate(-50%, -50%) rotate(-15deg); font-size: 16px; font-weight: 900; padding: 4px 10px; border: 2px solid; border-radius: 8px; z-index: 10; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 10px rgba(0,0,0,0.5); opacity: 0.9; background: var(--bg-panel); backdrop-filter: blur(2px);}
        .stamp.win { color: var(--success); border-color: var(--success); }
        .stamp.lose { color: var(--danger) !important; border-color: var(--danger) !important; }
        
        .vs-col { width: 70px; display: flex; flex-direction: column; justify-content: center; align-items: center; position: relative; z-index: 5; margin: 0 10px;}
        .vs-fire-text { font-size: 20px; font-weight: 900; font-style: italic; color: var(--text-muted); opacity: 0.5; margin-bottom: 5px;}
        .bet-badge { background: var(--bg-input); color: var(--text-main); padding: 4px 10px; border-radius: 12px; font-size: 10px; font-weight: bold; margin-top: 5px; box-shadow: var(--shadow-inner); border: 1px solid var(--border-color); white-space: nowrap;}
        .score-badge { color: #fff; background: linear-gradient(145deg, #9b59b6, #8e44ad); box-shadow: 0 4px 10px rgba(155, 89, 182, 0.4); border: none; font-size: 11px;}
        
        .match-card-v2 .card-actions { position: absolute; bottom: 12px; right: 12px; display: flex; gap: 8px; opacity: 0; transition: all 0.3s ease; }
        .match-card-v2:hover .card-actions { opacity: 1; }
        .match-card-v2 .card-actions button { padding: 6px 12px; font-size: 11px; min-height: 28px; box-shadow: var(--shadow-outer); transform: translateY(0);}
        .match-card-v2 .card-actions button:active { box-shadow: var(--shadow-inner); }
        @media (max-width: 768px) { .match-card-v2 .card-actions { opacity: 1; } }

        .boards-container { display: flex; flex-wrap: wrap; gap: 25px; margin-top: 15px; }
        .board-half { flex: 1; min-width: 300px; }
        .board-half h3 { text-align: center; margin-top: 0; font-size: 20px; font-weight: 900; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);}
        .podium-container { display: flex; justify-content: center; align-items: flex-end; gap: 10px; margin-top: 40px; min-height: 180px; }
        .podium-box { width: 30%; text-align: center; border-radius: 15px 15px 0 0; color: white; padding-top: 15px; font-weight: bold; position: relative; display: flex; flex-direction: column; transition: 0.3s; box-shadow: var(--shadow-outer); border: 1px solid var(--border-color); border-bottom: none;}
        
        .podium-1 { background: linear-gradient(145deg, #f1c40f, #f39c12); height: 170px; z-index: 3; }
        .podium-2 { background: linear-gradient(145deg, #bdc3c7, #95a5a6); height: 140px; z-index: 2; }
        .podium-3 { background: linear-gradient(145deg, #d35400, #e67e22); height: 120px; z-index: 1; }
        .podium-bot-1 { background: linear-gradient(145deg, #c0392b, #e74c3c); height: 170px; z-index: 3; }
        .podium-bot-2, .podium-bot-3 { background: linear-gradient(145deg, #7f8c8d, #95a5a6); height: 130px; z-index: 2; }
        .podium-water-1 { background: linear-gradient(145deg, #00a8ff, #0097e6); height: 170px; z-index: 3; }
        .podium-water-2, .podium-water-3 { background: linear-gradient(145deg, #74b9ff, #0984e3); height: 130px; z-index: 2; }
        
        .podium-avatar { width: 55px; height: 55px; background: var(--bg-body); border-radius: 50%; margin: -45px auto 10px auto; display: flex; align-items: center; justify-content: center; font-size: 24px; border: 3px solid #fff; color: var(--text-main); box-shadow: 0 5px 15px rgba(0,0,0,0.5), inset 2px 2px 5px rgba(0,0,0,0.1); overflow: hidden;}
        .podium-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .podium-name { font-size: 14px; margin-bottom: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 5px; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); }
        .podium-score { font-size: 22px; font-weight: 900; background: rgba(0,0,0,0.3); margin: auto 10px 15px 10px; border-radius: 10px; padding: 4px 0; box-shadow: var(--shadow-inner);}

        .stats-grid-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 25px; margin-top: 20px; }
        .stat-card { display: flex; background: var(--bg-panel); border-radius: 24px; padding: 20px; box-shadow: var(--shadow-outer); border: 1px solid var(--border-color); align-items: center; gap: 20px; transition: 0.3s; }
        .stat-avatar { width: 100px; height: 140px; border-radius: 16px; background: var(--bg-body); color: var(--text-main); display: flex; justify-content: center; align-items: center; font-size: 40px; font-weight: 900; flex-shrink: 0; box-shadow: var(--shadow-inner); border: 1px solid var(--border-color); position: relative; overflow: hidden; }
        .stat-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .upload-overlay { position: absolute; bottom: 0; left: 0; width: 100%; background: rgba(0, 0, 0, 0.7); color: #fff; font-size: 12px; padding: 6px 0; text-align: center; opacity: 0; transition: opacity 0.3s ease; }
        .stat-avatar:hover .upload-overlay { opacity: 1; }
        .stat-info { flex-grow: 1; }
        .stat-name { margin: 0 0 15px 0; font-size: 20px; color: var(--primary); font-weight: 900; border-bottom: 2px dashed var(--border-color); padding-bottom: 10px;}
        .stat-details-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; text-align: center; }
        .stat-box { background: var(--bg-input); padding: 10px 5px; border-radius: 12px; display: flex; flex-direction: column; justify-content: center; box-shadow: var(--shadow-inner); border: 1px solid var(--border-color);}
        .stat-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; font-weight: bold; margin-bottom: 4px; }
        .stat-val { font-size: 16px; font-weight: 900; color: var(--text-main); }

        .badge-title { display: inline-block; font-size: 10px; padding: 4px 8px; border-radius: 12px; margin-left: 8px; font-weight: 900; vertical-align: middle; box-shadow: 0 2px 5px rgba(0,0,0,0.3); color: #fff;}
        .title-top1 { background: linear-gradient(145deg, #f1c40f, #f39c12); border: 1px solid #d35400; }
        .title-top23 { background: linear-gradient(145deg, #bdc3c7, #7f8c8d); border: 1px solid #34495e; }
        .title-bot { background: linear-gradient(145deg, #e74c3c, #c0392b); border: 1px solid #900; }
        .title-water { background: linear-gradient(145deg, #00a8ff, #0097e6); border: 1px solid #0097e6; }

        @media (max-width: 768px) {
            .header { flex-direction: column; gap: 15px; }
            .match-form { grid-template-columns: 1fr; gap: 15px; }
            .center-col { flex-direction: column-reverse !important; margin: 5px 0; }
            .vs-badge { position: relative; top: auto; left: auto; transform: none; margin: 0 auto; border: 3px solid var(--bg-body); }
            
            .form-footer { padding: 15px; }
            .form-footer > div:first-child { flex-direction: row; flex-wrap: wrap; gap: 10px; }
            .form-footer > div:first-child > div { flex: 1; min-width: 45%; padding: 12px; }
            .form-footer > div:last-child { width: 100%; flex-direction: column; }
            .form-footer > div:last-child button { width: 100%; }
            
            .history-grid { grid-template-columns: 1fr; }
            
            .boards-container { gap: 15px; flex-direction: column; }
            .board-half { width: 100%; min-width: 0; padding: 15px; }
            .podium-container { min-height: 120px; margin-top: 35px; gap: 5px; }
            .podium-box { width: 32%; }
            .podium-1, .podium-bot-1, .podium-water-1 { height: 120px; }
            .podium-2, .podium-bot-2, .podium-3, .podium-bot-3, .podium-water-2, .podium-water-3 { height: 90px; }
            .podium-avatar { width: 40px; height: 40px; margin: -30px auto 5px auto; font-size: 16px; border-width: 2px; }
            .podium-score { font-size: 16px; margin: auto 5px 10px 5px; padding: 2px 0; }
            .podium-name { font-size: 11px; }

            .stat-card { flex-direction: column; text-align: center; }
            .stat-avatar { width: 120px; height: 120px; border-radius: 50%; }
            .stat-details-grid { grid-template-columns: repeat(2, 1fr); width: 100%;}
        }
        
        #loading { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 9999; justify-content: center; align-items: center; font-size: 20px; font-weight: bold; color: #fff; flex-direction: column; gap: 15px;}
        .dashboard-date { font-size: 14px; font-weight: normal; color: var(--text-muted); font-style: italic; display: block; margin-top: 8px;}

        .stats-modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(8px); justify-content: center; align-items: center; }
        .modal-content-wrapper { position: relative; background: var(--bg-panel); padding: 30px; border-radius: 30px; max-width: 90%; width: 450px; text-align: center; box-shadow: var(--shadow-outer); border: 1px solid var(--border-color); }
        .modal-content-wrapper .stat-card { flex-direction: column; width: 100%; box-shadow: none; border: none; padding: 0; background: transparent;}
        .modal-content-wrapper .stat-avatar { width: 180px; height: 240px; margin-bottom: 20px; font-size: 70px; border-radius: 20px;}
        .modal-content-wrapper .stat-name { font-size: 28px; margin-bottom: 25px; }
        .close-modal { position: absolute; top: -50px; right: 0; color: white; font-size: 35px; cursor: pointer; text-shadow: 0 2px 5px rgba(0,0,0,0.5);}
        .stat-avatar-hq { display: none; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 15px; transition: all 0.3s ease; overflow-x: hidden; }
        .container { max-width: 900px; margin: 0 auto; position: relative; width: 100%; }
        #app_screen, #login_screen, #superadmin_screen { display: none; }

        .custom-autocomplete-container { position: relative; width: 100%; }
        .custom-autocomplete-box { position: absolute; top: 100%; left: 0; width: 100%; max-height: 180px; overflow-y: auto; background: var(--bg-panel); border: 1px solid var(--primary); box-shadow: 0 10px 25px rgba(0,0,0,0.5); border-radius: 12px; z-index: 9999; margin-top: -10px; display: none; }
        .custom-suggestion-item { padding: 12px 15px; cursor: pointer; font-size: 15px; font-weight: bold; color: var(--text-main); border-bottom: 1px solid var(--border-color); transition: 0.2s; }
        .custom-suggestion-item:last-child { border-bottom: none; }
        .custom-suggestion-item:active, .custom-suggestion-item:hover { background: rgba(79, 172, 254, 0.1); color: var(--primary); }

        @keyframes goldGlow { 0% { box-shadow: inset 0 0 5px rgba(241,196,15,0.1); } 50% { box-shadow: inset 0 0 20px rgba(241,196,15,0.5); background: rgba(241, 196, 15, 0.1); } 100% { box-shadow: inset 0 0 5px rgba(241,196,15,0.1); } }
        @keyframes silverGlow { 0% { box-shadow: inset 0 0 5px rgba(189,195,199,0.1); } 50% { box-shadow: inset 0 0 15px rgba(189,195,199,0.4); background: rgba(189,195,199,0.1); } 100% { box-shadow: inset 0 0 5px rgba(189,195,199,0.1); } }
        @keyframes bronzeGlow { 0% { box-shadow: inset 0 0 5px rgba(230,126,34,0.1); } 50% { box-shadow: inset 0 0 15px rgba(230,126,34,0.4); background: rgba(230,126,34,0.1); } 100% { box-shadow: inset 0 0 5px rgba(230,126,34,0.1); } }
        @keyframes redPulse { 0% { box-shadow: inset 0 0 5px rgba(231,76,60,0.1); } 50% { box-shadow: inset 0 0 20px rgba(231,76,60,0.5); background: rgba(231,76,60,0.1); } 100% { box-shadow: inset 0 0 5px rgba(231,76,60,0.1); } }
        @keyframes softRedPulse { 0% { box-shadow: inset 0 0 5px rgba(231,76,60,0.05); } 50% { box-shadow: inset 0 0 10px rgba(231,76,60,0.3); background: rgba(231,76,60,0.05); } 100% { box-shadow: inset 0 0 5px rgba(231,76,60,0.05); } }

        .row-top1 td { animation: goldGlow 2s infinite alternate; border-top: 1px solid rgba(241,196,15,0.5); border-bottom: 1px solid rgba(241,196,15,0.5); }
        .row-top2 td { animation: silverGlow 2.5s infinite alternate; border-top: 1px solid rgba(189,195,199,0.4); border-bottom: 1px solid rgba(189,195,199,0.4); }
        .row-top3 td { animation: bronzeGlow 3s infinite alternate; border-top: 1px solid rgba(230,126,34,0.4); border-bottom: 1px solid rgba(230,126,34,0.4); }
        .row-bot1 td { animation: redPulse 2s infinite alternate; border-top: 1px solid rgba(231,76,60,0.5); border-bottom: 1px solid rgba(231,76,60,0.5); }
        .row-bot2 td { animation: softRedPulse 2.5s infinite alternate; border-top: 1px solid rgba(231,76,60,0.3); border-bottom: 1px solid rgba(231,76,60,0.3); }
        .row-bot3 td { animation: softRedPulse 3s infinite alternate; border-top: 1px solid rgba(231,76,60,0.2); border-bottom: 1px solid rgba(231,76,60,0.2); }

        .streak-badge { font-size: 11px; padding: 2px 6px; border-radius: 8px; margin-left: 5px; font-weight: 900; box-shadow: 0 2px 5px rgba(0,0,0,0.5); }
        .streak-win { background: rgba(230, 126, 34, 0.2); color: #f39c12; border: 1px solid #f39c12; text-shadow: 0 0 5px #f39c12; }
        .streak-lose { background: rgba(52, 152, 219, 0.2); color: #4facfe; border: 1px solid #4facfe; text-shadow: 0 0 5px #4facfe; }

        #h2h_result { font-size: 12px; font-weight: 900; text-align: center; margin-top: 10px; color: var(--primary); background: rgba(0,0,0,0.3); padding: 5px 10px; border-radius: 8px; border: 1px dashed var(--primary); display: none; }

        .card-container { perspective: 1000px; display: flex; justify-content: center; margin-bottom: 10px;}
        .fut-card { width: 300px; height: 480px; background: var(--bg-panel); border-radius: 20px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.8), inset 0 0 0 2px rgba(255,215,0,0.3); transition: transform 0.5s ease; transform-style: preserve-3d; border: 2px solid rgba(255,215,0,0.2); overflow: hidden; }
        .fut-card::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: linear-gradient(45deg, rgba(255,255,255,0) 40%, rgba(255,255,255,0.2) 50%, rgba(255,255,255,0) 60%); transform: rotate(45deg); opacity: 0; transition: 0.5s; pointer-events: none; z-index: 10; }
        .fut-card:hover::after { opacity: 1; transform: rotate(45deg) translateY(50%); }
        .card-rating { position: absolute; top: 20px; left: 15px; text-align: center; z-index: 2; }
        .card-rating .label { font-size: 8px; font-weight: 900; color: #f1c40f; text-transform: uppercase; margin-bottom: -2px; display: block; letter-spacing: 0.5px; }
        .card-rating .pos { font-size: 13px; font-weight: bold; color: #f1c40f; border-bottom: 2px solid #f1c40f; padding-bottom: 2px; margin-bottom: 5px;}
        .card-avatar-fut { width: 100%; height: 200px; display: flex; justify-content: center; align-items: flex-end; background: radial-gradient(circle at center, rgba(255,255,255,0.1) 0%, transparent 70%); }
        .card-avatar-fut img { height: 180px; object-fit: cover; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.8)); border-radius: 10px;}
        .card-name-fut { text-align: center; font-size: 22px; font-weight: 900; text-transform: uppercase; margin: 5px 0; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px; width: 80%; margin-left: auto; margin-right: auto; color: #fff; }
        .card-radar { width: 100%; height: 150px; position: relative; z-index: 2; display: flex; justify-content: center;}
        .card-stats-fut { display: flex; justify-content: space-between; padding: 10px 25px; font-size: 13px; font-weight: bold; color: #ddd; }
        .stat-col { display: flex; flex-direction: column; gap: 5px; }
        .stat-item { display: flex; gap: 5px; align-items: center;}
        .stat-val { font-size: 15px; font-weight: 900; color: #fff; min-width: 45px; text-align: left; }

        .card-red { background: linear-gradient(135deg, #c0392b, #8e44ad); border-color: #ff4757;}
        .card-gold { background: linear-gradient(135deg, #d4af37, #8a6d3b); border-color: #f1c40f;}
        .card-silver { background: linear-gradient(135deg, #bdc3c7, #7f8c8d); border-color: #fff;}
        .card-bronze { background: linear-gradient(135deg, #cd7f32, #8b5a2b); border-color: #e67e22;}
        .card-vip { background: linear-gradient(135deg, #0f2027, #203a43, #2c5364); border-color: #00f2fe;}
    </style>
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
        <p style="margin-top: 20px; font-size: 14px;">Chưa có nhóm? <a href="javascript:void(0)" onclick="toggleAuthView('register')" style="color: var(--primary); font-weight: 900; text-decoration: none;">Đăng ký Tài khoản Nhóm (Miễn Phí)</a></p>
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
                <thead>
                    <tr><th>ID</th><th>Tên Nhóm</th><th>Tài khoản</th><th>Mật khẩu</th><th>License (Hết hạn)</th><th>Trạng thái</th><th>Hành động</th></tr>
                </thead>
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
		    <button id="btn_change_pass" class="admin-only btn-warning" onclick="document.getElementById('change_pass_modal').style.display='flex'" style="padding: 10px 15px; display: none; color: #000;">🔑 Đổi Pass</button>
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
            <button class="tab-btn" onclick="switchTab('monthly')">📅 Tổng Kết Tháng</button> 
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
        </div>
                
        <div class="section" id="capture_area">
            <div class="dashboard-header-flex" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; color: var(--primary); font-weight: 900;">📊 Bảng Phong Thần <br><span id="dashboard_date_display" class="dashboard-date"></span></h2>
                <button class="btn-info" onclick="captureDashboard()">📸 Chụp Bảng Điểm</button>
            </div>
            <div class="table-responsive">
                <table><tbody id="dashboard_body"></tbody></table>
            </div>
        </div>

        <div class="section" id="capture_history_area">
            <div class="dashboard-header-flex" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; color: var(--primary); font-weight: 900;">📜 Lịch Sử Trận Đấu <br><span id="history_date_display" class="dashboard-date"></span></h2>
                <button class="btn-info" onclick="captureHistory()">📸 Chụp Lịch Sử</button>
            </div>
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

	<div id="tab_monthly" class="tab-pane">
        <div class="section">
            <h2 style="margin: 0; color: var(--primary); font-weight: 900;">📅 Báo Cáo Tổng Kết Tháng</h2>
            <div class="filter-bar" style="margin-top: 15px; display: flex; gap: 10px;">
                <select id="report_month" class="btn-outline" style="flex: 1; padding: 12px; border-radius: 12px; background: var(--bg-input); color: var(--text-main);"></select>
                <select id="report_year" class="btn-outline" style="flex: 1; padding: 12px; border-radius: 12px; background: var(--bg-input); color: var(--text-main);"></select>
                <button class="btn-primary" onclick="generateMonthlyReport()" style="flex: 1;">Xem Báo Cáo</button>
            </div>
        </div>

        <div id="monthly_report_content" style="display: none;">
            <div class="section" id="capture_monthly_area">
                <div class="dashboard-header-flex" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; color: var(--primary); font-weight: 900;">🏆 Bảng Vàng Tháng <br><span id="monthly_label" class="dashboard-date"></span></h2>
                    <button class="btn-info" onclick="captureMonthlyReport()">📸 Chụp Báo Cáo</button>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>STT</th><th>Tên</th><th>Trận</th><th>T</th><th>B</th><th>Nước</th><th>H.Số</th><th>Điểm</th></tr></thead>
                        <tbody id="monthly_dashboard_body"></tbody>
                    </table>
                </div>
            </div>
            <div class="section">
                <h2 style="margin: 0; color: var(--primary); font-weight: 900; margin-bottom: 20px;">📈 Thống Kê Chi Tiết Tháng</h2>
                <div class="stats-grid-container" id="monthly_stats_container"></div>
            </div>
            <p style="text-align: center; margin-top: 15px; font-size: 12px; color: var(--text-muted); font-style: italic;">Dữ liệu được tổng kết tự động bởi Badminton Battle 3D</p>
        </div>
    </div>

    <div class="section" style="text-align: center; padding: 25px 15px; margin-top: 30px; font-size: 14px; color: var(--text-muted);">
        <div style="font-weight: 900; font-size: 20px; color: var(--primary); margin-bottom: 8px;">🏆 Badminton Battle 3D v1.0</div>
        <div style="margin-bottom: 15px;">Hệ thống ghi điểm & quản lý cầu lông nội bộ</div>
        <div style="display: flex; justify-content: center; align-items: stretch; gap: 20px; flex-wrap: wrap; margin-bottom: 20px; max-width: 100%;">
            <div style="background: var(--bg-input); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); text-align: left; box-shadow: var(--shadow-inner); flex: 1; min-width: 250px;">
                <div style="margin-bottom: 8px;">📞 <strong>Hỗ trợ & Gia hạn:</strong> <a href="https://zalo.me/0938844865" target="_blank" style="color: var(--success); text-decoration: none; font-weight: bold;">0938.844.865 (Zalo)</a></div>
                <div style="margin-bottom: 8px;">✉️ <strong>Báo lỗi & Góp ý:</strong> <a href="mailto:Badmintonbattle@gmail.com" style="color: var(--primary); text-decoration: none;">Badmintonbattle@gmail.com</a></div>
                <div>🌐 <strong>Cộng đồng:</strong> <span style="color: var(--secondary); font-weight: bold;">Facebook page: Update soon.</span></div>
            </div>
            <div style="background: var(--bg-input); padding: 15px; border-radius: 12px; border: 1px dashed #c61a7e; text-align: left; box-shadow: var(--shadow-inner); flex: 1; min-width: 250px; display: flex; align-items: center; justify-content: space-between; gap: 15px; transition: 0.3s;">
                <div>
                    <div style="font-weight: 900; color: #c61a7e; font-size: 15px; margin-bottom: 5px; text-transform: uppercase;">☕ Tiếp Lửa Admin</div>
                    <div style="font-size: 12px; color: var(--text-muted); line-height: 1.4; margin-bottom: 8px;">Ủng hộ ly trà đá để duy trì máy chủ chạy mượt mà hơn nhé!</div>
                    <div style="font-size: 15px; font-weight: 900; color: var(--text-main);"><img src="https://upload.wikimedia.org/wikipedia/vi/f/fe/MoMo_Logo.png" alt="MoMo" style="height: 16px; vertical-align: middle; margin-right: 5px;"> 0327.840.068</div>
                </div>
                <img src="Screenshot_13.png" alt="QR MoMo" style="width: 110px; height: 110px; border-radius: 8px; object-fit: contain; background: #fff; padding: 3px; border: 1px solid rgba(0,0,0,0.1); box-shadow: var(--shadow-outer); flex-shrink: 0;">
            </div>
        </div>
        <div style="font-size: 12px; margin-bottom: 15px; line-height: 1.6; padding: 0 10px; opacity: 0.8;">
            <strong style="color: var(--text-main);">📜 Điều khoản sử dụng:</strong> Hệ thống cung cấp công cụ lưu trữ điểm số độc lập cho các hội nhóm với mục đích giải trí lành mạnh.
        </div>
    </div> 
</div>

<div id="stats_modal" class="stats-modal">
    <div class="modal-content-wrapper" style="background: transparent; border: none; box-shadow: none;">
        <span class="close-modal" onclick="closeStatsModal()" style="top: -20px; right: 10px;">&times;</span>
        <div id="capture_card_area">
            <div class="card-container">
                <div class="fut-card card-silver" id="player_fut_card">
                    <div class="card-rating">
                        <span class="label">Overall</span> <div class="ovr" id="fut_ovr">0</div>
                        <div class="pos" id="fut_pos">POS</div>
                        <div class="country">🏸</div>
                    </div>
                    <div class="card-avatar-fut" id="fut_avatar"></div>
                    <div class="card-name-fut" id="fut_name">NAME</div>
                    <div class="card-radar"><div style="width: 220px; height: 100%;"><canvas id="radarChart"></canvas></div></div>
                    <div class="card-stats-fut">
                        <div class="stat-col">
                            <div class="stat-item"><span class="stat-val" id="fut_match">0</span> TRẬN</div>
                            <div class="stat-item"><span class="stat-val" id="fut_winrate">0</span> % THẮNG</div>
                            <div class="stat-item"><span class="stat-val" id="fut_wl">0/0</span> T/B</div>
                        </div>
                        <div class="stat-col">
                            <div class="stat-item"><span class="stat-val" id="fut_point">0</span> ĐIỂM</div>
                            <div class="stat-item"><span class="stat-val" id="fut_water">0</span> NƯỚC</div>
                            <div class="stat-item"><span class="stat-val" id="fut_h">0</span> H.SỐ</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <button class="btn-success" onclick="downloadSingleCard()" style="margin-top: 15px; width: 100%; padding: 15px; font-size: 16px; border-radius: 12px; font-weight: bold;">📥 Tải Ảnh Thẻ Về Máy</button>
    </div>
</div>

<script>
    let matches = []; 
    let playerAvatars = {}; 
    let userRole = '';
    let currentDataString = ""; 
    let currentAvatarPlayer = ""; 
    let superAdminData = [];
    let currentFilteredMatches = [];
    let uniqueHistoryDates = [];
    let currentHistoryDateIndex = 0;
    let arrFiltered = [];

    function applyTheme(isDark) {
        const btns = document.querySelectorAll('.theme-btn');
        if (isDark) {
            document.body.classList.add('dark-mode');
            btns.forEach(btn => btn.innerHTML = '☀️ Sáng 3D');
        } else {
            document.body.classList.remove('dark-mode');
            btns.forEach(btn => btn.innerHTML = '🌙 Tối 3D');
        }
    }

    function getTodayVN() {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function toggleTheme() {
        const isDark = !document.body.classList.contains('dark-mode');
        localStorage.setItem('betminton_theme', isDark ? 'dark' : 'light');
        applyTheme(isDark);
    }

    window.onload = () => {
        const savedTheme = localStorage.getItem('betminton_theme') || 'dark';
        applyTheme(savedTheme === 'dark');

        // Gửi token đang lưu lên check
        sendAPI({ action: 'check_auth' }, (res) => {
            if (res.status === 'success') {
                handleLoginSuccess(res.role, res.group_name, res.expire_date);
            } else {
                document.getElementById('login_screen').style.display = 'block';
            }
        });
    };

    function sendAPI(payload, callback, silent = false) {
        if (!silent) document.getElementById('loading').style.display = 'flex';
        
        // TỰ ĐỘNG ĐÍNH KÈM STATELESS TOKEN VÀO MỌI API REQUEST GỬI LÊN VERCEL
        payload.token = localStorage.getItem('badminton_auth_token') || '';

        fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(res => res.json())
        .then(data => { if (!silent) document.getElementById('loading').style.display = 'none'; if(callback) callback(data); })
        .catch(err => { 
            if (!silent) {
                document.getElementById('loading').style.display = 'none';
                alert("Đã xảy ra lỗi hệ thống! Vui lòng kiểm tra lại kết nối Database hoặc mã nguồn.");
            }
            console.error("Fetch API Error", err);
        });
    }

    function login(type) {
        const u = document.getElementById('login_user').value;
        const p = document.getElementById('login_pass').value;
        if(!u) { alert("Vui lòng nhập Tài khoản nhóm!"); return; }

        if (type === 'guest') {
            sendAPI({ action: 'guest_login', username: u }, res => {
                if (res.status === 'success') handleLoginSuccess(res.role, res.group_name, res.expire_date, res.token);
                else alert(res.message);
            });
        } else {
            sendAPI({ action: 'login', username: u, password: p }, res => {
                if (res.status === 'success') handleLoginSuccess(res.role, res.group_name, res.expire_date, res.token);
                else alert(res.message);
            });
        }
    }

    function toggleAuthView(view) {
        document.getElementById('form_login_view').style.display = view === 'register' ? 'none' : 'block';
        document.getElementById('form_register_view').style.display = view === 'register' ? 'block' : 'none';
    }

    function registerNewGroup() {
        const n = document.getElementById('reg_group_name').value;
        const u = document.getElementById('reg_user').value;
        const p = document.getElementById('reg_pass').value;

        if(!n || !u || !p) { alert("Vui lòng điền đầy đủ thông tin!"); return; }
        if(u.includes(' ')) { alert("ID Đăng nhập không được có khoảng trắng!"); return; }

        sendAPI({ action: 'register_group', new_name: n, new_user: u, new_pass: p }, res => {
            if (res.status === 'success') {
                alert(res.message);
                toggleAuthView('login');
                document.getElementById('login_user').value = u;
                document.getElementById('login_pass').value = '';
            } else {
                alert(res.message);
            }
        });
    }

    function handleLoginSuccess(role, groupName, expireDate, token) {
        userRole = role;
        // Nếu có token mới từ server cấp về thì lưu đè vào localStorage
        if (token) localStorage.setItem('badminton_auth_token', token);
        document.getElementById('login_screen').style.display = 'none';
        
        if (role === 'superadmin') {
            document.getElementById('superadmin_screen').style.display = 'block';
            document.getElementById('new_group_expire').value = new Date(Date.now() + 30*24*60*60*1000).toISOString().split('T')[0];
            fetchAccounts(); 
        } else {
            document.getElementById('app_screen').style.display = 'block';
            let expHtml = expireDate ? `<span style="display: inline-block; font-size: 11px; font-weight: 900; background: var(--danger); color: white; padding: 4px 10px; border-radius: 12px; margin-left: 10px; vertical-align: middle; text-transform: none; letter-spacing: 0.5px;">HSD: ${expireDate}</span>` : '';
            document.getElementById('app_title').innerHTML = "🏆 " + groupName + expHtml;
            document.getElementById('user_role_badge').innerText = role === 'admin' ? "👤 Admin Sân" : "👁️ Xem Khách";
            
            document.querySelectorAll('.admin-only').forEach(el => {
                if(el.id === 'banner_upload_overlay') el.style.display = (role === 'admin') ? 'flex' : 'none';
                else if(el.id === 'btn_change_pass') el.style.display = (role === 'admin') ? 'inline-block' : 'none';
                else el.style.display = (role === 'admin') ? 'block' : 'none';
            });
            
            const today = getTodayVN();
            document.getElementById('date_from').value = today; 
            document.getElementById('date_to').value = today; 
            document.getElementById('match_date_input').value = today; 
            
            fetchDataFromServer(false); 
            setInterval(() => { fetchDataFromServer(true); }, 5000); 
        }
    }

	function initMonthlySelectors() {
        const monthSelect = document.getElementById('report_month');
        const yearSelect = document.getElementById('report_year');
        if(!monthSelect || !yearSelect) return;
        const now = new Date();

        const monthNames = ["Tháng 1", "Tháng 2", "Tháng 3", "Tháng 4", "Tháng 5", "Tháng 6", "Tháng 7", "Tháng 8", "Tháng 9", "Tháng 10", "Tháng 11", "Tháng 12"];
        monthSelect.innerHTML = ''; yearSelect.innerHTML = '';
        for (let i = 0; i < 12; i++) {
            let opt = document.createElement('option'); opt.value = i + 1; opt.innerHTML = monthNames[i];
            if (i === now.getMonth()) opt.selected = true;
            monthSelect.appendChild(opt);
        }
        for (let i = 2024; i <= 2030; i++) {
            let opt = document.createElement('option'); opt.value = i; opt.innerHTML = "Năm " + i;
            if (i === now.getFullYear()) opt.selected = true;
            yearSelect.appendChild(opt);
        }
    }
    initMonthlySelectors();

    function generateMonthlyReport() {
        const month = parseInt(document.getElementById('report_month').value);
        const year = parseInt(document.getElementById('report_year').value);
        const firstDay = `${year}-${String(month).padStart(2, '0')}-01`;
        const lastDay = `${year}-${String(month).padStart(2, '0')}-${new Date(year, month, 0).getDate()}`;
        
        document.getElementById('monthly_label').innerText = `Tháng ${month}/${year}`;
        document.getElementById('monthly_report_content').style.display = 'block';

        const m_matches = matches.filter(m => m.date >= firstDay && m.date <= lastDay);
        let players = {};

        m_matches.forEach(m => {
            const team1 = Array.isArray(m.team1) ? m.team1.filter(Boolean) : [];
            const team2 = Array.isArray(m.team2) ? m.team2.filter(Boolean) : [];
            const isDub = (team1.length === 2 && team2.length === 2);
            
            [...team1, ...team2].forEach(p => { 
                if(!players[p]) players[p] = { m: 0, w: 0, l: 0, p: 0, h: 0, water: 0, winPoints: 0, m_dub: 0, w_dub: 0 }; 
                if(isDub) players[p].m_dub++;
            });

            const wT = m.winner === 'team1' ? team1 : team2;
            const lT = m.winner === 'team1' ? team2 : team1;
            
            let t1_score = 0; let t2_score = 0;
            if (m.score && String(m.score).trim() !== "") {
                const pts = String(m.score).split('-');
                if(pts.length >= 2) {
                    t1_score = parseInt(pts[0].trim()) || 0; t2_score = parseInt(pts[1].trim()) || 0;
                }
            }
            team1.forEach(p => { players[p].h += (t1_score - t2_score); });
            team2.forEach(p => { players[p].h += (t2_score - t1_score); });

            wT.forEach(p => { 
                players[p].m++; players[p].w++; players[p].p += m.bet; players[p].water += (m.water || 0); players[p].winPoints += m.bet;
                if(isDub) players[p].w_dub++;
            });
            lT.forEach(p => { players[p].m++; players[p].l++; players[p].p -= m.bet; players[p].water -= (m.water || 0); });
        });

        const sorted = Object.keys(players).map(n => ({ n, ...players[n] })).sort((a, b) => b.p - a.p || b.h - a.h);
        const tbody = document.getElementById('monthly_dashboard_body');
        tbody.innerHTML = '';
        
        if(sorted.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="padding:20px; color:var(--text-muted);">Tháng này chưa có trận nào</td></tr>';
            document.getElementById('monthly_stats_container').innerHTML = '';
            return;
        }

        sorted.forEach((p, i) => {
            let rowCls = i === 0 ? 'row-top1' : (i === 1 ? 'row-top2' : (i === 2 ? 'row-top3' : ''));
            tbody.innerHTML += `
                <tr class="${rowCls}">
                    <td>${i+1}</td>
                    <td style="text-align:left;"><strong>${p.n}</strong></td>
                    <td>${p.m}</td>
                    <td>${p.w}</td>
                    <td>${p.l}</td>
                    <td>${p.water > 0 ? '+'+p.water : p.water}</td>
                    <td>${p.h > 0 ? '+'+p.h : p.h}</td>
                    <td style="font-size:16px;"><strong>${p.p > 0 ? '+'+p.p : p.p}</strong></td>
                </tr>`;
        });

        const statsContainer = document.getElementById('monthly_stats_container');
        statsContainer.innerHTML = '';
        const maxM = Math.max(...sorted.map(x => x.m), 1);
        const maxWinPoints = Math.max(...sorted.map(x => x.winPoints), 1);
        
        sorted.forEach(p => {
            const winRate = p.m > 0 ? Math.round((p.w / p.m) * 100) : 0;
            let avaHtml = playerAvatars[p.n] ? `<img src="${playerAvatars[p.n]}">` : String(p.n).charAt(0).toUpperCase();
            const safeName = p.n ? String(p.n).replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, ' ') : 'Unknown';
            
            statsContainer.innerHTML += `
                <div class="stat-card" style="cursor: pointer;" onclick="openFutCardModal('${safeName}', ${p.m}, ${p.w}, ${p.p}, ${p.h}, ${p.water}, ${p.m_dub}, ${p.w_dub}, ${p.winPoints}, ${maxM}, ${maxWinPoints})">
                    <div class="stat-avatar">${avaHtml}</div>
                    <div class="stat-info">
                        <h3 class="stat-name">${p.n}</h3>
                        <div class="stat-details-grid">
                            <div class="stat-box"><span class="stat-label">Số trận</span><span class="stat-val" style="color: #f1c40f;">${p.m}</span></div>
                            <div class="stat-box"><span class="stat-label">Tỉ lệ thắng</span><span class="stat-val" style="color: #e67e22;">${winRate}%</span></div>
                            <div class="stat-box"><span class="stat-label">Điểm Tháng</span><span class="stat-val" style="color: #27ae60;">${p.p > 0 ? '+'+p.p : p.p}</span></div>
                            <div class="stat-box"><span class="stat-label">Nước</span><span class="stat-val" style="color: #3498db;">${p.water}</span></div>
                            <div class="stat-box"><span class="stat-label">Hiệu số</span><span class="stat-val" style="color: #e74c3c;">${p.h > 0 ? '+'+p.h : p.h}</span></div>
                            <div class="stat-box"><span class="stat-label">T/B Trận</span><span class="stat-val" style="color: #9b59b6;">${p.w}/${p.l}</span></div>
                        </div>
                    </div>
                </div>`;
        });
    }

    function captureMonthlyReport() {
        document.getElementById('loading_text').innerText = "📸 Đang tạo báo cáo tháng..."; 
        document.getElementById('loading').style.display = 'flex';
        const area = document.getElementById('capture_monthly_area');
        const oldWidth = area.style.width; area.style.width = '600px';
        setTimeout(() => {
            html2canvas(area, { scale: 2, backgroundColor: "#12141c", useCORS: true }).then(canvas => {
                area.style.width = oldWidth;
                const base64Data = canvas.toDataURL('image/jpeg', 0.9);
                sendAPI({ action: 'save_capture', image: base64Data }, res => {
                    document.getElementById('loading').style.display = 'none';
                    if(res.status === 'success') {
                        let link = document.createElement('a'); link.href = res.url;
                        link.download = `Tong-Ket-${document.getElementById('monthly_label').innerText}.jpg`;
                        link.click();
                    }
                }, true);
            });
        }, 500);
    }

    function logout() {
        localStorage.removeItem('badminton_auth_token'); // Xóa token cục bộ khi thoát
        sendAPI({ action: 'logout' }, () => { window.location.reload(); });
    }

    function switchSATab(tabId) {
        document.querySelectorAll('#superadmin_screen .tab-pane').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('#superadmin_screen .tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById('tab_sa_' + tabId).classList.add('active');
        document.getElementById('btn_tab_sa_' + tabId).classList.add('active');
    }

    function createGroup() {
        const u = document.getElementById('new_group_user').value;
        const p = document.getElementById('new_group_pass').value;
        const n = document.getElementById('new_group_name').value;
        const e = document.getElementById('new_group_expire').value;
        if(!u || !p || !n || !e) { alert("Nhập đủ thông tin!"); return; }
        sendAPI({ action: 'create_group', new_user: u, new_pass: p, new_name: n, expire_date: e }, res => {
            if(res.status === 'success') { 
                alert("Đã tạo nhóm thành công!"); 
                document.getElementById('new_group_user').value = ''; document.getElementById('new_group_pass').value = ''; document.getElementById('new_group_name').value = '';
                switchSATab('list'); fetchAccounts(); 
            } else alert(res.message);
        });
    }

    function fetchAccounts() {
        sendAPI({ action: 'fetch_accounts' }, res => {
            if(res.status === 'success') {
                superAdminData = res.data;
                const tbody = document.getElementById('accounts_tbody'); tbody.innerHTML = '';
                const todayStr = getTodayVN();
                res.data.forEach(acc => {
                    const isExpired = acc.expire_date < todayStr;
                    const statusBadge = isExpired ? `<span class="badge badge-danger">Hết hạn</span>` : `<span class="badge badge-success">Còn hạn</span>`;
                    const dParts = acc.expire_date.split('-');
                    tbody.innerHTML += `<tr><td>${acc.id}</td><td><strong>${acc.group_name}</strong></td><td>${acc.username}</td><td>${acc.raw_password}</td><td>${dParts[2]}-${dParts[1]}-${dParts[0]}</td><td>${statusBadge}</td><td><button class="btn-outline" style="padding:8px 12px; font-size:12px; margin-bottom:5px;" onclick="openEditAccount(${acc.id})">✏️ Sửa</button> <button class="btn-danger" style="padding:8px 12px; font-size:12px;" onclick="deleteAccount(${acc.id})">🗑️ Xóa</button></td></tr>`;
                });
            }
        });
    }

    function openEditAccount(id) {
        const acc = superAdminData.find(a => a.id == id); if(!acc) return;
        document.getElementById('edit_acc_id').value = acc.id;
        document.getElementById('edit_acc_name').value = acc.group_name;
        document.getElementById('edit_acc_user').value = acc.username;
        document.getElementById('edit_acc_pass').value = acc.raw_password;
        document.getElementById('edit_acc_expire').value = acc.expire_date;
        document.getElementById('edit_account_box').style.display = 'block';
    }

    function saveEditAccount() {
        const id = document.getElementById('edit_acc_id').value;
        const name = document.getElementById('edit_acc_name').value;
        const user = document.getElementById('edit_acc_user').value;
        const pass = document.getElementById('edit_acc_pass').value;
        const exp = document.getElementById('edit_acc_expire').value;
        sendAPI({ action: 'edit_account', id: id, group_name: name, username: user, password: pass, expire_date: exp }, res => {
            if(res.status === 'success') { alert("Cập nhật thành công!"); document.getElementById('edit_account_box').style.display = 'none'; fetchAccounts(); }
        });
    }

    function deleteAccount(id) {
        if(confirm("CẢNH BÁO: Xóa nhóm này sẽ mất sạch toàn bộ lịch sử trận đấu! Bạn chắc chắn chứ?")) {
            sendAPI({ action: 'delete_account', id: id }, res => { if(res.status === 'success') fetchAccounts(); });
        }
    }

    function fetchDataFromServer(silent = true) {
        if(userRole === 'superadmin') return;
        sendAPI({ action: 'fetch' }, (res) => {
            if(res.status === 'success') {
                playerAvatars = res.avatars || {}; 
                const newDataString = JSON.stringify(res.data) + JSON.stringify(playerAvatars) + res.banner;
                if (currentDataString !== newDataString) { 
                    currentDataString = newDataString; matches = res.data; 
                    document.getElementById('main_banner_img').src = res.banner ? res.banner : "logo.png";
                    renderAll(); 
                }
            }
        }, silent);
    }

    function switchTab(tabId) {
        document.querySelectorAll('#app_screen .tab-pane').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('#app_screen .tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById('tab_' + tabId).classList.add('active');
        document.querySelector(`button[onclick="switchTab('${tabId}')"]`).classList.add('active');
    }

    function autoHighlightWinner() {
        const score = document.getElementById('match_score_input').value.trim();
        if(score.includes('-')) {
            const pts = score.split('-'); const p1 = parseInt(pts[0].trim()); const p2 = parseInt(pts[1].trim());
            if(!isNaN(p1) && !isNaN(p2) && p1 !== p2) {
                const radio = document.querySelector(`input[name="manual_winner"][value="${p1 > p2 ? 'team1' : 'team2'}"]`);
                if(radio) radio.checked = true;
            }
        }
        highlightManualWinner();
    }

    function highlightManualWinner() {
        const box1 = document.getElementById('box_team1'); const box2 = document.getElementById('box_team2');
        box1.classList.remove('active'); box2.classList.remove('active');
        const winnerRadio = document.querySelector('input[name="manual_winner"]:checked');
        if (winnerRadio && winnerRadio.value === 'team1') box1.classList.add('active');
        if (winnerRadio && winnerRadio.value === 'team2') box2.classList.add('active');
    }

    function formatName(name) { return name.trim().replace(/\s+/g, ' ').replace(/(^\w|\s\w)/g, m => m.toUpperCase()); }

    function saveMatch() {
        if(userRole !== 'admin') return;
        const t1 = [formatName(document.getElementById('t1_p1').value), formatName(document.getElementById('t1_p2').value)].filter(n => n);
        const t2 = [formatName(document.getElementById('t2_p1').value), formatName(document.getElementById('t2_p2').value)].filter(n => n);
        const bet = parseInt(document.getElementById('bet_amount').value) || 1;
        const water = parseInt(document.getElementById('water_amount').value) || 0;
        const matchDate = document.getElementById('match_date_input').value; 
        const isDon = document.querySelector('input[name="match_type"]:checked').value === 'don';
        const matchScore = document.getElementById('match_score_input').value.trim();

        if (!matchDate) { alert("Vui lòng chọn ngày thi đấu!"); return; }
        let winner = '';

        if (matchScore) {
            if (matchScore.includes(',')) { alert("❌ Lỗi: Vui lòng nhập tỉ số 1 set duy nhất (Ví dụ: 21-19). Không dùng dấu phẩy!"); return; }
            const scoreParts = matchScore.split('-');
            if(scoreParts.length !== 2) { alert("❌ Tỉ số không đúng định dạng (Ví dụ: 21-19)"); return; }
            const p1 = parseInt(scoreParts[0].trim()); const p2 = parseInt(scoreParts[1].trim());
            if(isNaN(p1) || isNaN(p2) || p1 === p2) { alert("❌ Tỉ số không hợp lệ!"); return; }
            winner = p1 > p2 ? 'team1' : 'team2';
        } else {
            const winnerRadio = document.querySelector('input[name="manual_winner"]:checked');
            if (!winnerRadio) { alert("Vui lòng CHỌN ĐỘI THẮNG!"); return; }
            winner = winnerRadio.value;
        }

        if (isDon ? (t1.length < 1 || t2.length < 1) : (t1.length < 2 || t2.length < 2)) { alert("Vui lòng nhập đủ thành viên!"); return; }
        const editId = document.getElementById('edit_match_id').value;
        const now = new Date();
        
        if (editId) {
            sendAPI({ action: 'edit', match: { id: editId, date: matchDate, team1: t1, team2: t2, bet: bet, water: water, score: matchScore, winner: winner } }, () => { cancelEdit(); fetchDataFromServer(false); }, false);
        } else {
            sendAPI({ action: 'add', match: { id: Date.now(), date: matchDate, time: now.toLocaleTimeString(), team1: t1, team2: t2, bet: bet, water: water, score: matchScore, winner: winner } }, (res) => {
                if(res.status === 'success') {
                    document.getElementById('t1_p1').value = ""; document.getElementById('t1_p2').value = ""; 
                    document.getElementById('t2_p1').value = ""; document.getElementById('t2_p2').value = ""; 
                    document.getElementById('match_score_input').value = ""; 
                    document.querySelectorAll('input[name="manual_winner"]').forEach(r => r.checked = false);
                    fetchDataFromServer(false); highlightManualWinner();
                } else {
                    alert(res.message); // Hiển thị chi tiết lỗi nếu sập
                }
            }, false);
        }
    }

    function editMatch(id) {
        if(userRole !== 'admin') return;
        const match = matches.find(m => m.id == id); if(!match) return;
        document.getElementById('match_date_input').value = match.date; 
        document.getElementById('t1_p1').value = match.team1[0] || ''; document.getElementById('t1_p2').value = match.team1[1] || '';
        document.getElementById('t2_p1').value = match.team2[0] || ''; document.getElementById('t2_p2').value = match.team2[1] || '';
        document.getElementById('bet_amount').value = match.bet;
        document.getElementById('water_amount').value = match.water || 0;
        document.getElementById('match_score_input').value = match.score || '';

        const radio = document.querySelector(`input[name="manual_winner"][value="${match.winner}"]`);
        if(radio) radio.checked = true;
        const isDon = match.team1.length === 1 && match.team2.length === 1;
        document.querySelector(`input[name="match_type"][value="${isDon ? 'don' : 'doi'}"]`).checked = true;
        toggleMatchType();

        document.getElementById('edit_match_id').value = match.id;
        document.getElementById('form_title').innerText = "Sửa Trận Đấu 3D"; 
        document.getElementById('btn_save').innerText = "Cập Nhật Dữ Liệu";
        document.getElementById('btn_cancel').style.display = "block"; 
        highlightManualWinner(); window.scrollTo(0, 0); 
    }

    function cancelEdit() {
        document.getElementById('edit_match_id').value = ""; document.getElementById('form_title').innerText = "Thêm Trận Đấu Mới";
        document.getElementById('match_date_input').value = getTodayVN(); document.getElementById('match_score_input').value = "";
        document.querySelector('input[name="match_type"][value="doi"]').checked = true; toggleMatchType();
        document.getElementById('btn_save').innerText = "LƯU TRẬN ĐẤU"; document.getElementById('btn_cancel').style.display = "none";
        document.getElementById('t1_p1').value = ""; document.getElementById('t1_p2').value = ""; document.getElementById('t2_p1').value = ""; document.getElementById('t2_p2').value = "";
        document.getElementById('bet_amount').value = "1"; document.getElementById('water_amount').value = "0";
        document.querySelectorAll('input[name="manual_winner"]').forEach(r => r.checked = false); highlightManualWinner();
    }

    function deleteMatch(id) { if(userRole === 'admin' && confirm("Xóa trận đấu này?")) sendAPI({ action: 'delete', id: id }, () => fetchDataFromServer(false), false); }
    function resetFilter() { const today = getTodayVN(); document.getElementById('date_from').value = today; document.getElementById('date_to').value = today; renderAll(); }
    
    function captureDashboard() {
        document.getElementById('loading_text').innerText = "📸 Đang xử lý ảnh..."; document.getElementById('loading').style.display = 'flex';
        const isDark = document.body.classList.contains('dark-mode'); if(!isDark) document.body.classList.add('dark-mode');
        const captureArea = document.getElementById('capture_area'); const tableResponsive = captureArea.querySelector('.table-responsive');
        const oldOverflow = tableResponsive ? tableResponsive.style.overflow : ''; const oldWidth = captureArea.style.width;
        if (tableResponsive) { tableResponsive.style.overflow = 'visible'; }
        captureArea.style.width = captureArea.scrollWidth + 'px';
        const captureScale = window.innerWidth <= 768 ? 1.5 : 2;

        setTimeout(() => {
            html2canvas(captureArea, { scale: captureScale, backgroundColor: "#12141c", useCORS: true, width: captureArea.scrollWidth, windowWidth: captureArea.scrollWidth }).then(canvas => {
                if (tableResponsive) { tableResponsive.style.overflow = oldOverflow; }
                captureArea.style.width = oldWidth; if(!isDark) document.body.classList.remove('dark-mode'); 
                const base64Data = canvas.toDataURL('image/jpeg', 0.8);
                sendAPI({ action: 'save_capture', image: base64Data }, res => {
                    document.getElementById('loading').style.display = 'none';
                    if(res.status === 'success') {
                        let link = document.createElement('a'); link.href = res.url; link.download = 'Bang-Phong-Than.jpg'; link.click();
                    }
                }, true);
            }).catch(err => { document.getElementById('loading').style.display = 'none'; });
        }, 500);
    }

    function captureHistory() {
        document.getElementById('loading_text').innerText = "📸 Đang xử lý ảnh lịch sử..."; document.getElementById('loading').style.display = 'flex';
        const isDark = document.body.classList.contains('dark-mode'); if(!isDark) document.body.classList.add('dark-mode');
        const captureArea = document.getElementById('capture_history_area'); 
        const actions = captureArea.querySelectorAll('.card-actions'); const pagination = document.getElementById('history_pagination');
        actions.forEach(el => el.style.display = 'none'); if (pagination) pagination.style.display = 'none';
        const oldWidth = captureArea.style.width; captureArea.style.width = captureArea.scrollWidth + 'px';
        const captureScale = window.innerWidth <= 768 ? 1.5 : 2;

        setTimeout(() => {
            html2canvas(captureArea, { scale: captureScale, backgroundColor: "#12141c", useCORS: true, width: captureArea.scrollWidth, windowWidth: captureArea.scrollWidth }).then(canvas => {
                captureArea.style.width = oldWidth; actions.forEach(el => el.style.display = ''); if (pagination) pagination.style.display = 'flex';
                if(!isDark) document.body.classList.remove('dark-mode'); 
                const base64Data = canvas.toDataURL('image/jpeg', 0.8);
                sendAPI({ action: 'save_capture', image: base64Data }, res => {
                    document.getElementById('loading').style.display = 'none';
                    if(res.status === 'success') {
                        let link = document.createElement('a'); link.href = res.url; link.download = 'Lich-Su-Tran-Dau.jpg'; link.click();
                    }
                }, true);
            }).catch(err => { document.getElementById('loading').style.display = 'none'; });
        }, 500);
    }

    function triggerAvatarUpload(playerName) { if (userRole !== 'admin') return; currentAvatarPlayer = playerName; document.getElementById('avatar_file_input').click(); }

    function handleAvatarSelection(event) {
        const file = event.target.files[0]; if (!file) return;
        document.getElementById('loading_text').innerText = "📸 Đang tối ưu ảnh..."; document.getElementById('loading').style.display = 'flex';
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas'); const MAX_SIZE = 800; let width = img.width; let height = img.height;
                if (width > height) { if (width > MAX_SIZE) { height *= MAX_SIZE / width; width = MAX_SIZE; } } else { if (height > MAX_SIZE) { width *= MAX_SIZE / height; height = MAX_SIZE; } }
                canvas.width = width; canvas.height = height; const ctx = canvas.getContext('2d');
                ctx.fillStyle = "#12141c"; ctx.fillRect(0, 0, width, height); ctx.drawImage(img, 0, 0, width, height);
                const base64String = canvas.toDataURL('image/webp', 0.85);
                sendAPI({ action: 'upload_avatar', name: currentAvatarPlayer, image: base64String }, () => { document.getElementById('avatar_file_input').value = ""; fetchDataFromServer(false); }, false);
            }
            img.src = e.target.result;
        }
        reader.readAsDataURL(file);
    }

    function handleBannerSelection(event) {
        const file = event.target.files[0]; if (!file) return;
        document.getElementById('loading_text').innerText = "📸 Đang nén Banner..."; document.getElementById('loading').style.display = 'flex';
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas'); const MAX_WIDTH = 1200; let width = img.width; let height = img.height;
                if (width > MAX_WIDTH) { height *= MAX_WIDTH / width; width = MAX_WIDTH; }
                canvas.width = width; canvas.height = height; const ctx = canvas.getContext('2d');
                ctx.fillStyle = "#12141c"; ctx.fillRect(0, 0, width, height); ctx.drawImage(img, 0, 0, width, height);
                const base64String = canvas.toDataURL('image/webp', 0.85);
                sendAPI({ action: 'upload_banner', image: base64String }, () => { document.getElementById('banner_file_input').value = ""; fetchDataFromServer(false); }, false);
            }
            img.src = e.target.result;
        }
        reader.readAsDataURL(file);
    }
    
    let allPlayerNames = [];
    function updatePlayerDatalist(playersArray) { allPlayerNames = playersArray.map(p => p.n); }

    document.addEventListener("DOMContentLoaded", function() {
        const inputIds = ['t1_p1', 't1_p2', 't2_p1', 't2_p2'];
        inputIds.forEach(id => {
            const input = document.getElementById(id); if(!input) return;
            const suggestionBox = document.createElement('div'); suggestionBox.className = 'custom-autocomplete-box';
            input.parentNode.appendChild(suggestionBox);
            input.addEventListener('input', function() { showSuggestions(this, suggestionBox); });
            input.addEventListener('focus', function() { showSuggestions(this, suggestionBox); });
            document.addEventListener('click', function(e) { if (e.target !== input && !suggestionBox.contains(e.target)) suggestionBox.style.display = 'none'; });
        });
    });

    function showSuggestions(input, box) {
        const val = input.value.toLowerCase().trim();
        const matches = allPlayerNames.filter(n => n.toLowerCase().includes(val));
        if (matches.length === 0) { box.style.display = 'none'; return; }
        box.innerHTML = '';
        matches.forEach(name => {
            const item = document.createElement('div'); item.className = 'custom-suggestion-item';
            let avaHtml = playerAvatars[name] ? `<img src="${playerAvatars[name]}" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:8px;object-fit:cover;">` : `👤 `;
            item.innerHTML = avaHtml + name;
            item.onclick = function() { input.value = name; box.style.display = 'none'; checkHeadToHead(); };
            box.appendChild(item);
        });
        box.style.display = 'block';
    }

    function renderAvatarHTML(playerName, isPodium = false) {
        if (!playerName || playerName === 'undefined') return '';
        if (playerAvatars[playerName]) return `<img src="${playerAvatars[playerName]}" alt="${playerName}">`;
        return String(playerName).charAt(0).toUpperCase();
    }

    function renderAll() {
        try {
            const d1 = document.getElementById('date_from').value; const d2 = document.getElementById('date_to').value;
            const formatDate = (dateStr) => { if(!dateStr) return ""; const parts = String(dateStr).split('-'); return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : dateStr; };
            const dateText = (d1 === d2) ? `(Ngày ${formatDate(d1)})` : `(${formatDate(d1)} - ${formatDate(d2)})`;
            
            if(document.getElementById('dashboard_date_display')) document.getElementById('dashboard_date_display').innerText = dateText; 
            if(document.getElementById('history_date_display')) document.getElementById('history_date_display').innerText = dateText; 

            const filtered = matches.filter(m => m.date && m.date >= d1 && m.date <= d2);
            let filteredPlayers = {};
            filtered.forEach(m => {
                const team1 = Array.isArray(m.team1) ? m.team1.filter(Boolean) : [];
                const team2 = Array.isArray(m.team2) ? m.team2.filter(Boolean) : [];
                [...team1, ...team2].forEach(p => { if(!filteredPlayers[p]) filteredPlayers[p] = { m: 0, w: 0, l: 0, p: 0, h: 0, winPoints: 0, losePoints: 0, waterBalance: 0 }; });
                const wT = m.winner === 'team1' ? team1 : team2; const lT = m.winner === 'team1' ? team2 : team1;
                const waterBet = m.water ? parseInt(m.water) : 0; 
                
                let t1_score = 0; let t2_score = 0;
                if (m.score && String(m.score).trim() !== "") {
                    const sets = String(m.score).split(',');
                    sets.forEach(s => {
                        const pts = s.split('-'); if(pts.length >= 2) { t1_score += parseInt(pts[0].trim()) || 0; t2_score += parseInt(pts[1].trim()) || 0; }
                    });
                }
                team1.forEach(p => { filteredPlayers[p].h += (t1_score - t2_score); });
                team2.forEach(p => { filteredPlayers[p].h += (t2_score - t1_score); });
                wT.forEach(p => { filteredPlayers[p].m++; filteredPlayers[p].w++; filteredPlayers[p].p += m.bet; filteredPlayers[p].winPoints += m.bet; filteredPlayers[p].waterBalance += waterBet; });
                lT.forEach(p => { filteredPlayers[p].m++; filteredPlayers[p].l++; filteredPlayers[p].p -= m.bet; filteredPlayers[p].losePoints += m.bet; filteredPlayers[p].waterBalance -= waterBet; });
            });
            
            arrFiltered = Object.keys(filteredPlayers).map(n => ({ n, ...filteredPlayers[n] })).sort((a, b) => b.p - a.p || b.h - a.h);
            const waterLosers = Object.keys(filteredPlayers).map(n => ({ n, waterBalance: filteredPlayers[n].waterBalance })).filter(p => p.waterBalance < 0).sort((a, b) => a.waterBalance - b.waterBalance).slice(0, 3).map(p => p.n);

            let allTimePlayers = {};
            matches.forEach(m => {
                const team1 = Array.isArray(m.team1) ? m.team1.filter(Boolean) : [];
                const team2 = Array.isArray(m.team2) ? m.team2.filter(Boolean) : [];
                const isDub = (team1.length === 2 && team2.length === 2);
                [...team1, ...team2].forEach(p => { 
                    if(!allTimePlayers[p]) allTimePlayers[p] = { m: 0, w: 0, l: 0, p: 0, h: 0, winPoints: 0, losePoints: 0, waterBalance: 0, m_dub: 0, w_dub: 0 };
                    if(isDub) allTimePlayers[p].m_dub++;
                });
                const wT = m.winner === 'team1' ? team1 : team2; const lT = m.winner === 'team1' ? team2 : team1;
                const waterBet = m.water ? parseInt(m.water) : 0;

                let t1_score = 0; let t2_score = 0;
                if (m.score && String(m.score).trim() !== "") {
                    const sets = String(m.score).split(',');
                    sets.forEach(s => {
                        const pts = s.split('-'); if(pts.length >= 2) { t1_score += parseInt(pts[0].trim()) || 0; t2_score += parseInt(pts[1].trim()) || 0; }
                    });
                }
                team1.forEach(p => { allTimePlayers[p].h += (t1_score - t2_score); });
                team2.forEach(p => { allTimePlayers[p].h += (t2_score - t1_score); });
                wT.forEach(p => { allTimePlayers[p].m++; allTimePlayers[p].w++; allTimePlayers[p].p += m.bet; allTimePlayers[p].winPoints += m.bet; allTimePlayers[p].waterBalance += waterBet; if(isDub) allTimePlayers[p].w_dub++; });
                lT.forEach(p => { allTimePlayers[p].m++; allTimePlayers[p].l++; allTimePlayers[p].p -= m.bet; allTimePlayers[p].losePoints += m.bet; allTimePlayers[p].waterBalance -= waterBet;});
            });
            
            const arrAllTime = Object.keys(allTimePlayers).map(n => ({ n, ...allTimePlayers[n] })).sort((a, b) => b.p - a.p || b.h - a.h);
            updatePlayerDatalist(arrAllTime);

            let playerStreaks = {};
            matches.forEach(m => {
                const wT = m.winner === 'team1' ? (Array.isArray(m.team1)?m.team1:[]) : (m.winner === 'team2' ? (Array.isArray(m.team2)?m.team2:[]) : []);
                const lT = m.winner === 'team1' ? (Array.isArray(m.team2)?m.team2:[]) : (m.winner === 'team2' ? (Array.isArray(m.team1)?m.team1:[]) : []);
                wT.filter(Boolean).forEach(p => {
                    if(!playerStreaks[p] || playerStreaks[p].type !== 'W') playerStreaks[p] = { type: 'W', count: 1 }; else playerStreaks[p].count++;
                });
                lT.filter(Boolean).forEach(p => {
                    if(!playerStreaks[p] || playerStreaks[p].type !== 'L') playerStreaks[p] = { type: 'L', count: 1 }; else playerStreaks[p].count++;
                });
            });

            const tbody = document.getElementById('dashboard_body'); 
            if(tbody) {
                tbody.innerHTML = '';
                if(arrFiltered.length===0) { tbody.innerHTML = '<tr><td colspan="8" style="color:var(--text-muted);italic;">Chưa có dữ liệu</td></tr>'; }
                else {
                    arrFiltered.forEach((p, i) => {
                        let rClass = i === 0 ? 'row-top1' : (i === 1 ? 'row-top2' : (i === 2 ? 'row-top3' : ''));
                        let isBot = false;
                        if (arrFiltered.length >= 4) {
                            if (i === arrFiltered.length - 1) { rClass = 'row-bot1'; isBot = true; }
                            else if (i === arrFiltered.length - 2) { rClass = 'row-bot2'; isBot = true; }
                            else if (i === arrFiltered.length - 3) { rClass = 'row-bot3'; isBot = true; }
                        }
                        let titleBadge = i === 0 && p.p > 0 ? `<span class="badge-title title-top1">👑 Kẻ Huỷ Diệt</span>` : ((i === 1 || i === 2) && p.p > 0 ? `<span class="badge-title title-top23">⚔️ Cao Thủ</span>` : '');
                        if (isBot && p.p < 0) titleBadge += ` <span class="badge-title title-bot">🐆 Vua Báo Thủ</span>`;
                        if (waterLosers.includes(p.n)) titleBadge += ` <span class="badge-title title-water">🥤 Thần Nước</span>`;
                        if (p.m >= 20) titleBadge += `<span class="badge-title" style="background:linear-gradient(145deg, #9b59b6, #8e44ad);">🤖 Thánh Bào Điểm</span>`;
                        
                        let streakHtml = '';
                        if (playerStreaks[p.n] && playerStreaks[p.n].count >= 3) {
                            streakHtml = playerStreaks[p.n].type === 'W' ? `<span class="streak-badge streak-win">🔥 x${playerStreaks[p.n].count}</span>` : `<span class="streak-badge streak-lose">🥶 x${playerStreaks[p.n].count}</span>`;
                        }
                        tbody.innerHTML += `<tr class="${rClass}"><td><strong>${i+1}</strong></td><td style="text-align:left;"><strong>${p.n}</strong> ${streakHtml} <br> ${titleBadge}</td><td>${p.m}</td><td>${p.w}</td><td>${p.l}</td><td>${p.waterBalance > 0 ? '+' + p.waterBalance : p.waterBalance}</td><td><strong>${p.h > 0 ? '+' + p.h : p.h}</strong></td><td style="font-size:18px;"><strong>${p.p>0?'+'+p.p:p.p}</strong></td></tr>`;
                    });
                }
            }

            currentFilteredMatches = [...filtered];
            uniqueHistoryDates = [...new Set(currentFilteredMatches.map(m => m.date))].sort((a, b) => new Date(b) - new Date(a));
            renderHistoryGrid();

            const renderPodium = (arr, type) => {
                if(!arr || arr.length === 0) return `<p style="text-align:center;width:100%;color:var(--text-muted);italic;">Chưa có dữ liệu</p>`;
                let prefix = type === 'bot' ? 'podium-bot' : (type === 'water' ? 'podium-water' : 'podium');
                const p1 = arr[0], p2 = arr[1] || null, p3 = arr[2] || null;
                let html = '';
                if (p2) html += `<div class="podium-box ${prefix}-2"><div class="podium-avatar">${renderAvatarHTML(p2.n, true)}</div><div class="podium-name">${p2.n}</div><div class="podium-score">${type === 'water' ? p2.waterBalance : (p2.p > 0 ? '+'+p2.p : p2.p)}</div></div>`;
                if (p1) html += `<div class="podium-box ${prefix}-1"><div class="podium-avatar">${renderAvatarHTML(p1.n, true)}</div><div class="podium-name">${p1.n}</div><div class="podium-score">${type === 'water' ? p1.waterBalance : (p1.p > 0 ? '+'+p1.p : p1.p)}</div></div>`;
                if (p3) html += `<div class="podium-box ${prefix}-3"><div class="podium-avatar">${renderAvatarHTML(p3.n, true)}</div><div class="podium-name">${p3.n}</div><div class="podium-score">${type === 'water' ? p3.waterBalance : (p3.p > 0 ? '+'+p3.p : p3.p)}</div></div>`;
                return html;
            };
            
            document.getElementById('top3_podium').innerHTML = renderPodium(arrFiltered.slice(0, 3), 'top');
            document.getElementById('bottom3_podium').innerHTML = renderPodium([...arrFiltered].reverse().slice(0, 3), 'bot');
            document.getElementById('water3_podium').innerHTML = renderPodium([...arrFiltered].filter(p => p.waterBalance < 0).sort((a, b) => a.waterBalance - b.waterBalance).slice(0, 3), 'water');

            const statsContainer = document.getElementById('stats_container'); 
            if(statsContainer) {
                statsContainer.innerHTML = '';
                const maxM = Math.max(...arrAllTime.map(x => x.m), 1);
                const maxWinPoints = Math.max(...arrAllTime.map(x => x.winPoints), 1);
                arrAllTime.forEach(p => {
                    const winRate = p.m > 0 ? Math.round((p.w / p.m) * 100) : 0;
                    let avaHtml = p.n ? (playerAvatars[p.n] ? `<img src="${playerAvatars[p.n]}">` : String(p.n).charAt(0).toUpperCase()) : '';
                    const safeName = String(p.n).replace(/'/g, "\\'");
                    statsContainer.innerHTML += `<div class="stat-card" style="cursor: pointer;" onclick="openFutCardModal('${safeName}', ${p.m}, ${p.w}, ${p.p}, ${p.h}, ${p.waterBalance}, ${p.m_dub || 0}, ${p.w_dub || 0}, ${p.winPoints}, ${maxM}, ${maxWinPoints})"><div class="stat-avatar" onclick="event.stopPropagation(); triggerAvatarUpload('${safeName}')">${avaHtml}${userRole==='admin'?'<div class="upload-overlay">📷 Đổi</div>':''}</div><div class="stat-info"><h3 class="stat-name">${p.n}</h3><div class="stat-details-grid"><div class="stat-box"><span class="stat-label">Số trận</span><span class="stat-val" style="color: #f1c40f;">${p.m}</span></div><div class="stat-box"><span class="stat-label">Tỉ lệ thắng</span><span class="stat-val" style="color: #e67e22;">${winRate}%</span></div><div class="stat-box"><span class="stat-label">Tổng Điểm</span><span class="stat-val" style="color: #27ae60;">${p.p}</span></div><div class="stat-box"><span class="stat-label">Nước</span><span class="stat-val" style="color: #3498db;">${p.waterBalance}</span></div><div class="stat-box"><span class="stat-label">Hiệu số</span><span class="stat-val" style="color: #e74c3c;">${p.h > 0 ? '+' + p.h : p.h}</span></div><div class="stat-box"><span class="stat-label">T/B Trận</span><span class="stat-val" style="color: #9b59b6;">${p.w}/${p.l}</span></div></div></div></div>`;
                });
            }
        } catch(err) { console.error(err); }
    }

    function changeHistoryDate(step) {
        currentHistoryDateIndex += step;
        if (currentHistoryDateIndex < 0) currentHistoryDateIndex = 0;
        if (currentHistoryDateIndex >= uniqueHistoryDates.length) currentHistoryDateIndex = uniqueHistoryDates.length - 1;
        renderHistoryGrid();
    }

    function renderHistoryGrid() {
        const grid = document.getElementById('history_grid'); grid.innerHTML = '';
        const paginationDiv = document.getElementById('history_pagination');
        if (!uniqueHistoryDates || uniqueHistoryDates.length === 0) { if(paginationDiv) paginationDiv.style.display = 'none'; return; }
        if(paginationDiv) paginationDiv.style.display = 'flex';
        
        document.getElementById('btn_prev_date').disabled = currentHistoryDateIndex >= uniqueHistoryDates.length - 1; 
        document.getElementById('btn_next_date').disabled = currentHistoryDateIndex <= 0; 
        
        const currentDateStr = uniqueHistoryDates[currentHistoryDateIndex];
        const dParts = currentDateStr.split('-');
        document.getElementById('current_history_date_label').innerText = `📅 Ngày ${dParts[2]}/${dParts[1]}/${dParts[0]}`;

        matches.filter(m => m.date === currentDateStr).forEach((m) => {
            const w1 = m.winner === 'team1'; const shortTime = m.time ? String(m.time).substring(0, 5) : '--:--';
            const getAva = (name) => name ? (playerAvatars[name] ? `<img src="${playerAvatars[name]}">` : String(name).charAt(0).toUpperCase()) : '';
            const t1_p1 = m.team1[0] || 'Player', t1_p2 = m.team1[1] || '';
            const t2_p1 = m.team2[0] || 'Player', t2_p2 = m.team2[1] || '';

            grid.innerHTML += `<div class="match-card-v2"><div class="match-info-header">🕒 ${shortTime}</div><div class="match-battle-area"><div class="team-col ${w1?'winner':'loser'}">${w1?'<div class="stamp win">WIN</div>':'<div class="stamp lose">LOSE</div>'}<div class="avatar-split-container ${!t1_p2?'single-mode':''}"><div class="ava-p1">${getAva(t1_p1)}</div>${t1_p2?`<div class="diagonal-line"></div><div class="ava-p2">${getAva(t1_p2)}</div>`:''}</div><div class="team-names-v2">${t1_p1} <br> ${t1_p2}</div></div><div class="vs-col"><div class="vs-fire-text">VS</div><div class="bet-badge">Cược: ${m.bet} Đ</div>${m.water>0?`<div class="bet-badge" style="background:var(--secondary); color:#fff;">🥤 Nước: ${m.water}</div>`:''}${m.score?`<div class="bet-badge score-badge">🎯 ${m.score}</div>`:''}</div><div class="team-col ${!w1?'winner':'loser'}">${!w1?'<div class="stamp win">WIN</div>':'<div class="stamp lose">LOSE</div>'}<div class="avatar-split-container ${!t2_p2?'single-mode':''}"><div class="ava-p1">${getAva(t2_p1)}</div>${t2_p2?`<div class="diagonal-line"></div><div class="ava-p2">${getAva(t2_p2)}</div>`:''}</div><div class="team-names-v2">${t2_p1} <br> ${t2_p2}</div></div></div>${userRole==='admin'?`<div class="card-actions"><button class="btn-outline" onclick="editMatch(${m.id})">✏️ Sửa</button> <button class="btn-danger" onclick="deleteMatch(${m.id})">🗑️ Xóa</button></div>`:''}</div>`;
        });
    }

    let myRadarChart; let currentCardName = "";
    function openFutCardModal(name, m, w, p, h, water, m_dub, w_dub, winPoints, maxM, maxWin) {
        currentCardName = name; const winRate = m > 0 ? Math.round((w / m) * 100) : 0;
        const act = m > 0 ? Math.round((m / maxM) * 100) : 0; const bet = Math.round((winPoints / maxWin) * 100);
        let gapScore = Math.max(0, Math.min(100, Math.round(50 + ((m > 0 ? h/m : 0) * 5))));
        const dubRate = m_dub > 0 ? Math.round((w_dub / m_dub) * 100) : 0;
        let wtrScore = Math.max(0, Math.min(100, Math.round(50 + (water * 2))));
        const ovr = Math.round((winRate + act + bet + gapScore + dubRate + wtrScore) / 6);

        let theme = "card-silver"; let pos = "TÂN BINH";
        if(ovr >= 80 || p > 20) { theme = "card-vip"; pos = "GÁNH TEAM"; }
        else if(ovr >= 65 || p > 5) { theme = "card-gold"; pos = "SÁT THỦ"; }
        else if(water < -10) { theme = "card-red"; pos = "BÁO THỦ"; }

        document.getElementById('fut_name').innerText = name; document.getElementById('fut_ovr').innerText = ovr; document.getElementById('fut_pos').innerText = pos;
        document.getElementById('player_fut_card').className = "fut-card " + theme;
        document.getElementById('fut_match').innerText = m; document.getElementById('fut_winrate').innerText = winRate; document.getElementById('fut_wl').innerText = `${w}/${m-w}`;
        document.getElementById('fut_point').innerText = p > 0 ? '+'+p : p; document.getElementById('fut_water').innerText = water > 0 ? '+'+water : water; document.getElementById('fut_h').innerText = h > 0 ? '+'+h : h;
        document.getElementById('fut_avatar').innerHTML = playerAvatars[name] ? `<img src="${playerAvatars[name]}">` : `<div style="font-size:80px; font-weight:900; color:rgba(255,255,255,0.3);">${name.charAt(0).toUpperCase()}</div>`;

        const ctx = document.getElementById('radarChart').getContext('2d'); if(myRadarChart) myRadarChart.destroy();
        let borderCls = theme === 'card-vip' ? "#00f2fe" : (theme === 'card-gold' ? "#f1c40f" : (theme === 'card-red' ? "#ff4757" : "#bdc3c7"));
        
        myRadarChart = new Chart(ctx, {
            type: 'doughnut', data: { datasets: [{ data: [ovr, 100 - ovr], backgroundColor: [borderCls, 'rgba(255,255,255,0.1)'], borderWidth: 0, borderRadius: [10, 0], cutout: '75%' }] },
            options: { animation: false, responsive: true, maintainAspectRatio: false, plugins: { legend: false, tooltip: false } },
            plugins: [{ id: 'textCenter', beforeDraw: function(chart) { var w = chart.width, height = chart.height, c = chart.ctx; c.restore(); c.font = "900 2.5em sans-serif"; c.textBaseline = "middle"; c.fillStyle = "#fff"; c.fillText(ovr.toString(), Math.round((w - c.measureText(ovr.toString()).width) / 2), height / 2); c.save(); } }]
        });
        document.getElementById('stats_modal').style.display = "flex";
    }

    function closeStatsModal() { document.getElementById('stats_modal').style.display = "none"; }
    function downloadSingleCard() {
        const area = document.getElementById('capture_card_area'); document.getElementById('loading_text').innerText = "📸 Đang tạo ảnh thẻ..."; document.getElementById('loading').style.display = 'flex';
        setTimeout(() => {
            html2canvas(area, { scale: 3, backgroundColor: null, useCORS: true }).then(canvas => {
                let link = document.createElement('a'); link.download = `The-Bai-${currentCardName}.png`; link.href = canvas.toDataURL('image/png'); link.click();
                document.getElementById('loading').style.display = 'none';
            });
        }, 500);
    }

    window.onclick = (e) => { if (e.target == document.getElementById('stats_modal')) closeStatsModal(); }
    function toggleMatchType() {
        const isDon = document.querySelector('input[name="match_type"]:checked').value === 'don';
        document.getElementById('t1_p2').style.display = isDon ? 'none' : 'block'; document.getElementById('t2_p2').style.display = isDon ? 'none' : 'block';
        if (isDon) { document.getElementById('t1_p2').value = ''; document.getElementById('t2_p2').value = ''; }
    }

    function checkHeadToHead() {
        const t1 = [document.getElementById('t1_p1').value.trim(), document.getElementById('t1_p2').value.trim()].filter(Boolean).sort().join(' & ');
        const t2 = [document.getElementById('t2_p1').value.trim(), document.getElementById('t2_p2').value.trim()].filter(Boolean).sort().join(' & ');
        const h2hBox = document.getElementById('h2h_result'); if(!h2hBox) return; if(!t1 || !t2) { h2hBox.style.display = 'none'; return; }
        
        let t1Wins = 0, t2Wins = 0;
        matches.forEach(m => {
            const mt1 = (m.team1 || []).filter(Boolean).sort().join(' & '); const mt2 = (m.team2 || []).filter(Boolean).sort().join(' & ');
            if ((mt1 === t1 && mt2 === t2) || (mt1 === t2 && mt2 === t1)) {
                if (m.winner === 'team1') { mt1 === t1 ? t1Wins++ : t2Wins++; } else { mt1 === t1 ? t2Wins++ : t1Wins++; }
            }
        });
        h2hBox.style.display = 'block'; h2hBox.innerHTML = (t1Wins > 0 || t2Wins > 0) ? `⚔️ H2H: [${t1Wins}] - [${t2Wins}]` : `⚔️ Lần đầu chạm trán`;
    }

    function autoMatchmake() {
        const inputs = [document.getElementById('t1_p1'), document.getElementById('t1_p2'), document.getElementById('t2_p1'), document.getElementById('t2_p2')];
        const players = inputs.map(i => i.value.trim()).filter(Boolean); if(players.length < 4) { alert("❌ Nhập đủ tên 4 người!"); return; }
        const getScore = (n) => { const p = arrFiltered.find(x => x.n === n); return p ? p.p : 0; };
        const pData = players.map(name => ({ name, score: getScore(name) }));
        const combos = [
            { t1: [pData[0], pData[1]], t2: [pData[2], pData[3]] }, { t1: [pData[0], pData[2]], t2: [pData[1], pData[3]] }, { t1: [pData[0], pData[3]], t2: [pData[1], pData[2]] }
        ];
        let bestCombo = null; let minDiff = Infinity;
        combos.forEach(c => { const diff = Math.abs((c.t1[0].score + c.t1[1].score) - (c.t2[0].score + c.t2[1].score)); if (diff < minDiff) { minDiff = diff; bestCombo = c; } });
        if(bestCombo) {
            inputs[0].value = bestCombo.t1[0].name; inputs[1].value = bestCombo.t1[1].name; inputs[2].value = bestCombo.t2[0].name; inputs[3].value = bestCombo.t2[1].name;
            checkHeadToHead();
        }
    }

    function submitChangePassword() {
        const p1 = document.getElementById('cp_new_pass').value, p2 = document.getElementById('cp_confirm_pass').value;
        if(!p1 || !p2) { alert("Nhập đủ mật khẩu!"); return; } if(p1 !== p2) { alert("❌ Mật khẩu nhập lại không khớp!"); return; }
        sendAPI({ action: 'change_password', new_password: p1 }, res => {
            if(res.status === 'success') { alert("✅ Đổi thành công!"); document.getElementById('change_pass_modal').style.display = 'none'; }
        });
    }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition; let isRecording = false;
    if (SpeechRecognition) {
        recognition = new SpeechRecognition(); recognition.lang = 'vi-VN'; recognition.continuous = true;
        recognition.onresult = (e) => {
            let trans = ""; for (let i = e.resultIndex; i < e.results.length; ++i) trans += e.results[i][0].transcript;
            trans = trans.toLowerCase(); if(trans.trim() !== "") parseVoiceToMatch(trans);
        };
    }
    const btnVoice = document.getElementById('btn_voice_input');
    const startHold = (e) => {
        e.preventDefault(); if (!recognition) return;
        if (!isRecording) {
            try { recognition.start(); isRecording = true; btnVoice.innerHTML = '🎙️ Đang nghe...'; btnVoice.style.background = 'var(--danger)'; } catch(err) {}
        }
    };
    const stopHold = (e) => { e.preventDefault(); if (isRecording) { recognition.stop(); isRecording = false; btnVoice.innerHTML = '🎤 Nhấn Giữ Để Nói'; btnVoice.style.background = ''; } };
    if (btnVoice) {
        btnVoice.addEventListener('mousedown', startHold); btnVoice.addEventListener('mouseup', stopHold); btnVoice.addEventListener('mouseleave', stopHold);
        btnVoice.addEventListener('touchstart', startHold, {passive: false}); btnVoice.addEventListener('touchend', stopHold, {passive: false});
    }

    function parseVoiceToMatch(text) {
        let betMatch = text.match(/(độ|cược|điểm)\s*(\d+)/i), waterMatch = text.match(/nước\s*(\d+)/i);
        document.getElementById('bet_amount').value = betMatch ? betMatch[2] : "1";
        document.getElementById('water_amount').value = waterMatch ? waterMatch[2] : "0";
        let teams = [];
        for (let s of [' đấu với ', ' đánh với ', ' đấu ', ' gặp ', ' vs ']) { if (text.includes(s)) { teams = text.split(s); break; } }
        if (teams.length < 2) return;
        let parseP = (txt) => txt.split(/\s+và\s+|\s+với\s+|,/).map(p => formatName(p.trim())).filter(p => p);
        let t1 = parseP(teams[0]), t2 = parseP(teams[1]);
        document.getElementById('t1_p1').value = t1[0] || ''; document.getElementById('t1_p2').value = t1[1] || '';
        document.getElementById('t2_p1').value = t2[0] || ''; document.getElementById('t2_p2').value = t2[1] || '';
        document.querySelector('input[name="manual_winner"][value="team1"]').checked = true; highlightManualWinner(); checkHeadToHead();
    }
</script>

<div id="change_pass_modal" class="stats-modal">
    <div class="modal-content-wrapper" style="width: 350px;">
        <span class="close-modal" onclick="document.getElementById('change_pass_modal').style.display='none'">&times;</span>
        <h3 style="color: var(--primary); margin-top:0; margin-bottom: 20px;">🔑 Đổi Mật Khẩu</h3>
        <input type="password" id="cp_new_pass" placeholder="Nhập mật khẩu mới">
        <input type="password" id="cp_confirm_pass" placeholder="Nhập lại mật khẩu mới">
        <button class="btn-success" onclick="submitChangePassword()" style="width: 100%; margin-top: 15px; padding: 15px;">💾 Cập Nhật Mật Khẩu</button>
    </div>
</div>

</body>
</html>