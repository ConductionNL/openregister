<?php

/**
 * Golden outcomes of the `$memberOf` resolver, pinned before its refactor.
 *
 * The set this resolver answers is what a `$memberOf` rule admits on: a value
 * in the set is access admitted, an empty set admits nothing and null denies
 * the rule outright. Each scenario records the answer, the search count and
 * the register lookups the resolver gave before its schema and register
 * mappers were bundled. No scenario uses a `current` window, so nothing here
 * depends on today's date.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins admitted, empty and denied `$memberOf` sets.
 */
class MemberOfResolverGoldenTest extends TestCase {

	/**
	 * Searches made by the last resolver.
	 *
	 * @var integer
	 */
	private int $searches = 0;

	/**
	 * Register ids looked up by the last resolver.
	 *
	 * @var array<int, string|int>
	 */
	private array $lookups = [];

	/**
	 * Build the resolver.
	 *
	 * @param SchemaMapperInterface   $schemas   The schemas.
	 * @param RegisterMapperInterface $registers The registers.
	 * @param ContainerInterface      $container Gives the magic mapper.
	 *
	 * @return MemberOfResolver The resolver.
	 */
	private function build(
		SchemaMapperInterface $schemas,
		RegisterMapperInterface $registers,
		ContainerInterface $container
	): MemberOfResolver {
		return new MemberOfResolver(
			schemas: $schemas,
			registers: $registers,
			container: $container,
			operators: new OperatorEvaluator(new NullLogger()),
			logger: new NullLogger()
		);
	}//end build()

	/**
	 * A resolver whose search answers $answer.
	 *
	 * @param mixed        $answer The search answer, or 'throw'.
	 * @param integer|null $holder The register holding the schema.
	 *
	 * @return MemberOfResolver The resolver.
	 */
	private function resolver(mixed $answer, ?int $holder=7): MemberOfResolver {
		$schema = new Schema();
		$schema->setId(40);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			static function ($id) use ($schema): Schema {
				if ($id === 'membership') {
					return $schema;
				}

				throw new DoesNotExistException('no schema');
			}
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('getFirstRegisterWithSchema')->willReturn($holder);
		$registers->method('find')->willReturnCallback(
			function ($id): Register {
				$this->lookups[] = $id;
				$register = new Register();
				$register->setId(7);
				return $register;
			}
		);

		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('searchObjects')->willReturnCallback(
			function () use ($answer): array|int {
				$this->searches++;
				if ($answer === 'throw') {
					throw new RuntimeException('search');
				}

				return $answer;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return $this->build($schemas, $registers, $container);
	}//end resolver()

	/**
	 * Membership rows, as the search returns them.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private static function rows(): array {
		$entity = new ObjectEntity();
		$entity->setObject(['userId' => 'anna', 'administrationId' => 'A2', 'role' => 'lid', 'level' => 3]);

		return [
			['userId' => 'anna', 'administrationId' => 'A1', 'role' => 'beheer', 'level' => 5],
			$entity,
			['userId' => 'bob', 'administrationId' => 'B1', 'role' => 'beheer', 'level' => 9],
			['userId' => 'anna', 'administrationId' => ['A3', 'A1', ''], 'role' => 'beheer', 'level' => 1],
			'not a row',
		];
	}//end rows()

	/**
	 * The scenarios and the answers the pre-refactor code gave.
	 *
	 * Arguments: operand, user, search answer, holding register, expected
	 * set (null = deny), expected searches, expected register lookups.
	 *
	 * @return array<string, array<int, mixed>> Scenario name => arguments.
	 */
	public static function scenarios(): array {
		$op   = ['schema' => 'membership', 'property' => 'administrationId'];
		$rows = self::rows();

		return [
			'own rows give own values' => [$op, 'anna', $rows, 7, ['A1', 'A2', 'A3'], 1, ['7']],
			'where literal narrows' => [$op + ['where' => ['role' => 'beheer']], 'anna', $rows, 7, ['A1', 'A3'], 1, ['7']],
			'where operator narrows' => [$op + ['where' => ['level' => ['$gte' => 3]]], 'anna', $rows, 7, ['A1', 'A2'], 1, ['7']],
			'other user gets own values only' => [$op, 'bob', $rows, 7, ['B1'], 1, ['7']],
			'explicit register' => [$op + ['register' => ' 12 '], 'anna', $rows, null, ['A1', 'A2', 'A3'], 1, ['12']],
			'other match field' => [$op + ['match' => 'role'], 'anna', $rows, 7, [], 1, ['7']],
			'empty user' => [$op, '', $rows, 7, [], 0, []],
			'null user' => [$op, null, $rows, 7, [], 0, []],
			'no register holds the schema' => [$op, 'anna', $rows, null, [], 0, []],
			'unknown schema' => [['schema' => 'nope'] + $op, 'anna', $rows, 7, [], 0, []],
			'search fails' => [$op, 'anna', 'throw', 7, [], 1, ['7']],
			'search answers a count' => [$op, 'anna', 4, 7, [], 1, ['7']],
			'list operand denies' => [['membership', 'administrationId'], 'anna', $rows, 7, null, 0, []],
			'scalar operand denies' => ['membership', 'anna', $rows, 7, null, 0, []],
			'no schema denies' => [['property' => 'administrationId'], 'anna', $rows, 7, null, 0, []],
			'no property denies' => [['schema' => 'membership'], 'anna', $rows, 7, null, 0, []],
			'empty match denies' => [$op + ['match' => ' '], 'anna', $rows, 7, null, 0, []],
			'list where denies' => [$op + ['where' => ['beheer']], 'anna', $rows, 7, null, 0, []],
			'nested memberOf denies' => [$op + ['where' => ['x' => ['$memberOf' => $op]]], 'anna', $rows, 7, null, 0, []],
			'scalar window denies' => [$op + ['current' => 'yes'], 'anna', $rows, 7, null, 0, []],
			'empty window denies' => [$op + ['current' => ['from' => ' ', 'until' => '']], 'anna', $rows, 7, null, 0, []],
			'malformed denies even without a user' => [['schema' => 'membership'], null, $rows, 7, null, 0, []],
		];
	}//end scenarios()

	/**
	 * The set, the search count and the lookups match the pinned ones; a
	 * second ask is served from the request cache.
	 *
	 * @param mixed                  $operand  The `$memberOf` operand.
	 * @param string|null            $userId   The caller.
	 * @param mixed                  $answer   The search answer.
	 * @param integer|null           $holder   The register holding the schema.
	 * @param array<int, string>|null $expected The pinned set.
	 * @param integer                $searches The pinned search count.
	 * @param array<int, string>     $lookups  The pinned register lookups.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testTheSetIsPinned(
		mixed $operand,
		?string $userId,
		mixed $answer,
		?int $holder,
		?array $expected,
		int $searches,
		array $lookups
	): void {
		$resolver = $this->resolver($answer, $holder);

		$this->assertSame($expected, $resolver->valuesFor($operand, $userId));
		$this->assertSame($expected, $resolver->valuesFor($operand, $userId));
		$this->assertSame($searches, $this->searches);
		$this->assertSame($lookups, $this->lookups);
	}//end testTheSetIsPinned()

	/**
	 * `resolveOperators()` intersects with a sibling `$in` and denies on a
	 * malformed operand or a sibling that is not a list.
	 *
	 * @return void
	 */
	public function testResolveOperatorsIsPinned(): void {
		$op       = ['schema' => 'membership', 'property' => 'administrationId'];
		$identity = static fn ($value) => $value;

		$answers = [
			'alone' => $this->resolver(self::rows())->resolveOperators(['$memberOf' => $op], 'anna', $identity),
			'with in' => $this->resolver(self::rows())->resolveOperators(['$memberOf' => $op, '$in' => ['A3', 'Z9', 'A1']], 'anna', $identity),
			'with ne kept' => $this->resolver(self::rows())->resolveOperators(['$memberOf' => $op, '$ne' => 'A2'], 'anna', $identity),
			'in not a list' => $this->resolver(self::rows())->resolveOperators(['$memberOf' => $op, '$in' => ['a' => 'A1']], 'anna', $identity),
			'in scalar' => $this->resolver(self::rows())->resolveOperators(['$memberOf' => $op, '$in' => 'A1'], 'anna', $identity),
			'malformed' => $this->resolver(self::rows())->resolveOperators(['$memberOf' => ['schema' => 'membership']], 'anna', $identity),
			'no user' => $this->resolver(self::rows())->resolveOperators(['$memberOf' => $op, '$in' => ['A1']], null, $identity),
		];

		$this->assertSame(
			[
				'alone' => ['$in' => ['A1', 'A2', 'A3']],
				'with in' => ['$in' => ['A1', 'A3']],
				'with ne kept' => ['$ne' => 'A2', '$in' => ['A1', 'A2', 'A3']],
				'in not a list' => null,
				'in scalar' => null,
				'malformed' => null,
				'no user' => ['$in' => []],
			],
			$answers
		);
	}//end testResolveOperatorsIsPinned()
}//end class
