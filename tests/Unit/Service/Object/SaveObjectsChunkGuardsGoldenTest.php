<?php

/**
 * SaveObjects::enforceChunkGuards() admits and refuses chunk rows exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUser;
use OCP\IUserSession;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden record of the chunk guards: the rows admitted, the rows refused with their error and
 * type, the invalid and error counts, every permission question with its arguments, and every
 * validation call. Covers guards off, RBAC only, validation only and both, rows whose schema is
 * missing, unknown or not a schema, refused owners and schemas, invalid business data, rows
 * without business data, no signed-in user, and handlers without a permission or validation
 * service. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class SaveObjectsChunkGuardsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/save-objects-chunk-guards-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->outcome(scenario: $scenario);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testScenariosMatchTheGolden()

	/**
	 * The scenarios: the flags, the services present, the signed-in user and the rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$rows = [
			['schema' => 1, 'owner' => 'bob', 'object' => ['name' => 'a']],
			['owner' => 'bob', 'object' => ['name' => 'no schema key']],
			['schema' => 99, 'object' => ['name' => 'unknown schema']],
			['schema' => 'odd', 'object' => ['name' => 'cache holds no schema']],
			['schema' => 1, 'owner' => 'eve', 'object' => ['name' => 'refused owner']],
			['schema' => 2, 'object' => ['name' => 'refused schema']],
			['schema' => 1, 'owner' => 'bob', 'object' => ['name' => 'bad', 'bad' => true]],
			['schema' => 3, 'owner' => null],
			['schema' => '1', 'object' => ['name' => 'string id']],
			['schema' => null, 'object' => ['name' => 'null schema']],
		];
		$all = ['permissions' => true, 'validator' => true, 'user' => 'bob'];
		return [
			'guards off'                   => ['rbac' => false, 'validation' => false, 'rows' => $rows] + $all,
			'rbac only'                    => ['rbac' => true, 'validation' => false, 'rows' => $rows] + $all,
			'validation only'              => ['rbac' => false, 'validation' => true, 'rows' => $rows] + $all,
			'both'                         => ['rbac' => true, 'validation' => true, 'rows' => $rows] + $all,
			'both, no user'                => ['rbac' => true, 'validation' => true, 'rows' => $rows, 'user' => null] + $all,
			'both, no services'            => ['rbac' => true, 'validation' => true, 'rows' => $rows, 'permissions' => false, 'validator' => false, 'user' => 'bob'],
			'rbac, no permission service'  => ['rbac' => true, 'validation' => false, 'rows' => $rows, 'permissions' => false] + $all,
			'validation, no validator'     => ['rbac' => false, 'validation' => true, 'rows' => $rows, 'validator' => false] + $all,
			'empty chunk'                  => ['rbac' => true, 'validation' => true, 'rows' => []] + $all,
		];
	}//end scenarios()

	/**
	 * Run the guards over one scenario and record what they admit, refuse and ask.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed> The recorded outcome.
	 */
	private function outcome(array $scenario): array {
		$record = ['asked' => [], 'validated' => []];

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($scenario['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($scenario['user']);
		}

		$session->method('getUser')->willReturnCallback(
			function () use (&$record, $user): ?IUser {
				$record['asked'][] = 'getUser';
				return $user;
			}
		);

		$permissions = null;
		if ($scenario['permissions'] === true) {
			$permissions = $this->createMock(PermissionHandler::class);
			$permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
				function (Schema $schema, string $action, ?string $userId, ?string $objectOwner, bool $_rbac) use (&$record): bool {
					$record['asked'][] = [$schema->getId(), $action, $userId, $objectOwner, $_rbac];
					return $objectOwner !== 'eve' && $schema->getId() !== 2;
				}
			));
		}

		$validator = null;
		if ($scenario['validator'] === true) {
			$validator = $this->createMock(ValidateObject::class);
			$validator->method('validateObject')->willReturnCallback(
				function (array $object, Schema $schema) use (&$record): ValidationResult {
					$record['validated'][] = [$object, $schema->getId()];
					$result = $this->createMock(ValidationResult::class);
					$result->method('isValid')->willReturn(isset($object['bad']) === false);
					return $result;
				}
			);
			$validator->method('generateErrorMessage')->willReturn('the name is bad');
		}

		$handler = new SaveObjects(
			stores: new BulkSaveStores(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(RegisterMapperInterface::class), schemas: $this->createMock(SchemaMapperInterface::class), tables: $this->createMock(MagicMapper::class)), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $session, organisationService: $this->createMock(OrganisationServiceInterface::class)),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class)),
			checks: new BulkRowChecks(permissionHandler: $permissions, validateHandler: $validator)
		);

		$cache = [1 => $this->schema(id: 1), 2 => $this->schema(id: 2), 3 => $this->schema(id: 3), 'odd' => ['not' => 'a schema']];
		$prior = ['object' => ['earlier'], 'error' => 'earlier failure', 'type' => 'EarlierException'];
		$result = [
			'invalid'    => [$prior],
			'statistics' => ['invalid' => 1, 'errors' => 1, 'saved' => 0],
		];

		$run              = new BulkSaveRun();
		$run->schemaCache = $cache;
		$run->rbac        = $scenario['rbac'];
		$run->validation  = $scenario['validation'];

		$method = new ReflectionMethod(SaveObjects::class, 'enforceChunkGuards');
		[$allowed, $refused] = $method->invoke($handler, $run, $scenario['rows']);
		foreach ($refused as $entry) {
			$result['invalid'][] = $entry;
			$result['statistics']['invalid']++;
			$result['statistics']['errors']++;
		}

		$record['allowed'] = $allowed;
		$record['result']  = $result;
		return $record;
	}//end outcome()

	/**
	 * A schema with an id.
	 *
	 * @param int $id The id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}//end schema()
}//end class
