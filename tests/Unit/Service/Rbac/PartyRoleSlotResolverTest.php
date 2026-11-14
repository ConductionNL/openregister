<?php

/**
 * The slot reader and the occupant lookup, on their own.
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
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCA\OpenRegister\Service\Rbac\PartyRoleSlotResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\PartyRoleSlotResolver
 */
class PartyRoleSlotResolverTest extends TestCase {

	/**
	 * The object every case asks about.
	 *
	 * @var string
	 */
	private const OBJECT = '5107a000-0000-4000-8000-000000000001';

	/**
	 * A user link on the object.
	 *
	 * @param string        $userId     The user.
	 * @param string|null   $role       The role.
	 * @param DateTime|null $validUntil The last day, null for open.
	 * @param DateTime|null $validFrom  The first day, null for open.
	 *
	 * @return ContactLink The link.
	 */
	private static function userLink(string $userId, ?string $role, ?DateTime $validUntil = null, ?DateTime $validFrom = null): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid(self::OBJECT);
		$link->setContactUid(ContactLink::USER_UID_PREFIX . $userId);
		$link->setUserId($userId);
		$link->setRole($role);
		$link->setValidFrom($validFrom);
		$link->setValidUntil($validUntil);

		return $link;
	}//end userLink()

	/**
	 * A resolver over a fixed set of links.
	 *
	 * @param array<int, mixed> $links The rows the link table answers.
	 *
	 * @return PartyRoleSlotResolver The resolver.
	 */
	private function resolverOver(array $links): PartyRoleSlotResolver {
		$mapper = $this->createMock(ContactLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->with(self::OBJECT)->willReturn($links);

		return new PartyRoleSlotResolver(links: $mapper, logger: new NullLogger());
	}//end resolverOver()

	/**
	 * Only a non-blank string under `partyRole` names a slot, trimmed.
	 *
	 * @return void
	 */
	public function testSlotOfReadsOnlyAWellFormedRole(): void {
		$this->assertSame('behandelaar', PartyRoleSlotResolver::slotOf(['partyRole' => 'behandelaar']));
		$this->assertSame('behandelaar', PartyRoleSlotResolver::slotOf(['partyRole' => '  behandelaar ', 'match' => []]));

		foreach ([
			'behandelaar',
			'partyRole:behandelaar',
			['group' => 'behandelaars'],
			['partyRole' => ''],
			['partyRole' => '   '],
			['partyRole' => null],
			['partyRole' => 7],
			['partyRole' => ['behandelaar']],
			['partyRole' => true],
			null,
			42,
		] as $entry) {
			$this->assertNull(PartyRoleSlotResolver::slotOf($entry), var_export($entry, true) . ' named a slot');
		}
	}//end testSlotOfReadsOnlyAWellFormedRole()

	/**
	 * A block's slots are read per action; settings and denies name none.
	 *
	 * @return void
	 */
	public function testSlotsNamedInReadsTheRuleListsOnly(): void {
		$block = [
			'scope' => 'private',
			'read' => ['medewerkers', ['partyRole' => 'adviseur']],
			'update' => [['partyRole' => 'behandelaar'], ['partyRole' => 'behandelaar'], ['partyRole' => '']],
			'delete' => ['beheerders'],
			'deny' => ['update' => [['partyRole' => 'aanvrager']]],
		];

		$this->assertSame(['read' => ['adviseur'], 'update' => ['behandelaar']], PartyRoleSlotResolver::slotsNamedIn($block));
		$this->assertSame([], PartyRoleSlotResolver::slotsNamedIn(null));
		$this->assertSame([], PartyRoleSlotResolver::slotsNamedIn(['update' => ['behandelaars']]));
	}//end testSlotsNamedInReadsTheRuleListsOnly()

	/**
	 * The roles a user holds now: their own user links, inside the period, with a role.
	 *
	 * @return void
	 */
	public function testRolesHeldByReadsTheCurrentOccupantsOnly(): void {
		$party = new ContactLink();
		$party->setObjectUuid(self::OBJECT);
		$party->setContactUid(ContactLink::partyUid('ana'));
		$party->setPartyUuid('ana');
		$party->setRole('gemachtigde');

		$resolver = $this->resolverOver(
			[
				self::userLink(userId: 'ana', role: 'behandelaar'),
				self::userLink(userId: 'ana', role: 'behandelaar'),
				self::userLink(userId: 'ana', role: 'adviseur', validUntil: new DateTime('-3 days')),
				self::userLink(userId: 'ana', role: 'coordinator', validFrom: new DateTime('+3 days')),
				self::userLink(userId: 'ana', role: null),
				self::userLink(userId: 'bob', role: 'archivaris'),
				$party,
				'not a link',
			]
		);

		$this->assertSame(['behandelaar'], $resolver->rolesHeldBy('ana', self::OBJECT));
		$this->assertSame(['archivaris'], $resolver->rolesHeldBy('bob', self::OBJECT));
		$this->assertSame([], $resolver->rolesHeldBy('carla', self::OBJECT));
	}//end testRolesHeldByReadsTheCurrentOccupantsOnly()

	/**
	 * A blank caller or object holds nothing, and the table is not asked.
	 *
	 * @return void
	 */
	public function testABlankCallerOrObjectHoldsNothing(): void {
		$mapper = $this->createMock(ContactLinkMapperInterface::class);
		$mapper->expects($this->never())->method('findByObjectUuid');
		$resolver = new PartyRoleSlotResolver(links: $mapper, logger: new NullLogger());

		$this->assertSame([], $resolver->rolesHeldBy(null, self::OBJECT));
		$this->assertSame([], $resolver->rolesHeldBy('', self::OBJECT));
		$this->assertSame([], $resolver->rolesHeldBy('ana', null));
		$this->assertSame([], $resolver->rolesHeldBy('ana', ''));
	}//end testABlankCallerOrObjectHoldsNothing()

	/**
	 * A lookup that throws holds nothing, and says so.
	 *
	 * @return void
	 */
	public function testALookupFailureHoldsNothing(): void {
		$mapper = $this->createMock(ContactLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->willThrowException(new RuntimeException('database gone'));

		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$resolver = new PartyRoleSlotResolver(links: $mapper, logger: $logger);

		$this->assertSame([], $resolver->rolesHeldBy('ana', self::OBJECT));
	}//end testALookupFailureHoldsNothing()
}//end class
