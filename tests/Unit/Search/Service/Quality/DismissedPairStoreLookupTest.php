<?php

/**
 * Characterisation tests for DismissedPairStore's lookups.
 *
 * Pins the query activeFor() sends (scope filters, `active`, the dismissal cap),
 * how it keys the rows it gets back (canonical pair key, the object's uuid as
 * `id`, rows without both uuids and non-entities skipped) and that a failed
 * lookup is logged and answers no dismissals. Also pins the row lookup that
 * dismiss() and restore use: the canonical pair in the filters and limit 1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Quality;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions and entity setters.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Quality\DismissedPairStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class DismissedPairStoreLookupTest extends TestCase {

	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}

	public function testActiveForQueriesTheScopeAndKeysRowsByCanonicalPair(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('findAll')
			->with(
				[
					'filters' => [
						'register' => 'dismissed-pair',
						'schema' => 'dismissedPair',
						'registerSlug' => 'personen',
						'schemaSlug' => 'persoon',
						'active' => true,
					],
					'limit' => 5000,
				]
			)
			->willReturn(
				[
					self::entity(uuid: 'd-1', data: ['objectA' => 'zz', 'objectB' => 'aa', 'reason' => 'x']),
					'not an entity',
					self::entity(uuid: 'd-2', data: ['objectA' => 'bb', 'objectB' => '']),
					self::entity(uuid: 'd-3', data: ['objectB' => 'cc']),
					self::entity(uuid: 'd-4', data: ['objectA' => 'cc', 'objectB' => 'dd', 'id' => 'stale']),
				]
			);

		$store = new DismissedPairStore($objects, $this->createMock(LoggerInterface::class));

		$this->assertSame(
			[
				'aa|zz' => ['id' => 'd-1', 'objectA' => 'zz', 'objectB' => 'aa', 'reason' => 'x'],
				'cc|dd' => ['id' => 'd-4', 'objectA' => 'cc', 'objectB' => 'dd'],
			],
			$store->activeFor(registerSlug: 'personen', schemaSlug: 'persoon')
		);
	}

	public function testALaterRowForTheSamePairWins(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willReturn(
			[
				self::entity(uuid: 'd-1', data: ['objectA' => 'a', 'objectB' => 'b']),
				self::entity(uuid: 'd-2', data: ['objectA' => 'b', 'objectB' => 'a']),
			]
		);

		$store = new DismissedPairStore($objects, $this->createMock(LoggerInterface::class));

		$this->assertSame(['a|b' => ['id' => 'd-2', 'objectA' => 'b', 'objectB' => 'a']], $store->activeFor(registerSlug: 'r', schemaSlug: 's'));
	}

	public function testAFailedLookupIsLoggedAndAnswersNoDismissals(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[DismissedPairStore] dismissal lookup failed: db down');

		$store = new DismissedPairStore($objects, $logger);

		$this->assertSame([], $store->activeFor(registerSlug: 'r', schemaSlug: 's'));
	}

	public function testTheRowLookupAsksForTheCanonicalPairOnce(): void {
		$row = self::entity(uuid: 'd-9', data: []);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('findAll')
			->with(
				[
					'filters' => [
						'register' => 'dismissed-pair',
						'schema' => 'dismissedPair',
						'registerSlug' => 'personen',
						'schemaSlug' => 'persoon',
						'objectA' => 'aa',
						'objectB' => 'zz',
					],
					'limit' => 1,
				]
			)
			->willReturn(['skip me', $row]);

		$store = new DismissedPairStore($objects, $this->createMock(LoggerInterface::class));
		$pair = new \OCA\OpenRegister\Search\Service\Quality\ReviewedPair(first: 'zz', second: 'aa', registerSlug: 'personen', schemaSlug: 'persoon');

		$this->assertSame($row, (new ReflectionMethod(DismissedPairStore::class, 'findRow'))->invoke($store, $pair));
	}

	public function testAFailedRowLookupIsLoggedAndFindsNothing(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[DismissedPairStore] dismissal row lookup failed: db down');

		$store = new DismissedPairStore($objects, $logger);
		$pair = new \OCA\OpenRegister\Search\Service\Quality\ReviewedPair(first: 'a', second: 'b', registerSlug: 'r', schemaSlug: 's');

		$this->assertNull((new ReflectionMethod(DismissedPairStore::class, 'findRow'))->invoke($store, $pair));
	}
}
