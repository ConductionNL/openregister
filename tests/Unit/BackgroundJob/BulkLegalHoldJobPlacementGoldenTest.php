<?php

declare(strict_types=1);

/**
 * Golden of BulkLegalHoldJob placing holds: every object of the schema is
 * held with the given reason and saved, in order, and only a run that held
 * something reports a count.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\BulkLegalHoldJob;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Pins which objects are held, with what reason, and what is logged.
 */
class BulkLegalHoldJobPlacementGoldenTest extends TestCase {

	/**
	 * Everything observable, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	public function testEveryObjectOfTheSchemaIsHeldAndSaved(): void {
		$this->runJob(uuids: ['a', 'b']);

		$this->assertSame(
			[
				'info [BulkLegalHoldJob] Placing legal holds on schema: 42',
				'find schema 42',
				'hold a audit',
				'update a',
				'hold b audit',
				'update b',
				'info [BulkLegalHoldJob] Placed legal holds on 2 objects',
			],
			$this->trail
		);
	}//end testEveryObjectOfTheSchemaIsHeldAndSaved()

	public function testAnEmptySchemaReportsNoCount(): void {
		$this->runJob(uuids: []);

		$this->assertSame(
			['info [BulkLegalHoldJob] Placing legal holds on schema: 42', 'find schema 42'],
			$this->trail
		);
	}//end testAnEmptySchemaReportsNoCount()

	/**
	 * Run the job for schema 42 with reason "audit" over the given objects.
	 *
	 * @param array<int, string> $uuids The schema's objects.
	 *
	 * @return void
	 */
	private function runJob(array $uuids): void {
		$this->trail = [];
		$objects     = [];
		foreach ($uuids as $uuid) {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$objects[] = $object;
		}

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('findBySchema')->willReturnCallback(function (int $schemaId) use ($objects): array {
			$this->trail[] = 'find schema ' . $schemaId;
			return $objects;
		});
		$mapper->method('update')->willReturnCallback(function (ObjectEntity $object): ObjectEntity {
			$this->trail[] = 'update ' . $object->getUuid();
			return $object;
		});

		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('placeHold')->willReturnCallback(function (ObjectEntity $object, $order) {
			$this->trail[] = 'hold ' . $object->getUuid() . ' ' . $order->reason;
			return $object;
		});

		$trail  = &$this->trail;
		$logger = new class($trail) extends AbstractLogger {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->trail[] = $level . ' ' . $message;
			}
		};

		$services  = [
			LoggerInterface::class => $logger,
			RetentionServiceInterface::class => $retention,
			MagicMapperInterface::class => $mapper,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		$job    = new BulkLegalHoldJob($this->createMock(ITimeFactory::class), $container);
		$method = new \ReflectionMethod(BulkLegalHoldJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, ['schemaId' => '42', 'reason' => 'audit']);
	}//end runJob()
}//end class
