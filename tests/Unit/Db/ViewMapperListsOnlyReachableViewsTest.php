<?php

/**
 * Listing views returns the caller's own views, the public ones and the ones
 * shared with them, never another user's private view, also not to an
 * administrator: an administrator's reach to such a view is for opening or
 * managing it by id, not for their own lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/saved-search-views/spec.md
 */
class ViewMapperListsOnlyReachableViewsTest extends TestCase {

	/**
	 * A mapper whose query returns the given views.
	 *
	 * @param View[] $views The rows the query finds.
	 *
	 * @return ViewMapper The mapper.
	 */
	private function mapperFinding(array $views): ViewMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'orderBy', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = $this->getMockBuilder(ViewMapper::class)
			->setConstructorArgs([
				$db,
				$this->createMock(OrganisationMapper::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(IEventDispatcher::class),
			])
			->onlyMethods(['findEntities', 'verifyRbacPermission', 'applyOrganisationFilter'])
			->getMock();
		$mapper->method('findEntities')->willReturn($views);

		return $mapper;
	}//end mapperFinding()

	/**
	 * A view.
	 *
	 * @param string $name     Its name.
	 * @param string $owner    Its owner.
	 * @param bool   $isPublic Whether it is public.
	 * @param array  $shares   Its group shares.
	 *
	 * @return View The view.
	 */
	private function view(string $name, string $owner, bool $isPublic=false, array $shares=[]): View {
		$view = new View();
		$view->setName($name);
		$view->setOwner($owner);
		$view->setIsPublic($isPublic);
		$view->setSharedWith($shares);
		return $view;
	}//end view()

	/**
	 * The names of the listed views.
	 *
	 * @param View[] $views The views.
	 *
	 * @return string[] Their names.
	 */
	private function names(array $views): array {
		return array_map(static fn (View $view): string => (string)$view->getName(), $views);
	}//end names()

	public function testAnotherUsersPrivateViewIsNotListedForAnAdministrator(): void {
		$mapper = $this->mapperFinding(
			[
				$this->view(name: 'Mine', owner: 'pieter'),
				$this->view(name: 'Admin private', owner: 'admin'),
				$this->view(name: 'Public', owner: 'anouk', isPublic: true),
				$this->view(name: 'Team', owner: 'anouk', shares: [['group' => 'woo', 'mode' => 'read']]),
				$this->view(name: 'Other team', owner: 'anouk', shares: [['group' => 'vth', 'mode' => 'read']]),
			]
		);

		$listed = $mapper->findAllFor(reach: new ViewerReach(userId: 'pieter', groups: ['woo', 'admin'], isAdmin: true));

		$this->assertSame(['Mine', 'Public', 'Team'], $this->names($listed));
	}//end testAnotherUsersPrivateViewIsNotListedForAnAdministrator()

	public function testAnotherUsersPrivateViewIsNotListedForAUser(): void {
		$mapper = $this->mapperFinding([$this->view(name: 'Mine', owner: 'pieter'), $this->view(name: 'Admin private', owner: 'admin')]);

		$listed = $mapper->findAllFor(reach: new ViewerReach(userId: 'pieter', groups: [], isAdmin: false));

		$this->assertSame(['Mine'], $this->names($listed));
	}//end testAnotherUsersPrivateViewIsNotListedForAUser()
}//end class
