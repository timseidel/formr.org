<?php
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ExternalData::isValidSource (pure, no DB).
 *
 * The DB-backed behaviour — atomic JSON_MERGE_PATCH create/merge,
 * null-key deletion (RFC 7386), and PHP-side key projection in
 * getForRun — is MariaDB-specific (JSON_MERGE_PATCH, ON DUPLICATE KEY
 * UPDATE … VALUES()) and the SQLite :memory: test bootstrap can't host
 * it, so it is exercised by bin/test_external_data_smoke.php against
 * live MariaDB instead (same split as the Track A smokes).
 */
class ExternalDataTest extends TestCase
{
    public function testValidSourceNames()
    {
        $this->assertTrue(ExternalData::isValidSource('scoring_engine'));
        $this->assertTrue(ExternalData::isValidSource('crm.webhook'));
        $this->assertTrue(ExternalData::isValidSource('tool-1'));
        $this->assertTrue(ExternalData::isValidSource('A'));
    }

    public function testInvalidSourceNames()
    {
        $this->assertFalse(ExternalData::isValidSource(''));
        $this->assertFalse(ExternalData::isValidSource('has space'));
        $this->assertFalse(ExternalData::isValidSource('drop;table'));
        $this->assertFalse(ExternalData::isValidSource('slash/here'));
        $this->assertFalse(ExternalData::isValidSource('umläut'));
        $this->assertFalse(ExternalData::isValidSource(str_repeat('a', 51))); // varchar(50) budget
        $this->assertFalse(ExternalData::isValidSource(null));
        $this->assertFalse(ExternalData::isValidSource(42));
    }

    public function testBatchLimitConstant()
    {
        $this->assertSame(100, ExternalData::BATCH_LIMIT);
    }

    public function testValidateBatchAcceptsValidEntries()
    {
        $this->assertNull(ExternalData::validateBatch(array(
            array('ref' => 'p1', 'data' => array('score_a' => 1)),
            array('ref' => 'p2', 'data' => array()),
        )));
    }

    public function testValidateBatchRejectsEmpty()
    {
        $this->assertNotNull(ExternalData::validateBatch(array()));
    }

    public function testValidateBatchRejectsOversized()
    {
        $entries = array();
        for ($i = 0; $i < 101; $i++) {
            $entries[] = array('ref' => "p{$i}", 'data' => array('x' => 1));
        }
        $err = ExternalData::validateBatch($entries);
        $this->assertNotNull($err);
        $this->assertStringContainsString('101', $err);
        $this->assertStringContainsString('100', $err);
    }

    public function testValidateBatchRejectsNonArrayEntries()
    {
        $err = ExternalData::validateBatch(array('not_an_array'));
        $this->assertNotNull($err);
        $this->assertStringContainsString('entries[0]', $err);
    }

    public function testValidateBatchReportsEntryIndex()
    {
        $err = ExternalData::validateBatch(array(
            array('ref' => 'good', 'data' => array('x' => 1)),
            array('ref' => '', 'data' => array('x' => 2)),
        ));
        $this->assertNotNull($err);
        $this->assertStringContainsString('entries[1]', $err);
    }

    public function testValidateBatchRejectsListData()
    {
        $err = ExternalData::validateBatch(array(
            array('ref' => 'p1', 'data' => array(1, 2, 3)),
        ));
        $this->assertNotNull($err);
        $this->assertStringContainsString('entries[0]', $err);
    }
}
