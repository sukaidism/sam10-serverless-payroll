<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/helpers.php';

use Bramus\Router\Router;
use Dompdf\Dompdf;
use Dompdf\Options;
use Aws\S3\S3Client;

$router = new Router();
$router->setBasePath('');

$router->get('/api/employees', function () {
    json_response(['success' => true, 'data' => read_csv('employees.csv')]);
});

$router->get('/api/reports/payroll', function () {
    $rows = read_csv('payroll.csv');
    $gross = $net = $ded = 0.0;
    foreach ($rows as $r) {
        $g = num(pick($r, ['gross_pay', 'gross_salary', 'gross']));
        $n = num(pick($r, ['net_pay', 'net_salary', 'net']));
        $d = pick($r, ['deductions', 'total_deductions']);
        $gross += $g;
        $net   += $n;
        $ded   += $d !== null ? num($d) : $g - $n;
    }
    json_response(['success' => true, 'data' => [
        'total_payroll_records' => count($rows),
        'total_gross_pay'       => round($gross, 2),
        'total_deductions'      => round($ded, 2),
        'total_net_pay'         => round($net, 2),
    ]]);
});

$router->get('/api/payslips/(\w+)', function ($id) {
    $slip = build_payslip($id);
    if (!$slip) json_response(['success' => false, 'message' => 'Payroll record not found'], 404);
    json_response(['success' => true, 'data' => $slip]);
});

$router->post('/api/payslips/(\w+)/generate', function ($id) {
    $slip = build_payslip($id);
    if (!$slip) json_response(['success' => false, 'message' => 'Payroll record not found'], 404);

    // Lambda's only writable path is /tmp
    $tmp = sys_get_temp_dir();
    $opts = new Options();
    $opts->setTempDir($tmp)->setFontDir($tmp)->setFontCache($tmp)
         ->setChroot(__DIR__)->setIsRemoteEnabled(false);

    $dompdf = new Dompdf($opts);
    $dompdf->loadHtml(payslip_html($slip));
    $dompdf->setPaper('A4');
    $dompdf->render();

    $key = "payslips/{$id}.pdf";
    $s3 = new S3Client(['version' => 'latest', 'region' => getenv('AWS_REGION') ?: 'ap-southeast-2']);
    $bucket = getenv('BUCKET_NAME');
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $key,
        'Body'        => $dompdf->output(),
        'ContentType' => 'application/pdf',
    ]);
    json_response([
        'success' => true,
        'message' => 'Payslip generated and uploaded.',
        'data'    => [
            'payroll_id' => $slip['payroll_id'],
            'payslip_file' => $s3->getObjectUrl($bucket, $key),
        ],
    ]);
});

$router->set404(function () {
    json_response(['success' => false, 'message' => 'Not found'], 404);
});

$router->run();