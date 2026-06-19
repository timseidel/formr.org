#!/usr/bin/php
<?php
/**
 * Run ingestion key smoke check (live MariaDB).
 *
 * Exercises RunIngestKey against a real database: generate (hash stored,
 * plaintext returned once), resolve (by hash, pinned source returned),
 * revoke (resolve then fails), run-scoped revoke isolation, and that the
 * stored value is a hash and not the plaintext. PHPUnit can't host this
 * (SQLite test bootstrap), same as the external-data smoke.
 *
 * Usage:
 *   docker exec formr_app php /var/www/formr/bin/test_ingest_key_smoke.php
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
        try { $db->exec('DELETE FROM survey_run_ingest_keys WHERE run_id = :rid', ['rid' => $run_id]); } catch (Throwable $e) {}
        try { $db->exec('DELETE FROM survey_runs WHERE id = :rid', ['rid' => $run_id]); } catch (Throwable $e) {}
    }
}

try {
    echo "== Run ingestion key smoke ==\n";
    $owner = $db->execute('SELECT id FROM survey_users ORDER BY id LIMIT 1', [], false, true);
    if (!$owner) { fwrite(STDERR, "No survey_users row.\n"); exit(2); }

    $run_id = (int) $db->insert('survey_runs', [
        'user_id' => (int) $owner['id'],
        'name' => 'ingest_key_smoke_' . bin2hex(random_bytes(4)),
        'created' => mysql_now(), 'modified' => mysql_now(), 'cron_active' => 0,
    ]);

    // 1. Generate.
    $res = RunIngestKey::generate($run_id, 'Smoke key', 'scoring_engine');
    assert_eq(is_array($res) && !empty($res['key']), true, 'generate returns a key');
    assert_eq(strpos($res['key'], 'fri_') === 0, true, 'key carries fri_ prefix');

    // 2. Stored value is a hash, not the plaintext.
    $stored = $db->findRow('survey_run_ingest_keys', ['id' => $res['id']], 'key_hash, source_name, revoked, last_used_at');
    assert_eq($stored['key_hash'] === $res['key'], false, 'plaintext is NOT stored');
    assert_eq($stored['key_hash'] === hash('sha256', $res['key']), true, 'sha256 hash is stored');

    // 3. Resolve returns the row with pinned source.
    $row = RunIngestKey::resolve($res['key']);
    assert_eq(is_array($row), true, 'resolve finds the active key');
    assert_eq((int) $row['run_id'], $run_id, 'resolve binds to the right run');
    assert_eq($row['source_name'], 'scoring_engine', 'resolve returns pinned source');

    // 4. Bad / unknown key resolves to null.
    assert_eq(RunIngestKey::resolve('fri_definitely_not_a_real_key'), null, 'unknown key resolves null');

    // 5. Revoke scoped to a different run is a no-op.
    RunIngestKey::revoke($res['id'], $run_id + 999999);
    assert_eq(is_array(RunIngestKey::resolve($res['key'])), true, 'revoke under wrong run does nothing');

    // 6. Revoke under the right run, then resolve fails.
    RunIngestKey::revoke($res['id'], $run_id);
    assert_eq(RunIngestKey::resolve($res['key']), null, 'revoked key resolves null');

    // 7. listForRun still shows the revoked key (for the admin UI).
    $list = RunIngestKey::listForRun($run_id);
    assert_eq(count($list), 1, 'listForRun includes the revoked key');
    assert_eq((int) $list[0]['revoked'], 1, 'listed key marked revoked');

    echo $failures === 0 ? "\n\e[32mAll ingestion key smoke checks passed.\e[0m\n"
                         : "\n\e[31m{$failures} check(s) failed.\e[0m\n";
} catch (Throwable $e) {
    fwrite(STDERR, "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failures++;
} finally {
    teardown($db, $run_id);
}

exit($failures === 0 ? 0 : 1);
