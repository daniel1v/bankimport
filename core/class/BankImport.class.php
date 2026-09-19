<?php
/* Copyright (C) 2024 Tilo Thiele <tilo.thiele@hamburg.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!class_exists('CommonObject')) {
    require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
}
if (!class_exists('Account')) {
    require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
}

/** Import supported bank CSV exports into Dolibarr bank accounts. */
class BankImport extends CommonObject
{
    /** @var DoliDB */
    public $db;
    /** @var string */
    public $error = '';
    /** @var string[] */
    public $errors = array();
    /** @var int */
    public $accountid;
    /** @var string */
    public $encoding;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function setAccountId($accountid)
    {
        $this->accountid = (int) $accountid;
    }

    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    public function validateFile($file)
    {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            $this->error = 'File upload failed (code '.(isset($file['error']) ? (int) $file['error'] : -1).')';
            return false;
        }
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $this->error = 'No file uploaded';
            return false;
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->error = 'Invalid file upload';
            return false;
        }
        if (!isset($file['size']) || (int) $file['size'] > 10 * 1024 * 1024) {
            $this->error = 'File too large (max 10MB)';
            return false;
        }
        $allowedTypes = array('text/csv', 'text/plain', 'application/csv');
        $type = isset($file['type']) ? $file['type'] : '';
        $name = isset($file['name']) ? $file['name'] : '';
        if (!in_array($type, $allowedTypes) && !preg_match('/\.csv$/i', $name)) {
            $this->error = 'Invalid file type (CSV required)';
            return false;
        }
        return true;
    }

    /**
     * Validate and normalize all records before creating a bank line.
     *
     * @param string $filename
     * @return array
     */
    public function processFile($filename)
    {
        $result = array('success' => 0, 'errors' => array(), 'skipped' => 0);
        if (empty($this->accountid) || $this->accountid <= 0) {
            $this->error = 'No valid bank account selected';
            $result['errors'][] = $this->error;
            return $result;
        }

        $transactions = $this->readAndNormalizeFile($filename, $result['errors']);
        if ($transactions === false) {
            if (empty($result['errors']) && $this->error !== '') $result['errors'][] = $this->error;
            return $result;
        }

        foreach ($transactions as $transaction) {
            $importResult = $this->processRow($transaction, true);
            if ($importResult === true) $result['success']++;
            elseif ($importResult === 'skipped') $result['skipped']++;
            else $result['errors'][] = 'Row '.$transaction['row'].': '.$importResult;
        }
        return $result;
    }

    /**
     * Parse a file and enrich every transaction with account-specific duplicate
     * and similarity information without writing to the database.
     *
     * @param string $filename
     * @return array
     */
    public function previewFile($filename)
    {
        $result = array('transactions' => array(), 'errors' => array());
        if (empty($this->accountid) || $this->accountid <= 0) {
            $result['errors'][] = 'No valid bank account selected';
            return $result;
        }

        $transactions = $this->readAndNormalizeFile($filename, $result['errors']);
        if ($transactions === false) {
            if (empty($result['errors']) && $this->error !== '') $result['errors'][] = $this->error;
            return $result;
        }
        $inputLimit = (int) ini_get('max_input_vars');
        $previewLimit = $inputLimit > 0 ? min(5000, max(1, $inputLimit - 20)) : 5000;
        if (count($transactions) > $previewLimit) {
            $result['errors'][] = 'CSV contains too many transactions for one preview (maximum '.$previewLimit.' on this server). Split the file into smaller CSV files with headers.';
            return $result;
        }

        $accountCurrency = $this->getAccountCurrency();
        if ($accountCurrency === false) {
            $result['errors'][] = $this->error;
            return $result;
        }

        $seenKeys = array();
        foreach ($transactions as $transaction) {
            if ($transaction['currency'] !== '' && $accountCurrency !== '' && strtoupper($transaction['currency']) !== $accountCurrency) {
                $result['errors'][] = 'Row '.$transaction['row'].': transaction currency '.strtoupper($transaction['currency']).' does not match account currency '.$accountCurrency;
                continue;
            }
            $analysis = $this->analyzeTransaction($transaction);
            if ($analysis === false) {
                $result['errors'][] = 'Row '.$transaction['row'].': '.$this->error;
                continue;
            }
            $transaction['duplicate'] = $analysis['duplicate'];
            $transaction['similarities'] = $analysis['similarities'];
            $keys = $this->generateImportKeys($transaction, $this->parseAmount($transaction['amount']));
            if (!$transaction['duplicate'] && isset($seenKeys[$keys['primary']])) {
                $transaction['duplicate'] = $seenKeys[$keys['primary']];
            }
            if (!isset($seenKeys[$keys['primary']])) {
                $seenKeys[$keys['primary']] = array('csv_row' => $transaction['row'], 'date' => $transaction['booking_date'], 'label' => $transaction['label']);
            }
            $result['transactions'][] = $transaction;
        }
        return $result;
    }

    /** @return string|false */
    private function getAccountCurrency()
    {
        $sql = "SELECT currency_code FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".((int) $this->accountid);
        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) === 0) {
            $this->error = $resql ? 'Selected bank account no longer exists' : $this->db->lasterror();
            return false;
        }
        $object = $this->db->fetch_object($resql);
        return strtoupper((string) $object->currency_code);
    }

    /**
     * Import only explicitly selected CSV row numbers. Duplicate warnings from
     * the preview are advisory: selecting such a row deliberately imports it.
     *
     * @param array $transactions Server-side preview transactions
     * @param int[] $selectedRows Original CSV row numbers
     * @return array
     */
    public function importSelected($transactions, $selectedRows)
    {
        $result = array('success' => 0, 'errors' => array(), 'skipped' => 0, 'imported_rows' => array(), 'failed_rows' => array());
        $accountCurrency = $this->getAccountCurrency();
        if ($accountCurrency === false) {
            $result['errors'][] = $this->error;
            return $result;
        }
        $selected = array();
        foreach ($selectedRows as $row) $selected[(int) $row] = true;

        foreach ($transactions as $transaction) {
            if (!isset($transaction['row']) || !isset($selected[(int) $transaction['row']])) continue;
            if ($transaction['currency'] !== '' && $accountCurrency !== '' && strtoupper($transaction['currency']) !== $accountCurrency) {
                $importResult = 'Transaction currency no longer matches account currency; create a new preview';
            } else {
                $importResult = $this->processRow($transaction, false);
            }
            if ($importResult === true) {
                $result['success']++;
                $result['imported_rows'][] = (int) $transaction['row'];
            } else {
                $result['failed_rows'][] = (int) $transaction['row'];
                $result['errors'][] = 'Row '.$transaction['row'].': '.$importResult;
            }
        }
        return $result;
    }

    /** @return array|false */
    private function readAndNormalizeFile($filename, &$errors)
    {
        if (!in_array($this->encoding, array(null, '', 'UTF-8', 'ISO-8859-1'), true)) {
            $errors[] = 'Unsupported encoding';
            return false;
        }
        $handle = fopen($filename, 'r');
        if (!$handle) {
            $this->error = 'Could not open file';
            return false;
        }
        $firstLine = '';
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $firstLine = $line;
                break;
            }
        }
        fclose($handle);
        if ($firstLine === '') {
            $this->error = 'CSV file is empty';
            return false;
        }

        $layout = $this->detectCsvLayout($firstLine);
        if ($layout === false) return false;

        $handle = fopen($filename, 'r');
        if (!$handle) {
            $this->error = 'Could not open file';
            return false;
        }
        $headerRead = false;
        $row = 0;
        $transactions = array();
        while (($data = fgetcsv($handle, 0, $layout['separator'])) !== false) {
            $row++;
            $data = $this->convertEncoding($data);
            foreach ($data as $field) {
                if (preg_match('//u', (string) $field) !== 1) {
                    $errors[] = 'Row '.$row.': invalid text encoding; choose the matching CSV encoding';
                    fclose($handle);
                    return false;
                }
            }
            if (!$headerRead) {
                if (!$this->isEmptyCsvRow($data)) $headerRead = true;
                continue;
            }
            if ($this->isEmptyCsvRow($data)) continue;
            $transaction = $this->mapRowToTransaction($data, $layout['profile'], $row);
            if ($transaction === false) $errors[] = 'Row '.$row.': '.$this->error;
            else $transactions[] = $transaction;
            if (count($transactions) + count($errors) > 5000) {
                $errors[] = 'CSV contains too many transactions (maximum 5000)';
                break;
            }
        }
        fclose($handle);
        return empty($errors) ? $transactions : false;
    }

    /** Use str_getcsv to score both supported delimiters without breaking quoted fields. */
    private function detectCsvLayout($headerLine)
    {
        $candidates = array();
        foreach (array(';', ',') as $separator) {
            $header = $this->convertEncoding(str_getcsv($headerLine, $separator));
            $candidates[] = array(
                'separator' => $separator,
                'profile' => $this->detectProfile($header, false),
                'columns' => count($header)
            );
        }
        usort($candidates, function ($a, $b) {
            $aMatch = $a['profile'] === false ? 0 : 1;
            $bMatch = $b['profile'] === false ? 0 : 1;
            return $aMatch === $bMatch ? $b['columns'] - $a['columns'] : $bMatch - $aMatch;
        });
        if ($candidates[0]['profile'] !== false) return $candidates[0];

        $header = $this->convertEncoding(str_getcsv($headerLine, $candidates[0]['separator']));
        $this->detectProfile($header, true);
        return false;
    }

    /** @return array|false */
    private function detectProfile($header, $setError)
    {
        $headers = array();
        foreach ($header as $index => $name) {
            $normalized = $this->normalizeHeader($name);
            if ($normalized !== '' && !isset($headers[$normalized])) $headers[$normalized] = $index;
        }

        if ($this->hasAnyHeader($headers, array('partner name', 'partner iban', 'payment reference', 'amount eur', 'account name', 'original amount'))) {
            return $this->buildProfile('N26', $headers, $this->n26Columns(), array(
                'booking_date', 'value_date', 'counterparty_name', 'counterparty_iban',
                'booking_text', 'payment_purpose', 'amount'
            ), $setError);
        }
        if ($this->hasAnyHeader($headers, array('buchungstag', 'buchungstext', 'verwendungszweck', 'auftragskonto', 'sammlerreferenz', 'glaeubiger id'))) {
            return $this->buildProfile('Haspa', $headers, $this->haspaColumns(), array('booking_date', 'amount'), $setError);
        }
        if ($setError) $this->error = 'Unsupported CSV format: required N26/Haspa headers not found';
        return false;
    }

    private function n26Columns()
    {
        return array(
            'booking_date' => array('booking date'), 'value_date' => array('value date'),
            'counterparty_name' => array('partner name'), 'counterparty_iban' => array('partner iban'),
            'booking_text' => array('type'), 'payment_purpose' => array('payment reference'),
            'category' => array('category'), 'account_name' => array('account name'),
            'amount' => array('amount eur'), 'original_amount' => array('original amount'),
            'original_currency' => array('original currency'), 'exchange_rate' => array('exchange rate')
        );
    }

    private function haspaColumns()
    {
        return array(
            'account' => array('auftragskonto', 'account'), 'booking_date' => array('buchungstag'),
            'value_date' => array('valutadatum'), 'booking_text' => array('buchungstext'),
            'payment_purpose' => array('verwendungszweck'),
            'creditor_id' => array('glaeubiger id', 'glaubiger id', 'creditor id'),
            'mandate_reference' => array('mandatsreferenz', 'mandate reference'),
            'collector_reference' => array('sammlerreferenz', 'collector reference'),
            'counterparty_name' => array('beguenstigter zahlungspflichtiger', 'begunstigter zahlungspflichtiger', 'counterparty name'),
            'counterparty_iban' => array('kontonummer iban', 'counterparty iban'),
            'counterparty_bic' => array('bic swift code', 'counterparty bic'),
            'amount' => array('betrag', 'amount'), 'currency' => array('waehrung', 'wahrung', 'currency'),
            'info' => array('info')
        );
    }

    /** @return array|false */
    private function buildProfile($format, $headers, $definitions, $required, $setError)
    {
        $columns = array();
        foreach ($definitions as $field => $aliases) {
            $columns[$field] = null;
            foreach ($aliases as $alias) {
                if (isset($headers[$alias])) {
                    $columns[$field] = $headers[$alias];
                    break;
                }
            }
        }
        foreach ($required as $field) {
            if ($columns[$field] === null) {
                if ($setError) $this->error = 'Missing required '.$format.' column: '.$this->displayColumnName($format, $field);
                return false;
            }
        }
        return array('format' => strtolower($format), 'columns' => $columns);
    }

    private function displayColumnName($format, $field)
    {
        $names = array(
            'booking_date' => $format === 'N26' ? 'Booking Date' : 'Buchungstag',
            'value_date' => $format === 'N26' ? 'Value Date' : 'Valutadatum',
            'counterparty_name' => $format === 'N26' ? 'Partner Name' : 'Beguenstigter/Zahlungspflichtiger',
            'counterparty_iban' => $format === 'N26' ? 'Partner Iban' : 'Kontonummer/IBAN',
            'booking_text' => $format === 'N26' ? 'Type' : 'Buchungstext',
            'payment_purpose' => $format === 'N26' ? 'Payment Reference' : 'Verwendungszweck',
            'amount' => $format === 'N26' ? 'Amount (EUR)' : 'Betrag'
        );
        return $names[$field];
    }

    private function hasAnyHeader($headers, $needles)
    {
        foreach ($needles as $needle) if (isset($headers[$needle])) return true;
        return false;
    }

    /** Map one format-specific record to the common transaction model. */
    private function mapRowToTransaction($data, $profile, $row)
    {
        $columns = $profile['columns'];
        $get = function ($name) use ($data, $columns) {
            return isset($columns[$name]) && $columns[$name] !== null && isset($data[$columns[$name]]) ? trim($data[$columns[$name]]) : '';
        };
        $bookingDate = $get('booking_date');
        $valueDate = $get('value_date');
        if ($valueDate === '') $valueDate = $bookingDate;
        $transaction = array(
            'row' => $row, 'format' => $profile['format'], 'booking_date' => $bookingDate, 'value_date' => $valueDate,
            'booking_text' => $get('booking_text'), 'payment_purpose' => $get('payment_purpose'),
            'counterparty_name' => $get('counterparty_name'), 'counterparty_iban' => $get('counterparty_iban'),
            'counterparty_bic' => $get('counterparty_bic'), 'amount' => $get('amount'),
            'currency' => $profile['format'] === 'n26' ? 'EUR' : $get('currency'),
            'reference' => $profile['format'] === 'n26' ? $get('payment_purpose') : $get('mandate_reference'),
            'creditor_id' => $get('creditor_id'), 'collector_reference' => $get('collector_reference'),
            'category' => $get('category'), 'account_name' => $get('account_name'),
            'original_amount' => $get('original_amount'), 'original_currency' => $get('original_currency'), 'exchange_rate' => $get('exchange_rate')
        );
        if ($bookingDate === '') $this->error = 'Missing booking date';
        elseif ($this->parseDate($bookingDate) === false) $this->error = 'Invalid booking date: '.$bookingDate;
        elseif ($this->parseDate($valueDate) === false) $this->error = 'Invalid value date: '.$valueDate;
        elseif ($transaction['amount'] === '') $this->error = 'Missing amount';
        elseif ($this->parseAmount($transaction['amount']) === false) $this->error = 'Invalid amount: '.$transaction['amount'];
        else {
            $transaction['label'] = $this->buildLabel($transaction);
            return $transaction;
        }
        return false;
    }

    /** Insert an already validated, normalized transaction. */
    private function processRow($transaction, $skipExisting)
    {
        global $user;
        $this->error = '';
        $dateo = $this->parseDate($transaction['booking_date']);
        $datev = $this->parseDate($transaction['value_date']);
        $amount = $this->parseAmount($transaction['amount']);
        $importKeys = $this->generateImportKeys($transaction, $amount);
        $duplicate = $this->findExactDuplicate($importKeys);
        if ($this->error !== '') return $this->error;
        if ($skipExisting && $duplicate !== false) return 'skipped';

        $this->db->begin();
        try {
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."bank (";
            $sql .= "datec, dateo, datev, label, amount, amount_main_currency, fk_user_author, num_chq, ";
            $sql .= "fk_account, fk_type, emetteur, banque, rappro, numero_compte, num_releve, note, import_key";
            $sql .= ") VALUES (";
            $sql .= "'".$this->db->idate(dol_now())."', ";
            $sql .= "'".$this->db->idate($dateo)."', ";
            $sql .= "'".$this->db->idate($datev)."', ";
            $sql .= "'".$this->db->escape($this->limitString($transaction['label']))."', ";
            $sql .= number_format($amount, 8, '.', '').", NULL, ".((int) $user->id).", NULL, ";
            $sql .= ((int) $this->accountid).", 'VIR', ";
            $sql .= $this->sqlNullableString($this->limitString($transaction['counterparty_name'])).", ";
            $sql .= $this->sqlNullableString($this->limitString($transaction['counterparty_bic'])).", 0, '', NULL, ";
            $sql .= $this->sqlNullableString($this->buildNote($transaction)).", ";
            $sql .= "'".$this->db->escape($importKeys['primary'])."')";

            if ($this->db->query($sql)) {
                if ($this->db->commit() <= 0) throw new RuntimeException('Could not commit bank transaction: '.$this->db->lasterror());
                return true;
            }
            $error = $this->db->lasterror();
            $this->db->rollback();
            return $error;
        } catch (Throwable $exception) {
            $this->db->rollback();
            return $exception->getMessage();
        }
    }

    /**
     * Generate the current account-scoped key plus compatible historical keys.
     * The old Haspa key is only used for lookup, so existing installations do
     * not re-import earlier statements after upgrading.
     */
    private function generateImportKeys($transaction, $amount)
    {
        if ($transaction['format'] !== 'n26') {
            $legacy = implode('|', array(trim($transaction['counterparty_iban']), trim($transaction['counterparty_name']), number_format($amount, 2, '.', ''), trim($transaction['label']), trim($transaction['reference'])));
            $current = implode('|', array('haspa-v2', date('Y-m-d', $this->parseDate($transaction['booking_date'])), $legacy));
            return array('primary' => substr(sha1($current), 0, 14), 'lookup' => array(substr(sha1($current), 0, 14), substr(sha1($legacy), 0, 14)));
        } else {
            $key = implode('|', array('n26', date('Y-m-d', $this->parseDate($transaction['booking_date'])), trim($transaction['counterparty_iban']), trim($transaction['counterparty_name']), number_format($amount, 2, '.', ''), trim($transaction['payment_purpose']), trim($transaction['booking_text']), trim($transaction['reference'])));
            $key = substr(sha1($key), 0, 14);
            return array('primary' => $key, 'lookup' => array($key));
        }
    }

    /** @return array|false */
    private function findExactDuplicate($importKeys)
    {
        $quotedKeys = array();
        foreach (array_unique($importKeys['lookup']) as $key) $quotedKeys[] = "'".$this->db->escape($key)."'";
        $sql = "SELECT rowid, dateo, label, amount FROM ".MAIN_DB_PREFIX."bank";
        $sql .= " WHERE fk_account = ".((int) $this->accountid);
        $sql .= " AND import_key IN (".implode(',', $quotedKeys).")";
        $sql .= " ORDER BY rowid ASC LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }
        if ($this->db->num_rows($resql) === 0) return false;
        $object = $this->db->fetch_object($resql);
        return array('rowid' => (int) $object->rowid, 'date' => $object->dateo, 'label' => $object->label, 'amount' => (float) $object->amount);
    }

    /** @return array|false */
    private function analyzeTransaction($transaction)
    {
        $this->error = '';
        $amount = $this->parseAmount($transaction['amount']);
        $date = $this->parseDate($transaction['booking_date']);
        $keys = $this->generateImportKeys($transaction, $amount);
        $duplicate = $this->findExactDuplicate($keys);
        if ($this->error !== '' && $duplicate === false) return false;

        $from = date('Y-m-d', strtotime('-30 days', $date));
        $to = date('Y-m-d', strtotime('+30 days', $date));
        $sql = "SELECT rowid, dateo, label, amount, import_key FROM ".MAIN_DB_PREFIX."bank";
        $sql .= " WHERE fk_account = ".((int) $this->accountid);
        $sql .= " AND dateo >= '".$this->db->escape($from)."' AND dateo <= '".$this->db->escape($to)."'";
        $sql .= " AND amount >= ".number_format($amount - 0.005, 8, '.', '');
        $sql .= " AND amount <= ".number_format($amount + 0.005, 8, '.', '');
        $sql .= " ORDER BY dateo ASC, rowid ASC LIMIT 50";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }

        $similarities = array();
        $incomingLabel = $this->normalizeSimilarityLabel($transaction['label']);
        while ($object = $this->db->fetch_object($resql)) {
            if ($duplicate !== false && (int) $object->rowid === (int) $duplicate['rowid']) continue;
            $existingDate = strtotime(substr($object->dateo, 0, 10));
            $days = abs((int) round(($date - $existingDate) / 86400));
            $existingLabel = $this->normalizeSimilarityLabel($object->label);
            $score = 0.0;
            if ($incomingLabel !== '' && $existingLabel !== '') similar_text($incomingLabel, $existingLabel, $score);
            if ($days <= 3 || $score >= 85.0) {
                $similarities[] = array(
                    'rowid' => (int) $object->rowid,
                    'date' => $object->dateo,
                    'label' => $object->label,
                    'amount' => (float) $object->amount,
                    'score' => round($score, 1),
                    'days' => $days
                );
            }
        }
        return array('duplicate' => $duplicate, 'similarities' => $similarities);
    }

    private function sqlNullableString($value)
    {
        $value = trim((string) $value);
        return $value === '' ? 'NULL' : "'".$this->db->escape($value)."'";
    }

    private function normalizeSimilarityLabel($value)
    {
        $value = trim((string) $value);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) $value = $ascii;
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    private function buildLabel($transaction)
    {
        // Keep the historical Haspa label (and therefore its import key) intact.
        if ($transaction['format'] !== 'n26') return trim($transaction['payment_purpose']);
        foreach (array('payment_purpose', 'booking_text', 'counterparty_name') as $field) {
            if (trim($transaction[$field]) !== '') return trim($transaction[$field]);
        }
        return 'Bank transaction';
    }

    private function buildNote($transaction)
    {
        $parts = array();
        if ($transaction['label'] !== $this->limitString($transaction['label'])) $parts[] = 'Description='.$transaction['label'];
        if ($transaction['counterparty_name'] !== $this->limitString($transaction['counterparty_name'])) $parts[] = 'PartnerName='.$transaction['counterparty_name'];
        if ($transaction['reference'] !== '') $parts[] = 'Reference='.$transaction['reference'];
        if ($transaction['counterparty_iban'] !== '') $parts[] = 'PartnerIBAN='.$transaction['counterparty_iban'];
        if ($transaction['counterparty_bic'] !== '') $parts[] = 'PartnerBIC='.$transaction['counterparty_bic'];
        if ($transaction['collector_reference'] !== '') $parts[] = 'Sammlerreferenz='.$transaction['collector_reference'];
        if ($transaction['creditor_id'] !== '') $parts[] = 'GlaeubigerId='.$transaction['creditor_id'];
        return implode(' ', $parts);
    }

    private function convertEncoding($data)
    {
        if ($this->encoding && strtoupper($this->encoding) !== 'UTF-8') {
            foreach ($data as &$field) {
                $converted = iconv($this->encoding, 'UTF-8//TRANSLIT', $field);
                if ($converted !== false) $field = $converted;
            }
            unset($field);
        }
        return $data;
    }

    private function normalizeHeader($value)
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) $value = $ascii;
        $value = strtolower($value);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private function isEmptyCsvRow($data)
    {
        foreach ($data as $field) if (trim($field) !== '') return false;
        return true;
    }

    /** Parse only documented date formats, never silently reinterpret them. */
    private function parseDate($dateString)
    {
        $dateString = trim($dateString);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateString, $match)) {
            $year = (int) $match[1]; $month = (int) $match[2]; $day = (int) $match[3];
        } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{2}|\d{4})$/', $dateString, $match)) {
            $day = (int) $match[1]; $month = (int) $match[2]; $year = (int) $match[3];
            if (strlen($match[3]) === 2) $year += 2000;
        } else return false;
        if (!checkdate($month, $day, $year)) return false;
        return dol_mktime(0, 0, 0, $month, $day, $year);
    }

    private function parseAmount($value)
    {
        $value = trim(str_replace(' ', '', $value));
        if ($value === '') return false;
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            if ($comma > $dot) {
                if (!preg_match('/^[+-]?\d{1,3}(?:\.\d{3})+,\d{1,8}$/D', $value)) return false;
                $value = str_replace(',', '.', str_replace('.', '', $value));
            } else {
                if (!preg_match('/^[+-]?\d{1,3}(?:,\d{3})+\.\d{1,8}$/D', $value)) return false;
                $value = str_replace(',', '', $value);
            }
        } elseif ($comma !== false) $value = str_replace(',', '.', $value);
        if (!preg_match('/^[+-]?\d+(?:\.\d{1,8})?$/D', $value)) return false;
        $amount = (float) $value;
        return is_finite($amount) && abs($amount) < 1.0e16 ? $amount : false;
    }

    private function limitString($text, $length = 255, $fixed = false)
    {
        if ($text === null) return $fixed ? str_repeat(' ', $length) : '';
        // Cut on a UTF-8 character boundary, including on PHP without mbstring.
        preg_match('/^.{0,'.((int) $length).'}/us', $text, $match);
        $limited = isset($match[0]) ? $match[0] : '';
        return $fixed ? str_pad($limited, $length) : $limited;
    }
}
