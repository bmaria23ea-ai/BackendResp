<?php
// ── CORS ─────────────────────────────────────────────────────────────────────
$allowed_origins = [
    'https://bmaria23ea-ai.github.io',   // ajusta a tu dominio de GitHub Pages
    'http://localhost',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins, true) ? $origin : $allowed_origins[0]));
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── API Key ───────────────────────────────────────────────────────────────────
$api_key = getenv('ROM_API_KEY');
$req_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($api_key && $req_key !== $api_key) {
    http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit;
}

// ── Turso ─────────────────────────────────────────────────────────────────────
define('TURSO_URL',   getenv('TURSO_URL')   ?: '');
define('TURSO_TOKEN', getenv('TURSO_TOKEN') ?: '');

function turso($stmts) {
    $reqs = array_map(fn($s) => ['type'=>'execute','stmt'=>isset($s['args'])
        ? ['sql'=>$s['sql'],'named_args'=>array_map(
            fn($k,$v)=>['name'=>$k,'value'=>['type'=>'text','value'=>(string)$v]],
            array_keys($s['args']),$s['args'])]
        : ['sql'=>$s['sql']]], $stmts);
    $reqs[] = ['type'=>'close'];
    $ch = curl_init(TURSO_URL.'/v2/pipeline');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.TURSO_TOKEN,'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['requests'=>$reqs])
    ]);
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) throw new Exception($err);
    $d = json_decode($res,true);
    if (!$d) throw new Exception('Turso vacío');
    return $d['results'] ?? [];
}

// ── Crear tablas si no existen ────────────────────────────────────────────────
turso([
    ['sql'=>"CREATE TABLE IF NOT EXISTS resp_sesiones(
        id         INTEGER PRIMARY KEY,
        paciente   TEXT    DEFAULT '',
        notas      TEXT    DEFAULT '',
        inicio     TEXT    NOT NULL,
        fin        TEXT,
        total_ciclos INTEGER DEFAULT 0,
        total_apneas INTEGER DEFAULT 0,
        created_at TEXT    DEFAULT(strftime('%Y-%m-%dT%H:%M:%fZ','now'))
    )"],
    ['sql'=>"CREATE TABLE IF NOT EXISTS resp_eventos(
        id        INTEGER PRIMARY KEY,
        sesion_id INTEGER NOT NULL,
        timestamp TEXT    NOT NULL,
        tipo      TEXT    NOT NULL,
        valor_s   REAL    NOT NULL,
        ie_ratio  REAL    DEFAULT 0
    )"],
]);

// ── Router ────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$sid    = isset($_GET['sid']) ? (int)$_GET['sid'] : null;
$action = $_GET['action'] ?? null;

try {

    // GET /sesiones.php → lista todas las sesiones
    if ($method === 'GET' && !$sid && !$action) {
        $res  = turso([['sql'=>"SELECT * FROM resp_sesiones ORDER BY id DESC"]]);
        $rows = $res[0]['response']['result']['rows'] ?? [];
        $cols = array_column($res[0]['response']['result']['cols'] ?? [], 'name');
        $out  = array_map(fn($r) => array_combine($cols, array_column($r,'value')), $rows);
        // convertir tipos numéricos
        foreach ($out as &$s) {
            $s['id']           = (int)$s['id'];
            $s['total_ciclos'] = (int)$s['total_ciclos'];
            $s['total_apneas'] = (int)$s['total_apneas'];
        }
        echo json_encode($out);
    }

    // GET /sesiones.php?sid=N&action=events → eventos de una sesión
    elseif ($method === 'GET' && $sid && $action === 'events') {
        $res  = turso([['sql'=>"SELECT * FROM resp_eventos WHERE sesion_id=:sid ORDER BY id ASC",'args'=>[':sid'=>$sid]]]);
        $rows = $res[0]['response']['result']['rows'] ?? [];
        $cols = array_column($res[0]['response']['result']['cols'] ?? [], 'name');
        $out  = array_map(fn($r) => array_combine($cols, array_column($r,'value')), $rows);
        foreach ($out as &$e) {
            $e['id']       = (int)$e['id'];
            $e['sesion_id']= (int)$e['sesion_id'];
            $e['valor_s']  = (float)$e['valor_s'];
            $e['ie_ratio'] = (float)$e['ie_ratio'];
        }
        echo json_encode($out);
    }

    // GET /sesiones.php?action=nextid → siguiente id de sesión
    elseif ($method === 'GET' && $action === 'nextid') {
        $res  = turso([['sql'=>"SELECT COALESCE(MAX(id),0) AS m FROM resp_sesiones"]]);
        $rows = $res[0]['response']['result']['rows'] ?? [];
        $max  = (int)($rows[0][0]['value'] ?? 0);
        echo json_encode(['nextId' => $max + 1]);
    }

    // GET /sesiones.php?action=nexteventid → siguiente id de evento
    elseif ($method === 'GET' && $action === 'nexteventid') {
        $res  = turso([['sql'=>"SELECT COALESCE(MAX(id),0) AS m FROM resp_eventos"]]);
        $rows = $res[0]['response']['result']['rows'] ?? [];
        $max  = (int)($rows[0][0]['value'] ?? 0);
        echo json_encode(['nextId' => $max + 1]);
    }

    // POST /sesiones.php → crear sesión
    elseif ($method === 'POST' && !$sid) {
        $b = json_decode(file_get_contents('php://input'), true);
        if (!$b || !isset($b['id'])) { http_response_code(400); echo json_encode(['error'=>'invalid']); exit; }
        turso([['sql'=>"INSERT INTO resp_sesiones(id,paciente,notas,inicio,fin,total_ciclos,total_apneas)
            VALUES(:id,:paciente,:notas,:inicio,:fin,:ciclos,:apneas)",
            'args'=>[
                ':id'      => $b['id'],
                ':paciente'=> $b['paciente'] ?? '',
                ':notas'   => $b['notas'] ?? '',
                ':inicio'  => $b['inicio'],
                ':fin'     => $b['fin'] ?? '',
                ':ciclos'  => $b['total_ciclos'] ?? 0,
                ':apneas'  => $b['total_apneas'] ?? 0,
            ]
        ]]);
        http_response_code(201); echo json_encode(['ok'=>true,'id'=>$b['id']]);
    }

    // POST /sesiones.php?sid=N&action=event → agregar evento
    elseif ($method === 'POST' && $sid && $action === 'event') {
        $b = json_decode(file_get_contents('php://input'), true);
        if (!$b || !isset($b['tipo'])) { http_response_code(400); echo json_encode(['error'=>'invalid']); exit; }
        turso([['sql'=>"INSERT INTO resp_eventos(id,sesion_id,timestamp,tipo,valor_s,ie_ratio)
            VALUES(:id,:sid,:ts,:tipo,:valor,:ie)",
            'args'=>[
                ':id'   => $b['id'],
                ':sid'  => $sid,
                ':ts'   => $b['timestamp'],
                ':tipo' => $b['tipo'],
                ':valor'=> $b['valor_s'],
                ':ie'   => $b['ie_ratio'] ?? 0,
            ]
        ]]);
        http_response_code(201); echo json_encode(['ok'=>true]);
    }

    // PATCH /sesiones.php?sid=N → actualizar paciente/notas/fin/contadores
    elseif ($method === 'PATCH' && $sid) {
        $b   = json_decode(file_get_contents('php://input'), true);
        $res = turso([['sql'=>"SELECT id FROM resp_sesiones WHERE id=:sid",'args'=>[':sid'=>$sid]]]);
        if (empty($res[0]['response']['result']['rows'])) {
            http_response_code(404); echo json_encode(['error'=>'not found']); exit;
        }
        $sets = []; $args = [':sid'=>$sid];
        if (array_key_exists('paciente',     $b)) { $sets[] = 'paciente=:p';   $args[':p']  = $b['paciente']; }
        if (array_key_exists('notas',        $b)) { $sets[] = 'notas=:n';      $args[':n']  = $b['notas']; }
        if (array_key_exists('fin',          $b)) { $sets[] = 'fin=:f';        $args[':f']  = $b['fin']; }
        if (array_key_exists('total_ciclos', $b)) { $sets[] = 'total_ciclos=:c';$args[':c'] = $b['total_ciclos']; }
        if (array_key_exists('total_apneas', $b)) { $sets[] = 'total_apneas=:a';$args[':a'] = $b['total_apneas']; }
        if ($sets) turso([['sql'=>"UPDATE resp_sesiones SET ".implode(',',$sets)." WHERE id=:sid",'args'=>$args]]);
        echo json_encode(['ok'=>true]);
    }

    // DELETE /sesiones.php?sid=N → eliminar sesión y sus eventos
    elseif ($method === 'DELETE' && $sid) {
        turso([
            ['sql'=>"DELETE FROM resp_eventos  WHERE sesion_id=:sid",'args'=>[':sid'=>$sid]],
            ['sql'=>"DELETE FROM resp_sesiones WHERE id=:sid",        'args'=>[':sid'=>$sid]],
        ]);
        echo json_encode(['ok'=>true]);
    }

    else { http_response_code(405); echo json_encode(['error'=>'not allowed']); }

} catch(Exception $e) {
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}
