<?php

/**
 * The dependency console: the list of breakers with state, and the audited reset.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Resilience;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Audit\AuditEntryWriter;
use OCA\OpenRegister\Service\Audit\OperatorActionAudit;
use OCA\OpenRegister\Service\Resilience\BreakerRegistry;
use OCA\OpenRegister\Service\Resilience\BreakerThresholds;
use OCA\OpenRegister\Service\Resilience\CircuitBreaker;
use OCA\OpenRegister\Service\Resilience\DatabasePressureMeter;
use OCA\OpenRegister\Service\Resilience\DependencyConsole;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/FakeMemcache.php';

/**
 * @covers \OCA\OpenRegister\Service\Resilience\DependencyConsole
 * @uses   \OCA\OpenRegister\Service\Resilience\CircuitBreaker
 * @uses   \OCA\OpenRegister\Service\Resilience\BreakerThresholds
 * @uses   \OCA\OpenRegister\Service\Resilience\DatabasePressureMeter
 * @uses   \OCA\OpenRegister\Service\Resilience\DependencyUnavailableException
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Service\Audit\AuditEntryWriter
 * @uses   \OCA\OpenRegister\Service\Audit\OperatorActionAudit
 */
class DependencyConsoleTest extends TestCase {

	private int $now = 1_000_000;

	private CircuitBreaker $breaker;

	/** @var list<AuditTrail> */
	private array $audited = [];

	private bool $auditFails = false;

	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->breaker = new CircuitBreaker(cache: new FakeMemcache(), thresholds: new BreakerThresholds(), clock: $clock, logger: new NullLogger(), shared: true);
	}

	private function console(?LoggerInterface $logger = null): DependencyConsole {
		$registry = $this->createMock(BreakerRegistry::class);
		$registry->method('breaker')->willReturn($this->breaker);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default): int => $default);
		$appConfig->method('getValueBool')->willReturn(true);
		$factory = $this->createStub(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn(new FakeMemcache());
		$pressure = new DatabasePressureMeter(cacheFactory: $factory, appConfig: $appConfig, clock: $clock, logger: new NullLogger());

		$auditTrails = $this->createMock(AuditTrailMapperInterface::class);
		$auditTrails->method('insertAuditTrails')->willReturnCallback(function (array $entries): array {
			if ($this->auditFails === true) {
				throw new RuntimeException('database down');
			}

			$this->audited = array_merge($this->audited, $entries);
			return $entries;
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$user->method('getDisplayName')->willReturn('Functional Administrator');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DependencyConsole(breakers: $registry, pressure: $pressure, audit: new OperatorActionAudit(entries: new AuditEntryWriter(mapper: $auditTrails), userSession: $session), logger: $logger ?? new NullLogger());
	}

	private function open(string $key): void {
		for ($i = 0; $i < 10; $i++) {
			$this->breaker->recordFailure($key);
		}
	}

	public function testTheOverviewListsBreakersWithStateSortedByKey(): void {
		$this->open('source:3');
		$this->breaker->recordFailure('llm');

		$overview = $this->console()->overview();

		$this->assertSame(['llm', 'source:3'], array_column($overview['dependencies'], 'key'));
		$this->assertSame('closed', $overview['dependencies'][0]['state']);
		$this->assertSame(1, $overview['dependencies'][0]['failures']);
		$this->assertSame('open', $overview['dependencies'][1]['state']);
		$this->assertSame('source', $overview['dependencies'][1]['dependency']);
		$this->assertSame(1_000_030, $overview['dependencies'][1]['nextProbeAt']);
		$this->assertTrue($overview['shared']);
		$this->assertFalse($overview['pressure']['underPressure']);
	}

	public function testABreakerWhoseWindowHasPassedIsNoLongerListed(): void {
		$this->breaker->recordFailure('llm');
		$this->now += 120;

		$this->assertSame([], $this->console()->overview()['dependencies']);
	}

	public function testResetClosesTheBreakerAndAuditsTheAdministrator(): void {
		$this->open('llm');

		$state = $this->console()->reset('llm');

		$this->assertSame('closed', $state['state']);
		$this->assertSame('closed', $this->breaker->state('llm')['state']);
		$this->assertCount(1, $this->audited);
		$row = $this->audited[0];
		$this->assertSame('dependency.reset', $row->getAction());
		$this->assertSame('admin', $row->getUser());
		$this->assertSame('Functional Administrator', $row->getUserName());
		$this->assertSame(['dependency' => 'llm', 'key' => 'llm', 'state' => ['old' => 'open', 'new' => 'closed']], $row->getChanged());
		$this->assertNotEmpty($row->getUuid());
	}

	public function testAnUnknownKeyIsNotResetAndNotAudited(): void {
		$this->assertNull($this->console()->reset('llm'));
		$this->assertSame([], $this->audited);
	}

	public function testAFailedAuditWriteIsLoggedAndTheResetStands(): void {
		$this->open('llm');
		$this->auditFails = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('not audited'));

		$state = $this->console(logger: $logger)->reset('llm');

		$this->assertSame('closed', $state['state']);
	}
}
