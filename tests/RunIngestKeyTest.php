<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure (no-DB) parts of RunIngestKey and the shared
 * write validation. The DB-backed parts (generate/resolve/revoke against
 * survey_run_ingest_keys, and the /api/ingest endpoint) are exercised by
 * bin/test_ingest_key_smoke.php against live MariaDB — the SQLite test
 * bootstrap can't host the JSON / merge surface they depend on.
 */
class RunIngestKeyTest extends TestCase
{
    public function testKeyPrefixIsIdentifiableConstant()
    {
        $this->assertSame('fri_', RunIngestKey::KEY_PREFIX);
        $this->assertGreaterThanOrEqual(8, RunIngestKey::PREFIX_DISPLAY_LEN);
    }

    public function testResolveRejectsEmptyAndNonStringWithoutDb()
    {
        // Short-circuits before any DB access for empty / non-string input.
        $this->assertNull(RunIngestKey::resolve(''));
        $this->assertNull(RunIngestKey::resolve(null));
        $this->assertNull(RunIngestKey::resolve(123));
    }

    public function testRefAndDataValidation()
    {
        // Valid: object payload, sane ref.
        $this->assertNull(ExternalData::validateRefAndData('p1', array('score_a' => 1)));
        $this->assertNull(ExternalData::validateRefAndData('p1', array())); // empty object ok (no-op)

        // Bad ref.
        $this->assertNotNull(ExternalData::validateRefAndData('', array('a' => 1)));
        $this->assertNotNull(ExternalData::validateRefAndData('   ', array('a' => 1)));
        $this->assertNotNull(ExternalData::validateRefAndData(str_repeat('x', 192), array('a' => 1)));
        $this->assertNotNull(ExternalData::validateRefAndData(123, array('a' => 1)));

        // data must be a JSON object, not a list or scalar.
        $this->assertNotNull(ExternalData::validateRefAndData('p1', array(1, 2, 3)));
        $this->assertNotNull(ExternalData::validateRefAndData('p1', 'nope'));
        $this->assertNotNull(ExternalData::validateRefAndData('p1', null));
    }
}
