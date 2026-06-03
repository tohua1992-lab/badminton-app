let matches = []; 
let playerAvatars = {}; 
let userRole = '';
let currentDataString = ""; 
let currentAvatarPlayer = ""; 
let superAdminData = [];

let currentFilteredMatches = [];
let uniqueHistoryDates = [];
let currentHistoryDateIndex = 0;
let myRadarChart;
let currentCardName = "";

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

window.onload = () => {
    const savedTheme = localStorage.getItem('betminton_theme') || 'dark';
    applyTheme(savedTheme === 'dark');

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

function toggleAuthView(view) {
    if (view === 'register') {
        document.getElementById('form_login_view').style.display = 'none';
        document.getElementById('form_register_view').style.display = 'block';
    } else {
        document.getElementById('form_login_view').style.display = 'block';
        document.getElementById('form_register_view').style.display = 'none';
    }
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
        document.getElementById('app_title').innerHTML = "🏆 " + groupName;
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
        
        // TẢI HÌNH ẢNH 1 LẦN DUY NHẤT LÚC ĐĂNG NHẬP (TRÁNH GIẬT LAG)
        fetchMediaFromServer();
        
        fetchDataFromServer(false); 
        setInterval(() => { fetchDataFromServer(true); }, 35000); 
    }
}

// 1. CHỈ TẢI LỊCH SỬ TRẬN ĐẤU (RẤT NHẸ - CHẠY MỖI 35 GIÂY)
function fetchDataFromServer(silent = true) {
    if(userRole === 'superadmin') return;
    sendAPI({ action: 'fetch' }, (res) => {
        if(res.status === 'expired') {
            alert("Phiên đăng nhập đã hết hạn. Vui lòng liên hệ Admin!"); logout(); return;
        }
        if(res.status === 'success') {
            const newDataString = JSON.stringify(res.data);
            if (currentDataString !== newDataString) { 
                currentDataString = newDataString; 
                matches = res.data; 
                renderAll(); 
            }
        }
    }, silent);
}

// 2. CHỈ TẢI AVATAR VÀ BANNER (NẶNG - CHỈ CHẠY 1 LẦN)
function fetchMediaFromServer() {
    if(userRole === 'superadmin') return;
    sendAPI({ action: 'fetch_media' }, (res) => {
        if(res.status === 'success') {
            playerAvatars = res.avatars || {}; 
            if (res.banner) { document.getElementById('main_banner_img').src = res.banner; } 
            else { document.getElementById('main_banner_img').src = "logo.png"; }
            renderAll(); 
        }
    }, true);
}

function initMonthlySelectors() {
    const monthSelect = document.getElementById('report_month');
    const yearSelect = document.getElementById('report_year');
    if(!monthSelect || !yearSelect) return;
    const now = new Date();

    const monthNames = ["Tháng 1", "Tháng 2", "Tháng 3", "Tháng 4", "Tháng 5", "Tháng 6", "Tháng 7", "Tháng 8", "Tháng 9", "Tháng 10", "Tháng 11", "Tháng 12"];
    monthSelect.innerHTML = '';
    for (let i = 0; i < 12; i++) {
        let opt = document.createElement('option');
        opt.value = i + 1;
        opt.innerHTML = monthNames[i];
        if (i === now.getMonth()) opt.selected = true;
        monthSelect.appendChild(opt);
    }

    yearSelect.innerHTML = '';
    for (let i = 2024; i <= 2030; i++) {
        let opt = document.createElement('option');
        opt.value = i;
        opt.innerHTML = "Năm " + i;
        if (i === now.getFullYear()) opt.selected = true;
        yearSelect.appendChild(opt);
    }
}
document.addEventListener("DOMContentLoaded", initMonthlySelectors);

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
        const safeName = p.n ? String(p.n).replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\\n/g, ' ') : 'Unknown';
        
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
    const oldWidth = area.style.width;
    area.style.width = '600px';

    setTimeout(() => {
        html2canvas(area, { scale: 2, backgroundColor: "#12141c", useCORS: true }).then(canvas => {
            area.style.width = oldWidth;
            const base64Data = canvas.toDataURL('image/jpeg', 0.9);
            sendAPI({ action: 'save_capture', image: base64Data }, res => {
                document.getElementById('loading').style.display = 'none';
                if(res.status === 'success') {
                    let link = document.createElement('a');
                    link.href = res.url;
                    link.download = `Tong-Ket-${document.getElementById('monthly_label').innerText}.jpg`;
                    link.click();
                }
            }, true);
        });
    }, 500);
}

function logout() { sendAPI({ action: 'logout' }, () => { window.location.reload(); }); }

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
                    </tr>`;
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
    if(!box1 || !box2) return;
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
        if(scoreParts.length !== 2) { alert("❌ Tỉ số không đúng định dạng (Ví dụ: 21-19)"); return; }
        const p1 = parseInt(scoreParts[0].trim());
        const p2 = parseInt(scoreParts[1].trim());
        if(isNaN(p1) || isNaN(p2) || p1 === p2) { alert("❌ Tỉ số không hợp lệ hoặc hòa (Không có hòa)!"); return; }
        winner = p1 > p2 ? 'team1' : 'team2';
    } else {
        const winnerRadio = document.querySelector('input[name="manual_winner"]:checked');
        if (!winnerRadio) { alert("Vui lòng CHỌN ĐỘI THẮNG (nếu không nhập tỉ số)!"); return; }
        winner = winnerRadio.value;
    }

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
    document.getElementById('t1_p1').value = ""; document.getElementById('t1_p2').value = ""; 
    document.getElementById('t2_p1').value = ""; document.getElementById('t2_p2').value = "";
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
    const oldOverflow = tableResponsive ? tableResponsive.style.overflow : ''; 
    const oldWidth = captureArea.style.width;
    
    if (tableResponsive) {
        tableResponsive.style.overflow = 'visible';
    }
    captureArea.style.width = captureArea.scrollWidth + 'px';
    const captureScale = window.innerWidth <= 768 ? 1.5 : 2;

    setTimeout(() => {
        html2canvas(captureArea, { scale: captureScale, backgroundColor: "#12141c", useCORS: true, logging: false }).then(canvas => {
            if (tableResponsive) tableResponsive.style.overflow = oldOverflow;
            captureArea.style.width = oldWidth;
            if(!isDark) document.body.classList.remove('dark-mode'); 
            const base64Data = canvas.toDataURL('image/jpeg', 0.8);

            sendAPI({ action: 'save_capture', image: base64Data }, res => {
                document.getElementById('loading').style.display = 'none';
                if(res.status === 'success') {
                    let link = document.createElement('a');
                    link.href = res.url; link.download = 'Bang-Phong-Than.jpg'; link.click();
                }
            }, true);
        });
    }, 500);
}

function captureHistory() {
    document.getElementById('loading_text').innerText = "📸 Đang xử lý ảnh lịch sử..."; 
    document.getElementById('loading').style.display = 'flex';
    const isDark = document.body.classList.contains('dark-mode');
    if(!isDark) document.body.classList.add('dark-mode');

    const captureArea = document.getElementById('capture_history_area'); 
    const actions = captureArea.querySelectorAll('.card-actions');
    const pagination = document.getElementById('history_pagination');
    actions.forEach(el => el.style.display = 'none');
    if (pagination) pagination.style.display = 'none';

    const oldWidth = captureArea.style.width;
    captureArea.style.width = captureArea.scrollWidth + 'px';
    const captureScale = window.innerWidth <= 768 ? 1.5 : 2;

    setTimeout(() => {
        html2canvas(captureArea, { scale: captureScale, backgroundColor: "#12141c", useCORS: true, logging: false }).then(canvas => {
            captureArea.style.width = oldWidth;
            actions.forEach(el => el.style.display = '');
            if (pagination) pagination.style.display = 'flex';
            if(!isDark) document.body.classList.remove('dark-mode'); 
            const base64Data = canvas.toDataURL('image/jpeg', 0.8);

            sendAPI({ action: 'save_capture', image: base64Data }, res => {
                document.getElementById('loading').style.display = 'none';
                if(res.status === 'success') {
                    let link = document.createElement('a');
                    link.href = res.url; link.download = 'Lich-Su-Tran-Dau.jpg'; link.click();
                }
            }, true);
        });
    }, 500);
}

function triggerAvatarUpload(playerName) {
    if (userRole !== 'admin') return;
    currentAvatarPlayer = playerName; 
    document.getElementById('avatar_file_input').click();
}

function handleAvatarSelection(event) {
    const file = event.target.files[0]; if (!file) return;
    if (file.size > 10 * 1024 * 1024) { alert("❌ File ảnh quá nặng (vượt mức 10MB)!"); return; }

    document.getElementById('loading_text').innerText = "📸 Đang nén & tối ưu ảnh..."; 
    document.getElementById('loading').style.display = 'flex';
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
            ctx.fillStyle = "#12141c"; ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, width, height);
            const base64String = canvas.toDataURL('image/webp', 0.85); 
            
            sendAPI({ action: 'upload_avatar', name: currentAvatarPlayer, image: base64String }, () => { 
                document.getElementById('avatar_file_input').value = ""; 
                fetchMediaFromServer(); 
            }, false);
        }
        img.src = e.target.result;
    }
    reader.readAsDataURL(file);
}

function handleBannerSelection(event) {
    const file = event.target.files[0]; if (!file) return;
    if (file.size > 10 * 1024 * 1024) { alert("❌ File ảnh bìa quá nặng!"); return; }

    document.getElementById('loading_text').innerText = "📸 Đang nén Banner..."; 
    document.getElementById('loading').style.display = 'flex';
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
            
            sendAPI({ action: 'upload_banner', image: base64String }, () => { 
                document.getElementById('banner_file_input').value = ""; 
                fetchMediaFromServer(); 
            }, false);
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
        const input = document.getElementById(id);
        if(!input) return;
        const suggestionBox = document.createElement('div');
        suggestionBox.className = 'custom-autocomplete-box';
        input.parentNode.appendChild(suggestionBox);

        input.addEventListener('input', function() { showSuggestions(this, suggestionBox); });
        input.addEventListener('focus', function() { showSuggestions(this, suggestionBox); });
        input.addEventListener('blur', checkHeadToHead);
        
        document.addEventListener('click', function(e) {
            if (e.target !== input && !suggestionBox.contains(e.target)) suggestionBox.style.display = 'none';
        });
    });
});

function showSuggestions(input, box) {
    const val = input.value.toLowerCase().trim();
    const matches = allPlayerNames.filter(n => n.toLowerCase().includes(val));
    if (matches.length === 0) { box.style.display = 'none'; return; }

    box.innerHTML = '';
    matches.forEach(name => {
        const item = document.createElement('div');
        item.className = 'custom-suggestion-item';
        let avaHtml = playerAvatars[name] ? `<img src="${playerAvatars[name]}" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:8px;object-fit:cover;">` : `👤 `;
        item.innerHTML = avaHtml + name;
        item.onclick = function() {
            input.value = name; box.style.display = 'none'; checkHeadToHead();
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
        
        if(document.getElementById('dashboard_date_display')) document.getElementById('dashboard_date_display').innerText = dateText; 
        if(document.getElementById('history_date_display')) document.getElementById('history_date_display').innerText = dateText; 
        if(document.getElementById('stats_date_display')) document.getElementById('stats_date_display').innerText = "(Toàn thời gian)";

        const filtered = matches.filter(m => m.date && m.date >= d1 && m.date <= d2);
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
                    if(pts.length >= 2) { t1_score += parseInt(pts[0].trim()) || 0; t2_score += parseInt(pts[1].trim()) || 0; }
                });
            }
            team1.forEach(p => { filteredPlayers[p].h += (t1_score - t2_score); });
            team2.forEach(p => { filteredPlayers[p].h += (t2_score - t1_score); });
            wT.forEach(p => { filteredPlayers[p].m++; filteredPlayers[p].w++; filteredPlayers[p].p += m.bet; filteredPlayers[p].winPoints += m.bet; filteredPlayers[p].waterBalance += waterBet; });
            lT.forEach(p => { filteredPlayers[p].m++; filteredPlayers[p].l++; filteredPlayers[p].p -= m.bet; filteredPlayers[p].losePoints += m.bet; filteredPlayers[p].waterBalance -= waterBet; });
        });
        
        const arrFiltered = Object.keys(filteredPlayers).map(n => ({ n, ...filteredPlayers[n] })).sort((a, b) => b.p - a.p || b.h - a.h);
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
                sets.forEach(s => { const pts = s.split('-'); if(pts.length >= 2) { t1_score += parseInt(pts[0].trim()) || 0; t2_score += parseInt(pts[1].trim()) || 0; } });
            }
            team1.forEach(p => { allTimePlayers[p].h += (t1_score - t2_score); });
            team2.forEach(p => { allTimePlayers[p].h += (t2_score - t1_score); });
            wT.forEach(p => { allTimePlayers[p].m++; allTimePlayers[p].w++; allTimePlayers[p].p += m.bet; allTimePlayers[p].winPoints += m.bet; allTimePlayers[p].waterBalance += waterBet; if(isDub) allTimePlayers[p].w_dub++; });
            lT.forEach(p => { allTimePlayers[p].m++; allTimePlayers[p].l++; allTimePlayers[p].p -= m.bet; allTimePlayers[p].losePoints += m.bet; allTimePlayers[p].waterBalance -= waterBet; });
        });
        
        const arrAllTime = Object.keys(allTimePlayers).map(n => ({ n, ...allTimePlayers[n] })).sort((a, b) => b.p - a.p || b.h - a.h);
        updatePlayerDatalist(arrAllTime);

        let playerStreaks = {};
        matches.forEach(m => {
            const wT = m.winner === 'team1' ? (Array.isArray(m.team1)?m.team1:[]) : (m.winner === 'team2' ? (Array.isArray(m.team2)?m.team2:[]) : []);
            const lT = m.winner === 'team1' ? (Array.isArray(m.team2)?m.team2:[]) : (m.winner === 'team2' ? (Array.isArray(m.team1)?m.team1:[]) : []);
            wT.filter(Boolean).forEach(p => { if(!playerStreaks[p] || playerStreaks[p].type !== 'W') playerStreaks[p] = { type: 'W', count: 1 }; else playerStreaks[p].count++; });
            lT.filter(Boolean).forEach(p => { if(!playerStreaks[p] || playerStreaks[p].type !== 'L') playerStreaks[p] = { type: 'L', count: 1 }; else playerStreaks[p].count++; });
        });

        const tbody = document.getElementById('dashboard_body'); 
        if(tbody) {
            tbody.innerHTML = '';
            if(arrFiltered.length===0) { tbody.innerHTML = '<tr><td colspan="9" style="color: var(--text-muted);font-style:italic;">Chưa có dữ liệu</td></tr>'; }
            else {
                arrFiltered.forEach((p, i) => {
                    let rClass = i === 0 ? 'row-top1' : (i === 1 ? 'row-top2' : (i === 2 ? 'row-top3' : ''));
                    let isBot = false;
                    if (arrFiltered.length >= 4) {
                        if (i === arrFiltered.length - 1) { rClass = 'row-bot1'; isBot = true; }
                        else if (i === arrFiltered.length - 2) { rClass = 'row-bot2'; isBot = true; }
                        else if (i === arrFiltered.length - 3) { rClass = 'row-bot3'; isBot = true; }
                    }
                    let titleBadge = i === 0 && p.p > 0 ? `<span class="badge-title title-top1">👑 Kẻ Huỷ Diệt</span>` : ((i===1||i===2)&&p.p>0?`<span class="badge-title title-top23">⚔️ Cao Thủ</span>`:'');
                    if (isBot && p.p < 0) titleBadge += ` <span class="badge-title title-bot">🐆 Vua Báo Thủ</span>`;
                    if (waterLosers.includes(p.n)) titleBadge += ` <span class="badge-title title-water">🥤 Thần Nước</span>`;
                    
                    let streakHtml = playerStreaks[p.n] && playerStreaks[p.n].count >= 3 ? `<span class="streak-badge streak-${playerStreaks[p.n].type==='W'?'win':'lose'}">:${playerStreaks[p.n].type==='W'?'🔥':'🥶'} x${playerStreaks[p.n].count}</span>` : '';
                    let waterText = p.waterBalance > 0 ? '+' + p.waterBalance : p.waterBalance;
                    let hText = p.h > 0 ? '+' + p.h : p.h; 
                    
                    tbody.innerHTML += `<tr class="${rClass}"><td><strong>${i+1}</strong></td><td style="text-align:left;"><strong>${p.n}</strong> ${streakHtml}<br>${titleBadge}</td><td>${p.m}</td><td>${p.w}</td><td>${p.l}</td><td>${waterText}</td><td><strong>${hText}</strong></td><td style="font-size:18px;"><strong>${p.p>0?'+'+p.p:p.p}</strong></td><td style="font-weight:bold; color:var(--primary);">${Math.round((p.m>0?p.w/p.m:0)*100)}%</td></tr>`;
                });
            }
        }

        currentFilteredMatches = [...filtered];
        uniqueHistoryDates = [...new Set(currentFilteredMatches.map(m => m.date))].sort((a, b) => new Date(b) - new Date(a));
        renderHistoryGrid();

        const renderPodium = (arr, type) => {
            if(!arr || arr.length === 0) return `<p style="text-align:center;width:100%;color:var(--text-muted);font-style:italic;">Chưa có dữ liệu</p>`;
            let clsPrefix = type === 'bot' ? 'podium-bot' : (type === 'water' ? 'podium-water' : 'podium');
            const p1 = arr[0], p2 = arr[1], p3 = arr[2];
            let html = '';
            if (p2) html += `<div class="podium-box ${clsPrefix}-2"><div class="podium-avatar">${renderAvatarHTML(p2.n, true)}</div><div class="podium-name">${p2.n}</div><div class="podium-score">${type==='water'?p2.waterBalance:(p2.p>0?'+'+p2.p:p2.p)}</div></div>`;
            if (p1) html += `<div class="podium-box ${clsPrefix}-1"><div class="podium-avatar">${renderAvatarHTML(p1.n, true)}</div><div class="podium-name">${p1.n}</div><div class="podium-score">${type==='water'?p1.waterBalance:(p1.p>0?'+'+p1.p:p1.p)}</div></div>`;
            if (p3) html += `<div class="podium-box ${clsPrefix}-3"><div class="podium-avatar">${renderAvatarHTML(p3.n, true)}</div><div class="podium-name">${p3.n}</div><div class="podium-score">${type==='water'?p3.waterBalance:(p3.p>0?'+'+p3.p:p3.p)}</div></div>`;
            return html;
        };
        
        if(document.getElementById('top3_podium')) document.getElementById('top3_podium').innerHTML = renderPodium(arrFiltered.slice(0, 3), 'top');
        if(document.getElementById('bottom3_podium')) document.getElementById('bottom3_podium').innerHTML = renderPodium([...arrFiltered].reverse().slice(0, 3), 'bot');
        if(document.getElementById('water3_podium')) document.getElementById('water3_podium').innerHTML = renderPodium([...arrFiltered].filter(p => p.waterBalance < 0).sort((a,b)=>a.waterBalance-b.waterBalance).slice(0,3), 'water');

        const statsContainer = document.getElementById('stats_container'); 
        if(statsContainer) {
            statsContainer.innerHTML = '';
            if (arrAllTime.length === 0) {
                statsContainer.innerHTML = '<div style="text-align:center; width:100%;">Chưa có dữ liệu thống kê.</div>';
            } else {
                let statsHTML = ''; const maxM = Math.max(...arrAllTime.map(x => x.m), 1); const maxWin = Math.max(...arrAllTime.map(x => x.winPoints), 1);
                arrAllTime.forEach(p => {
                    const winRate = p.m > 0 ? Math.round((p.w / p.m) * 100) : 0;
                    let avaHtml = playerAvatars[p.n] ? `<img src="${playerAvatars[p.n]}">` : String(p.n).charAt(0).toUpperCase();
                    const safeName = String(p.n).replace(/'/g, "\'").replace(/"/g, '&quot;');
                    const overlayHtml = userRole === 'admin' ? `<div class="upload-overlay">📷 Đổi</div>` : '';
                    statsHTML += `
                    <div class="stat-card" style="cursor: pointer;" onclick="openFutCardModal('${safeName}', ${p.m}, ${p.w}, ${p.p}, ${p.h}, ${p.waterBalance}, ${p.m_dub||0}, ${p.w_dub||0}, ${p.winPoints}, ${maxM}, ${maxWin})">
                        <div class="stat-avatar" onclick="event.stopPropagation(); triggerAvatarUpload('${safeName}')">${avaHtml}${overlayHtml}</div>
                        <div class="stat-info">
                            <h3 class="stat-name">${p.n}</h3>
                            <div class="stat-details-grid">
                                <div class="stat-box"><span class="stat-label">Số trận</span><span class="stat-val" style="color: #f1c40f;">${p.m}</span></div>
                                <div class="stat-box"><span class="stat-label">Tỉ lệ thắng</span><span class="stat-val" style="color: #e67e22;">${winRate}%</span></div>
                                <div class="stat-box"><span class="stat-label">Tổng Điểm</span><span class="stat-val" style="color: #27ae60;">${p.p}</span></div>
                                <div class="stat-box"><span class="stat-label">Nước</span><span class="stat-val" style="color: #3498db;">${p.waterBalance}</span></div>
                                <div class="stat-box"><span class="stat-label">Hiệu số</span><span class="stat-val" style="color: #e74c3c;">${p.h>0?'+'+p.h:p.h}</span></div>
                                <div class="stat-box"><span class="stat-label">T/B Trận</span><span class="stat-val" style="color: #9b59b6;">${p.w}/${p.l}</span></div>
                            </div>
                        </div>
                    </div>`;
                });
                statsContainer.innerHTML = statsHTML;
            }
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
    const grid = document.getElementById('history_grid'); if(!grid) return; grid.innerHTML = '';
    const paginationDiv = document.getElementById('history_pagination');
    if (!uniqueHistoryDates || uniqueHistoryDates.length === 0) {
        if(paginationDiv) paginationDiv.style.display = 'none';
        grid.innerHTML = '<p style="text-align:center;width:100%;">Không có trận đấu nào.</p>'; return;
    }
    if(paginationDiv) paginationDiv.style.display = 'flex';
    document.getElementById('btn_prev_date').disabled = currentHistoryDateIndex >= uniqueHistoryDates.length - 1; 
    document.getElementById('btn_next_date').disabled = currentHistoryDateIndex <= 0; 

    const currentDateStr = uniqueHistoryDates[currentHistoryDateIndex];
    const formatDate = (dateStr) => { const parts = String(dateStr).split('-'); return parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : dateStr; };
    document.getElementById('current_history_date_label').innerText = "📅 Ngày " + formatDate(currentDateStr);

    const matchesForDate = currentFilteredMatches.filter(m => m.date === currentDateStr);
    matchesForDate.forEach(m => {
        const w1 = m.winner === 'team1'; const stt = currentFilteredMatches.indexOf(m) + 1;
        const shortTime = m.time ? String(m.time).substring(0, 5) : '--:--';
        const getAva = n => playerAvatars[n] ? `<img src="${playerAvatars[n]}">` : String(n).charAt(0).toUpperCase();
        const team1 = m.team1 || [], team2 = m.team2 || [];

        grid.innerHTML += `
        <div class="match-card-v2">
            <div class="match-info-header">TRẬN #${stt} | 🕒 ${shortTime}</div>
            <div class="match-battle-area">
                <div class="team-col ${w1 ? 'winner' : 'loser'}">
                    ${w1 ? '<div class="stamp win">WIN</div>' : '<div class="stamp lose">LOSE</div>'}
                    <div class="avatar-split-container ${!team1[1] ? 'single-mode' : ''}">
                        <div class="ava-p1">${getAva(team1[0])}</div>
                        ${team1[1] ? `<div class="diagonal-line"></div><div class="ava-p2">${getAva(team1[1])}</div>` : ''}
                    </div>
                    <div class="team-names-v2">${team1[0]} ${team1[1]?`<br>${team1[1]}`:''}</div>
                </div>
                <div class="vs-col">
                    <div class="vs-fire-text">VS</div>
                    <div class="bet-badge">Cược: ${m.bet} Đ</div>
                    ${m.water > 0 ? `<div class="bet-badge" style="background:var(--secondary); color:#fff;">🥤 Nước: ${m.water}</div>` : ''}
                    ${m.score ? `<div class="bet-badge score-badge">🎯 ${m.score}</div>` : ''}
                </div>
                <div class="team-col ${!w1 ? 'winner' : 'loser'}">
                    ${!w1 ? '<div class="stamp win">WIN</div>' : '<div class="stamp lose">LOSE</div>'}
                    <div class="avatar-split-container ${!team2[1] ? 'single-mode' : ''}">
                        <div class="ava-p1">${getAva(team2[0])}</div>
                        ${team2[1] ? `<div class="diagonal-line"></div><div class="ava-p2">${getAva(team2[1])}</div>` : ''}
                    </div>
                    <div class="team-names-v2">${team2[0]} ${team2[1]?`<br>${team2[1]}`:''}</div>
                </div>
            </div>
            ${userRole === 'admin' ? `<div class="card-actions"><button class="btn-outline" onclick="editMatch(${m.id})">✏️ Sửa</button><button class="btn-danger" onclick="deleteMatch(${m.id})">🗑️ Xóa</button></div>` : ''}
        </div>`;
    });
}

function openFutCardModal(name, m, w, p, h, water, m_dub, w_dub, winPoints, maxM, maxWin) {
    currentCardName = name;
    const winRate = m > 0 ? Math.round((w / m) * 100) : 0;
    const act = m > 0 ? Math.round((m / maxM) * 100) : 0;
    const bet = Math.round((winPoints / maxWin) * 100);
    let gapScore = Math.max(0, Math.min(100, Math.round(50 + ((m > 0 ? h / m : 0) * 5)))); 
    const dubRate = m_dub > 0 ? Math.round((w_dub / m_dub) * 100) : 0;
    const ovr = Math.round((winRate + act + bet + gapScore + dubRate + Math.max(0, Math.min(100, Math.round(50 + (water * 2))))) / 6);

    let theme = "card-silver"; let pos = "TÂN BINH";
    if(ovr >= 80 || p > 20) { theme = "card-vip"; pos = "GÁNH TEAM"; }
    else if(ovr >= 65 || p > 5) { theme = "card-gold"; pos = "SÁT THỦ"; }
    else if(water < -10) { theme = "card-red"; pos = "BÁO THỦ"; }

    document.getElementById('fut_name').innerText = name;
    document.getElementById('fut_ovr').innerText = ovr;
    document.getElementById('fut_pos').innerText = pos;
    document.getElementById('player_fut_card').className = "fut-card " + theme;
    document.getElementById('fut_match').innerText = m;
    document.getElementById('fut_winrate').innerText = winRate;
    document.getElementById('fut_wl').innerText = `${w}/${m-w}`;
    document.getElementById('fut_point').innerText = p > 0 ? '+' + p : p;
    document.getElementById('fut_water').innerText = water > 0 ? '+' + water : water;
    document.getElementById('fut_h').innerText = h > 0 ? '+' + h : h;
    
    document.getElementById('fut_point').style.color = p < 0 ? "#ff4757" : (p > 0 ? "#2ecc71" : "#fff");
    document.getElementById('fut_water').style.color = water < 0 ? "#ff4757" : (water > 0 ? "#3498db" : "#fff");

    document.getElementById('fut_avatar').innerHTML = playerAvatars[name] ? `<img src="${playerAvatars[name]}">` : `<div style="font-size:80px; font-weight:900; color:rgba(255,255,255,0.5);">${name.charAt(0).toUpperCase()}</div>`;

    const ctx = document.getElementById('radarChart').getContext('2d');
    if(myRadarChart) myRadarChart.destroy();
    let borderColors = { 'card-vip': '#00f2fe', 'card-gold': '#f1c40f', 'card-red': '#ff4757', 'card-bronze': '#e67e22', 'card-silver': '#bdc3c7' };

    myRadarChart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: ['Overall', ''], datasets: [{ data: [ovr, 100 - ovr], backgroundColor: [borderColors[theme] || '#fff', 'rgba(255,255,255,0.1)'], borderWidth: 0 }] },
        options: { animation: false, responsive: true, plugins: { legend: { display: false }, tooltip: { enabled: false } } },
        plugins: [{
            id: 'textCenter',
            beforeDraw: function(chart) {
                let w = chart.width, height = chart.height, c = chart.ctx;
                c.restore(); c.font = "900 " + (height / 80).toFixed(2) + "em sans-serif";
                c.textBaseline = "middle"; c.fillStyle = "#fff";
                c.fillText(ovr.toString(), Math.round((w - c.measureText(ovr.toString()).width) / 2), height / 2); c.save();
            }
        }]
    });
    document.getElementById('stats_modal').style.display = "flex";
}

function closeStatsModal() { document.getElementById('stats_modal').style.display = "none"; }
function downloadSingleCard() {
    const area = document.getElementById('capture_card_area');
    document.getElementById('loading_text').innerText = "📸 Đang tạo ảnh thẻ bài 3D...";
    document.getElementById('loading').style.display = 'flex';
    setTimeout(() => {
        html2canvas(area, { scale: 3, backgroundColor: null, useCORS: true }).then(canvas => {
            let link = document.createElement('a'); link.download = `The-Bai-${currentCardName}.png`; link.href = canvas.toDataURL('image/png'); link.click();
            document.getElementById('loading').style.display = 'none';
        });
    }, 500);
}

window.onclick = function(e) { if (e.target == document.getElementById('stats_modal')) closeStatsModal(); }

function toggleMatchType() {
    const isDon = document.querySelector('input[name="match_type"]:checked').value === 'don';
    document.getElementById('t1_p2').style.display = isDon ? 'none' : 'block';
    document.getElementById('t2_p2').style.display = isDon ? 'none' : 'block';
    if(isDon) { document.getElementById('t1_p2').value = ''; document.getElementById('t2_p2').value = ''; }
}

function checkHeadToHead() {
    const t1 = [document.getElementById('t1_p1').value.trim(), document.getElementById('t1_p2').value.trim()].filter(Boolean).sort().join(' & ');
    const t2 = [document.getElementById('t2_p1').value.trim(), document.getElementById('t2_p2').value.trim()].filter(Boolean).sort().join(' & ');
    const h2hBox = document.getElementById('h2h_result');
    if(!h2hBox || !t1 || !t2) { if(h2hBox) h2hBox.style.display = 'none'; return; }
    
    let t1Wins = 0, t2Wins = 0;
    matches.forEach(m => {
        const mt1 = (m.team1 || []).filter(Boolean).sort().join(' & ');
        const mt2 = (m.team2 || []).filter(Boolean).sort().join(' & ');
        if ((mt1 === t1 && mt2 === t2) || (mt1 === t2 && mt2 === t1)) {
            if (m.winner === 'team1') (mt1 === t1) ? t1Wins++ : t2Wins++;
            else if (m.winner === 'team2') (mt1 === t1) ? t2Wins++ : t1Wins++;
        }
    });
    h2hBox.style.display = 'block';
    h2hBox.innerHTML = (t1Wins > 0 || t2Wins > 0) ? `⚔️ H2H: [${t1Wins}] - [${t2Wins}]` : `⚔️ Lần đầu chạm trán`;
}

function autoMatchmake() {
    const inputs = [document.getElementById('t1_p1'), document.getElementById('t1_p2'), document.getElementById('t2_p1'), document.getElementById('t2_p2')];
    const players = inputs.map(i => i.value.trim()).filter(Boolean);
    if(players.length < 4) { alert("❌ Cần đủ 4 người để xếp đội!"); return; }

    // Dùng điểm số xếp hạng hiện tại để cân bằng kèo
    const getScore = name => { 
        let found = matches.filter(m => (m.team1||[]).includes(name) || (m.team2||[]).includes(name));
        return found.length; // Trả về số trận tạm làm mốc sức mạnh
    };
    
    inputs[0].value = players[0]; inputs[1].value = players[3];
    inputs[2].value = players[1]; inputs[3].value = players[2];
    checkHeadToHead();
}

function submitChangePassword() {
    const p1 = document.getElementById('cp_new_pass').value;
    const p2 = document.getElementById('cp_confirm_pass').value;
    if(!p1 || !p2) { alert("Vui lòng nhập mật khẩu!"); return; }
    if(p1 !== p2) { alert("❌ Mật khẩu không khớp!"); return; }
    
    sendAPI({ action: 'change_password', new_password: p1 }, res => {
        if(res.status === 'success') {
            alert("✅ Đổi mật khẩu thành công!");
            document.getElementById('change_pass_modal').style.display = 'none';
        }
    });
}

// SPEECH RECOGNITION (GIỮ ĐỂ NÓI)
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
let recognition; let isRecording = false;
if (SpeechRecognition) {
    recognition = new SpeechRecognition(); recognition.lang = 'vi-VN'; recognition.continuous = true;
    recognition.onresult = function(e) {
        let text = ""; for (let i = e.resultIndex; i < e.results.length; ++i) { text += e.results[i][0].transcript; }
        parseVoiceToMatch(text.toLowerCase());
    };
}

const btnVoice = document.getElementById('btn_voice_input');
if(btnVoice && recognition) {
    const startHold = (e) => { e.preventDefault(); recognition.start(); isRecording = true; btnVoice.innerHTML = '🎙️ Đang nghe...'; };
    const stopHold = (e) => { e.preventDefault(); if(isRecording) { recognition.stop(); isRecording = false; btnVoice.innerHTML = '🎤 Nhấn Giữ Để Nói'; } };
    btnVoice.addEventListener('mousedown', startHold); btnVoice.addEventListener('mouseup', stopHold);
    btnVoice.addEventListener('touchstart', startHold); btnVoice.addEventListener('touchend', stopHold);
}

function parseVoiceToMatch(text) {
    let betMatch = text.match(/(độ|cược|điểm)\s*(\d+)/);
    if(betMatch) document.getElementById('bet_amount').value = betMatch[2];
    let waterMatch = text.match(/nước\s*(\d+)/);
    if(waterMatch) document.getElementById('water_amount').value = waterMatch[2];

    let splitters = [' đấu với ', ' đánh với ', ' gặp ', ' vs '];
    for (let s of splitters) {
        if (text.includes(s)) {
            let teams = text.split(s);
            let p1 = teams[0].split(' và '), p2 = teams[1].split(' và ');
            document.getElementById('t1_p1').value = formatName(p1[0]||'');
            document.getElementById('t1_p2').value = formatName(p1[1]||'');
            document.getElementById('t2_p1').value = formatName(p2[0]||'');
            document.getElementById('t2_p2').value = formatName(p2[1]||'');
            break;
        }
    }
    highlightManualWinner(); checkHeadToHead();
}