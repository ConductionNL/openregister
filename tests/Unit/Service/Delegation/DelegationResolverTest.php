<?php

/**
 * Unit coverage for DelegationResolver — the delegation decision point.
 *
 * Every assertion here is paired against its opposite. A resolver that refuses
 * everything satisfies "an ungranted principal is refused" perfectly, and would
 * be a total outage; a resolver that permits everything satisfies "a granted
 * principal is permitted" and would be the vulnerability. Neither can pass this
 * file.
 *
 * The clock is supplied by the test rather than read by the code, which is the
 * only way the expiry boundary can be asserted at all.
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
use OCA\OpenRegister\Service\Delegation\DelegationResolver;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks who may act as whom, and why the answer is what it is.
 */
class DelegationResolverTest extends TestCase {

	/**
	 * The moment every test judges against.
	 *
	 * @var DateTime
	 */
	private DateTime $now;

	/**
	 * Grants the mapper double returns.
	 *
	 * @var array<int, DelegationGrant>
	 */
	private array $stored = [];

	/**
	 * Whether the store throws when read.
	 *
	 * @var boolean
	 */
	private bool $storeBroken = false;

	protected function setUp(): void {
		parent::setUp();

		$this->now = new DateTime('2026-08-24 12:00:00');
		$this->stored = [];
		$this->storeBroken = false;
	}//end setUp()

	/**
	 * A resolver reading $this->stored.
	 *
	 * @return DelegationResolver The resolver under test.
	 */
	private function resolver(): DelegationResolver {
		$mapper = $this->createMock(DelegationGrantMapper::class);
		$mapper->method('findFor')->willReturnCallback(
			function (string $principal, string $actingAs): array {
				if ($this->storeBroken === true) {
					throw new RuntimeException('the grant table is unreadable');
				}

				return $this->stored;
			}
		);

		return new DelegationResolver($mapper, $this->createMock(LoggerInterface::class));
	}

	/**
	 * Build a grant.
	 *
	 * @param string      $status  The status.
	 * @param string|null $expires An expiry, or null for none.
	 * @param array       $scope   The granted scope.
	 * @param string|null $revoked A revocation time, or null.
	 *
	 * @return DelegationGrant The grant.
	 */
	private function grant(
		string $status = DelegationGrant::STATUS_GRANTED,
		?string $expires = '2026-12-31 00:00:00',
		array $scope = [],
		?string $revoked = null,
	): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');
		$grant->setStatus($status);
		$grant->setGrantedBy('mayor');
		$grant->setScope($scope);

		if ($expires !== null) {
			$grant->setExpiresAt(new DateTime($expires));
		}

		if ($revoked !== null) {
			$grant->setRevokedAt(new DateTime($revoked));
		}

		return $grant;
	}

	/**
	 * POSITIVE CONTROL: a live grant permits.
	 *
	 * @return void
	 */
	public function testALiveGrantPermits(): void {
		$this->stored = [$this->grant()];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertTrue($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_GRANTED, $verdict->reason);
		$this->assertNotNull($verdict->grant, 'a permitted verdict must name the grant it relied on');
	}

	/**
	 * NEGATIVE CONTROL: no grant refuses.
	 *
	 * @return void
	 */
	public function testNoGrantRefuses(): void {
		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_NONE, $verdict->reason);
		$this->assertStringContainsString('alice', $verdict->detail);
		$this->assertStringContainsString('mayor', $verdict->detail);
	}

	/**
	 * Acting as yourself never touches the store.
	 *
	 * Asserted by leaving the store BROKEN: if self-delegation consulted it, this
	 * would refuse with REASON_UNREADABLE instead of permitting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testSelfDelegationShortCircuitsBeforeTheStore(): void {
		$this->storeBroken = true;

		$verdict = $this->resolver()->resolve('alice', 'alice', $this->now);

		$this->assertTrue($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_SELF, $verdict->reason);
		$this->assertNull($verdict->grant, 'self-delegation creates no grant to rely on');
	}

	/**
	 * An expired grant refuses, and says so.
	 *
	 * A grant can be `granted` AND expired. A reader checking only the status
	 * would let it through, which is how a time-boxed delegation quietly becomes
	 * a permanent one.
	 *
	 * @return void
	 */
	public function testAnExpiredGrantRefusesAsExpired(): void {
		$this->stored = [$this->grant(expires: '2026-08-01 00:00:00')];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_EXPIRED, $verdict->reason);
	}

	/**
	 * The expiry boundary is decided against the SUPPLIED clock.
	 *
	 * One second either side of the expiry is the case that must not depend on
	 * whichever machine happens to ask, and it is only assertable because the
	 * resolver takes the time as an argument.
	 *
	 * @return void
	 */
	public function testTheExpiryBoundaryIsExact(): void {
		$this->stored = [$this->grant(expires: '2026-08-24 12:00:00')];

		$justBefore = $this->resolver()->resolve('alice', 'mayor', new DateTime('2026-08-24 11:59:59'));
		$atExpiry = $this->resolver()->resolve('alice', 'mayor', new DateTime('2026-08-24 12:00:00'));

		$this->assertTrue($justBefore->permitted, 'a grant is live up to its expiry');
		$this->assertFalse($atExpiry->permitted, 'a grant is not live AT its expiry');
	}

	/**
	 * A revoked grant refuses as revoked, not as absent.
	 *
	 * "You had this and it was withdrawn" is a different conversation from "you
	 * never had it", and only the first tells the requester something changed.
	 *
	 * @return void
	 */
	public function testARevokedGrantRefusesAsRevoked(): void {
		$this->stored = [$this->grant(status: DelegationGrant::STATUS_REVOKED, revoked: '2026-08-20 00:00:00')];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_REVOKED, $verdict->reason);
	}

	/**
	 * A denial refuses AND suppresses re-requesting.
	 *
	 * The suppression is the security property. Re-asking something a person
	 * declined is how consent fatigue is manufactured: the eleventh identical
	 * prompt is accepted by reflex rather than by decision.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testADenialRefusesAndSuppressesReRequesting(): void {
		$this->stored = [$this->grant(status: DelegationGrant::STATUS_DENIED)];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_DENIED, $verdict->reason);
		$this->assertFalse($verdict->mayRequestConsent(), 'a denial must not be immediately re-asked');
	}

	/**
	 * An outstanding request refuses without raising a second one.
	 *
	 * @return void
	 */
	public function testAPendingRequestDoesNotRaiseAnother(): void {
		$this->stored = [$this->grant(status: DelegationGrant::STATUS_PENDING)];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_PENDING, $verdict->reason);
		$this->assertFalse($verdict->mayRequestConsent());
	}

	/**
	 * Never-granted DOES allow asking.
	 *
	 * The counterpart to the two above — without this, "suppress re-requests"
	 * would be satisfied by never requesting anything.
	 *
	 * @return void
	 */
	public function testAnAbsentGrantMayBeRequested(): void {
		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertTrue($verdict->mayRequestConsent());
	}

	/**
	 * A live grant that does not cover the scope refuses as out-of-scope.
	 *
	 * @return void
	 */
	public function testAScopeMismatchRefusesAsOutOfScope(): void {
		$this->stored = [$this->grant(scope: ['register' => ['reports']])];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now, ['register' => ['payroll']]);

		$this->assertFalse($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_OUT_OF_SCOPE, $verdict->reason);
	}

	/**
	 * A grant covering the scope permits.
	 *
	 * @return void
	 */
	public function testACoveringScopePermits(): void {
		$this->stored = [$this->grant(scope: ['register' => ['payroll', 'reports']])];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now, ['register' => ['payroll']]);

		$this->assertTrue($verdict->permitted);
	}

	/**
	 * An UNSCOPED grant does not silently become the broadest one.
	 *
	 * A record created without thinking about scope must not end up permitting
	 * more than one that was thought about.
	 *
	 * @return void
	 */
	public function testAnUnscopedGrantDoesNotCoverAScopedRequest(): void {
		$this->stored = [$this->grant(scope: [])];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now, ['register' => ['payroll']]);

		$this->assertFalse($verdict->permitted);
	}

	/**
	 * A live grant wins even when dead ones are stored alongside it.
	 *
	 * The realistic shape: somebody was denied, then later granted. Reporting the
	 * denial would refuse work that is in fact permitted.
	 *
	 * @return void
	 */
	public function testALiveGrantWinsOverDeadOnes(): void {
		$this->stored = [
			$this->grant(status: DelegationGrant::STATUS_DENIED),
			$this->grant(status: DelegationGrant::STATUS_REVOKED, revoked: '2026-08-01 00:00:00'),
			$this->grant(),
		];

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertTrue($verdict->permitted);
		$this->assertSame(DelegationVerdict::REASON_GRANTED, $verdict->reason);
	}

	/**
	 * An unreadable store refuses — it does not fall open.
	 *
	 * 🔴 The whole subsystem has now been bitten twice by a guard that returned
	 * "allowed" when its collaborator was absent (a never-injected logger, a
	 * never-injected organisation service). This asserts the opposite default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnreadableStoreFailsClosed(): void {
		$this->storeBroken = true;

		$verdict = $this->resolver()->resolve('alice', 'mayor', $this->now);

		$this->assertFalse($verdict->permitted, 'an unreadable grant store must not permit anything');
		$this->assertSame(DelegationVerdict::REASON_UNREADABLE, $verdict->reason);
	}

	/**
	 * A half-named delegation is refused.
	 *
	 * @return void
	 */
	public function testAnUnnamedPartyIsRefused(): void {
		foreach ([['', 'mayor'], ['alice', ''], ['   ', 'mayor']] as [$principal, $actingAs]) {
			$verdict = $this->resolver()->resolve($principal, $actingAs, $this->now);

			$this->assertFalse($verdict->permitted);
			$this->assertSame(DelegationVerdict::REASON_UNNAMED, $verdict->reason);
		}
	}
}
