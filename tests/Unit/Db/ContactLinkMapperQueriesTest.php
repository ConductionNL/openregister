<?php

/**
 * ContactLinkMapper query behaviour tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Each finder selects on the column it is named after and maps the rows it gets.
 */
class ContactLinkMapperQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var ContactLinkMapper
	 */
	private ContactLinkMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new ContactLinkMapper($this->recordingDb());
	}//end setUp()

	/**
	 * find() binds the id as an integer and returns the mapped row.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdAsAnInteger(): void {
		$this->results[] = [['id' => 7, 'contact_uid' => 'user:jan']];

		$link = $this->mapper->find(7);

		$this->assertSame('user:jan', $link->getContactUid());
		$this->assertSame(
			'select(*) from(openregister_contact_links) where(eq(id, :p0)) executeQuery()',
			$this->sql()
		);
		$this->assertSame([7, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindBindsTheIdAsAnInteger()

	/**
	 * findByContactUid() filters on contact_uid, newest first, and maps every row.
	 *
	 * @return void
	 */
	public function testFindByContactUidOrdersNewestFirst(): void {
		$this->results[] = [
			['id' => 1, 'object_uuid' => 'o-1'],
			['id' => 2, 'object_uuid' => 'o-2'],
		];

		$links = $this->mapper->findByContactUid('card-9');

		$this->assertSame(['o-1', 'o-2'], array_map(static fn (ContactLink $l): ?string => $l->getObjectUuid(), $links));
		$this->assertSame(
			'select(*) from(openregister_contact_links) where(eq(contact_uid, :p0)) orderBy(linked_at, DESC) executeQuery()',
			$this->sql()
		);
		$this->assertSame('card-9', $this->bound(':p0'));
	}//end testFindByContactUidOrdersNewestFirst()

	/**
	 * An empty result yields an empty list, not an error.
	 *
	 * @return void
	 */
	public function testFindByContactUidWithoutRowsIsEmpty(): void {
		$this->assertSame([], $this->mapper->findByContactUid('nobody'));
	}//end testFindByContactUidWithoutRowsIsEmpty()

	/**
	 * findByObjectAndContact() matches both columns and returns the single row.
	 *
	 * @return void
	 */
	public function testFindByObjectAndContactReturnsTheMatch(): void {
		$this->results[] = [['id' => 3, 'role' => 'applicant']];

		$link = $this->mapper->findByObjectAndContact('obj-1', 'card-1');

		$this->assertSame('applicant', $link?->getRole());
		$this->assertSame(
			'select(*) from(openregister_contact_links) where(eq(object_uuid, :p0)) andWhere(eq(contact_uid, :p1)) setMaxResults(1) executeQuery()',
			$this->sql()
		);
		$this->assertSame(['obj-1', 'card-1'], $this->boundValues());
	}//end testFindByObjectAndContactReturnsTheMatch()

	/**
	 * No row for the pair answers null instead of throwing.
	 *
	 * @return void
	 */
	public function testFindByObjectAndContactWithoutMatchIsNull(): void {
		$this->assertNull($this->mapper->findByObjectAndContact('obj-1', 'card-1'));
	}//end testFindByObjectAndContactWithoutMatchIsNull()

	/**
	 * A null role matches links without a role.
	 *
	 * @return void
	 */
	public function testNullRoleMatchesRolelessLinks(): void {
		$this->results[] = [['id' => 4]];

		$link = $this->mapper->findByObjectContactAndRole('obj-1', 'card-1', null);

		$this->assertSame(4, $link?->getId());
		$this->assertStringContainsString('andWhere(isNull(role))', $this->sql());
		$this->assertSame(['obj-1', 'card-1'], $this->boundValues());
	}//end testNullRoleMatchesRolelessLinks()

	/**
	 * An empty role string is treated as no role.
	 *
	 * @return void
	 */
	public function testEmptyRoleMatchesRolelessLinks(): void {
		$this->mapper->findByObjectContactAndRole('obj-1', 'card-1', '');

		$this->assertStringContainsString('andWhere(isNull(role))', $this->sql());
		$this->assertCount(2, $this->params);
	}//end testEmptyRoleMatchesRolelessLinks()

	/**
	 * A named role binds that role, and no match answers null.
	 *
	 * @return void
	 */
	public function testNamedRoleBindsTheRole(): void {
		$result = $this->mapper->findByObjectContactAndRole('obj-1', 'card-1', 'witness');

		$this->assertNull($result);
		$this->assertStringContainsString('andWhere(eq(role, :p2))', $this->sql());
		$this->assertStringNotContainsString('isNull(role)', $this->sql());
		$this->assertSame('witness', $this->bound(':p2'));
	}//end testNamedRoleBindsTheRole()

	/**
	 * findByUserId() filters on user_id, newest first.
	 *
	 * @return void
	 */
	public function testFindByUserIdFiltersOnUser(): void {
		$this->results[] = [['id' => 5, 'user_id' => 'jan']];

		$links = $this->mapper->findByUserId('jan');

		$this->assertSame('jan', $links[0]->getUserId());
		$this->assertSame(
			'select(*) from(openregister_contact_links) where(eq(user_id, :p0)) orderBy(linked_at, DESC) executeQuery()',
			$this->sql()
		);
	}//end testFindByUserIdFiltersOnUser()

	/**
	 * findByPartyUuid() filters on party_uuid, newest first.
	 *
	 * @return void
	 */
	public function testFindByPartyUuidFiltersOnParty(): void {
		$this->results[] = [['id' => 6, 'party_uuid' => 'p-1']];

		$links = $this->mapper->findByPartyUuid('p-1');

		$this->assertSame('p-1', $links[0]->getPartyUuid());
		$this->assertSame(
			'select(*) from(openregister_contact_links) where(eq(party_uuid, :p0)) orderBy(linked_at, DESC) executeQuery()',
			$this->sql()
		);
	}//end testFindByPartyUuidFiltersOnParty()

	/**
	 * findPartiesForObject() keeps only party links, oldest first.
	 *
	 * @return void
	 */
	public function testFindPartiesForObjectKeepsPartyLinksOldestFirst(): void {
		$this->results[] = [['id' => 1, 'party_uuid' => 'p-1'], ['id' => 2, 'party_uuid' => 'p-2']];

		$links = $this->mapper->findPartiesForObject('obj-1');

		$this->assertCount(2, $links);
		$this->assertSame(
			'select(*) from(openregister_contact_links) where(eq(object_uuid, :p0)) andWhere(isNotNull(party_uuid)) orderBy(linked_at, ASC) executeQuery()',
			$this->sql()
		);
	}//end testFindPartiesForObjectKeepsPartyLinksOldestFirst()

	/**
	 * findPrimaryParty() binds primary_party as a boolean true.
	 *
	 * @return void
	 */
	public function testFindPrimaryPartyBindsTrueAsBoolean(): void {
		$this->results[] = [['id' => 8, 'party_uuid' => 'p-main']];

		$link = $this->mapper->findPrimaryParty('obj-1');

		$this->assertSame('p-main', $link?->getPartyUuid());
		$this->assertStringContainsString('andWhere(eq(primary_party, :p1)) setMaxResults(1)', $this->sql());
		$this->assertSame([true, IQueryBuilder::PARAM_BOOL], $this->params[':p1']);
	}//end testFindPrimaryPartyBindsTrueAsBoolean()

	/**
	 * An object without a primary party answers null.
	 *
	 * @return void
	 */
	public function testFindPrimaryPartyWithoutOneIsNull(): void {
		$this->assertNull($this->mapper->findPrimaryParty('obj-1'));
	}//end testFindPrimaryPartyWithoutOneIsNull()

	/**
	 * findByOperationMemo() searches the metadata with the escaped id inside wildcards.
	 *
	 * @return void
	 */
	public function testFindByOperationMemoEscapesTheOperationId(): void {
		$this->results[] = [['id' => 9]];

		$links = $this->mapper->findByOperationMemo('op_1%');

		$this->assertCount(1, $links);
		$this->assertSame(
			'select(*) from(openregister_contact_links) where(like(metadata, :p0)) executeQuery()',
			$this->sql()
		);
		$this->assertSame('%op\\_1\\%%', $this->bound(':p0'));
	}//end testFindByOperationMemoEscapesTheOperationId()
}//end class
