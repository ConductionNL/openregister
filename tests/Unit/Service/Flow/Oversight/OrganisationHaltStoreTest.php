<?php

/**
 * OrganisationHaltService against its stored list: releasing among several
 * halts, malformed stored config, lookups that skip other apps, and an audit
 * write that fails after the act.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Oversight;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Flow\Oversight\HaltLookup;
use OCA\OpenRegister\Service\Flow\Oversight\HaltRequest;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltRecords;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The halt list as stored, read back and changed.
 */
class OrganisationHaltStoreTest extends TestCase {

	/**
	 * The stored halt list, as the app config holds it.
	 *
	 * @var string
	 */
	private string $stored = '[]';

	/**
	 * Where the history goes.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $audit;

	/**
	 * Records an unrecorded act.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The session, with or without a user.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->audit   = $this->createMock(AuditTrailMapperInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * The service over an in-memory app config.
	 *
	 * @return OrganisationHaltService
	 */
	private function service(): OrganisationHaltService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('openregister', $app);
				$this->assertSame(OrganisationHaltService::CONFIG_KEY, $key);
				$this->stored = $value;

				return true;
			}
		);

		return new OrganisationHaltService(
			records: new OrganisationHaltRecords(appConfig: $config, audit: $this->audit),
			session: $this->session,
			organisations: $this->createMock(OrganisationMapperInterface::class),
			logger: $this->logger
		);
	}//end service()

	/**
	 * Log a user into the session.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('oversight');
		$user->method('getDisplayName')->willReturn('Oversight');
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Store the given halts.
	 *
	 * @param array<int, mixed> $halts The halts.
	 *
	 * @return void
	 */
	private function storeHalts(array $halts): void {
		$this->stored = (string)json_encode($halts);
	}//end storeHalts()

	/**
	 * A stored halt.
	 *
	 * @param string $id     The halt id.
	 * @param string $app    The halted app.
	 * @param string $prefix The halted node-type prefix.
	 *
	 * @return array<string, string>
	 */
	private static function halt(string $id, string $app, string $prefix): array {
		return ['id' => $id, 'organisation' => 'org-1', 'app' => $app, 'nodeTypePrefix' => $prefix, 'reason' => 'r'];
	}//end halt()

	/**
	 * Releasing needs an actor.
	 *
	 * @return void
	 */
	public function testReleasingWithoutASessionIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->audit->expects($this->never())->method('insertAuditTrails');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Releasing a halt needs an actor, and there is no session.');

		$this->service()->release('h-1');
	}//end testReleasingWithoutASessionIsRefused()

	/**
	 * Releasing an unknown id changes nothing and records nothing.
	 *
	 * @return void
	 */
	public function testReleasingAnUnknownIdIsFalseAndStoresNothing(): void {
		$this->signIn();
		$this->storeHalts([self::halt('h-1', 'app-a', 'app-a.')]);
		$before = $this->stored;
		$this->audit->expects($this->never())->method('insertAuditTrails');

		$this->assertFalse($this->service()->release('h-9'));
		$this->assertSame($before, $this->stored);
	}//end testReleasingAnUnknownIdIsFalseAndStoresNothing()

	/**
	 * Releasing one halt keeps the others and audits the released one.
	 *
	 * @return void
	 */
	public function testReleasingOneHaltKeepsTheOthers(): void {
		$this->signIn();
		$this->storeHalts([self::halt('h-1', 'app-a', 'app-a.'), self::halt('h-2', 'app-b', 'app-b.')]);
		$this->audit->expects($this->once())
			->method('insertAuditTrails')
			->with(
				$this->callback(
					static fn (array $rows): bool => $rows[0] instanceof AuditTrail
						&& $rows[0]->getAction() === OrganisationHaltService::ACTION_RELEASED
						&& $rows[0]->getChanged()['id'] === 'h-1'
						&& $rows[0]->getUser() === 'oversight'
				)
			)
			->willReturn([]);

		$service = $this->service();

		$this->assertTrue($service->release('h-1'));
		$this->assertSame(['h-2'], array_column($service->list(), 'id'));
	}//end testReleasingOneHaltKeepsTheOthers()

	/**
	 * Stored config that is not a JSON list reads as no halts.
	 *
	 * @return void
	 */
	public function testUnreadableStoredConfigIsNoHalts(): void {
		$this->stored = 'not json';

		$this->assertSame([], $this->service()->list());
	}//end testUnreadableStoredConfigIsNoHalts()

	/**
	 * Malformed entries are skipped, and the rest come back as strings.
	 *
	 * @return void
	 */
	public function testMalformedEntriesAreSkipped(): void {
		$this->storeHalts(['junk', ['id' => 'h-0', 'app' => 'x'], self::halt('h-1', 'app-a', 'app-a.')]);

		$halts = $this->service()->list();

		$this->assertSame(['h-1'], array_column($halts, 'id'));
	}//end testMalformedEntriesAreSkipped()

	/**
	 * A lookup skips a halt on another app and finds the one on its own.
	 *
	 * @return void
	 */
	public function testALookupSkipsHaltsOnOtherApps(): void {
		$this->storeHalts([self::halt('h-1', 'app-a', 'app-a.'), self::halt('h-2', 'app-b', 'app-b.')]);
		$service = $this->service();

		$this->assertSame('h-2', $service->haltFor(new HaltLookup(organisation: 'org-1', app: 'app-b'))['id']);
		$this->assertNull($service->haltFor(new HaltLookup(organisation: 'org-1', app: 'app-c')));
		$this->assertSame('h-1', $service->haltFor(new HaltLookup(organisation: 'org-1', app: 'app-a', nodeType: 'app-a.send'))['id']);
		$this->assertNull(
			$service->haltFor(new HaltLookup(organisation: 'org-1', app: 'app-a', nodeType: 'other.send')),
			'a node type outside the halted prefix is not halted'
		);
	}//end testALookupSkipsHaltsOnOtherApps()

	/**
	 * An audit write that fails is logged; the halt is engaged all the same.
	 *
	 * @return void
	 */
	public function testAFailedAuditWriteIsLoggedAndTheHaltStands(): void {
		$this->signIn();
		$this->audit->method('insertAuditTrails')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('error')
			->with('[OrganisationHalt] the act happened but was not recorded: db down');

		$service = $this->service();
		$halt    = $service->engage(new HaltRequest(organisation: 'org-1', app: 'app-a', reason: 'incident'));

		$this->assertSame('app-a.', $halt['nodeTypePrefix']);
		$this->assertSame([$halt['id']], array_column($service->list(), 'id'));
	}//end testAFailedAuditWriteIsLoggedAndTheHaltStands()
}//end class
