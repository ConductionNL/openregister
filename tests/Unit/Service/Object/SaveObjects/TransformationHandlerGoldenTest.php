<?php

/**
 * TransformationHandler turns a batch of objects into database rows exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of transformObjectsToDatabaseFormatInPlace(): the valid rows, the invalid
 * entries and the log lines, for a batch that takes every branch, with and without a
 * logged-in user. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * Generated (v4) uuids are recorded as "(generated)"; log context drops 'file' and 'line'.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects\TransformationHandler
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\SaveObjects\RelationScan
 */
class TransformationHandlerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/transformation-handler-golden.json';

	/**
	 * The transformation of the fixed batch matches the recorded golden.
	 *
	 * @return void
	 */
	public function testTransformationMatchesTheGolden(): void {
		$actual = [
			'alice'     => $this->transform(userId: 'alice'),
			'anonymous' => $this->transform(userId: null),
		];

		// Entities in the rows and invalid entries compare by their JSON form.
		$actual = json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true);

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testTransformationMatchesTheGolden()

	/**
	 * Run the batch for one session user and record the result and the log.
	 *
	 * @param string|null $userId The logged-in user, or null for none.
	 *
	 * @return array<string, mixed>
	 */
	private function transform(?string $userId): array {
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$relations = $this->createMock(RelationCascadeHandler::class);
		$relations->method('scanForRelations')->willReturnCallback(
			function (array $data, string $prefix = '', ?Schema $schema = null): array {
				$found = [];
				foreach ($data as $key => $value) {
					if (is_string($value) === true && str_starts_with($value, 'ref-') === true) {
						$found[$prefix . $key] = $value . '@' . $schema?->getId();
					}
				}

				return $found;
			}
		);

		$log    = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []) use (&$log): void {
				unset($context['file'], $context['line']);
				$log[] = ['message' => $message, 'context' => $context];
			}
		);

		$handler = new TransformationHandler(relCascadeHandler: $relations, userSession: $session, logger: $logger);
		$objects = $this->batch();
		$result  = $handler->transformObjectsToDatabaseFormatInPlace($objects, $this->schemaCache());

		$recorded = ['result' => $result, 'log' => $log, 'objectsAfter' => $objects];
		array_walk_recursive(
			$recorded,
			function (mixed &$value): void {
				if (is_string($value) === true && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1) {
					$value = '(generated)';
				}
			}
		);

		return $recorded;
	}//end transform()

	/**
	 * The schemas the batch may name: only id 10 exists.
	 *
	 * @return array<int|string, Schema>
	 */
	private function schemaCache(): array {
		$schema = new Schema();
		$schema->setId(10);
		return [10 => $schema];
	}//end schemaCache()

	/**
	 * A batch that takes every branch of the transformation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function batch(): array {
		$register = new Register();
		$register->setId(2);
		$schema = new Schema();
		$schema->setId(10);

		return [
			// Flat legacy row: metadata stripped, owner from the session, relations scanned.
			['id' => 'flat-1', 'register' => 1, 'schema' => 10, 'name' => 'N', 'slug' => 's', 'title' => 'T', 'link' => 'ref-a', 'owner' => ''],
			// @self row with objects for register and schema, business data under 'object', owner kept.
			[
				'@self'  => ['id' => 'self-1', 'owner' => 'bob'],
				'register' => $register,
				'schema' => $schema,
				'object' => ['title' => 'X', 'parent' => 'ref-b'],
			],
			// Relations already set in @self are kept; no id, so a uuid is generated.
			['@self' => ['register' => 1, 'schema' => 10, 'relations' => ['p' => 'q']], 'object' => ['v' => 'ref-c']],
			// Whitespace id: generated uuid; a provided organisation is kept.
			['id' => '   ', 'register' => 1, 'schema' => 10, 'organisation' => 'org-1', 'x' => 1],
			// @self id loses to the row's own id.
			['id' => 'org-kept', '@self' => ['id' => 'ignored', 'organisation' => ''], 'register' => 1, 'schema' => 10, 'object' => 'not-an-array', 'k' => 'ref-d'],
			// Relations set but empty are scanned again.
			['id' => 'rel-set', 'register' => 1, 'schema' => 10, '@self' => ['relations' => []], 'object' => ['z' => 'ref-e']],
			// Missing register.
			['id' => 'no-register', 'schema' => 10],
			// Missing schema.
			['id' => 'no-schema', 'register' => 1],
			// Missing both: reported as missing register.
			['id' => 'no-both'],
			// Unknown schema.
			['id' => 'bad-schema', 'register' => 1, 'schema' => 99],
			// Register and schema from @self win over the row.
			['id' => 'int-like', '@self' => ['register' => 5, 'schema' => 10], 'register' => 1, 'schema' => 99],
		];
	}//end batch()
}//end class
