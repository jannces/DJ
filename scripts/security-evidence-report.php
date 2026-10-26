<?php

/**
 * Turns the raw evidence written by SecurityTestMatrixTest into three durable
 * artifacts, all under storage/app/security-evidence/:
 *
 *   1. security-evidence.sqlite  a queryable database — one row per trial in
 *      `security_test_runs`, plus the captured application log rows in
 *      `evidence_samples`. This is the "evidence via database".
 *   2. runs.csv                  the same ledger as a spreadsheet.
 *   3. report.html               a printable report; screenshot it for figures.
 *
 * Plain PHP + PDO; it does not boot Laravel, so it runs even without a database
 * server. Usage:  php scripts/security-evidence-report.php
 */

$base = __DIR__.'/../storage/app/security-evidence';
$summaryFile = $base.'/summary.json';
$ledgerFile = $base.'/runs.jsonl';

if (! is_file($summaryFile)) {
    fwrite(STDERR, "No summary.json found. Run: php artisan test --filter=SecurityTestMatrixTest\n");
    exit(1);
}

$summary = json_decode(file_get_contents($summaryFile), true);
$trials = [];
foreach (file($ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $row = json_decode($line, true);
    if (is_array($row)) {
        $trials[] = $row;
    }
}

$samples = [];
foreach (glob($base.'/samples/*.json') ?: [] as $f) {
    $samples[basename($f, '.json')] = json_decode(file_get_contents($f), true);
}

// ---------------------------------------------------------------- 1. SQLite DB
$dbPath = $base.'/security-evidence.sqlite';
@unlink($dbPath);
$pdo = new PDO('sqlite:'.$dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE security_test_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scenario TEXT NOT NULL,
    iteration INTEGER NOT NULL,
    result TEXT NOT NULL,
    executed_at TEXT NOT NULL,
    detail_json TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE evidence_samples (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scenario TEXT NOT NULL,
    row_json TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE scenario_summary (
    scenario TEXT PRIMARY KEY,
    iterations INTEGER NOT NULL,
    passed INTEGER NOT NULL,
    failed INTEGER NOT NULL,
    result TEXT NOT NULL
)');

$ins = $pdo->prepare('INSERT INTO security_test_runs (scenario, iteration, result, executed_at, detail_json) VALUES (?,?,?,?,?)');
foreach ($trials as $t) {
    $ins->execute([
        $t['scenario'],
        $t['iteration'],
        $t['pass'] ? 'PASS' : 'FAIL',
        $t['at'] ?? '',
        json_encode($t['detail'] ?? [], JSON_UNESCAPED_SLASHES),
    ]);
}

$insSample = $pdo->prepare('INSERT INTO evidence_samples (scenario, row_json) VALUES (?,?)');
foreach ($samples as $slug => $rows) {
    foreach ((array) $rows as $row) {
        $insSample->execute([$slug, json_encode($row, JSON_UNESCAPED_SLASHES)]);
    }
}

$insSum = $pdo->prepare('INSERT INTO scenario_summary VALUES (?,?,?,?,?)');
foreach ($summary['scenarios'] as $s) {
    $insSum->execute([$s['scenario'], $s['iterations'], $s['passed'], $s['failed'], $s['result']]);
}

// ------------------------------------------------------------------- 2. CSV
$csv = fopen($base.'/runs.csv', 'w');
fputcsv($csv, ['scenario', 'iteration', 'result', 'executed_at', 'detail']);
foreach ($trials as $t) {
    fputcsv($csv, [
        $t['scenario'], $t['iteration'], $t['pass'] ? 'PASS' : 'FAIL',
        $t['at'] ?? '', json_encode($t['detail'] ?? [], JSON_UNESCAPED_SLASHES),
    ]);
}
fclose($csv);

// ------------------------------------------------------------------ 3. HTML
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$generated = $e($summary['generated_at'] ?? date('c'));
$totalIter = (int) ($summary['overall']['total_iterations'] ?? 0);
$allPass = ! empty($summary['overall']['all_passed']);

// Average lockout alert latency from scenario 6, if present.
$latencies = [];
foreach ($trials as $t) {
    if (isset($t['detail']['lockout_alert_latency_ms'])) {
        $latencies[] = (float) $t['detail']['lockout_alert_latency_ms'];
    }
}
$avgLatency = $latencies ? round(array_sum($latencies) / count($latencies), 2) : null;

$controlMap = [
    'Detected Vulnerabilities' => 'Input validation · RBAC · Authentication · Secure configuration',
    'Unauthorized Access Attempt' => 'OTP authentication · RBAC · Access logging',
    'Blocked Unauthorized Login Attempts' => 'Login-attempt blocking · IP / source monitoring',
    'SQL Injection Attempt' => 'Input validation · IDS signatures · Parameterized queries',
    'Brute Force Login Attempt' => 'Login blocking · OTP authentication · Intrusion logging',
    'Intrusion Alerts Generated' => 'Real-time intrusion alerts · Audit logging',
];

ob_start();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Security Test Matrix — Evidence Report</title>
<style>
  :root {
    --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --bg:#f8fafc; --card:#ffffff;
    --pass:#15803d; --pass-bg:#dcfce7; --fail:#b91c1c; --fail-bg:#fee2e2;
    --accent:#1d4ed8; --accent-bg:#eff6ff;
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink);
    font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  .wrap { max-width:960px; margin:0 auto; padding:32px 20px 64px; }
  header { border-bottom:3px solid var(--accent); padding-bottom:16px; margin-bottom:24px; }
  h1 { font-size:24px; margin:0 0 4px; }
  .sub { color:var(--muted); font-size:13px; }
  .pill { display:inline-block; padding:3px 12px; border-radius:999px; font-weight:700;
    font-size:13px; letter-spacing:.3px; }
  .pill.pass { background:var(--pass-bg); color:var(--pass); }
  .pill.fail { background:var(--fail-bg); color:var(--fail); }
  .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin:20px 0 28px; }
  @media (max-width:720px){ .grid{ grid-template-columns:repeat(2,1fr);} }
  .stat { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px 16px; }
  .stat .n { font-size:26px; font-weight:800; }
  .stat .l { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.5px; }
  table { width:100%; border-collapse:collapse; background:var(--card);
    border:1px solid var(--line); border-radius:12px; overflow:hidden; margin:8px 0 28px; }
  th,td { text-align:left; padding:10px 14px; border-bottom:1px solid var(--line); font-size:14px; vertical-align:top; }
  th { background:var(--accent-bg); color:var(--accent); font-size:12px; text-transform:uppercase; letter-spacing:.4px; }
  tr:last-child td { border-bottom:none; }
  .mono { font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace; font-size:12px; color:var(--muted); }
  h2 { font-size:17px; margin:30px 0 10px; }
  .ratio { font-weight:700; }
  .ok { color:var(--pass); } .bad { color:var(--fail); }
  .note { color:var(--muted); font-size:12.5px; margin-top:6px; }
  details { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:8px 14px; margin:8px 0; }
  summary { cursor:pointer; font-weight:600; }
  pre { background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px; overflow:auto; font-size:12px; }
  footer { color:var(--muted); font-size:12px; border-top:1px solid var(--line); padding-top:14px; margin-top:32px; }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Security Test Matrix — Evidence Report</h1>
    <div class="sub">Cybersecurity Integrated Digital Leave Management System · LGU of Alicia<br>
      Generated <?= $generated ?> · Suite <span class="mono">SecurityTestMatrixTest</span></div>
    <p style="margin:12px 0 0"><span class="pill <?= $allPass ? 'pass':'fail' ?>"><?= $allPass ? 'ALL SCENARIOS PASS' : 'FAILURES PRESENT' ?></span></p>
  </header>

  <div class="grid">
    <div class="stat"><div class="n"><?= count($summary['scenarios']) ?></div><div class="l">Scenarios</div></div>
    <div class="stat"><div class="n"><?= $totalIter ?></div><div class="l">Total trials</div></div>
    <div class="stat"><div class="n ok"><?= array_sum(array_column($summary['scenarios'],'passed')) ?></div><div class="l">Passed</div></div>
    <div class="stat"><div class="n <?= array_sum(array_column($summary['scenarios'],'failed'))?'bad':'ok' ?>"><?= array_sum(array_column($summary['scenarios'],'failed')) ?></div><div class="l">Failed</div></div>
  </div>

  <h2>Results by scenario</h2>
  <table>
    <thead><tr><th>#</th><th>Scenario</th><th>Security control verified</th><th>Trials (pass/total)</th><th>Result</th></tr></thead>
    <tbody>
<?php foreach ($summary['scenarios'] as $i => $s): $name = $s['scenario']; ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><strong><?= $e($name) ?></strong></td>
        <td><?= $e($controlMap[$name] ?? '') ?></td>
        <td class="ratio <?= $s['failed'] ? 'bad':'ok' ?>"><?= $s['passed'] ?> / <?= $s['iterations'] ?></td>
        <td><span class="pill <?= $s['result']==='PASS'?'pass':'fail' ?>"><?= $s['result'] ?></span></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php if ($avgLatency !== null): ?>
  <p class="note">Mean real-time alert latency (brute-force lockout → administrator notification queued): <strong><?= $avgLatency ?> ms</strong> across <?= count($latencies) ?> trials.</p>
<?php endif; ?>

  <h2>Database evidence — sample application log rows</h2>
  <p class="note">Captured live from the application's own tables during the run
    (<span class="mono">intrusion_logs</span>, <span class="mono">failed_logins</span>).
    The full set is queryable in <span class="mono">security-evidence.sqlite</span>.</p>
<?php foreach ($samples as $slug => $rows): if (! $rows) continue; ?>
  <details open>
    <summary><?= $e(str_replace('-', ' ', ucwords($slug, '-'))) ?> — <?= is_array($rows)&&isset($rows[0])?count($rows):1 ?> row(s)</summary>
    <pre><?= $e(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </details>
<?php endforeach; ?>

  <footer>
    Artifacts in <span class="mono">storage/app/security-evidence/</span>:
    <span class="mono">security-evidence.sqlite</span> (database),
    <span class="mono">runs.csv</span> (ledger),
    <span class="mono">summary.json</span>, <span class="mono">runs.jsonl</span>, <span class="mono">samples/</span>.<br>
    Reproduce: <span class="mono">php artisan test --filter=SecurityTestMatrixTest &amp;&amp; php scripts/security-evidence-report.php</span>
  </footer>
</div>
</body>
</html>
<?php
$html = ob_get_clean();
file_put_contents($base.'/report.html', $html);

echo "Wrote:\n";
echo "  $dbPath\n";
echo "  $base/runs.csv\n";
echo "  $base/report.html\n";
echo "Trials: ".count($trials)."  Scenarios: ".count($summary['scenarios'])."  All pass: ".($allPass?'yes':'NO')."\n";
