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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

// Make Dolibarr validate its rolling CSRF token for every POST on this page.
define('CSRFCHECK_WITH_TOKEN', 1);

// Load Dolibarr environment.
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) {
    $res = @include substr($tmp, 0, $i + 1).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, $i + 1)).'/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, $i + 1)).'/main.inc.php';
}
foreach (array('../main.inc.php', '../../main.inc.php', '../../../main.inc.php') as $mainFile) {
    if (!$res && file_exists($mainFile)) $res = @include $mainFile;
}
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/core/class/BankImport.class.php';

if (!isModEnabled('bankimport') || !isModEnabled('banque') || !empty($user->socid) || !$user->hasRight('banque', 'modifier')) accessforbidden();
$langs->load('bankimport@bankimport');

function bankimportAccountExists($db, $accountId)
{
    if ($accountId <= 0) return false;
    $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'bank_account WHERE rowid = '.((int) $accountId);
    $sql .= ' AND entity IN ('.getEntity('bank_account').')';
    $sql .= ' AND courant <> 2 AND clos = 0';
    $resql = $db->query($sql);
    return $resql && $db->num_rows($resql) > 0;
}

function bankimportEscape($value)
{
    return dol_escape_htmltag((string) $value);
}

$accountid = GETPOST('accountid', 'int');
$encoding = GETPOST('encoding', 'alpha');
$action = GETPOST('action', 'alpha');
if ($encoding === '') $encoding = 'UTF-8';
if ($action !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') accessforbidden();
if (!in_array($encoding, array('UTF-8', 'ISO-8859-1'), true)) accessforbidden();

if (!isset($_SESSION['bankimport_previews']) || !is_array($_SESSION['bankimport_previews'])) {
    $_SESSION['bankimport_previews'] = array();
}
foreach ($_SESSION['bankimport_previews'] as $storedId => $storedPreview) {
    if (empty($storedPreview['created']) || $storedPreview['created'] < time() - 3600) {
        unset($_SESSION['bankimport_previews'][$storedId]);
    }
}

$bankImport = new BankImport($db);
$preview = null;
$previewId = '';

if ($action === 'preview') {
    $errors = array();
    if (!bankimportAccountExists($db, $accountid)) $errors[] = $langs->trans('BANKIMPORT_Invalid_account');
    if (empty($_FILES['statement']['tmp_name'])) $errors[] = $langs->trans('BANKIMPORT_Choose_file');

    if (empty($errors)) {
        $bankImport->setAccountId($accountid);
        $bankImport->setEncoding($encoding);
        if (!$bankImport->validateFile($_FILES['statement'])) {
            $errors[] = $bankImport->error;
        } else {
            $previewResult = $bankImport->previewFile($_FILES['statement']['tmp_name']);
            $errors = $previewResult['errors'];
            if (empty($errors)) {
                // Bound the amount of bank data retained in the PHP session.
                while (count($_SESSION['bankimport_previews']) >= 3) array_shift($_SESSION['bankimport_previews']);
                $previewId = bin2hex(random_bytes(16));
                $preview = array(
                    'created' => time(),
                    'user_id' => (int) $user->id,
                    'entity' => (int) $conf->entity,
                    'accountid' => (int) $accountid,
                    'encoding' => $encoding,
                    'transactions' => $previewResult['transactions']
                );
                $_SESSION['bankimport_previews'][$previewId] = $preview;
            }
        }
    }
    foreach ($errors as $error) setEventMessages(bankimportEscape($error), null, 'errors');
} elseif ($action === 'import_selected') {
    $previewId = GETPOST('preview_id', 'alpha');
    if (!isset($_SESSION['bankimport_previews'][$previewId])) {
        setEventMessages($langs->trans('BANKIMPORT_Preview_expired'), null, 'errors');
    } else {
        $preview = $_SESSION['bankimport_previews'][$previewId];
        if ((int) $preview['user_id'] !== (int) $user->id || (int) $preview['entity'] !== (int) $conf->entity) {
            unset($_SESSION['bankimport_previews'][$previewId]);
            $preview = null;
            setEventMessages($langs->trans('BANKIMPORT_Preview_expired'), null, 'errors');
        } else {
            $selectedRows = array();
            if (isset($_POST['selected']) && is_array($_POST['selected'])) {
                foreach ($_POST['selected'] as $row) if ((int) $row > 0) $selectedRows[] = (int) $row;
            }
            if (GETPOST('selection_complete', 'alpha') !== 'yes') {
                setEventMessages($langs->trans('BANKIMPORT_Incomplete_selection'), null, 'errors');
            } elseif (empty($selectedRows)) {
                setEventMessages($langs->trans('BANKIMPORT_Select_at_least_one'), null, 'errors');
            } elseif (!bankimportAccountExists($db, (int) $preview['accountid'])) {
                setEventMessages($langs->trans('BANKIMPORT_Invalid_account'), null, 'errors');
            } else {
                $bankImport->setAccountId((int) $preview['accountid']);
                $bankImport->setEncoding($preview['encoding']);
                $result = $bankImport->importSelected($preview['transactions'], $selectedRows);
                if ($result['success'] > 0) setEventMessages($langs->trans('BANKIMPORT_Success_imported', $result['success']), null, 'mesgs');
                foreach ($result['errors'] as $error) setEventMessages(bankimportEscape($error), null, 'errors');

                if (!empty($result['imported_rows'])) {
                    $imported = array_flip($result['imported_rows']);
                    $remaining = array();
                    foreach ($preview['transactions'] as $transaction) {
                        if (!isset($imported[(int) $transaction['row']])) $remaining[] = $transaction;
                    }
                    $preview['transactions'] = $remaining;
                }
                if (empty($preview['transactions'])) {
                    unset($_SESSION['bankimport_previews'][$previewId]);
                    $preview = null;
                } else {
                    $_SESSION['bankimport_previews'][$previewId] = $preview;
                }
            }
        }
    }
}

llxHeader('', $langs->trans('BANKIMPORT_Title'));
print load_fiche_titre($langs->trans('BANKIMPORT_Title'));

if ($preview !== null) {
    $duplicateCount = 0;
    $similarCount = 0;
    foreach ($preview['transactions'] as $transaction) {
        if (!empty($transaction['duplicate'])) $duplicateCount++;
        elseif (!empty($transaction['similarities'])) $similarCount++;
    }

    print '<div class="info marginbottomonly">'.$langs->trans('BANKIMPORT_Preview_help').'</div>';
    print '<div class="opacitymedium marginbottomonly">'.$langs->trans('BANKIMPORT_Preview_summary', count($preview['transactions']), $duplicateCount, $similarCount).'</div>';
    print '<form action="'.bankimportEscape($_SERVER['PHP_SELF']).'" method="post" id="bankimport-preview-form">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="import_selected">';
    print '<input type="hidden" name="preview_id" value="'.bankimportEscape($previewId).'">';
    print '<div class="marginbottomonly"><button type="button" class="button small" id="select-all">'.$langs->trans('BANKIMPORT_Select_all').'</button> ';
    print '<button type="button" class="button small" id="select-none">'.$langs->trans('BANKIMPORT_Select_none').'</button></div>';
    print '<div class="div-table-responsive"><table class="liste centpercent">';
    print '<tr class="liste_titre"><th class="center"></th><th>'.$langs->trans('BANKIMPORT_Row').'</th><th>'.$langs->trans('BANKIMPORT_Date').'</th><th>'.$langs->trans('BANKIMPORT_Partner').'</th><th>'.$langs->trans('BANKIMPORT_Description').'</th><th class="right">'.$langs->trans('BANKIMPORT_Amount').'</th><th>'.$langs->trans('BANKIMPORT_Status').'</th></tr>';
    foreach ($preview['transactions'] as $transaction) {
        $hasWarning = !empty($transaction['duplicate']) || !empty($transaction['similarities']);
        print '<tr class="oddeven">';
        print '<td class="center"><input type="checkbox" class="bankimport-select" name="selected[]" value="'.((int) $transaction['row']).'"'.($hasWarning ? '' : ' checked').'></td>';
        print '<td>'.((int) $transaction['row']).'</td><td>'.bankimportEscape($transaction['booking_date']).'</td>';
        print '<td>'.bankimportEscape($transaction['counterparty_name']).'</td><td>'.bankimportEscape($transaction['label']).'</td>';
        print '<td class="right">'.bankimportEscape($transaction['amount']).' '.bankimportEscape($transaction['currency']).'</td><td>';
        if (!empty($transaction['duplicate'])) {
            $item = $transaction['duplicate'];
            print '<span class="badge badge-status8">'.$langs->trans('BANKIMPORT_Exact_duplicate').'</span><br>';
            $source = isset($item['csv_row']) ? $langs->trans('BANKIMPORT_Row').' '.((int) $item['csv_row']) : '#'.((int) $item['rowid']);
            print '<span class="opacitymedium">'.bankimportEscape($source).' · '.bankimportEscape(substr($item['date'], 0, 10)).' · '.bankimportEscape($item['label']).'</span>';
        } elseif (!empty($transaction['similarities'])) {
            print '<span class="badge badge-status4">'.$langs->trans('BANKIMPORT_Similar_found', count($transaction['similarities'])).'</span>';
            foreach (array_slice($transaction['similarities'], 0, 3) as $item) {
                print '<br><span class="opacitymedium">#'.((int) $item['rowid']).' · '.bankimportEscape(substr($item['date'], 0, 10)).' · '.bankimportEscape($item['label']).'</span>';
            }
        } else {
            print '<span class="badge badge-status4">'.$langs->trans('BANKIMPORT_New_transaction').'</span>';
        }
        print '</td></tr>';
    }
    print '</table></div><input type="hidden" name="selection_complete" value="yes"><div class="center margin-top">';
    print '<input type="submit" class="button button-save" id="import-selected" value="'.$langs->trans('BANKIMPORT_Import_selected').'"> ';
    print '<a class="button button-cancel" href="'.bankimportEscape($_SERVER['PHP_SELF']).'">'.$langs->trans('BANKIMPORT_Choose_another_file').'</a>';
    print '</div></form>';
    print '<script>document.addEventListener("DOMContentLoaded",function(){var boxes=[].slice.call(document.querySelectorAll(".bankimport-select"));var submit=document.getElementById("import-selected");function update(){submit.disabled=!boxes.some(function(box){return box.checked;});}document.getElementById("select-all").addEventListener("click",function(){boxes.forEach(function(box){box.checked=true;});update();});document.getElementById("select-none").addEventListener("click",function(){boxes.forEach(function(box){box.checked=false;});update();});boxes.forEach(function(box){box.addEventListener("change",update);});update();});</script>';
} else {
    print '<form action="'.bankimportEscape($_SERVER['PHP_SELF']).'" method="post" enctype="multipart/form-data">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="preview">';
    print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'.$langs->trans('BANKIMPORT_Import_Form').'</td></tr>';
    print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('BANKIMPORT_Bank_account').'</td><td>';
    $form = new Form($db);
    $form->select_comptes($accountid, 'accountid', 0, '(courant:<>:2)', 1, '', 1);
    print ' <span class="fieldrequired">*</span></td></tr>';
    print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('BANKIMPORT_File_label').'</td><td><input type="file" name="statement" accept=".csv,text/csv,text/plain" required></td></tr>';
    print '<tr class="oddeven"><td>'.$langs->trans('BANKIMPORT_Encoding').'</td><td><select name="encoding">';
    foreach (array('UTF-8', 'ISO-8859-1') as $availableEncoding) {
        print '<option value="'.$availableEncoding.'"'.($encoding === $availableEncoding ? ' selected' : '').'>'.$availableEncoding.'</option>';
    }
    print '</select></td></tr><tr class="oddeven"><td colspan="2" class="center"><input type="submit" class="button button-save" value="'.$langs->trans('BANKIMPORT_Create_preview').'"></td></tr></table></form>';
}

print '<br><div class="info"><strong>'.$langs->trans('BANKIMPORT_Help_Title').'</strong><br>'.$langs->trans('BANKIMPORT_Help_Description').'<br><br><strong>'.$langs->trans('BANKIMPORT_Help_Format').'</strong><br>'.$langs->trans('BANKIMPORT_Help_Format_Details').'</div>';

llxFooter();
$db->close();
