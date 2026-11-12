<?php

/**
 * Golden test for PropertyRbacHandler's object-level filters.
 *
 * Pins, for every user, what filterReadableProperties, stripWriteOnlyProperties,
 * getUnauthorizedProperties, collectOmittedWriteOnlyPaths,
 * collectOmittedProtectedProperties and restoreWriteOnlyValues return over a fixed
 * schema and object, together with the reveals recorded and the warnings logged.
 * Both what is let through and what is refused are pinned. The expected outcomes
 * live in fixtures/property-rbac-filter-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ProtectedCarryForward;
use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\PropertyWriteCheck;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateFieldLists;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\OmittedWriteOnly;
use OCA\OpenRegister\Service\Rbac\PropertyReveal;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\PropertyRbacHandler
 */
final class PropertyRbacHandlerFilterGoldenTest extends TestCase {

	/**
	 * Property authorization blocks by property name.
	 *
	 * @var array<string, array>
	 */
	private const AUTH = [
		'secretNote' => ['read' => ['g1'], 'update' => ['g1']],
		'openNote' => ['read' => [['group' => 'g2', 'match' => ['status' => 'open']]], 'update' => ['g2']],
		'audited' => ['read' => ['authenticated'], 'audit' => true],
		'publicOnly' => ['read' => ['public']],
		'missing' => ['read' => ['g1'], 'update' => ['g1']],
	];

	/**
	 * Users: null for none, else [uid, groups].
	 *
	 * @var array<string, array|null>
	 */
	private const USERS = [
		'anonymous' => null,
		'admin' => ['root', ['admin']],
		'g1' => ['ann', ['g1']],
		'g2' => ['bob', ['g2']],
		'nobody' => ['cid', []],
	];

	/**
	 * One scenario per user, object state and whether a reveal collector is wired.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$out = [];
		foreach (array_keys(self::USERS) as $user) {
			foreach (['open', 'closed'] as $status) {
				$out[$user . ' ' . $status] = [$user, $status];
			}
		}

		return $out;
	}

	/**
	 * The handler's outcomes for one user and object state equal the pinned ones.
	 *
	 * @param string $userKey The user.
	 * @param string $status  The object's status.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testFiltersGolden(string $userKey, string $status): void {
		$log = [];
		$handler = $this->handler(userKey: $userKey, log: $log);
		$schema = $this->schema();
		$object = [
			'id' => 'obj-1',
			'status' => $status,
			'secretNote' => 's',
			'openNote' => 'o',
			'audited' => 'a',
			'publicOnly' => 'p',
			'plain' => 'x',
			'token' => 't',
			'cfg' => ['auth' => ['secret' => 'k', 'keep' => 1], 'endpoint' => 'e'],
			'cfg.auth.secret' => 'mirror',
			'cfg.auth.secret.deep' => 'mirror2',
			'hiddenByState' => 'h',
		];
		$incoming = [
			'status' => $status,
			'secretNote' => 'changed',
			'openNote' => 'o',
			'audited' => 'changed',
			'hiddenByState' => 'changed',
			'cfg' => ['endpoint' => 'new'],
		];

		$actual = [];
		$actual['filter'] = [$handler->filterReadableProperties(schema: $schema, object: $object), $log];
		$log = [];
		$actual['strip'] = [$handler->stripWriteOnlyProperties(schema: $schema, object: $object), $log];
		$log = [];
		$actual['unauthorizedUpdate'] = [$handler->getUnauthorizedProperties(
			write: new PropertyWriteCheck(schema: $schema, request: new PropertyAccessRequest(object: $object), incoming: $incoming)
		), $log];
		$log = [];
		$actual['unauthorizedCreate'] = [$handler->getUnauthorizedProperties(
			write: new PropertyWriteCheck(schema: $schema, request: new PropertyAccessRequest(object: [], isCreate: true), incoming: $incoming)
		), $log];
		$log = [];
		$omitted = $handler->collectOmittedWriteOnlyPaths(schema: $schema, incoming: $incoming);
		$actual['omittedWriteOnly'] = $omitted;
		$actual['omittedProtected'] = [$handler->collectOmittedProtectedProperties(update: new ProtectedCarryForward(schema: $schema, incoming: ['status' => $status], stored: $object)), $log];
		$log = [];
		$actual['restore'] = $handler->restoreWriteOnlyValues(prepared: $incoming, omitted: new OmittedWriteOnly(stored: $object, paths: [...$omitted, 'nowhere.at.all']));
		$actual['restoreScalar'] = $handler->restoreWriteOnlyValues(prepared: ['cfg' => 'flat'], omitted: new OmittedWriteOnly(stored: $object, paths: ['cfg.auth.secret']));
		$actual['couldRead'] = array_map(
			fn (string $p): bool => $handler->couldReadProperty(schema: $schema, property: $p),
			array_combine(array_keys(self::AUTH), array_keys(self::AUTH))
		);
		$actual['isAdmin'] = $handler->isAdmin();

		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);
		$file = __DIR__ . '/fixtures/property-rbac-filter-golden.json';
		$name = $userKey . ' ' . $status;
		$all = [];
		if (is_file($file) === true) {
			$all = json_decode(file_get_contents($file), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}

	/**
	 * A handler for one user that records reveals and warnings into $log.
	 *
	 * @param string $userKey The user.
	 * @param array  $log     The log, by reference.
	 *
	 * @return PropertyRbacHandler
	 */
	private function handler(string $userKey, array &$log): PropertyRbacHandler {
		$spec = self::USERS[$userKey];
		$user = null;
		if ($spec !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($spec[0]);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($spec[1] ?? []);

		$matcher = $this->createMock(ConditionMatcher::class);
		$matcher->method('filterOrganisationMatchForCreate')->willReturnArgument(0);
		$matcher->method('objectMatchesConditions')->willReturnCallback(
			fn (array $object, array $match): bool => ($object['status'] ?? null) === ($match['status'] ?? null)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message) use (&$log): void {
				$log[] = ['warning', $message];
			}
		);

		$states = $this->createMock(StateFieldRuleResolver::class);
		$states->method('resolve')->willReturnCallback(
			fn (Schema $schema, array $data): StateFieldRules => new StateFieldRules(
				state: ($data['status'] ?? null),
				fields: new StateFieldLists(hidden: (($data['status'] ?? null) === 'closed') ? ['hiddenByState'] : [])
			)
		);

		$reveals = $this->createMock(RevealCollector::class);
		$reveals->method('isAudited')->willReturnCallback(fn (?array $rules): bool => ($rules['audit'] ?? false) === true);
		$reveals->method('recordReveal')->willReturnCallback(
			function (PropertyReveal $reveal) use (&$log): void {
				$log[] = ['reveal', $reveal->userId, $reveal->objectUuid, $reveal->property, $reveal->schemaId];
			}
		);

		return new PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($session, $groups), rules: new \OCA\OpenRegister\Service\PropertyRbacRules($matcher, $states), logger: $logger, reveals: $reveals);
	}

	/**
	 * The fixed schema: authorization blocks, one write-only property and one write-only path.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('hasPropertyAuthorization')->willReturn(true);
		$schema->method('getPropertiesWithAuthorization')->willReturn(self::AUTH);
		$schema->method('getPropertyAuthorization')->willReturnCallback(fn (string $p): ?array => (self::AUTH[$p] ?? null));
		$schema->method('hasWriteOnlyProperties')->willReturn(true);
		$schema->method('getWriteOnlyProperties')->willReturn(['token']);
		$schema->method('getWriteOnlyPaths')->willReturn(['cfg.auth.secret']);
		$schema->method('__call')->willReturnCallback(fn (string $name): ?int => ($name === 'getId') ? 7 : null);

		return $schema;
	}
}
