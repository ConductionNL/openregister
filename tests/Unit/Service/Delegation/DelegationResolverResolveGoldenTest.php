<?php

/**
 * Golden coverage for DelegationResolver::resolve before the grants are judged.
 *
 * Pins the exact refusal details, the trimmed parties the store is asked for,
 * the error logged when the store cannot be read, and that a live grant found
 * under padded names still permits.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Delegation;

use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Service\Delegation\DelegationRequest;
use OCA\OpenRegister\Service\Delegation\DelegationResolver;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks the allowed and refused answers resolve gives before judging grants.
 */
class DelegationResolverResolveGoldenTest extends TestCase {

	/**
	 * The parties the store was asked for, per call.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $asked = [];

	/**
	 * Reset the recorder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->asked = [];
	}//end setUp()

	/**
	 * A resolver whose store returns `$grants`, or throws when null.
	 *
	 * @param array<int, DelegationGrant>|null $grants The stored grants.
	 * @param LoggerInterface|null             $logger The logger, or a silent double.
	 *
	 * @return DelegationResolver The resolver.
	 */
	private function resolver(?array $grants, ?LoggerInterface $logger = null): DelegationResolver {
		$mapper = $this->createMock(DelegationGrantMapper::class);
		$mapper->method('findFor')->willReturnCallback(
			function (string $principal, string $actingAs) use ($grants): array {
				$this->asked[] = [$principal, $actingAs];
				if ($grants === null) {
					throw new RuntimeException('table gone');
				}

				return $grants;
			}
		);

		return new DelegationResolver($mapper, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end resolver()

	/**
	 * A request between padded parties.
	 *
	 * @param string $principal Who would act.
	 * @param string $actingAs  As whom.
	 *
	 * @return DelegationRequest The request.
	 */
	private function request(string $principal = ' alice ', string $actingAs = 'mayor  '): DelegationRequest {
		return new DelegationRequest(
			principal: $principal,
			actingAs: $actingAs,
			scope: [],
			now: new DateTime('2026-08-24 12:00:00')
		);
	}//end request()

	/**
	 * An unnamed party is refused with its detail and the store is not read.
	 *
	 * @return void
	 */
	public function testAnUnnamedPartyIsRefusedWithoutReadingTheStore(): void {
		$verdict = $this->resolver([])->resolve($this->request(' ', 'mayor'));

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_UNNAMED, $verdict->reason);
		$this->assertSame('A delegation needs both a principal and an identity to act as.', $verdict->detail);
		$this->assertSame([], $this->asked);
	}//end testAnUnnamedPartyIsRefusedWithoutReadingTheStore()

	/**
	 * Acting as oneself (after trimming) is allowed without reading the store.
	 *
	 * @return void
	 */
	public function testActingAsOneselfIsAllowedWithoutReadingTheStore(): void {
		$verdict = $this->resolver(null)->resolve($this->request(' alice', 'alice '));

		$this->assertTrue($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_SELF, $verdict->reason);
		$this->assertSame([], $this->asked);
	}//end testActingAsOneselfIsAllowedWithoutReadingTheStore()

	/**
	 * The store is asked for the trimmed parties; no grant is refused with both names.
	 *
	 * @return void
	 */
	public function testNoGrantIsRefusedNamingTheTrimmedParties(): void {
		$verdict = $this->resolver([])->resolve($this->request());

		$this->assertSame([['alice', 'mayor']], $this->asked);
		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_NONE, $verdict->reason);
		$this->assertSame('"alice" holds no grant to act as "mayor".', $verdict->detail);
	}//end testNoGrantIsRefusedNamingTheTrimmedParties()

	/**
	 * An unreadable store is refused and logged as an error with the trimmed parties.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreIsRefusedAndLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[DelegationResolver] Could not read the grant store; refusing: table gone',
			$this->callback(
				static fn (array $context): bool => $context['principal'] === 'alice' && $context['actingAs'] === 'mayor'
			)
		);

		$verdict = $this->resolver(null, $logger)->resolve($this->request());

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_UNREADABLE, $verdict->reason);
		$this->assertSame('The delegation store could not be read, so the delegation is refused.', $verdict->detail);
	}//end testAnUnreadableStoreIsRefusedAndLogged()

	/**
	 * A live grant found under padded names permits, and a dead one is judged with trimmed names.
	 *
	 * @return void
	 */
	public function testFoundGrantsAreJudgedWithTheTrimmedRequest(): void {
		$live = new DelegationGrant();
		$live->setStatus(DelegationGrant::STATUS_GRANTED);
		$live->setScope([]);
		$this->assertTrue($this->resolver([$live])->resolve($this->request())->permitted);

		$denied = new DelegationGrant();
		$denied->setStatus(DelegationGrant::STATUS_DENIED);
		$verdict = $this->resolver([$denied])->resolve($this->request());
		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_DENIED, $verdict->reason);
		$this->assertSame('"alice" holds no live grant to act as "mayor".', $verdict->detail);
	}//end testFoundGrantsAreJudgedWithTheTrimmedRequest()
}//end class
