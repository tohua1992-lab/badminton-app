let matches = []; 
    let playerAvatars = {}; 
    let userRole = '';
    let currentDataString = ""; 
    let currentAvatarPlayer = ""; 
    let superAdminData = [];

    // --- BIẾN MỚI CHO PHÂN TRANG LỊCH SỬ ---
    let currentFilteredMatches = [];
    let uniqueHistoryDates = [];
    let currentHistoryDateIndex = 0;

    // ==========================================
    // LOGIC GIAO DIỆN SÁNG / TỐI (THEME)
    // ==========================================
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

    // Hàm lấy ngày chuẩn theo múi giờ địa phương (Việt Nam)
    function getTodayVN() {
        const d = new Date();
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function toggleTheme() {
        const isDark = !document.body.classList.contains('dark-mode');
        localStorage.setItem('betminton_theme', isDark ? 'dark' : 'light');
        applyTheme(isDark);
    }

    // CHẠY KHI MỞ TRANG
    window.onload = () => {
        // Mặc định ép luôn vào Dark Mode vì giao diện 3D Premium Dark quá xịn
        const savedTheme = localStorage.getItem('betminton_theme') || 'dark';
        if (savedTheme === 'dark') applyTheme(true);
        else applyTheme(false);

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
        fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(res => res.json())
        .then(data => { if (!silent) document.getElementById('loading').style.display = 'none'; if(callback) callback(data); })
        .catch(err => { 
            if (!silent) {
                document.getElementById('loading').style.display = 'none';
                alert("Đã xảy ra lỗi hệ thống! Vui lòng tải lại trang.");
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
                if (res.status === 'success') handleLoginSuccess(res.role, res.group_name, res.expire_date);
                else alert(res.message);
            });
        } else {
            sendAPI({ action: 'login', username: u, password: p }, res => {
                if (res.status === 'success') handleLoginSuccess(res.role, res.group_name, res.expire_date);
                else alert(res.message);
            });
        }
    }

    // --- CHUYỂN ĐỔI UI ĐĂNG NHẬP / ĐĂNG KÝ ---
    function toggleAuthView(view) {
        if (view === 'register') {
            document.getElementById('form_login_view').style.display = 'none';
            document.getElementById('form_register_view').style.display = 'block';
        } else {
            document.getElementById('form_login_view').style.display = 'block';
            document.getElementById('form_register_view').style.display = 'none';
        }
    }

    // --- GỬI YÊU CẦU ĐĂNG KÝ ---
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

    function handleLoginSuccess(role, groupName, expireDate) {
        userRole = role;
        document.getElementById('login_screen').style.display = 'none';
        
        if (role === 'superadmin') {
            document.getElementById('superadmin_screen').style.display = 'block';
            const futureDate = new Date(Date.now() + 30*24*60*60*1000);
            document.getElementById('new_group_expire').value = futureDate.toISOString().split('T')[0];
            fetchAccounts(); 
        } else {
            document.getElementById('app_screen').style.display = 'block';
            
            // GẮN CHỮ NGÀY HẾT HẠN VÀO TIÊU ĐỀ
            let expHtml = expireDate ? `<span style="display: inline-block; font-size: 11px; font-weight: 900; background: var(--danger); color: white; padding: 4px 10px; border-radius: 12px; margin-left: 10px; vertical-align: middle; box-shadow: var(--shadow-outer); text-transform: none; letter-spacing: 0.5px;">HSD: ${expireDate}</span>` : '';
            document.getElementById('app_title').innerHTML = "🏆 " + groupName + expHtml;
            
            document.getElementById('user_role_badge').innerText = role === 'admin' ? "👤 Admin Sân" : "👁️ Xem Khách";
            
            document.querySelectorAll('.admin-only').forEach(el => {
                if(el.id === 'banner_upload_overlay') el.style.display = (role === 'admin') ? 'flex' : 'none';
                else if(el.id === 'btn_change_pass') el.style.display = (role === 'admin') ? 'inline-block' : 'none';
                else el.style.display = (role === 'admin') ? 'block' : 'none';
            });
            
            const today = getTodayVN()
            document.getElementById('date_from').value = today; document.getElementById('date_to').value = today; document.getElementById('match_date_input').value = today; 
            
            fetchDataFromServer(false); 
            setInterval(() => { fetchDataFromServer(true); }, 5000); 
        }
    }

    function logout() {
        sendAPI({ action: 'logout' }, () => { window.location.reload(); });
    }

    // ==========================================
    // JS CHO SUPER ADMIN
    // ==========================================
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
                const tbody = document.getElementById('accounts_tbody');
                tbody.innerHTML = '';
                const todayStr = getTodayVN();

                res.data.forEach(acc => {
                    const isExpired = acc.expire_date < todayStr;
                    const statusBadge = isExpired ? `<span class="badge badge-danger">Hết hạn</span>` : `<span class="badge badge-success">Còn hạn</span>`;
                    
                    const dParts = acc.expire_date.split('-');
                    const niceDate = `${dParts[2]}-${dParts[1]}-${dParts[0]}`;

                    tbody.innerHTML += `
                        <tr>
                            <td>${acc.id}</td>
                            <td><strong>${acc.group_name}</strong></td>
                            <td>${acc.username}</td>
                            <td>${acc.raw_password}</td>
                            <td>${niceDate}</td>
                            <td>${statusBadge}</td>
                            <td>
                                <button class="btn-outline" style="padding:8px 12px; font-size:12px; margin-bottom:5px;" onclick="openEditAccount(${acc.id})">✏️ Sửa</button>
                                <button class="btn-danger" style="padding:8px 12px; font-size:12px;" onclick="deleteAccount(${acc.id})">🗑️ Xóa</button>
                            </td>
                        </tr>
                    `;
                });
            }
        });
    }

    function openEditAccount(id) {
        const acc = superAdminData.find(a => a.id == id);
        if(!acc) return;
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
            if(res.status === 'success') {
                alert("Cập nhật tài khoản thành công!");
                document.getElementById('edit_account_box').style.display = 'none';
                fetchAccounts();
            }
        });
    }

    function deleteAccount(id) {
        if(confirm("CẢNH BÁO: Bạn có chắc muốn xóa nhóm này? TOÀN BỘ dữ liệu trận đấu và điểm số của nhóm sẽ bị XÓA SẠCH!")) {
            sendAPI({ action: 'delete_account', id: id }, res => {
                if(res.status === 'success') fetchAccounts();
            });
        }
    }

    // ==========================================
    // JS CHO NGƯỜI THUÊ (APP CHÍNH)
    // ==========================================
    function fetchDataFromServer(silent = true) {
        if(userRole === 'superadmin') return;
        sendAPI({ action: 'fetch' }, (res) => {
            if(res.status === 'expired') {
                alert("Phiên đăng nhập đã hết hạn. Vui lòng liên hệ Admin!"); logout(); return;
            }
            if(res.status === 'success') {
                playerAvatars = res.avatars || {}; 
                const newDataString = JSON.stringify(res.data) + JSON.stringify(playerAvatars) + res.banner;
                if (currentDataString !== newDataString) { 
                    currentDataString = newDataString; 
                    matches = res.data; 
                    
                    if (res.banner) { document.getElementById('main_banner_img').src = res.banner; } 
                    else { document.getElementById('main_banner_img').src = "logo.png"; }
                    
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
        const pts = score.split('-');
        const p1 = parseInt(pts[0].trim());
        const p2 = parseInt(pts[1].trim());
        if(!isNaN(p1) && !isNaN(p2) && p1 !== p2) {
            const radioValue = p1 > p2 ? 'team1' : 'team2';
            const radio = document.querySelector(`input[name="manual_winner"][value="${radioValue}"]`);
            if(radio) radio.checked = true;
        }
    }
    highlightManualWinner();
}

function highlightManualWinner() {
    const box1 = document.getElementById('box_team1');
    const box2 = document.getElementById('box_team2');
    box1.classList.remove('active');
    box2.classList.remove('active');
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
        if (matchScore.includes(',')) {
            alert("❌ Lỗi: Vui lòng chỉ nhập tỉ số của 1 set đấu duy nhất (Ví dụ: 21-19). Không dùng dấu phẩy!");
            return;
        }
        const scoreParts = matchScore.split('-');
        if(scoreParts.length !== 2) { 
            alert("❌ Tỉ số không đúng định dạng (Ví dụ: 21-19)"); 
            return; 
        }
        const p1 = parseInt(scoreParts[0].trim());
        const p2 = parseInt(scoreParts[1].trim());
        
        if(isNaN(p1) || isNaN(p2) || p1 === p2) { 
            alert("❌ Tỉ số không hợp lệ hoặc hòa (Không có hòa)!"); 
            return; 
        }
        winner = p1 > p2 ? 'team1' : 'team2';
    } else {
        const winnerRadio = document.querySelector('input[name="manual_winner"]:checked');
        if (!winnerRadio) {
            alert("Vui lòng CHỌN ĐỘI THẮNG (nếu không nhập tỉ số)!");
            return;
        }
        winner = winnerRadio.value;
    }

    // Kiểm tra số lượng tay vợt
    if (isDon) {
        if (t1.length < 1 || t2.length < 1) { alert("Vui lòng nhập đủ 2 tên tay vợt thi đấu Đơn!"); return; }
    } else {
        if (t1.length < 2 || t2.length < 2) { alert("Nhập đủ 4 tên nhé!"); return; }
    }

    const editId = document.getElementById('edit_match_id').value;
    const now = new Date();
    
    if (editId) {
        sendAPI({ action: 'edit', match: { id: editId, date: matchDate, team1: t1, team2: t2, bet: bet, water: water, score: matchScore, winner: winner } }, () => { cancelEdit(); fetchDataFromServer(false); }, false);
    } else {
        sendAPI({ action: 'add', match: { id: Date.now(), date: matchDate, time: now.toLocaleTimeString(), team1: t1, team2: t2, bet: bet, water: water, score: matchScore, winner: winner } }, () => {
            document.getElementById('t1_p1').value = ""; 
            document.getElementById('t1_p2').value = ""; 
            document.getElementById('t2_p1').value = ""; 
            document.getElementById('t2_p2').value = ""; 
            document.getElementById('match_score_input').value = ""; 
            document.querySelectorAll('input[name="manual_winner"]').forEach(r => r.checked = false);
            fetchDataFromServer(false); 
            highlightManualWinner();
        }, false);
    }
}

    function editMatch(id) {
    if(userRole !== 'admin') return;
    const match = matches.find(m => m.id == id);
    if(!match) return;
    document.getElementById('match_date_input').value = match.date; 
    document.getElementById('t1_p1').value = match.team1[0] || ''; 
    document.getElementById('t1_p2').value = match.team1[1] || '';
    document.getElementById('t2_p1').value = match.team2[0] || ''; 
    document.getElementById('t2_p2').value = match.team2[1] || '';
    document.getElementById('bet_amount').value = match.bet;
    document.getElementById('water_amount').value = match.water || 0;
    document.getElementById('match_score_input').value = match.score || '';

    const radioToSelect = document.querySelector(`input[name="manual_winner"][value="${match.winner}"]`);
    if(radioToSelect) radioToSelect.checked = true;

    const isDon = match.team1.length === 1 && match.team2.length === 1;
    document.querySelector(`input[name="match_type"][value="${isDon ? 'don' : 'doi'}"]`).checked = true;
    toggleMatchType();

    document.getElementById('edit_match_id').value = match.id;
    document.getElementById('form_title').innerText = "Sửa Trận Đấu 3D"; 
    document.getElementById('btn_save').innerText = "Cập Nhật Dữ Liệu";
    document.getElementById('btn_cancel').style.display = "block"; 
    highlightManualWinner(); 
    window.scrollTo(0, 0); 
}

function cancelEdit() {
    document.getElementById('edit_match_id').value = ""; 
    document.getElementById('form_title').innerText = "Thêm Trận Đấu Mới";
    document.getElementById('match_date_input').value = getTodayVN();
    document.getElementById('match_score_input').value = "";

    document.querySelector('input[name="match_type"][value="doi"]').checked = true;
    toggleMatchType();

    document.getElementById('btn_save').innerText = "LƯU TRẬN ĐẤU"; 
    document.getElementById('btn_cancel').style.display = "none";
    document.getElementById('t1_p1').value = ""; 
    document.getElementById('t1_p2').value = ""; 
    document.getElementById('t2_p1').value = ""; 
    document.getElementById('t2_p2').value = "";
    document.getElementById('bet_amount').value = "1";
    document.getElementById('water_amount').value = "0";
    
    document.querySelectorAll('input[name="manual_winner"]').forEach(r => r.checked = false);
    highlightManualWinner();
}

    function deleteMatch(id) { if(userRole === 'admin' && confirm("Xóa trận đấu này?")) sendAPI({ action: 'delete', id: id }, () => fetchDataFromServer(false), false); }
    
    function resetFilter() { const today = getTodayVN(); document.getElementById('date_from').value = today; document.getElementById('date_to').value = today; renderAll(); }
    
    function captureDashboard() {
        document.getElementById('loading_text').innerText = "📸 Đang xử lý ảnh..."; 
        document.getElementById('loading').style.display = 'flex';
        
        const isDark = document.body.classList.contains('dark-mode');
        if(!isDark) document.body.classList.add('dark-mode');

        const captureArea = document.getElementById('capture_area'); 
        const tableResponsive = captureArea.querySelector('.table-responsive');
        
        // Lưu lại style cũ
        const oldOverflow = tableResponsive ? tableResponsive.style.overflow : ''; 
        const oldOverflowX = tableResponsive ? tableResponsive.style.overflowX : ''; 
        const oldWidth = captureArea.style.width;
        
        // Ép width ra full để html2canvas chụp không bị cắt xén
        if (tableResponsive) {
            tableResponsive.style.overflow = 'visible';
            tableResponsive.style.overflowX = 'visible';
        }
        captureArea.style.width = captureArea.scrollWidth + 'px';

        // Dùng scale 1.5 cho Mobile để nét hơn nhưng không gây tràn RAM
        const captureScale = window.innerWidth <= 768 ? 1.5 : 2;

        setTimeout(() => {
            html2canvas(captureArea, { 
                scale: captureScale, 
                backgroundColor: "#12141c", 
                useCORS: true,
                logging: false,
                width: captureArea.scrollWidth,
                windowWidth: captureArea.scrollWidth
            }).then(canvas => {
                // Trả lại style cũ ngay sau khi chụp xong
                if (tableResponsive) {
                    tableResponsive.style.overflow = oldOverflow;
                    tableResponsive.style.overflowX = oldOverflowX;
                }
                captureArea.style.width = oldWidth;
                if(!isDark) document.body.classList.remove('dark-mode'); 
                
                // Nén JPEG ở mức 0.8 để gửi lên PHP nhẹ nhàng
                const base64Data = canvas.toDataURL('image/jpeg', 0.8);

                // Gửi ảnh lên server PHP
                sendAPI({ action: 'save_capture', image: base64Data }, res => {
                    document.getElementById('loading').style.display = 'none';
                    if(res.status === 'success') {
                        let link = document.createElement('a');
                        link.href = res.url;
                        link.download = 'Bang-Phong-Than.jpg';
                        link.click();
                    } else {
                        alert("❌ Lỗi lưu ảnh trên server!");
                    }
                }, true);

            }).catch(err => { 
                if (tableResponsive) {
                    tableResponsive.style.overflow = oldOverflow;
                    tableResponsive.style.overflowX = oldOverflowX;
                }
                captureArea.style.width = oldWidth;
                if(!isDark) document.body.classList.remove('dark-mode');
                document.getElementById('loading').style.display = 'none'; 
                alert("❌ Lỗi tạo ảnh!");
            });
        }, 500);
    }

// Thêm hàm tạo Pop-up hiển thị ảnh
function showImagePreviewModal(imgSrc) {
    const oldModal = document.getElementById('preview_modal_3d');
    if (oldModal) oldModal.remove();

    const modalHtml = `
        <div id="preview_modal_3d" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 10001; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
            <p style="color: #2ecc71; font-weight: bold; font-size: 16px; margin-bottom: 10px; text-align: center;">✅ Đã chụp bảng điểm!</p>
            <p style="color: white; font-size: 14px; margin-bottom: 20px; text-align: center;">👇 <b>Chạm và giữ</b> vào ảnh bên dưới, chọn "Lưu hình ảnh" để tải về máy.</p>
            <div style="width: 100%; max-height: 70vh; overflow-y: auto; border: 2px solid var(--primary); border-radius: 12px; box-shadow: 0 0 15px rgba(52, 152, 219, 0.5);">
                <img src="${imgSrc}" style="width: 100%; height: auto; display: block; touch-action: none; pointer-events: auto;">
            </div>
            <button onclick="document.getElementById('preview_modal_3d').remove()" style="margin-top: 20px; padding: 12px 40px; background: var(--danger); color: white; border: none; border-radius: 12px; font-weight: bold; font-size: 16px; cursor: pointer;">Đóng</button>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

    function triggerAvatarUpload(playerName) {
        if (userRole !== 'admin') return;
        currentAvatarPlayer = playerName; document.getElementById('avatar_file_input').click();
    }

    function handleAvatarSelection(event) {
        const file = event.target.files[0]; if (!file) return;
        
        if (file.size > 10 * 1024 * 1024) {
            alert("❌ File ảnh quá nặng (vượt mức 10MB). Vui lòng chọn ảnh khác!");
            event.target.value = ""; return;
        }

        document.getElementById('loading_text').innerText = "📸 Đang nén & tối ưu ảnh..."; document.getElementById('loading').style.display = 'flex';
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas'); 
                
                const MAX_SIZE = 800; 
                let width = img.width; let height = img.height;
                if (width > height) { if (width > MAX_SIZE) { height *= MAX_SIZE / width; width = MAX_SIZE; } } else { if (height > MAX_SIZE) { width *= MAX_SIZE / height; height = MAX_SIZE; } }
                
                canvas.width = width; canvas.height = height; 
                const ctx = canvas.getContext('2d'); 
                
                ctx.fillStyle = "#12141c"; // Nền tối
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, width, height);
                
                const base64String = canvas.toDataURL('image/webp', 0.85); 
                
                sendAPI({ action: 'upload_avatar', name: currentAvatarPlayer, image: base64String }, () => { document.getElementById('avatar_file_input').value = ""; fetchDataFromServer(false); }, false);
            }
            img.src = e.target.result;
        }
        reader.readAsDataURL(file);
    }

    function handleBannerSelection(event) {
        const file = event.target.files[0]; if (!file) return;
        
        if (file.size > 10 * 1024 * 1024) {
            alert("❌ File ảnh bìa quá nặng (vượt mức 10MB). Vui lòng chọn ảnh khác!");
            event.target.value = ""; return;
        }

        document.getElementById('loading_text').innerText = "📸 Đang nén Banner..."; document.getElementById('loading').style.display = 'flex';
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                const canvas = document.createElement('canvas'); 
                
                const MAX_WIDTH = 1200; 
                let width = img.width; let height = img.height;
                if (width > MAX_WIDTH) { height *= MAX_WIDTH / width; width = MAX_WIDTH; }
                
                canvas.width = width; canvas.height = height; 
                const ctx = canvas.getContext('2d'); 
                
                ctx.fillStyle = "#12141c"; ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, width, height);
                
                const base64String = canvas.toDataURL('image/webp', 0.85); 
                
                sendAPI({ action: 'upload_banner', image: base64String }, () => { document.getElementById('banner_file_input').value = ""; fetchDataFromServer(false); }, false);
            }
            img.src = e.target.result;
        }
        reader.readAsDataURL(file);
    }
    
    // --- LOGIC CUSTOM AUTOCOMPLETE TÊN NGƯỜI CHƠI ---
let allPlayerNames = [];

function updatePlayerDatalist(playersArray) {
    // Chỉ lưu mảng tên vào bộ nhớ, không dùng datalist nữa
    allPlayerNames = playersArray.map(p => p.n);
}

// Khởi tạo tính năng gợi ý tên khi trang load xong
document.addEventListener("DOMContentLoaded", function() {
    const inputIds = ['t1_p1', 't1_p2', 't2_p1', 't2_p2'];
    
    inputIds.forEach(id => {
        const input = document.getElementById(id);
        if(!input) return;
        
        // Tạo hộp thoại gợi ý
        const suggestionBox = document.createElement('div');
        suggestionBox.className = 'custom-autocomplete-box';
        input.parentNode.appendChild(suggestionBox);

        // Bắt sự kiện gõ phím hoặc focus
        input.addEventListener('input', function() { showSuggestions(this, suggestionBox); });
        input.addEventListener('focus', function() { showSuggestions(this, suggestionBox); });
        
        // Ẩn bảng khi bấm ra chỗ khác
        document.addEventListener('click', function(e) {
            if (e.target !== input && !suggestionBox.contains(e.target)) {
                suggestionBox.style.display = 'none';
            }
        });
    });
});

function showSuggestions(input, box) {
    const val = input.value.toLowerCase().trim();
    
    // Lọc những tên chứa từ khóa (hoặc hiện tất cả nếu chưa gõ gì)
    const matches = allPlayerNames.filter(n => n.toLowerCase().includes(val));
    
    if (matches.length === 0) {
        box.style.display = 'none';
        return;
    }

    box.innerHTML = '';
    matches.forEach(name => {
        const item = document.createElement('div');
        item.className = 'custom-suggestion-item';
        // Avatar nhỏ đi kèm (nếu có) để tăng độ premium
        let avaHtml = playerAvatars[name] ? `<img src="${playerAvatars[name]}" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:8px;object-fit:cover;">` : `👤 `;
        
        item.innerHTML = avaHtml + name;
        item.onclick = function() {
            input.value = name;
            box.style.display = 'none';
		checkHeadToHead();
        };
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
        const d1 = document.getElementById('date_from').value; 
        const d2 = document.getElementById('date_to').value;
        const formatDate = (dateStr) => { if(!dateStr) return ""; const parts = String(dateStr).split('-'); return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : dateStr; };
        
        const dateText = (d1 === d2) ? `(Ngày ${formatDate(d1)})` : `(${formatDate(d1)} - ${formatDate(d2)})`;
        
        const dashboardDateDisplay = document.getElementById('dashboard_date_display');
        if(dashboardDateDisplay) dashboardDateDisplay.innerText = dateText; 
        const historyDateDisplay = document.getElementById('history_date_display');
        if(historyDateDisplay) historyDateDisplay.innerText = dateText; 
        const statsDateDisplay = document.getElementById('stats_date_display');
        if(statsDateDisplay) statsDateDisplay.innerText = "(Toàn thời gian)";

        const filtered = matches.filter(m => {
            if(!m.date) return false;
            return m.date >= d1 && m.date <= d2;
        });
        
        let filteredPlayers = {};
        filtered.forEach(m => {
            const team1 = Array.isArray(m.team1) ? m.team1.filter(Boolean) : [];
            const team2 = Array.isArray(m.team2) ? m.team2.filter(Boolean) : [];
            [...team1, ...team2].forEach(p => { if(!filteredPlayers[p]) filteredPlayers[p] = { m: 0, w: 0, l: 0, p: 0, h: 0, winPoints: 0, losePoints: 0, waterBalance: 0 }; });
            
            const wT = m.winner === 'team1' ? team1 : team2; 
            const lT = m.winner === 'team1' ? team2 : team1;
            const waterBet = m.water ? parseInt(m.water) : 0; 
            
            let t1_score = 0; let t2_score = 0;
            if (m.score && String(m.score).trim() !== "") {
                const sets = String(m.score).split(',');
                sets.forEach(s => {
                    const pts = s.split('-');
                    if(pts.length >= 2) {
                        t1_score += parseInt(pts[0].trim()) || 0;
                        t2_score += parseInt(pts[1].trim()) || 0;
                    }
                });
            }
            const t1_diff = t1_score - t2_score;
            const t2_diff = t2_score - t1_score;

            team1.forEach(p => { filteredPlayers[p].h += t1_diff; });
            team2.forEach(p => { filteredPlayers[p].h += t2_diff; });
            
            wT.forEach(p => { filteredPlayers[p].m++; filteredPlayers[p].w++; filteredPlayers[p].p += m.bet; filteredPlayers[p].winPoints += m.bet; filteredPlayers[p].waterBalance += waterBet; });
            lT.forEach(p => { filteredPlayers[p].m++; filteredPlayers[p].l++; filteredPlayers[p].p -= m.bet; filteredPlayers[p].losePoints += m.bet; filteredPlayers[p].waterBalance -= waterBet; });
        });
        
        const arrFiltered = Object.keys(filteredPlayers).map(n => ({ n, ...filteredPlayers[n] })).sort((a, b) => {
            if (b.p !== a.p) return b.p - a.p;
            return b.h - a.h;
        });
        
        const waterLosers = Object.keys(filteredPlayers).map(n => ({ n, waterBalance: filteredPlayers[n].waterBalance })).filter(p => p.waterBalance < 0).sort((a, b) => a.waterBalance - b.waterBalance).slice(0, 3).map(p => p.n);

        let allTimePlayers = {};
        matches.forEach(m => {
            const team1 = Array.isArray(m.team1) ? m.team1.filter(Boolean) : [];
            const team2 = Array.isArray(m.team2) ? m.team2.filter(Boolean) : [];
            [...team1, ...team2].forEach(p => { if(!allTimePlayers[p]) allTimePlayers[p] = { m: 0, w: 0, l: 0, p: 0, h: 0, winPoints: 0, losePoints: 0, waterBalance: 0 }; });
            
            const wT = m.winner === 'team1' ? team1 : team2; 
            const lT = m.winner === 'team1' ? team2 : team1;
            const waterBet = m.water ? parseInt(m.water) : 0;

            let t1_score = 0; let t2_score = 0;
            if (m.score && String(m.score).trim() !== "") {
                const sets = String(m.score).split(',');
                sets.forEach(s => {
                    const pts = s.split('-');
                    if(pts.length >= 2) {
                        t1_score += parseInt(pts[0].trim()) || 0;
                        t2_score += parseInt(pts[1].trim()) || 0;
                    }
                });
            }
            const t1_diff = t1_score - t2_score;
            const t2_diff = t2_score - t1_score;

            team1.forEach(p => { allTimePlayers[p].h += t1_diff; });
            team2.forEach(p => { allTimePlayers[p].h += t2_diff; });

            wT.forEach(p => { allTimePlayers[p].m++; allTimePlayers[p].w++; allTimePlayers[p].p += m.bet; allTimePlayers[p].winPoints += m.bet; allTimePlayers[p].waterBalance += waterBet;});
            lT.forEach(p => { allTimePlayers[p].m++; allTimePlayers[p].l++; allTimePlayers[p].p -= m.bet; allTimePlayers[p].losePoints += m.bet; allTimePlayers[p].waterBalance -= waterBet;});
        });
        
        const arrAllTime = Object.keys(allTimePlayers).map(n => ({ n, ...allTimePlayers[n] })).sort((a, b) => {
            if (b.p !== a.p) return b.p - a.p;
            return b.h - a.h;
        });
        updatePlayerDatalist(arrAllTime);

        // ==========================================
        // TÍNH TOÁN WINSTREAK / LOSESTREAK
        // ==========================================
        let playerStreaks = {};
        matches.forEach(m => {
            const wT = m.winner === 'team1' ? (Array.isArray(m.team1)?m.team1:[]) : (m.winner === 'team2' ? (Array.isArray(m.team2)?m.team2:[]) : []);
            const lT = m.winner === 'team1' ? (Array.isArray(m.team2)?m.team2:[]) : (m.winner === 'team2' ? (Array.isArray(m.team1)?m.team1:[]) : []);
            
            wT.filter(Boolean).forEach(p => {
                if(!playerStreaks[p]) playerStreaks[p] = { type: 'W', count: 0 };
                if(playerStreaks[p].type === 'W') playerStreaks[p].count++;
                else playerStreaks[p] = { type: 'W', count: 1 };
            });
            
            lT.filter(Boolean).forEach(p => {
                if(!playerStreaks[p]) playerStreaks[p] = { type: 'L', count: 0 };
                if(playerStreaks[p].type === 'L') playerStreaks[p].count++;
                else playerStreaks[p] = { type: 'L', count: 1 };
            });
        });

        // ==========================================
        // RENDER BẢNG PHONG THẦN
        // ==========================================
        const tbody = document.getElementById('dashboard_body'); 
        if(tbody) {
            tbody.innerHTML = '';
            if(arrFiltered.length===0) { tbody.innerHTML = '<tr><td colspan="8" style="color: var(--text-muted);font-style:italic; border:none;">Chưa có dữ liệu</td></tr>'; }
            else {
                arrFiltered.forEach((p, i) => {
                    let rClass = ''; let stt = i + 1; let pointColor = 'inherit'; 
                    
                    if (i === 0) rClass = 'row-top1'; else if (i === 1) rClass = 'row-top2'; else if (i === 2) rClass = 'row-top3';
                    let isBot = false;
                    if (arrFiltered.length >= 4) {
                        if (i === arrFiltered.length - 1) { rClass = 'row-bot1'; isBot = true; }
                        else if (i === arrFiltered.length - 2) { rClass = 'row-bot2'; isBot = true; }
                        else if (i === arrFiltered.length - 3) { rClass = 'row-bot3'; isBot = true; }
                    }
                    if (rClass === '') pointColor = p.p > 0 ? 'var(--success)' : (p.p < 0 ? 'var(--danger)' : 'var(--text-main)');
                    
                    let titleBadge = '';
                    if (i === 0 && p.p > 0) titleBadge += `<span class="badge-title title-top1">👑 Kẻ Huỷ Diệt</span>`;
                    else if ((i === 1 || i === 2) && p.p > 0) titleBadge += `<span class="badge-title title-top23">⚔️ Cao Thủ</span>`;
                    if (isBot && p.p < 0) titleBadge += ` <span class="badge-title title-bot">🐆 Vua Báo Thủ</span>`;
                    if (waterLosers.includes(p.n)) titleBadge += ` <span class="badge-title title-water">🥤 Thần Nước</span>`;

                    // Danh hiệu mở rộng
                    if (p.m >= 20) titleBadge += `<span class="badge-title" style="background: linear-gradient(145deg, #9b59b6, #8e44ad); border: 1px solid #8e44ad;">🤖 Thánh Bào Điểm</span>`;
                    const winRate = p.m > 0 ? (p.w / p.m) : 0;
                    if (p.m >= 5 && winRate >= 0.8) titleBadge += `<span class="badge-title" style="background: linear-gradient(145deg, #2ecc71, #27ae60); border: 1px solid #27ae60;">🛡️ Bất Bại</span>`;
                    if (p.waterBalance <= -20) titleBadge += `<span class="badge-title" style="background: linear-gradient(145deg, #34495e, #2c3e50); border: 1px solid #2c3e50;">🧊 Trúng Thủy Độc</span>`;

                    // Render Streak
                    let streakHtml = '';
                    if (playerStreaks[p.n] && playerStreaks[p.n].count >= 3) {
                        if (playerStreaks[p.n].type === 'W') {
                            streakHtml = `<span class="streak-badge streak-win" title="Đang thắng ${playerStreaks[p.n].count} trận liên tiếp">🔥 x${playerStreaks[p.n].count}</span>`;
                        } else if (playerStreaks[p.n].type === 'L') {
                            streakHtml = `<span class="streak-badge streak-lose" title="Đang thua ${playerStreaks[p.n].count} trận liên tiếp">🥶 x${playerStreaks[p.n].count}</span>`;
                        }
                    }
                    
                    let waterText = p.waterBalance > 0 ? '+' + p.waterBalance : p.waterBalance;
                    let hText = p.h > 0 ? '+' + p.h : p.h; 
                    
                    tbody.innerHTML += `<tr class="${rClass}"><td><strong>${stt}</strong></td><td style="text-align:left;"><strong>${p.n}</strong> ${streakHtml} <br> ${titleBadge}</td><td>${p.m}</td><td>${p.w}</td><td>${p.l}</td><td>${waterText}</td><td><strong>${hText}</strong></td><td style="color:${pointColor}; font-size:18px;"><strong>${p.p>0?'+'+p.p:p.p}</strong></td></tr>`;
                });
            }
        }

        currentFilteredMatches = [...filtered];
        uniqueHistoryDates = [...new Set(currentFilteredMatches.map(m => m.date))].sort((a, b) => new Date(b) - new Date(a));
        if (!uniqueHistoryDates[currentHistoryDateIndex]) currentHistoryDateIndex = 0;
        renderHistoryGrid();

        // ==========================================
        // RENDER BỤC VINH QUANG
        // ==========================================
        const renderPodium = (arr, type) => {
            if(!arr || arr.length === 0) return `<p style="text-align:center;width:100%;color:var(--text-muted);font-style:italic;">Chưa có dữ liệu</p>`;
            let clsPrefix = 'podium';
            if (type === 'bot') clsPrefix = 'podium-bot';
            if (type === 'water') clsPrefix = 'podium-water';
            
            const getScore = (p) => {
                if (type === 'water') return p.waterBalance;
                return p.p > 0 ? '+'+p.p : p.p;
            };

            const p1 = arr[0];
            const p2 = arr.length > 1 ? arr[1] : null;
            const p3 = arr.length > 2 ? arr[2] : null;

            let html = '';
            if (p2) html += `<div class="podium-box ${clsPrefix}-2"><div class="podium-avatar">${renderAvatarHTML(p2.n, true)}</div><div class="podium-name">${p2.n}</div><div class="podium-score">${getScore(p2)}</div></div>`;
            if (p1) html += `<div class="podium-box ${clsPrefix}-1"><div class="podium-avatar">${renderAvatarHTML(p1.n, true)}</div><div class="podium-name">${p1.n}</div><div class="podium-score">${getScore(p1)}</div></div>`;
            if (p3) html += `<div class="podium-box ${clsPrefix}-3"><div class="podium-avatar">${renderAvatarHTML(p3.n, true)}</div><div class="podium-name">${p3.n}</div><div class="podium-score">${getScore(p3)}</div></div>`;
            return html;
        };
        
        const top3Arr = arrFiltered.slice(0, 3);
        const bot3Arr = [...arrFiltered].reverse().slice(0, 3);
        const water3Arr = [...arrFiltered].filter(p => p.waterBalance < 0).sort((a, b) => a.waterBalance - b.waterBalance).slice(0, 3);
        
        const top3El = document.getElementById('top3_podium');
        if(top3El) top3El.innerHTML = renderPodium(top3Arr, 'top');
        const bot3El = document.getElementById('bottom3_podium');
        if(bot3El) bot3El.innerHTML = renderPodium(bot3Arr, 'bot');
        const water3El = document.getElementById('water3_podium');
        if(water3El) water3El.innerHTML = renderPodium(water3Arr, 'water');

        // ==========================================
        // RENDER THỐNG KÊ CHI TIẾT
        // ==========================================
        try {
            const statsContainer = document.getElementById('stats_container'); 
            if(statsContainer) {
                statsContainer.innerHTML = '';
                if (arrAllTime.length === 0) {
                    statsContainer.innerHTML = '<div style="text-align:center; width:100%; grid-column: 1 / -1; padding: 20px; color: var(--text-muted); font-style: italic;">Chưa có dữ liệu thống kê nào.</div>';
                } else {
                    let statsHTML = ''; 
                    arrAllTime.forEach(p => {
                        try {
                            const winRate = p.m > 0 ? Math.round((p.w / p.m) * 100) : 0;
                            let avaHtml = (p.n && String(p.n) !== 'undefined') ? (playerAvatars[p.n] ? `<img src="${playerAvatars[p.n]}">` : String(p.n).charAt(0).toUpperCase()) : '';
                            const safeName = p.n ? String(p.n).replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, ' ') : 'Unknown';
                            const overlayHtml = userRole === 'admin' ? `<div class="upload-overlay">📷 Đổi</div>` : '';
                            let hText = p.h > 0 ? '+' + p.h : p.h; 
                            
                            statsHTML += `
                            <div class="stat-card" onclick="openStatsModal('${safeName}', this)">
                                <div class="stat-avatar-hq">${avaHtml}</div>
                                <div class="stat-avatar" onclick="event.stopPropagation(); triggerAvatarUpload('${safeName}')">${avaHtml}${overlayHtml}</div>
                                <div class="stat-info">
                                    <h3 class="stat-name">${p.n}</h3>
                                    <div class="stat-details-grid">
                                        <div class="stat-box"><span class="stat-label">Số trận</span><span class="stat-val" style="color: #f1c40f;">${p.m}</span></div>
                                        <div class="stat-box"><span class="stat-label">Tỉ lệ thắng</span><span class="stat-val" style="color: #e67e22;">${winRate}%</span></div>
                                        <div class="stat-box"><span class="stat-label">Tổng Điểm</span><span class="stat-val" style="color: #27ae60;">${p.p}</span></div>
                                        <div class="stat-box"><span class="stat-label">Nước</span><span class="stat-val" style="color: #3498db;">${p.waterBalance}</span></div>
                                        <div class="stat-box"><span class="stat-label">Hiệu số</span><span class="stat-val" style="color: #e74c3c;">${hText}</span></div>
                                        <div class="stat-box"><span class="stat-label">T/B Trận</span><span class="stat-val" style="color: #9b59b6;">${p.w}/${p.l}</span></div>
                                    </div>
                                </div>
                            </div>`;
                        } catch(e) { console.error("Lỗi 1 thẻ:", e); }
                    });
                    statsContainer.innerHTML = statsHTML;
                }
            }
        } catch(errStats) {
            console.error("Lỗi khi render Thống Kê:", errStats);
        }
        
    } catch (error) {
        console.error("Lỗi khi render dữ liệu: ", error);
    }
}


    function changeHistoryDate(step) {
        currentHistoryDateIndex += step;
        if (currentHistoryDateIndex < 0) currentHistoryDateIndex = 0;
        if (currentHistoryDateIndex >= uniqueHistoryDates.length) currentHistoryDateIndex = uniqueHistoryDates.length - 1;
        renderHistoryGrid();
    }

    function renderHistoryGrid() {
        try {
            const grid = document.getElementById('history_grid'); grid.innerHTML = '';
            const paginationDiv = document.getElementById('history_pagination');
            
            if (!uniqueHistoryDates || uniqueHistoryDates.length === 0) {
                if(paginationDiv) paginationDiv.style.display = 'none';
                grid.innerHTML = '<p style="color: var(--text-muted);font-style:italic;text-align:center;width:100%;">Không có trận đấu nào.</p>';
                return;
            }

            if(paginationDiv) paginationDiv.style.display = 'flex';
            const btnPrev = document.getElementById('btn_prev_date');
            const btnNext = document.getElementById('btn_next_date');
            const dateLabel = document.getElementById('current_history_date_label');

            btnPrev.disabled = currentHistoryDateIndex >= uniqueHistoryDates.length - 1; 
            btnNext.disabled = currentHistoryDateIndex <= 0; 
            btnPrev.style.opacity = btnPrev.disabled ? '0.3' : '1';
            btnNext.style.opacity = btnNext.disabled ? '0.3' : '1';

            const currentDateStr = uniqueHistoryDates[currentHistoryDateIndex];
            const formatDate = (dateStr) => { 
                if(!dateStr) return "";
                const parts = String(dateStr).split('-'); 
                return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : dateStr; 
            };
            dateLabel.innerText = "📅 Ngày " + formatDate(currentDateStr);

            const matchesForDate = currentFilteredMatches.filter(m => m.date === currentDateStr);

            [...matchesForDate].forEach((m) => {
                const w1 = m.winner === 'team1'; 
                const absoluteIndex = currentFilteredMatches.indexOf(m);
                const stt = absoluteIndex + 1;
                const shortTime = (m.time && String(m.time).length > 5) ? String(m.time).substring(0, 5) : (m.time || '--:--');
                
                const getAva = (name) => {
                    if (!name || name === 'undefined') return '';
                    return playerAvatars[name] ? `<img src="${playerAvatars[name]}">` : String(name).charAt(0).toUpperCase();
                };

                const team1 = Array.isArray(m.team1) ? m.team1 : [];
                const team2 = Array.isArray(m.team2) ? m.team2 : [];

                const t1_p1 = team1[0] || 'Player'; const t1_p2 = team1[1] || '';
                const t2_p1 = team2[0] || 'Player'; const t2_p2 = team2[1] || '';

                grid.innerHTML += `
                <div class="match-card-v2">
                    <div class="match-info-header">TRẬN #${stt} | 🕒 ${shortTime}</div>
                    <div class="match-battle-area">
                        <div class="team-col ${w1 ? 'winner' : 'loser'}">
                            ${w1 ? '<div class="stamp win">WIN</div>' : '<div class="stamp lose">LOSE</div>'}
                            <div class="avatar-split-container ${!t1_p2 ? 'single-mode' : ''}">
                                <div class="ava-p1">${getAva(t1_p1)}</div>
                                ${t1_p2 ? `<div class="diagonal-line"></div><div class="ava-p2">${getAva(t1_p2)}</div>` : ''}
                            </div>
                            <div class="team-names-v2">${t1_p1} <br> ${t1_p2}</div>
                        </div>
                        <div class="vs-col">
                            <div class="vs-fire-text">VS</div>
                            <div class="bet-badge">Cược: ${m.bet} Đ</div>
                            ${m.water > 0 ? `<div class="bet-badge water-badge" style="background:var(--secondary); color:#fff; border-color:var(--secondary);">🥤 Nước: ${m.water}</div>` : ''}
                            ${m.score ? `<div class="bet-badge score-badge">🎯 ${m.score}</div>` : ''}
                        </div>
                        <div class="team-col ${!w1 ? 'winner' : 'loser'}">
                            ${!w1 ? '<div class="stamp win">WIN</div>' : '<div class="stamp lose">LOSE</div>'}
                            <div class="avatar-split-container ${!t2_p2 ? 'single-mode' : ''}">
                                <div class="ava-p1">${getAva(t2_p1)}</div>
                                ${t2_p2 ? `<div class="diagonal-line"></div><div class="ava-p2">${getAva(t2_p2)}</div>` : ''}
                            </div>
                            <div class="team-names-v2">${t2_p1} <br> ${t2_p2}</div>
                        </div>
                    </div>
                    ${userRole === 'admin' ? `
                    <div class="card-actions">
                        <button class="btn-outline" style="padding: 8px 15px;" onclick="editMatch(${m.id})">✏️ Sửa</button>
                        <button class="btn-danger" style="padding: 8px 15px;" onclick="deleteMatch(${m.id})">🗑️ Xóa</button>
                    </div>` : ''}
                </div>`;
            });
        } catch(e) { console.error("History render error", e); }
    }

    let currentCardName = "";

    function openStatsModal(playerName, cardElement) {
        currentCardName = playerName;
        const modal = document.getElementById('stats_modal');
        const captureArea = document.getElementById('capture_card_area');
        
        let cardClone = cardElement.cloneNode(true);
        const overlay = cardClone.querySelector('.upload-overlay');
        if(overlay) overlay.remove();

        const avatarHq = cardClone.querySelector('.stat-avatar-hq');
        const avatar = cardClone.querySelector('.stat-avatar');
        if(avatarHq && avatar){
            avatar.innerHTML = avatarHq.innerHTML;
            avatarHq.remove();
        }
        
        captureArea.innerHTML = "";
        captureArea.appendChild(cardClone);
        modal.style.display = "flex";
    }

    function closeStatsModal() {
        document.getElementById('stats_modal').style.display = "none";
    }

    function downloadSingleCard() {
        const area = document.getElementById('capture_card_area');
        document.getElementById('loading_text').innerText = "📸 Đang tạo ảnh thẻ bài 3D...";
        document.getElementById('loading').style.display = 'flex';

        setTimeout(() => {
            html2canvas(area, { 
                scale: 3, 
                backgroundColor: null,
                logging: false,
                useCORS: true 
            }).then(canvas => {
                let link = document.createElement('a');
                link.download = `The-Bai-${currentCardName}.png`;
                link.href = canvas.toDataURL('image/png');
                link.click();
                document.getElementById('loading').style.display = 'none';
            });
        }, 500);
    }

    window.onclick = function(event) {
        const modal = document.getElementById('stats_modal');
        if (event.target == modal) closeStatsModal();
    }

    function toggleMatchType() {
        const isDon = document.querySelector('input[name="match_type"]:checked').value === 'don';
        const p2Fields = [document.getElementById('t1_p2'), document.getElementById('t2_p2')];
        
        p2Fields.forEach(el => {
            el.style.display = isDon ? 'none' : 'block';
            if (isDon) el.value = ''; 
        });
    }
// --- LOGIC: LỊCH SỬ ĐỐI ĐẦU (H2H) ---
function checkHeadToHead() {
    const t1 = [document.getElementById('t1_p1').value.trim(), document.getElementById('t1_p2').value.trim()].filter(Boolean).sort().join(' & ');
    const t2 = [document.getElementById('t2_p1').value.trim(), document.getElementById('t2_p2').value.trim()].filter(Boolean).sort().join(' & ');
    
    const h2hBox = document.getElementById('h2h_result');
    if(!h2hBox) return; // Bảo vệ lỗi nếu chưa thêm div vào HTML
    
    if(!t1 || !t2) { h2hBox.style.display = 'none'; return; } // Chưa nhập đủ thì ẩn
    
    let t1Wins = 0, t2Wins = 0;
    matches.forEach(m => {
        const mt1 = (Array.isArray(m.team1) ? m.team1 : []).filter(Boolean).sort().join(' & ');
        const mt2 = (Array.isArray(m.team2) ? m.team2 : []).filter(Boolean).sort().join(' & ');
        
        // Kiểm tra xem 2 đội này đã từng gặp nhau chưa (có thể bị đảo ngược vị trí Đội 1 Đội 2)
        if ((mt1 === t1 && mt2 === t2) || (mt1 === t2 && mt2 === t1)) {
            const isT1MatchTeam1 = (mt1 === t1);
            if (m.winner === 'team1') {
                isT1MatchTeam1 ? t1Wins++ : t2Wins++;
            } else if (m.winner === 'team2') {
                isT1MatchTeam1 ? t2Wins++ : t1Wins++;
            }
        }
    });
    
    if (t1Wins > 0 || t2Wins > 0) {
        h2hBox.style.display = 'block';
        h2hBox.innerHTML = `⚔️ H2H: [${t1Wins}] - [${t2Wins}]`;
    } else {
        h2hBox.style.display = 'block';
        h2hBox.innerHTML = `⚔️ Lần đầu chạm trán`;
    }
}

// Bắt sự kiện người dùng nhấp ra ngoài ô nhập tên để tự động tính H2H
document.addEventListener("DOMContentLoaded", function() {
    const inputIds = ['t1_p1', 't1_p2', 't2_p1', 't2_p2'];
    inputIds.forEach(id => {
        const input = document.getElementById(id);
        if(input) {
            input.addEventListener('blur', checkHeadToHead); 
        }
    });
});

let currentWrappedIndex = 0;
let wrappedSlidesData = [];
let wrappedTimer;

function showMonthlyWrapped() {
    // 1. Chuẩn bị dữ liệu từ mảng arrFiltered (Dữ liệu bảng xếp hạng hiện tại)
    // Lấy top 1 điểm
    let top1 = arrFiltered.length > 0 ? arrFiltered[0] : null;
    // Lấy top nợ nước (nhỏ nhất)
    let waterKing = [...arrFiltered].sort((a, b) => a.waterBalance - b.waterBalance)[0];
    // Lấy người cày nhiều trận nhất
    let ironMan = [...arrFiltered].sort((a, b) => b.m - a.m)[0];
    
    let totalMatches = matches.filter(m => m.date >= document.getElementById('date_from').value && m.date <= document.getElementById('date_to').value).length;

    if(totalMatches === 0) { alert("Tháng này chưa có trận đấu nào để tổng kết!"); return; }

    // 2. Tạo kịch bản Slides
    wrappedSlidesData = [
        { bg: 'bg-slide-1', title: 'Tháng vừa qua...', val: totalMatches, desc: 'trận đấu nảy lửa đã diễn ra trên sân của chúng ta. Mồ hôi, tiếng cười và cả những cú vấp ngã!' },
    ];

    if(top1 && top1.p > 0) {
        wrappedSlidesData.push({ bg: 'bg-slide-2', title: '👑 Kẻ Hủy Diệt', val: top1.n, desc: `Không có đối thủ! Mang về +${top1.p} điểm với ${top1.w} trận thắng.` });
    }
    if(waterKing && waterKing.waterBalance < 0) {
        wrappedSlidesData.push({ bg: 'bg-slide-3', title: '🥤 Thần Nước', val: waterKing.n, desc: `Nhà tài trợ vàng của tháng. Đã cống hiến ${Math.abs(waterKing.waterBalance)} ly nước cho anh em!` });
    }
    if(ironMan && ironMan.m > 0) {
        wrappedSlidesData.push({ bg: 'bg-slide-4', title: '🤖 Cỗ Máy', val: ironMan.n, desc: `Thể lực vô cực! Đã ra sân cày ải tổng cộng ${ironMan.m} trận.` });
    }

    // 3. Render HTML
    let progressHtml = '';
    let slidesHtml = '';
    wrappedSlidesData.forEach((slide, i) => {
        progressHtml += `<div class="wrapped-bar"><div class="wrapped-bar-fill" id="wrap_fill_${i}"></div></div>`;
        slidesHtml += `
            <div class="wrapped-slide ${slide.bg}" id="wrap_slide_${i}">
                <div class="wrapped-title">${slide.title}</div>
                <div class="wrapped-value">${slide.val}</div>
                <div class="wrapped-desc">${slide.desc}</div>
                ${i === wrappedSlidesData.length - 1 ? '<button class="btn-outline" style="margin-top:30px; background:rgba(0,0,0,0.5); border:none;" onclick="closeWrapped()">Kết Thúc</button>' : ''}
            </div>
        `;
    });

    document.getElementById('wrapped_progress_container').innerHTML = progressHtml;
    document.getElementById('wrapped_slides_container').innerHTML = slidesHtml;
    
    document.getElementById('wrapped_modal').style.display = 'flex';
    currentWrappedIndex = 0;
    playWrappedSlide(0);
}

function playWrappedSlide(index) {
    if(index >= wrappedSlidesData.length) { closeWrapped(); return; }
    
    // Reset tất cả
    clearTimeout(wrappedTimer);
    document.querySelectorAll('.wrapped-slide').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.wrapped-bar-fill').forEach((el, i) => {
        el.style.transition = 'none';
        el.style.width = i < index ? '100%' : '0%';
    });

    // Kích hoạt slide hiện tại
    document.getElementById(`wrap_slide_${index}`).classList.add('active');
    
    // Animation thanh tiến trình (Chạy trong 4 giây)
    setTimeout(() => {
        let currentFill = document.getElementById(`wrap_fill_${index}`);
        if(currentFill) {
            currentFill.style.transition = 'width 4s linear';
            currentFill.style.width = '100%';
        }
    }, 50);

    // Tự động chuyển slide sau 4 giây
    wrappedTimer = setTimeout(() => {
        nextWrappedSlide();
    }, 4000);
}

function nextWrappedSlide() {
    currentWrappedIndex++;
    playWrappedSlide(currentWrappedIndex);
}

function prevWrappedSlide() {
    if(currentWrappedIndex > 0) {
        currentWrappedIndex--;
        playWrappedSlide(currentWrappedIndex);
    }
}

function closeWrapped() {
    clearTimeout(wrappedTimer);
    document.getElementById('wrapped_modal').style.display = 'none';
}

// ==========================================
// THUẬT TOÁN GHÉP KÈO CÂN BẰNG
// ==========================================
function autoMatchmake() {
    const inputs = [
        document.getElementById('t1_p1'),
        document.getElementById('t1_p2'),
        document.getElementById('t2_p1'),
        document.getElementById('t2_p2')
    ];
    
    // Lấy tên 4 người
    const players = inputs.map(input => input.value.trim()).filter(Boolean);
    
    if(players.length < 4) {
        alert("❌ Vui lòng nhập đủ tên 4 người chơi để hệ thống chia đội!");
        return;
    }

    // Hàm lấy điểm của người chơi từ biến arrFiltered
    const getScore = (playerName) => {
        const pInfo = typeof arrFiltered !== 'undefined' ? arrFiltered.find(p => p.n === playerName) : null;
        return pInfo ? pInfo.p : 0; 
    };

    const pData = players.map(name => ({ name: name, score: getScore(name) }));
    
    const combos = [
        { t1: [pData[0], pData[1]], t2: [pData[2], pData[3]] },
        { t1: [pData[0], pData[2]], t2: [pData[1], pData[3]] },
        { t1: [pData[0], pData[3]], t2: [pData[1], pData[2]] }
    ];

    let bestCombo = null;
    let minDiff = Infinity;

    // Tìm tổ hợp có độ lệch sức mạnh nhỏ nhất
    combos.forEach(combo => {
        const score1 = combo.t1[0].score + combo.t1[1].score;
        const score2 = combo.t2[0].score + combo.t2[1].score;
        const diff = Math.abs(score1 - score2);
        
        if (diff < minDiff) {
            minDiff = diff;
            bestCombo = combo;
        }
    });

    // Cập nhật lại giao diện
    if(bestCombo) {
        inputs[0].value = bestCombo.t1[0].name;
        inputs[1].value = bestCombo.t1[1].name;
        inputs[2].value = bestCombo.t2[0].name;
        inputs[3].value = bestCombo.t2[1].name;
        
        inputs.forEach(input => {
            input.style.backgroundColor = 'rgba(46, 204, 113, 0.3)';
            setTimeout(() => input.style.backgroundColor = 'var(--bg-input)', 500);
        });

        if(typeof checkHeadToHead === 'function') checkHeadToHead();
    }
}

// --- HÀM SUBMIT ĐỔI MẬT KHẨU ---
function submitChangePassword() {
    const p1 = document.getElementById('cp_new_pass').value;
    const p2 = document.getElementById('cp_confirm_pass').value;
    
    if(!p1 || !p2) { 
        alert("Vui lòng nhập đầy đủ mật khẩu!"); 
        return; 
    }
    if(p1 !== p2) { 
        alert("❌ Mật khẩu nhập lại không khớp!"); 
        return; 
    }
    
    sendAPI({ action: 'change_password', new_password: p1 }, res => {
        if(res.status === 'success') {
            alert("✅ Đổi mật khẩu thành công!");
            document.getElementById('change_pass_modal').style.display = 'none';
            document.getElementById('cp_new_pass').value = '';
            document.getElementById('cp_confirm_pass').value = '';
        } else {
            alert(res.message || "❌ Lỗi hệ thống khi đổi mật khẩu!");
        }
    });
}

// ==========================================
// TÍNH NĂNG NHẬP GIỌNG NÓI (GIỮ ĐỂ NÓI)
// ==========================================
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
let recognition;
let isRecording = false;

if (SpeechRecognition) {
    recognition = new SpeechRecognition();
    recognition.lang = 'vi-VN'; 
    recognition.continuous = true; // Cho phép nghe liên tục đến khi thả tay
    recognition.interimResults = false;

    recognition.onerror = function(event) {
        console.error('Lỗi nhận diện:', event.error);
        resetVoiceButton();
    };

    recognition.onresult = function(event) {
        // Lấy đoạn text cuối cùng sau khi thả tay
        let transcript = "";
        for (let i = event.resultIndex; i < event.results.length; ++i) {
            transcript += event.results[i][0].transcript;
        }
        transcript = transcript.toLowerCase();
        console.log("Hệ thống nghe được:", transcript);
        if(transcript.trim() !== "") {
            parseVoiceToMatch(transcript);
        }
    };
}

const btnVoice = document.getElementById('btn_voice_input');

// HÀM BẮT ĐẦU NGHE (KHI NHẤN XUỐNG)
const startHold = (e) => {
    e.preventDefault(); // Chặn menu chuột phải / bôi đen chữ
    if (!recognition) {
        alert("Trình duyệt không hỗ trợ. Vui lòng dùng Chrome/Safari/Edge mới nhất!");
        return;
    }
    if (!isRecording) {
        try {
            recognition.start();
            isRecording = true;
            btnVoice.innerHTML = '🎙️ Đang nghe... (Thả ra để chốt)';
            btnVoice.style.background = 'linear-gradient(145deg, #e74c3c, #c0392b)';
            btnVoice.style.transform = 'scale(0.96)'; // Hiệu ứng lún nút
        } catch(err) {}
    }
};

// HÀM DỪNG NGHE (KHI THẢ TAY)
const stopHold = (e) => {
    e.preventDefault();
    if (isRecording) {
        recognition.stop(); // Ép hệ thống chốt câu nói ngay lập tức
        isRecording = false;
        resetVoiceButton();
    }
};

function resetVoiceButton() {
    if (btnVoice) {
        btnVoice.innerHTML = '🎤 Nhấn Giữ Để Nói';
        btnVoice.style.background = 'linear-gradient(145deg, #ff4757, #ff6b81)';
        btnVoice.style.transform = 'scale(1)';
    }
}

// GẮN SỰ KIỆN CHUỘT (CHO MÁY TÍNH) VÀ CẢM ỨNG (CHO ĐIỆN THOẠI)
if (btnVoice) {
    // Máy tính
    btnVoice.addEventListener('mousedown', startHold);
    btnVoice.addEventListener('mouseup', stopHold);
    btnVoice.addEventListener('mouseleave', stopHold); // Trượt chuột ra ngoài cũng ngắt
    
    // Điện thoại cảm ứng
    btnVoice.addEventListener('touchstart', startHold, {passive: false});
    btnVoice.addEventListener('touchend', stopHold, {passive: false});
    btnVoice.addEventListener('touchcancel', stopHold, {passive: false});
}

function parseVoiceToMatch(text) {
    let betMatch = text.match(/(độ|cược|điểm)\s*(\d+)/i);
    let waterMatch = text.match(/nước\s*(\d+)/i);
    
    if (betMatch) {
        document.getElementById('bet_amount').value = betMatch[2];
        text = text.replace(betMatch[0], ''); 
    } else {
        document.getElementById('bet_amount').value = "1";
    }

    if (waterMatch) {
        document.getElementById('water_amount').value = waterMatch[2];
        text = text.replace(waterMatch[0], ''); 
    } else {
        document.getElementById('water_amount').value = "0";
    }

    let teamSplitters = [' đấu với ', ' đánh với ', ' đấu ', ' gặp ', ' vs '];
    let teams = [];
    for (let splitter of teamSplitters) {
        if (text.includes(splitter)) {
            teams = text.split(splitter);
            break;
        }
    }

    if (teams.length < 2) {
        alert(`Máy nghe được: "${text}"\nThiếu từ khóa nối. Vui lòng đọc chữ "đấu" hoặc "gặp" ở giữa 2 đội.`);
        return;
    }

    let parsePlayers = (teamText) => {
        let players = teamText.split(/\s+và\s+|\s+với\s+|,/);
        return players.map(p => formatName(p.trim())).filter(p => p);
    };

    let t1Players = parsePlayers(teams[0]);
    let t2Players = parsePlayers(teams[1]);

    document.getElementById('t1_p1').value = t1Players[0] || '';
    document.getElementById('t1_p2').value = t1Players[1] || '';
    document.getElementById('t2_p1').value = t2Players[0] || '';
    document.getElementById('t2_p2').value = t2Players[1] || '';

    const radioT1 = document.querySelector('input[name="manual_winner"][value="team1"]');
    if(radioT1) radioT1.checked = true;
    if(typeof highlightManualWinner === 'function') highlightManualWinner();
    
    if(typeof checkHeadToHead === 'function') checkHeadToHead();
    
    if (navigator.vibrate) navigator.vibrate(200);
}