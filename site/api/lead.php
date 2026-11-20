<?php
/**
 * Приём заявки с формы записи.
 *
 * Настройки лежат вне папки сайта: ../../lead.config.php (на Timeweb ~/alyans-dance.ru/lead.config.php, рядом с public_html),
 * образец в deploy/lead.config.example.php. Каждая заявка сначала пишется в журнал
 * (папка leads рядом с конфигом), потом уходит в Telegram и на почту, что настроено.
 * Ответ всегда JSON: {"ok": true} или {"ok": false, "error": "..."}.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function respond($code, $data)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method']);
}

$configPath = getenv('LEAD_CONFIG') ?: dirname(__DIR__, 2) . '/lead.config.php';
$cfg = is_file($configPath) ? require $configPath : [];
$logDir = $cfg['log_dir'] ?? dirname(__DIR__, 2) . '/leads';

// Бот заполнил скрытое поле: отвечаем как обычно, но никуда не шлём
if (trim($_POST['website'] ?? '') !== '') {
    respond(200, ['ok' => true]);
}

function field($key, $max = 200)
{
    $v = trim((string)($_POST[$key] ?? ''));
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
    return mb_substr($v, 0, $max);
}

$name = field('name', 100);
$phoneDigits = preg_replace('/\D+/', '', field('phone', 30));
if (strlen($phoneDigits) === 11 && $phoneDigits[0] === '8') {
    $phoneDigits = '7' . substr($phoneDigits, 1);
}
if (mb_strlen($name) < 2 || strlen($phoneDigits) !== 11 || $phoneDigits[0] !== '7' || empty($_POST['consent'])) {
    respond(422, ['ok' => false, 'error' => 'validation']);
}

// Не больше 5 заявок с одного адреса за 10 минут
if (!is_dir($logDir)) {
    @mkdir($logDir, 0700, true);
}
$ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
$rlFile = $logDir . '/rate-' . md5($ip) . '.json';
$now = time();
$hits = is_file($rlFile) ? array_filter((array)json_decode((string)file_get_contents($rlFile), true), function ($t) use ($now) {
    return $t > $now - 600;
}) : [];
if (count($hits) >= 5) {
    respond(429, ['ok' => false, 'error' => 'rate']);
}
$hits[] = $now;
@file_put_contents($rlFile, json_encode(array_values($hits)));

$audience = field('audience', 10) === 'self' ? 'для себя' : 'для ребёнка';
$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid'] as $k) {
    if (field($k) !== '') {
        $utm[$k] = field($k);
    }
}
$time = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i');
$lead = [
    'time' => $time,
    'name' => $name,
    'phone' => '+' . $phoneDigits,
    'audience' => $audience,
    'details' => field('details', 60),
    'coach' => field('coach', 100),
    'page' => field('page', 200),
    'referrer' => field('referrer', 300),
    'utm' => $utm,
];

// Журнал заявок: если отправка не дойдёт, заявка не потеряется
$logged = @file_put_contents($logDir . '/leads-' . date('Y-m') . '.jsonl', json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;

$phonePretty = sprintf('+7 (%s) %s-%s-%s', substr($phoneDigits, 1, 3), substr($phoneDigits, 4, 3), substr($phoneDigits, 7, 2), substr($phoneDigits, 9, 2));
$lines = [
    'Заявка с сайта ' . ($cfg['site_name'] ?? 'Альянс'),
    '',
    'Имя: ' . $name,
    'Телефон: ' . $phonePretty,
    'Занятие ' . $audience . ($lead['details'] !== '' ? ', ' . $lead['details'] : ''),
];
if ($lead['coach'] !== '') {
    $lines[] = 'Тренер: ' . $lead['coach'];
}
$lines[] = 'Страница: ' . ($lead['page'] ?: '/');
if ($utm) {
    $lines[] = 'Метки: ' . implode(', ', array_map(function ($k, $v) {
        return "$k=$v";
    }, array_keys($utm), $utm));
}
$lines[] = $time . ' МСК';
$text = implode("\n", $lines);

$dry = !empty($cfg['dry_run']);
$sent = 0;
$errors = [];

// Запрос к API Telegram. Через curl по IPv4: на Timeweb у api.telegram.org есть адрес IPv6,
// а исходящего IPv6 у хостинга нет, и file_get_contents через раз уходил туда и падал по таймауту.
// Соединение с Telegram с сервера рвётся примерно в каждом пятом запросе (замер 18.11.2026), поэтому до трёх попыток.
function telegram_post($token, $method, $payload)
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $res = false;
        for ($attempt = 1; $attempt <= 3 && $res === false; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
        }
    } else {
        $res = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 8,
            'ignore_errors' => true,
        ]]));
    }
    return $res ? json_decode($res, true) : null;
}

// Telegram. Серверы Telegram за рубежом, по 152-ФЗ это трансграничная передача данных
$token = $cfg['telegram_token'] ?? '';
foreach ((array)($cfg['telegram_chat_ids'] ?? []) as $chat) {
    if (!$token) {
        break;
    }
    if ($dry) {
        $sent++;
        continue;
    }
    $res = telegram_post($token, 'sendMessage', ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true]);
    !empty($res['ok']) ? $sent++ : $errors[] = 'telegram';
}

// Почта
foreach ((array)($cfg['email_to'] ?? []) as $to) {
    if ($dry) {
        $sent++;
        continue;
    }
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . $name . ', ' . $phonePretty) . '?=';
    $from = $cfg['email_from'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit";
    @mail($to, $subject, $text, $headers) ? $sent++ : $errors[] = 'mail';
}

if ($dry && getenv('LEAD_DEBUG')) {
    fwrite(STDERR, $text . "\n");
}

if ($sent > 0) {
    respond(200, ['ok' => true]);
}
// Ни один канал не сработал или не настроен. Заявка есть в журнале, но человеку лучше позвонить
respond(502, ['ok' => false, 'error' => $errors ? implode(',', $errors) : 'not_configured', 'logged' => $logged]);
