<?php

/**
 * Unit coverage for DelegationService — the guarded form of "act as this user".
 *
 * Every refusal here is paired with a positive control, because a service that
 * refuses everything satisfies all four refusal assertions and is an outage.
 *
 * The test that matters most is the last one: a LIVE GRANT naming an account
 * that no longer resolves must refuse, not fall back. The tempting repair —
 * run as the principal instead — executes the work under an identity nobody
 * authorised while the grant record says otherwise, and nothing goes red.
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
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationResolver;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks what a delegation permits, refuses, and never substitutes.
 */
class DelegationServiceTest extends TestCase {

	/**
	 * The uid the acted-as user resolves to, or null to make it unresolvable.
	 *
	 * @var string|null
	 */
	private ?string $resolvable = 'mayor';

	/**
	 * Whether the identity-switch primitive was actually used.
	 *
	 * @var boolean
	 */
	private bool $switched = false;

	protected function setUp(): void {
		parent::setUp();

		$this->resolvable = 'mayor';
		$this->switched = false;
	}//end setUp()

	/**
	 * A service whose resolver returns the given verdict.
	 *
	 * @param DelegationVerdict $verdict What the resolver answers.
	 *
	 * @return DelegationService The service under test.
	 */
	private function service(DelegationVerdict $verdict): DelegationService {
		$resolver = $this->createMock(DelegationResolver::class);
		$resolver->method('resolve')->willReturn($verdict);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($this->resolvable === null || $uid !== $this->resolvable) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return $user;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->switched = true;

				return $operation();
			}
		);

		return new DelegationService(
			$resolver,
			$userManager,
			$objectService,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A grant covering the work.
	 *
	 * @return DelegationGrant The grant.
	 */
	private function grant(): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);

		return $grant;
	}

	/**
	 * POSITIVE CONTROL: a granted delegation runs, as the named user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAGrantedDelegationRunsAsTheNamedUser(): void {
		$result = $this->service(DelegationVerdict::granted($this->grant()))
			->runAsDelegated('alice', 'mayor', static fn (): string => 'done');

		$this->assertSame('done', $result);
		$this->assertTrue($this->switched, 'a delegation that permits must actually switch identity');
	}

	/**
	 * Acting as yourself runs WITHOUT an identity switch.
	 *
	 * Not an optimisation. Switching to the identity already in effect is still a
	 * switch, and one a `finally` has to undo — pure risk for no behaviour change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testActingAsYourselfDoesNotSwitchIdentity(): void {
		$result = $this->service(DelegationVerdict::self())
			->runAsDelegated('alice', 'alice', static fn (): string => 'done');

		$this->assertSame('done', $result);
		$this->assertFalse($this->switched);
	}

	/**
	 * A refusal throws, and the OPERATION NEVER RUNS.
	 *
	 * Both halves are asserted. A refusal that stops the return value but lets
	 * the work happen is the shape this codebase has already shipped once: the
	 * control flow refused while the audit trail recorded the run as having taken
	 * place under the identity that was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testARefusalThrowsAndTheOperationNeverRuns(): void {
		$ran = false;

		$service = $this->service(
			DelegationVerdict::refused(DelegationVerdict::REASON_NONE, 'alice holds no grant.')
		);

		try {
			$service->runAsDelegated(
				'alice',
				'mayor',
				static function () use (&$ran): string {
					$ran = true;

					return 'done';
				}
			);
			$this->fail('a refused delegation must throw');
		} catch (DelegationRefused $e) {
			$this->assertSame(DelegationVerdict::REASON_NONE, $e->getVerdict()->reason);
			$this->assertSame('alice', $e->getPrincipal());
			$this->assertSame('mayor', $e->getActingAs());
		}

		$this->assertFalse($ran, 'the work must not run when the delegation was refused');
		$this->assertFalse($this->switched);
	}

	/**
	 * The refusal carries WHICH refusal, so a caller can route on it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testADenialIsNotReAskable(): void {
		$service = $this->service(
			DelegationVerdict::refused(DelegationVerdict::REASON_DENIED, 'the mayor said no.')
		);

		try {
			$service->runAsDelegated('alice', 'mayor', static fn (): string => 'done');
			$this->fail('a denied delegation must throw');
		} catch (DelegationRefused $e) {
			$this->assertFalse(
				$e->mayRequestConsent(),
				're-asking after a denial is how consent fatigue is manufactured'
			);
		}
	}

	/**
	 * A never-asked refusal IS askable — the control for the test above.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testANeverAskedRefusalIsAskable(): void {
		$service = $this->service(
			DelegationVerdict::refused(DelegationVerdict::REASON_NONE, 'nobody was asked.')
		);

		try {
			$service->runAsDelegated('alice', 'mayor', static fn (): string => 'done');
			$this->fail('an ungranted delegation must throw');
		} catch (DelegationRefused $e) {
			$this->assertTrue($e->mayRequestConsent());
		}
	}

	/**
	 * 🔴 A live grant naming an account that no longer resolves REFUSES.
	 *
	 * The one case where falling back is genuinely tempting, because a grant
	 * plainly exists and plainly says yes. Running as the principal instead would
	 * execute the work under an identity nobody authorised while the record said
	 * otherwise — a substitution invisible to every green test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testALiveGrantNamingAGhostAccountIsRefused(): void {
		$this->resolvable = null;
		$ran = false;

		$service = $this->service(DelegationVerdict::granted($this->grant()));

		try {
			$service->runAsDelegated(
				'alice',
				'mayor',
				static function () use (&$ran): string {
					$ran = true;

					return 'done';
				}
			);
			$this->fail('a grant naming no account must not permit the work');
		} catch (DelegationRefused $e) {
			$this->assertSame(DelegationVerdict::REASON_UNNAMED, $e->getVerdict()->reason);
		}

		$this->assertFalse($ran);
		$this->assertFalse($this->switched, 'nothing may run when the identity does not resolve');
	}

	/**
	 * `verdictFor()` answers without performing the work.
	 *
	 * Save-time validation needs the answer and not the side effect.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testVerdictForAnswersWithoutRunningAnything(): void {
		$verdict = $this->service(DelegationVerdict::granted($this->grant()))
			->verdictFor('alice', 'mayor', [], new DateTime('2026-08-25 12:00:00'));

		$this->assertTrue($verdict->permitted);
		$this->assertFalse($this->switched);
	}
}
