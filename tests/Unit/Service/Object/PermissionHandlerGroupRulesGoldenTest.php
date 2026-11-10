<?php

/**
 * PermissionHandler::hasGroupPermission() answers every input as recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks authorization blocks against callers, owners, object data, the default-closed flag and the token ceiling.
 *
 * Every combination's verdict, condition-matcher calls and warnings feed one hash per block, action and group.
 * The recording was made on the code before hasGroupPermission() was split.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantNarrower
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses   \OCA\OpenRegister\Service\Object\GroupRuleEntries
 */
class PermissionHandlerGroupRulesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/permission-handler/group-rules-golden.json';

	private const ACTIONS = ['read', 'create', 'update'];

	private const GROUPS = ['editors', 'public', 'admin', 'mcp', 'user:alice'];

	private const USERS = [null, '', 'alice', 'bob'];

	private const USER_GROUPS = [null, 'admin', 'editors'];

	private const OWNERS = [null, 'alice'];

	private const OBJECT_DATA = [null, ['title' => 'x', '@self' => ['organisation' => 'body-org', 'owner' => 'o']]];

	private const ORGANISATIONS = [null, 'row-org'];

	/**
	 * What the condition matcher was asked during the current case.
	 *
	 * @var array<int, array>
	 */
	private array $matcherCalls = [];

	/**
	 * What was logged during the current case.
	 *
	 * @var array<int, array>
	 */
	private array $warnings = [];

	private bool $defaultClosed = false;

	/**
	 * The rule lists under test, each applied to every action.
	 *
	 * @return array<string, array|null>
	 */
	private static function ruleLists(): array {
		$pass = ['pass' => true];
		$fail = ['pass' => false];
		$org  = ['_organisation' => '$organisation'];

		return [
			'string group'      => ['editors'],
			'string public'     => ['public'],
			'string mcp'        => ['mcp'],
			'group mcp'         => [['group' => 'mcp']],
			'mcp then group'    => ['mcp', ['group' => 'mcp', 'match' => $pass], 'editors'],
			'user string'       => ['user:alice'],
			'user blank'        => ['user:'],
			'tail equals a uid' => ['groupalice'],
			'user complex'      => [['user' => 'alice']],
			'user blank uid'    => [['user' => '']],
			'user int uid'      => [['user' => 7]],
			'user match pass'   => [['user' => 'alice', 'match' => $pass]],
			'user match fail'   => [['user' => 'alice', 'match' => $fail]],
			'user match org'    => [['user' => 'alice', 'match' => $org]],
			'user org and fail' => [['user' => 'alice', 'match' => array_merge($org, $fail)]],
			'user match empty'  => [['user' => 'alice', 'match' => []]],
			'user match scalar' => [['user' => 'alice', 'match' => 'x']],
			'group plain'       => [['group' => 'editors']],
			'group match empty' => [['group' => 'editors', 'match' => []]],
			'group match pass'  => [['group' => 'editors', 'match' => $pass]],
			'group match fail'  => [['group' => 'editors', 'match' => $fail]],
			'group fail, pass'  => [['group' => 'editors', 'match' => $fail], ['group' => 'editors', 'match' => $pass]],
			'user and group'    => [['user' => 'bob', 'group' => 'editors', 'match' => $fail]],
			'group not a name'  => [['group' => 7]],
			'malformed entries' => [7, null, true, [], ['match' => $pass], 'editors'],
			'empty list'        => [],
		];
	}//end ruleLists()

	/**
	 * The whole blocks under test.
	 *
	 * @return array<string, array|null>
	 */
	private static function blocks(): array {
		$blocks = ['null' => null, 'empty' => [], 'public on' => ['public' => true], 'public off' => ['public' => false]];
		foreach (self::ruleLists() as $name => $rules) {
			$blocks[$name] = array_fill_keys(self::ACTIONS, $rules);
		}

		$blocks['other action only'] = ['delete' => ['editors']];
		$blocks['public on + rules'] = ['public' => true, 'read' => ['editors']];

		$marker = TokenGrantNarrower::MARKER;
		$blocks['token read']       = [$marker => ['verbs' => ['read']], 'read' => ['editors'], 'create' => ['editors']];
		$blocks['token none']       = [$marker => ['verbs' => []], 'read' => ['editors']];
		$blocks['token unreadable'] = [$marker => ['verbs' => 'read'], 'read' => ['editors']];
		$blocks['token not array']  = [$marker => 'read', 'read' => ['editors']];

		return $blocks;
	}//end blocks()

	private function newHandler(?TokenGrantNarrower $narrower): PermissionHandler {
		$matcher = $this->createStub(ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			function (array $object, array $match): bool {
				$this->matcherCalls[] = [$object, $match];
				return (($match['pass'] ?? false) === true);
			}
		);
		$matcher->method('filterOrganisationMatchForCreate')->willReturnCallback(
			static function (array $match): array {
				unset($match['_organisation']);
				return $match;
			}
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(fn (): bool => $this->defaultClosed);

		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context['action'] ?? null, $context['flag'] ?? null];
			}
		);

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			userSession: $this->createStub(IUserSession::class),
			userManager: $this->createStub(IUserManager::class),
			groupManager: $this->createStub(IGroupManager::class),
			schemaMapper: $this->createStub(SchemaMapper::class),
			objectEntityMapper: $this->createStub(MagicMapper::class),
			conditionMatcher: $matcher,
			appConfig: $appConfig,
			logger: $logger,
			container: $this->createStub(ContainerInterface::class),
			tokenGrantNarrower: $narrower
		);
	}//end newHandler()

	/**
	 * One call's verdict and everything it asked and logged, on a handler that has warned nobody yet.
	 *
	 * @param PermissionHandler $handler The handler.
	 * @param array             $args    The named arguments of hasGroupPermission().
	 *
	 * @return array{0: mixed, 1: array, 2: array}
	 */
	private function trace(PermissionHandler $handler, array $args): array {
		$this->matcherCalls = [];
		$this->warnings     = [];
		(function (): void {
			$this->deprecationWarnedActions = [];
		})->call($handler);

		try {
			$verdict = $handler->hasGroupPermission(...$args);
		} catch (Throwable $e) {
			$verdict = get_class($e);
		}

		return [$verdict, $this->matcherCalls, $this->warnings];
	}//end trace()

	/**
	 * The recording of one block, action and group over every caller and object.
	 *
	 * @param array{handlers: array<int, PermissionHandler>, block: array|null, action: string, group: string} $cell The cell.
	 *
	 * @return array{hash: string, traces: array<int, array>}
	 */
	private function recordCell(array $cell): array {
		$traces = [];
		foreach ($cell['handlers'] as $handler) {
			foreach ([false, true] as $closed) {
				$this->defaultClosed = $closed;
				foreach (self::USERS as $user) {
					foreach (self::USER_GROUPS as $userGroup) {
						foreach (self::OWNERS as $owner) {
							foreach (self::OBJECT_DATA as $data) {
								foreach (self::ORGANISATIONS as $organisation) {
									$traces[] = $this->trace(
										$handler,
										[
											'authorization'      => $cell['block'],
											'groupId'            => $cell['group'],
											'action'             => $cell['action'],
											'userId'             => $user,
											'userGroup'          => $userGroup,
											'objectOwner'        => $owner,
											'objectData'         => $data,
											'objectOrganisation' => $organisation,
										]
									);
								}
							}
						}
					}
				}
			}
		}//end foreach

		return ['hash' => sha1(json_encode($traces, JSON_THROW_ON_ERROR)), 'traces' => $traces];
	}//end recordCell()

	/**
	 * Every cell's hash, plus what the traces prove was exercised.
	 *
	 * @return array{hashes: array<string, string>, seen: array<string, int>}
	 */
	private function record(): array {
		$handlers = [$this->newHandler(null), $this->newHandler(new TokenGrantNarrower())];
		$hashes   = [];
		$seen     = ['granted' => 0, 'refused' => 0, 'thrown' => 0, 'matcher' => 0, 'warned' => 0];
		foreach (self::blocks() as $name => $block) {
			foreach (self::ACTIONS as $action) {
				foreach (self::GROUPS as $group) {
					$cell = $this->recordCell(['handlers' => $handlers, 'block' => $block, 'action' => $action, 'group' => $group]);
					$hashes["$name|$action|$group"] = $cell['hash'];
					foreach ($cell['traces'] as [$verdict, $calls, $warnings]) {
						$seen['granted'] += (int) ($verdict === true);
						$seen['refused'] += (int) ($verdict === false);
						$seen['thrown']  += (int) is_string($verdict);
						$seen['matcher'] += count($calls);
						$seen['warned']  += count($warnings);
					}
				}
			}
		}

		return ['hashes' => $hashes, 'seen' => $seen];
	}//end record()

	public function testEveryInputAnswersAsRecorded(): void {
		$recording = $this->record();
		$golden    = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);

		$changed = array_keys(array_diff_assoc($recording['hashes'], $golden['hashes']));
		$this->assertSame([], $changed, 'Cells answering differently from the recording.');
		$this->assertSame(array_keys($golden['hashes']), array_keys($recording['hashes']));
		$this->assertSame($golden['seen'], $recording['seen']);
		foreach (['granted', 'refused', 'matcher', 'warned'] as $kind) {
			$this->assertGreaterThan(0, $recording['seen'][$kind], "The recording never saw: $kind");
		}
	}//end testEveryInputAnswersAsRecorded()

	public function testTheDefaultOpenDeprecationIsLoggedOncePerAction(): void {
		$handler  = $this->newHandler(null);
		$warnings = [];
		foreach (['create', 'create', 'update', 'create'] as $action) {
			$this->assertTrue($handler->hasGroupPermission(authorization: null, groupId: 'editors', action: $action));
			$warnings = array_merge($warnings, $this->warnings);
			$this->warnings = [];
		}

		$this->assertSame(['create', 'update'], array_column($warnings, 1));
	}//end testTheDefaultOpenDeprecationIsLoggedOncePerAction()
}//end class
