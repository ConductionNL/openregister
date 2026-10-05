<?php

/**
 * The per-organisation halt: the record, the flow oversight check, and the read an app asks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Oversight;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Listener\FlowOversightRegistrationListener;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\Oversight\KillSwitchCheck;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltCheck;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltService;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * hermiq H1: stop one organisation's agent work, and nothing else.
 */
class OrganisationHaltTest extends TestCase {

	private const ORG_A = 'org-a-uuid';

	private const ORG_B = 'org-b-uuid';

	/** The app config value store, in memory. */
	private array $config = [];

	/** @var array<int, array{action: string, user: string, changed: array}> */
	private array $rows = [];

	private ?IUser $actor = null;

	private IAppConfig $appConfig;

	/**
	 * The service over an in-memory app config.
	 *
	 * @return OrganisationHaltService
	 */
	private function service(): OrganisationHaltService {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$app . '/' . $key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$app . '/' . $key] = $value;
				return true;
			}
		);
		$this->appConfig->method('getValueBool')->willReturn(false);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				foreach ($entries as $entry) {
					$this->rows[] = ['action' => $entry->getAction(), 'user' => $entry->getUser(), 'changed' => $entry->getChanged()];
				}

				return $entries;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->actor);

		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Organisation {
				if (in_array($uuid, [self::ORG_A, self::ORG_B], true) === false) {
					throw new DoesNotExistException('no organisation ' . $uuid);
				}

				$organisation = new Organisation();
				$organisation->setUuid($uuid);
				return $organisation;
			}
		);

		return new OrganisationHaltService(
			appConfig: $this->appConfig,
			audit: $audit,
			session: $session,
			organisations: $organisations,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * The oversight registry with OpenRegister's own checks registered by the real listener.
	 *
	 * @param OrganisationHaltService $halts The halts.
	 * @param array<string, string>   $runs  Run uuid => organisation.
	 *
	 * @return FlowOversightRegistry
	 */
	private function registry(OrganisationHaltService $halts, array $runs): FlowOversightRegistry {
		$runMapper = $this->createMock(FlowRunMapper::class);
		$runMapper->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($runs): FlowRun {
				if (isset($runs[$uuid]) === false) {
					throw new DoesNotExistException('no run ' . $uuid);
				}

				$run = new FlowRun();
				$run->setUuid($uuid);
				$run->setOrganisation($runs[$uuid]);
				return $run;
			}
		);

		$listener = new FlowOversightRegistrationListener(
			killSwitch: new KillSwitchCheck(appConfig: $this->appConfig),
			organisationHalt: new OrganisationHaltCheck(halts: $halts, runs: $runMapper)
		);

		$dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function ($event) use ($listener): void {
				$listener->handle($event);
			}
		);

		return new FlowOversightRegistry(dispatcher: $dispatcher, logger: $this->createMock(LoggerInterface::class));
	}//end registry()

	/**
	 * An administrator.
	 *
	 * @return IUser
	 */
	private function signIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ops-admin');
		$user->method('getDisplayName')->willReturn('Ops Admin');
		$this->actor = $user;
		return $user;
	}//end signIn()

	/**
	 * Engaged for organisation A and hermiq: A's hermiq steps stop; B's, and A's other apps', run.
	 *
	 * @return void
	 */
	public function testAHaltStopsOnlyThatOrganisationsMatchingFlowSteps(): void {
		$halts = $this->service();
		$this->signIn();
		$halt = $halts->engage(organisation: self::ORG_A, app: 'hermiq', reason: 'Incident 42: agent sent mail it should not');

		$this->assertSame(self::ORG_A, $halt['organisation']);
		$this->assertSame('hermiq', $halt['app']);
		$this->assertSame('hermiq.', $halt['nodeTypePrefix']);
		$this->assertSame('ops-admin', $halt['engagedBy']);
		$this->assertNotSame('', $halt['engagedAt']);

		$registry = $this->registry(halts: $halts, runs: ['run-a' => self::ORG_A, 'run-b' => self::ORG_B]);

		$refusal = $registry->firstRefusal(context: ['runUuid' => 'run-a', 'nodeType' => 'hermiq.agent-tick']);
		$this->assertNotNull($refusal);
		$this->assertSame('openregister.organisation-halt', $refusal['checkId']);
		$this->assertStringContainsString('Incident 42', $refusal['reason']);

		$this->assertNull($registry->firstRefusal(context: ['runUuid' => 'run-b', 'nodeType' => 'hermiq.agent-tick']));
		$this->assertNull($registry->firstRefusal(context: ['runUuid' => 'run-a', 'nodeType' => 'openregister.http']));
	}//end testAHaltStopsOnlyThatOrganisationsMatchingFlowSteps()

	/**
	 * A narrower node-type prefix stops only those steps.
	 *
	 * @return void
	 */
	public function testANodeTypePrefixNarrowsTheHalt(): void {
		$halts = $this->service();
		$this->signIn();
		$halts->engage(organisation: self::ORG_A, app: 'hermiq', reason: 'mail agent only', nodeTypePrefix: 'hermiq.mail');

		$registry = $this->registry(halts: $halts, runs: ['run-a' => self::ORG_A]);
		$this->assertNotNull($registry->firstRefusal(context: ['runUuid' => 'run-a', 'nodeType' => 'hermiq.mail-send']));
		$this->assertNull($registry->firstRefusal(context: ['runUuid' => 'run-a', 'nodeType' => 'hermiq.summarise']));
	}//end testANodeTypePrefixNarrowsTheHalt()

	/**
	 * A run whose organisation cannot be established is refused while a matching halt exists.
	 *
	 * @return void
	 */
	public function testAnUnattributedRunIsRefusedWhileAMatchingHaltExists(): void {
		$halts = $this->service();
		$this->signIn();
		$halts->engage(organisation: self::ORG_A, app: 'hermiq', reason: 'incident');

		$registry = $this->registry(halts: $halts, runs: []);
		$this->assertNotNull($registry->firstRefusal(context: ['runUuid' => 'run-unknown', 'nodeType' => 'hermiq.agent-tick']));
		$this->assertNull($registry->firstRefusal(context: ['runUuid' => 'run-unknown', 'nodeType' => 'openregister.http']));
	}//end testAnUnattributedRunIsRefusedWhileAMatchingHaltExists()

	/**
	 * The read an app asks outside flows, and release.
	 *
	 * @return void
	 */
	public function testTheReadAnAppAsksAndRelease(): void {
		$halts = $this->service();
		$this->signIn();
		$halt = $halts->engage(organisation: self::ORG_A, app: 'hermiq', reason: 'incident');

		$this->assertSame($halt['id'], $halts->haltFor(organisation: self::ORG_A, app: 'hermiq')['id']);
		$this->assertNotNull($halts->haltFor(organisation: self::ORG_A, app: 'hermiq', nodeType: 'hermiq.agent-tick'));
		$this->assertNull($halts->haltFor(organisation: self::ORG_B, app: 'hermiq'));
		$this->assertNull($halts->haltFor(organisation: self::ORG_A, app: 'learniq'));

		$released = $halts->release(id: $halt['id']);
		$this->assertTrue($released);
		$this->assertNull($halts->haltFor(organisation: self::ORG_A, app: 'hermiq'));
		$this->assertSame([], $halts->list());

		$this->assertSame(
			[OrganisationHaltService::ACTION_ENGAGED, OrganisationHaltService::ACTION_RELEASED],
			array_column($this->rows, 'action')
		);
		$this->assertSame(['ops-admin', 'ops-admin'], array_column($this->rows, 'user'));
		$this->assertSame('incident', $this->rows[0]['changed']['reason']);
	}//end testTheReadAnAppAsksAndRelease()

	/**
	 * No actor, no reason, no app, an unknown organisation or a second identical halt: refused.
	 *
	 * @return void
	 */
	public function testEngageRefusesWhatItCannotRecordProperly(): void {
		$halts = $this->service();

		try {
			$halts->engage(organisation: self::ORG_A, app: 'hermiq', reason: 'no actor');
			$this->fail('engaged without an actor');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('actor', $e->getMessage());
		}

		$this->signIn();
		foreach ([
			[self::ORG_A, 'hermiq', '  ', 'reason'],
			[self::ORG_A, '', 'reason', 'app'],
			['org-nobody', 'hermiq', 'reason', 'organisation'],
		] as [$organisation, $app, $reason, $named]) {
			try {
				$halts->engage(organisation: $organisation, app: $app, reason: $reason);
				$this->fail('engaged without ' . $named);
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString($named, $e->getMessage());
			}
		}

		$halts->engage(organisation: self::ORG_A, app: 'hermiq', reason: 'first');
		try {
			$halts->engage(organisation: self::ORG_A, app: 'hermiq', reason: 'second');
			$this->fail('engaged twice');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('already', $e->getMessage());
		}

		$this->assertCount(1, $halts->list());
		$this->assertCount(1, $this->rows);
	}//end testEngageRefusesWhatItCannotRecordProperly()
}//end class
