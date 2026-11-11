<?php

/**
 * ReflectionJsonType unit tests.
 *
 * Maps real PHP reflection types (named, nullable, union, intersection, DNF,
 * untyped) to their JSON-Schema type and pins every branch's decision.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use ArrayObject;
use Countable;
use Iterator;
use OCA\OpenRegister\Mcp\ReflectionJsonType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionType;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Mcp\ReflectionJsonType
 */
class ReflectionJsonTypeTest extends TestCase {

	/**
	 * Reflection type of the closure's first parameter.
	 *
	 * @param \Closure $closure Closure whose first parameter carries the type.
	 *
	 * @return ReflectionType|null The type.
	 */
	private static function typeOf(\Closure $closure): ?ReflectionType {
		return (new ReflectionFunction($closure))->getParameters()[0]->getType();
	}//end typeOf()

	/**
	 * Type cases: closure => expected JSON-Schema type.
	 *
	 * @return array<string, array{0: \Closure, 1: string|array|null}>
	 */
	public static function typeCases(): array {
		return [
			'untyped'               => [static function ($a) {}, null],
			'int'                   => [static function (int $a) {}, 'integer'],
			'float'                 => [static function (float $a) {}, 'number'],
			'string'                => [static function (string $a) {}, 'string'],
			'bool'                  => [static function (bool $a) {}, 'boolean'],
			'array'                 => [static function (array $a) {}, 'array'],
			'iterable'              => [static function (iterable $a) {}, 'array'],
			'mixed'                 => [static function (mixed $a) {}, null],
			'nullable callable'     => [static function (?callable $a) {}, null],
			'class'                 => [static function (stdClass $a) {}, 'object'],
			'nullable int'          => [static function (?int $a) {}, ['integer', 'null']],
			'nullable class'        => [static function (?stdClass $a) {}, ['object', 'null']],
			'union int|string'      => [static function (int|string $a) {}, ['string', 'integer']],
			'union int|string|null' => [static function (int|string|null $a) {}, ['string', 'integer', 'null']],
			'union collapses dupes' => [static function (stdClass|ArrayObject $a) {}, 'object'],
			'union single mapped'   => [static function (int|false $a) {}, 'integer'],
			'union nothing mapped'  => [static function (callable|false $a) {}, null],
			'intersection'          => [static function (Countable&Iterator $a) {}, null],
		];
	}//end typeCases()

	/**
	 * Every reflection form maps to its documented JSON-Schema type.
	 *
	 * @param \Closure $closure Closure carrying the type.
	 * @param string|array|null $expected Expected JSON-Schema type.
	 *
	 * @return void
	 */
	#[DataProvider('typeCases')]
	public function testOfMapsReflectionTypes(\Closure $closure, string|array|null $expected): void {
		$this->assertSame($expected, ReflectionJsonType::of(self::typeOf($closure)));
	}//end testOfMapsReflectionTypes()

	/**
	 * A null reflection type (untyped) is unconstrained.
	 *
	 * @return void
	 */
	public function testOfNullIsUnconstrained(): void {
		$this->assertNull(ReflectionJsonType::of(null));
	}//end testOfNullIsUnconstrained()

	/**
	 * memberType: null member is 'null', a non-named member is null, a named member is mapped.
	 *
	 * @return void
	 */
	public function testMemberTypeDecisions(): void {
		$dnf     = self::typeOf(static function ((Countable&Iterator)|int $a) {});
		$members = $dnf->getTypes();

		$this->assertNull(ReflectionJsonType::memberType($members[0]));
		$this->assertSame('integer', ReflectionJsonType::memberType($members[1]));

		$nullable = self::typeOf(static function (int|string|null $a) {});
		$this->assertSame('null', ReflectionJsonType::memberType($nullable->getTypes()[2]));
	}//end testMemberTypeDecisions()

	/**
	 * named() maps a builtin without nullability wrapping.
	 *
	 * @return void
	 */
	public function testNamedIgnoresNullability(): void {
		$type = self::typeOf(static function (?float $a) {});
		$this->assertSame('number', ReflectionJsonType::named($type));
	}//end testNamedIgnoresNullability()
}//end class
