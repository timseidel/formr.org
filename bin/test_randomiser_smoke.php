#!/usr/bin/php
<?php
/**
 * Randomiser end-to-end smoke check against the live MariaDB.
 *
 * Reproduces the core of AdminAjaxController::ajaxCreateRandomiser (which
 * needs an HTTP/ajax + Site context PHPUnit's SQLite bootstrap can't host):
 *   - mint a premade survey via SurveyStudy::createFromData with a single
 *     `calculate` item `group` = `sample(1:N, 1)`,
 *   - attach it to a run as a Survey unit.
 *
 * Asserts the survey, its results table, the calculate item value, and the
 * survey_run_units attachment all land correctly. Cleans up its fixtures.
 *
 * Usage:
 *   docker exec formr_app php bin/test_randomiser_smoke.php
 *
 * Exits 0 on success, non-zero on first assertion failure.
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();

$failures = 0;
$artefacts = ['run_id' => null, 'study_id' => null, 'run_unit_id' => null, 'results_table' => null];

function r_teardown(DB $db, array &$a): void {
    if ($a['run_unit_id']) {
        try { $db->exec('DELETE FROM survey_run_units WHERE id = :id', ['id' => $a['run_unit_id']]); } catch (Throwable $e) {}
    }
    if ($a['study_id']) {
        try { $db->exec('DELETE FROM survey_items WHERE study_id = :id', ['id' => $a['study_id']]); } catch (Throwable $e) {}
        try { $db->exec('DELETE FROM survey_studies WHERE id = :id', ['id' => $a['study_id']]); } catch (Throwable $e) {}
        try { $db->exec('DELETE FROM survey_units WHERE id = :id', ['id' => $a['study_id']]); } catch (Throwable $e) {}
    }
    if ($a['results_table']) {
        try { $db->exec('DROP TABLE IF EXISTS `' . $a['results_table'] . '`'); } catch (Throwable $e) {}
    }
    if ($a['run_id']) {
        try { $db->exec('DELETE FROM survey_runs WHERE id = :id', ['id' => $a['run_id']]); } catch (Throwable $e) {}
    }
}

function r_assert($cond, string $label, $detail = ''): void {
    global $failures;
    if ($cond) {
        echo "  \e[32mOK\e[0m  {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
    } else {
        echo "  \e[31mFAIL\e[0m {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
        $failures++;
    }
}

try {
    echo "== Randomiser smoke ==\n";

    $owner = $db->execute('SELECT id FROM survey_users WHERE admin >= 1 ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) {
        fwrite(STDERR, "No admin survey_users row to anchor the test run.\n");
        exit(2);
    }
    $ownerId = (int) $owner['id'];

    // Site::getCurrentUser() simply returns the global $user.
    $GLOBALS['user'] = new User($ownerId);

    $artefacts['run_id'] = $db->insert('survey_runs', [
        'user_id' => $ownerId,
        'name'    => 'randomiser_smoke_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(),
    ]);
    $run = new Run(null, $artefacts['run_id']);

    $groups = 3;
    $name = 'randomiser_smoke_s_' . bin2hex(random_bytes(3));
    $surveyData = (object) [
        'name'  => $name,
        'items' => [
            (object) [
                'type'  => 'calculate',
                'name'  => 'group',
                'label' => '',
                'value' => "sample(1:{$groups}, 1)",
            ],
        ],
    ];

    // createFromData mints the survey AND attaches it to the run as a
    // Survey unit (single source of attachment — see ajaxCreateRandomiser).
    SurveyStudy::createFromData($surveyData, ['run' => $run, 'position' => 10]);

    $study = SurveyStudy::loadByUserAndName(Site::getCurrentUser(), $name);
    r_assert($study->valid, 'survey created', $name);
    $artefacts['study_id'] = $study->id ?: null;
    $artefacts['results_table'] = $study->results_table ?: null;

    // calculate item present with the right R expression
    $item = $db->execute(
        'SELECT type, value FROM survey_items WHERE study_id = :id AND name = :n',
        ['id' => $study->id, 'n' => 'group'], false, true
    );
    r_assert($item && $item['type'] === 'calculate', 'group item is calculate', $item['type'] ?? 'missing');
    r_assert($item && $item['value'] === "sample(1:{$groups}, 1)", 'group item value', $item['value'] ?? 'missing');

    // results table carries the `group` column
    $cols = $db->execute("SHOW COLUMNS FROM `{$study->results_table}`");
    $colNames = array_column($cols, 'Field');
    r_assert(in_array('group', $colNames, true), 'results table has `group` column', $study->results_table);

    // EXACTLY ONE run_unit row must exist for this survey in this run.
    // (Regression guard: an earlier version attached a second time, which
    // produced a duplicate unit that only surfaced after a page reload.)
    $runUnits = $db->execute(
        'SELECT id, position FROM survey_run_units WHERE run_id = :run_id AND unit_id = :unit_id',
        ['run_id' => $run->id, 'unit_id' => $study->id]
    );
    r_assert(count($runUnits) === 1, 'exactly one run_unit attached', 'count=' . count($runUnits));
    if ($runUnits) {
        $artefacts['run_unit_id'] = (int) $runUnits[0]['id'];
        r_assert((int) $runUnits[0]['position'] === 10, 'run_unit at requested position', $runUnits[0]['position']);

        // The AJAX response renders the just-attached unit via
        // RunUnit::findByRunUnitId(...)->displayForRun(); confirm it loads
        // as a valid Survey unit carrying its surveyStudy.
        $loaded = RunUnit::findByRunUnitId($artefacts['run_unit_id'], ['run_unit_id' => $artefacts['run_unit_id']]);
        r_assert($loaded instanceof Survey && $loaded->valid, 'unit loads as a valid Survey');
        r_assert(
            $loaded && $loaded->surveyStudy && (int) $loaded->surveyStudy->id === (int) $study->id,
            'loaded unit carries surveyStudy',
            ($loaded && $loaded->surveyStudy) ? $loaded->surveyStudy->id : 'none'
        );
    }

} catch (Throwable $e) {
    echo "  \e[31mEXCEPTION\e[0m " . $e->getMessage() . "\n";
    $failures++;
} finally {
    r_teardown($db, $artefacts);
}

echo $failures === 0 ? "\n\e[32mAll randomiser smoke checks passed.\e[0m\n" : "\n\e[31m{$failures} check(s) failed.\e[0m\n";
exit($failures === 0 ? 0 : 1);
