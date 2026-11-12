<?php

/**
 * Characterisation tests for how ObjectScopeResolver reads the declared scope
 * and the owning group from an authorization block.
 *
 * Pins every input shape of `declaredScope()` (no block, unset, the one
 * non-private value, every other value failing closed to private) and of
 * `ownerGroup()` (no block, unset, unreadable values, a group id).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for declaredScope() and ownerGroup().
 */
class ObjectScopeResolverDeclarationGoldenTest extends TestCase {

	/**
	 * Every value of `scope` reads as before; anything unrecognised is private.
	 *
	 * @return void
	 */
	public function testDeclaredScopeForEveryShape(): void {
		$resolver = new ObjectScopeResolver();
		$cases    = [
			'no block'          => [null, null],
			'empty block'       => [[], null],
			'null scope'        => [['scope' => null], null],
			'empty scope'       => [['scope' => ''], null],
			'organisation'      => [['scope' => 'organisation'], 'organisation'],
			'private'           => [['scope' => 'private'], 'private'],
			'differently cased' => [['scope' => 'Organisation'], 'private'],
			'padded'            => [['scope' => ' organisation'], 'private'],
			'unknown word'      => [['scope' => 'public'], 'private'],
			'number'            => [['scope' => 0], 'private'],
			'false'             => [['scope' => false], 'private'],
			'list'              => [['scope' => ['organisation']], 'private'],
		];

		foreach ($cases as $label => [$authorization, $expected]) {
			$this->assertSame($expected, $resolver->declaredScope($authorization), $label);
		}
	}//end testDeclaredScopeForEveryShape()

	/**
	 * Only a non-empty string names an owning group.
	 *
	 * @return void
	 */
	public function testOwnerGroupForEveryShape(): void {
		$resolver = new ObjectScopeResolver();
		$cases    = [
			'no block'     => [null, null],
			'empty block'  => [[], null],
			'null'         => [['ownerGroup' => null], null],
			'empty string' => [['ownerGroup' => ''], null],
			'number'       => [['ownerGroup' => 7], null],
			'list'         => [['ownerGroup' => ['redactie']], null],
			'group id'     => [['ownerGroup' => 'redactie'], 'redactie'],
			'padded id'    => [['ownerGroup' => ' redactie '], ' redactie '],
		];

		foreach ($cases as $label => [$authorization, $expected]) {
			$this->assertSame($expected, $resolver->ownerGroup($authorization), $label);
		}
	}//end testOwnerGroupForEveryShape()
}//end class
