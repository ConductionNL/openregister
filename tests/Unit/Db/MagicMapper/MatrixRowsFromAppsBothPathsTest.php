<?php

/**
 * Matrix rows an app adds at check time, on both enforcement paths.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\MatrixRowsRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * A provider row admits on both paths; a failing or unreadable one admits nobody.
 */
class MatrixRowsFromAppsBothPathsTest extends TestCase {

	/**
	 * A case schema whose matrix has no rows of its own and names a provider.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = MatrixRowConditionsBothPathsTest::caseSchema();
		$schema->setAuthorization(
			[
				'matrix' => [
					'field' => 'assignedGroup',
					'userSource' => ['groups' => true],
					'rowsFrom' => 'test.provider',
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * A dispatcher whose listener does what the callback says, counting calls.
	 *
	 * @param callable $listener Receives the event.
	 * @param int      $calls    Counts dispatches.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(callable $listener, int &$calls): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($listener, &$calls): void {
				if ($event instanceof MatrixRowsRequestedEvent && $event->getProvider() === 'test.provider') {
					$calls++;
					$listener($event);
				}
			}
		);

		return $dispatcher;
	}//end dispatcher()

	/**
	 * Run both paths for anna with the given listener.
	 *
	 * @param callable $listener The listener.
	 * @param int      $calls    Counts dispatches.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} PHP verdicts, SQL list.
	 */
	private function bothPaths(callable $listener, int &$calls): array {
		$harness = new MatrixRowConditionsBothPathsTest('harness');
		[$permissions, $rbac] = $harness->handlers('anna', $this->dispatcher($listener, $calls));
		$schema = $this->schema();

		return [
			MatrixRowConditionsBothPathsTest::admitted($permissions, $schema, 'anna', 'read'),
			MatrixRowConditionsBothPathsTest::listed($rbac, $schema),
		];
	}//end bothPaths()

	/**
	 * A provider row admits, the same set on both paths, asked once per request.
	 *
	 * @return void
	 */
	public function testAProviderRowAdmitsOnBothPaths(): void {
		$calls = 0;
		[$php, $sql] = $this->bothPaths(
			static fn (MatrixRowsRequestedEvent $event) => $event->addRows(
				[['value' => '$self', 'group' => 'intakers', 'actions' => ['read'], 'where' => ['status' => 's1']]]
			),
			$calls
		);

		$this->assertSame(['c-1'], $php);
		$this->assertSame($php, $sql);
		$this->assertSame(1, $calls, 'four verdicts and a list ask the provider once');
	}//end testAProviderRowAdmitsOnBothPaths()

	/**
	 * A listener that throws admits nobody.
	 *
	 * @return void
	 */
	public function testAFailingProviderAdmitsNobody(): void {
		$calls = 0;
		[$php, $sql] = $this->bothPaths(
			static function (): void {
				throw new \RuntimeException('provider down');
			},
			$calls
		);

		$this->assertSame([], $php);
		$this->assertSame([], $sql);
	}//end testAFailingProviderAdmitsNobody()

	/**
	 * A provider row with an undeclared `where` key is dropped, not widened.
	 *
	 * @return void
	 */
	public function testAnUnreadableProviderRowIsDropped(): void {
		$calls = 0;
		[$php, $sql] = $this->bothPaths(
			static fn (MatrixRowsRequestedEvent $event) => $event->addRows(
				[['value' => '$self', 'group' => 'intakers', 'actions' => ['read'], 'where' => ['phase' => 'p1']]]
			),
			$calls
		);

		$this->assertSame([], $php);
		$this->assertSame([], $sql);
	}//end testAnUnreadableProviderRowIsDropped()
}//end class
