<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The link mapper answers the queries its callers actually make.
 *
 * `find()` exists because ContactService::unlinkContact(), updateRole() and
 * the controller's legacy id path all call it: without it every unlink of a
 * contact answered 500 with "Call to undefined method". The service's own
 * tests could not see that, because the mapper double declared the method
 * with `addMethods(['find'])` — a double that adds a method the real class
 * lacks passes whatever the real class does.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
 */
class ContactLinkMapperTest extends TestCase {

	/**
	 * The methods the services call on the mapper must exist on it.
	 *
	 * @return void
	 */
	public function testTheMapperDeclaresEveryMethodItsCallersUse(): void {
		foreach (
			[
				'find',
				'findByObjectUuid',
				'findByContactUid',
				'findByObjectAndContact',
				'findByObjectContactAndRole',
				'findByUserId',
				'countByObjectUuid',
				'deleteByObjectUuid',
			] as $method
		) {
			$this->assertTrue(
				method_exists(ContactLinkMapper::class, $method),
				"ContactLinkMapper::{$method}() is called by a service and must exist"
			);
		}
	}//end testTheMapperDeclaresEveryMethodItsCallersUse()

	/**
	 * find() selects the row by its id and hands back the entity.
	 *
	 * @return void
	 */
	public function testFindSelectsTheRowById(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('id = :id');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$where = null;
		$qb->method('where')->willReturnCallback(
			function (mixed $condition) use ($qb, &$where): IQueryBuilder {
				$where = $condition;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturn(':id');

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$mapper = new class($db) extends ContactLinkMapper {
			/**
			 * The entity the query would have found.
			 *
			 * @param IQueryBuilder $query The query.
			 *
			 * @return ContactLink The entity.
			 */
			protected function findEntity(IQueryBuilder $query): \OCP\AppFramework\Db\Entity {
				$link = new ContactLink();
				$link->setContactUid('user:jan');
				return $link;
			}
		};

		$found = $mapper->find(7);

		$this->assertSame('user:jan', $found->getContactUid());
		$this->assertSame('id = :id', $where);
	}//end testFindSelectsTheRowById()
}//end class
