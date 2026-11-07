<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\TranslationProjectionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the source language of a translatable property, as the save side
 * (TranslationProjectionService::resolveSourceLanguage) and the render side
 * (RenderObject's language meta) resolve it, on one table of bodies, schema
 * definitions and register defaults.
 *
 * @covers \OCA\OpenRegister\Service\TranslationProjectionService
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Service\Object\RecurrenceOccurrences
 * @uses \OCA\OpenRegister\Service\Object\VirtualCalculations
 * @uses \OCA\OpenRegister\Service\Object\InverseBatch
 * @uses \OCA\OpenRegister\Service\Object\InverseLookup
 * @uses \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationCache
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationRender
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseTarget
 * @uses   \OCA\OpenRegister\Service\Object\PropertySourceLanguage
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class SourceLanguageResolutionTableTest extends TestCase {

	/**
	 * Body meta for `title`, schema definition of `title`, register default, expected language.
	 *
	 * @return array<string, array{mixed, mixed, string, string}>
	 */
	public static function cases(): array {
		$schemaFr = ['type' => 'string', 'sourceLanguage' => 'fr'];
		return [
			'object override'         => [['title' => ['sourceLanguage' => 'de']], $schemaFr, 'en', 'de'],
			'empty override'          => [['title' => ['sourceLanguage' => '']], $schemaFr, 'en', 'fr'],
			'non-string override'     => [['title' => ['sourceLanguage' => 5]], $schemaFr, 'en', 'fr'],
			'override not an array'   => [['title' => 'de'], $schemaFr, 'en', 'fr'],
			'meta not an array'       => ['de', $schemaFr, 'en', 'fr'],
			'other property override' => [['body' => ['sourceLanguage' => 'de']], $schemaFr, 'en', 'fr'],
			'no meta, schema'         => [null, $schemaFr, 'en', 'fr'],
			'schema empty'            => [null, ['sourceLanguage' => ''], 'en', 'en'],
			'schema non-string'       => [null, ['sourceLanguage' => ['x']], 'en', 'en'],
			'schema not an array'     => [null, 'fr', 'en', 'en'],
			'no schema definition'    => [null, null, 'en', 'en'],
			'register default empty'  => [null, null, '', 'nl'],
			'override beats empty'    => [['title' => ['sourceLanguage' => 'de']], null, '', 'de'],
		];
	}//end cases()

	/**
	 * @dataProvider cases
	 */
	public function testBothSidesResolveTheSameLanguage(mixed $meta, mixed $definition, string $default, string $expected): void {
		$schema = new Schema();
		$schema->setProperties($definition === null ? ['other' => ['type' => 'string']] : ['title' => $definition]);
		$body = ['title' => 'x'];
		if ($meta !== null) {
			$body['_translationMeta'] = $meta;
		}

		$object = new ObjectEntity();
		$object->setObject($body);

		$service = (new ReflectionClass(TranslationProjectionService::class))->newInstanceWithoutConstructor();
		$render = (new ReflectionClass(RenderObject::class))->newInstanceWithoutConstructor();
		$renderSide = new ReflectionMethod($render, 'resolveSourceLanguageForProperty');
		$renderSide->setAccessible(true);

		$this->assertSame(
			[$expected, $expected],
			[
				$service->resolveSourceLanguage($schema, $object, 'title', $default),
				$renderSide->invoke($render, $schema, $body, 'title', $default),
			]
		);
	}//end testBothSidesResolveTheSameLanguage()
}//end class
