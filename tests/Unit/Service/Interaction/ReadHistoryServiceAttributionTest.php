<?php

/**
 * Characterisation of how a listed row is attributed, and of the anonymous lens.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Interaction\ProcessingListRead;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\ProcessingLogService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One case per attribution branch of a listed row, plus the blank-uid lens.
 *
 * @covers \OCA\OpenRegister\Service\Interaction\ReadHistoryService
 */
class ReadHistoryServiceAttributionTest extends TestCase {

	/**
	 * Register one page and return the objects the processing log saw, and the warnings.
	 *
	 * @param array<int, mixed> $rows The listed rows.
	 *
	 * @return array{0: array<int, ObjectEntity>, 1: array<int, array<string, int>>}
	 */
	private function register(array $rows): array {
		$seen = [];
		$log  = $this->createMock(ProcessingLogService::class);
		$log->method('recordReadList')->willReturnCallback(
			static function (array $objects) use (&$seen): void {
				$seen = array_merge($seen, $objects);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($log);

		$warned = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warned): void {
				$warned[] = $context;
			}
		);

		$service = new ReadHistoryService(
			$this->createMock(AuditTrailMapperInterface::class),
			$this->createMock(SettingsServiceInterface::class),
			$container,
			$logger
		);
		$service->registerListRead($rows, new ProcessingListRead());

		return [$seen, $warned];
	}

	public function testAnIdStandsInForAMissingUuidAndANonScalarOrganisationIsDropped(): void {
		[$seen, $warned] = $this->register([['a' => 1, '@self' => ['id' => 7, 'register' => 1, 'schema' => 2, 'organisation' => ['x']]]]);

		$this->assertCount(1, $seen);
		$this->assertSame('7', $seen[0]->getUuid());
		$this->assertSame('1', $seen[0]->getRegister());
		$this->assertSame('2', $seen[0]->getSchema());
		$this->assertNull($seen[0]->getOrganisation());
		$this->assertSame(['a' => 1], $seen[0]->getObject());
		$this->assertSame([], $warned);
	}

	public function testAUuidWinsOverAnId(): void {
		[$seen] = $this->register([['@self' => ['uuid' => 'u-1', 'id' => 'i-1', 'register' => 'r', 'schema' => 's']]]);

		$this->assertSame('u-1', $seen[0]->getUuid());
	}

	public function testAnEntityIsTakenAsItIs(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('e-1');
		$entity->setRegister('r');
		$entity->setSchema('s');

		[$seen] = $this->register([$entity]);

		$this->assertSame([$entity], $seen);
	}

	public function testRowsWithoutAUsableSelfBlockAreNotAttributed(): void {
		[$seen, $warned] = $this->register(
			[
				['@self' => 'not a block', 'register' => 'r'],
				['@self' => ['uuid' => 'u', 'register' => ['r'], 'schema' => 's']],
				['@self' => ['uuid' => 'u', 'register' => 'r', 'schema' => null]],
				['@self' => ['register' => 'r', 'schema' => 's']],
				42,
			]
		);

		$this->assertSame([], $seen);
		$this->assertSame([['rows' => 5, 'of' => 5]], $warned);
	}

	public function testABlankUidIsAnAnonymousLens(): void {
		$trails = $this->createMock(AuditTrailMapperInterface::class);
		$trails->expects($this->never())->method('findLatestReadsByUser');
		$service = new ReadHistoryService(
			$trails,
			$this->createMock(SettingsServiceInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(['available' => false, 'reason' => 'anonymous', 'views' => []], $service->resolveRecentLens(userId: ''));
	}
}
