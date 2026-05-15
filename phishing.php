<?php
/**
 * -------------------------------------------------------------------
 * 1. 後端 PHP：資料處理與統計 (phishing.php)
 * -------------------------------------------------------------------
 */
ini_set('memory_limit', '512M');
set_time_limit(60);

// 載入 .env 環境變數
if (file_exists(__DIR__ . '/.env')) {
    foreach (parse_ini_file(__DIR__ . '/.env') ?: [] as $key => $value) {
        putenv("$key=$value");
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');

    $start    = $_GET['start'] ?? '';
    $end      = $_GET['end'] ?? '';
    $tagName  = $_GET['tagName'] ?? 'Department'; // 預設使用 Department

    $apiUrl = "https://results.us.securityeducation.com/api/reporting/v0.3.0/phishing";
    $params = ['page[size]' => '5000']; 
    if ($start) $params["filter[_campaignstartdate_start]"] = "'$start'";
    if ($end)   $params["filter[_campaignstartdate_end]"]   = "'$end'";

    $fullUrl = $apiUrl . "?" . http_build_query($params) . "&user_tag_enable";
    // 優先讀取環境變數，若無則使用硬編碼金鑰作為備援
    $apiKey = getenv('PHISHING_API_KEY') ?: "6VPasCLKwgHttewO/JgX9wfFI9WdfvDFQpBmteS6E5RVccwZ3";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["x-apikey-token: $apiKey", "Accept: application/json"]);
    $response = curl_exec($ch);
    curl_close($ch);

    $rawData = json_decode($response, true);
    $events = $rawData['data'] ?? [];

    // --- 資料統計變數 ---
    $campaigns = [];
    $users_status = []; // 用於去重統計帳號
    $dept_data = [];
    $campaign_stats_raw = []; // 用於儲存範本統計原始資料

    //取得前端傳來的屬性名稱
    $targetTag = $_GET['tagName'] ?? ''; 

    foreach ($events as $item) {
    $attr = $item['attributes'];
    $email = $attr['useremailaddress'];
    $type = $attr['eventtype'];
    $cName = $attr['campaignname'] ?? 'N/A';
    $tSubject = $attr['templatesubject'] ?? 'N/A';
    
    // --- 2. 嚴格解析指定的屬性值 ---
    $currentTagValue = null; 
    if ($targetTag && isset($attr['usertags'][$targetTag])) {
        $tagData = $attr['usertags'][$targetTag];
        // 處理陣列格式，例如 ["PM"]
        $currentTagValue = is_array($tagData) ? ($tagData[0] ?? null) : $tagData;
    }

    // 如果沒有對應的屬性值，給予預設值「未分類」而非直接跳過，確保資料能載入
    if ($currentTagValue === null || $currentTagValue === '') {
        $currentTagValue = '未分類';
    }

    // --- 3. 帳號狀態追蹤 (僅針對有屬性值的帳號) ---
    if (!isset($users_status[$email])) {
        $users_status[$email] = [
            "View" => 0, "Click" => 0, "Attach" => 0, "Input" => 0, 
            "dept" => $currentTagValue // 這裡存放的就是 Department 的值
        ];
    }
    
    // 更新行為狀態
    if ($type === "Email View")      $users_status[$email]["View"] = 1;
    if ($type === "Email Click")     $users_status[$email]["Click"] = 1;
    if ($type === "Attachment Open") $users_status[$email]["Attach"] = 1;
    if ($type === "Data Submission") $users_status[$email]["Input"] = 1;

    // 建立活動表格資料 (此部分維持原樣)
    $cName = $attr['campaignname'] ?? 'N/A';
    if (!isset($campaigns[$cName])) {
        $campaigns[$cName] = [
            "name" => $cName,
            "type" => $attr['campaigntype'] ?? 'N/A',
            "subject" => $tSubject
        ];
    }

    // --- 4. 範本統計累加 ---
    if (!isset($campaign_stats_raw[$cName])) {
        $campaign_stats_raw[$cName] = [
            "name" => $cName,
            "subject" => $tSubject,
            "users" => []
        ];
    }
    if (!isset($campaign_stats_raw[$cName]["users"][$email])) {
        $campaign_stats_raw[$cName]["users"][$email] = ["Click" => 0, "Attach" => 0, "Input" => 0];
    }
    if ($type === "Email Click")     $campaign_stats_raw[$cName]["users"][$email]["Click"] = 1;
    if ($type === "Attachment Open") $campaign_stats_raw[$cName]["users"][$email]["Attach"] = 1;
    if ($type === "Data Submission") $campaign_stats_raw[$cName]["users"][$email]["Input"] = 1;
}

    // --- 計算最終統計 ---
    $totalUsers = count($users_status);
    $counts = ["View" => 0, "Click" => 0, "Attach" => 0, "Input" => 0];

    foreach ($users_status as $u) {
        if ($u["View"])   $counts["View"]++;
        if ($u["Click"])  $counts["Click"]++;
        if ($u["Attach"]) $counts["Attach"]++;
        if ($u["Input"])  $counts["Input"]++;

        // 部門統計
        $d = $u["dept"];
        if (!isset($dept_data[$d])) {
            $dept_data[$d] = ["Total" => 0, "Click" => 0, "Attach" => 0, "Input" => 0];
        }
        $dept_data[$d]["Total"]++;
        if ($u["Click"])  $dept_data[$d]["Click"]++;
        if ($u["Attach"]) $dept_data[$d]["Attach"]++;
        if ($u["Input"])  $dept_data[$d]["Input"]++;
    }

    // 格式化長條圖數據 (按部門排序)
    ksort($dept_data);
    $formatted_depts = [];
    foreach ($dept_data as $name => $v) {
        $total = $v["Total"] ?: 1;
        $formatted_depts[] = [
            "name" => $name,
            "click_rate" => round(($v["Click"] / $total) * 100, 1),
            "attach_rate" => round(($v["Attach"] / $total) * 100, 1),
            "input_rate" => round(($v["Input"] / $total) * 100, 1)
        ];
    }

    // 格式化範本統計數據 (將原始資料加總並計算比率)
    $template_stats = [];
    foreach ($campaign_stats_raw as $c) {
        $total = count($c["users"]);
        $clicks = 0; $attach = 0; $input = 0;
        foreach ($c["users"] as $u) {
            if ($u["Click"]) $clicks++;
            if ($u["Attach"]) $attach++;
            if ($u["Input"]) $input++;
        }
        $template_stats[] = [
            "name" => $c["name"],
            "subject" => $c["subject"],
            "total" => $total,
            "click_cnt" => $clicks, "click_rate" => $total > 0 ? round(($clicks / $total) * 100, 1) : 0,
            "attach_cnt" => $attach, "attach_rate" => $total > 0 ? round(($attach / $total) * 100, 1) : 0,
            "input_cnt" => $input, "input_rate" => $total > 0 ? round(($input / $total) * 100, 1) : 0
        ];
    }

    echo json_encode([
        "table" => array_values($campaigns),
        "total" => $totalUsers,
        "counts" => $counts,
        "template_stats" => $template_stats,
        "dept_stats" => $formatted_depts
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>演練成效分析報表</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <!-- 補上長條圖基準線需要的外掛程式 -->
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.1.0"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <style>
        body { font-family: "Microsoft JhengHei", sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: auto; }
        .card { background: #fff; padding: 20px; margin-bottom: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2, h3 { color: #004481; border-left: 5px solid #004481; padding-left: 10px; }
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .filter-row div { flex: 1; min-width: 150px; }
        input, button { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #ddd; box-sizing: border-box; }
        button { background: #004481; color: white; border: none; cursor: pointer; font-weight: bold; }
        
        /* 表格樣式 */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #f8f9fa; }

        /* 圖表佈局 */
        .pie-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; text-align: center; margin: 20px 0; }
        .bar-grid { display: grid; grid-template-columns: repeat(1, 1fr); gap: 30px; margin-top: 20px; }
        .chart-container { position: relative; background: #fff; padding: 10px; border-radius: 8px; }
        .chart-label { margin-top: 10px; font-weight: bold; font-size: 0.9em; margin-bottom: 10px; }
        
        /* 標題與按鈕 */
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .card-header h3 { margin: 0; }
        .btn-export { width: auto; padding: 5px 15px; font-size: 0.8em; background: #28a745; margin-left: 10px; }
    </style>
</head>
<body>

<div class="container">
    <h2>社交工程演練報表系統</h2>

    <div class="card">
        <div class="filter-row">
            <div><label>開始日期</label><input type="date" id="start" value="2026-01-01"></div>
            <div><label>結束日期</label><input type="date" id="end" value="2026-03-31"></div>
            <div><label>屬性名稱</label><input type="text" id="tagName" value="Department"></div>
            <div><button onclick="loadData()">更新報表</button></div>
        </div>
    </div>

    <div class="card" id="tableSection">
        <div class="card-header">
            <h3>活動與範本資訊</h3>
            <button class="btn-export" data-html2canvas-ignore onclick="exportTableAsImage('tableSection', '活動資訊')">匯出圖片</button>
        </div>
        <table id="campaignTable">
            <thead>
                <tr><th>活動名稱</th><th>範本類別</th><th>範本主題</th></tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div class="card" id="templateSection">
        <div class="card-header">
            <h3>演練結果依範本統計</h3>
            <button class="btn-export" data-html2canvas-ignore onclick="exportTableAsImage('templateSection', '範本統計結果')">匯出圖片</button>
        </div>
        <table id="templateStatsTable">
            <thead>
                <tr>
                    <th>活動名稱</th>
                    <th>範本主題</th>
                    <th>帳號數量</th>
                    <th>點閱連結</th>
                    <th>開啟附件</th>
                    <th>釣魚輸入</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>各項指標帳號數比率 (總帳號數: <span id="totalTxt">0</span>)</h3>
        </div>
        <div class="pie-grid">
            <div class="chart-container">
                <div class="chart-label">信件開啟比率 <button class="btn-export" data-html2canvas-ignore onclick="exportChart('pieView', '信件開啟比率')">匯出</button></div>
                <canvas id="pieView"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-label">點閱連結比率 <button class="btn-export" data-html2canvas-ignore onclick="exportChart('pieClick', '點閱連結比率')">匯出</button></div>
                <canvas id="pieClick"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-label">開啟附件比率 <button class="btn-export" data-html2canvas-ignore onclick="exportChart('pieAttach', '開啟附件比率')">匯出</button></div>
                <canvas id="pieAttach"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-label">釣魚輸入比率 <button class="btn-export" data-html2canvas-ignore onclick="exportChart('pieInput', '釣魚輸入比率')">匯出</button></div>
                <canvas id="pieInput"></canvas>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>部門比率分析</h3></div>
        <div class="bar-grid">
            <div class="chart-container">
                <div class="chart-label">點擊連結比率 <button class="btn-export" data-html2canvas-ignore onclick="exportChart('barClick', '部門點擊比率')">匯出</button></div>
                <canvas id="barClick"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-label">開啟附件比率 <button class="btn-export" data-html2canvas-ignore onclick="exportChart('barAttach', '部門附件比率')">匯出</button></div>
                <canvas id="barAttach"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-label">輸入資料比率 <button class="btn-export" data-html2canvas-ignore onclick="exportChart('barInput', '部門輸入比率')">匯出</button></div>
                <canvas id="barInput"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
let chartInstances = {};
let currentData = null;

// 註冊外掛程式，用於顯示圖表上的數值標籤
Chart.register(ChartDataLabels);
Chart.register(window['chartjs-plugin-annotation']);

async function loadData() {
    const q = new URLSearchParams({
        action: 'get_data',
        start: document.getElementById('start').value,
        end: document.getElementById('end').value,
        tagName: document.getElementById('tagName').value
    });

    let data;
    try {
        const res = await fetch(`phishing.php?${q}`);
        data = await res.json();
    } catch (e) {
        console.error("資料載入失敗:", e);
        alert("無法讀取資料，請檢查網路連線或 API 金鑰設定。");
        return;
    }
    currentData = data;

    document.getElementById('totalTxt').innerText = data.total;

    // --- 更新表格 ---
    const tbody = document.querySelector('#campaignTable tbody');
    tbody.innerHTML = data.table.map(c => 
        `<tr><td>${c.name}</td><td>${c.type}</td><td>${c.subject}</td></tr>`
    ).join('');

    // --- 更新範本統計表格 ---
    const tStatsBody = document.querySelector('#templateStatsTable tbody');
    tStatsBody.innerHTML = data.template_stats.map(s => `
        <tr>
            <td>${s.name}</td>
            <td>${s.subject}</td>
            <td>${s.total}</td>
            <td>${s.click_cnt} (${s.click_rate}%)</td>
            <td>${s.attach_cnt} (${s.attach_rate}%)</td>
            <td>${s.input_cnt} (${s.input_rate}%)</td>
        </tr>
    `).join('');

    // --- 更新圓餅圖 (參考 image_cbf21e.png) ---
    const pieSet = [
        { id: 'pieView', count: data.counts.View, label: '開啟信件', color: '#4285f4' },
        { id: 'pieClick', count: data.counts.Click, label: '點擊連結', color: '#fabb05' },
        { id: 'pieAttach', count: data.counts.Attach, label: '開啟附件', color: '#34a853' },
        { id: 'pieInput', count: data.counts.Input, label: '輸入資料', color: '#ea4335' }
    ];

    pieSet.forEach(p => {
        if (chartInstances[p.id]) chartInstances[p.id].destroy();
        chartInstances[p.id] = new Chart(document.getElementById(p.id), {
            type: 'pie',
            data: {
                labels: [`${p.label}帳號數 ${p.count}`, `未${p.label}帳號數 ${data.total - p.count}`],
                datasets: [{ 
                    data: [p.count, data.total - p.count], 
                    backgroundColor: [p.color, '#66bb6a'] // 參考圖片使用綠色系
                }]
            },
            options: {
                plugins: {
                    legend: { 
                        position: 'bottom', // 改為下方，釋放寬度
                        labels: { 
                            boxWidth: 12, 
                            font: { size: 11 } 
                        }
                    },
                    datalabels: {
                        formatter: (value, ctx) => {
                            if (value === 0) return ''; // 數值為 0 時不顯示，避免重疊
                            let sum = ctx.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                            let percentage = (value * 100 / sum).toFixed(2) + "%";
                            return percentage;
                        },
                        color: '#444',
                        anchor: 'end',
                        align: 'start',
                        offset: 10
                    }
                },
                layout: {
                    padding: {
                        top: 10, bottom: 10, left: 20, right: 20 // 增加內距防止文字裁切
                    }
                }
            }
        });
    });

    // --- 更新長條圖 (參考 image_cbf241.png) ---
    const barSet = [
        { id: 'barClick', label: '點擊連結比率', key: 'click_rate', color: '#4285f4' },
        { id: 'barAttach', label: '開啟附件比率', key: 'attach_rate', color: '#34a853' },
        { id: 'barInput', label: '輸入資料比率', key: 'input_rate', color: '#ea4335' }
    ];

    barSet.forEach(b => {
        if (chartInstances[b.id]) chartInstances[b.id].destroy();
        chartInstances[b.id] = new Chart(document.getElementById(b.id), {
            type: 'bar',
            data: {
                labels: data.dept_stats.map(d => d.name),
                datasets: [{
                    label: b.label,
                    data: data.dept_stats.map(d => d[b.key]),
                    backgroundColor: data.dept_stats.map((_, i) => `hsl(${i * 45}, 70%, 50%)`), // 每個部門不同顏色
                    barThickness: 20
                }]
            },
            options: {
                indexAxis: 'y',
                scales: { 
                    x: { max: 100, beginAtZero: true, title: { display: true, text: '比率' } },
                    y: { title: { display: true, text: '部門' } }
                },
                plugins: {
                    datalabels: {
                        anchor: 'end',
                        align: 'right',
                        formatter: (val) => val + (val > 0 ? "" : ""), // 顯示數值
                        font: { weight: 'bold' }
                    },
                    // 模擬紅色基準線
                    annotation: {
                        annotations: {
                            line1: {
                                type: 'line',
                                xMin: 5,
                                xMax: 5,
                                borderColor: 'red',
                                borderWidth: 2,
                                label: { content: '5%', enabled: true, position: 'end' }
                            }
                        }
                    }
                }
            }
        });
    });
}

// --- 匯出功能 ---
function exportChart(canvasId, name) {
    const canvas = document.getElementById(canvasId);
    const container = canvas.closest('.chart-container');
    html2canvas(container, { backgroundColor: "#ffffff", scale: 2 }).then(canvasResult => {
        const link = document.createElement('a');
        link.download = `${name}.png`;
        link.href = canvasResult.toDataURL('image/png');
        link.click();
    });
}

function exportTableAsImage(elementId, name) {
    const element = document.getElementById(elementId);
    html2canvas(element, { backgroundColor: "#ffffff", scale: 2 }).then(canvas => {
        const link = document.createElement('a');
        link.download = `${name}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
}

loadData();
</script>
</body>
</html>
