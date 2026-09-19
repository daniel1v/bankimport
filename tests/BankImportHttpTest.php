<?php
/** End-to-end test inside the local Dolibarr container, with self-cleaning synthetic data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('DOLI_INSTANCE_UNIQUE_ID') !== 'bankimport-local-development-only') {
    fwrite(STDERR, "Run only inside the project's Dolibarr development container.\n");
    exit(1);
}
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/custom/bankimport/tests/BankImportHttpTest.php';
require '/var/www/html/main.inc.php';
session_write_close();

function expectHttpTest($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
function xpathHttpTest($html) {
    $doc = new DOMDocument();
    $before = libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($before);
    return new DOMXPath($doc);
}
function hiddenHttpTest($html, $name) {
    $nodes = xpathHttpTest($html)->query('//input[@name="'.$name.'"]/@value');
    expectHttpTest($nodes->length === 1, 'Missing hidden field '.$name);
    return $nodes->item(0)->nodeValue;
}
function requestHttpTest($curl, $path, $post = null) {
    curl_setopt($curl, CURLOPT_URL, 'http://127.0.0.1'.$path);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
    $html = curl_exec($curl);
    expectHttpTest($html !== false, 'HTTP request failed: '.curl_error($curl));
    expectHttpTest(curl_getinfo($curl, CURLINFO_HTTP_CODE) < 500, 'HTTP server error');
    expectHttpTest(strpos($html, 'Fatal error') === false && strpos($html, 'SQL syntax') === false, 'PHP or SQL error in response');
    return $html;
}
$curl = curl_init();
curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 30));
$file = tempnam(sys_get_temp_dir(), 'bankimport-http-');
$label = 'BANKIMPORT-HTTP-'.bin2hex(random_bytes(12));
$account = 1;
$where = 'fk_account = '.$account." AND label = '".$db->escape($label)."'";
$countRows = function () use ($db, $where) {
    $result = $db->query('SELECT count(*) AS total FROM '.MAIN_DB_PREFIX.'bank WHERE '.$where);
    expectHttpTest((bool) $result, 'Could not count test rows');
    return (int) $db->fetch_object($result)->total;
};
$failed = false;
try {
    $csv = fopen($file, 'w');
    fputcsv($csv, array('Booking Date', 'Value Date', 'Partner Name', 'Partner Iban', 'Type', 'Payment Reference', 'Amount (EUR)'));
    for ($i = 0; $i < 2; $i++) fputcsv($csv, array('2026-09-01', '', '<script>test</script>', '', 'TRANSFER', $label, '0.37'));
    fclose($csv);
    $html = requestHttpTest($curl, '/');
    $html = requestHttpTest($curl, '/index.php', http_build_query(array('token' => hiddenHttpTest($html, 'token'), 'actionlogin' => 'login', 'username' => getenv('DOLI_ADMIN_LOGIN'), 'password' => getenv('DOLI_ADMIN_PASSWORD'))));
    $path = '/custom/bankimport/import.php';
    $html = requestHttpTest($curl, $path);
    expectHttpTest(strpos($html, 'name="statement"') !== false, 'Login failed or import form missing');
    expectHttpTest(xpathHttpTest($html)->query('//select[@name="accountid"]/option[@value="1"]')->length === 1, 'Test account missing from selector');

    $upload = function ($html) use ($curl, $file, $path, $account) {
        return requestHttpTest($curl, $path, array('token' => hiddenHttpTest($html, 'token'), 'action' => 'preview', 'accountid' => $account, 'encoding' => 'UTF-8', 'statement' => new CURLFile($file, 'text/csv', 'synthetic.csv')));
    };
    $html = $upload($html);
    $xpath = xpathHttpTest($html);
    expectHttpTest($xpath->query('//input[@name="selected[]"]')->length === 2, 'Preview did not show both rows');
    expectHttpTest($xpath->query('//input[@name="selected[]" and @checked]')->length === 1, 'In-file duplicate should be unchecked');
    expectHttpTest(strpos($html, '&lt;script&gt;test&lt;/script&gt;') !== false, 'CSV HTML was not escaped');
    expectHttpTest($countRows() === 0, 'Preview wrote bank rows');
    $preview = hiddenHttpTest($html, 'preview_id');
    $post = array('token' => hiddenHttpTest($html, 'token'), 'action' => 'import_selected', 'preview_id' => $preview, 'selected' => array(2), 'selection_complete' => 'yes');
    $bad = $post;
    unset($bad['token']);
    requestHttpTest($curl, $path, http_build_query($bad));
    expectHttpTest(curl_getinfo($curl, CURLINFO_HTTP_CODE) === 403 && $countRows() === 0, 'Missing CSRF token was not blocked');
    $html = requestHttpTest($curl, $path, http_build_query($post));
    expectHttpTest($countRows() === 1, 'Selected import should create exactly one row');
    expectHttpTest(xpathHttpTest($html)->query('//input[@name="selected[]" and @value="2"]')->length === 0, 'Imported row was not consumed');
    $post['token'] = hiddenHttpTest($html, 'token');
    $html = requestHttpTest($curl, $path, http_build_query($post));
    expectHttpTest($countRows() === 1, 'Replaying a selection imported the same preview row twice');

    // The user can still explicitly choose the warned, remaining row.
    $post['token'] = hiddenHttpTest($html, 'token');
    $post['selected'] = array(3);
    requestHttpTest($curl, $path, http_build_query($post));
    expectHttpTest($countRows() === 2, 'Explicit duplicate selection did not import');
    $html = $upload(requestHttpTest($curl, $path));
    expectHttpTest(xpathHttpTest($html)->query('//input[@name="selected[]" and @checked]')->length === 0, 'Existing duplicates should default to unchecked');
    $post['token'] = hiddenHttpTest($html, 'token');
    $post['preview_id'] = hiddenHttpTest($html, 'preview_id');
    unset($post['selection_complete']);
    requestHttpTest($curl, $path, http_build_query($post));
    expectHttpTest($countRows() === 2, 'Truncated selection was imported');
    foreach (array('BankImportCsvTest.php', 'BankImportDolibarrIntegration.php', 'BankImportHttpTest.php') as $test) {
        requestHttpTest($curl, '/custom/bankimport/tests/'.$test);
        expectHttpTest(curl_getinfo($curl, CURLINFO_HTTP_CODE) === 404, 'Test endpoint is accessible through HTTP');
    }
    echo "HTTP tests passed: login, upload, preview, escaping, selection, advisory duplicates, replay protection, CSRF, truncated POST, CLI-only tests.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    $failed = true;
} finally {
    if (!$db->query('DELETE FROM '.MAIN_DB_PREFIX.'bank WHERE '.$where)) {
        fwrite(STDERR, "Could not clean synthetic test rows\n");
        $failed = true;
    }
    unlink($file);
    curl_close($curl);
}
exit($failed ? 1 : 0);
