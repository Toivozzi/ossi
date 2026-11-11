<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$file = __DIR__ . DIRECTORY_SEPARATOR . 'scores.json';

function respond(bool $ok, array $extra = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function read_scores(string $file): array {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_scores(string $file, array $scores): bool {
    $json = json_encode($scores, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    return @file_put_contents($file, $json, LOCK_EX) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? 'list') !== 'list') respond(false, ['error' => 'Tuntematon toiminto'], 400);
    $scores = read_scores($file);
    usort($scores, fn($a,$b) => ((int)$b['score']) <=> ((int)$a['score']));
    respond(true, ['scores' => array_slice($scores, 0, 10)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, ['error' => 'Method not allowed'], 405);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) respond(false, ['error' => 'Virheellinen pyyntö'], 400);

$name = trim((string)($input['name'] ?? ''));
$score = filter_var($input['score'] ?? null, FILTER_VALIDATE_INT);

if ($name === '' || mb_strlen($name) > 20) respond(false, ['error' => 'Nimen pitää olla 1–20 merkkiä.'], 400);
if ($score === false || $score < 0 || $score > 1000000000) respond(false, ['error' => 'Virheellinen pistemäärä.'], 400);

$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
if ($name === '') respond(false, ['error' => 'Virheellinen nimi.'], 400);

$scores = read_scores($file);

$found = false;
foreach ($scores as $i => $oldScore) {
    if (mb_strtolower((string)($oldScore['name'] ?? '')) === mb_strtolower($name)) {
        $found = true;
        if ((int)$score > (int)($oldScore['score'] ?? 0)) {
            $scores[$i] = [
                'name' => $name,
                'score' => (int)$score,
                'time' => time()
            ];
        }
        break;
    }
}

if (!$found) {
    $scores[] = [
        'name' => $name,
        'score' => (int)$score,
        'time' => time()
    ];
}

usort($scores, fn($a,$b) => ((int)$b['score']) <=> ((int)$a['score']));
$scores = array_slice($scores, 0, 10);

if (!write_scores($file, $scores)) {
    respond(false, ['error' => 'scores.json ei ole kirjoitettavissa. Anna kansiolle kirjoitusoikeus.'], 500);
}

respond(true, ['scores' => $scores]);
?>
