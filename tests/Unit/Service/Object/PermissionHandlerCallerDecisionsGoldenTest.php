<?php

/**
 * PermissionHandler answers denials, provenance and the private scope for every caller as recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Service\Object\DenialQuestion;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\ProvenanceRequest;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks blocks, callers, objects and deny modes through denialFor(), provenanceFor() and hasPermission().
 *
 * The recording was made on the code before these units were split.
 *
 * @covers \OCA\OpenRegister\Service\Object\PermissionHandler
 * @uses   \OCA\OpenRegister\Service\Object\DenialQuestion
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheck
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantConstraints
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ProvenanceResolver
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\Object\GroupRuleEntries
 * @uses \OCA\OpenRegister\Service\Object\GroupRuleRequest
 * @uses \OCA\OpenRegister\Service\Object\PermissionAsk
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionCallerServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDataServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDenyServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionDerivedGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionGrantServices
 * @uses \OCA\OpenRegister\Service\Object\Permission\PermissionRuleServices
 * @uses \OCA\OpenRegister\Service\Object\ProvenanceRequest
 * @uses \OCA\OpenRegister\Service\Rbac\DenyQuestion
 * @uses \OCA\OpenRegister\Service\Rbac\McpScope
 * @uses \OCA\OpenRegister\Service\Rbac\ProvenanceOptions
 * @uses \OCA\OpenRegister\Service\Rbac\RoleActionMap
 * @uses \OCA\OpenRegister\Service\Rbac\StagedDenial
 */
class PermissionHandlerCallerDecisionsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/permission-handler/caller-decisions-golden.json';

	private const GROUPS = ['alice' => ['editors'], 'root' => ['admin'], 'bob' => ['staff', 'editors']];

	private const ACTIONS = ['read', 'update', 'create'];

	/**
	 * What was logged during the current case.
	 *
	 * @var array<int, array>
	 */
	private array $logged = [];

	/**
	 * The blocks the cases run.
	 *
	 * @return array<string, array|null>
	 */
	private static function blocks(): array {
		$pass = ['pass' => true];
		$fail = ['pass' => false];

		return [
			'none'             => null,
			'grants'           => ['read' => ['editors'], 'update' => ['staff']],
			'deny group'       => ['read' => ['editors'], 'deny' => ['read' => ['editors']]],
			'deny user'        => ['read' => ['editors'], 'deny' => ['read' => ['user:alice']]],
			'deny public'      => ['read' => ['public'], 'deny' => ['read' => ['public']]],
			'deny match pass'  => ['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => $pass]]]],
			'deny match fail'  => ['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => $fail]]]],
			'deny unknown key' => ['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => ['unknown' => true]]]]],
			'deny bad match'   => ['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => 'x']]]],
			'deny second rule' => ['read' => ['editors'], 'deny' => ['read' => [['group' => 'editors', 'match' => $fail], ['group' => 'staff', 'match' => $pass]]]],
			'private'          => ['scope' => 'private', 'read' => ['editors'], 'update' => ['editors']],
			'private owner'    => ['scope' => 'private', 'ownerGroup' => 'staff', 'read' => ['staff']],
		];
	}//end blocks()

	private function newHandler(array $caller): PermissionHandler {
		$users = [];
		foreach (array_keys(self::GROUPS) as $uid) {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[$uid] = $user;
		}

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($users[$caller['session']] ?? null);
		$manager = $this->createStub(IUserManager::class);
		$manager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($users[$uid] ?? null));
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => self::GROUPS[$user->getUID()]);

		$matcher = $this->createStub(ConditionMatcher::class);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			static fn (array $object, array $match): bool => (($match['pass'] ?? false) === true)
		);
		$matcher->method('namesUnknownMetadata')->willReturnCallback(
			static fn (array $match): bool => isset($match['unknown'])
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (str_contains($key, 'deny') === true ? $caller['mode'] : $default)
		);

		$registers = $this->createStub(RegisterMapper::class);
		$registers->method('getFirstRegisterWithSchema')->willReturn(null);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): ?RegisterMapper => ($id === RegisterMapperInterface::class ? $registers : null)
		);

		$logger = $this->createStub(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = [$level, $message];
				}
			);
		}

		return \OCA\OpenRegister\Tests\Unit\Service\Object\Support\PermissionHandlerFactory::create(
			userSession: $session,
			userManager: $manager,
			groupManager: $groups,
			schemaMapper: $this->createStub(SchemaMapper::class),
			objectEntityMapper: $this->createStub(MagicMapper::class),
			conditionMatcher: $matcher,
			appConfig: $appConfig,
			logger: $logger,
			container: $container
		);
	}//end newHandler()

	private function schemaWith(?array $block): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setAuthorization($block);

		return $schema;
	}//end schemaWith()

	private function objectWith(?array $block, ?string $owner): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-2222-3333-4444-555555555555');
		$object->setObject(['title' => 'x']);
		$object->setOwner($owner);
		$object->setAuthorization($block);

		return $object;
	}//end objectWith()

	/**
	 * The call's answer, or the class it threw, with what it logged.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{answer: mixed, logged: array}
	 */
	private function answer(callable $call): array {
		$this->logged = [];
		try {
			$answer = $call();
		} catch (Throwable $e) {
			$answer = get_class($e);
		}

		return ['answer' => $answer, 'logged' => $this->logged];
	}//end answer()

	/**
	 * Every question for one block and caller.
	 *
	 * @param array|null $block  The block.
	 * @param array      $caller The caller, mode and object choice.
	 *
	 * @return array<string, array>
	 */
	private function questions(?array $block, array $caller): array {
		$schema  = $this->schemaWith($block);
		$object  = null;
		if ($caller['object'] !== 'none') {
			$ownBlock = (str_ends_with($caller['object'], 'own block') === true ? $block : null);
			$object   = $this->objectWith($ownBlock, (str_starts_with($caller['object'], 'foreign') === true ? 'ghost' : 'bob'));
		}

		$userId  = $caller['user'];
		$answers = [];
		foreach (self::ACTIONS as $action) {
			$handler = $this->newHandler($caller);
			$answers["denial $action"] = $this->answer(
				static fn () => $handler->denialFor(
					question: (new DenialQuestion(authorization: $block, action: $action))->about(principals: [], object: $object),
					userId: $userId
				)
			);
			$answers["allowed $action"] = $this->answer(
				fn () => $this->newHandler($caller)->hasPermission(schema: $schema, action: $action, userId: $userId, object: $object)
			);
		}

		$handler = $this->newHandler($caller);
		$answers['provenance'] = $this->answer(
			static fn () => $handler->provenanceFor(request: new ProvenanceRequest(schema: $schema, actions: self::ACTIONS, userId: $userId, object: $object))
		);

		return $answers;
	}//end questions()

	/**
	 * The hash of every session and explicit caller's answers for one block, mode and object.
	 *
	 * @param array|null $block The block.
	 * @param array      $cell  The mode and object choice.
	 *
	 * @return string The hash.
	 */
	private function recordCallers(?array $block, array $cell): string {
		$answers = [];
		foreach ([null, 'alice', 'root', 'bob'] as $session) {
			foreach ([null, 'alice', 'bob', 'root', 'ghost'] as $user) {
				$answers[] = $this->questions($block, array_merge($cell, ['session' => $session, 'user' => $user]));
			}
		}

		return sha1(json_encode($answers, JSON_THROW_ON_ERROR));
	}//end recordCallers()

	/**
	 * Every case.
	 *
	 * @return array<string, array>
	 */
	private function record(): array {
		$recording = [];
		foreach (self::blocks() as $blockName => $block) {
			foreach (['enforcing', 'staging', 'off'] as $mode) {
				foreach (['none', 'plain', 'own block', 'foreign', 'foreign own block'] as $objectKind) {
					$recording["$blockName|$mode|$objectKind"] = $this->recordCallers($block, ['mode' => $mode, 'object' => $objectKind]);
				}
			}
		}

		return $recording;
	}//end record()

	public function testEveryCallerIsAnsweredAsRecorded(): void {
		$recording = $this->record();
		$golden    = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame([], array_keys(array_diff_assoc($recording, $golden)), 'Cases answering differently from the recording.');
		$this->assertSame(array_keys($golden), array_keys($recording));
	}//end testEveryCallerIsAnsweredAsRecorded()
}//end class
