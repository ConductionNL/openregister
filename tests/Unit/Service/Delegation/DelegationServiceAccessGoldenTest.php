<?php

/**
 * Access golden test for {@see \OCA\OpenRegister\Service\Delegation\DelegationService::runAsDelegated()}.
 *
 * Pins, on the code before it was split, which identity the work runs under
 * (allowed) and that it never runs at all (refused), together with what the
 * resolver is asked and what is logged at which level.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Delegation;

use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationRequest;
use OCA\OpenRegister\Service\Delegation\DelegationResolver;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * DelegationServiceAccessGoldenTest.
 */
final class DelegationServiceAccessGoldenTest extends TestCase {

	/**
	 * Every resolver call, by argument name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $resolved = [];

	/**
	 * Every log line, as [level, message, context without file/line].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * The uids runAs switched to.
	 *
	 * @var array<int, string>
	 */
	private array $switchedTo = [];

	/**
	 * Whether the operation ran.
	 *
	 * @var boolean
	 */
	private bool $ran = false;

	public function testAllowedRunsTheWorkOnceAsTheActedAsUser(): void {
		$grant = $this->grant();
		$now = new DateTime('2026-01-01T00:00:00+00:00');

		$result = $this->service(DelegationVerdict::granted($grant))
			->runAsDelegated(new DelegationRequest(principal: 'alice', actingAs: 'mayor', scope: ['schema' => 'zaak'], now: $now), $this->operation());

		$this->assertSame('done', $result);
		$this->assertTrue($this->ran);
		$this->assertSame(['mayor'], $this->switchedTo);
		$this->assertSame([['principal' => 'alice', 'actingAs' => 'mayor', 'now' => $now, 'scope' => ['schema' => 'zaak']]], $this->resolved);
		$this->assertSame(
			[
				[
					'info',
					'[DelegationService] Acting on behalf of another user',
					[
						'principal' => 'alice',
						'actingAs' => 'mayor',
						'grantId' => 41,
						'grantedBy' => 'mayor',
						'statedReason' => 'holiday cover',
						'scope' => ['schema' => 'zaak'],
					],
				],
			],
			$this->logged
		);
	}

	public function testAllowedSelfRunsWithoutSwitchOrLog(): void {
		$result = $this->service(DelegationVerdict::self())->runAsDelegated(new DelegationRequest(principal: 'alice', actingAs: 'alice', scope: [], now: new DateTime()), $this->operation());

		$this->assertSame('done', $result);
		$this->assertTrue($this->ran);
		$this->assertSame([], $this->switchedTo);
		$this->assertSame([], $this->logged);
		$this->assertInstanceOf(DateTime::class, $this->resolved[0]['now']);
		$this->assertSame([], $this->resolved[0]['scope']);
	}

	public function testRefusedNeverRunsAndLogsAWarning(): void {
		try {
			$this->service(DelegationVerdict::refused(DelegationVerdict::REASON_EXPIRED, 'the grant lapsed.'))
				->runAsDelegated(new DelegationRequest(principal: 'alice', actingAs: 'mayor', scope: ['schema' => 'zaak'], now: new DateTime()), $this->operation());
			$this->fail('a refused delegation must throw');
		} catch (DelegationRefused $e) {
			$this->assertSame(DelegationVerdict::REASON_EXPIRED, $e->getVerdict()->reason);
			$this->assertSame(['alice', 'mayor'], [$e->getPrincipal(), $e->getActingAs()]);
		}

		$this->assertFalse($this->ran);
		$this->assertSame([], $this->switchedTo);
		$this->assertSame(
			[
				[
					'warning',
					'[DelegationService] Delegation refused',
					['principal' => 'alice', 'actingAs' => 'mayor', 'reason' => DelegationVerdict::REASON_EXPIRED, 'scope' => ['schema' => 'zaak']],
				],
			],
			$this->logged
		);
	}

	public function testRefusedWhenTheGrantedAccountDoesNotResolve(): void {
		try {
			$this->service(DelegationVerdict::granted($this->grant()), resolvable: false)
				->runAsDelegated(new DelegationRequest(principal: 'alice', actingAs: 'mayor', scope: [], now: new DateTime()), $this->operation());
			$this->fail('an unresolvable acted-as account must be refused');
		} catch (DelegationRefused $e) {
			$this->assertFalse($e->getVerdict()->permitted);
			$this->assertSame(DelegationVerdict::REASON_UNNAMED, $e->getVerdict()->reason);
			$this->assertSame('"mayor" resolves to no account, so it cannot be acted as.', $e->getVerdict()->detail);
			$this->assertSame(['alice', 'mayor'], [$e->getPrincipal(), $e->getActingAs()]);
		}

		$this->assertFalse($this->ran);
		$this->assertSame([], $this->switchedTo);
		$this->assertSame(
			[
				[
					'error',
					'[DelegationService] A live grant names an account that does not resolve',
					['principal' => 'alice', 'actingAs' => 'mayor', 'grantId' => 41],
				],
			],
			$this->logged
		);
	}

	/**
	 * The operation under test; records that it ran.
	 *
	 * @return callable
	 */
	private function operation(): callable {
		return function (): string {
			$this->ran = true;

			return 'done';
		};
	}

	/**
	 * A granted grant from mayor to alice.
	 *
	 * @return DelegationGrant
	 */
	private function grant(): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setId(41);
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');
		$grant->setGrantedBy('mayor');
		$grant->setReason('holiday cover');
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);

		return $grant;
	}

	/**
	 * The service over a resolver answering one verdict.
	 *
	 * @param DelegationVerdict $verdict    The verdict.
	 * @param bool              $resolvable Whether the acted-as uid resolves to an account.
	 *
	 * @return DelegationService
	 */
	private function service(DelegationVerdict $verdict, bool $resolvable = true): DelegationService {
		$resolver = $this->createMock(DelegationResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			function (DelegationRequest $request) use ($verdict): DelegationVerdict {
				$this->resolved[] = ['principal' => $request->principal, 'actingAs' => $request->actingAs, 'now' => $request->now, 'scope' => $request->scope];

				return $verdict;
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			function (string $uid) use ($resolvable): ?IUser {
				if ($resolvable === false) {
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
				$this->switchedTo[] = $user->getUID();

				return $operation();
			}
		);

		$logger = new class($this->logged) extends AbstractLogger {
			/**
			 * Bind the sink.
			 *
			 * @param array<int, array<int, mixed>> $sink The log lines.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->sink[] = [$level, (string)$message, $context];
			}
		};

		return new DelegationService($resolver, $userManager, $objectService, $logger);
	}
}//end class
