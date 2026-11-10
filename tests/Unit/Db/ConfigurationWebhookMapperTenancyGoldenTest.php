<?php

/**
 * Pins the multi-tenancy outcomes of ConfigurationMapper and WebhookMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden: the organisation filter each caller gets from findAll().
 *
 * Covers the allowed side (admin, member of an organisation, multitenancy
 * switched off) and the refused side (member without an organisation fails
 * closed, no user). The queries are recorded in full, so a mapper that reads
 * the wrong user session, group manager or organisation mapper changes the log.
 */
final class ConfigurationWebhookMapperTenancyGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/configuration-webhook-mapper-tenancy-golden.json';

	/**
	 * The callers: user id (null for none), groups, active organisation.
	 *
	 * @return array<string, array{0: ?string, 1: array<int, string>, 2: ?string}>
	 */
	private static function callers(): array {
		return [
			'no user' => [null, [], null],
			'member with organisation' => ['alice', ['users'], 'org-a'],
			'member without organisation' => ['bob', ['users'], null],
			'admin' => ['root', ['admin'], 'org-a'],
		];
	}//end callers()

	/**
	 * Every caller against both mappers matches the recorded outcome.
	 *
	 * @return void
	 */
	public function testFindAllOrganisationFilterMatchesGolden(): void {
		$actual = [];
		foreach (self::callers() as $name => $caller) {
			$actual['configuration / ' . $name] = $this->outcomeOf(mapper: 'configuration', caller: $caller);
			$actual['webhook / ' . $name] = $this->outcomeOf(mapper: 'webhook', caller: $caller);
		}

		$actual['configuration / member with organisation, multitenancy off'] = $this->outcomeOf(mapper: 'configuration-off', caller: self::callers()['member with organisation']);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFindAllOrganisationFilterMatchesGolden()

	/**
	 * The member without an organisation is refused: the query matches no rows.
	 *
	 * @return void
	 */
	public function testMemberWithoutOrganisationIsRefused(): void {
		foreach (['configuration', 'webhook'] as $mapper) {
			$outcome = $this->outcomeOf(mapper: $mapper, caller: self::callers()['member without organisation']);
			$this->assertContains('#' . ($mapper === 'webhook' ? 2 : 1) . ' andWhere(1 = 0)', $outcome['queries'], $mapper);
		}
	}//end testMemberWithoutOrganisationIsRefused()

	/**
	 * Run findAll() for one caller and record the queries.
	 *
	 * @param string                                             $mapper Which mapper.
	 * @param array{0: ?string, 1: array<int, string>, 2: ?string} $caller The caller.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function outcomeOf(string $mapper, array $caller): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [[['id' => 1]], []];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		[$userSession, $groupManager, $organisations] = $this->tenancy(caller: $caller);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		if ($mapper === 'webhook') {
			$instance = new WebhookMapper($db, new TenancyServices($organisations, $userSession, $groupManager), $appConfig);
		} else {
			$instance = new ConfigurationMapper($db, new TenancyServices($organisations, $userSession, $groupManager), new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)));
		}

		$outcome = [];
		try {
			if ($mapper === 'configuration-off') {
				$outcome['result'] = count($instance->findAll(null, null, [], [], [], false));
			} else {
				$outcome['result'] = count($instance->findAll());
			}
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcomeOf()

	/**
	 * The user session, group manager and organisation mapper of one caller.
	 *
	 * @param array{0: ?string, 1: array<int, string>, 2: ?string} $caller The caller.
	 *
	 * @return array{0: IUserSession, 1: IGroupManager, 2: OrganisationMapper}
	 */
	private function tenancy(array $caller): array {
		[$uid, $groups, $organisation] = $caller;
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isAdmin')->willReturn(in_array('admin', $groups, true));
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn($organisation);
		$organisations->method('getOrganisationHierarchy')->willReturn($organisation === null ? [] : [$organisation]);

		return [$userSession, $groupManager, $organisations];
	}//end tenancy()
}//end class
