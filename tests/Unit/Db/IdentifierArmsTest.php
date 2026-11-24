<?php

/**
 * The single-column arms an id, uuid or slug identifier is looked up through.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\IdentifierArms;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\IdentifierArms
 */
final class IdentifierArmsTest extends TestCase {
	public function testANumericIdentifierAsksTheIdArmFirstThenSlugThenUuid(): void {
		$this->assertSame(['id', 'slug', 'uuid'], IdentifierArms::of(7));
		$this->assertSame(['id', 'slug', 'uuid'], IdentifierArms::of('42'));
	}//end testANumericIdentifierAsksTheIdArmFirstThenSlugThenUuid()

	public function testAUuidShapedIdentifierAsksTheUuidArmFirstInAnyCase(): void {
		$this->assertSame(['uuid', 'slug'], IdentifierArms::of('0b6c6f1e-8d5a-4c2b-9f3e-2a1d4c5b6e7f'));
		$this->assertSame(['uuid', 'slug'], IdentifierArms::of('0B6C6F1E-8D5A-4C2B-9F3E-2A1D4C5B6E7F'));
	}//end testAUuidShapedIdentifierAsksTheUuidArmFirstInAnyCase()

	public function testAnyOtherIdentifierAsksTheSlugArmFirstThenUuid(): void {
		$this->assertSame(['slug', 'uuid'], IdentifierArms::of('publicatie'));
		$this->assertSame(['slug', 'uuid'], IdentifierArms::of('7abc'));
		// One character short of a UUID is a slug, not a uuid.
		$this->assertSame(['slug', 'uuid'], IdentifierArms::of('0b6c6f1e-8d5a-4c2b-9f3e-2a1d4c5b6e7'));
	}//end testAnyOtherIdentifierAsksTheSlugArmFirstThenUuid()

	public function testEachArmComparesOneColumnWithTheParameterTypeItNeeds(): void {
		$qb = $this->recordingBuilder();

		$this->assertSame('id = :p(7,int)', IdentifierArms::condition($qb, IdentifierArms::ID, '7'));
		$this->assertSame('uuid = :p(Abc-1,str)', IdentifierArms::condition($qb, IdentifierArms::UUID, 'Abc-1'));
		$this->assertSame('LOWER(slug) = :p(publicatie,str)', IdentifierArms::condition($qb, IdentifierArms::SLUG, 'Publicatie'));
		$this->assertSame('uuid = :p(12,str)', IdentifierArms::condition($qb, IdentifierArms::UUID, 12));
	}//end testEachArmComparesOneColumnWithTheParameterTypeItNeeds()

	/**
	 * A query builder whose expressions render as readable text.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function recordingBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static fn (mixed $left, mixed $right): string => (string)$left . ' = ' . (string)$right
		);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('lower')->willReturnCallback(
			function (string $column): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn('LOWER(' . $column . ')');
				return $function;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (mixed $value, mixed $type): string {
				// The value's PHP type must match the declared parameter type.
				$kind = 'mismatch';
				if ($type === IQueryBuilder::PARAM_INT && is_int($value) === true) {
					$kind = 'int';
				}

				if ($type === IQueryBuilder::PARAM_STR && is_string($value) === true) {
					$kind = 'str';
				}

				return ':p(' . $value . ',' . $kind . ')';
			}
		);

		return $qb;
	}//end recordingBuilder()
}//end class
