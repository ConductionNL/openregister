<?php

/**
 * Tests for the object AuditHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Object\AuditHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which filters reach the audit trail mapper, and what happens when it fails.
 *
 * @covers \OCA\OpenRegister\Service\Object\AuditHandler
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
class AuditHandlerTest extends TestCase {

	/**
	 * Without filters the query is bound to the object and ordered newest first.
	 *
	 * @return void
	 */
	public function testDefaultsBindTheObjectAndOrderNewestFirst(): void {
		$entry  = new AuditTrail();
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('findTrail')
			->with(ListQuery::unbounded(['object_uuid' => 'u-1', 'order_by' => 'created_at', 'order' => 'DESC'], ['created' => 'DESC']))
			->willReturn([$entry]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('[AuditHandler] Logs retrieved successfully', $this->callback(static fn (array $c): bool => $c['log_count'] === 1));
		$logger->expects($this->never())->method('error');

		$handler = new AuditHandler(auditTrailMapper: $mapper, logger: $logger);

		$this->assertSame([$entry], $handler->getLogs(uuid: 'u-1'));
	}//end testDefaultsBindTheObjectAndOrderNewestFirst()

	/**
	 * Non-empty filters pass through, empty ones are dropped, unknown ones never reach
	 * the mapper, and a caller cannot replace the object binding.
	 *
	 * @return void
	 */
	public function testOnlyKnownNonEmptyFiltersPassThrough(): void {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('findTrail')
			->with(ListQuery::unbounded([
					'object_uuid' => 'u-1',
					'action'      => 'update',
					'user'        => 'alice',
					'date_from'   => '2026-01-01',
					'date_to'     => '2026-02-01',
					'order_by'    => 'action',
					'order'       => 'ASC',
				], ['created' => 'DESC']))
			->willReturn([]);
		$handler = new AuditHandler(auditTrailMapper: $mapper, logger: $this->createMock(LoggerInterface::class));

		$result = $handler->getLogs(
			uuid: 'u-1',
			filters: [
				'action'      => 'update',
				'user'        => 'alice',
				'date_from'   => '2026-01-01',
				'date_to'     => '2026-02-01',
				'order_by'    => 'action',
				'order'       => 'ASC',
				'object_uuid' => 'someone-else',
				'register'    => 7,
			]
		);

		$this->assertSame([], $result);
	}//end testOnlyKnownNonEmptyFiltersPassThrough()

	/**
	 * Empty filter values are not forwarded.
	 *
	 * @return void
	 */
	public function testEmptyFilterValuesAreDropped(): void {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->once())->method('findTrail')
			->with(ListQuery::unbounded(['object_uuid' => 'u-2', 'order_by' => 'created_at', 'order' => 'DESC'], ['created' => 'DESC']))
			->willReturn([]);
		$handler = new AuditHandler(auditTrailMapper: $mapper, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $handler->getLogs(uuid: 'u-2', filters: ['action' => '', 'user' => null, 'date_from' => 0, 'date_to' => []]));
	}//end testEmptyFilterValuesAreDropped()

	/**
	 * A mapper failure is logged and rethrown unchanged.
	 *
	 * @return void
	 */
	public function testMapperFailureIsLoggedAndRethrown(): void {
		$failure = new RuntimeException('db down');
		$mapper  = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('findTrail')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[AuditHandler] Failed to get logs', $this->callback(static fn (array $c): bool => $c['uuid'] === 'u-3' && $c['error'] === 'db down'));
		$logger->expects($this->never())->method('info');

		$handler = new AuditHandler(auditTrailMapper: $mapper, logger: $logger);

		try {
			$handler->getLogs(uuid: 'u-3');
			$this->fail('The mapper failure must propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
	}//end testMapperFailureIsLoggedAndRethrown()
}//end class
