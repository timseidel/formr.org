#!/usr/bin/php
<?php
/**
 * External key-value storage smoke check (live MariaDB).
 *
 * Exercises ExternalData::mergePayload / getForRun against a real
 * MariaDB: atomic create-on-write, partial JSON_MERGE_PATCH merge
 * (untouched keys survive), RFC 7386 null-key deletion, source
 * filtering, and PHP-side key projection. PHPUnit can't host this — the
 * SQLite :memory: test bootstrap lacks JSON_MERGE_PATCH and
 * ON DUPLICATE KEY UPDATE … VALUES() (same reason as the Track A
 * smokes).
 *
 * Usage:
 *   docker exec formr_app php /var/www/formr/bin/test_external_data_smoke.php
 */
require_once dirname(__FILE__) . '/../setup.php';

$db = DB::getInstance();
$failures = 0;
$run_id = null;

function assert_eq($actual, $expected, string $label): void {
    global $failures;
    if ($actual === $expected) {
        echo "  \e[32mOK\e[0m  {$label}: " . var_export($actual, true) . "\n";
    } else {
        echo "  \e[31mFAIL\e[0m {$label}: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $failures++;
    }
}

function teardown(DB $db, $run_id): void {
    if ($run_id !== null) {
        // survey_external_data cascades on the run FK, but be explicit.
        try { $db->exec('DELETE FROM survey_external_data WHERE run_id = :rid', ['rid' => $run_id]); } catch (Throwable $e) {}
        try { $db->exec('DELETE FROM survey_runs WHERE id = :rid', ['rid' => $run_id]); } catch (Throwable $e) {}
    }
}

try {
    echo "== External KV storage smoke ==\n";
    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) {
        fwrite(STDERR, "No survey_users row.\n");
        exit(2);
    }

    $run_id = (int) $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'],
        'name' => 'ext_kv_smoke_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(), 'modified' => mysql_now(),
        'cron_active' => 0,
    ]);

    $src = 'scoring_engine';
    $ref = 'participant-' . bin2hex(random_bytes(4));

    // 1. Create-on-write.
    $merged = ExternalData::mergePayload($run_id, $src, $ref, ['score_a' => 1]);
    assert_eq($merged, ['score_a' => 1], 'create-on-write payload');

    // 2. Partial merge — score_a must survive an update that only sends score_b.
    $merged = ExternalData::mergePayload($run_id, $src, $ref, ['score_b' => 2]);
    assert_eq($merged, ['score_a' => 1, 'score_b' => 2], 'partial merge keeps prior key');

    // 3. Overwrite one key, leave the other.
    $merged = ExternalData::mergePayload($run_id, $src, $ref, ['score_a' => 9]);
    assert_eq($merged, ['score_a' => 9, 'score_b' => 2], 'overwrite single key');

    // 4. RFC 7386 — a null value deletes the key.
    $merged = ExternalData::mergePayload($run_id, $src, $ref, ['score_a' => null]);
    assert_eq($merged, ['score_b' => 2], 'null deletes key (RFC 7386)');

    // 5. Distinct ref under the same source is isolated.
    $ref2 = 'participant-' . bin2hex(random_bytes(4));
    ExternalData::mergePayload($run_id, $src, $ref2, ['other' => true]);
    $rows = ExternalData::getForRun($run_id, $src, $ref2);
    assert_eq(count($rows), 1, 'getForRun ref filter returns one row');
    assert_eq($rows[0]['payload'], ['other' => true], 'second ref payload isolated');

    // 6. Source listing returns both refs.
    $all = ExternalData::getForRun($run_id, $src);
    assert_eq(count($all), 2, 'getForRun source filter returns both refs');

    // 7. PHP-side key projection.
    ExternalData::mergePayload($run_id, $src, $ref, ['score_b' => 2, 'extra' => 'x']);
    $proj = ExternalData::getForRun($run_id, $src, $ref, ['score_b']);
    assert_eq($proj[0]['payload'], ['score_b' => 2], 'key projection drops unrequested keys');

    // 8. updated_at is populated.
    assert_eq(!empty($proj[0]['updated_at']), true, 'updated_at present');

    // 9. Empty patch is a no-op (must not wipe the document to []).
    $merged = ExternalData::mergePayload($run_id, $src, $ref, []);
    assert_eq($merged, ['score_b' => 2, 'extra' => 'x'], 'empty patch leaves payload intact');

    echo $failures === 0 ? "\n\e[32mAll external KV smoke checks passed.\e[0m\n"
                         : "\n\e[31m{$failures} check(s) failed.\e[0m\n";
} catch (Throwable $e) {
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failures++;
} finally {
    teardown($db, $run_id);
}

exit($failures === 0 ? 0 : 1);
