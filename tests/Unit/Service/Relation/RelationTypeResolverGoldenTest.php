<?php

/**
 * Golden test for RelationTypeResolver.
 *
 * Pins the exact descriptor set, and the rows built from it, for one schema
 * that exercises every branch the resolver has: vocabulary hits and misses,
 * own declarations over vocabulary entries, both inheritance forms with their
 * invalid entries, per-language and malformed labels, symmetric relations,
 * declared and undeclared link exposure, and array-valued references. Taken
 * ahead of a unit-size refactor of the resolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for the relation descriptor resolver.
 */
class RelationTypeResolverGoldenTest extends TestCase {

	/**
	 * A schema touching every branch of the resolver.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setConfiguration(
			[
				'x-openregister-relation-types' => [
					[
						'key' => ' parent ',
						'label' => ['nl' => 'ouder', 'en' => 'parent'],
						'inverseLabel' => 'kind',
						'inherits' => ['classification', 'bogus', 3],
					],
					(object)['key' => 'peer', 'label' => 'peer of', 'symmetric' => true, 'inverseLabel' => 'ignored'],
					['key' => 'exposing', 'label' => ' ', 'exposes' => ['name', 7]],
					['key' => '', 'label' => 'no key'],
					['label' => 'missing key'],
					'not an entry',
					[
						'key' => 'odd',
						'symmetric' => 'yes',
						'inherits' => (object)['confidentiality' => ' level ', 'responsible' => ' ', 'bogus' => 'x', 'classification' => 5],
					],
				],
			]
		);
		$schema->setProperties(
			[
				'plain'      => ['$ref' => '#/components/schemas/a', 'title' => ' Plain title '],
				'untitled'   => ['$ref' => '#/components/schemas/a'],
				'notref'     => ['type' => 'string', 'x-openregister-relation' => ['label' => 'x']],
				'parentRef'  => ['$ref' => 'p', 'x-openregister-relation' => ['type' => ' parent ']],
				'override'   => ['$ref' => 'p', 'x-openregister-relation' => ['type' => 'parent', 'label' => 'own', 'inherits' => []]],
				'peerRef'    => ['inversedBy' => 'x', 'x-openregister-relation' => (object)['type' => 'peer']],
				'unknown'    => ['$ref' => 'p', 'title' => 'Unknown', 'x-openregister-relation' => ['type' => 'nope', 'inverseLabel' => 'back']],
				'exposeRef'  => ['$ref' => 'p', 'x-openregister-relation' => ['type' => 'exposing']],
				'exposeNone' => ['$ref' => 'p', 'x-openregister-relation' => ['exposes' => []]],
				'exposeBad'  => ['$ref' => 'p', 'x-openregister-relation' => ['exposes' => 'name']],
				'oddRef'     => ['$ref' => 'p', 'x-openregister-relation' => ['type' => 'odd']],
				'arrayRef'   => [
					'type' => 'array',
					'items' => ['$ref' => 'p', 'x-openregister-relation' => ['label' => ['de' => 'Teil', 'fr' => '']]],
				],
				'arrayObj'   => ['type' => 'array', 'items' => (object)['inversedBy' => 'q']],
				'mapLabel'   => [
					'$ref' => 'p',
					'x-openregister-relation' => ['label' => ['en-GB' => ' colour '], 'inverseLabel' => ['xx' => 3, 'yy' => ' tail ']],
				],
				'badLabel'   => ['$ref' => 'p', 'title' => '', 'x-openregister-relation' => ['label' => 42, 'inverseLabel' => ['a' => ' ']]],
				'badDecl'    => ['$ref' => 'p', 'x-openregister-relation' => 'string'],
				'inheritStr' => ['$ref' => 'p', 'x-openregister-relation' => ['inherits' => 'classification']],
				'objProp'    => (object)['$ref' => 'p', 'title' => 'Object property'],
				'scalar'     => 'nothing',
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The languages the descriptors are pinned in.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function languageProvider(): array {
		return [
			'nl'    => ['nl'],
			'en'    => ['en'],
			'en-GB' => ['en-GB'],
			'de-AT' => ['de-AT'],
		];
	}//end languageProvider()

	/**
	 * Every descriptor, and every row built from it in both directions, matches the pin.
	 *
	 * @param string $language The language.
	 *
	 * @return void
	 */
	#[DataProvider('languageProvider')]
	public function testDescriptorsAndRowsMatchThePin(string $language): void {
		$resolver    = new RelationTypeResolver();
		$descriptors = $resolver->descriptors(schema: self::schema(), language: $language);

		$rows = [];
		foreach ($descriptors as $name => $descriptor) {
			$rows[$name] = [
				$resolver->row(descriptor: $descriptor, direction: RelationTypeResolver::DIRECTION_OUTGOING, path: $name.'.0'),
				$resolver->row(descriptor: $descriptor, direction: RelationTypeResolver::DIRECTION_INCOMING),
			];
		}

		$actual = (string)json_encode(value: ['descriptors' => $descriptors, 'rows' => $rows], flags: JSON_UNESCAPED_SLASHES);
		$this->assertSame(self::PINNED[$language], md5(string: $actual), $actual);
	}//end testDescriptorsAndRowsMatchThePin()

	/**
	 * Rows without a descriptor, and the lookup of one property by path.
	 *
	 * @return void
	 */
	public function testFallbackRowsAndLookupMatchThePin(): void {
		$resolver = new RelationTypeResolver();
		$actual   = [
			$resolver->row(descriptor: null, direction: RelationTypeResolver::DIRECTION_OUTGOING),
			$resolver->row(descriptor: null, direction: RelationTypeResolver::DIRECTION_INCOMING, path: 'deelzaken.2.zaak'),
			$resolver->row(descriptor: ['label' => 'x'], direction: RelationTypeResolver::DIRECTION_INCOMING, path: 'a'),
			$resolver->row(descriptor: [], direction: RelationTypeResolver::DIRECTION_OUTGOING, path: ''),
			$resolver->descriptorFor(schema: self::schema(), property: 'parentRef.3.x', language: 'en'),
			$resolver->descriptorFor(schema: self::schema(), property: 'notref'),
			$resolver->descriptorFor(schema: null, property: 'plain'),
			$resolver->descriptors(schema: new Schema()),
			RelationTypeResolver::propertyNameOf(path: ''),
		];

		$fallback = '"type":null,"label":%s,"inverseLabel":"referenced by","symmetric":false,"inherits":[],"direction":"%s",';
		$expected = '[{"property":null,'.sprintf($fallback, 'null', 'outgoing').'"displayLabel":null,"path":null},'
			.'{"property":"deelzaken",'.sprintf($fallback, '"deelzaken"', 'incoming')
			.'"displayLabel":"referenced by","path":"deelzaken.2.zaak"},'
			.'{"label":"x","direction":"incoming","displayLabel":"referenced by","path":"a"},'
			.'{"direction":"outgoing","displayLabel":null,"path":""},'
			.'{"property":"parentRef","type":"parent","label":"parent","inverseLabel":"kind","symmetric":false,'
			.'"inherits":{"classification":"classification"}},'
			.'null,null,[],""]';

		$this->assertSame($expected, json_encode(value: $actual, flags: JSON_UNESCAPED_SLASHES));
	}//end testFallbackRowsAndLookupMatchThePin()

	/**
	 * The md5 of the pinned descriptors-and-rows rendering, per language.
	 *
	 * @var array<string, string>
	 */
	private const PINNED = [
		'nl'    => '6aada12600de0dc8c3bee31a7c4e9ab4',
		'en'    => '2fd49cd2c40d451ab6861628cf3574e1',
		'en-GB' => '2fd49cd2c40d451ab6861628cf3574e1',
		'de-AT' => '6aada12600de0dc8c3bee31a7c4e9ab4',
	];
}//end class
