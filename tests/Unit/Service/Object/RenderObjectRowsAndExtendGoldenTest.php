<?php

/**
 * What the list-row helpers and the extend list helpers of RenderObject answer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins what the list-row helpers and the extend list helpers of RenderObject answer.
 *
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class RenderObjectRowsAndExtendGoldenTest extends TestCase {

	/**
	 * A handler with only the given properties set.
	 *
	 * @param array<string, mixed> $values Property values by name.
	 *
	 * @return RenderObject
	 */
	private function handlerWith(array $values): RenderObject {
		$handler = (new ReflectionClass(RenderObject::class))->newInstanceWithoutConstructor();
		\Closure::bind(function (array $values): void {
			foreach ($values as $name => $value) {
				$this->$name = $value;
			}
		}, $handler, RenderObject::class)($values);

		return $handler;
	}

	/**
	 * Call a private method of a handler.
	 *
	 * @param RenderObject $handler The handler.
	 * @param string       $method  The method name.
	 * @param array        $args    The arguments.
	 *
	 * @return mixed
	 */
	private function call(RenderObject $handler, string $method, array $args): mixed {
		$reflected = new ReflectionMethod(RenderObject::class, $method);
		$reflected->setAccessible(true);

		return $reflected->invokeArgs($handler, $args);
	}

	/**
	 * The extend list forms and what normalizeExtend() makes of them.
	 *
	 * @return array<string, array{0: mixed, 1: array, 2: mixed}>
	 */
	public static function extendForms(): array {
		$data = ['id' => 'own', 'name' => 'x', 'origin' => 'o', 'link' => 'l'];

		return [
			'null'              => [null, $data, null],
			'empty list'        => [[], $data, []],
			'string'            => ['a,b', $data, ['a', 'b']],
			'string shorthands' => ['_schema,_files', $data, ['@self.schema', '@self.files']],
			'list shorthands'   => [['x', '_register'], $data, ['x', '@self.register']],
			'shorthand keeps its key' => [[5 => '_schema', 7 => 'y'], $data, [5 => '@self.schema', 7 => 'y']],
			'first shorthand only'    => [['_schema', '_schema'], $data, ['@self.schema', '_schema']],
			'string all stays literal' => ['all', $data, ['all']],
		];
	}

	/**
	 * Every extend form normalises as pinned.
	 *
	 * @dataProvider extendForms
	 *
	 * @param mixed $extend   The extend parameter.
	 * @param array $data     The object data.
	 * @param mixed $expected The normalised list.
	 *
	 * @return void
	 */
	public function testNormalizeExtend(mixed $extend, array $data, mixed $expected): void {
		$this->assertSame($expected, $this->call($this->handlerWith([]), 'normalizeExtend', [$extend, $data]));
	}

	/**
	 * `all` in a list keeps the list's other entries and adds the object's properties.
	 *
	 * @return void
	 */
	public function testNormalizeExtendExpandsAllInAList(): void {
		$result = $this->call($this->handlerWith([]), 'normalizeExtend', [['all', '_schema'], ['name' => 'x', 'link' => 'l']]);

		$this->assertContains('@self.schema', $result);
		$this->assertContains('name', $result);
		$this->assertContains('link', $result);
		$this->assertNotContains('_schema', $result);
	}

	/**
	 * The row shapes and the uuid extractRowUuid() reads from them.
	 *
	 * @return array<string, array{0: mixed, 1: ?string}>
	 */
	public static function rowShapes(): array {
		$entity = new ObjectEntity();
		$entity->setUuid('entity-uuid');

		return [
			'entity'           => [$entity, 'entity-uuid'],
			'scalar'           => ['row', null],
			'null'             => [null, null],
			'self id'          => [['@self' => ['id' => 'a', 'uuid' => 'b'], 'id' => 'c'], 'a'],
			'self uuid'        => [['@self' => ['uuid' => 'b'], 'id' => 'c'], 'b'],
			'plain id'         => [['id' => 'c'], 'c'],
			'numeric self id'  => [['@self' => ['id' => 5, 'uuid' => 'b']], 'b'],
			'numeric ids only' => [['@self' => ['id' => 5, 'uuid' => 6], 'id' => 7], null],
			'empty'            => [[], null],
		];
	}

	/**
	 * Every row shape answers its pinned uuid.
	 *
	 * @dataProvider rowShapes
	 *
	 * @param mixed       $row      The row.
	 * @param string|null $expected The uuid.
	 *
	 * @return void
	 */
	public function testExtractRowUuid(mixed $row, ?string $expected): void {
		$this->assertSame($expected, $this->call($this->handlerWith([]), 'extractRowUuid', [$row]));
	}

	/**
	 * An entity in a register.
	 *
	 * @param mixed $register The register value.
	 *
	 * @return ObjectEntity
	 */
	private static function entityIn(mixed $register): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister($register);

		return $entity;
	}

	/**
	 * Pages and the register they share.
	 *
	 * @return array<string, array{0: array, 1: ?int}>
	 */
	public static function pages(): array {
		return [
			'empty'             => [[], null],
			'no entities'       => [['x', 5], null],
			'one register'      => [[self::entityIn('3'), self::entityIn('3')], 3],
			'non entity skipped' => [[self::entityIn('3'), 'x', self::entityIn('3')], 3],
			'two registers'     => [[self::entityIn('3'), self::entityIn('4')], null],
			'register missing'  => [[self::entityIn('3'), self::entityIn(null)], null],
			'missing first'     => [[self::entityIn(null), self::entityIn('3')], null],
			'non numeric'       => [[self::entityIn('slug')], null],
			'zero and missing'  => [[self::entityIn('0'), self::entityIn(null)], null],
			'zero'              => [[self::entityIn('0')], 0],
		];
	}

	/**
	 * A page shares a register only when every entity has that same register.
	 *
	 * @dataProvider pages
	 *
	 * @param array    $entities The page.
	 * @param int|null $expected The shared register id.
	 *
	 * @return void
	 */
	public function testSharedRegisterIdOf(array $entities, ?int $expected): void {
		$this->assertSame($expected, $this->call($this->handlerWith([]), 'sharedRegisterIdOf', [$entities]));
	}

	/**
	 * A handler whose translation step marks a row with the schema and register it was given.
	 *
	 * @param bool $all Whether the reader asked for all translations.
	 *
	 * @return RenderObject
	 */
	private function translatingHandler(bool $all = false): RenderObject {
		$language = $this->createMock(LanguageService::class);
		$language->method('shouldReturnAllTranslations')->willReturn($all);
		$translation = $this->createMock(TranslationHandler::class);
		$translation->method('resolveTranslationsForRender')->willReturnCallback(
			static fn (array $objectData, Schema $schema, ?Register $register): array => $objectData + [
				'translatedWith' => [$schema->getSlug(), $register?->getSlug()],
			]
		);
		$schema = new Schema();
		$schema->setSlug('s');
		$register = new Register();
		$register->setSlug('r');

		return $this->handlerWith(
			[
				'enrichers'          => new \OCA\OpenRegister\Service\Object\RenderEnrichers(
					translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
						translationHandler: $translation,
						translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
						translationMapper: $this->createMock(\OCA\OpenRegister\Db\TranslationMapperInterface::class),
						languageService: $language
					),
					derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
						computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
						calculationEvaluator: $this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface::class),
						linkedEntityEnricher: $this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
						urnService: $this->createMock(\OCA\OpenRegister\Service\UrnService::class)
					)
				),
				'schemasCache'       => [7 => $schema, 'seven' => $schema],
				'registersCache'     => [8 => $register, 'eight' => $register],
			]
		);
	}

	/**
	 * Array rows resolve with the schema and register their @self names; other values pass unchanged.
	 *
	 * @return void
	 */
	public function testResolveTranslationsForArrayRows(): void {
		$rows = [
			'int ids'     => ['@self' => ['schema' => 7, 'register' => 8]],
			'string ids'  => ['@self' => ['schema' => 'seven', 'register' => 'eight']],
			'no register' => ['@self' => ['schema' => 7]],
			'odd register' => ['@self' => ['schema' => 7, 'register' => 1.5]],
			'no schema'   => ['@self' => ['register' => 8]],
			'odd schema'  => ['@self' => ['schema' => ['x']]],
			'scalar'      => 'row',
		];
		$this->translatingHandler()->resolveTranslationsForRows($rows);

		$this->assertSame(['s', 'r'], $rows['int ids']['translatedWith']);
		$this->assertSame(['s', 'r'], $rows['string ids']['translatedWith']);
		$this->assertSame(['s', null], $rows['no register']['translatedWith']);
		$this->assertSame(['s', null], $rows['odd register']['translatedWith']);
		$this->assertArrayNotHasKey('translatedWith', $rows['no schema']);
		$this->assertArrayNotHasKey('translatedWith', $rows['odd schema']);
		$this->assertSame('row', $rows['scalar']);
	}

	/**
	 * Entity rows are resolved in place; an entity without a schema stays as it is.
	 *
	 * @return void
	 */
	public function testResolveTranslationsForEntityRows(): void {
		$known = new ObjectEntity();
		$known->setSchema('7');
		$known->setRegister('8');
		$known->setObject(['a' => 1]);
		$unknown = new ObjectEntity();
		$unknown->setObject(['b' => 2]);
		$rows = [$known, $unknown];

		$this->translatingHandler()->resolveTranslationsForRows($rows);

		$this->assertSame($known, $rows[0]);
		$this->assertSame(['s', 'r'], $known->getObject()['translatedWith']);
		$this->assertArrayNotHasKey('translatedWith', $unknown->getObject());
	}

	/**
	 * A reader who asked for all translations gets the rows untouched.
	 *
	 * @return void
	 */
	public function testAllTranslationsLeavesRowsAlone(): void {
		$rows = [['@self' => ['schema' => 7]]];
		$this->translatingHandler(all: true)->resolveTranslationsForRows($rows);

		$this->assertSame([['@self' => ['schema' => 7]]], $rows);
	}
}
