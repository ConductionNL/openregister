<?php

/**
 * OpenRegister ReferenceLoopCheckTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\ReferenceLoopCheck;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A save refuses a reference that comes back to itself and nothing else.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\ReferenceLoopCheck
 * @covers \OCA\OpenRegister\Db\SchemaMapper\ReferenceLoops
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition
 */
final class ReferenceLoopCheckTest extends TestCase {
	/**
	 * Properties that loop, with the pointers named in the refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, string>}>
	 */
	public static function loops(): array {
		$self = ['$ref' => '#/properties/a'];

		return [
			'itself'         => [['a' => $self], ['/properties/a']],
			'a pair'         => [['a' => ['$ref' => '#/properties/b'], 'b' => $self], ['/properties/a', '/properties/b']],
			'a way into one' => [['p' => ['$ref' => '#/properties/a'], 'a' => ['$ref' => '#/properties/b'], 'b' => $self], ['/properties/a', '/properties/b']],
			'through allOf'  => [['a' => ['allOf' => [$self]]], ['/properties/a/allOf/0']],
			'through anyOf'  => [['a' => ['anyOf' => [['type' => 'string'], $self]]], ['/properties/a/anyOf/1']],
			'through oneOf'  => [['a' => ['oneOf' => [$self]]], ['/properties/a/oneOf/0']],
			'through not'    => [['a' => ['not' => $self]], ['/properties/a/not']],
			'through if'     => [['a' => ['if' => $self]], ['/properties/a/if']],
			'through then'   => [['a' => ['then' => $self]], ['/properties/a/then']],
			'through else'   => [['a' => ['else' => $self]], ['/properties/a/else']],
			'a list member'  => [['a' => ['allOf' => [['$ref' => '#/properties/a/allOf/0']]]], ['/properties/a/allOf/0']],
			'a slash in key' => [['a/b' => ['$ref' => '#/properties/a~1b']], ['/properties/a~1b']],
			'a tilde in key' => [['a~b' => ['$ref' => '#/properties/a~0b']], ['/properties/a~0b']],
		];
	}//end loops()

	/**
	 * A loop is refused, naming each reference on it.
	 *
	 * @param array<string, mixed> $properties The properties.
	 * @param array<int, string>   $pointers   The pointers named.
	 *
	 * @return void
	 */
	#[DataProvider('loops')]
	public function testALoopIsRefused(array $properties, array $pointers): void {
		$sentences = array_map(
			static fn (string $pointer): string => 'Schema: the $ref at "#' . $pointer . '" comes back to itself without moving through the data.',
			$pointers
		);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(implode(' ', $sentences));

		(new ReferenceLoopCheck())->check($this->schema(properties: $properties));
	}//end testALoopIsRefused()

	/**
	 * References a save lets through.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function fine(): array {
		$node = ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/properties/node']]];

		return [
			'no reference'            => [['a' => ['type' => 'string']]],
			'to another property'     => [['size' => ['type' => 'number'], 'a' => ['$ref' => '#/properties/size']]],
			'descends by properties'  => [['node' => $node]],
			'descends by items'       => [['node' => ['type' => 'array', 'items' => ['$ref' => '#/properties/node']]]],
			'the root, by properties' => [['next' => ['$ref' => '#']]],
			'a relation'              => [['a' => ['$ref' => '#/components/schemas/a']]],
			'a slug'                  => [['a' => ['$ref' => 'a']]],
			'a url'                   => [['a' => ['$ref' => 'https://example.org/a.json#/properties/a']]],
			'a reference number'      => [['a' => ['$ref' => 7]]],
			'a pointer to a value'    => [['a' => ['type' => 'string', 'allOf' => [['$ref' => '#/properties/a/type']]]]],
		];
	}//end fine()

	/**
	 * Anything else saves.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return void
	 */
	#[DataProvider('fine')]
	public function testEverythingElseSaves(array $properties): void {
		$refusal = $this->refusalOf($this->schema(properties: $properties));

		// Pair: testALoopIsRefused, the same shapes pointed back at themselves.
		$this->assertNull($refusal, 'a reference that does not loop was refused');
	}//end testEverythingElseSaves()

	/**
	 * Action inputs that loop.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function inputLoops(): array {
		return [
			'a property' => [['properties' => ['a' => ['$ref' => '#/properties/a']]], '#/properties/a'],
			'the root'   => [['$ref' => '#'], '#'],
		];
	}//end inputLoops()

	/**
	 * The inputs of a declared action are their own document, named by their action.
	 *
	 * @param array<string, mixed> $inputs  The input schema.
	 * @param string               $pointer The pointer named.
	 *
	 * @return void
	 */
	#[DataProvider('inputLoops')]
	public function testALoopInActionInputsIsRefusedNamingTheAction(array $inputs, string $pointer): void {
		$schema = $this->schema(properties: []);
		$schema->setConfiguration([ObjectActionDefinition::ACTION_BLOCK => ['close' => ['inputs' => $inputs]]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Action "close" inputs: the $ref at "' . $pointer . '" comes back');

		(new ReferenceLoopCheck())->check($schema);
	}//end testALoopInActionInputsIsRefusedNamingTheAction()

	/**
	 * Action inputs do not reach into the schema's properties.
	 *
	 * @return void
	 */
	public function testActionInputsResolveInsideThemselves(): void {
		$schema = $this->schema(properties: ['b' => ['$ref' => '#/properties/a'], 'a' => ['type' => 'string']]);
		$inputs = ['properties' => ['a' => ['$ref' => '#/properties/b']]];
		$schema->setConfiguration([ObjectActionDefinition::ACTION_BLOCK => ['close' => ['inputs' => $inputs]]]);

		$refusal = $this->refusalOf($schema);

		// Resolved against the schema's properties these two would form a loop
		// (b -> a in properties, a -> b in inputs). Pair:
		// testALoopInActionInputsIsRefusedNamingTheAction, a loop within the inputs.
		$this->assertNull($refusal, 'action inputs were resolved against the schema properties');
	}//end testActionInputsResolveInsideThemselves()

	/**
	 * Run the check and return its refusal, or null when the save may go on.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return InvalidArgumentException|null The refusal.
	 */
	private function refusalOf(Schema $schema): ?InvalidArgumentException {
		try {
			(new ReferenceLoopCheck())->check($schema);
		} catch (InvalidArgumentException $refusal) {
			return $refusal;
		}

		return null;
	}//end refusalOf()

	/**
	 * A schema over these properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);

		return $schema;
	}//end schema()
}//end class
