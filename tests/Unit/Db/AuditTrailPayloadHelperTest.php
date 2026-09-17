<?php

declare(strict_types=1);

/**
 * AuditTrailPayloadHelper Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailPayloadHelper;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Covers the audit-payload helpers extracted from AuditTrailMapper.
 */
class AuditTrailPayloadHelperTest extends TestCase {
	private AuditTrailPayloadHelper $helper;

	protected function setUp(): void {
		parent::setUp();
		$this->helper = new AuditTrailPayloadHelper();
	}

	public function testToDatabaseValueNullStaysNull(): void {
		$this->assertNull($this->helper->toDatabaseValue(null, 'json'));
	}

	public function testToDatabaseValueJsonEncodes(): void {
		$this->assertSame('{"a":1}', $this->helper->toDatabaseValue(['a' => 1], 'json'));
	}

	public function testToDatabaseValueFormatsDatetime(): void {
		$date = new \DateTimeImmutable('2026-01-02 03:04:05');
		$this->assertSame('2026-01-02 03:04:05', $this->helper->toDatabaseValue($date, 'datetime'));
	}

	public function testToDatabaseValueCastsBoolean(): void {
		$this->assertSame(1, $this->helper->toDatabaseValue(true, 'boolean'));
		$this->assertSame(0, $this->helper->toDatabaseValue(false, 'bool'));
	}

	public function testToDatabaseValuePassesScalarThrough(): void {
		$this->assertSame('plain', $this->helper->toDatabaseValue('plain', 'string'));
	}

	public function testIsSemanticVersion(): void {
		$this->assertTrue($this->helper->isSemanticVersion('1.2.3'));
		$this->assertFalse($this->helper->isSemanticVersion('1.2'));
		$this->assertFalse($this->helper->isSemanticVersion('nope'));
	}

	public function testCapChangedPayloadNullStaysNull(): void {
		$this->assertNull($this->helper->capChangedPayload(null));
	}

	public function testCapChangedPayloadLeavesSmallValuesUntouched(): void {
		$changed = ['title' => ['old' => 'a', 'new' => 'b']];
		$this->assertSame($changed, $this->helper->capChangedPayload($changed));
	}

	public function testCapChangedPayloadElidesOversizedValues(): void {
		$big = ['old' => str_repeat('x', 70000), 'new' => 'y'];
		$result = $this->helper->capChangedPayload(['blob' => $big]);
		$this->assertTrue($result['blob']['elided']);
		$this->assertGreaterThan(65536, $result['blob']['bytes']);
		$this->assertStringContainsString('ceiling', $result['blob']['reason']);
	}

	public function testRevertChangesSkipsWhenNoOldValue(): void {
		$audit = $this->createMock(AuditTrail::class);
		$audit->method('getChanged')->willReturn(['title' => ['old' => null, 'new' => 'x']]);
		$object = new ObjectEntity();

		// No 'old' value means nothing is reverted; the call must not throw.
		$this->helper->revertChanges($object, $audit);
		$this->addToAssertionCount(1);
	}

	public function testRevertChangesHandlesEmptyChangeSet(): void {
		$audit = $this->createMock(AuditTrail::class);
		$audit->method('getChanged')->willReturn([]);
		$object = new ObjectEntity();

		$this->helper->revertChanges($object, $audit);
		$this->addToAssertionCount(1);
	}
}
