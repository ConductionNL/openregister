<?php

/**
 * Tests for MemberOfResolver: the caller's set for a `$memberOf` match.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-follows-membership/specs/rbac-scopes/spec.md#requirement-a-match-can-admit-by-the-callers-membership-records
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCA\OpenRegister\Service\Rbac\MemberOfResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The membership set a `$memberOf` operand resolves to.
 */
class MemberOfResolverTest extends TestCase {

	private const OPERAND = [
		'schema'   => 'administration-membership',
		'property' => 'administrationId',
	];

	private SchemaMapper&MockObject $schemas;

	private RegisterMapper&MockObject $registers;

	private MagicMapper&MockObject $objects;

	/**
	 * The searches the resolver ran.
	 *
	 * @var array<int, array{query: array, rbac: bool, multitenancy: bool}>
	 */
	private array $searches = [];

	/**
	 * Wire a membership schema `administration-membership` (id 50) in register 8.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->objects = $this->createMock(MagicMapper::class);

		$membership = new Schema();
		$membership->setId(50);
		$membership->setSlug('administration-membership');
		$this->schemas->method('find')->willReturnCallback(
			static function ($id) use ($membership): Schema {
				if ($id === 'administration-membership' || $id === 50 || $id === '50') {
					return $membership;
				}

				throw new DoesNotExistException('no schema ' . $id);
			}
		);

		$register = new Register();
		$register->setId(8);
		$this->registers->method('getFirstRegisterWithSchema')->willReturn(8);
		$this->registers->method('find')->willReturn($register);
	}//end setUp()

	/**
	 * A resolver over the mocked mappers.
	 *
	 * @return MemberOfResolver
	 */
	private function resolver(): MemberOfResolver {
		return new MemberOfResolver(
			schemas: $this->schemas,
			registers: $this->registers,
			container: $this->containerGiving($this->objects),
			operators: new OperatorEvaluator(new NullLogger()),
			logger: new NullLogger()
		);
	}//end resolver()

	/**
	 * A membership row as the search returns it.
	 *
	 * @param array $data The row's data.
	 *
	 * @return ObjectEntity
	 */
	private function row(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setObject($data);
		return $object;
	}//end row()

	/**
	 * Make the search answer with these rows, whatever it is asked.
	 *
	 * @param array<int, ObjectEntity> $rows The rows.
	 *
	 * @return void
	 */
	private function searchAnswers(array $rows): void {
		$this->objects->method('searchObjects')->willReturnCallback(
			function (
				array $query = [],
				?string $_activeOrgUuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true
			) use ($rows): array {
				$this->searches[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $rows;
			}
		);
	}//end searchAnswers()

	/**
	 * The caller's membership rows give the set, read without RBAC.
	 *
	 * @return void
	 */
	public function testTheCallersRowsGiveTheSet(): void {
		$this->searchAnswers(
			[
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-1']),
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-2']),
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-2']),
			]
		);

		$this->assertSame(['adm-1', 'adm-2'], $this->resolver()->valuesFor(operand: self::OPERAND, userId: 'anna'));
		$this->assertFalse($this->searches[0]['rbac'], 'the read must not re-enter the permission handler');
		$this->assertFalse($this->searches[0]['multitenancy']);
		$this->assertSame(['register' => '8', 'schema' => '50'], $this->searches[0]['query']['@self']);
		$this->assertSame('anna', $this->searches[0]['query']['userId']);
	}//end testTheCallersRowsGiveTheSet()

	/**
	 * A row of another user adds nothing, even when the search returns it.
	 *
	 * @return void
	 */
	public function testAnotherUsersRowIsIgnored(): void {
		$this->searchAnswers(
			[
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-1']),
				$this->row(['userId' => 'bram', 'administrationId' => 'adm-3']),
			]
		);

		$this->assertSame(['adm-1'], $this->resolver()->valuesFor(operand: self::OPERAND, userId: 'anna'));
	}//end testAnotherUsersRowIsIgnored()

	/**
	 * A custom user field is matched and searched on.
	 *
	 * @return void
	 */
	public function testACustomUserFieldIsUsed(): void {
		$this->searchAnswers(
			[
				$this->row(['member' => 'anna', 'administrationId' => 'adm-1']),
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-2']),
			]
		);

		$values = $this->resolver()->valuesFor(operand: self::OPERAND + ['match' => 'member'], userId: 'anna');

		$this->assertSame(['adm-1'], $values);
		$this->assertSame('anna', $this->searches[0]['query']['member']);
	}//end testACustomUserFieldIsUsed()

	/**
	 * The `where` filters keep only the rows whose role is listed.
	 *
	 * @return void
	 */
	public function testWhereFiltersByRole(): void {
		$this->searchAnswers(
			[
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-1', 'role' => 'inkijker']),
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-2', 'role' => 'boekhouder']),
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-3', 'role' => 'controller']),
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-4']),
			]
		);

		$operand = self::OPERAND + ['where' => ['role' => ['$in' => ['boekhouder', 'controller']]]];
		$this->assertSame(['adm-2', 'adm-3'], $this->resolver()->valuesFor(operand: $operand, userId: 'anna'));

		$literal = self::OPERAND + ['where' => ['role' => 'boekhouder']];
		$this->assertSame(['adm-2'], $this->resolver()->valuesFor(operand: $literal, userId: 'anna'));
	}//end testWhereFiltersByRole()

	/**
	 * The validity window drops rows that have not started or have ended.
	 *
	 * @return void
	 */
	public function testCurrentDropsRowsOutsideTheirWindow(): void {
		$yesterday = date('Y-m-d', strtotime('-1 day'));
		$tomorrow  = date('Y-m-d', strtotime('+1 day'));
		$today     = date('Y-m-d');
		$this->searchAnswers(
			[
				$this->row(['userId' => 'anna', 'administrationId' => 'ended', 'validUntil' => $yesterday]),
				$this->row(['userId' => 'anna', 'administrationId' => 'future', 'validFrom' => $tomorrow]),
				$this->row(['userId' => 'anna', 'administrationId' => 'open']),
				$this->row(['userId' => 'anna', 'administrationId' => 'empty', 'validFrom' => '', 'validUntil' => null]),
				$this->row(['userId' => 'anna', 'administrationId' => 'today', 'validFrom' => $today, 'validUntil' => $today . 'T23:00:00+00:00']),
			]
		);

		$operand = self::OPERAND + ['current' => ['from' => 'validFrom', 'until' => 'validUntil']];

		$this->assertSame(['open', 'empty', 'today'], $this->resolver()->valuesFor(operand: $operand, userId: 'anna'));
	}//end testCurrentDropsRowsOutsideTheirWindow()

	/**
	 * An array-valued property contributes each of its values.
	 *
	 * @return void
	 */
	public function testAnArrayPropertyContributesEachValue(): void {
		$this->searchAnswers([$this->row(['userId' => 'anna', 'administrationId' => ['adm-1', 'adm-2', '']])]);

		$this->assertSame(['adm-1', 'adm-2'], $this->resolver()->valuesFor(operand: self::OPERAND, userId: 'anna'));
	}//end testAnArrayPropertyContributesEachValue()

	/**
	 * Nobody signed in has no memberships and nothing is read.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerHasAnEmptySet(): void {
		$this->searchAnswers([$this->row(['userId' => '', 'administrationId' => 'adm-1'])]);

		$this->assertSame([], $this->resolver()->valuesFor(operand: self::OPERAND, userId: null));
		$this->assertSame([], $this->resolver()->valuesFor(operand: self::OPERAND, userId: ''));
		$this->assertSame([], $this->searches);
	}//end testAnAnonymousCallerHasAnEmptySet()

	/**
	 * Each malformed operand is unresolvable, which the caller turns into a deny.
	 *
	 * @return void
	 */
	public function testAMalformedOperandIsUnresolvable(): void {
		$this->searchAnswers([$this->row(['userId' => 'anna', 'administrationId' => 'adm-1'])]);
		$resolver = $this->resolver();

		$malformed = [
			'not an object'      => 'administration-membership',
			'a list'             => ['administration-membership', 'administrationId'],
			'no schema'          => ['property' => 'administrationId'],
			'no property'        => ['schema' => 'administration-membership'],
			'an empty match'     => self::OPERAND + ['match' => ' '],
			'a list where'       => self::OPERAND + ['where' => ['role']],
			'a scalar where'     => self::OPERAND + ['where' => 'boekhouder'],
			'a scalar current'   => self::OPERAND + ['current' => 'validFrom'],
			'an empty current'   => self::OPERAND + ['current' => []],
			'a nested memberOf'  => self::OPERAND + ['where' => ['x' => ['$memberOf' => self::OPERAND]]],
		];

		foreach ($malformed as $label => $operand) {
			$this->assertNull($resolver->valuesFor(operand: $operand, userId: 'anna'), $label);
		}

		$this->assertSame([], $this->searches, 'a malformed operand reads nothing');
	}//end testAMalformedOperandIsUnresolvable()

	/**
	 * A membership schema that cannot be found admits nobody.
	 *
	 * @return void
	 */
	public function testAMissingSchemaGivesAnEmptySet(): void {
		$this->searchAnswers([$this->row(['userId' => 'anna', 'administrationId' => 'adm-1'])]);

		$operand = ['schema' => 'no-such-schema', 'property' => 'administrationId'];

		$this->assertSame([], $this->resolver()->valuesFor(operand: $operand, userId: 'anna'));
	}//end testAMissingSchemaGivesAnEmptySet()

	/**
	 * A read that throws admits nobody.
	 *
	 * @return void
	 */
	public function testAFailingReadGivesAnEmptySet(): void {
		$this->objects->method('searchObjects')->willThrowException(new \RuntimeException('database gone'));

		$this->assertSame([], $this->resolver()->valuesFor(operand: self::OPERAND, userId: 'anna'));
	}//end testAFailingReadGivesAnEmptySet()

	/**
	 * A container that cannot give MagicMapper on the first read also admits nobody.
	 *
	 * @return void
	 */
	public function testAContainerThatCannotGiveTheMapperGivesAnEmptySet(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no such service'));
		$resolver = new MemberOfResolver(
			schemas: $this->schemas,
			registers: $this->registers,
			container: $container,
			operators: new OperatorEvaluator(new NullLogger()),
			logger: new NullLogger()
		);

		$this->assertSame([], $resolver->valuesFor(operand: self::OPERAND, userId: 'anna'));
	}//end testAContainerThatCannotGiveTheMapperGivesAnEmptySet()

	/**
	 * The set is read once per caller and operand for the life of the resolver.
	 *
	 * @return void
	 */
	public function testTheSetIsReadOncePerCallerAndOperand(): void {
		$this->searchAnswers(
			[
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-1']),
				$this->row(['userId' => 'bram', 'administrationId' => 'adm-3']),
			]
		);
		$resolver = $this->resolver();

		for ($i = 0; $i < 5; $i++) {
			$this->assertSame(['adm-1'], $resolver->valuesFor(operand: self::OPERAND, userId: 'anna'));
		}

		$this->assertCount(1, $this->searches);

		$this->assertSame(['adm-3'], $resolver->valuesFor(operand: self::OPERAND, userId: 'bram'));
		$this->assertCount(2, $this->searches, 'another caller is read for themselves');

		$resolver->valuesFor(operand: self::OPERAND + ['where' => ['role' => 'boekhouder']], userId: 'anna');
		$this->assertCount(3, $this->searches, 'another operand is read for itself');
	}//end testTheSetIsReadOncePerCallerAndOperand()

	/**
	 * `$memberOf` becomes `$in` over the set; siblings are resolved by the caller and still apply.
	 *
	 * @return void
	 */
	public function testResolveOperatorsTurnsMemberOfIntoIn(): void {
		$this->searchAnswers(
			[
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-1']),
				$this->row(['userId' => 'anna', 'administrationId' => 'adm-2']),
			]
		);
		$resolver = $this->resolver();
		$sibling = static fn (mixed $value): mixed => ($value === '$userId') ? 'anna' : $value;

		$this->assertSame(
			['$in' => ['adm-1', 'adm-2']],
			$resolver->resolveOperators(operators: ['$memberOf' => self::OPERAND], userId: 'anna', resolveSibling: $sibling)
		);
		$this->assertSame(
			['$in' => ['adm-2']],
			$resolver->resolveOperators(
				operators: ['$memberOf' => self::OPERAND, '$in' => ['adm-2', 'adm-9']],
				userId: 'anna',
				resolveSibling: $sibling
			),
			'a sibling $in narrows the set'
		);
		$this->assertSame(
			['$ne' => 'anna', '$in' => ['adm-1', 'adm-2']],
			$resolver->resolveOperators(
				operators: ['$memberOf' => self::OPERAND, '$ne' => '$userId'],
				userId: 'anna',
				resolveSibling: $sibling
			),
			'another sibling is resolved and kept'
		);
	}//end testResolveOperatorsTurnsMemberOfIntoIn()

	/**
	 * An unresolvable operand or a sibling `$in` that is not a list resolves to null (deny).
	 *
	 * @return void
	 */
	public function testResolveOperatorsDeniesWhatItCannotResolve(): void {
		$this->searchAnswers([$this->row(['userId' => 'anna', 'administrationId' => 'adm-1'])]);
		$resolver = $this->resolver();
		$sibling = static fn (mixed $value): mixed => $value;

		$this->assertNull($resolver->resolveOperators(operators: ['$memberOf' => ['schema' => '']], userId: 'anna', resolveSibling: $sibling));
		$this->assertNull($resolver->resolveOperators(operators: ['$memberOf' => null], userId: 'anna', resolveSibling: $sibling));
		$this->assertNull(
			$resolver->resolveOperators(operators: ['$memberOf' => self::OPERAND, '$in' => 'adm-1'], userId: 'anna', resolveSibling: $sibling)
		);
		$this->assertSame(
			['$in' => []],
			$resolver->resolveOperators(operators: ['$memberOf' => self::OPERAND], userId: null, resolveSibling: $sibling),
			'nobody signed in is a member of nothing'
		);
	}//end testResolveOperatorsDeniesWhatItCannotResolve()

	/**
	 * The read is capped, and the cap can only hide rows.
	 *
	 * @return void
	 */
	public function testTheReadIsCapped(): void {
		$this->searchAnswers([$this->row(['userId' => 'anna', 'administrationId' => 'adm-1'])]);

		$this->resolver()->valuesFor(operand: self::OPERAND, userId: 'anna');

		$this->assertSame(MemberOfResolver::ROW_LIMIT, $this->searches[0]['query']['_limit']);
	}//end testTheReadIsCapped()

	/**
	 * A container that hands out the given MagicMapper, as the real one does on first read.
	 *
	 * @param MagicMapper $objects The mapper to hand out.
	 *
	 * @return ContainerInterface
	 */
	private function containerGiving(MagicMapper $objects): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): mixed => ($id === MagicMapper::class) ? $objects : null
		);
		return $container;
	}//end containerGiving()

	/**
	 * Regression (openregister#4741): constructing the resolver must not resolve MagicMapper.
	 *
	 * MagicMapper's constructor resolves ConditionMatcher, which autowires this class; a
	 * resolver that pulled MagicMapper at construction recursed until the stack ran out.
	 *
	 * @return void
	 */
	public function testConstructionDoesNotResolveMagicMapper(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		new MemberOfResolver(
			schemas: $this->schemas,
			registers: $this->registers,
			container: $container,
			operators: new OperatorEvaluator(new NullLogger()),
			logger: new NullLogger()
		);
	}//end testConstructionDoesNotResolveMagicMapper()

	/**
	 * Regression (openregister#4741): no constructor parameter may be a MagicMapper.
	 *
	 * @return void
	 */
	public function testConstructorTakesNoMagicMapper(): void {
		foreach ((new \ReflectionClass(MemberOfResolver::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertFalse(
				$type instanceof \ReflectionNamedType && is_a($type->getName(), MagicMapper::class, true),
				'MemberOfResolver must resolve MagicMapper lazily: ' . $parameter->getName()
			);
		}
	}//end testConstructorTakesNoMagicMapper()
}//end class
