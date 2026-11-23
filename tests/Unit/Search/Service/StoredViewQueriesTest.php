<?php

/**
 * StoredViewQueries: a view's stored query, read the way object search asked for it before the port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\StoredViewQueriesInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\Service\StoredViewQueries;
use OCA\OpenRegister\Search\Service\ViewService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The guarded read passes its flag to the mapper's RBAC and multitenancy; the usable read goes through the view service.
 *
 * @covers \OCA\OpenRegister\Search\Service\StoredViewQueries
 * @uses   \OCA\OpenRegister\Search\Db\View
 */
final class StoredViewQueriesTest extends TestCase {

	/**
	 * A view storing a query.
	 *
	 * @param array<string, mixed>|null $query The stored query.
	 *
	 * @return View
	 */
	private static function view(?array $query): View {
		$view = new View();
		$view->setQuery($query);

		return $view;
	}//end view()

	/**
	 * The guard flag reaches both the RBAC and the multitenancy switch of the mapper.
	 *
	 * @return void
	 */
	public function testTheGuardFlagReachesTheMapper(): void {
		$calls  = [];
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function ($id, bool $_rbac = true, bool $_multitenancy = true) use (&$calls): View {
				$calls[] = [$id, $_rbac, $_multitenancy];
				return self::view(query: ['registers' => [1]]);
			}
		);
		$queries = new StoredViewQueries(viewMapper: $mapper, views: $this->createMock(ViewService::class));

		$this->assertInstanceOf(StoredViewQueriesInterface::class, $queries);
		$this->assertSame(['registers' => [1]], $queries->queryOf(viewId: 7, guarded: true));
		$this->assertSame(['registers' => [1]], $queries->queryOf(viewId: 'abc', guarded: false));
		$this->assertSame([[7, true, true], ['abc', false, false]], $calls);
	}//end testTheGuardFlagReachesTheMapper()

	/**
	 * A view with no stored query answers null.
	 *
	 * @return void
	 */
	public function testAViewWithoutAQueryAnswersNull(): void {
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('find')->willReturn(self::view(query: null));
		$queries = new StoredViewQueries(viewMapper: $mapper, views: $this->createMock(ViewService::class));

		$this->assertNull($queries->queryOf(viewId: 7, guarded: false));
	}//end testAViewWithoutAQueryAnswersNull()

	/**
	 * The usable read asks the view service for the owner's view and passes its refusal on.
	 *
	 * @return void
	 */
	public function testTheUsableReadGoesThroughTheViewService(): void {
		$views = $this->createMock(ViewService::class);
		$views->method('find')->willReturnCallback(
			static function (int|string $id, string $owner): View {
				if ($owner !== 'alice') {
					throw new DoesNotExistException('View not found or access denied');
				}

				return self::view(query: ['schemas' => [$id]]);
			}
		);
		$queries = new StoredViewQueries(viewMapper: $this->createMock(ViewMapperInterface::class), views: $views);

		$this->assertSame(['schemas' => [9]], $queries->usableQueryOf(viewId: 9, owner: 'alice'));
		$this->expectException(DoesNotExistException::class);
		$queries->usableQueryOf(viewId: 9, owner: 'bob');
	}//end testTheUsableReadGoesThroughTheViewService()
}//end class
