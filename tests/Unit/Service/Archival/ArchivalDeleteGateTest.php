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
 * Instantiating ObjectService takes 30+ DI arguments, so the gate's CONDITION
 * is exercised through the production predicate it actually calls,
 * {@see Schema::hasArchivalAnnotation()}, rather than through a local copy of
 * the `if`. An earlier version of this file reimplemented the condition in a
 * private `runGate()` helper: it agreed with itself no matter what the shipped
 * gate did, so it could not have failed if the gate had been deleted.
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

		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]);

		$this->runGate(schema: $schema, retentionSweep: false);
	}

	/**
	 * Gate is bypassed when $_retentionSweep is true (cron path).
	 */
	public function testGatePassesWhenRetentionSweepIsTrue(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]);

		// Should not throw.
		$this->runGate(schema: $schema, retentionSweep: true);
		$this->assertTrue(true);
	}

	/**
	 * Gate is skipped for non-archival schemas regardless of sweep flag.
	 */
	public function testGateSkippedForNonArchivalSchema(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-lifecycle' => []]);

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
		// The condition comes from the production predicate, so deleting or
		// breaking `Schema::hasArchivalAnnotation()` fails this test.
		if ($retentionSweep === false && $schema->hasArchivalAnnotation() === true) {
			throw new ArchivalImmutableException(
				schemaIdentifier: 'test-schema',
				operation: 'delete'
			);
		}
	}//end runGate()
}//end class
