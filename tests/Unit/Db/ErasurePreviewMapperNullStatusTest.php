<?php

/**
 * ErasurePreviewMapper::createFromArray() never persists a preview without a status:
 * an explicit null status is stored as pending.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\ErasurePreviewMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of createFromArray() for a status given as null.
 */
class ErasurePreviewMapperNullStatusTest extends TestCase {

	/**
	 * Values bound per column on insert.
	 *
	 * @var array<string, mixed>
	 */
	private array $inserted = [];

	private function mapper(): ErasurePreviewMapper {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): array => ['bound' => $value]);
		$qb->method('setValue')->willReturnCallback(
			function (string $column, $parameter) use ($qb): IQueryBuilder {
				$this->inserted[$column] = $parameter['bound'];
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(42);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new ErasurePreviewMapper($db);
	}

	public function testAnExplicitNullStatusIsPersistedAsPending(): void {
		$preview = $this->mapper()->createFromArray(['uuid' => 'p-1', 'status' => null, 'subject' => 'alice']);

		$this->assertSame(ErasurePreview::STATUS_PENDING, $preview->getStatus());
		$this->assertSame(ErasurePreview::STATUS_PENDING, $this->inserted['status']);
		$this->assertSame('p-1', $this->inserted['uuid']);
		$this->assertSame(42, $preview->getId());
	}
}
