<?php

declare(strict_types=1);

/**
 * Bulk save: classification of database-computed results.
 *
 * `classifyDatabaseComputedResults()` is what turns the magic table's
 * `object_status` column into the saved/updated/unchanged buckets and the
 * statistics a caller reads to decide whether a batch succeeded. It had **no
 * tests at all**, which is a poor place for that gap: this programme has already
 * been bitten twice by a bulk save reporting success over objects it did not
 * write (openregister#2778, #2781), and every one of those reports is assembled
 * here.
 *
 * These tests drive it directly with rows carrying no `_register`/`_schema`, so
 * entity conversion short-circuits to null and the classification logic is
 * exercised on its own, without a mapper in the way.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for the bulk result classification.
 */
class SaveObjectsResultClassificationTest extends TestCase {
	/** @var SaveObjects */
	private SaveObjects $service;

	protected function setUp(): void {
		parent::setUp();

		$this->service = new SaveObjects(
			$this->createMock(MagicMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SaveObject::class),
			$this->createMock(IUserSession::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * An empty result skeleton in the shape the classifier mutates.
	 *
	 * @return array the skeleton
	 */
	private function emptyResult(): array {
		return [
			'saved' => [],
			'updated' => [],
			'unchanged' => [],
			'statistics' => ['saved' => 0, 'updated' => 0, 'unchanged' => 0],
		];
	}

	/**
	 * Invoke the private classifier.
	 *
	 * @param array $rows   the bulk rows
	 * @param array $result the result skeleton, mutated in place
	 *
	 * @return array the side effects returned by the classifier
	 */
	private function classify(array $rows, array &$result): array {
		$method = new ReflectionMethod(SaveObjects::class, 'classifyDatabaseComputedResults');
		$method->setAccessible(true);
		return $method->invokeArgs($this->service, [$rows, &$result]);
	}

	/**
	 * Each status lands in its own bucket, with statistics to match.
	 *
	 * @return void
	 */
	public function testEachStatusLandsInItsOwnBucket(): void {
		$result = $this->emptyResult();
		$this->classify(
			[
				['_uuid' => 'a', 'object_status' => 'created'],
				['_uuid' => 'b', 'object_status' => 'updated'],
				['_uuid' => 'c', 'object_status' => 'unchanged'],
				['_uuid' => 'd', 'object_status' => 'created'],
			],
			$result
		);

		$this->assertCount(2, $result['saved']);
		$this->assertCount(1, $result['updated']);
		$this->assertCount(1, $result['unchanged']);
		$this->assertSame(2, $result['statistics']['saved']);
		$this->assertSame(1, $result['statistics']['updated']);
		$this->assertSame(1, $result['statistics']['unchanged']);
	}

	/**
	 * Internal bookkeeping columns never reach the caller's payload.
	 *
	 * `object_status`, `operation_start_time` and `_pre_update_row` are how the
	 * SQL and this method talk to each other. Leaking them would put a private
	 * pre-update copy of the row — the audit diff source — into an API response.
	 *
	 * @return void
	 */
	public function testInternalBookkeepingColumnsAreStripped(): void {
		$result = $this->emptyResult();
		$this->classify(
			[
				[
					'_uuid' => 'a',
					'object_status' => 'created',
					'operation_start_time' => '2026-01-01 00:00:00',
					'_pre_update_row' => ['_uuid' => 'a', 'secret' => 'previous value'],
					'title' => 'kept',
				],
			],
			$result
		);

		$saved = $result['saved'][0];
		$this->assertSame('kept', $saved['title'] ?? null);
		$this->assertArrayNotHasKey('object_status', $saved);
		$this->assertArrayNotHasKey('operation_start_time', $saved);
		$this->assertArrayNotHasKey('_pre_update_row', $saved);
	}

	/**
	 * An unrecognised status is counted, not dropped.
	 *
	 * Silently discarding a row here would be the exact failure this programme
	 * keeps hitting: a batch that reports success while objects went missing.
	 * The row is bucketed as unchanged and warned about, so the totals still
	 * add up to what was submitted.
	 *
	 * @return void
	 */
	public function testAnUnexpectedStatusIsCountedRatherThanDropped(): void {
		$result = $this->emptyResult();
		$this->classify(
			[
				['_uuid' => 'a', 'object_status' => 'exploded'],
				['_uuid' => 'b'],
			],
			$result
		);

		$total = count($result['saved']) + count($result['updated']) + count($result['unchanged']);
		$this->assertSame(2, $total, 'every submitted row must appear in some bucket');
		$this->assertSame(2, $result['statistics']['unchanged']);
	}

	/**
	 * With no resolvable entity there are no side effects to emit.
	 *
	 * @return void
	 */
	public function testNoSideEffectsWithoutResolvableEntities(): void {
		$result = $this->emptyResult();
		$sideEffects = $this->classify(
			[
				['_uuid' => 'a', 'object_status' => 'created'],
				['_uuid' => 'b', 'object_status' => 'updated'],
			],
			$result
		);

		$this->assertSame([], $sideEffects['created']);
		$this->assertSame([], $sideEffects['updated']);
	}

	/**
	 * An empty batch classifies to an empty result rather than failing.
	 *
	 * @return void
	 */
	public function testAnEmptyBatchIsHandled(): void {
		$result = $this->emptyResult();
		$sideEffects = $this->classify([], $result);

		$this->assertSame([], $result['saved']);
		$this->assertSame(0, $result['statistics']['saved']);
		$this->assertSame(['created' => [], 'updated' => []], $sideEffects);
	}

	/**
	 * A row with no register/schema converts to no entity instead of throwing.
	 *
	 * @return void
	 */
	public function testARowWithoutRegisterOrSchemaYieldsNoEntity(): void {
		$method = new ReflectionMethod(SaveObjects::class, 'convertBulkRowToEntity');
		$method->setAccessible(true);

		$this->assertNull($method->invoke($this->service, ['_uuid' => 'a']));
		$this->assertNull($method->invoke($this->service, ['_uuid' => 'a', '_register' => 1]));
		$this->assertNull($method->invoke($this->service, ['_uuid' => 'a', '_schema' => 2]));
	}

	/**
	 * The safeguard group key pairs a register with a schema.
	 *
	 * One line, previously untested, and it is the key bulk safeguards are
	 * grouped by — a wrong pairing would apply one schema's safeguard to
	 * another's rows.
	 *
	 * @return void
	 */
	public function testSafeguardGroupKeyPairsRegisterAndSchema(): void {
		$register = new \OCA\OpenRegister\Db\Register();
		$register->setId(19);
		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(9475);

		$method = new ReflectionMethod(SaveObjects::class, 'safeguardGroupKey');
		$method->setAccessible(true);

		$this->assertSame('19/9475', $method->invoke($this->service, $register, $schema));
	}
}
