<?php

function json_response(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_csv(string $file): array
{
    $path = __DIR__ . '/../data/' . $file;
    if (!is_readable($path)) {
        json_response(['success' => false, 'message' => "Missing $file"], 500);
    }
    $rows = [];
    $fh = fopen($path, 'r');
    $header = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($fh));
    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) !== count($header)) continue;
        $rows[] = array_combine($header, $line);
    }
    fclose($fh);
    return $rows;
}

/** First matching column (case-insensitive) from candidate names. */
function pick(array $row, array $candidates, $default = null)
{
    $lower = array_change_key_case($row, CASE_LOWER);
    foreach ($candidates as $c) {
        if (array_key_exists(strtolower($c), $lower)) return $lower[strtolower($c)];
    }
    return $default;
}

function num($v): float
{
    return (float) preg_replace('/[^\d.\-]/', '', (string) $v);
}

function build_payslip(string $payrollId): ?array
{
    $payroll = null;
    foreach (read_csv('payroll.csv') as $p) {
        if ((string) pick($p, ['payroll_id', 'id']) === $payrollId) { $payroll = $p; break; }
    }
    if (!$payroll) return null;

    $empId = pick($payroll, ['employee_id']);
    $employee = [];
    foreach (read_csv('employees.csv') as $e) {
        if ((string) pick($e, ['employee_id', 'id']) === (string) $empId) { $employee = $e; break; }
    }

    $gross = num(pick($payroll, ['gross_pay', 'gross_salary', 'gross']));
    $net   = num(pick($payroll, ['net_pay', 'net_salary', 'net']));
    $ded   = pick($payroll, ['deductions', 'total_deductions']);
    $ded   = $ded !== null ? num($ded) : $gross - $net;

    $name = pick($employee, ['full_name', 'name']) ??
        trim((pick($employee, ['first_name']) ?? '') . ' ' . (pick($employee, ['last_name']) ?? ''));

    return [
        'payroll_id'    => is_numeric($payrollId) ? (int) $payrollId : $payrollId,
        'employee_id'   => $empId,
        'employee_name' => $name,
        'department_id' => pick($employee, ['department_id']),
        'pay_period'    => pick($payroll, ['pay_period', 'pay_date', 'period_start', 'payroll_date']),
        'gross_pay'     => round($gross, 2),
        'deductions'    => round($ded, 2),
        'net_pay'       => round($net, 2),
    ];
}

function payslip_html(array $s): string
{
    $h = fn($v) => htmlspecialchars((string) $v);
    $m = fn($v) => number_format((float) $v, 2);
    return "<html><body style='font-family: DejaVu Sans, sans-serif; font-size:13px'>
    <h2>Payslip</h2>
    <table width='100%' cellpadding='6' border='1' style='border-collapse:collapse'>
      <tr><td><b>Payroll ID</b></td><td>{$h($s['payroll_id'])}</td></tr>
      <tr><td><b>Employee ID</b></td><td>{$h($s['employee_id'])}</td></tr>
      <tr><td><b>Employee Name</b></td><td>{$h($s['employee_name'])}</td></tr>
      <tr><td><b>Department ID</b></td><td>{$h($s['department_id'])}</td></tr>
      <tr><td><b>Pay Period</b></td><td>{$h($s['pay_period'])}</td></tr>
      <tr><td><b>Gross Pay</b></td><td>{$m($s['gross_pay'])}</td></tr>
      <tr><td><b>Deductions</b></td><td>{$m($s['deductions'])}</td></tr>
      <tr><td><b>Net Pay</b></td><td><b>{$m($s['net_pay'])}</b></td></tr>
    </table></body></html>";
}

/*
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function payslip_key(array $s): string
{
    $parts = [
        'payroll-' . $s['payroll_id'],
        slugify((string) $s['employee_name']),
        slugify((string) $s['pay_period']),
    ];
    $parts = array_filter($parts, fn($p) => $p !== '');
    return 'payslips/' . implode('_', $parts) . '.pdf';
}
*/