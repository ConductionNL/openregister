<?php

/**
 * Golden test for PropertyRbacHandler::canReadProperty and canUpdateProperty.
 *
 * Every combination of user, rule set, action and create flag over a fixed object,
 * with the condition matcher, group lookups and warnings recorded, is pinned in
 * fixtures/property-rbac-access-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\PropertyRbacHandler
 * @covers \OCA\OpenRegister\Service\PropertyAccessRequest
 */
class PropertyRbacHandlerAccessGoldenTest extends TestCase {

	/**
	 * Property authorization blocks by property name.
	 *
	 * @return array<string, array|null>
	 */
	private static function authorizations(): array {
		return [
			'none' => null,
			'empty' => [],
			'readOnlyRule' => ['read' => ['g1']],
			'emptyAction' => ['read' => [], 'update' => []],
			'public' => ['read' => ['public'], 'update' => ['public']],
			'authenticated' => ['read' => ['authenticated'], 'update' => ['authenticated']],
			'groups' => ['read' => ['g2', 'g1'], 'update' => ['g3']],
			'conditional' => [
				'read' => [['group' => 'g1', 'match' => ['status' => 'open']]],
				'update' => [['group' => 'g1', 'match' => ['_organisation' => '$organisation']], ['group' => 'g2', 'match' => ['_organisation' => '$organisation', 'status' => 'open']]],
			],
			'conditionalNoMatch' => ['read' => [['group' => 'authenticated']], 'update' => [['group' => 'g1', 'match' => []]]],
			'invalid' => ['read' => [42, ['match' => ['x' => 1]], 'g1'], 'update' => [null]],
		];
	}

	/**
	 * Users: null for none, else [uid, groups].
	 *
	 * @return array<string, array|null>
	 */
	private static function users(): array {
		return [
			'anonymous' => null,
			'admin' => ['root', ['admin']],
			'g1' => ['ann', ['g1']],
			'g2' => ['bob', ['g2', 'x']],
			'nobody' => ['cid', []],
		];
	}

	/**
	 * One scenario per user and object state.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$out = [];
		foreach (array_keys(self::users()) as $user) {
			foreach (['open', 'closed'] as $status) {
				$out[$user . ' ' . $status] = [$user, $status];
			}
		}

		return $out;
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testAccessGolden(string $userKey, string $status): void {
		$log = [];
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);
		$spec = self::users()[$userKey];
		$user = null;
		if ($spec !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($spec[0]);
		}

		$session->method('getUser')->willReturnCallback(
			function () use (&$log, $user) {
				$log[] = 'getUser';
				return $user;
			}
		);
		$groups->method('getUserGroupIds')->willReturnCallback(
			function () use (&$log, $spec) {
				$log[] = 'getUserGroupIds';
				return $spec[1];
			}
		);
		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('filterOrganisationMatchForCreate')->willReturnCallback(
			function (array $match) use (&$log) {
				$log[] = ['filterForCreate', $match];
				unset($match['_organisation']);
				return $match;
			}
		);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			function (array $object, array $match) use (&$log) {
				$log[] = ['matches', $match];
				return ($match['status'] ?? null) === null || ($object['status'] ?? null) === $match['status'];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message, array $context = []) use (&$log): void {
				$log[] = ['warning', $message, json_encode($context['rule'] ?? null)];
			}
		);
		$handler = new PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($session, $groups), rules: new \OCA\OpenRegister\Service\PropertyRbacRules($matcher, $this->createMock(StateFieldRuleResolver::class)), logger: $logger);

		$schema = $this->createMock(Schema::class);
		$schema->method('getPropertyAuthorization')->willReturnCallback(fn (string $p) => self::authorizations()[$p]);
		$object = ['status' => $status, '_organisation' => 'org-1'];

		$actual = [];
		foreach (array_keys(self::authorizations()) as $property) {
			$log = [];
			$read = $handler->canReadProperty($schema, $property, $object);
			$readLog = $log;
			$log = [];
			$update = $handler->canUpdateProperty($schema, $property, $object);
			$updateLog = $log;
			$log = [];
			$create = $handler->canUpdateProperty($schema, $property, $object, true);
			$actual[$property] = ['read' => [$read, $readLog], 'update' => [$update, $updateLog], 'create' => [$create, $log]];
		}

		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);
		$file = __DIR__ . '/fixtures/property-rbac-access-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}
}
