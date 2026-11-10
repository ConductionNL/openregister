<?php

/**
 * Unit test for ExampleSetParties: what it refuses, writes, proves and unlinks,
 * against the party services and link mapper it reaches through the container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetParties;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Party\PartyRoleService;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetFieldRules
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 * @uses   \OCA\OpenRegister\Db\ContactLink
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ExampleSetPartiesBehaviourTest extends TestCase {

	/**
	 * The party role service.
	 *
	 * @var PartyRoleService&MockObject
	 */
	private PartyRoleService&MockObject $roles;

	/**
	 * The party service.
	 *
	 * @var PartyService&MockObject
	 */
	private PartyService&MockObject $parties;

	/**
	 * The link mapper.
	 *
	 * @var ContactLinkMapperInterface&MockObject
	 */
	private ContactLinkMapperInterface&MockObject $links;

	/**
	 * The class under test.
	 *
	 * @var ExampleSetParties
	 */
	private ExampleSetParties $subject;

	/**
	 * A container that hands out the three collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->roles   = $this->createMock(PartyRoleService::class);
		$this->parties = $this->createMock(PartyService::class);
		$this->links   = $this->createMock(ContactLinkMapperInterface::class);
		$map           = [
			PartyRoleService::class           => $this->roles,
			PartyService::class               => $this->parties,
			ContactLinkMapperInterface::class => $this->links,
		];
		$container     = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $map[$id]);
		$this->subject = new ExampleSetParties(container: $container);
	}//end setUp()

	/**
	 * A complete link.
	 *
	 * @return array<string, mixed>
	 */
	private function link(): array {
		return ['objectUuid' => 'obj-1', 'partyUuid' => 'party-1', 'role' => 'gemachtigde', 'note' => 'Awb 2:1'];
	}//end link()

	/**
	 * The kind names its record part and noun, and refreshes in place.
	 *
	 * @return void
	 */
	public function testTheKindNamesItsPartAndNounAndRefreshesInPlace(): void {
		$this->assertSame('parties', $this->subject->part());
		$this->assertSame('party', $this->subject->noun());
		$this->assertTrue($this->subject->updatesInPlace());
	}//end testTheKindNamesItsPartAndNounAndRefreshesInPlace()

	/**
	 * A complete link with valid dates is not refused.
	 *
	 * @return void
	 */
	public function testACompleteLinkIsNotRefused(): void {
		$data = $this->link() + ['validFrom' => '2026-01-01', 'validUntil' => '2026-12-31', 'primary' => true];
		$this->assertSame('', $this->subject->refusal(data: $data, users: []));
	}//end testACompleteLinkIsNotRefused()

	/**
	 * An unknown field, a missing required field and an unreadable date are each refused, unknown first.
	 *
	 * @return void
	 */
	public function testAFaultyLinkIsRefusedWithTheFirstReason(): void {
		$this->assertSame(
			'field "colour" is not a party field a set may write',
			$this->subject->refusal(data: ['colour' => 'red', 'role' => ''], users: [])
		);
		$this->assertSame(
			'a party link needs a role (role)',
			$this->subject->refusal(data: ['objectUuid' => 'o', 'partyUuid' => 'p', 'role' => ' '], users: [])
		);
		$this->assertSame(
			'a party link needs the object it is on (objectUuid)',
			$this->subject->refusal(data: ['partyUuid' => 'p', 'role' => 'r'], users: [])
		);
		$this->assertSame(
			'validUntil "soon" is not a date',
			$this->subject->refusal(data: $this->link() + ['validUntil' => 'soon'], users: [])
		);
	}//end testAFaultyLinkIsRefusedWithTheFirstReason()

	/**
	 * A write links the party through the trusted path, without the object uuid in the payload, with the app as linker.
	 *
	 * @return void
	 */
	public function testAWriteLinksThePartyWithTheAppAsLinker(): void {
		$object = new ObjectEntity();
		$this->parties->expects($this->once())->method('findUnscoped')->with('obj-1')->willReturn($object);
		$this->roles->expects($this->once())->method('importParty')->with(
			$object,
			['partyUuid' => 'party-1', 'role' => 'gemachtigde', 'note' => 'Awb 2:1'],
			'alpha'
		)->willReturn(new ContactLink());

		$result = $this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'k', data: $this->link()));

		$this->assertSame(
			['error' => '', 'entry' => ['objectUuid' => 'obj-1', 'partyUuid' => 'party-1', 'role' => 'gemachtigde']],
			$result
		);
	}//end testAWriteLinksThePartyWithTheAppAsLinker()

	/**
	 * A write onto an object that cannot be found links nothing and says so.
	 *
	 * @return void
	 */
	public function testAWriteOntoAMissingObjectLinksNothing(): void {
		$this->parties->method('findUnscoped')->willReturn(null);
		$this->roles->expects($this->never())->method('importParty');

		$result = $this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'k', data: $this->link()));

		$this->assertSame(['error' => 'no object "obj-1" to put the party on', 'entry' => null], $result);
	}//end testAWriteOntoAMissingObjectLinksNothing()

	/**
	 * A link the party model refuses is not recorded and its message is the error.
	 *
	 * @return void
	 */
	public function testAWriteThePartyModelRefusesReturnsItsMessage(): void {
		$this->parties->method('findUnscoped')->willReturn(new ObjectEntity());
		$this->roles->method('importParty')->willThrowException(new RuntimeException('"x" is not a party'));

		$result = $this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'k', data: $this->link()));

		$this->assertSame(['error' => '"x" is not a party', 'entry' => null], $result);
	}//end testAWriteThePartyModelRefusesReturnsItsMessage()

	/**
	 * A link is lost when the mapper finds none by object, party uid and role, and kept when it finds one.
	 *
	 * @return void
	 */
	public function testALinkIsLostOnlyWhenTheMapperFindsNone(): void {
		$entry = ['objectUuid' => 'obj-1', 'partyUuid' => 'party-1', 'role' => 'gemachtigde'];
		$this->links->expects($this->exactly(2))->method('findByObjectContactAndRole')
			->with('obj-1', ContactLink::partyUid(partyUuid: 'party-1'), 'gemachtigde')
			->willReturnOnConsecutiveCalls(new ContactLink(), null);

		$this->assertFalse($this->subject->lost(entry: $entry));
		$this->assertTrue($this->subject->lost(entry: $entry));
	}//end testALinkIsLostOnlyWhenTheMapperFindsNone()

	/**
	 * A mapper that fails reads as a lost link.
	 *
	 * @return void
	 */
	public function testAFailingMapperReadsAsLost(): void {
		$this->links->method('findByObjectContactAndRole')->willThrowException(new RuntimeException('db down'));
		$this->assertTrue($this->subject->lost(entry: ['objectUuid' => 'o', 'partyUuid' => 'p', 'role' => 'r']));
	}//end testAFailingMapperReadsAsLost()

	/**
	 * A revert of a link that is already gone removes nothing and reports removed.
	 *
	 * @return void
	 */
	public function testARevertOfAGoneLinkRemovesNothing(): void {
		$this->links->method('findByObjectContactAndRole')->willReturn(null);
		$this->roles->expects($this->never())->method('removeParty');

		$this->assertSame('removed', $this->subject->revert(entry: ['objectUuid' => 'o', 'partyUuid' => 'p', 'role' => 'r']));
	}//end testARevertOfAGoneLinkRemovesNothing()

	/**
	 * A revert of a held link unlinks exactly that object, party and role.
	 *
	 * @return void
	 */
	public function testARevertUnlinksTheHeldLink(): void {
		$this->links->method('findByObjectContactAndRole')->willReturn(new ContactLink());
		$this->roles->expects($this->once())->method('removeParty')->with('obj-1', 'party-1', 'gemachtigde')->willReturn(1);

		$this->assertSame(
			'removed',
			$this->subject->revert(entry: ['objectUuid' => 'obj-1', 'partyUuid' => 'party-1', 'role' => 'gemachtigde'])
		);
	}//end testARevertUnlinksTheHeldLink()

	/**
	 * A revert whose unlink fails reports failed.
	 *
	 * @return void
	 */
	public function testARevertWhoseUnlinkFailsReportsFailed(): void {
		$this->links->method('findByObjectContactAndRole')->willReturn(new ContactLink());
		$this->roles->method('removeParty')->willThrowException(new RuntimeException('nope'));

		$this->assertSame('failed', $this->subject->revert(entry: ['objectUuid' => 'o', 'partyUuid' => 'p', 'role' => 'r']));
	}//end testARevertWhoseUnlinkFailsReportsFailed()
}//end class
