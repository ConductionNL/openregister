<?php

/**
 * Golden test pinning which provider matrix rows are kept, and what a dropped row logs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\MatrixRowsRequestedEvent;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A row that reads is kept; a reserved-group row and a row that does not validate are dropped and logged.
 */
class MatrixRowsFromAppsDropGoldenTest extends TestCase {

	/**
	 * The rows kept from a provider answer, and the warnings logged on the way.
	 *
	 * @return void
	 */
	public function testOnlyRowsThatReadAreKept(): void {
		$good = ['value' => '$self', 'group' => 'intakers', 'actions' => ['read']];
		$rows = [
			$good,
			['value' => '$self', 'group' => 'Admin', 'actions' => ['read']],
			['value' => '$self', 'group' => 'intakers', 'actions' => 'read'],
		];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($rows): void {
				if ($event instanceof MatrixRowsRequestedEvent) {
					$event->addRows($rows, 'test');
				}
			}
		);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context['provider'] ?? null, isset($context['findings'])];
			}
		);

		$schema = new Schema();
		$schema->setId(5);
		$schema->setProperties(['assignedGroup' => ['type' => 'string']]);
		$matrix = (new MatrixRowsFromApps(dispatcher: $dispatcher, validator: new DepartmentMatrixValidator(), logger: $logger))
			->withAppRows(schema: $schema, matrix: ['field' => 'assignedGroup', 'rowsFrom' => 'test.provider', 'rows' => []]);

		$this->assertSame([$good], $matrix['rows']);
		$this->assertSame(
			[
				['[MatrixRowsFromApps] A provider row granting a reserved group was dropped', 'test.provider', false],
				['[MatrixRowsFromApps] A provider row does not read and was dropped', 'test.provider', true],
			],
			$warnings
		);
	}//end testOnlyRowsThatReadAreKept()
}//end class
