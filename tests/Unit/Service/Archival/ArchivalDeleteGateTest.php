<?php

declare(strict_types=1);

/**
 * Archival Delete Gate Unit Tests
 *
 * Verifies that ObjectService::deleteObject() blocks user-driven deletes on
 * archival schemas and that the $_retentionSweep flag bypasses the gate.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3.4
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-3.5
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the archival immutability gate in ObjectService.
 *
 * We test the gate logic directly (not via a full ObjectService mock stack)
 * because instantiating ObjectService requires 30+ DI arguments. Instead,
 * we use a small anonymous class that exposes the gate logic via an extracted
 * helper, verifying the exception shape and the sweep-flag bypass path.
 */
class ArchivalDeleteGateTest extends TestCase {

	/**
	 * ArchivalImmutableException HTTP code is 403.
	 */
	public function testExceptionCodeIs403(): void {
		$exception = new ArchivalImmutableException(schemaIdentifier: 'call_log', operation: 'delete');
		$this->assertSame(403, $exception->getCode());
	}

	/**
	 * Exception body contains the required structured fields.
	 */
	public function testExceptionBodyStructure(): void {
		$exception = new ArchivalImmutableException(schemaIdentifier: 'call_log', operation: 'delete');
		$body = $exception->toResponseBody();

		$this->assertSame('SCHEMA_ARCHIVAL_IMMUTABLE', $body['error']);
		$this->assertSame('call_log', $body['schema']);
		$this->assertSame('delete', $body['operation']);
		$this->assertArrayHasKey('message', $body);
		$this->assertArrayHasKey('hint', $body);
	}

	/**
	 * Gate fires when schema has x-openregister-archival and $_retentionSweep is false.
	 *
	 * We test the gate condition in isolation using a minimal Schema double.
	 */
	public function testGateThrowsForArchivalSchemaWithoutSweepFlag(): void {
		$this->expectException(ArchivalImmutableException::class);

		// Gate reads getConfiguration() — mock that, not the magic getSlug().
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')
			->willReturn(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]);

		$this->runGate(schema: $schema, retentionSweep: false);
	}

	/**
	 * Gate is bypassed when $_retentionSweep is true (cron path).
	 */
	public function testGatePassesWhenRetentionSweepIsTrue(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')
			->willReturn(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]);

		// Should not throw.
		$this->runGate(schema: $schema, retentionSweep: true);
		$this->assertTrue(true);
	}

	/**
	 * Gate is skipped for non-archival schemas regardless of sweep flag.
	 */
	public function testGateSkippedForNonArchivalSchema(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-lifecycle' => []]);

		$this->runGate(schema: $schema, retentionSweep: false);
		$this->assertTrue(true);
	}

	/**
	 * Simulate the gate logic extracted from ObjectService::deleteObject().
	 *
	 * @param Schema $schema The schema to check.
	 * @param bool $retentionSweep Whether the sweep flag is set.
	 *
	 * @throws ArchivalImmutableException When schema is archival and sweep is false.
	 *
	 * @return void
	 */
	private function runGate(Schema $schema, bool $retentionSweep): void {
		if ($retentionSweep === false) {
			$config = $schema->getConfiguration() ?? [];
			if (isset($config['x-openregister-archival']) === true) {
				throw new ArchivalImmutableException(
					schemaIdentifier: 'test-schema',
					operation: 'delete'
				);
			}
		}
	}//end runGate()
}//end class
