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
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="block">
    <div class="block-container">
        <div class="block-header">
            <?php if ($playerProfile): ?>
                <h2 class="block-title">Статистика игрока: <?= htmlspecialchars($playerProfile['nick']) ?></h2>
            <?php else: ?>
                <h2 class="block-title">Статистика игроков</h2>
            <?php endif; ?>
        </div>
        
        <div class="block-body">
            <?php if (!$playerProfile): ?>
                <!-- Информационные карточки -->
                <div class="info-cards">
                    <div class="info-card">
                        <div class="info-card-title">Уникальные подключения за период</div>
                        <div class="info-card-value"><?= $periodStats['unique_players'] ?></div>
                        <div class="info-card-subtext">
                            +<?= $todayStats['unique_players'] ?> за сегодня
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-card-title">Время игры за период</div>
                        <div class="info-card-value"><?= formatTime($periodStats['playtime'] / 60) ?></div>
                        <div class="info-card-subtext">
                            +<?= formatTime($todayStats['playtime'] / 60) ?> за сегодня
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <div class="info-card-title">Всего уникальных</div>
                        <div class="info-card-value"><?= $totalStats['unique_players'] ?></div>
                        <div class="info-card-subtext">
                            Всего времени: <?= formatTime($totalStats['playtime'] / 60) ?>
                        </div>
                    </div>
                </div>
                
                <!-- Отступ между карточками и графиком -->
                <div class="spacer-lg"></div>
            <?php endif; ?>
            
            <?php if (!$playerProfile || $playerId): ?>
                <!-- График активности -->
                <div class="chart-container">
                    <canvas id="activityChart" height="200"></canvas>
                </div>
                
                <!-- Отступ между графиком и фильтрами -->
                <div class="spacer"></div>
            <?php endif; ?>
            
            <!-- Фильтры -->
            <div class="filters">
                <form method="get" class="filter-form" id="filtersForm">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($selectedTab) ?>">
                    <input type="hidden" name="page" value="1">
                    
                    <div class="filter-group">
                        <h3 class="filter-header-title">Фильтры:</h3>
                        
                        <div class="filter-row">
                            <div class="filter-item">
                                <label>Сервер:</label>
                                <select name="server" class="input input--select">
                                    <option value="0">Все серверы</option>
                                    <?php foreach ($servers as $id => $server): ?>
                                        <option value="<?= $id ?>" <?= $selectedServer == $id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($server['server_shortname']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label>Период:</label>
                                <input type="text" name="date_range" class="input date-range" 
                                       placeholder="Выберите даты" 
                                       data-start="<?= htmlspecialchars($startDate) ?>" 
                                       data-end="<?= htmlspecialchars($endDate) ?>">
                                <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                                <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                            </div>
                        </div>
                        
                        <div class="filter-row">
                            <div class="filter-item">
                                <label>Игрок:</label>
                                <input type="text" name="player" class="input" placeholder="Ник или SteamID" value="<?= htmlspecialchars($playerSearch) ?>">
                            </div>
                            
                            <div class="filter-item">
                                <label>IP-адрес:</label>
                                <input type="text" name="ip" class="input" placeholder="xxx.xxx.xxx.xxx" value="<?= htmlspecialchars($ipSearch) ?>">
                            </div>
                        </div>
                        
                        <?php if ($selectedTab == 'chat'): ?>
                        <div class="filter-row">
                            <div class="filter-item">
                                <label>Тип сообщения:</label>
                                <select name="type" class="input input--select">
                                    <option value="">Все типы</option>
                                    <option value="say" <?= $typeFilter == 'say' ? 'selected' : '' ?>>Общий чат</option>
                                    <option value="team_say" <?= $typeFilter == 'team_say' ? 'selected' : '' ?>>Командный чат</option>
                                </select>
                            </div>
                            
                            <div class="filter-item">
                                <label>Поиск по тексту:</label>
                                <input type="text" name="msg" class="input" placeholder="Текст сообщения..." value="<?= htmlspecialchars($msgSearch) ?>">
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="filter-row">
                            <button type="submit" class="button button--primary">Применить фильтры</button>
                            <a href="?" class="button button--link">Сбросить фильтры</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php if ($playerProfile): ?>
                <!-- Профиль игрока -->
                <div class="player-profile">
                    <a href="?<?= http_build_query(array_diff_key($_GET, ['player_id' => ''])) ?>" class="button button--primary" style="margin-bottom: 20px;">Назад к статистике</a>
                    
                    <div class="profile-header">
                        <?php if ($playerProfile['steamid64']): ?>
                            <div class="profile-avatar">
                                <?php
                                $avatarUrl = 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7e1997310d705b2a6158ff8dc1cdfeb_full.jpg';
                                if ($steamInfo && isset($steamInfo[$playerProfile['steamid64']]['avatar'])) {
                                    $avatarUrl = $steamInfo[$playerProfile['steamid64']]['avatar'];
                                }
                                ?>
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" 
                                     alt="Аватар <?= htmlspecialchars($playerProfile['nick']) ?>"
                                     onerror="this.onerror=null;this.src='https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7e1997310d705b2a6158ff8dc1cdfeb_full.jpg'">
                            </div>
                        <?php endif; ?>
                        
                        <div class="profile-header-main">
                            <h2 class="profile-title">Профиль игрока: <?= htmlspecialchars($playerProfile['nick']) ?></h2>
                            
                            <!-- Статистика игрока -->
                            <div class="info-cards">
                                <div class="info-card">
                                    <div class="info-card-title">Подключения за период</div>
                                    <div class="info-card-value"><?= $playerPeriodStats['connections'] ?></div>
                                    <div class="info-card-subtext">
                                        <?= $playerTodayStats['connections'] ?> за сегодня
                                    </div>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Время игры за период</div>
                                    <div class="info-card-value"><?= formatTime($playerPeriodStats['playtime'] / 60) ?></div>
                                    <div class="info-card-subtext">
                                        +<?= formatTime($playerTodayStats['playtime'] / 60) ?> за сегодня
                                    </div>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-card-title">Всего времени в игре</div>
                                    <div class="info-card-value"><?= formatTime($playerTotalStats['playtime'] / 60) ?></div>
                                    <div class="info-card-subtext">
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
                                    <?php if ($playerProfile['steamid64']): ?>
                                        <a href="https://steamcommunity.com/profiles/<?= htmlspecialchars($playerProfile['steamid64']) ?>" target="_blank">
                                            <?= htmlspecialchars($playerProfile['steamid']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($playerProfile['steamid'] ?: '-') ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">IP-адрес:</span>
                                <span class="info-value"><?= htmlspecialchars($playerProfile['ip'] ?: '-') ?></span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">Дата регистрации:</span>
                                <span class="info-value"><?= formatDate($playerProfile['regdate']) ?></span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">Последняя активность:</span>
                                <span class="info-value"><?= formatDate($playerProfile['activitydate']) ?></span>
                            </div>
                            
                            <div class="profile-info-item">
                                <span class="info-label">Время онлайн:</span>
                                <span class="info-value"><?= formatTime($playerProfile['online']) ?></span>
                            </div>
                            
                            <?php if ($steamInfo && isset($steamInfo[$playerProfile['steamid64']]['timecreated']) && $steamInfo[$playerProfile['steamid64']]['timecreated'] > 0): ?>
                                <div class="profile-info-item">
                                    <span class="info-label">Профиль Steam создан:</span>
                                    <span class="info-value"><?= formatDate($steamInfo[$playerProfile['steamid64']]['timecreated']) ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($tf2Playtime > 0): ?>
                                <div class="profile-info-item">
                                    <span class="info-label">Время в TF2:</span>
                                    <span class="info-value"><?= formatTime($tf2Playtime) ?></span>
                                </div>
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
                                    <?php if (!empty($visits)): ?>
                                        <?php foreach ($visits as $visit): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($visit['server_name']) ?></td>
                                                <td><?= formatDate($visit['connect']) ?></td>
                                                <td><?= $visit['disconnect'] ? formatDate($visit['disconnect']) : 'В сети' ?></td>
                                                <td>
                                                    <?php if ($visit['disconnect']): ?>
                                                        <?= formatTime(($visit['disconnect'] - $visit['connect']) / 60) ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($visit['map'] ?: '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="no-data">Нет данных о посещениях</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
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
                                ?>
                            </div>
                        </div>
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
                                    <?php if (!empty($chatMessages)): ?>
                                        <?php foreach ($chatMessages as $message): ?>
                                            <tr>
                                                <td><?= formatDate($message['date']) ?></td>
                                                <td><?= htmlspecialchars($message['server_name']) ?></td>
                                                <td>
                                                    <?php if ($message['type'] == 'say'): ?>
                                                        <span class="badge badge--general">Общий</span>
                                                    <?php elseif ($message['type'] == 'team_say'): ?>
                                                        <span class="badge badge--team">Команда</span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($message['type']) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($message['msg']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="no-data">Нет сообщений в чате</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
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
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Вкладки -->
                <div class="tabs">
                    <a href="?tab=summary&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'summary' ? 'active' : '' ?>">Обзор</a>
                    <a href="?tab=visits&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'visits' ? 'active' : '' ?>">Посещения</a>
                    <a href="?tab=chat&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'chat' ? 'active' : '' ?>">Лог чата</a>
                    <a href="?tab=players&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'players' ? 'active' : '' ?>">Статистика игроков</a>
                    <a href="?tab=top100&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'top100' ? 'active' : '' ?>">Топ 100</a>
                    <a href="?tab=additional&<?= http_build_query(array_merge(['page' => 1], array_diff_key($_GET, ['tab'=>'', 'page'=>'']))) ?>" class="tab <?= $selectedTab == 'additional' ? 'active' : '' ?>">Дополнительно</a>
                </div>
                
                <!-- Контент вкладок -->
                <div class="tab-content">
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
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.nick', 'order' => ($sortField === 'u.nick' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Игрок
                                                <?= ($sortField === 'u.nick') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 's.server_name', 'order' => ($sortField === 's.server_name' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Сервер
                                                <?= ($sortField === 's.server_name') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.connect', 'order' => ($sortField === 'v.connect' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Подключение
                                                <?= ($sortField === 'v.connect') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.disconnect', 'order' => ($sortField === 'v.disconnect' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Отключение
                                                <?= ($sortField === 'v.disconnect') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'duration', 'order' => ($sortField === 'duration' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Длительность
                                                <?= ($sortField === 'duration') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.map', 'order' => ($sortField === 'v.map' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Карта
                                                <?= ($sortField === 'v.map') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.ip', 'order' => ($sortField === 'v.ip' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                IP
                                                <?= ($sortField === 'v.ip') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'v.geo', 'order' => ($sortField === 'v.geo' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Гео
                                                <?= ($sortField === 'v.geo') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($visits)): ?>
                                        <?php foreach ($visits as $visit): ?>
                                            <tr>
                                                <td>
                                                    <a href="?<?= http_build_query(array_merge($_GET, ['player_id' => $visit['uid']])) ?>" 
                                                       class="player-link">
                                                        <?= htmlspecialchars($visit['nick']) ?>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($visit['server_name']) ?></td>
                                                <td><?= formatDate($visit['connect']) ?></td>
                                                <td><?= $visit['disconnect'] ? formatDate($visit['disconnect']) : 'В сети' ?></td>
                                                <td>
                                                    <?php if ($visit['disconnect']): ?>
                                                        <?= formatTime(($visit['disconnect'] - $visit['connect']) / 60) ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($visit['map'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($visit['ip']) ?></td>
                                                <td><?= htmlspecialchars($visit['geo']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="no-data">Нет данных о посещениях</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
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
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    
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
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'c.date', 'order' => ($sortField === 'c.date' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Дата
                                                <?= ($sortField === 'c.date') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.nick', 'order' => ($sortField === 'u.nick' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Игрок
                                                <?= ($sortField === 'u.nick') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 's.server_name', 'order' => ($sortField === 's.server_name' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Сервер
                                                <?= ($sortField === 's.server_name') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'c.type', 'order' => ($sortField === 'c.type' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Тип
                                                <?= ($sortField === 'c.type') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'c.msg', 'order' => ($sortField === 'c.msg' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Сообщение
                                                <?= ($sortField === 'c.msg') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($chatMessages)): ?>
                                        <?php foreach ($chatMessages as $message): ?>
                                            <tr>
                                                <td><?= formatDate($message['date']) ?></td>
                                                <td>
                                                    <a href="?<?= http_build_query(array_merge($_GET, ['player_id' => $message['uid']])) ?>" 
                                                       class="player-link">
                                                        <?= htmlspecialchars($message['nick']) ?>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars($message['server_name']) ?></td>
                                                <td>
                                                    <?php if ($message['type'] == 'say'): ?>
                                                        <span class="badge badge--general">Общий</span>
                                                    <?php elseif ($message['type'] == 'team_say'): ?>
                                                        <span class="badge badge--team">Команда</span>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($message['type']) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($message['msg']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="no-data">Нет сообщений в чате</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
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
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    
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
                        ?>
                        
                        <div class="dataList">
                            <table class="dataList-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.nick', 'order' => ($sortField === 'u.nick' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Ник
                                                <?= ($sortField === 'u.nick') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.steamid', 'order' => ($sortField === 'u.steamid' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                SteamID
                                                <?= ($sortField === 'u.steamid') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'u.ip', 'order' => ($sortField === 'u.ip' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                IP
                                                <?= ($sortField === 'u.ip') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'a.regdate', 'order' => ($sortField === 'a.regdate' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Регистрация
                                                <?= ($sortField === 'a.regdate') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'a.activitydate', 'order' => ($sortField === 'a.activitydate' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Последняя активность
                                                <?= ($sortField === 'a.activitydate') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'played_seconds', 'order' => ($sortField === 'played_seconds' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Общее время
                                                <?= ($sortField === 'played_seconds') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                        <th>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'a.online', 'order' => ($sortField === 'a.online' && $sortOrder === 'ASC') ? 'DESC' : 'ASC'])) ?>">
                                                Время онлайн
                                                <?= ($sortField === 'a.online') ? ($sortOrder === 'ASC' ? '▲' : '▼') : '' ?>
                                            </a>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($players)): ?>
                                        <?php foreach ($players as $index => $player): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td>
                                                    <a href="?<?= http_build_query(array_merge($_GET, ['player_id' => $player['id']])) ?>" 
                                                       class="player-link">
                                                        <?= htmlspecialchars($player['nick']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php if ($player['steamid64']): ?>
                                                        <a href="https://steamcommunity.com/profiles/<?= htmlspecialchars($player['steamid64']) ?>" target="_blank">
                                                            <?= htmlspecialchars($player['steamid']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($player['steamid'] ?: '-') ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($player['ip'] ?: '-') ?></td>
                                                <td><?= formatDate($player['regdate']) ?></td>
                                                <td><?= formatDate($player['activitydate']) ?></td>
                                                <td><?= formatTime($player['played_seconds'] / 60) ?></td>
                                                <td><?= formatTime($player['online']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="no-data">Игроки не найдены</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
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
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    
                    <?php elseif ($selectedTab == 'additional'): ?>
                        <!-- Вкладка "Дополнительно" -->
                        <div class="additional-tab">
                            <div class="charts-row">
                                <div class="chart-container">
                                    <h3>Топ карт по времени игры</h3>
                                    <canvas id="mapsChart"></canvas>
                                    
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
                                                <?php foreach ($mapsData['maps'] as $index => $map): ?>
                                                    <tr>
                                                        <td><?= $map ?></td>
                                                        <td><?= formatTime($mapsData['playtime'][$index] / 60) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="chart-container">
                                    <h3>Топ стран по подключениям</h3>
                                    <canvas id="countriesChart"></canvas>
                                    
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
                                                <?php foreach ($countriesData['countries'] as $index => $country): ?>
                                                    <tr>
                                                        <td><?= $country ?></td>
                                                        <td><?= $countriesData['connections'][$index] ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Общие стили - интегрированные с XF2 */
.block {
    background-color: var(--xf-contentBg);
    border: 1px solid var(--xf-borderColor);
    border-radius: var(--xf-borderRadiusMedium);
    margin-bottom: var(--xf-paddingLarge);
    box-shadow: var(--xf-boxShadow);
}

.block-container {
    padding: var(--xf-paddingLarge);
}

.block-header {
    border-bottom: 1px solid var(--xf-borderColor);
    padding-bottom: var(--xf-paddingMedium);
    margin-bottom: var(--xf-paddingLarge);
}

.block-title {
    font-size: var(--xf-fontSizeLarge);
    color: var(--xf-textColor);
    margin: 0;
}

.filters {
    background-color: var(--xf-contentAltBg);
    border-radius: var(--xf-borderRadiusMedium);
    padding: var(--xf-paddingLarge);
    margin-bottom: var(--xf-paddingLarge);
    border: 1px solid var(--xf-borderColor);
}

.filter-group {
    padding: 15px;
}

.filter-header-title {
    color: var(--xf-textColorMuted);
    font-size: var(--xf-fontSizeNormal);
    margin-top: 0;
    margin-bottom: var(--xf-paddingMedium);
    padding: 0 5px;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--xf-paddingMedium);
    margin-bottom: var(--xf-paddingMedium);
}

.filter-item {
    flex: 1;
    min-width: 250px;
}

.filter-item label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: var(--xf-textColor);
    padding: 0 5px;
}

.input {
    padding: 10px 12px;
    border: 1px solid var(--xf-borderColor);
    border-radius: var(--xf-borderRadiusSmall);
    width: 100%;
    box-sizing: border-box;
    background-color: var(--xf-contentBg);
    color: var(--xf-textColor);
}

.input:focus {
    border-color: var(--xf-textSelectionColor);
    outline: none;
    box-shadow: 0 0 0 2px rgba(red(var(--xf-textSelectionColor)), green(var(--xf-textSelectionColor)), blue(var(--xf-textSelectionColor)), 0.15);
}

.input--select {
    height: 40px;
}

.button {
    display: inline-block;
    padding: 10px 16px;
    border-radius: var(--xf-borderRadiusSmall);
    font-size: var(--xf-fontSizeNormal);
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid transparent;
    text-decoration: none;
    white-space: nowrap;
}

.button--primary {
    background-color: var(--xf-buttonPrimaryBg);
    color: var(--xf-buttonPrimaryColor);
    border-color: var(--xf-buttonPrimaryBg);
}

.button--primary:hover {
    background-color: var(--xf-buttonPrimaryBgActive);
    border-color: var(--xf-buttonPrimaryBgActive);
}

.button--link {
    background-color: transparent;
    color: var(--xf-linkColor);
    border: 1px solid transparent;
}

.button--link:hover {
    color: var(--xf-linkHoverColor);
    text-decoration: underline;
}

.tabs {
    display: flex;
    border-bottom: 1px solid var(--xf-borderColor);
    margin-bottom: var(--xf-paddingLarge);
    flex-wrap: wrap;
}

.tab {
    padding: 10px 20px;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: var(--xf-borderRadiusMedium) var(--xf-borderRadiusMedium) 0 0;
    text-decoration: none;
    color: var(--xf-textColorMuted);
    font-weight: 600;
    margin-right: 5px;
    margin-bottom: -1px;
}

.tab.active {
    background-color: var(--xf-contentBg);
    border-color: var(--xf-borderColor);
    color: var(--xf-textColor);
    position: relative;
    top: 1px;
}

.tab-content {
    background-color: var(--xf-contentBg);
    border: 1px solid var(--xf-borderColor);
    border-radius: var(--xf-borderRadiusMedium);
    padding: var(--xf-paddingLarge);
}

/* Информационные карточки */
.info-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--xf-paddingLarge);
}

.info-card {
    background-color: var(--xf-contentAltBg);
    border-radius: var(--xf-borderRadiusMedium);
    padding: var(--xf-paddingLarge);
    box-shadow: var(--xf-boxShadow);
    text-align: center;
    border: 1px solid var(--xf-borderColor);
}

.info-card-title {
    font-size: var(--xf-fontSizeNormal);
    color: var(--xf-textColorMuted);
    margin-bottom: 10px;
}

.info-card-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--xf-textColor);
    margin-bottom: 5px;
}

.info-card-subtext {
    font-size: var(--xf-fontSizeSmall);
    color: var(--xf-textColorAccent);
    font-weight: 600;
}

/* Графики */
.chart-container {
    background-color: var(--xf-contentAltBg);
    border-radius: var(--xf-borderRadiusMedium);
    padding: var(--xf-paddingLarge);
    box-shadow: var(--xf-boxShadow);
    min-height: 300px;
    border: 1px solid var(--xf-borderColor);
    display: flex;
    flex-direction: column;
}

.chart-container canvas {
    width: 100% !important;
    height: auto !important;
    max-height: 400px;
    flex-grow: 1;
}

.charts-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--xf-paddingLarge);
}

.charts-row .chart-container {
    flex: 1 1 45%;
    min-width: 300px;
}

/* Отступы */
.spacer {
    height: 20px;
}

.spacer-lg {
    height: 30px;
}

/* Таблицы */
.dataList {
    overflow-x: auto;
    margin-top: 15px;
}

.dataList-table {
    width: 100%;
    border-collapse: collapse;
    background-color: var(--xf-contentBg);
    border: 1px solid var(--xf-borderColor);
}

.dataList-table th {
    background-color: var(--xf-contentAltBg);
    padding: 12px 15px;
    text-align: left;
    font-weight: 600;
    border-bottom: 1px solid var(--xf-borderColor);
    color: var(--xf-textColor);
}

.dataList-table th a {
    color: var(--xf-textColor);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
}

.dataList-table td {
    padding: 12px 15px;
    border-bottom: 1px solid var(--xf-borderColor);
    color: var(--xf-textColor);
}

.dataList-table tr:last-child td {
    border-bottom: none;
}

.dataList-table tr:hover td {
    background-color: var(--xf-contentHighlightBg);
}

.no-data {
    text-align: center;
    padding: 20px;
    color: var(--xf-textColorMuted);
}

.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: var(--xf-borderRadiusSmall);
    font-size: var(--xf-fontSizeSmall);
    font-weight: 600;
}

.badge--general {
    background-color: rgba(54, 162, 235, 0.2);
    color: #36a2eb;
}

.badge--team {
    background-color: rgba(255, 99, 132, 0.2);
    color: #ff6384;
}

/* Профиль игрока */
.player-profile {
    background-color: var(--xf-contentAltBg);
    border-radius: var(--xf-borderRadiusMedium);
    padding: var(--xf-paddingLarge);
    box-shadow: var(--xf-boxShadow);
    border: 1px solid var(--xf-borderColor);
}

.profile-header {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    align-items: flex-start;
}

.profile-avatar img {
    width: 150px;
    height: 150px;
    border-radius: 0;
    object-fit: cover;
    border: 2px solid var(--xf-borderColor);
}

.profile-header-main {
    flex: 1;
}

.profile-title {
    margin-top: 0;
    margin-bottom: var(--xf-paddingLarge);
    padding-bottom: var(--xf-paddingMedium);
    border-bottom: 1px solid var(--xf-borderColor);
    color: var(--xf-textColor);
}

.profile-info {
    margin-top: var(--xf-paddingLarge);
    background: var(--xf-contentBg);
    border: 1px solid var(--xf-borderColor);
    border-radius: var(--xf-borderRadiusMedium);
    padding: 15px;
}

.profile-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--xf-paddingMedium);
}

.profile-info-item {
    background: var(--xf-contentAltBg);
    border: 1px solid var(--xf-borderColor);
    border-radius: var(--xf-borderRadiusSmall);
    padding: 10px 15px;
}

.info-label {
    font-size: var(--xf-fontSizeSmall);
    color: var(--xf-textColorMuted);
    margin-bottom: 5px;
}

.info-value {
    font-size: var(--xf-fontSizeNormal);
    font-weight: 600;
    color: var(--xf-textColor);
}

.profile-section {
    margin-top: var(--xf-paddingLarge);
    padding-top: var(--xf-paddingLarge);
    border-top: 1px solid var(--xf-borderColor);
}

.profile-section:first-child {
    margin-top: 0;
    padding-top: 0;
    border-top: none;
}

.profile-section h3 {
    margin-top: 0;
    margin-bottom: var(--xf-paddingMedium);
    padding-bottom: var(--xf-paddingMedium);
    border-bottom: 1px solid var(--xf-borderColor);
    color: var(--xf-textColor);
}

/* Ссылки на игроков */
.player-link {
    color: var(--xf-linkColor);
    text-decoration: none;
    font-weight: 600;
}

.player-link:hover {
    color: var(--xf-linkHoverColor);
    text-decoration: underline;
}

/* Пагинация */
.pageNavWrapper {
    margin-top: 20px;
}

.pageNav {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 5px;
}

.pageNav-jump,
.pageNav-page {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    border: 1px solid var(--xf-borderColor);
    border-radius: 4px;
    font-size: 13px;
    line-height: 1;
    text-decoration: none;
}

.pageNav-jump--prev:before {
    content: "«";
}

.pageNav-jump--next:before {
    content: "»";
}

.pageNav-jump--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pageNav-page {
    background-color: var(--xf-contentAltBg);
}

.pageNav-page--current {
    background-color: var(--xf-buttonPrimaryBg);
    color: var(--xf-buttonPrimaryColor);
    border-color: var(--xf-buttonPrimaryBg);
}

.pageNav-ellipsis {
    padding: 0 8px;
}

/* Адаптивность */
@media (max-width: 900px) {
    .filter-row {
        flex-direction: column;
        gap: 10px;
    }
    
    .filter-item {
        min-width: 100%;
    }
    
    .info-cards {
        grid-template-columns: 1fr;
    }
    
    .profile-header {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .profile-info-grid {
        grid-template-columns: 1fr;
    }
    
    .tabs {
        flex-direction: column;
    }
    
    .tab {
        width: 100%;
        margin-right: 0;
        border-radius: var(--xf-borderRadiusSmall);
        margin-bottom: 5px;
    }
    
    .tab.active {
        border-bottom: 1px solid var(--xf-borderColor);
    }
    
    .charts-row {
        flex-direction: column;
    }
    
    .charts-row .chart-container {
        flex: 1 1 100%;
    }
}

@media (max-width: 480px) {
    .filter-item input, 
    .filter-item select {
        width: 100%;
    }
    
    .dataList-table th,
    .dataList-table td {
        padding: 8px 10px;
        font-size: var(--xf-fontSizeSmall);
    }
    
    .tab {
        padding: 8px 12px;
        font-size: var(--xf-fontSizeSmall);
    }
    
    .pageNav {
        flex-direction: column;
        align-items: center;
    }
    
    .pageNav a {
        width: 100%;
        text-align: center;
        margin-bottom: 5px;
    }
    
    .info-card {
        padding: var(--xf-paddingMedium);
    }
    
    .info-card-value {
        font-size: 24px;
    }
    
    .profile-avatar img {
        width: 120px;
        height: 120px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация календаря
    const dateRangeInput = document.querySelector('.date-range');
    const startDateInput = document.querySelector('input[name="start_date"]');
    const endDateInput = document.querySelector('input[name="end_date"]');
    
    if (dateRangeInput) {
        flatpickr(dateRangeInput, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            defaultDate: [startDateInput.value, endDateInput.value],
            onChange: function(selectedDates, dateStr, instance) {
                if (selectedDates.length === 2) {
                    startDateInput.value = selectedDates[0] ? selectedDates[0].toISOString().split('T')[0] : '';
                    endDateInput.value = selectedDates[1] ? selectedDates[1].toISOString().split('T')[0] : '';
                }
            }
        });
    }
    
    // График активности (два графика)
    const activityCtx = document.getElementById('activityChart')?.getContext('2d');
    if (activityCtx) {
        const activityChart = new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($activityData['dates']) ?>,
                datasets: [
                    {
                        label: 'Подключения',
                        data: <?= json_encode($activityData['connections']) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(54, 162, 235, 1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Время игры (часы)',
                        data: <?= json_encode($activityData['playtime']) ?>,
                        backgroundColor: 'rgba(255, 159, 64, 0.1)',
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(255, 159, 64, 1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            color: 'rgba(0, 0, 0, 0.7)'
                        },
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Подключения'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            color: 'rgba(255, 159, 64, 1)'
                        },
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Часы игры'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: 'rgba(116, 118, 121, 0.72)'
                        }
                    }
                }
            }
        });
    }
    
    <?php if ($selectedTab == 'additional'): ?>
        // График по картам
        const mapsCtx = document.getElementById('mapsChart')?.getContext('2d');
        if (mapsCtx) {
            const mapsChart = new Chart(mapsCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($mapsData['maps']) ?>,
                    datasets: [{
                        label: 'Время игры (часы)',
                        data: <?= json_encode(array_map(function($pt) { return $pt / 3600; }, $mapsData['playtime'])) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.6)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Часы игры'
                            }
                        }
                    }
                }
            });
        }
        
        // График по странам
        const countriesCtx = document.getElementById('countriesChart')?.getContext('2d');
        if (countriesCtx) {
            const countriesChart = new Chart(countriesCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($countriesData['countries']) ?>,
                    datasets: [{
                        label: 'Подключения',
                        data: <?= json_encode($countriesData['connections']) ?>,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.6)',
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 206, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(255, 159, 64, 0.6)',
                            'rgba(199, 199, 199, 0.6)',
                            'rgba(83, 102, 255, 0.6)',
                            'rgba(40, 159, 64, 0.6)',
                            'rgba(210, 99, 132, 0.6)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(199, 199, 199, 1)',
                            'rgba(83, 102, 255, 1)',
                            'rgba(40, 159, 64, 1)',
                            'rgba(210, 99, 132, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
        }
    <?php endif; ?>
});
</script>

<?php
// ================= КОНЕЦ СКРИПТА =================

// Получаем сгенерированный контент
$content = ob_get_clean();

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
