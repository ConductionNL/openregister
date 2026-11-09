<?php

/**
 * Golden test for CompositionHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\GraphQL;

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\CompositionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fields applyComposition() produces and the callbacks it makes.
 *
 * @covers \OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator\CompositionHandler
 */
class CompositionHandlerGoldenTest extends TestCase {

	/**
	 * Field names per referenced schema slug; slugs not listed do not resolve.
	 */
	private const REF_FIELDS = [
		'base'    => ['title', 'status', '_id'],
		'audit'   => ['title', 'createdBy'],
		'7'       => ['seven'],
		'empty'   => [],
		'person'  => ['name', 'email', '_meta'],
		'company' => ['name', 'kvk', 'email', '_meta'],
	];

	/**
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Case => [allOf, oneOf, anyOf, slug, own fields, expected recording].
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: ?string, 4: list<string>, 5: array<string, mixed>}>
	 */
	public static function cases(): array {
		return [
			'nothing composed' => [null, null, null, 'thing', ['own'], [
				'fields' => ['own' => 'String:own field'],
				'calls'  => [],
			]],
			'allOf mixed refs, own fields win' => [
				['base', ['$ref' => 'audit'], ['other' => 'x'], 'missing', 7, 'empty'],
				[],
				[],
				'thing',
				['title', 'own'],
				[
					'fields' => [
						'seven'     => 'String:7 field',
						'title'     => 'String:own field',
						'createdBy' => 'String:audit field',
						'status'    => 'String:base field',
						'_id'       => 'String:base field',
						'own'       => 'String:own field',
					],
					'calls'  => [
						'resolve:base', 'fields:base', 'resolve:audit', 'fields:audit',
						'resolve:missing', 'resolve:7', 'fields:7', 'resolve:empty', 'fields:empty',
					],
				],
			],
			'oneOf union with slug' => [
				null,
				['person', ['$ref' => 'company'], ['nope' => 1], 42, 'missing'],
				null,
				'party',
				['own'],
				[
					'fields' => [
						'own'    => 'String:own field',
						'_oneOf' => 'union PartyUnion[Person,Company]:One of the composed types',
					],
					'calls'  => [
						'resolve:person', 'type:person', 'resolve:company', 'type:company',
						'resolve:missing', 'name:party',
					],
				],
			],
			'oneOf union without slug' => [null, ['person'], null, null, [], [
				'fields' => ['_oneOf' => 'union Schema5Union[Person]:One of the composed types'],
				'calls'  => ['resolve:person', 'type:person', 'name:Schema5'],
			]],
			'oneOf unresolved' => [null, ['missing'], null, 'party', [], [
				'fields' => [],
				'calls'  => ['resolve:missing'],
			]],
			'oneOf empty array' => [null, [], null, 'party', [], [
				'fields' => [],
				'calls'  => [],
			]],
			'anyOf shared fields with empty slug' => [null, null, ['person', ['$ref' => 'company']], '', ['own'], [
				'fields' => [
					'own'    => 'String:own field',
					'_anyOf' => 'interface Schema5Interface{name:String:person field,email:String:person field}'
						. ':Any of the composed types (shared fields)',
				],
				'calls'  => ['resolve:person', 'type:person', 'resolve:company', 'type:company', 'name:Schema5'],
			]],
			'anyOf nothing shared' => [null, null, ['person', 'base'], 'party', [], [
				'fields' => [],
				'calls'  => ['resolve:person', 'type:person', 'resolve:base', 'type:base'],
			]],
			'anyOf unresolved' => [null, null, ['missing'], 'party', [], [
				'fields' => [],
				'calls'  => ['resolve:missing'],
			]],
			'all three together' => [['audit'], ['person'], ['person', 'company'], 'mix', ['own'], [
				'fields' => [
					'title'     => 'String:audit field',
					'createdBy' => 'String:audit field',
					'own'       => 'String:own field',
					'_oneOf'    => 'union MixUnion[Person]:One of the composed types',
					'_anyOf'    => 'interface MixInterface{name:String:person field,email:String:person field}'
						. ':Any of the composed types (shared fields)',
				],
				'calls'  => [
					'resolve:audit', 'fields:audit', 'resolve:person', 'type:person', 'name:mix',
					'resolve:person', 'type:person', 'resolve:company', 'type:company', 'name:mix',
				],
			]],
		];
	}

	/**
	 * @param mixed                $allOf    The allOf value.
	 * @param mixed                $oneOf    The oneOf value.
	 * @param mixed                $anyOf    The anyOf value.
	 * @param string|null          $slug     The schema slug.
	 * @param list<string>         $own      The schema's own field names.
	 * @param array<string, mixed> $expected The expected recording.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testApplyComposition(mixed $allOf, mixed $oneOf, mixed $anyOf, ?string $slug, array $own, array $expected): void {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug($slug);
		$schema->setAllOf($allOf);
		$schema->setOneOf($oneOf);
		$schema->setAnyOf($anyOf);

		$fields = $this->fieldsFor('own', $own);
		$this->handler()->applyComposition($schema, $fields);

		$this->assertSame($expected, ['fields' => $this->describeFields($fields), 'calls' => $this->calls]);
	}

	private function handler(): CompositionHandler {
		$types = [];
		return new CompositionHandler(
			function ($slug): ?Schema {
				$this->calls[] = 'resolve:' . $slug;
				if (array_key_exists((string) $slug, self::REF_FIELDS) === false) {
					return null;
				}
				$schema = new Schema();
				$schema->setSlug((string) $slug);
				return $schema;
			},
			function (Schema $schema) use (&$types): ObjectType {
				$slug = (string) $schema->getSlug();
				$this->calls[] = 'type:' . $slug;
				$types[$slug] ??= new ObjectType([
					'name'   => ucfirst($slug),
					'fields' => $this->fieldsFor($slug, self::REF_FIELDS[$slug]),
				]);
				return $types[$slug];
			},
			function (Schema $schema): array {
				$slug = (string) $schema->getSlug();
				$this->calls[] = 'fields:' . $slug;
				return $this->fieldsFor($slug, self::REF_FIELDS[$slug]);
			},
			function (string $slug, ?int $suffix): string {
				$call = 'name:' . $slug;
				if ($suffix !== null) {
					$call .= ':' . $suffix;
				}
				$this->calls[] = $call;
				return ucfirst($slug);
			}
		);
	}

	/**
	 * @param string       $owner The owning slug used in the description.
	 * @param list<string> $names The field names.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fieldsFor(string $owner, array $names): array {
		$fields = [];
		foreach ($names as $name) {
			$fields[$name] = ['type' => Type::string(), 'description' => $owner . ' field'];
		}
		return $fields;
	}

	/**
	 * @param array<string, mixed> $fields The fields to describe.
	 *
	 * @return array<string, string>
	 */
	private function describeFields(array $fields): array {
		$out = [];
		foreach ($fields as $name => $field) {
			$out[$name] = $this->describeType($field['type']) . ':' . $field['description'];
		}
		return $out;
	}

	private function describeType(Type $type): string {
		if ($type instanceof UnionType) {
			$names = array_map(static fn (ObjectType $t): string => $t->name, $type->getTypes());
			return 'union ' . $type->name . '[' . implode(',', $names) . ']';
		}
		if ($type instanceof InterfaceType) {
			$parts = [];
			foreach ($type->getFields() as $field) {
				$parts[] = $field->name . ':' . $field->getType()->toString() . ':' . $field->description;
			}
			return 'interface ' . $type->name . '{' . implode(',', $parts) . '}';
		}
		return $type->toString();
	}
}
