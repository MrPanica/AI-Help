<?php
// Увеличим лимит памяти и времени выполнения
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 600);
set_time_limit(600);

// Инициализация XenForo
error_reporting(E_ALL & ~E_NOTICE);
define('__XF__', '/var/www/progameszet.ru');
require __XF__ . '/src/XF.php';

$_SERVER['SCRIPT_NAME'] = __XF__ . '/index.php';
$_SERVER['REQUEST_URI'] = preg_replace("|^" . preg_quote(preg_replace('|(/+)$|', '', $_SERVER['DOCUMENT_ROOT'])) . "|", '', __XF__ . '/index.php');

XF::start(__XF__);
$app = \XF::setupApp('XF\Pub\App');
$request = $app->request();

\ScriptsPages\Setup::set('init', true);

// ================= НАЧАЛО СКРИПТА =================
// Конфигурация базы данных из внешнего файла
$configFile = __DIR__ . '/../src/secure_config_statstf2.php';
if (!file_exists($configFile)) {
    die('Конфигурационный файл не найден');
}

$config = include $configFile;
$dbHost = $config['db']['host'];
$dbUser = $config['db']['user'];
$dbPass = $config['db']['pass'];
$dbName = $config['db']['name'];
$steamApiKey = $config['steam_api_key'];

// Подключение к БД
$db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($db->connect_error) {
    error_log('DB connection error: ' . $db->connect_error);
    die('Ошибка подключения к базе данных');
}
$db->set_charset('utf8');

// Получение списка серверов
$servers = [];
$serverResult = $db->query("SELECT id, server_name, server_shortname FROM syspanel_servers");
if ($serverResult) {
    while ($row = $serverResult->fetch_assoc()) {
        $servers[$row['id']] = $row;
    }
    $serverResult->free();
} else {
    error_log('Servers query error: ' . $db->error);
    die("Ошибка получения списка серверов");
}

// Обработка параметров с валидацией
$selectedServer = isset($_GET['server']) ? (int)$_GET['server'] : 0;
$selectedTab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$playerSearch = isset($_GET['player']) ? trim($_GET['player']) : '';
$ipSearch = isset($_GET['ip']) ? trim($_GET['ip']) : '';
$typeFilter = isset($_GET['type']) ? $_GET['type'] : '';
$msgSearch = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;

// Валидация дат
$maxPeriod = 365; // Максимальный период в днях
$startTimestamp = strtotime($startDate);
$endTimestamp = strtotime($endDate . ' 23:59:59');

if ($startTimestamp === false || $endTimestamp === false) {
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $endDate = date('Y-m-d');
    $startTimestamp = strtotime($startDate);
    $endTimestamp = strtotime($endDate . ' 23:59:59');
}

// Ограничение периода выборки
$diffDays = ($endTimestamp - $startTimestamp) / (60 * 60 * 24);
if ($diffDays > $maxPeriod) {
    $endDate = date('Y-m-d', $startTimestamp + ($maxPeriod * 24 * 60 * 60));
    $endTimestamp = strtotime($endDate . ' 23:59:59');
}

// Пагинация
$itemsPerPage = 50;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// Пагинация для профиля игрока
$visitsPerPage = 10;
$visitsPage = isset($_GET['visits_page']) ? max(1, (int)$_GET['visits_page']) : 1;
$chatPage = isset($_GET['chat_page']) ? max(1, (int)$_GET['chat_page']) : 1;

// Функция для форматирования времени (в минутах)
function formatTime($minutes) {
    if (!$minutes || $minutes < 1) return '-';
    
    $years = floor($minutes / (365 * 24 * 60));
    $minutes %= 365 * 24 * 60;
    
    $months = floor($minutes / (30 * 24 * 60));
    $minutes %= 30 * 24 * 60;
    
    $days = floor($minutes / (24 * 60));
    $minutes %= 24 * 60;
    
    $hours = floor($minutes / 60);
    $minutes = $minutes % 60;
    
    $result = [];
    if ($years > 0) $result[] = $years . ' г.';
    if ($months > 0) $result[] = $months . ' мес.';
    if ($days > 0) $result[] = $days . ' дн.';
    if ($hours > 0) $result[] = $hours . ' ч';
    if ($minutes > 0) $result[] = $minutes . ' мин';
    
    return $result ? implode(' ', $result) : '0 мин';
}

// Функция для форматирования даты
function formatDate($timestamp) {
    if (!$timestamp) return '-';
    return date('d.m.Y H:i', $timestamp);
}

// Функция для форматирования даты (только дата)
function formatDateOnly($timestamp) {
    if (!$timestamp) return '-';
    return date('d.m.Y', $timestamp);
}

// Функция для получения аватаров Steam с кешированием
function getUserAvatars($steamIdArr, $ApiKey) {
    if (!is_array($steamIdArr)) {
        throw new LogicException("No array has been passed to function.");
    }

    // Фильтрация SteamID
    $steamIdArr = array_filter($steamIdArr, 'is_numeric');
    if (empty($steamIdArr)) {
        return [];
    }

    $steamIdReq = implode(',', $steamIdArr);
    $url = "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key={$ApiKey}&steamids={$steamIdReq}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return [];
    }

    $result = json_decode($response, true);
    $responseData = [];

    if (isset($result['response']['players'])) {
        foreach ($result['response']['players'] as $player) {
            $responseData[$player['steamid']] = [
                'avatar' => $player['avatarfull'] ?? '',
                'timecreated' => $player['timecreated'] ?? 0,
            ];
        }
    }

    return $responseData;
}

// Функция для получения времени игры в TF2 с кешированием
function getTf2Playtime($steamId64, $ApiKey) {
    if (!is_numeric($steamId64)) return 0;
    
    $url = "https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key={$ApiKey}&include_played_free_games=1&include_appinfo=1&steamid={$steamId64}&format=json";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return 0;
    }

    $data = json_decode($response, true);
    
    if (isset($data['response']['games'])) {
        foreach ($data['response']['games'] as $game) {
            if ($game['appid'] == 440) {
                return $game['playtime_forever'] ?? 0;
            }
        }
    }
    return 0;
}

// Получение статистики за сегодня
function getTodayStats($db, $serverId = 0) {
    $todayStart = strtotime('today');
    $todayEnd = strtotime('tomorrow') - 1;
    
    $stats = [
        'unique_players' => 0,
        'playtime' => 0
    ];
    
    $conditions = [];
    $params = [];
    $types = '';
    
    $conditions[] = "v.connect >= ?";
    $params[] = $todayStart;
    $types .= 'i';
    
    $conditions[] = "v.connect <= ?";
    $params[] = $todayEnd;
    $types .= 'i';
    
    if ($serverId) {
        $conditions[] = "v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }
    
    // Уникальные игроки за сегодня
    $query = "SELECT COUNT(DISTINCT v.uid) as total 
              FROM SP_users_visits v
              WHERE " . implode(" AND ", $conditions);
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['unique_players'] = $row['total'] ?? 0;
        $stmt->close();
    }
    
    // Суммарное время игры за сегодня
    $query = "SELECT SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)) as total 
              FROM SP_users_visits v
              WHERE " . implode(" AND ", $conditions);
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['playtime'] = $row['total'] ?? 0;
        $stmt->close();
    }
    
    return $stats;
}

// Получение общей статистики
function getTotalStats($db, $serverId = 0) {
    $stats = [
        'unique_players' => 0,
        'playtime' => 0
    ];
    
    $conditions = [];
    $params = [];
    $types = '';
    
    if ($serverId) {
        $conditions[] = "v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }
    
    $whereClause = $conditions ? " WHERE " . implode(" AND ", $conditions) : '';
    
    // Уникальные игроки всего
    $query = "SELECT COUNT(DISTINCT v.uid) as total 
              FROM SP_users_visits v
              $whereClause";
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['unique_players'] = $row['total'] ?? 0;
        $stmt->close();
    }
    
    // Суммарное время игры всего
    $query = "SELECT SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)) as total 
              FROM SP_users_visits v
              $whereClause";
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['playtime'] = $row['total'] ?? 0;
        $stmt->close();
    }
    
    return $stats;
}

// Получение статистики за период
function getPeriodStats($db, $startTimestamp, $endTimestamp, $serverId = 0) {
    $stats = [
        'unique_players' => 0,
        'playtime' => 0
    ];
    
    $conditions = [];
    $params = [];
    $types = '';
    
    $conditions[] = "v.connect >= ?";
    $params[] = $startTimestamp;
    $types .= 'i';
    
    $conditions[] = "v.connect <= ?";
    $params[] = $endTimestamp;
    $types .= 'i';
    
    if ($serverId) {
        $conditions[] = "v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }
    
    // Уникальные игроки за период
    $query = "SELECT COUNT(DISTINCT v.uid) as total 
              FROM SP_users_visits v
              WHERE " . implode(" AND ", $conditions);
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['unique_players'] = $row['total'] ?? 0;
        $stmt->close();
    }
    
    // Суммарное время игры за период
    $query = "SELECT SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)) as total 
              FROM SP_users_visits v
              WHERE " . implode(" AND ", $conditions);
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['playtime'] = $row['total'] ?? 0;
        $stmt->close();
    }
    
    return $stats;
}

// Получение данных для графика активности с агрегацией
function getActivityData($db, $startDate, $endDate, $serverId = 0, $playerId = 0) {
    $startTimestamp = strtotime($startDate);
    $endTimestamp = strtotime($endDate . ' 23:59:59');
    
    $data = [
        'dates' => [],
        'connections' => [],
        'playtime' => []
    ];
    
    // Определяем уровень агрегации
    $daysDiff = ($endTimestamp - $startTimestamp) / (60 * 60 * 24);
    $aggregation = 'DAY';
    
    if ($daysDiff > 90) {
        $aggregation = 'WEEK';
    } elseif ($daysDiff > 365) {
        $aggregation = 'MONTH';
    }
    
    // Создаем массив всех дат в диапазоне
    $current = $startTimestamp;
    $dateFormat = '';
    
    switch ($aggregation) {
        case 'WEEK':
            while ($current <= $endTimestamp) {
                $data['dates'][] = date('o-W', $current);
                $data['connections'][] = 0;
                $data['playtime'][] = 0;
                $current = strtotime('next monday', $current);
            }
            $dateFormat = "DATE_FORMAT(FROM_UNIXTIME(v.connect), '%x-%v')";
            break;
            
        case 'MONTH':
            $current = strtotime('first day of this month', $startTimestamp);
            while ($current <= $endTimestamp) {
                $data['dates'][] = date('m.Y', $current);
                $data['connections'][] = 0;
                $data['playtime'][] = 0;
                $current = strtotime('+1 month', $current);
            }
            $dateFormat = "DATE_FORMAT(FROM_UNIXTIME(v.connect), '%m.%Y')";
            break;
            
        default: // DAY
            $current = $startTimestamp;
            while ($current <= $endTimestamp) {
                $data['dates'][] = date('d.m.Y', $current);
                $data['connections'][] = 0;
                $data['playtime'][] = 0;
                $current = strtotime('+1 day', $current);
            }
            $dateFormat = "DATE_FORMAT(FROM_UNIXTIME(v.connect), '%d.%m.%Y')";
    }
    
    // Получаем данные из БД
    $query = "SELECT 
                {$dateFormat} as date_group,
                COUNT(*) as connections,
                SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)) as playtime
              FROM SP_users_visits v
              WHERE v.connect >= ? AND v.connect <= ?";
    
    $params = [$startTimestamp, $endTimestamp];
    $types = 'ii';
    
    if ($serverId) {
        $query .= " AND v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }
    
    if ($playerId) {
        $query .= " AND v.uid = ?";
        $params[] = $playerId;
        $types .= 'i';
    }
    
    $query .= " GROUP BY date_group";
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $key = $row['date_group'];
            
            // Для месячной группировки: преобразуем 01.2023 в 1.2023
            if ($aggregation === 'MONTH') {
                $key = ltrim($key, '0');
            }
            
            $index = array_search($key, $data['dates']);
            if ($index !== false) {
                $data['connections'][$index] = (int)$row['connections'];
                $data['playtime'][$index] = (int)$row['playtime'] / 3600;
            }
        }
        
        $stmt->close();
    }
    
    return $data;
}

// Получение данных по картам для вкладки "Дополнительно"
function getMapsData($db, $startDate, $endDate, $serverId = 0) {
    $startTimestamp = strtotime($startDate);
    $endTimestamp = strtotime($endDate . ' 23:59:59');
    
    $query = "SELECT v.map, SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)) as playtime
              FROM SP_users_visits v
              WHERE v.connect >= ? AND v.connect <= ?";
    
    $params = [$startTimestamp, $endTimestamp];
    $types = 'ii';
    
    if ($serverId) {
        $query .= " AND v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }
    
    $query .= " AND v.map != '' GROUP BY v.map ORDER BY playtime DESC LIMIT 10";
    
    $stmt = $db->prepare($query);
    $data = ['maps' => [], 'playtime' => []];
    
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data['maps'][] = htmlspecialchars($row['map'] ?: 'Неизвестно');
            $data['playtime'][] = (int)$row['playtime'];
        }
        
        $stmt->close();
    }
    
    return $data;
}

// Получение данных по странам для вкладка "Дополнительно"
function getCountriesData($db, $startDate, $endDate, $serverId = 0) {
    $startTimestamp = strtotime($startDate);
    $endTimestamp = strtotime($endDate . ' 23:59:59');
    
    $query = "SELECT v.geo, COUNT(*) as connections
              FROM SP_users_visits v
              WHERE v.connect >= ? AND v.connect <= ?";
    
    $params = [$startTimestamp, $endTimestamp];
    $types = 'ii';
    
    if ($serverId) {
        $query .= " AND v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }
    
    $query .= " AND v.geo != '' GROUP BY v.geo ORDER BY connections DESC LIMIT 10";
    
    $stmt = $db->prepare($query);
    $data = ['countries' => [], 'connections' => []];
    
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data['countries'][] = htmlspecialchars($row['geo'] ?: 'Неизвестно');
            $data['connections'][] = (int)$row['connections'];
        }
        
        $stmt->close();
    }
    
    return $data;
}

// Получение статистики игрока
function getPlayerStats($db, $playerId, $startTimestamp, $endTimestamp, $serverId = 0) {
    $stats = [
        'connections' => 0,
        'playtime' => 0
    ];

    $conditions = ["v.uid = ?"];
    $params = [$playerId];
    $types = 'i';

    $conditions[] = "v.connect >= ?";
    $params[] = $startTimestamp;
    $types .= 'i';

    $conditions[] = "v.connect <= ?";
    $params[] = $endTimestamp;
    $types .= 'i';

    if ($serverId) {
        $conditions[] = "v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }

    // Количество подключений за период
    $query = "SELECT COUNT(*) as connections, 
                     SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)) as playtime 
              FROM SP_users_visits v
              WHERE " . implode(" AND ", $conditions);

    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['connections'] = $row['connections'] ?? 0;
            $stats['playtime'] = $row['playtime'] ?? 0;
        }
        $stmt->close();
    }

    return $stats;
}

// Получение статистики игрока за сегодня
function getPlayerTodayStats($db, $playerId, $serverId = 0) {
    $todayStart = strtotime('today');
    $todayEnd = strtotime('tomorrow') - 1;
    return getPlayerStats($db, $playerId, $todayStart, $todayEnd, $serverId);
}

// Получение статистики игрока за все время
function getPlayerTotalStats($db, $playerId, $serverId = 0) {
    $conditions = ["v.uid = ?"];
    $params = [$playerId];
    $types = 'i';

    if ($serverId) {
        $conditions[] = "v.server = ?";
        $params[] = $serverId;
        $types .= 'i';
    }

    $query = "SELECT 
                 COUNT(*) as connections, 
                 SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)) as playtime 
              FROM SP_users_visits v
              WHERE " . implode(" AND ", $conditions);

    $stmt = $db->prepare($query);
    $stats = ['connections' => 0, 'playtime' => 0];
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['connections'] = $row['connections'] ?? 0;
            $stats['playtime'] = $row['playtime'] ?? 0;
        }
        $stmt->close();
    }

    return $stats;
}

// Захватываем вывод в буфер
ob_start();

// Получение статистики для верхнего блока
$todayStats = getTodayStats($db, $selectedServer);
$totalStats = getTotalStats($db, $selectedServer);
$periodStats = getPeriodStats(
    $db, 
    $startTimestamp, 
    $endTimestamp,
    $selectedServer
);

// Проверка, нужно ли показывать профиль игрока
$playerProfile = null;
$steamInfo = null;
$tf2Playtime = 0;
if ($playerId > 0) {
    $query = "SELECT u.*, a.regdate, a.activitydate, a.online 
              FROM SP_users u
              LEFT JOIN SP_users_activity a ON u.id = a.uid
              WHERE u.id = ?";
    
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param('i', $playerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $playerProfile = $result->fetch_assoc();
        $stmt->close();
    }
    
    // Получаем Steam информацию
    if ($playerProfile && $playerProfile['steamid64'] && $steamApiKey) {
        $steamInfo = getUserAvatars([$playerProfile['steamid64']], $steamApiKey);
        $tf2Playtime = getTf2Playtime($playerProfile['steamid64'], $steamApiKey);
    }
}

// Получение данных для графика
$activityData = getActivityData(
    $db, 
    $startDate, 
    $endDate, 
    $selectedServer,
    $playerId ? $playerId : 0
);

// Получение данных для вкладки "Дополнительно"
$mapsData = [];
$countriesData = [];
if ($selectedTab == 'additional') {
    $mapsData = getMapsData($db, $startDate, $endDate, $selectedServer);
    $countriesData = getCountriesData($db, $startDate, $endDate, $selectedServer);
}

// Получение статистики игрока (если открыт профиль)
$playerPeriodStats = [];
$playerTodayStats = [];
$playerTotalStats = [];
if ($playerProfile) {
    $playerPeriodStats = getPlayerStats(
        $db, 
        $playerId, 
        $startTimestamp, 
        $endTimestamp,
        $selectedServer
    );
    $playerTodayStats = getPlayerTodayStats($db, $playerId, $selectedServer);
    $playerTotalStats = getPlayerTotalStats($db, $playerId, $selectedServer);
}
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="stats.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script type="application/json" id="stats-data"><?php echo json_encode($jsData); ?></script>
<script src="stats.js"></script>

<div class="block">
    <div class="block-container">
        <div class="block-header">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php if ($playerProfile): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                <h2 class="block-title">Статистика игрока: <?= htmlspecialchars($playerProfile['nick']) ?></h2>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php else: ?>
                <h2 class="block-title">Статистика игроков</h2>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php endif; ?>
        </div>
        
        <div class="block-body">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php if (!$playerProfile): ?>
                <!-- Информационные карточки -->
                <div class="info-cards">
                    <div class="info-card">
                        <div class="info-card-title">Уникальные подключения за период</div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <div class="info-card-value"><?= $periodStats['unique_players'] ?></div>
                        <div class="info-card-subtext">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            +<?= $todayStats['unique_players'] ?> за сегодня
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-card-title">Время игры за период</div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <div class="info-card-value"><?= formatTime($periodStats['playtime'] / 60) ?></div>
                        <div class="info-card-subtext">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            +<?= formatTime($todayStats['playtime'] / 60) ?> за сегодня
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-card-title">Всего уникальных</div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <div class="info-card-value"><?= $totalStats['unique_players'] ?></div>
                        <div class="info-card-subtext">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            Всего времени: <?= formatTime($totalStats['playtime'] / 60) ?>
                        </div>
                    </div>
                </div>
                
                <!-- Отступ между карточками и графиком -->
                <div class="spacer-lg"></div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php endif; ?>
            
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php if (!$playerProfile || $playerId): ?>
                <!-- График активности -->
                <div class="chart-container">
                    <canvas id="activityChart" height="200"></canvas>
                </div>
                
                <!-- Отступ между графиком и фильтрами -->
                <div class="spacer"></div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php endif; ?>
            
            <!-- Фильтры -->
            <div class="filters">
                <form method="get" class="filter-form" id="filtersForm">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($selectedTab) ?>">
                    <input type="hidden" name="page" value="1">
                    
                    <div class="filter-group">
                        <h3 class="filter-header-title">Фильтры:</h3>
                        
                        <div class="filter-row">
                            <div class="filter-item">
                                <label>Сервер:</label>
                                <select name="server" class="input input--select">
                                    <option value="0">Все серверы</option>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php foreach ($servers as $id => $server): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <option value="<?= $id ?>" <?= $selectedServer == $id ? 'selected' : '' ?>>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <?= htmlspecialchars($server['server_shortname']) ?>
                                        </option>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label>Период:</label>
                                <input type="text" name="date_range" class="input date-range" 
                                       placeholder="Выберите даты" 
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                       data-start="<?= htmlspecialchars($startDate) ?>" 
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                       data-end="<?= htmlspecialchars($endDate) ?>">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                            </div>
                        </div>
                        
                        <div class="filter-row">
                            <div class="filter-item">
                                <label>Игрок:</label>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <input type="text" name="player" class="input" placeholder="Ник или SteamID" value="<?= htmlspecialchars($playerSearch) ?>">
                            </div>
                            
                            <div class="filter-item">
                                <label>IP-адрес:</label>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <input type="text" name="ip" class="input" placeholder="xxx.xxx.xxx.xxx" value="<?= htmlspecialchars($ipSearch) ?>">
                            </div>
                        </div>
                        
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php if ($selectedTab == 'chat'): ?>
                        <div class="filter-row">
                            <div class="filter-item">
                                <label>Тип сообщения:</label>
                                <select name="type" class="input input--select">
                                    <option value="">Все типы</option>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <option value="say" <?= $typeFilter == 'say' ? 'selected' : '' ?>>Общий чат</option>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <option value="team_say" <?= $typeFilter == 'team_say' ? 'selected' : '' ?>>Командный чат</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label>Поиск по тексту:</label>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <input type="text" name="msg" class="input" placeholder="Текст сообщения..." value="<?= htmlspecialchars($msgSearch) ?>">
                            </div>
                        </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php endif; ?>
                        
                        <div class="filter-row">
                            <button type="submit" class="button button--primary">Применить фильтры</button>
                            <a href="?" class="button button--link">Сбросить фильтры</a>
                        </div>
                    </div>
                </form>
            </div>
            
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php if ($playerProfile): ?>
                <!-- Профиль игрока -->
                <div class="player-profile">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <a href="?<?= http_build_query(array_diff_key($_GET, ['player_id' => ''])) ?>" class="button button--primary" style="margin-bottom: 20px;">Назад к статистике</a>
                    
                    <div class="profile-header">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php if ($playerProfile['steamid64']): ?>
                            <div class="profile-avatar">
                                <?php
                                $avatarUrl = 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7e1997310d705b2a6158ff8dc1cdfeb_full.jpg';
                                if ($steamInfo && isset($steamInfo[$playerProfile['steamid64']]['avatar'])) {
                                    $avatarUrl = $steamInfo[$playerProfile['steamid64']]['avatar'];
                                }
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" 
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                     alt="Аватар <?= htmlspecialchars($playerProfile['nick']) ?>"
                                     onerror="this.onerror=null;this.src='https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7e1997310d705b2a6158ff8dc1cdfeb_full.jpg'">
                            </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php endif; ?>
                        
                        <div class="profile-header-main">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            <h2 class="profile-title">Профиль игрока: <?= htmlspecialchars($playerProfile['nick']) ?></h2>
                            
                            <!-- Статистика игрока -->
                            <div class="info-cards">
                                <div class="info-card">
                                    <div class="info-card-title">Подключения за период</div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <div class="info-card-value"><?= $playerPeriodStats['connections'] ?></div>
                                    <div class="info-card-subtext">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?= $playerTodayStats['connections'] ?> за сегодня
                                    </div>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Время игры за период</div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <div class="info-card-value"><?= formatTime($playerPeriodStats['playtime'] / 60) ?></div>
                                    <div class="info-card-subtext">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        +<?= formatTime($playerTodayStats['playtime'] / 60) ?> за сегодня
                                    </div>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Всего времени в игре</div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <div class="info-card-value"><?= formatTime($playerTotalStats['playtime'] / 60) ?></div>
                                    <div class="info-card-subtext">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        Всего подключений: <?= $playerTotalStats['connections'] ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="profile-info">
                        <div class="profile-info-grid">
                            <div class="profile-info-item">
                                <span class="info-label">SteamID:</span>
                                <span class="info-value">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if ($playerProfile['steamid64']): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <a href="https://steamcommunity.com/profiles/<?= htmlspecialchars($playerProfile['steamid64']) ?>" target="_blank">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <?= htmlspecialchars($playerProfile['steamid']) ?>
                                        </a>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php else: ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?= htmlspecialchars($playerProfile['steamid'] ?: '-') ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">IP-адрес:</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <span class="info-value"><?= htmlspecialchars($playerProfile['ip'] ?: '-') ?></span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">Дата регистрации:</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <span class="info-value"><?= formatDate($playerProfile['regdate']) ?></span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">Последняя активность:</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <span class="info-value"><?= formatDate($playerProfile['activitydate']) ?></span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">Время онлайн:</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                <span class="info-value"><?= formatTime($playerProfile['online']) ?></span>
                            </div>
                            
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            <?php if ($steamInfo && isset($steamInfo[$playerProfile['steamid64']]['timecreated']) && $steamInfo[$playerProfile['steamid64']]['timecreated'] > 0): ?>
                                <div class="profile-info-item">
                                    <span class="info-label">Профиль Steam создан:</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <span class="info-value"><?= formatDate($steamInfo[$playerProfile['steamid64']]['timecreated']) ?></span>
                                </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            <?php endif; ?>
                            
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            <?php if ($tf2Playtime > 0): ?>
                                <div class="profile-info-item">
                                    <span class="info-label">Время в TF2:</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <span class="info-value"><?= formatTime($tf2Playtime) ?></span>
                                </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="profile-section">
                        <h3>Последние посещения</h3>
                        <?php
                        // Пагинация для посещений
                        $visitsOffset = ($visitsPage - 1) * $visitsPerPage;
                        
                        $query = "SELECT v.*, s.server_name 
                                  FROM SP_users_visits v
                                  JOIN syspanel_servers s ON v.server = s.id
                                  WHERE v.uid = ?
                                  ORDER BY v.connect DESC
                                  LIMIT $visitsPerPage OFFSET $visitsOffset";
                        
                        $countQuery = "SELECT COUNT(*) as total 
                                       FROM SP_users_visits 
                                       WHERE uid = ?";
                        
                        $stmt = $db->prepare($countQuery);
                        $totalVisits = 0;
                        $visitsPages = 1;
                        
                        if ($stmt) {
                            $stmt->bind_param('i', $playerId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $row = $result->fetch_assoc();
                            $totalVisits = $row['total'] ?? 0;
                            $visitsPages = ceil($totalVisits / $visitsPerPage);
                            $stmt->close();
                        }
                        
                        $stmt = $db->prepare($query);
                        $visits = [];
                        
                        if ($stmt) {
                            $stmt->bind_param('i', $playerId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $visits = $result->fetch_all(MYSQLI_ASSOC);
                            $stmt->close();
                        }
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>Сервер</th>
                                        <th>Подключение</th>
                                        <th>Отключение</th>
                                        <th>Длительность</th>
                                        <th>Карта</th>
                                    </tr>
                                </thead>
                                <tbody>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if (!empty($visits)): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php foreach ($visits as $visit): ?>
                                            <tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($visit['server_name']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatDate($visit['connect']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= $visit['disconnect'] ? formatDate($visit['disconnect']) : 'В сети' ?></td>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php if ($visit['disconnect']): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= formatTime(($visit['disconnect'] - $visit['connect']) / 60) ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php else: ?>
                                                        -
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php endif; ?>
                                                </td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($visit['map'] ?: '-') ?></td>
                                            </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php endforeach; ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="no-data">Нет данных о посещениях</td>
                                        </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php if ($visitsPages > 1): ?>
                        <div class="pageNavWrapper">
                            <div class="pageNav">
                                <?php
                                $queryParams = $_GET;
                                unset($queryParams['visits_page']);
                                
                                $baseUrl = '?' . http_build_query($queryParams);
                                $pageNav = '';
                                
                                // Кнопка "Назад"
                                if ($visitsPage > 1) {
                                    $prevPage = $visitsPage - 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&visits_page=' . $prevPage . '" class="pageNav-jump pageNav-jump--prev">«</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--prev pageNav-jump--disabled">«</span>';
                                }
                                
                                // Страницы
                                $startPage = max(1, $visitsPage - 2);
                                $endPage = min($visitsPages, $visitsPage + 2);
                                
                                if ($startPage > 1) {
                                    $pageNav .= '<a href="' . $baseUrl . '&visits_page=1" class="pageNav-page">1</a>';
                                    if ($startPage > 2) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    if ($i == $visitsPage) {
                                        $pageNav .= '<span class="pageNav-page pageNav-page--current">' . $i . '</span>';
                                    } else {
                                        $pageNav .= '<a href="' . $baseUrl . '&visits_page=' . $i . '" class="pageNav-page">' . $i . '</a>';
                                    }
                                }
                                
                                if ($endPage < $visitsPages) {
                                    if ($endPage < $visitsPages - 1) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                    $pageNav .= '<a href="' . $baseUrl . '&visits_page=' . $visitsPages . '" class="pageNav-page">' . $visitsPages . '</a>';
                                }
                                
                                // Кнопка "Вперед"
                                if ($visitsPage < $visitsPages) {
                                    $nextPage = $visitsPage + 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&visits_page=' . $nextPage . '" class="pageNav-jump pageNav-jump--next">»</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--next pageNav-jump--disabled">»</span>';
                                }
                                
                                echo $pageNav;
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                ?>
                            </div>
                        </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php endif; ?>
                    </div>
                    
                    <div class="profile-section">
                        <h3>Последние сообщения в чате</h3>
                        <?php
                        // Пагинация для чата
                        $chatOffset = ($chatPage - 1) * $itemsPerPage;
                        
                        $query = "SELECT c.*, s.server_name 
                                  FROM SP_users_chat c
                                  JOIN syspanel_servers s ON c.server = s.id
                                  WHERE c.uid = ?
                                  ORDER BY c.date DESC
                                  LIMIT $itemsPerPage OFFSET $chatOffset";
                        
                        $countQuery = "SELECT COUNT(*) as total 
                                       FROM SP_users_chat 
                                       WHERE uid = ?";
                        
                        $stmt = $db->prepare($countQuery);
                        $totalChat = 0;
                        $chatPages = 1;
                        
                        if ($stmt) {
                            $stmt->bind_param('i', $playerId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $row = $result->fetch_assoc();
                            $totalChat = $row['total'] ?? 0;
                            $chatPages = ceil($totalChat / $itemsPerPage);
                            $stmt->close();
                        }
                        
                        $stmt = $db->prepare($query);
                        $chatMessages = [];
                        
                        if ($stmt) {
                            $stmt->bind_param('i', $playerId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $chatMessages = $result->fetch_all(MYSQLI_ASSOC);
                            $stmt->close();
                        }
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>Дата</th>
                                        <th>Сервер</th>
                                        <th>Тип</th>
                                        <th>Сообщение</th>
                                    </tr>
                                </thead>
                                <tbody>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if (!empty($chatMessages)): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php foreach ($chatMessages as $message): ?>
                                            <tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatDate($message['date']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($message['server_name']) ?></td>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php if ($message['type'] == 'say'): ?>
                                                        <span class="badge badge--general">Общий</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php elseif ($message['type'] == 'team_say'): ?>
                                                        <span class="badge badge--team">Команда</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php else: ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= htmlspecialchars($message['type']) ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php endif; ?>
                                                </td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($message['msg']) ?></td>
                                            </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php endforeach; ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="no-data">Нет сообщений в чате</td>
                                        </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php if ($chatPages > 1): ?>
                        <div class="pageNavWrapper">
                            <div class="pageNav">
                                <?php
                                $queryParams = $_GET;
                                unset($queryParams['chat_page']);
                                
                                $baseUrl = '?' . http_build_query($queryParams);
                                $pageNav = '';
                                
                                // Кнопка "Назад"
                                if ($chatPage > 1) {
                                    $prevPage = $chatPage - 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&chat_page=' . $prevPage . '" class="pageNav-jump pageNav-jump--prev">«</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--prev pageNav-jump--disabled">«</span>';
                                }
                                
                                // Страницы
                                $startPage = max(1, $chatPage - 2);
                                $endPage = min($chatPages, $chatPage + 2);
                                
                                if ($startPage > 1) {
                                    $pageNav .= '<a href="' . $baseUrl . '&chat_page=1" class="pageNav-page">1</a>';
                                    if ($startPage > 2) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    if ($i == $chatPage) {
                                        $pageNav .= '<span class="pageNav-page pageNav-page--current">' . $i . '</span>';
                                    } else {
                                        $pageNav .= '<a href="' . $baseUrl . '&chat_page=' . $i . '" class="pageNav-page">' . $i . '</a>';
                                    }
                                }
                                
                                if ($endPage < $chatPages) {
                                    if ($endPage < $chatPages - 1) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                    $pageNav .= '<a href="' . $baseUrl . '&chat_page=' . $chatPages . '" class="pageNav-page">' . $chatPages . '</a>';
                                }
                                
                                // Кнопка "Вперед"
                                if ($chatPage < $chatPages) {
                                    $nextPage = $chatPage + 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&chat_page=' . $nextPage . '" class="pageNav-jump pageNav-jump--next">»</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--next pageNav-jump--disabled">»</span>';
                                }
                                
                                echo $pageNav;
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                ?>
                            </div>
                        </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php endif; ?>
                    </div>
                </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php else: ?>
                <!-- Вкладки -->
                <div class="tabs">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <a href="?tab=summary&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'summary' ? 'active' : '' ?>">Обзор</a>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <a href="?tab=visits&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'visits' ? 'active' : '' ?>">Посещения</a>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <a href="?tab=chat&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'chat' ? 'active' : '' ?>">Лог чата</a>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <a href="?tab=players&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'players' ? 'active' : '' ?>">Статистика игроков</a>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <a href="?tab=top100&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'top100' ? 'active' : '' ?>">Топ 100</a>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <a href="?tab=additional&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'additional' ? 'active' : '' ?>">Дополнительно</a>
                </div>
                
                <!-- Контент вкладок -->
                <div class="tab-content">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <?php if ($selectedTab == 'summary' || $selectedTab == 'visits'): ?>
                        <!-- Статистика посещений -->
                        <?php
                        // Параметры сортировки
                        $sortField = $_GET['sort'] ?? 'v.connect';
                        $sortOrder = $_GET['order'] ?? 'DESC';
                        
                        // Разрешенные поля для сортировки
                        $allowedSortFields = [
                            'u.nick', 's.server_name', 'v.connect', 'v.disconnect', 
                            'duration', 'v.map', 'v.ip', 'v.geo'
                        ];
                        
                        if (!in_array($sortField, $allowedSortFields)) {
                            $sortField = 'v.connect';
                        }
                        $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';
                        
                        // Подсчет общего количества
                        $countQuery = "SELECT COUNT(*) as total 
                                       FROM SP_users_visits v
                                       JOIN SP_users u ON v.uid = u.id
                                       JOIN syspanel_servers s ON v.server = s.id
                                       WHERE 1";
                        
                        $query = "SELECT v.*, u.nick, s.server_name,
                                  (v.disconnect - v.connect) as duration
                                  FROM SP_users_visits v
                                  JOIN SP_users u ON v.uid = u.id
                                  JOIN syspanel_servers s ON v.server = s.id
                                  WHERE 1";
                        
                        $conditions = [];
                        $params = [];
                        $types = '';
                        
                        if ($selectedServer) {
                            $conditions[] = "v.server = ?";
                            $params[] = $selectedServer;
                            $types .= 'i';
                        }
                        
                        if ($startDate) {
                            $conditions[] = "v.connect >= ?";
                            $params[] = $startTimestamp;
                            $types .= 'i';
                        }
                        
                        if ($endDate) {
                            $conditions[] = "v.connect <= ?";
                            $params[] = $endTimestamp;
                            $types .= 'i';
                        }
                        
                        if ($playerSearch) {
                            $conditions[] = "(u.nick LIKE ? OR u.steamid LIKE ? OR u.steamid64 LIKE ?)";
                            $params[] = "%$playerSearch%";
                            $params[] = "%$playerSearch%";
                            $params[] = "%$playerSearch%";
                            $types .= 'sss';
                        }
                        
                        if ($ipSearch) {
                            $conditions[] = "v.ip = ?";
                            $params[] = $ipSearch;
                            $types .= 's';
                        }
                        
                        if (!empty($conditions)) {
                            $countQuery .= " AND " . implode(" AND ", $conditions);
                            $query .= " AND " . implode(" AND ", $conditions);
                        }
                        
                        // Получение общего количества
                        $countStmt = $db->prepare($countQuery);
                        if ($countStmt) {
                            if (!empty($params)) {
                                $countStmt->bind_param($types, ...$params);
                            }
                            $countStmt->execute();
                            $countResult = $countStmt->get_result();
                            $totalItems = $countResult->fetch_assoc()['total'];
                            $totalPages = ceil($totalItems / $itemsPerPage);
                            $countStmt->close();
                        } else {
                            $totalItems = 0;
                            $totalPages = 1;
                        }
                        
                        // Основной запрос с пагинацией
                        $query .= " ORDER BY $sortField $sortOrder LIMIT ? OFFSET ?";
                        $params[] = $itemsPerPage;
                        $params[] = $offset;
                        $types .= 'ii';
                        
                        $stmt = $db->prepare($query);
                        $visits = [];
                        
                        if ($stmt) {
                            if (!empty($params)) {
                                $stmt->bind_param($types, ...$params);
                            }
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $visits = $result->fetch_all(MYSQLI_ASSOC);
                            $stmt->close();
                        }
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.nick', 'order' => ($sortField === 'u.nick' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Игрок
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'u.nick') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 's.server_name', 'order' => ($sortField === 's.server_name' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Сервер
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 's.server_name') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.connect', 'order' => ($sortField === 'v.connect' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Подключение
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'v.connect') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.disconnect', 'order' => ($sortField === 'v.disconnect' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Отключение
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'v.disconnect') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'duration', 'order' => ($sortField === 'duration' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Длительность
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'duration') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.map', 'order' => ($sortField === 'v.map' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Карта
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'v.map') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.ip', 'order' => ($sortField === 'v.ip' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                IP
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'v.ip') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.geo', 'order' => ($sortField === 'v.geo' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Гео
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'v.geo') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if (!empty($visits)): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php foreach ($visits as $visit): ?>
                                            <tr>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <a href="?<?= http_build_query(array_merge($_GET, ['player_id' => $visit['uid']])) ?>" 
                                                       class="player-link">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= htmlspecialchars($visit['nick']) ?>
                                                    </a>
                                                </td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($visit['server_name']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatDate($visit['connect']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= $visit['disconnect'] ? formatDate($visit['disconnect']) : 'В сети' ?></td>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php if ($visit['disconnect']): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= formatTime(($visit['disconnect'] - $visit['connect']) / 60) ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php else: ?>
                                                        -
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php endif; ?>
                                                </td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($visit['map'] ?: '-') ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($visit['ip']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($visit['geo']) ?></td>
                                            </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php endforeach; ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="no-data">Нет данных о посещениях</td>
                                        </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php if ($totalPages > 1): ?>
                        <div class="pageNavWrapper">
                            <div class="pageNav">
                                <?php
                                $queryParams = $_GET;
                                unset($queryParams['page']);
                                
                                $baseUrl = '?' . http_build_query($queryParams);
                                $pageNav = '';
                                
                                // Кнопка "Назад"
                                if ($currentPage > 1) {
                                    $prevPage = $currentPage - 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $prevPage . '" class="pageNav-jump pageNav-jump--prev">«</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--prev pageNav-jump--disabled">«</span>';
                                }
                                
                                // Страницы
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1) {
                                    $pageNav .= '<a href="' . $baseUrl . '&page=1" class="pageNav-page">1</a>';
                                    if ($startPage > 2) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    if ($i == $currentPage) {
                                        $pageNav .= '<span class="pageNav-page pageNav-page--current">' . $i . '</span>';
                                    } else {
                                        $pageNav .= '<a href="' . $baseUrl . '&page=' . $i . '" class="pageNav-page">' . $i . '</a>';
                                    }
                                }
                                
                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $totalPages . '" class="pageNav-page">' . $totalPages . '</a>';
                                }
                                
                                // Кнопка "Вперед"
                                if ($currentPage < $totalPages) {
                                    $nextPage = $currentPage + 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $nextPage . '" class="pageNav-jump pageNav-jump--next">»</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--next pageNav-jump--disabled">»</span>';
                                }
                                
                                echo $pageNav;
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                ?>
                            </div>
                        </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php endif; ?>
                    
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <?php elseif ($selectedTab == 'chat'): ?>
                        <!-- Лог чата -->
                        <?php
                        // Параметры сортировки
                        $sortField = $_GET['sort'] ?? 'c.date';
                        $sortOrder = $_GET['order'] ?? 'DESC';
                        
                        // Разрешенные поля для сортировки
                        $allowedSortFields = [
                            'c.date', 'u.nick', 's.server_name', 'c.type', 'c.msg'
                        ];
                        
                        if (!in_array($sortField, $allowedSortFields)) {
                            $sortField = 'c.date';
                        }
                        $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';
                        
                        // Подсчет общего количества
                        $countQuery = "SELECT COUNT(*) as total 
                                       FROM SP_users_chat c
                                       JOIN SP_users u ON c.uid = u.id
                                       JOIN syspanel_servers s ON c.server = s.id
                                       WHERE 1";
                        
                        $query = "SELECT c.*, u.nick, s.server_name 
                                  FROM SP_users_chat c
                                  JOIN SP_users u ON c.uid = u.id
                                  JOIN syspanel_servers s ON c.server = s.id
                                  WHERE 1";
                        
                        $conditions = [];
                        $params = [];
                        $types = '';
                        
                        if ($selectedServer) {
                            $conditions[] = "c.server = ?";
                            $params[] = $selectedServer;
                            $types .= 'i';
                        }
                        
                        if ($startDate) {
                            $conditions[] = "c.date >= ?";
                            $params[] = $startTimestamp;
                            $types .= 'i';
                        }
                        
                        if ($endDate) {
                            $conditions[] = "c.date <= ?";
                            $params[] = $endTimestamp;
                            $types .= 'i';
                        }
                        
                        if ($playerSearch) {
                            $conditions[] = "(u.nick LIKE ? OR u.steamid LIKE ? OR u.steamid64 LIKE ?)";
                            $params[] = "%$playerSearch%";
                            $params[] = "%$playerSearch%";
                            $params[] = "%$playerSearch%";
                            $types .= 'sss';
                        }
                        
                        if ($typeFilter) {
                            $conditions[] = "c.type = ?";
                            $params[] = $typeFilter;
                            $types .= 's';
                        }
                        
                        if ($msgSearch) {
                            $conditions[] = "c.msg LIKE ?";
                            $params[] = "%$msgSearch%";
                            $types .= 's';
                        }
                        
                        if (!empty($conditions)) {
                            $countQuery .= " AND " . implode(" AND ", $conditions);
                            $query .= " AND " . implode(" AND ", $conditions);
                        }
                        
                        // Получение общего количества
                        $countStmt = $db->prepare($countQuery);
                        if ($countStmt) {
                            if (!empty($params)) {
                                $countStmt->bind_param($types, ...$params);
                            }
                            $countStmt->execute();
                            $countResult = $countStmt->get_result();
                            $totalItems = $countResult->fetch_assoc()['total'];
                            $totalPages = ceil($totalItems / $itemsPerPage);
                            $countStmt->close();
                        } else {
                            $totalItems = 0;
                            $totalPages = 1;
                        }
                        
                        // Основной запрос с пагинацией
                        $query .= " ORDER BY $sortField $sortOrder LIMIT ? OFFSET ?";
                        $params[] = $itemsPerPage;
                        $params[] = $offset;
                        $types .= 'ii';
                        
                        $stmt = $db->prepare($query);
                        $chatMessages = [];
                        
                        if ($stmt) {
                            if (!empty($params)) {
                                $stmt->bind_param($types, ...$params);
                            }
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $chatMessages = $result->fetch_all(MYSQLI_ASSOC);
                            $stmt->close();
                        }
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'c.date', 'order' => ($sortField === 'c.date' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Дата
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'c.date') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.nick', 'order' => ($sortField === 'u.nick' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Игрок
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'u.nick') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 's.server_name', 'order' => ($sortField === 's.server_name' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Сервер
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 's.server_name') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'c.type', 'order' => ($sortField === 'c.type' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Тип
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'c.type') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'c.msg', 'order' => ($sortField === 'c.msg' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Сообщение
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'c.msg') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if (!empty($chatMessages)): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php foreach ($chatMessages as $message): ?>
                                            <tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatDate($message['date']) ?></td>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <a href="?<?= http_build_query(array_merge($_GET, ['player_id' => $message['uid']])) ?>" 
                                                       class="player-link">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= htmlspecialchars($message['nick']) ?>
                                                    </a>
                                                </td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($message['server_name']) ?></td>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php if ($message['type'] == 'say'): ?>
                                                        <span class="badge badge--general">Общий</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php elseif ($message['type'] == 'team_say'): ?>
                                                        <span class="badge badge--team">Команда</span>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php else: ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= htmlspecialchars($message['type']) ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php endif; ?>
                                                </td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($message['msg']) ?></td>
                                            </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php endforeach; ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="no-data">Нет сообщений в чате</td>
                                        </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php if ($totalPages > 1): ?>
                        <div class="pageNavWrapper">
                            <div class="pageNav">
                                <?php
                                $queryParams = $_GET;
                                unset($queryParams['page']);
                                
                                $baseUrl = '?' . http_build_query($queryParams);
                                $pageNav = '';
                                
                                // Кнопка "Назад"
                                if ($currentPage > 1) {
                                    $prevPage = $currentPage - 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $prevPage . '" class="pageNav-jump pageNav-jump--prev">«</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--prev pageNav-jump--disabled">«</span>';
                                }
                                
                                // Страницы
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1) {
                                    $pageNav .= '<a href="' . $baseUrl . '&page=1" class="pageNav-page">1</a>';
                                    if ($startPage > 2) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    if ($i == $currentPage) {
                                        $pageNav .= '<span class="pageNav-page pageNav-page--current">' . $i . '</span>';
                                    } else {
                                        $pageNav .= '<a href="' . $baseUrl . '&page=' . $i . '" class="pageNav-page">' . $i . '</a>';
                                    }
                                }
                                
                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $totalPages . '" class="pageNav-page">' . $totalPages . '</a>';
                                }
                                
                                // Кнопка "Вперед"
                                if ($currentPage < $totalPages) {
                                    $nextPage = $currentPage + 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $nextPage . '" class="pageNav-jump pageNav-jump--next">»</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--next pageNav-jump--disabled">»</span>';
                                }
                                
                                echo $pageNav;
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                ?>
                            </div>
                        </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php endif; ?>
                    
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <?php elseif ($selectedTab == 'players' || $selectedTab == 'top100'): ?>
                        <!-- Статистика игроков -->
                        <?php
                        $isTop100 = ($selectedTab == 'top100');
                        $limit = $isTop100 ? 100 : $itemsPerPage;
                        
                        // Параметры сортировки
                        $sortField = $_GET['sort'] ?? 'played_seconds';
                        $sortOrder = $_GET['order'] ?? 'DESC';
                        
                        // Разрешенные поля для сортировки
                        $allowedSortFields = [
                            'u.nick', 'u.steamid', 'u.ip', 'a.regdate', 
                            'a.activitydate', 'played_seconds', 'a.online'
                        ];
                        
                        if (!in_array($sortField, $allowedSortFields)) {
                            $sortField = 'played_seconds';
                        }
                        $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

                        // Оптимизированный запрос
                        $query = "SELECT 
                                    u.*,
                                    a.regdate,
                                    a.activitydate,
                                    COALESCE(SUM(IF(v.disconnect > 0, v.disconnect - v.connect, 0)), 0) as played_seconds,
                                    a.online
                                  FROM SP_users u
                                  LEFT JOIN SP_users_activity a ON u.id = a.uid
                                  LEFT JOIN SP_users_visits v ON v.uid = u.id 
                                      AND v.connect >= ? 
                                      AND v.connect <= ? 
                                      " . ($selectedServer ? " AND v.server = ?" : "") . "
                                  WHERE 1";
                        
                        // Параметры
                        $subParams = [];
                        $subParams[] = $startTimestamp;
                        $subParams[] = $endTimestamp;
                        if ($selectedServer) {
                            $subParams[] = $selectedServer;
                        }
                        
                        $conditions = [];
                        $params = $subParams;
                        $types = str_repeat('i', count($subParams));
                        
                        if ($playerSearch) {
                            $conditions[] = "(u.nick LIKE ? OR u.steamid LIKE ? OR u.steamid64 LIKE ? OR u.ip LIKE ?)";
                            $params[] = "%$playerSearch%";
                            $params[] = "%$playerSearch%";
                            $params[] = "%$playerSearch%";
                            $params[] = "%$playerSearch%";
                            $types .= 'ssss';
                        }
                        
                        if ($ipSearch) {
                            $conditions[] = "u.ip = ?";
                            $params[] = $ipSearch;
                            $types .= 's';
                        }
                        
                        if (!empty($conditions)) {
                            $query .= " AND " . implode(" AND ", $conditions);
                        }
                        
                        $query .= " GROUP BY u.id";
                        
                        // Запрос для подсчета количества
                        $countQuery = "SELECT COUNT(*) as total 
                                       FROM SP_users u
                                       LEFT JOIN SP_users_activity a ON u.id = a.uid
                                       WHERE 1";
                        
                        if (!empty($conditions)) {
                            $countQuery .= " AND " . implode(" AND ", $conditions);
                        }
                        
                        // Получение общего количества
                        $countStmt = $db->prepare($countQuery);
                        $totalItems = 0;
                        $totalPages = 1;
                        
                        if ($countStmt) {
                            $countParams = array_slice($params, count($subParams));
                            $countTypes = substr($types, count($subParams));
                            
                            if (!empty($countParams)) {
                                $countStmt->bind_param($countTypes, ...$countParams);
                            }
                            $countStmt->execute();
                            $countResult = $countStmt->get_result();
                            if ($countResult) {
                                $row = $countResult->fetch_assoc();
                                $totalItems = $row['total'] ?? 0;
                                $totalPages = $isTop100 ? 1 : ceil($totalItems / $itemsPerPage);
                            }
                            $countStmt->close();
                        }
                        
                        // Основной запрос
                        $query .= " ORDER BY $sortField $sortOrder";
                        
                        if (!$isTop100) {
                            $query .= " LIMIT ? OFFSET ?";
                            $params[] = $limit;
                            $params[] = $offset;
                            $types .= 'ii';
                        } else {
                            $query .= " LIMIT 100";
                        }
                        
                        $stmt = $db->prepare($query);
                        $players = [];
                        
                        if ($stmt) {
                            if (!empty($params)) {
                                $stmt->bind_param($types, ...$params);
                            }
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result) {
                                $players = $result->fetch_all(MYSQLI_ASSOC);
                            }
                            $stmt->close();
                        }
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.nick', 'order' => ($sortField === 'u.nick' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Ник
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'u.nick') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.steamid', 'order' => ($sortField === 'u.steamid' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                SteamID
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'u.steamid') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.ip', 'order' => ($sortField === 'u.ip' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                IP
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'u.ip') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'a.regdate', 'order' => ($sortField === 'a.regdate' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Регистрация
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'a.regdate') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'a.activitydate', 'order' => ($sortField === 'a.activitydate' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Последняя активность
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'a.activitydate') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'played_seconds', 'order' => ($sortField === 'played_seconds' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Общее время
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'played_seconds') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'a.online', 'order' => ($sortField === 'a.online' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Время онлайн
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?= ($sortField === 'a.online') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if (!empty($players)): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php foreach ($players as $index => $player): ?>
                                            <tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= $index + 1 ?></td>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <a href="?<?= http_build_query(array_merge($_GET, ['player_id' => $player['id']])) ?>" 
                                                       class="player-link">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= htmlspecialchars($player['nick']) ?>
                                                    </a>
                                                </td>
                                                <td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php if ($player['steamid64']): ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <a href="https://steamcommunity.com/profiles/<?= htmlspecialchars($player['steamid64']) ?>" target="_blank">
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                            <?= htmlspecialchars($player['steamid']) ?>
                                                        </a>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php else: ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <?= htmlspecialchars($player['steamid'] ?: '-') ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                    <?php endif; ?>
                                                </td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= htmlspecialchars($player['ip'] ?: '-') ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatDate($player['regdate']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatDate($player['activitydate']) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatTime($player['played_seconds'] / 60) ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <td><?= formatTime($player['online']) ?></td>
                                            </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                        <?php endforeach; ?>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="no-data">Игроки не найдены</td>
                                        </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php if (!$isTop100 && $totalPages > 1): ?>
                        <div class="pageNavWrapper">
                            <div class="pageNav">
                                <?php
                                $queryParams = $_GET;
                                unset($queryParams['page']);
                                
                                $baseUrl = '?' . http_build_query($queryParams);
                                $pageNav = '';
                                
                                // Кнопка "Назад"
                                if ($currentPage > 1) {
                                    $prevPage = $currentPage - 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $prevPage . '" class="pageNav-jump pageNav-jump--prev">«</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--prev pageNav-jump--disabled">«</span>';
                                }
                                
                                // Страницы
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1) {
                                    $pageNav .= '<a href="' . $baseUrl . '&page=1" class="pageNav-page">1</a>';
                                    if ($startPage > 2) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    if ($i == $currentPage) {
                                        $pageNav .= '<span class="pageNav-page pageNav-page--current">' . $i . '</span>';
                                    } else {
                                        $pageNav .= '<a href="' . $baseUrl . '&page=' . $i . '" class="pageNav-page">' . $i . '</a>';
                                    }
                                }
                                
                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        $pageNav .= '<span class="pageNav-ellipsis">...</span>';
                                    }
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $totalPages . '" class="pageNav-page">' . $totalPages . '</a>';
                                }
                                
                                // Кнопка "Вперед"
                                if ($currentPage < $totalPages) {
                                    $nextPage = $currentPage + 1;
                                    $pageNav .= '<a href="' . $baseUrl . '&page=' . $nextPage . '" class="pageNav-jump pageNav-jump--next">»</a>';
                                } else {
                                    $pageNav .= '<span class="pageNav-jump pageNav-jump--next pageNav-jump--disabled">»</span>';
                                }
                                
                                echo $pageNav;
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                ?>
                            </div>
                        </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                        <?php endif; ?>
                    
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <?php elseif ($selectedTab == 'additional'): ?>
                        <!-- Вкладка "Дополнительно" -->
                        <div class="additional-tab">
                            <div class="charts-row">
                                <div class="chart-container">
                                    <h3>Топ карт по времени игры</h3>
                                    <canvas id="mapsChart"></canvas>
                                    
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if (!empty($mapsData['maps'])): ?>
                                    <div class="dataList" style="margin-top: 20px;">
                                        <table class="dataList-table">
                                            <thead>
                                                <tr>
                                                    <th>Карта</th>
                                                    <th>Время игры</th>
                                                </tr>
                                            </thead>
                                            <tbody>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?php foreach ($mapsData['maps'] as $index => $map): ?>
                                                    <tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <td><?= $map ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <td><?= formatTime($mapsData['playtime'][$index] / 60) ?></td>
                                                    </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </div>
                                
                                <div class="chart-container">
                                    <h3>Топ стран по подключениям</h3>
                                    <canvas id="countriesChart"></canvas>
                                    
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php if (!empty($countriesData['countries'])): ?>
                                    <div class="dataList" style="margin-top: 20px;">
                                        <table class="dataList-table">
                                            <thead>
                                                <tr>
                                                    <th>Страна</th>
                                                    <th>Подключения</th>
                                                </tr>
                                            </thead>
                                            <tbody>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?php foreach ($countriesData['countries'] as $index => $country): ?>
                                                    <tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <td><?= $country ?></td>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                        <td><?= $countriesData['connections'][$index] ?></td>
                                                    </tr>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
                    <?php endif; ?>
                </div>
$jsData = [
    "activityDates" => $activityData["dates"],
    "activityConnections" => $activityData["connections"],
    "activityPlaytime" => $activityData["playtime"],
    "maps" => $mapsData["maps"] ?? [],
    "mapsPlaytime" => array_map(function($pt){ return $pt / 3600; }, $mapsData["playtime"] ?? []),
    "countries" => $countriesData["countries"] ?? [],
    "countriesConnections" => $countriesData["connections"] ?? [],
    "selectedTab" => $selectedTab
];
            <?php endif; ?>
        </div>
    </div>
</div>



<?php
// ================= КОНЕЦ СКРИПТА =================

// Получаем сгенерированный контент
$content = ob_get_clean();
$templater = XF::app()->templater();
$content = $templater->renderTemplate('public:stats_page', ['content' => $content]);

// Настройки страницы для XenForo
\ScriptsPages\Setup::set([
    'title' => 'Статистика игроков',
    'breadcrumbs' => [
        'Главная' => '/',
        'Статистика' => ''
    ],
    'content' => $content,
    'navigation_id' => 'stats_page',
    'metadata' => true,
    'raw' => false
]);

// Запуск приложения
$app->run()->send($request);
