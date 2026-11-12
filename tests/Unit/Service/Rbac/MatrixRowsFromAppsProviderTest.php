<?php

/**
 * Provider-resolution tests for MatrixRowsFromApps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\MatrixRowsRequestedEvent;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A matrix without a provider is untouched, no dispatcher means no rows, a provider
 * without a dot is owned by the app of that name, and answers are cached per schema.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps
 * @uses \OCA\OpenRegister\Event\MatrixRowsRequestedEvent
 * @uses \OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator
 */
class MatrixRowsFromAppsProviderTest extends TestCase {

	/**
	 * A schema keyed on its assigned group.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private function schema(int $id=71): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties(['assignedGroup' => ['type' => 'string']]);
		return $schema;
	}//end schema()

	/**
	 * A matrix without a provider, or with a blank one, is returned unchanged and nobody is asked.
	 *
	 * @return void
	 */
	public function testMatrixWithoutProviderIsUntouched(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		$apps = new MatrixRowsFromApps(dispatcher: $dispatcher, validator: new DepartmentMatrixValidator(), logger: $this->createMock(LoggerInterface::class));

		$plain = ['field' => 'assignedGroup', 'rows' => [['value' => 'a']]];
		$this->assertSame($plain, $apps->withAppRows(schema: $this->schema(), matrix: $plain));
		$blank = ['field' => 'assignedGroup', 'rowsFrom' => '  '];
		$this->assertSame($blank, $apps->withAppRows(schema: $this->schema(), matrix: $blank));
	}//end testMatrixWithoutProviderIsUntouched()

	/**
	 * Without a dispatcher the provider contributes nothing, and malformed declared rows become a list.
	 *
	 * @return void
	 */
	public function testNoDispatcherContributesNothing(): void {
		$apps = new MatrixRowsFromApps(dispatcher: null, validator: new DepartmentMatrixValidator(), logger: $this->createMock(LoggerInterface::class));

		$result = $apps->withAppRows(schema: $this->schema(), matrix: ['field' => 'assignedGroup', 'rowsFrom' => 'dossiq', 'rows' => 'bad']);

		$this->assertSame([], $result['rows']);
	}//end testNoDispatcherContributesNothing()

	/**
	 * A dotless provider is owned by the app of that name; declared rows come first; the
	 * answer is asked once per schema and provider.
	 *
	 * @return void
	 */
	public function testDotlessProviderAndCaching(): void {
		$asked      = 0;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$asked): void {
				if ($event instanceof MatrixRowsRequestedEvent) {
					$asked++;
					$event->addRows(rows: [['value' => 'team-a', 'group' => 'inspectors', 'actions' => ['read']]], appId: 'dossiq');
				}
			}
		);
		$apps   = new MatrixRowsFromApps(dispatcher: $dispatcher, validator: new DepartmentMatrixValidator(), logger: $this->createMock(LoggerInterface::class));
		$matrix = ['field' => 'assignedGroup', 'rowsFrom' => ' dossiq ', 'rows' => ['k' => ['value' => 'own', 'group' => 'staff', 'actions' => ['read']]]];

		$first  = $apps->withAppRows(schema: $this->schema(), matrix: $matrix);
		$second = $apps->withAppRows(schema: $this->schema(), matrix: $matrix);
		$apps->withAppRows(schema: $this->schema(id: 72), matrix: $matrix);

		$this->assertSame(['own', 'team-a'], array_column($first['rows'], 'value'));
		$this->assertSame($first, $second);
		$this->assertSame(2, $asked, 'Asked once for schema 71 and once for schema 72.');
	}//end testDotlessProviderAndCaching()
}//end class
