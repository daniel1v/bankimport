<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Lightweight regression tests; run with: php tests/BankImportCsvTest.php */
define('DOL_DOCUMENT_ROOT', __DIR__);
define('MAIN_DB_PREFIX', 'llx_');

class CommonObject {}
function dol_mktime($hour, $minute, $second, $month, $day, $year) { return mktime($hour, $minute, $second, $month, $day, $year); }
function dol_now() { return time(); }
$GLOBALS['user'] = (object) array('id' => 1);

class TestDb
{
    public $keys = array();
    public $insertStatements = array();
    public $error = '';
    public $currency = 'EUR';
    public $similarRows = array();
    public $failInsert = false;
    public $failLookup = false;
    public $failCommit = false;
    public $rollbacks = 0;
    public function begin() {}
    public function commit() { return $this->failCommit ? 0 : 1; }
    public function rollback() { $this->rollbacks++; $this->error = ''; }
    public function escape($value) { return addslashes($value); }
    public function idate($value) { return date('Y-m-d H:i:s', $value); }
    public function lasterror() { return $this->error; }
    public function query($sql) {
        if (strpos($sql, 'SELECT currency_code FROM llx_bank_account') === 0) {
            return (object) array('rows' => array((object) array('currency_code' => $this->currency)));
        }
        if (strpos($sql, 'SELECT') === 0 && strpos($sql, 'import_key IN') !== false) {
            if ($this->failLookup) { $this->error = 'Lookup failed'; return false; }
            preg_match('/fk_account = (\d+)/', $sql, $accountMatch);
            preg_match('/import_key IN \(([^)]+)\)/', $sql, $keysMatch);
            preg_match_all("/'([a-f0-9]{14})'/", $keysMatch[1], $matches);
            $accountId = (int) $accountMatch[1];
            foreach ($matches[1] as $key) {
                if (isset($this->keys[$accountId][$key])) {
                    return (object) array('rows' => array((object) array('rowid' => 1, 'dateo' => '2026-09-01 00:00:00', 'label' => 'Existing', 'amount' => 1)));
                }
            }
            return (object) array('rows' => array());
        }
        if (strpos($sql, 'INSERT INTO llx_bank') === 0) {
            if ($this->failInsert) { $this->error = 'Insert failed'; return false; }
            $this->insertStatements[] = $sql;
            preg_match("/, NULL, (\d+), 'VIR'/", $sql, $accountMatch);
            preg_match("/, '([a-f0-9]{14})'\)$/", $sql, $keyMatch);
            $this->keys[(int) $accountMatch[1]][$keyMatch[1]] = true;
            return true;
        }
        if (strpos($sql, 'SELECT') === 0) return (object) array('rows' => $this->similarRows);
        throw new Exception('Unexpected query: '.$sql);
    }
    public function num_rows($result) { return count($result->rows); }
    public function fetch_object($result) { return array_shift($result->rows); }
}

class Account
{
    public static $lines = array();
    public $error = '';
    public function __construct($db) {}
    public function fetch($accountid) { return 1; }
    public function addline() {
        self::$lines[] = func_get_args();
        return count(self::$lines);
    }
}

require_once __DIR__.'/../core/class/BankImport.class.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) throw new Exception($message.' (expected '.var_export($expected, true).', got '.var_export($actual, true).')');
}

function importer($db, $accountId = 1)
{
    $importer = new BankImport($db);
    $importer->setAccountId($accountId);
    $importer->setEncoding('UTF-8');
    return $importer;
}

$fixtures = __DIR__.'/fixtures/';

// Historical semicolon-separated Haspa export still imports.
$db = new TestDb();
$result = importer($db)->processFile($fixtures.'haspa.csv');
assertSameValue(1, $result['success'], 'Haspa import succeeds');

// Recurring Haspa transactions with otherwise identical data remain distinct
// when their booking dates differ.
$db = new TestDb();
$haspaRecurring = importer($db);
$result = $haspaRecurring->processFile($fixtures.'haspa-recurring.csv');
assertSameValue(2, $result['success'], 'Recurring Haspa transactions on different dates both import');
$result = $haspaRecurring->processFile($fixtures.'haspa-recurring.csv');
assertSameValue(2, $result['skipped'], 'Re-importing recurring Haspa transactions detects both dates');

// Comma-separated N26: quoted comma, empty value date, both signs and Category.
$db = new TestDb();
$n26 = importer($db);
$result = $n26->processFile($fixtures.'n26-comma.csv');
assertSameValue(3, $result['success'], 'N26 comma import succeeds');
assertSameValue(true, strpos($db->insertStatements[0], 'Coffee, breakfast') !== false, 'Quoted payment reference becomes label and note');
assertSameValue(true, strpos($db->insertStatements[0], '-3.50000000') !== false, 'Negative N26 amount is retained');
assertSameValue(true, strpos($db->insertStatements[2], '1200.00000000') !== false, 'Positive N26 amount is retained');
$sameFileOtherAccount = importer($db, 2)->processFile($fixtures.'n26-comma.csv');
assertSameValue(3, $sameFileOtherAccount['success'], 'Duplicate keys are scoped to one bank account');
$result = $n26->processFile($fixtures.'n26-comma.csv');
assertSameValue(3, $result['skipped'], 'Re-importing the N26 file skips its rows');

// Different header order and no optional Category are accepted.
$db = new TestDb();
$result = importer($db)->processFile($fixtures.'n26-reordered-no-category.csv');
assertSameValue(1, $result['success'], 'N26 without Category and with reordered columns succeeds');

// Long N26 payment references fit in the note instead of overflowing llx_bank.num_chq.
$db = new TestDb();
$result = importer($db)->processFile($fixtures.'n26-long-reference.csv');
assertSameValue(1, $result['success'], 'N26 with a long reference succeeds');
assertSameValue(true, strpos($db->insertStatements[0], 'Synthetic Cloud Marketplace-Buchung') !== false, 'Long reference is retained in the atomic insert');

// Unsupported input never reaches Account::addline.
$db = new TestDb();
$result = importer($db)->processFile($fixtures.'unknown.csv');
assertSameValue(0, $result['success'], 'Unknown format has no imports');
assertSameValue('Unsupported CSV format: required N26/Haspa headers not found', $result['errors'][0], 'Unknown format reports a clear error');
assertSameValue(0, count($db->insertStatements), 'Unknown format creates no bank lines');

// Preview is read-only, and importing an empty selection does not write.
$db = new TestDb();
$n26 = importer($db);
$preview = $n26->previewFile($fixtures.'n26-comma.csv');
assertSameValue(array(), $preview['errors'], 'Preview succeeds');
assertSameValue(3, count($preview['transactions']), 'All rows are shown');
assertSameValue(0, count($db->insertStatements), 'Preview never writes');
assertSameValue(0, $n26->importSelected($preview['transactions'], array())['success'], 'Empty selection does not import');
$selected = $n26->importSelected($preview['transactions'], array(3, 999));
assertSameValue(array(3), $selected['imported_rows'], 'Only a selected row present in the preview imports');
$preview = $n26->previewFile($fixtures.'n26-comma.csv');
assertSameValue(true, !empty($preview['transactions'][1]['duplicate']), 'Already imported row is flagged');
assertSameValue(1, $n26->importSelected($preview['transactions'], array(3))['success'], 'A duplicate can deliberately be imported');

$db = new TestDb();
$preview = importer($db)->previewFile($fixtures.'n26-duplicate-in-file.csv');
assertSameValue(false, $preview['transactions'][0]['duplicate'], 'First occurrence is new');
assertSameValue(2, $preview['transactions'][1]['duplicate']['csv_row'], 'Repeated CSV row points to first occurrence');

$db = new TestDb();
$db->similarRows = array((object) array('rowid' => 42, 'dateo' => '2026-09-01', 'label' => 'Coffee breakfast', 'amount' => -3.5));
$preview = importer($db)->previewFile($fixtures.'n26-comma.csv');
assertSameValue(42, $preview['transactions'][0]['similarities'][0]['rowid'], 'Similar existing transaction is flagged');
$db->currency = 'USD';
assertSameValue(3, count(importer($db)->previewFile($fixtures.'n26-comma.csv')['errors']), 'Currency mismatch rejects every EUR row');

foreach (array('failInsert' => 'Insert failed', 'failLookup' => 'Lookup failed', 'failCommit' => 'Could not commit') as $failure => $message) {
    $db = new TestDb();
    $db->$failure = true;
    $result = importer($db)->processFile($fixtures.'n26-atomic-smoke.csv');
    assertSameValue(0, $result['success'], $failure.' is never reported as a success');
    assertSameValue(true, strpos($result['errors'][0], $message) !== false, $failure.' retains the original error');
    if ($failure !== 'failLookup') assertSameValue(1, $db->rollbacks, $failure.' rolls back');
}

// Generated inputs exercise text and numeric boundaries without personal data.
$header = "Booking Date,Value Date,Partner Name,Partner Iban,Type,Payment Reference,Amount (EUR)\n";
$longLabel = str_repeat('ä', 260);
$input = 'data://text/plain;base64,'.base64_encode($header.'2026-09-01,,Partner,,TRANSFER,'.$longLabel.',1.25');
$db = new TestDb();
assertSameValue(1, importer($db)->processFile($input)['success'], 'Long UTF-8 description imports');
assertSameValue(1, preg_match('//u', $db->insertStatements[0]), 'Truncation never breaks UTF-8');
assertSameValue(true, strpos($db->insertStatements[0], 'Description='.$longLabel) !== false, 'Full long description is preserved');
foreach (array('1e999', '1e3', '10000000000000000', '1.123456789', '1,2,3.45', '1.2.3,45') as $badAmount) {
    $db = new TestDb();
    $input = 'data://text/plain;base64,'.base64_encode($header.'2026-09-01,,Partner,,TRANSFER,Test,"'.$badAmount.'"');
    assertSameValue(0, importer($db)->processFile($input)['success'], 'Invalid amount is rejected: '.$badAmount);
    assertSameValue(0, count($db->insertStatements), 'Invalid amount creates no bank row');
}
$input = 'data://text/plain;base64,'.base64_encode($header.str_repeat("2026-09-01,,Partner,,TRANSFER,Test,1.25\n", 1001));
assertSameValue(true, !empty(importer(new TestDb())->previewFile($input)['errors']), 'Form input limit is enforced');
$n26 = importer(new TestDb());
$n26->setEncoding('not-an-encoding');
assertSameValue(array('Unsupported encoding'), $n26->previewFile($fixtures.'n26-comma.csv')['errors'], 'Invalid encoding is rejected safely');

echo "BankImport CSV tests passed\n";
