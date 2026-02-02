<?php
exit;
/*
Purpose: return live info on your local and remote AI infrastructure
Why: CodeWalker and admin-panel could show which models are up, with temps, tokens/sec, and GPU VRAM usage.
Example output:

{
  "ok": true,
  "models": [
    {"name": "gpt-4o", "backend": "openai", "status": "online"},
    {"name": "gemma3:4b", "backend": "ollama", "gpu": "4070 SUPER", "load": 0.45},
    {"name": "ossgpt20b", "backend": "lmstudio", "load": 0.62}
  ],
  "servers": {
    "home": "192.168.0.191",
    "jacksonville": "192.168.0.200",
    "do-panel": "NYC3-02"
  }
}
*/
// --- headers / CORS ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// --- bootstrap ---
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/schema_builder.php';
api_guard_once('search', false);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip === '192.168.0.210') {$admin = true;} else {$admin = false;}

if($admin){
    #display errors for admin
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

$model_db = '/web/private/db/ai_models.db';
/*
/web/private/db/ai_models.db
Table: ai_models
Column	Type
id	integer NULL Auto Increment
model_name	text NULL
endpoint	text NULL
source	text NULL
added	datetime NULL
last_seen	datetime NULL
param_size	text NULL
quantization	text NULL
family	text NULL
tags	text NULL
daily_score	real NULL
notes	text NULL
*/

$resp = ['php' => PHP_VERSION, 'api_ok' => true, 'models' => [], 'servers' => []];

$resp['lmstudio_loaded_model'] = 'openai/gpt-oss-20b';


if (is_readable($model_db)) {
  try {
    $pdo = new PDO("sqlite:$model_db", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    $pdo->exec("PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;");
    $rows = $pdo->query("SELECT * FROM  ai_models ORDER BY model_name ASC")->fetchAll();
    foreach($rows as $row){
        $model = [
            'name' => $row['model_name'],
            'backend' => $row['source'],
        ];
        // Add more info based on source
        if(stripos($row['source'],'openai')!==false){
            $model['status'] = 'online'; // assume online if in DB
        }elseif(stripos($row['source'],'ollama')!==false){
            $model['endpoint'] = $row['endpoint'] ?? 'unknown';
            $model['param_size'] = $row['param_size'] ?? 'unknown';
            $model['quantization'] = $row['quantization'] ?? 'unknown';
            $model['family'] = $row['family'] ?? 'unknown';

            // Example notes: "GPU: 4070 SUPER; Load: 0.45; VRAM: 6GB/8GB"
            if(!empty($row['notes'])){
                preg_match('/GPU:\s*([^;]+)/i',$row['notes'],$m);
                if(isset($m[1])) { $model['gpu'] = trim($m[1]); }
                preg_match('/Load:\s*([\d.]+)/i',$row['notes'],$m);
                if(isset($m[1])) { $model['load'] = (float)$m[1]; }
            }


        }elseif(stripos($row['source'],'lmstudio')!==false){
            $model['load'] = $row['load'] ?? 0;
        }
        $resp['models'][] = $model;
    }
  } catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
  }
} else {
  $resp['model_db_ok'] = false; $resp['model_db_err'] = 'file not readable';
}

$resp['servers'] = [
    'home' => '192.168.0.191',
    'jacksonville' => '192.168.0.200',
    'do-panel' => 'NYC3-02'
];



http_response_code(($resp['api_ok']) ? 200 : 503);
echo json_encode($resp, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), PHP_EOL;