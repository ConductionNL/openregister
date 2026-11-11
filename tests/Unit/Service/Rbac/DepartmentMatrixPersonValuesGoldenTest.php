<?php

/**
 * Golden values of the matrix person source, pinned before its refactor.
 *
 * The values a user gets from the person source are the departments a matrix
 * row grants them. A value returned is access admitted; an empty answer is
 * access refused. Each scenario records the answer the reader gave before the
 * person read was split from the value collection, together with how many
 * searches it made, so a refused case that starts answering (or an admitted
 * one that stops) fails here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixPersonValues;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins admitted and refused person-source answers.
 */
class DepartmentMatrixPersonValuesGoldenTest extends TestCase {

	/**
	 * A person object, as the search returns it.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function person(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setObject($data);
		return $object;
	}//end person()

	/**
	 * The scenarios and the answers the pre-refactor code gave.
	 *
	 * Arguments: source, user, what the search answers ('throw' to throw),
	 * the register the schema lives in (null for none), expected values,
	 * expected search count, expected register looked up (null for none).
	 *
	 * @return array<string, array<int, mixed>> Scenario name => arguments.
	 */
	public static function scenarios(): array {
		$source = ['schema' => 'person', 'property' => 'department', 'match' => 'userId'];
		$anna   = self::person(['userId' => 'anna', 'department' => ['VTH', 'Bouw', 'VTH', '', 7]]);
		$bob    = self::person(['userId' => 'bob', 'department' => 'Milieu']);

		return [
			'own person gives own values' => [$source, 'anna', [$anna], 7, ['VTH', 'Bouw', '7'], 1, 7],
			'another person contributes nothing' => [$source, 'anna', [$bob], 7, [], 1, 7],
			'plain array persons are read' => [$source, 'bob', [['userId' => 'bob', 'department' => 'Milieu']], 7, ['Milieu'], 1, 7],
			'explicit register is used' => [$source + ['register' => ' reg-9 '], 'anna', [$anna], null, ['VTH', 'Bouw', '7'], 1, 'reg-9'],
			'no register holds the schema' => [$source, 'anna', [$anna], null, [], 0, null],
			'unknown schema' => [['schema' => 'nobody'] + $source, 'anna', [$anna], 7, [], 0, null],
			'search fails' => [$source, 'anna', 'throw', 7, [], 1, 7],
			'search answers a count' => [$source, 'anna', 3, 7, [], 1, 7],
			'no property' => [['schema' => 'person'], 'anna', [$anna], 7, [], 0, null],
			'no schema' => [['property' => 'department'], 'anna', [$anna], 7, [], 0, null],
			'no source' => [null, 'anna', [$anna], 7, [], 0, null],
			'no user' => [$source, null, [$anna], 7, [], 0, null],
			'empty user' => [$source, '', [$anna], 7, [], 0, null],
			'empty match falls back to userId' => [['match' => ' '] + $source, 'anna', [$anna], 7, ['VTH', 'Bouw', '7'], 1, 7],
			'other match field' => [['match' => 'login'] + $source, 'anna', [$anna], 7, [], 1, 7],
		];
	}//end scenarios()

	/**
	 * The answer, the search count and the register lookup match the pinned ones.
	 *
	 * @param array<string, mixed>|null $source         The matrix's `userSource`.
	 * @param string|null               $userId         The user.
	 * @param mixed                     $answer         What the search answers.
	 * @param integer|null              $holder         The register holding the schema.
	 * @param string[]                  $expected       The pinned values.
	 * @param integer                   $searches       The pinned search count.
	 * @param string|integer|null       $registerLookup The pinned register lookup.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheAnswerIsPinned(
		?array $source,
		?string $userId,
		mixed $answer,
		?int $holder,
		array $expected,
		int $searches,
		string|int|null $registerLookup
	): void {
		$schema = new Schema();
		$schema->setId(40);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id) use ($schema): Schema {
				if ($id === 'person') {
					return $schema;
				}

				throw new DoesNotExistException('no schema');
			}
		);

		$looked    = [];
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('getFirstRegisterWithSchema')->willReturn($holder);
		$registers->method('find')->willReturnCallback(
			static function ($id) use (&$looked): Register {
				$looked[] = $id;
				$register = new Register();
				$register->setId(7);
				return $register;
			}
		);

		$count   = 0;
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			static function () use (&$count, $answer): array|int {
				$count++;
				if ($answer === 'throw') {
					throw new RuntimeException('search');
				}

				return $answer;
			}
		);

		$reader = new DepartmentMatrixPersonValues($schemas, $registers, $objects, new NullLogger());

		$this->assertSame($expected, $reader->valuesFor($source, $userId));
		$this->assertSame($expected, $reader->valuesFor($source, $userId), 'the second ask is answered the same');
		$this->assertSame($searches, $count, 'the second ask is served from the request cache');

		$lookups = [];
		if ($registerLookup !== null) {
			$lookups = [(string)$registerLookup];
		}

		$this->assertSame($lookups, $looked);
	}//end testTheAnswerIsPinned()
}//end class
