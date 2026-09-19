<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DOLI_INSTANCE_UNIQUE_ID') !== 'bankimport-local-development-only') {
    fwrite(STDERR, "This test may only run in the project's development container.\n");
    exit(1);
}

/**
 * Integration smoke test for a running Dolibarr development container.
 *
 * Usage inside the Dolibarr container:
 *   php /var/www/html/custom/bankimport/tests/BankImportDolibarrIntegration.php
 */

define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/custom/bankimport/tests/BankImportDolibarrIntegration.php';

require '/var/www/html/main.inc.php';
require_once __DIR__.'/../core/class/BankImport.class.php';

global $db, $user;

$user = new User($db);
if ($user->fetch(1) <= 0) {
    fwrite(STDERR, "Could not load the development administrator.\n");
    exit(1);
}

$accountId = isset($argv[1]) ? (int) $argv[1] : 1;
$import = new BankImport($db);
$import->setAccountId($accountId);
$import->setEncoding('UTF-8');

$results = array();
foreach (array('n26-comma.csv' => 3, 'n26-long-reference.csv' => 1) as $fixture => $expected) {
    $result = $import->previewFile(__DIR__.'/fixtures/'.$fixture);
    if (!empty($result['errors']) || count($result['transactions']) !== $expected) {
        fwrite(STDERR, $fixture.": ".json_encode($result, JSON_PRETTY_PRINT)."\n");
        exit(1);
    }
    $results[$fixture] = array('preview_rows' => count($result['transactions']), 'errors' => $result['errors']);
}

// Exercise the preview, explicit selection and atomic INSERT paths with a
// uniquely labelled synthetic row, then remove only that smoke-test row.
$smokeLabel = 'BANKIMPORT-INTEGRATION-ATOMIC-7F91C2';
$cleanupSql = "DELETE FROM ".MAIN_DB_PREFIX."bank WHERE fk_account = ".$accountId;
$cleanupSql .= " AND label = '".$db->escape($smokeLabel)."'";
try {
    if (!$db->query($cleanupSql)) throw new RuntimeException('Could not initialize smoke test');
    $preview = $import->previewFile(__DIR__.'/fixtures/n26-atomic-smoke.csv');
    if (!empty($preview['errors']) || count($preview['transactions']) !== 1 || !empty($preview['transactions'][0]['duplicate'])) {
        throw new RuntimeException('Initial preview failed: '.json_encode($preview));
    }
    $selected = $import->importSelected($preview['transactions'], array(2));
    if ($selected['success'] !== 1 || !empty($selected['errors'])) {
        throw new RuntimeException('Selected import failed: '.json_encode($selected));
    }
    $duplicatePreview = $import->previewFile(__DIR__.'/fixtures/n26-atomic-smoke.csv');
    if (empty($duplicatePreview['transactions'][0]['duplicate'])) {
        throw new RuntimeException('Duplicate preview did not flag the imported row');
    }
} finally {
    if (!$db->query($cleanupSql)) throw new RuntimeException('Could not clean up smoke test');
}
$results['preview-and-selected-import'] = array('success' => 1, 'duplicate_detected' => true, 'cleanup' => true);

echo json_encode($results, JSON_PRETTY_PRINT)."\n";
