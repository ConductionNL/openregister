<?php

/**
 * ExpiredObjectSweeper: what the sweep does with each expired object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Retention;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStoreInterface;
use OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-EXP-002 / REQ-EXP-003: delete or anonymise by the object's action,
 * skip legal holds, audit each action, log each schema, respect the batch,
 * and change nothing in a dry run.
 *
 * @covers \OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper
 * @uses   \OCA\OpenRegister\Service\Retention\ExpiryDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class ExpiredObjectSweeperTest extends TestCase {

	/**
	 * What the fake store was asked to do.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $writes = [];

	/**
	 * A submission object.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $method Its retention method.
	 *
	 * @return ObjectEntity
	 */
	private static function submission(string $uuid, string $method): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setExpires(new DateTime('2026-11-12T00:00:00+00:00'));
		$object->setObject(['reference' => 'PT-'.$uuid, 'answers' => ['name' => 'Jan'], 'retentionMethod' => $method]);
		return $object;
	}//end submission()

	/**
	 * The opted-in submission schema.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('portalIntakeSubmission');
		$schema->setProperties(['reference' => [], 'answers' => [], 'retentionMethod' => []]);
		$schema->setConfiguration(['x-openregister' => ['expiry' => ['action' => 'anonymise', 'keep' => ['reference'], 'actionField' => 'retentionMethod']]]);
		return $schema;
	}//end schema()

	/**
	 * A sweeper over a fake store holding $objects (of which $total are expired).
	 *
	 * @param array<int, ObjectEntity> $objects The batch the store returns.
	 * @param array<int, string>       $held    The uuids under a legal hold.
	 * @param array{0?: int, 1?: bool}  $config  Batch size and enabled flag.
	 * @param LoggerInterface|null     $logger  The logger.
	 * @param int|null                 $total   How many are expired in all.
	 *
	 * @return ExpiredObjectSweeper
	 */
	private function sweeper(array $objects, array $held=[], array $config=[], ?LoggerInterface $logger=null, ?int $total=null): ExpiredObjectSweeper {
		$register = new Register();
		$register->setSlug('portal');
		$table   = ['register' => $register, 'schema' => self::schema()];
		$writes  = &$this->writes;
		$store   = new class($table, $objects, $total ?? count($objects), $writes) implements ExpiredObjectStoreInterface {
			/**
			 * @param array                          $table   The opted-in table.
			 * @param array<int, ObjectEntity>       $objects The expired objects.
			 * @param int                            $total   The expired count.
			 * @param array<int, array<int, mixed>>  $writes  The write log.
			 */
			public function __construct(private array $table, private array $objects, private int $total, private array &$writes) {
			}

			public function optedInTables(): array {
				return [$this->table];
			}

			public function expired(Register $register, Schema $schema, DateTimeImmutable $now, int $limit): array {
				$this->writes[] = ['limit', $limit];
				return array_slice($this->objects, 0, $limit);
			}

			public function countExpired(Register $register, Schema $schema, DateTimeImmutable $now): int {
				return $this->total;
			}

			public function delete(Register $register, Schema $schema, ObjectEntity $object): bool {
				$this->writes[] = ['delete', $object->getUuid()];
				return $object->getUuid() !== 'undeletable';
			}

			public function anonymise(Register $register, Schema $schema, ObjectEntity $object, array $data): void {
				if ($object->getUuid() === 'broken') {
					throw new \RuntimeException('disk full');
				}

				$this->writes[] = ['anonymise', $object->getUuid(), $data];
			}

			public function audit(ObjectEntity $object, string $action, array $context): void {
				$this->writes[] = ['audit', $object->getUuid(), $action, $context];
			}
		};

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('hasActiveLegalHold')->willReturnCallback(static fn (ObjectEntity $o): bool => in_array($o->getUuid(), $held, true));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->with('openregister', 'expiry_sweep_batch', 500)->willReturn($config[0] ?? 500);
		$appConfig->method('getValueBool')->with('openregister', 'expiry_sweep_enabled', true)->willReturn($config[1] ?? true);

		return new ExpiredObjectSweeper(store: $store, retention: $retention, appConfig: $appConfig, logger: ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end sweeper()

	/**
	 * A draft marked delete is deleted, a failed one anonymised, a held one kept; each audited.
	 *
	 * @return void
	 */
	public function testEachObjectGetsItsAction(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'[ExpiredObjectSweep] portal/portalIntakeSubmission deleted=1 anonymised=1 held=1 failed=0 left=0',
			$this->arrayHasKey('deleted')
		);
		$sweeper = $this->sweeper(
			objects: [self::submission('draft', 'delete'), self::submission('failed', 'anonymise'), self::submission('held', 'delete')],
			held: ['held'],
			logger: $logger
		);

		$report = $sweeper->sweep(now: new DateTimeImmutable('2026-11-13T00:00:00+00:00'));

		$this->assertSame(
			[['register' => 'portal', 'schema' => 'portalIntakeSubmission', 'deleted' => 1, 'anonymised' => 1, 'held' => 1, 'failed' => 0, 'left' => 0]],
			$report
		);
		$this->assertSame(
			[
				['limit', 500],
				['delete', 'draft'],
				['audit', 'draft', 'expiry.deleted', ['expires' => '2026-11-12T00:00:00+00:00']],
				['anonymise', 'failed', ['id' => self::submission('failed', 'x')->getObject()['id'], 'reference' => 'PT-failed', 'answers' => [], 'retentionMethod' => null]],
				['audit', 'failed', 'expiry.anonymised', ['expires' => '2026-11-12T00:00:00+00:00', 'kept' => ['reference']]],
			],
			$this->writes
		);
	}//end testEachObjectGetsItsAction()

	/**
	 * A failed write is counted and logged, never audited, and the run goes on.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsCountedAndNotAudited(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('anonymise failed for object broken: disk full'));
		$sweeper = $this->sweeper(objects: [self::submission('broken', 'anonymise'), self::submission('undeletable', 'delete')], logger: $logger);

		$report = $sweeper->sweep(now: new DateTimeImmutable('2026-11-13'));

		$this->assertSame(2, $report[0]['failed']);
		$this->assertSame([['limit', 500], ['delete', 'undeletable']], $this->writes);
	}//end testAFailedWriteIsCountedAndNotAudited()

	/**
	 * The batch limits one run; the rest is reported as left.
	 *
	 * @return void
	 */
	public function testTheBatchLimitsARun(): void {
		$sweeper = $this->sweeper(objects: [self::submission('a', 'delete'), self::submission('b', 'delete'), self::submission('c', 'delete')], config: [2], total: 7);

		$report = $sweeper->sweep(now: new DateTimeImmutable('2026-11-13'));

		$this->assertSame([2, 5], [$report[0]['deleted'], $report[0]['left']]);
		$this->assertSame(['limit', 2], $this->writes[0]);
		$this->assertSame(2, $sweeper->batchSize());
		$this->assertSame(1, $this->sweeper(objects: [], config: [0])->batchSize());
	}//end testTheBatchLimitsARun()

	/**
	 * A dry run counts the same outcomes and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunChangesNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$sweeper = $this->sweeper(objects: [self::submission('draft', 'delete'), self::submission('failed', 'anonymise'), self::submission('held', 'x')], held: ['held'], logger: $logger);

		$report = $sweeper->sweep(now: new DateTimeImmutable('2026-11-13'), dryRun: true);

		$this->assertSame([1, 1, 1], [$report[0]['deleted'], $report[0]['anonymised'], $report[0]['held']]);
		$this->assertSame([['limit', 500]], $this->writes);
	}//end testADryRunChangesNothing()

	/**
	 * The switch reads the app config, default on.
	 *
	 * @return void
	 */
	public function testTheSwitch(): void {
		$this->assertTrue($this->sweeper(objects: [])->isEnabled());
		$this->assertFalse($this->sweeper(objects: [], config: [500, false])->isEnabled());
	}//end testTheSwitch()
}//end class
