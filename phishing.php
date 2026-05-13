<?php
/**
 * -------------------------------------------------------------------
 * 1. 後端 PHP：資料處理與統計 (phishing.php)
 * -------------------------------------------------------------------
 */
ini_set('memory_limit', '512M');
set_time_limit(60);

if (isset($_GET['action']) && $_GET['action'] === 'get_data') {
    header('Content-Type: application/json; charset=utf-8');

    $start    = $_GET['start'] ?? '';
    $end      = $_GET['end'] ?? '';
    $tagName  = $_GET['tagName'] ?? 'adGroup'; // 預設使用 adGroup
    $tagValue = $_GET['tagValue'] ?? '';

    $apiUrl = "https://results.us.securityeducation.com/api/reporting/v0.3.0/phishing";
    $params = ['page[size]' => '5000']; 
    if ($start) $params["filter[_campaignstartdate_start]"] = "'$start'";
    if ($end)   $params["filter[_campaignstartdate_end]"]   = "'$end'";
    if ($tagName && $tagValue) {
        $params["filter[user_tag][$tagName]"] = "'$tagValue'";
    }

    $fullUrl = $apiUrl . "?" . http_build_query($params) . "&user_tag_enable";
    $apiKey = "6VPasCLKwgHttewO/JgX9wfFI9WdfvDFQpBmteS6E5RVccwZ3";

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

    foreach ($events as $item) {
        $attr = $item['attributes'];
        $email = $attr['useremailaddress'];
        $type = $attr['eventtype'];
        
        // 1. 活動表格資料 (以活動名稱為 Key 去重)
        $cName = $attr['campaignname'] ?? 'N/A';
        if (!isset($campaigns[$cName])) {
            $campaigns[$cName] = [
                "name" => $cName,
                "type" => $attr['campaigntype'] ?? 'N/A',
                "subject" => $attr['templatesubject'] ?? 'N/A'
            ];
        }

        // 2. 解析部門 (adGroup)
        $dept = "未分類";
        if (isset($attr['usertags'][$tagName])) {
            $tagVal = $attr['usertags'][$tagName];
            $dept = is_array($tagVal) ? ($tagVal[0] ?? "空值") : $tagVal;
        }

        // 3. 帳號狀態追蹤 (每個 Email 在該活動中的最高風險行為)
        if (!isset($users_status[$email])) {
            $users_status[$email] = ["View" => 0, "Click" => 0, "Attach" => 0, "Input" => 0, "dept" => $dept];
        }
        if ($type === "Email View")      $users_status[$email]["View"] = 1;
        if ($type === "Email Click")     $users_status[$email]["Click"] = 1;
        if ($type === "Attachment Open") $users_status[$email]["Attach"] = 1;
        if ($type === "Data Submission") $users_status[$email]["Input"] = 1;

        // 4. 部門基礎數據累加
        if (!isset($dept_data[$dept])) {
            $dept_data[$dept] = ["Total" => 0, "Click" => 0, "Attach" => 0, "Input" => 0];
        }
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

    echo json_encode([
        "table" => array_values($campaigns),
        "total" => $totalUsers,
        "counts" => $counts,
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
        .pie-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; text-align: center; margin: 20px 0; }
        .bar-grid { display: grid; grid-template-columns: repeat(1, 1fr); gap: 30px; margin-top: 20px; }
        .chart-label { margin-top: 10px; font-weight: bold; font-size: 0.9em; }
    </style>
</head>
<body>

<div class="container">
    <h2>社交工程演練報表系統</h2>

    <div class="card">
        <div class="filter-row">
            <div><label>開始日期</label><input type="date" id="start" value="2026-04-01"></div>
            <div><label>結束日期</label><input type="date" id="end" value="2026-05-31"></div>
            <div><label>屬性名稱</label><input type="text" id="tagName" value="adGroup"></div>
            <div><label>屬性值</label><input type="text" id="tagValue" placeholder="全部"></div>
            <div><button onclick="loadData()">更新報表</button></div>
        </div>
    </div>

    <div class="card">
        <h3>活動與範本資訊</h3>
        <table id="campaignTable">
            <thead>
                <tr><th>活動名稱</th><th>範本類別</th><th>範本主題</th></tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <div class="card">
        <h3>各項指標帳號數比率 (總帳號數: <span id="totalTxt">0</span>)</h3>
        <div class="pie-grid">
            <div><canvas id="pieView"></canvas><div class="chart-label">信件開啟比率</div></div>
            <div><canvas id="pieClick"></canvas><div class="chart-label">點閱連結比率</div></div>
            <div><canvas id="pieAttach"></canvas><div class="chart-label">開啟附件比率</div></div>
            <div><canvas id="pieInput"></canvas><div class="chart-label">釣魚輸入比率</div></div>
        </div>
    </div>

    <div class="card">
        <h3>部門比率分析</h3>
        <div class="bar-grid">
            <div><canvas id="barClick"></canvas></div>
            <div><canvas id="barAttach"></canvas></div>
            <div><canvas id="barInput"></canvas></div>
        </div>
    </div>
</div>

<script>
let chartInstances = {};

async function loadData() {
    const q = new URLSearchParams({
        action: 'get_data',
        start: document.getElementById('start').value,
        end: document.getElementById('end').value,
        tagName: document.getElementById('tagName').value,
        tagValue: document.getElementById('tagValue').value
    });

    const res = await fetch(`phishing.php?${q}`);
    const data = await res.json();

    document.getElementById('totalTxt').innerText = data.total;

    // --- 更新表格 ---
    const tbody = document.querySelector('#campaignTable tbody');
    tbody.innerHTML = data.table.map(c => 
        `<tr><td>${c.name}</td><td>${c.type}</td><td>${c.subject}</td></tr>`
    ).join('');

    // --- 更新圓餅圖 ---
    const pieSet = [
        { id: 'pieView', count: data.counts.View, label: '已開啟', color: '#4285f4' },
        { id: 'pieClick', count: data.counts.Click, label: '已點擊', color: '#fabb05' },
        { id: 'pieAttach', count: data.counts.Attach, label: '已開附件', color: '#34a853' },
        { id: 'pieInput', count: data.counts.Input, label: '已輸入', color: '#ea4335' }
    ];

    pieSet.forEach(p => {
        if (chartInstances[p.id]) chartInstances[p.id].destroy();
        chartInstances[p.id] = new Chart(document.getElementById(p.id), {
            type: 'pie',
            data: {
                labels: [p.label, '未執行'],
                datasets: [{ data: [p.count, data.total - p.count], backgroundColor: [p.color, '#eee'] }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    });

    // --- 更新長條圖 ---
    const barSet = [
        { id: 'barClick', label: '部門點閱連結比率 (%)', key: 'click_rate', color: '#fabb05' },
        { id: 'barAttach', label: '部門開啟附件比率 (%)', key: 'attach_rate', color: '#34a853' },
        { id: 'barInput', label: '部門釣魚輸入比率 (%)', key: 'input_rate', color: '#ea4335' }
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
                    backgroundColor: b.color
                }]
            },
            options: { indexAxis: 'y', scales: { x: { max: 100, beginAtZero: true } } }
        });
    });
}

loadData();
</script>
</body>
</html>
