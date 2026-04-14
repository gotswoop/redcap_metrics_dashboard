<?php
/**
 * REDCap Metrics Dashboard
 *
 * Configuration:
 *	Edit config.php to change the REDCap connect file, dashboard title,
 *	instance name, and subtitle.
 *
 * Connection support:
 *	- REDCap connect file (/path/to/redcap_connect.php)
 *	- PDO ($pdo or $db)
 *	- MySQLi ($conn)
 *	- Fallback environment variables if no connect file is available
 *
 * Usage:
 *	 php generate.php --output=/path/to/metrics/index.html
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$config = require __DIR__ . '/config.php';

$connectFile = $config['connect_file'];

$pdo = null;
$mysqli = null;

if (is_file($connectFile)) {
	require_once $connectFile;
	if (isset($pdo) && $pdo instanceof PDO) {
		// use $pdo
	} elseif (isset($db) && $db instanceof PDO) {
		$pdo = $db;
	} elseif (isset($conn) && $conn instanceof mysqli) {
		$mysqli = $conn;
	}
}

if (!$pdo instanceof PDO && !$mysqli instanceof mysqli) {
	$host = getenv('REDCAP_DB_HOST') ?: '127.0.0.1';
	$name = getenv('REDCAP_DB_NAME') ?: 'redcap';
	$user = getenv('REDCAP_DB_USER') ?: 'root';
	$pass = getenv('REDCAP_DB_PASS') ?: '';
	$port = (int)(getenv('REDCAP_DB_PORT') ?: 3306);

	$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
	$pdo = new PDO($dsn, $user, $pass, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);
}

function h(?string $value): string {
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nfmt($value, int $decimals = 0): string {
	if ($value === null || $value === '') return '0';
	return number_format((float)$value, $decimals);
}

function pct($part, $whole, int $decimals = 1): string {
	$whole = (float)$whole;
	$part = (float)$part;
	if ($whole <= 0) return '0.0%';
	return number_format(($part / $whole) * 100, $decimals) . '%';
}

function dtOrBlank(?string $value): string {
	if (!$value) return '—';
	try {
		return (new DateTimeImmutable($value))->format('M j, Y');
	} catch (Throwable $e) {
		return h($value);
	}
}

function dtOrBlankPlus(?string $value): string {
	if (!$value) return '—';
	try {
		return (new DateTimeImmutable($value))->format('M j, Y g:i A');
	} catch (Throwable $e) {
		return h($value);
	}
}

function badgeClass(string $value): string {
	$v = strtolower(trim($value));
	if ($v === 'production' || $v === 'active' || str_contains($v, 'enabled')) return 'good';
	if ($v === 'inactive' || $v === 'suspended' || str_contains($v, 'disabled')) return 'bad';
	if ($v === 'development' || $v === 'draft' || str_contains($v, 'testing')) return 'warn';
	return 'neutral';
}

function rowBadge(string $label, string $class): string {
	return '<span class="badge ' . h($class) . '">' . h($label) . '</span>';
}

function shortName(?string $value): string {
	$name = trim((string)$value);
	if ($name === '') {
		return '—';
	}

	$parts = preg_split('/\s+/', $name);
	if (!$parts || count($parts) === 1) {
		return $name;
	}

	$first = array_shift($parts);
	$last = (string)array_pop($parts);
	$initial = $last !== '' ? mb_substr($last, 0, 1, 'UTF-8') . '.' : '';
	return trim($first . ' ' . $initial);
}

function fetchAllAny(PDO|mysqli $db, string $sql, array $params = []): array {
	if ($db instanceof PDO) {
		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}

	$stmt = $db->prepare($sql);
	if ($stmt === false) throw new RuntimeException('MySQL prepare failed: ' . $db->error);

	if ($params) {
		$types = '';
		$vals = [];
		foreach ($params as $p) {
			$types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
			$vals[] = $p;
		}
		$stmt->bind_param($types, ...$vals);
	}

	if (!$stmt->execute()) throw new RuntimeException('MySQL execute failed: ' . $stmt->error);
	$result = $stmt->get_result();
	if ($result === false) return [];
	return $result->fetch_all(MYSQLI_ASSOC);
}

function fetchOneAny(PDO|mysqli $db, string $sql, array $params = []): array {
	$rows = fetchAllAny($db, $sql, $params);
	return $rows[0] ?? [];
}

function fillDailySeries(array $rows, string $dateKey, string $valueKey, int $days = 30): array {
	$map = [];
	foreach ($rows as $row) {
		$map[$row[$dateKey]] = (int)$row[$valueKey];
	}

	$series = [];
	for ($i = $days - 1; $i >= 0; $i--) {
		$d = (new DateTimeImmutable("-$i days"))->format('Y-m-d');
		$series[$d] = $map[$d] ?? 0;
	}
	return $series;
}

function monthlyCumulative(array $rows, string $monthKey, string $countKey): array {
	$out = [];
	$running = 0;
	foreach ($rows as $row) {
		$count = (int)$row[$countKey];
		$running += $count;
		$out[] = [
			'month' => (string)$row[$monthKey],
			'new' => $count,
			'cumulative' => $running,
		];
	}
	return $out;
}

function sparkline(array $values, string $title = ''): string {
	$values = array_map('floatval', $values);
	$count = count($values);
	if ($count === 0) return '<div class="spark empty">No data</div>';

	$max = max($values);
	$min = min($values);
	if ($max === $min) $max += 1.0;

	$w = 460;
	$h = 110;
	$padL = 52;
	$padR = 18;
	$padT = 10;
	$padB = 18;
	$plotW = $w - $padL - $padR;
	$plotH = $h - $padT - $padB;

	$points = [];
	foreach ($values as $i => $v) {
		$x = $padL + ($count === 1 ? $plotW / 2 : ($i * ($plotW / ($count - 1))));
		$y = $padT + $plotH - (($v - $min) / ($max - $min)) * $plotH;

		$points[] = [
			'x' => round($x, 2),
			'y' => round($y, 2),
			'date' => (new DateTimeImmutable('-' . ($count - 1 - $i) . ' days'))->format('M j, Y'),
			'value' => (int)round($v),
		];
	}

	$poly = implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $points));
	$pathPoints = implode(' L ', array_map(fn($p) => $p['x'] . ' ' . $p['y'], $points));
	$area = 'M ' . $padL . ' ' . ($padT + $plotH) . ' L ' . $pathPoints . ' L ' . ($padL + $plotW) . ' ' . ($padT + $plotH) . ' Z';
	$mid = ($max + $min) / 2;
	$yMid = $padT + $plotH - (($mid - $min) / ($max - $min)) * $plotH;
	$yAxisMid = round($padT + $plotH / 2);

	$xTicks = '';
	$xLabels = '';
	$step = max(1, (int)ceil($count / 6));

	for ($i = 0; $i < $count; $i++) {
		if ($i % $step !== 0 && $i !== $count - 1) {
			continue;
		}

		$x = $padL + ($count === 1 ? $plotW / 2 : ($i * ($plotW / ($count - 1))));
		$date = (new DateTimeImmutable('-' . ($count - 1 - $i) . ' days'))->format('M j');

		$xTicks .= '<line x1="' . $x . '" y1="' . ($padT + $plotH) . '" x2="' . $x . '" y2="' . ($padT + $plotH + 4) . '" stroke="#cbd5e1" stroke-width="1" />';
		$xLabels .= '<text x="' . $x . '" y="' . ($padT + $plotH + 14) . '" text-anchor="middle" font-size="9" fill="#64748b">' . h($date) . '</text>';
	}

	$gridLines =
		'<line x1="' . $padL . '" y1="' . $padT . '" x2="' . ($padL + $plotW) . '" y2="' . $padT . '" stroke="#e2e8f0" stroke-width="1" />' .
		'<line x1="' . $padL . '" y1="' . $yMid . '" x2="' . ($padL + $plotW) . '" y2="' . $yMid . '" stroke="#e2e8f0" stroke-width="1" />';

	$hoverPoints = '';
	foreach ($points as $p) {
		$hoverPoints .=
			'<circle class="spark-point" ' .
				'cx="' . $p['x'] . '" ' .
				'cy="' . $p['y'] . '" ' .
				'r="8" ' .
				'fill="transparent" ' .
				'stroke="transparent" ' .
				'stroke-width="14" ' .
				'pointer-events="all" ' .
				'data-x="' . $p['x'] . '" ' .
				'data-y="' . $p['y'] . '" ' .
				'data-date="' . h($p['date']) . '" ' .
				'data-value="' . $p['value'] . '" ' .
			'/>';
	}

	return '<svg class="spark spark-interactive" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . h($title) . '" data-plot-top="' . $padT . '" data-plot-bottom="' . ($padT + $plotH) . '">' .
		$gridLines .
		'<text x="14" y="' . $yAxisMid . '" transform="rotate(-90 14 ' . $yAxisMid . ')" text-anchor="middle" font-size="10" fill="#64748b">Projects per day</text>' .
		'<line x1="' . $padL . '" y1="' . $padT . '" x2="' . $padL . '" y2="' . ($padT + $plotH) . '" stroke="#cbd5e1" stroke-width="1" />' .
		'<line x1="' . $padL . '" y1="' . ($padT + $plotH) . '" x2="' . ($padL + $plotW) . '" y2="' . ($padT + $plotH) . '" stroke="#cbd5e1" stroke-width="1" />' .
		'<line x1="' . ($padL - 4) . '" y1="' . $padT . '" x2="' . $padL . '" y2="' . $padT . '" stroke="#cbd5e1" stroke-width="1" />' .
		'<line x1="' . ($padL - 4) . '" y1="' . $yMid . '" x2="' . $padL . '" y2="' . $yMid . '" stroke="#cbd5e1" stroke-width="1" />' .
		'<line x1="' . ($padL - 4) . '" y1="' . ($padT + $plotH) . '" x2="' . $padL . '" y2="' . ($padT + $plotH) . '" stroke="#cbd5e1" stroke-width="1" />' .
		'<text x="' . ($padL - 8) . '" y="' . ($padT + 4) . '" text-anchor="end" font-size="10" fill="#64748b">' . nfmt($max) . '</text>' .
		'<text x="' . ($padL - 8) . '" y="' . $yMid . '" text-anchor="end" font-size="10" fill="#64748b">' . nfmt($mid) . '</text>' .
		'<text x="' . ($padL - 8) . '" y="' . ($padT + $plotH + 4) . '" text-anchor="end" font-size="10" fill="#64748b">' . nfmt($min) . '</text>' .
		$xTicks .
		$xLabels .
		'<path d="' . h($area) . '" class="spark-area"></path>' .
		'<polyline points="' . h($poly) . '" class="spark-line"></polyline>' .
		'<line class="spark-guide" x1="' . $padL . '" y1="' . $padT . '" x2="' . $padL . '" y2="' . ($padT + $plotH) . '" />' .
		'<circle class="spark-hover-dot" cx="' . $padL . '" cy="' . $padT . '" r="4" />' .
		$hoverPoints .
		'</svg>';
}

$tz = new DateTimeZone(date_default_timezone_get() ?: 'America/Los_Angeles');
$now = new DateTimeImmutable('now', $tz);
$updatedAt = $now->format('M j, Y g:i A');

try {
	$summaryProjects = fetchOneAny($pdo ?? $mysqli, '
		SELECT
			COUNT(*) AS total_projects,
			SUM(status_label = "production") AS production_projects,
			SUM(status_label = "inactive") AS inactive_projects,
			SUM(is_longitudinal = 1) AS longitudinal_projects,
			SUM(surveys_enabled = 1) AS surveys_enabled_projects,
			SUM(repeating_instruments_enabled = 1) AS repeating_projects,
			SUM(randomization_enabled = 1) AS randomized_projects,
			SUM(data_locked = 1) AS locked_projects,
			SUM(mycap_enabled = 1) AS mycap_projects,
			SUM(datamart_enabled = 1) AS datamart_projects,
			SUM(api_user_count > 0) AS api_projects,
			SUM(record_count) AS total_records,
			SUM(instrument_count) AS total_instruments,
			SUM(event_count) AS total_events,
			SUM(arm_count) AS total_arms,
			SUM(user_count) AS total_project_users,
			MAX(last_updated) AS newest_update,
			MAX(project_created_at) AS newest_project_created
		FROM view_redcap_metrics_projects
	');

	$summaryUsers = fetchOneAny($pdo ?? $mysqli, '
		SELECT
			COUNT(*) AS total_users,
			SUM(is_redcap_admin = 1) AS redcap_admins,
			SUM(is_account_manager = 1) AS account_managers,
			SUM(access_admin_dashboards = 1) AS dashboard_access,
			SUM(primary_email_verified = 1) AS verified_primary_emails,
			SUM(can_create_projects = 1) AS can_create_projects_users,
			SUM(has_system_api_token = 1) AS system_api_tokens,
			SUM(has_project_api_access > 0) AS project_api_users,
			SUM(project_count) AS total_user_projects,
			SUM(production_project_count) AS total_user_production_projects,
			MAX(last_updated) AS newest_update,
			MAX(account_created_at) AS newest_account_created
		FROM view_redcap_metrics_users
	');

	$projectStatus = fetchAllAny($pdo ?? $mysqli, '
		SELECT COALESCE(status_label, "unknown") AS label, COUNT(*) AS cnt
		FROM view_redcap_metrics_projects
		GROUP BY COALESCE(status_label, "unknown")
		ORDER BY cnt DESC, label ASC
	');

	$projectPurpose = fetchAllAny($pdo ?? $mysqli, '
		SELECT COALESCE(NULLIF(purpose_label, ""), "unknown") AS label, COUNT(*) AS cnt
		FROM view_redcap_metrics_projects
		GROUP BY COALESCE(NULLIF(purpose_label, ""), "unknown")
		ORDER BY cnt DESC, label ASC
	');

	$projectMonthly = fetchAllAny($pdo ?? $mysqli, '
		SELECT DATE_FORMAT(project_created_at, "%Y-%m") AS month_key, COUNT(*) AS cnt
		FROM view_redcap_metrics_projects
		WHERE project_created_at IS NOT NULL
		GROUP BY DATE_FORMAT(project_created_at, "%Y-%m")
		ORDER BY month_key ASC
	');

	$userMonthly = fetchAllAny($pdo ?? $mysqli, '
		SELECT DATE_FORMAT(account_created_at, "%Y-%m") AS month_key, COUNT(*) AS cnt
		FROM view_redcap_metrics_users
		WHERE account_created_at IS NOT NULL
		GROUP BY DATE_FORMAT(account_created_at, "%Y-%m")
		ORDER BY month_key ASC
	');

	$projectDaily = fetchAllAny($pdo ?? $mysqli, '
		SELECT DATE(project_created_at) AS activity_date, COUNT(*) AS cnt
		FROM view_redcap_metrics_projects
		WHERE project_created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
		GROUP BY DATE(project_created_at)
		ORDER BY activity_date ASC
	');

	$activeUsersRow = fetchOneAny($pdo ?? $mysqli, '
		SELECT COUNT(*) AS cnt
		FROM view_redcap_metrics_users
		WHERE COALESCE(last_activity_at, last_login_at, first_activity_at) >= DATE_SUB(NOW(), INTERVAL 29 DAY)
	');

	$activeProjectsRow = fetchOneAny($pdo ?? $mysqli, '
		SELECT COUNT(*) AS cnt
		FROM view_redcap_metrics_projects
		WHERE COALESCE(last_logged_event_at, last_updated, moved_to_production_at, completed_at) >= DATE_SUB(NOW(), INTERVAL 29 DAY)
	');

	$newUsers30d = fetchOneAny($pdo ?? $mysqli, '
		SELECT COUNT(*) AS cnt
		FROM view_redcap_metrics_users
		WHERE account_created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
	 ');

	$topProjectsByRecords = fetchAllAny($pdo ?? $mysqli, '
		SELECT project_title, record_count, user_count, status_label, last_updated
		FROM view_redcap_metrics_projects
		ORDER BY record_count DESC, project_id DESC
		LIMIT 10
	');

	$topProductionProjectsByRecords = fetchAllAny($pdo ?? $mysqli, '
		SELECT project_title, record_count, user_count, status_label, last_updated
		FROM view_redcap_metrics_projects
		WHERE status = 1
		ORDER BY record_count DESC, project_id DESC
		LIMIT 10
	');

	$topUsersByProjects = fetchAllAny($pdo ?? $mysqli, '
		SELECT user_id, username, full_name, project_count, production_project_count, is_redcap_admin, is_account_manager, last_updated
		FROM view_redcap_metrics_users
		ORDER BY project_count DESC, user_id DESC
		LIMIT 10
	');

	$recentProjects = fetchAllAny($pdo ?? $mysqli, '
		SELECT project_id, project_title, status_label, purpose_label, created_by_username,
			user_count, record_count, last_updated
		FROM view_redcap_metrics_projects
		ORDER BY COALESCE(last_updated, project_created_at) DESC, project_id DESC
		LIMIT 12
	');
} catch (Throwable $e) {
	http_response_code(500);
	echo '<pre>Dashboard query failed: ' . h($e->getMessage()) . '</pre>';
	exit(1);
}

$totalProjects = (int)($summaryProjects['total_projects'] ?? 0);
$totalUsers = (int)($summaryUsers['total_users'] ?? 0);
$totalRecords = (int)($summaryProjects['total_records'] ?? 0);
$productionProjects = (int)($summaryProjects['production_projects'] ?? 0);
$lockedProjects = (int)($summaryProjects['locked_projects'] ?? 0);
$apiProjects = (int)($summaryProjects['api_projects'] ?? 0);
$apiUsers = (int)($summaryUsers['project_api_users'] ?? 0);
$longitudinal = (int)($summaryProjects['longitudinal_projects'] ?? 0);
$surveys = (int)($summaryProjects['surveys_enabled_projects'] ?? 0);
$activeUsers = (int)($activeUsersRow['cnt'] ?? 0);
$activeProjects = (int)($activeProjectsRow['cnt'] ?? 0);

$projectMonthlySeries = monthlyCumulative($projectMonthly, 'month_key', 'cnt');
$userMonthlySeries = monthlyCumulative($userMonthly, 'month_key', 'cnt');
$projectDailyValues = array_values(fillDailySeries($projectDaily, 'activity_date', 'cnt', 30));

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= h($config['app_title']) ?></title>
	<style>
		:root {
			--bg: #f8fafc;
			--panel: #ffffff;
			--panel-soft: #f8fbff;
			--line: #dbe4f0;
			--text: #0f172a;
			--muted: #64748b;
			--good: #15803d;
			--bad: #b91c1c;
			--warn: #b45309;
			--accent: #2563eb;
			--accent2: #7c3aed;
			--shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
			--radius: 18px;
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
			background: linear-gradient(180deg, #f8fafc 0%, #eef4fb 100%);
			color: var(--text);
		}
		.wrap { max-width: 1600px; margin: 0 auto; padding: 28px; }
		.hero { display: grid; grid-template-columns: 1.35fr 1fr; gap: 18px; align-items: stretch; margin-bottom: 18px; }
		.hero-card, .card {
			background: var(--panel);
			border: 1px solid var(--line);
			border-radius: var(--radius);
			box-shadow: var(--shadow);
		}
		.hero-card { padding: 28px; position: relative; overflow: hidden; }
		.hero-card:before { content: ""; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(124, 58, 237, 0.04)); pointer-events: none; }
		.hero-inner { position: relative; z-index: 1; }
		.eyebrow { text-transform: uppercase; letter-spacing: .18em; color: var(--muted); font-size: 12px; font-weight: 700; }
		h1 { margin: 10px 0 8px; font-size: 38px; line-height: 1.05; color: #0f172a; }
		.sub { color: #334155; font-size: 15px; line-height: 1.5; max-width: 920px; }
		.meta-row { margin-top: 18px; display: flex; flex-wrap: wrap; gap: 10px; }
		.meta { margin-top: 16px; font-size: 13px; color: #64748b; /* muted */ }
		.meta .mono { font-weight: 600; color: #334155; }
		.insights-band { margin-top: 14px; display: grid; gap: 8px; padding: 14px 18px; border: 1px dashed var(--line); border-radius: 16px; background: rgba(255,255,255,0.35); }
		.insight-line { font-size: 14px; color: var(--text); font-weight: 600; }
		.insight-line.muted { color: var(--muted); font-weight: 500; }
		.pill { padding: 8px 12px; border: 1px solid var(--line); border-radius: 999px; background: #f8fafc; color: #0f172a; font-size: 13px; }
		.side-panel { padding: 20px; display: grid; gap: 12px; }
		.side-title { font-size: 14px; color: var(--muted); text-transform: uppercase; letter-spacing: .14em; }
		.metric-band { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; }
		.mini { padding: 16px; border-radius: 16px; border: 1px solid var(--line); background: var(--panel-soft); }
		.mini .label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .12em; }
		.mini .value { font-size: 28px; font-weight: 800; margin-top: 6px; color: #0f172a; }
		.mini .note { color: #475569; font-size: 12px; margin-top: 8px; }
		.grid { display: grid; gap: 18px; }
		.cards { grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 18px; }
		.card { padding: 18px; }
		.card .k { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .14em; }
		.card .v { font-size: 34px; font-weight: 800; margin-top: 8px; color: #0f172a; }
		.card .s { margin-top: 8px; color: #334155; font-size: 13px; }
		.section-grid { grid-template-columns: 1.15fr .85fr; }
		.section-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; }
		.section-title h2 { margin: 0; font-size: 20px; color: #0f172a; }
		.section-title .small { color: var(--muted); font-size: 13px; }
		.chart-box { padding: 20px; }
		.chart-wrap { margin-top: 12px; border: 1px solid var(--line); border-radius: 16px; background: #fbfdff; padding: 14px; overflow: hidden; }
		.spark { width: 100%; height: auto; display: block; }
		.spark-line { fill: none; stroke: var(--accent); stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }
		.spark-area { fill: rgba(37, 99, 235, 0.10); }
		.spark.empty { color: var(--muted); }
		.legend { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
		.legend span { font-size: 12px; color: #334155; padding: 6px 10px; border: 1px solid var(--line); border-radius: 999px; background: #f8fafc; }
		.bars { display: grid; gap: 10px; margin-top: 10px; }
		.bar-row { display: grid; grid-template-columns: 150px 1fr 58px; gap: 10px; align-items: center; }
		.bar-label { font-size: 13px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.bar-track { height: 12px; border-radius: 999px; background: #e2e8f0; overflow: hidden; border: 1px solid var(--line); }
		.bar-fill { height: 100%; border-radius: inherit; background: linear-gradient(90deg, var(--accent), var(--accent2)); }
		.bar-val { text-align: right; color: #0f172a; font-size: 13px; }
		.table-card { overflow: hidden; }
		table { width: 100%; border-collapse: collapse; }
		th, td { padding: 12px 14px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--line); color: #0f172a; }
		th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .12em; background: #f8fafc; }
		td { font-size: 13px; }
		tr:hover td { background: #f8fafc; }
		.mono { font-variant-numeric: tabular-nums; font-feature-settings: 'tnum' 1; }
		.muted { color: var(--muted); }
		.badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px; font-size: 12px; border: 1px solid var(--line); background: #f8fafc; white-space: nowrap; color: #0f172a; }
		.good { color: var(--good); }
		.bad { color: var(--bad); }
		.warn { color: var(--warn); }
		.neutral { color: #334155; }
		.footer { margin: 18px 2px 4px; color: var(--muted); font-size: 12px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
		.footer-divider {
			margin: 20px 0 12px;
			border: none;
			border-top: 1px solid #e2e8f0;
		}
		.footer-cta-center {
			text-align: center;
			font-size: 13px;
			color: #64748b;
		}
		.footer-cta-center a {
			color: #2563eb;
			text-decoration: none;
			font-weight: 500;
			margin-left: 6px;
		}
		.footer-cta-center a:hover {
			text-decoration: underline;
		}
		.tabs { display: flex; gap: 10px; margin: 10px 0 18px; flex-wrap: wrap; }
		.tab-btn {
			border: 1px solid var(--line);
			background: #fff;
			color: #0f172a;
			padding: 10px 14px;
			border-radius: 999px;
			cursor: pointer;
			font-weight: 600;
		}
		.tab-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
		.tab-panel { display: none; }
		.tab-panel.active { display: block; }
		.tab-grid { display: grid; gap: 18px; }
		.two-col { display: grid; gap: 18px; grid-template-columns: repeat(2, minmax(0,1fr)); }
		.trends-card { padding: 20px; }
		.scroll-table { max-height: 420px; overflow: auto; border-top: 1px solid var(--line); }
		.stack { display: grid; gap: 12px; align-content: start; }
		.stack > .card { align-self: start; }
		@media (max-width: 1200px) {
			.hero, .cards, .section-grid, .two-col { grid-template-columns: 1fr; }
		}
		@media (max-width: 700px) {
			.wrap { padding: 14px; }
			h1 { font-size: 30px; }
			.cards { grid-template-columns: 1fr 1fr; }
			.bar-row { grid-template-columns: 1fr; }
			.bar-val { text-align: left; }
			table, thead, tbody, th, td, tr { display: block; }
			thead { display: none; }
			tr { border-bottom: 1px solid var(--line); padding: 10px 0; }
			td { border-bottom: none; padding: 6px 14px; }
			td[data-label]::before { content: attr(data-label) ": "; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .12em; display: block; margin-bottom: 2px; }
		}
		.spark-guide {
			display: none;
			stroke: #94a3b8;
			stroke-dasharray: 3 3;
			stroke-width: 1;
			pointer-events: none;
		}
		.spark-hover-dot {
			display: none;
			fill: #ffffff;
			stroke: #2563eb;
			stroke-width: 2;
			pointer-events: none;
			filter: drop-shadow(0 2px 6px rgba(37, 99, 235, 0.2));
		}
		.spark-point {
			cursor: pointer;
		}
		.spark-tooltip {
			position: fixed;
			display: none;
			background: #334155;
			color: #f8fafc;
			border: 1px solid #475569;
			border-radius: 10px;
			padding: 7px 10px;
			font-size: 12px;
			line-height: 1.2;
			box-shadow: 0 6px 16px rgba(15, 23, 42, 0.18);
			pointer-events: none;
			z-index: 9999;
			white-space: nowrap;
			transition: opacity 0.1s ease;
		}
	</style>
</head>
<body>
	<div class="wrap">
		<div class="hero">
			<div class="hero-card">
				<div class="hero-inner">
					<div class="eyebrow"><?= h($config['instance_name']) ?></div>
					<h1>Metrics Dashboard</h1>
					<div class="sub"><?= h($config['subtitle']) ?></div>
					<div class="meta">Updated <?= h($updatedAt) ?></div>
					<div class="insights-band">
						<div class="insight-line muted">
							<strong><?= nfmt(array_sum($projectDailyValues)) ?></strong> new projects · 
							<strong><?= nfmt($newUsers30d['cnt']) ?></strong> new users
						</div>
						<div class="insight-line muted">
							Peak: <strong><?= nfmt(max($projectDailyValues) ?: 0) ?></strong> projects/day · 
							Avg: <strong><?= nfmt(count($projectDailyValues) ? array_sum($projectDailyValues) / count($projectDailyValues) : 0, 1) ?></strong>/day (30d)
						</div>
					</div>
				</div>
			</div>
			<div class="hero-card side-panel">
				<div class="side-title">Fast facts</div>
				<div class="metric-band">
					<div class="mini">
						<div class="label">Total projects</div>
						<div class="value mono"><?= nfmt($totalProjects) ?></div>
					</div>
					<div class="mini">
						<div class="label">Total users</div>
						<div class="value mono"><?= nfmt($totalUsers) ?></div>
					</div>
					<div class="mini">
						<div class="label">Production projects</div>
						<div class="value mono"><?= nfmt($productionProjects) ?></div>
						<div class="note"><?= pct($productionProjects, $totalProjects) ?> of all projects</div>
					</div>
					<div class="mini">
						<div class="label">Total records</div>
						<div class="value mono"><?= nfmt($totalRecords) ?></div>
						<div class="note">Records stored across all projects</div>
					</div>
				</div>
			</div>
		</div>

		<div class="tabs" role="tablist" aria-label="Dashboard sections">
			<button class="tab-btn active" data-tab="overview" type="button">Overview</button>
			<button class="tab-btn" data-tab="trends" type="button">Trends</button>
			<button class="tab-btn" data-tab="details" type="button">Details</button>
		</div>

		<div id="overview" class="tab-panel active">
			<div class="grid cards">
				<div class="card">
					<div class="k">Active projects (30d)</div>
					<div class="v mono"><?= nfmt($activeProjects) ?></div>
					<div class="s">Projects with recent activity</div>
				</div>
				<div class="card">
					<div class="k">Active users (30d)</div>
					<div class="v mono"><?= nfmt($activeUsers) ?></div>
					<div class="s">Users active in the last 30 days</div>
				</div>
				<div class="card">
					<div class="k">Projects with surveys</div>
					<div class="v mono"><?= nfmt($surveys) ?></div>
					<div class="s">Survey-enabled projects</div>
				</div>
				<div class="card">
					<div class="k">API projects</div>
					<div class="v mono"><?= nfmt($apiProjects) ?></div>
					<div class="s">Projects with API access</div>
				</div>
			</div>

			<div class="grid section-grid">
				<div class="card chart-box">
					<div class="section-title">
						<h2>Daily new projects</h2>
						<div class="small">Last 30 days</div>
					</div>
					<div class="chart-wrap">
						<?= sparkline($projectDailyValues, 'Daily new projects over the last 30 days') ?>
					</div>
					<div class="legend">
						<span>Total (30d): <strong class="mono"><?= nfmt(array_sum($projectDailyValues)) ?></strong></span>
						<span>Peak day: <strong class="mono"><?= nfmt(max($projectDailyValues) ?: 0) ?></strong></span>
						<span>Avg/day: <strong class="mono"><?= nfmt(count($projectDailyValues) ? array_sum($projectDailyValues) / count($projectDailyValues) : 0, 1) ?></strong></span>
					</div>
				</div>
				<div class="stack">
					<div class="card">
						<div class="section-title">
							<h2>Project status</h2>
							<div class="small">Distribution by status</div>
						</div>
						<div class="bars">
							<?php $maxStatus = max(array_map(fn($r) => (int)$r['cnt'], $projectStatus)) ?: 1; ?>
							<?php foreach ($projectStatus as $row): ?>
							<?php $pctWidth = ((int)$row['cnt'] / $maxStatus) * 100; ?>
							<div class="bar-row">
								<div class="bar-label">
									<?= h(ucwords($row['label'])) ?>
								</div>
								<div class="bar-track">
									<div class="bar-fill" style="width: <?= number_format($pctWidth, 2) ?>%"></div>
								</div>
								<div class="bar-val mono">
									<?= nfmt($row['cnt']) ?>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="card">
						<div class="section-title"><h2>Project purpose</h2>
							<div class="small">Distribution by purpose</div>
						</div>
						<div class="bars">
							<?php $maxPurpose = max(array_map(fn($r) => (int)$r['cnt'], $projectPurpose)) ?: 1; ?>
							<?php foreach ($projectPurpose as $row): ?>
							<?php $pctWidth = ((int)$row['cnt'] / $maxPurpose) * 100; ?>
							<div class="bar-row">
								<div class="bar-label"><?= h(ucwords($row['label'])) ?></div>
								<div class="bar-track">
									<div class="bar-fill" style="width: <?= number_format($pctWidth, 2) ?>%"></div>
								</div>
								<div class="bar-val mono"><?= nfmt($row['cnt']) ?></div>
							</div>
						<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div> <!-- closes overview -->

		<div id="trends" class="tab-panel">
			<div class="grid two-col">
				<div class="card trends-card table-card">
					<div class="section-title"><h2>Project growth</h2>
						<div class="small">New and total projects over time</div>
					</div>
					<div class="scroll-table">
						<table>
							<thead><tr><th>Month</th><th class="mono">New projects</th><th class="mono">Cumulative projects</th></tr></thead>
							<tbody>
							<?php foreach ($projectMonthlySeries as $row): ?>
								<tr>
								<td data-label="Month"><?= h($row['month']) ?></td>
								<td data-label="New projects" class="mono"><?= nfmt($row['new']) ?></td>
								<td data-label="Cumulative projects" class="mono"><?= nfmt($row['cumulative']) ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<div class="card trends-card table-card">
					<div class="section-title"><h2>User growth</h2>
						<div class="small">New and total users over time</div>
					</div>
					<div class="scroll-table">
						<table>
							<thead><tr><th>Month</th><th class="mono">New users</th><th class="mono">Cumulative users</th></tr></thead>
							<tbody>
							<?php foreach ($userMonthlySeries as $row): ?>
								<tr>
								<td data-label="Month"><?= h($row['month']) ?></td>
								<td data-label="New users" class="mono"><?= nfmt($row['new']) ?></td>
								<td data-label="Cumulative users" class="mono"><?= nfmt($row['cumulative']) ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div> <!-- closes trends -->

		<div id="details" class="tab-panel">
			<div class="grid two-col">
				<div class="card table-card">
					<div class="section-title"><h2>Largest production projects</h2></div>
					<div class="scroll-table">
						<table>
							<thead><tr><th>Project title</th><th class="mono">Records</th><th class="mono">Users</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ($topProductionProjectsByRecords as $row): ?>
								<tr>
								<td data-label="Project title"><strong><?= h((string)($row['project_title'] ?: '—')) ?></strong></td>
								<td data-label="Records" class="mono"><?= nfmt($row['record_count']) ?></td>
								<td data-label="Users" class="mono"><?= nfmt($row['user_count']) ?></td>
								<td data-label="Status"><?= rowBadge((string)($row['status_label'] ?: 'unknown'), badgeClass((string)($row['status_label'] ?: 'unknown'))) ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<div class="card table-card">
					<div class="section-title"><h2>Most active users</h2></div>
					<div class="scroll-table">
						<table>
							<thead><tr><th>User</th><th class="mono">Projects</th><th class="mono">Prod</th><th>Role</th></tr></thead>
							<tbody>
							<?php foreach ($topUsersByProjects as $row): ?>
								<tr>
								<td data-label="User"><strong><?= h(shortName((string)($row['full_name'] ?: $row['username']))) ?></strong></td>
								<td data-label="Projects" class="mono"><?= nfmt($row['project_count']) ?></td>
								<td data-label="Prod" class="mono"><?= nfmt($row['production_project_count'] ?? 0) ?></td>
								<td data-label="Role"><?php if ((int)$row['is_redcap_admin'] === 1): ?><span class="badge good">Admin</span><?php elseif ((int)$row['is_account_manager'] === 1): ?><span class="badge warn">Manager</span><?php else: ?><span class="badge neutral">User</span><?php endif; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<div class="card table-card" style="margin-top:18px;">
				<div class="section-title"><h2>Recently updated projects</h2>
					<div class="small">Most recently updated or created</div>
				</div>
				<div class="scroll-table" style="max-height: 520px;">
					<table>
						<thead><tr><th>Project Title</th><th>Status</th><th>Purpose</th><th class="mono">Records</th><th class="mono">Users</th><th>Updated</th></tr></thead>
						<tbody>
						<?php foreach ($recentProjects as $row): ?>
						<tr>
							<td data-label="Project"><strong><?= h((string)($row['project_title'] ?: '—')) ?></strong></td>
							<td data-label="Status"><?= rowBadge((string)($row['status_label'] ?: 'unknown'), badgeClass((string)($row['status_label'] ?: 'unknown'))) ?></td>
							<td data-label="Purpose"><?= h((string)($row['purpose_label'] ?: '—')) ?></td>
							<td data-label="Records" class="mono"><?= nfmt($row['record_count']) ?></td>
							<td data-label="Users" class="mono"><?= nfmt($row['user_count'] ?? 0) ?></td>
							<td data-label="Updated"><?= dtOrBlank($row['last_updated'] ?? null) ?></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div> <!-- closes details -->

		<div class="footer">
			<div>Users with API access: <strong class="mono"><?= nfmt($apiUsers) ?></strong> · Locked projects: <strong class="mono"><?= nfmt($lockedProjects) ?></strong> · Longitudinal projects: <strong class="mono"><?= nfmt($longitudinal) ?></strong></div>
			<div>Newest project created on <strong><?= dtOrBlankPlus($summaryProjects['newest_project_created'] ?? null) ?></strong></div>
		</div>

		<hr class="footer-divider">

		<div class="footer-cta-center">
			Want your own REDCap metrics dashboard? <a href="https://github.com/gotswoop/redcap_metrics_dashboard" target="_blank" rel="noopener">View on GitHub →</a>
		</div>

	</div> <!-- closes .wrap -->

	<div id="spark-tooltip" class="spark-tooltip" aria-hidden="true"></div>

	<script>
	(() => {
	const buttons = Array.from(document.querySelectorAll('.tab-btn'));
	const panels = Array.from(document.querySelectorAll('.tab-panel'));

	function activate(tab) {
		buttons.forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
		panels.forEach(p => p.classList.toggle('active', p.id === tab));
	}

	buttons.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.tab)));

	const tooltip = document.getElementById('spark-tooltip');
	if (!tooltip) return;

	document.querySelectorAll('svg.spark-interactive').forEach(svg => {
		const guide = svg.querySelector('.spark-guide');
		const dot = svg.querySelector('.spark-hover-dot');
		const top = parseFloat(svg.dataset.plotTop || '10');
		const bottom = parseFloat(svg.dataset.plotBottom || '100');

		const hide = () => {
		tooltip.style.display = 'none';
		if (guide) guide.style.display = 'none';
		if (dot) dot.style.display = 'none';
		};

		const show = (point, evt) => {
		const x = parseFloat(point.dataset.x || '0');
		const y = parseFloat(point.dataset.y || '0');
		const date = point.dataset.date || '';
		const value = Number(point.dataset.value || 0).toLocaleString();

		tooltip.textContent = `${date} • ${value} projects`;
		tooltip.style.display = 'block';

		let left = evt.clientX + 14;
		let topPos = evt.clientY - 28;

		tooltip.style.left = left + 'px';
		tooltip.style.top = topPos + 'px';

		const rect = tooltip.getBoundingClientRect();
		if (rect.right > window.innerWidth - 8) {
			left = evt.clientX - rect.width - 14;
			tooltip.style.left = left + 'px';
		}
		if (rect.top < 8) {
			tooltip.style.top = (evt.clientY + 18) + 'px';
		}

		if (guide) {
			guide.setAttribute('x1', x);
			guide.setAttribute('x2', x);
			guide.setAttribute('y1', top);
			guide.setAttribute('y2', bottom);
			guide.style.display = 'block';
		}

		if (dot) {
			dot.setAttribute('cx', x);
			dot.setAttribute('cy', y);
			dot.style.display = 'block';
		}
		};

		svg.querySelectorAll('.spark-point').forEach(point => {
		point.addEventListener('mouseenter', e => show(point, e));
		point.addEventListener('mousemove', e => show(point, e));
		point.addEventListener('mouseleave', hide);
		});

		svg.addEventListener('mouseleave', hide);
	});
	})();
</script>

</body>
</html>
<?php
$html = ob_get_clean();

if (PHP_SAPI === 'cli') {
	$output = null;
	foreach ($argv as $arg) {
		if (str_starts_with($arg, '--output=')) {
			$output = substr($arg, 9);
		}
	}
	if ($output) {
		$dir = dirname($output);
		if (!is_dir($dir)) mkdir($dir, 0775, true);
		file_put_contents($output, $html);
		fwrite(STDOUT, "Wrote dashboard to {$output}\n");
		exit(0);
	}
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
