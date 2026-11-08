<?php

declare(strict_types=1);

/**
 * CacheHandler name-visibility golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, the
 * allowed AND refused outcomes of the name-visibility decision when one call
 * carries every kind of candidate at once, plus the per-caller memo of the
 * read-path answers and the multitenancy-switch table. Existing policy suites
 * pin each arm in isolation; this one pins how the arms combine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Golden outcomes of CacheHandler::visibleNames() and the multitenancy switches.
 */
class CacheHandlerNameVisibilityGoldenTest extends TestCase {
	private const ORG_IN  = 'aaaaaaaa-0000-0000-0000-000000000001';
	private const ORG_OUT = 'bbbbbbbb-0000-0000-0000-000000000002';

	/** @var MagicMapper */
	private MagicMapper $objectMapper;

	/** @var OrganisationMapper */
	private OrganisationMapper $organisationMapper;

	/** @var IUserSession */
	private IUserSession $userSession;

	/** @var string The current caller's uid. */
	private string $uid = 'alice';

	/** @var list<array{int, int, list<string>}> Every question put to the read path. */
	private array $oracleCalls = [];

	/** @var bool Whether the read path fails for register 9. */
	private bool $failRegisterNine = true;

	/**
	 * Set up the doubles: the read path admits UUIDs ending in "r" only.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->objectMapper->method('filterReadableUuids')->willReturnCallback(
			function (int $registerId, int $schemaId, array $uuids): array {
				$this->oracleCalls[] = [$registerId, $schemaId, array_values($uuids)];
				if ($registerId === 9 && $this->failRegisterNine === true) {
					throw new \RuntimeException('read path down');
				}

				return array_values(array_filter($uuids, fn (string $uuid): bool => str_ends_with($uuid, 'r')));
			}
		);

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_IN);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn([self::ORG_IN]);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturnCallback(
			function (): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);
	}//end setUp()

	/**
	 * Build the handler under test.
	 *
	 * @param IAppConfig|null    $appConfig    Optional app config
	 * @param IGroupManager|null $groupManager Optional group manager
	 *
	 * @return CacheHandler
	 */
	private function handler(?IAppConfig $appConfig = null, ?IGroupManager $groupManager = null): CacheHandler {
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturn($this->objectMapper);

		return new CacheHandler(
			$this->organisationMapper,
			$this->createMock(LoggerInterface::class),
			null,
			$this->userSession,
			$container,
			null,
			null,
			null,
			$groupManager,
			$appConfig
		);
	}//end handler()

	/**
	 * Seed the in-memory name cache.
	 *
	 * @param CacheHandler                                  $handler The handler
	 * @param array<string, array{string, string|null, string|null}> $entries key => [name, org, source]
	 *
	 * @return void
	 */
	private function seed(CacheHandler $handler, array $entries): void {
		$ref = new ReflectionClass($handler);
		$names = [];
		$orgs = [];
		$sources = [];
		foreach ($entries as $key => $entry) {
			$names[$key] = $entry[0];
			$orgs[$key] = $entry[1];
			if ($entry[2] !== null) {
				$sources[$key] = $entry[2];
			}
		}

		$ref->getProperty('nameCache')->setValue($handler, $names);
		$ref->getProperty('nameOrganisations')->setValue($handler, $orgs);
		$ref->getProperty('nameSources')->setValue($handler, $sources);
	}//end seed()

	/**
	 * Call a private method.
	 *
	 * @param CacheHandler $handler The handler
	 * @param string       $method  The method name
	 * @param array        $args    Positional arguments
	 *
	 * @return mixed
	 */
	private function call(CacheHandler $handler, string $method, array $args = []): mixed {
		$ref = new ReflectionClass($handler);
		if ($method === 'visibleNames' || $method === 'resolveNameScope') {
			$ref->getMethod('beginNameScope')->invoke($handler);
		}

		return $ref->getMethod($method)->invokeArgs($handler, $args);
	}//end call()

	/**
	 * One call with every kind of candidate: allowed and refused outcomes together.
	 *
	 * @return void
	 */
	public function testMixedCandidatesYieldExactlyTheAllowedSubset(): void {
		$handler = $this->handler();
		$candidates = [
			'org-in'      => 'Org In',
			'org-out'     => 'Org Out',
			'org-unknown' => 'Org Unknown',
			'sourceless'  => 'No Source',
			'u1r'         => 'Readable 1',
			'u2x'         => 'Unreadable 2',
			'u3r'         => 'Readable 3 other table',
			'u4r'         => 'Readable but read path down',
		];
		$this->seed(
			$handler,
			[
				'org-in'      => ['Org In', self::ORG_IN, CacheHandler::NAME_SOURCE_ORGANISATION],
				'org-out'     => ['Org Out', self::ORG_OUT, CacheHandler::NAME_SOURCE_ORGANISATION],
				'org-unknown' => ['Org Unknown', null, CacheHandler::NAME_SOURCE_ORGANISATION],
				'sourceless'  => ['No Source', self::ORG_IN, null],
				'u1r'         => ['Readable 1', self::ORG_OUT, '1:5'],
				'u2x'         => ['Unreadable 2', self::ORG_IN, '1:5'],
				'u3r'         => ['Readable 3 other table', null, '2:7'],
				'u4r'         => ['Readable but read path down', self::ORG_IN, '9:9'],
			]
		);

		$visible = $this->call($handler, 'visibleNames', [$candidates]);

		$this->assertSame(
			['org-in' => 'Org In', 'u1r' => 'Readable 1', 'u3r' => 'Readable 3 other table'],
			$visible
		);
		$this->assertSame(
			[[1, 5, ['u1r', 'u2x']], [2, 7, ['u3r']], [9, 9, ['u4r']]],
			$this->oracleCalls
		);
	}//end testMixedCandidatesYieldExactlyTheAllowedSubset()

	/**
	 * The read-path answers are remembered per caller, refusals included.
	 *
	 * @return void
	 */
	public function testReadPathAnswersAreMemoisedPerCaller(): void {
		$handler = $this->handler();
		$candidates = ['u1r' => 'Readable', 'u2x' => 'Hidden', 'u4r' => 'Down'];
		$this->seed(
			$handler,
			[
				'u1r' => ['Readable', null, '1:5'],
				'u2x' => ['Hidden', null, '1:5'],
				'u4r' => ['Down', null, '9:9'],
			]
		);

		$first = $this->call($handler, 'visibleNames', [$candidates]);
		$this->failRegisterNine = false;
		$second = $this->call($handler, 'visibleNames', [$candidates]);

		$this->assertSame(['u1r' => 'Readable'], $first);
		$this->assertSame($first, $second, 'A remembered refusal (even from a failure) stays refused for that caller.');
		$this->assertCount(2, $this->oracleCalls, 'The same caller is not asked twice.');

		$this->uid = 'bob';
		$third = $this->call($handler, 'visibleNames', [$candidates]);
		$this->assertSame(['u1r' => 'Readable', 'u4r' => 'Down'], $third);
		$this->assertCount(4, $this->oracleCalls, 'Another caller is asked afresh.');
	}//end testReadPathAnswersAreMemoisedPerCaller()

	/**
	 * Multitenancy switch and admin-override table, allowed and refused.
	 *
	 * @return void
	 */
	public function testMultitenancySwitchTable(): void {
		$cases = [
			'{"enabled":false}'                                  => [true, false, false],
			'{"enabled":true}'                                   => [false, false, false],
			'{}'                                                 => [false, false, false],
			'[]'                                                 => [false, false, false],
			'"enabled"'                                          => [false, false, false],
			''                                                   => [false, false, false],
			'{"adminOverride":true}'                             => [false, true, false],
			'{"adminOverride":true,"saasMode":true}'             => [false, false, false],
			'{"adminOverride":"1"}'                              => [false, false, false],
			'{"adminOverride":true,"saasMode":"yes"}'            => [false, true, false],
			'{"enabled":0}'                                      => [false, false, false],
		];
		foreach ($cases as $json => [$disabled, $overrideForAdmin, $overrideForUser]) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn($json);
			$groupManager = $this->createMock(IGroupManager::class);
			$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'root');

			$this->uid = 'root';
			$handler = $this->handler($appConfig, $groupManager);
			$this->assertSame($disabled, $this->call($handler, 'isMultiTenancyDisabled'), "disabled for $json");
			$this->assertSame($overrideForAdmin, $this->call($handler, 'isAdminOverrideActive'), "admin override for $json");

			$this->uid = 'alice';
			$this->assertSame($overrideForUser, $this->call($handler, 'isAdminOverrideActive'), "user override for $json");
		}

		// Missing collaborators refuse the override and keep filtering on.
		$this->uid = 'root';
		$this->assertFalse($this->call($this->handler(), 'isMultiTenancyDisabled'));
		$this->assertFalse($this->call($this->handler(), 'isAdminOverrideActive'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"adminOverride":true}');
		$this->assertFalse($this->call($this->handler($appConfig, null), 'isAdminOverrideActive'));
	}//end testMultitenancySwitchTable()

	/**
	 * The resolved scope: hierarchy, unrestricted, empty, and failure.
	 *
	 * @return void
	 */
	public function testNameScopeOutcomes(): void {
		$this->assertSame([self::ORG_IN], $this->call($this->handler(), 'resolveNameScope'));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"enabled":false}');
		$this->assertNull($this->call($this->handler($appConfig), 'resolveNameScope'));

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn(self::ORG_IN);
		$this->organisationMapper->method('getOrganisationHierarchy')->willReturn(['', self::ORG_OUT, 7]);
		$this->assertSame([self::ORG_OUT, '7'], $this->call($this->handler(), 'resolveNameScope'));

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn('');
		$this->assertSame([], $this->call($this->handler(), 'resolveNameScope'));

		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willThrowException(new \RuntimeException('x'));
		$this->assertSame([], $this->call($this->handler(), 'resolveNameScope'));
	}//end testNameScopeOutcomes()
}//end class
