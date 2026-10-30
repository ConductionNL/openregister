<?php

/**
 * Provider rows are heard from the owning app only, and never grant a
 * reserved group.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\MatrixRowsRequestedEvent;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps
 * @covers \OCA\OpenRegister\Event\MatrixRowsRequestedEvent
 */
class MatrixRowsFromAppsTest extends TestCase {

	private const MATRIX = ['field' => 'assignedGroup', 'rowsFrom' => 'dossiq.case-type-rights'];

	/**
	 * A case schema keyed on its assigned group.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(71);
		$schema->setProperties(['assignedGroup' => ['type' => 'string']]);

		return $schema;
	}//end schema()

	/**
	 * The rows that reach the compiler when the listener answers as given.
	 *
	 * @param callable             $listener The listener.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return array<int, array<string, mixed>> The matrix rows.
	 */
	private function rows(callable $listener, ?LoggerInterface $logger = null): array {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($listener): void {
				if ($event instanceof MatrixRowsRequestedEvent) {
					$listener($event);
				}
			}
		);

		$apps = new MatrixRowsFromApps(
			$dispatcher,
			new DepartmentMatrixValidator(),
			($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
		);

		return $apps->withAppRows(schema: $this->schema(), matrix: self::MATRIX)['rows'];
	}//end rows()

	/**
	 * The control: the owning app's row for an ordinary group is kept.
	 *
	 * @return void
	 */
	public function testTheOwningAppsRowIsKept(): void {
		$rows = $this->rows(
			listener: static fn (MatrixRowsRequestedEvent $event) => $event->addRows(
				rows: [['value' => 'team-a', 'group' => 'inspectors', 'actions' => ['read']]],
				appId: 'dossiq'
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $rows);
		$this->assertSame(expected: 'inspectors', actual: $rows[0]['group']);
	}//end testTheOwningAppsRowIsKept()

	/**
	 * A provider row granting `public` (or `authenticated`, `admin`) is dropped with a warning.
	 *
	 * @return void
	 */
	public function testAPublicRowIsDroppedWithAWarning(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$warnings = 0;
		$logger->method('warning')->willReturnCallback(
			static function () use (&$warnings): void {
				$warnings++;
			}
		);

		$rows = $this->rows(
			listener: static fn (MatrixRowsRequestedEvent $event) => $event->addRows(
				rows: [
					['value' => 'team-a', 'group' => 'public', 'actions' => ['read']],
					['value' => 'team-a', 'group' => 'Authenticated', 'actions' => ['read', 'readFiles']],
					['value' => 'team-a', 'group' => 'admin', 'actions' => ['read']],
				],
				appId: 'dossiq'
			),
			logger: $logger
		);

		$this->assertSame(expected: [], actual: $rows);
		$this->assertSame(expected: 3, actual: $warnings);
	}//end testAPublicRowIsDroppedWithAWarning()

	/**
	 * Another app answering the provider, or an app that names none, is not heard.
	 *
	 * @return void
	 */
	public function testRowsFromAnotherAppOrNoAppAreDropped(): void {
		$rows = $this->rows(
			listener: static function (MatrixRowsRequestedEvent $event): void {
				$event->addRows(rows: [['value' => 'team-a', 'group' => 'inspectors', 'actions' => ['read']]], appId: 'otherapp');
				$event->addRows(rows: [['value' => 'team-b', 'group' => 'inspectors', 'actions' => ['read']]]);
			}
		);

		$this->assertSame(expected: [], actual: $rows);
	}//end testRowsFromAnotherAppOrNoAppAreDropped()
}//end class
