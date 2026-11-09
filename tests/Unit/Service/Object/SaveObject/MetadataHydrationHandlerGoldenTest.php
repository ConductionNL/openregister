<?php

/**
 * Golden tests for MetadataHydrationHandler: hydration, paths, fallbacks, filters, relations, slugs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the observable output of every public MetadataHydrationHandler method.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler
 */
class MetadataHydrationHandlerGoldenTest extends TestCase {

	private const UUID = '0b9d2f5e-6c1a-4f43-9b8e-3d2f1a0c9e77';

	/**
	 * Build a handler whose cache resolves UUID to "Grace".
	 *
	 * @return MetadataHydrationHandler
	 */
	private function handler(): MetadataHydrationHandler {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->method('getMultipleObjectNames')->willReturnCallback(
			static fn (array $ids): array => array_intersect_key([self::UUID => 'Grace'], array_flip($ids))
		);
		return new MetadataHydrationHandler($this->createMock(LoggerInterface::class), $cache);
	}//end handler()

	/**
	 * Hydrate an entity holding the data with a schema of the configuration.
	 *
	 * @param array $data The object data.
	 * @param array|null $config The schema configuration.
	 *
	 * @return array{0: string|null, 1: string|null, 2: string|null}
	 */
	private function hydrate(array $data, ?array $config): array {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$schema = new Schema();
		$schema->setConfiguration($config);
		$schema->setProperties(['owner' => ['$ref' => '#/components/schemas/person']]);
		$this->handler()->hydrateObjectMetadata($entity, $schema);
		return [$entity->getName(), $entity->getDescription(), $entity->getSummary()];
	}//end hydrate()

	/**
	 * Hydration cases.
	 *
	 * @return array<string, array{0: array, 1: array|null, 2: array}>
	 */
	public static function hydrations(): array {
		return [
			'nothing' => [[], null, [null, null, null]],
			'common fields' => [
				['titel' => ' T ', 'omschrijving' => 'D', 'samenvatting' => 'S'],
				[],
				['T', 'D', 'S'],
			],
			'common fields in preference order' => [
				['title' => 'B', 'naam' => 'A', 'description' => 'D2', 'beschrijvingLang' => 'D1', 'summary' => 'S2', 'beschrijvingKort' => 'S1'],
				null,
				['A', 'D1', 'S1'],
			],
			'configured fields' => [
				['a' => 'N', 'b' => 'D', 'c' => 'S', 'name' => 'ignored'],
				['objectNameField' => 'a', 'objectDescriptionField' => 'b', 'objectSummaryField' => 'c'],
				['N', 'D', 'S'],
			],
			'configured empty falls back' => [
				['a' => '  ', 'name' => 'Fallback', 'description' => 'Desc'],
				['objectNameField' => 'a', 'objectDescriptionField' => 'missing', 'objectSummaryField' => 'missing'],
				['Fallback', 'Desc', null],
			],
			'configured relation template' => [
				['owner' => self::UUID],
				['objectNameField' => 'By {{ owner }}'],
				['By Grace', null, null],
			],
			'structured object key' => [
				['object' => ['name' => 'Inner'], 'name' => 'Outer'],
				null,
				['Inner', null, null],
			],
			'string object key is a plain property' => [
				['object' => 'https://x', 'name' => 'Outer'],
				null,
				['Outer', null, null],
			],
			'language map name' => [
				['name' => ['nl' => '', 'en' => 'English']],
				null,
				['English', null, null],
			],
			'whitespace only stays unset' => [
				['name' => '   ', 'description' => "\t"],
				null,
				[null, null, null],
			],
		];
	}//end hydrations()

	/**
	 * Hydrate metadata.
	 *
	 * @param array $data The object data.
	 * @param array|null $config The schema configuration.
	 * @param array $expected Name, description, summary.
	 *
	 * @return void
	 */
	#[DataProvider('hydrations')]
	public function testHydrate(array $data, ?array $config, array $expected): void {
		$this->assertSame($expected, $this->hydrate($data, $config));
	}//end testHydrate()

	/**
	 * Path lookups.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function paths(): array {
		return [
			'top' => ['name', 'Ada'],
			'nested' => ['contact.email', 'a@x'],
			'index' => ['list.1.city', 'Utrecht'],
			'missing' => ['nope', null],
			'through scalar' => ['name.first', null],
			'int cast' => ['count', '7'],
			'bool cast' => ['flag', '1'],
			'false cast' => ['off', ''],
			'float cast' => ['ratio', '1.5'],
			'null' => ['nothing', null],
			'array kept' => ['contact', ['email' => 'a@x']],
		];
	}//end paths()

	/**
	 * Read a value by path.
	 *
	 * @param string $path The path.
	 * @param mixed $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('paths')]
	public function testGetValueFromPath(string $path, mixed $expected): void {
		$data = [
			'name' => 'Ada',
			'contact' => ['email' => 'a@x'],
			'list' => [['city' => 'Delft'], ['city' => 'Utrecht']],
			'count' => 7,
			'flag' => true,
			'off' => false,
			'ratio' => 1.5,
			'nothing' => null,
		];
		$this->assertSame($expected, $this->handler()->getValueFromPath($data, $path));
	}//end testGetValueFromPath()

	/**
	 * Metadata value cases.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function metadataValues(): array {
		return [
			'plain' => ['name', 'Ada'],
			'missing' => ['nope', null],
			'pipe chain' => ['nope | | name', 'Ada'],
			'pipe chain with no hit' => ['nope | none', null],
			'template' => ['{{ name }}!', 'Ada!'],
			'open braces only is a path' => ['{{ name', null],
			'language map' => ['title', 'Titel'],
			'language map all empty is json' => ['emptyTitle', '{"nl":"","en":" "}'],
			'nested language map is json' => ['nestedTitle', '{"nl":{"x":"y"}}'],
			'structured array is json' => ['contact', '{"email":"a@x"}'],
			'list is json' => ['tags', '["a","b"]'],
			'relation uuid without schema' => ['owner', self::UUID],
		];
	}//end metadataValues()

	/**
	 * Extract a metadata value without schema properties.
	 *
	 * @param string $path The field path.
	 * @param string|null $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('metadataValues')]
	public function testExtractMetadataValue(string $path, ?string $expected): void {
		$data = [
			'name' => 'Ada',
			'title' => ['nl' => 'Titel', 'en' => 'Title'],
			'emptyTitle' => ['nl' => '', 'en' => ' '],
			'nestedTitle' => ['nl' => ['x' => 'y']],
			'contact' => ['email' => 'a@x'],
			'tags' => ['a', 'b'],
			'owner' => self::UUID,
		];
		$this->assertSame($expected, $this->handler()->extractMetadataValue($data, $path));
	}//end testExtractMetadataValue()

	public function testExtractMetadataValueWithSchemaResolvesTemplateRelations(): void {
		$props = ['owner' => ['format' => 'uuid']];
		$handler = $this->handler();
		$this->assertSame('Grace', $handler->extractMetadataValue(['owner' => self::UUID], '{{ owner }}', $props));
		$this->assertSame(self::UUID, $handler->extractMetadataValue(['owner' => self::UUID], 'owner', $props));
		$this->assertSame(self::UUID, $handler->extractMetadataValue(['owner' => self::UUID], 'x | owner', $props));
	}//end testExtractMetadataValueWithSchemaResolvesTemplateRelations()

	/**
	 * Fallback chain cases with schema properties.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string|null}>
	 */
	public static function fallbacks(): array {
		return [
			'first filled' => [['a' => '', 'b' => ' B '], 'a | b', 'B'],
			'none' => [['a' => ''], 'a | b', null],
			'empty segments' => [['b' => 'B'], '| | b |', 'B'],
			'zero counts as filled' => [['a' => 0], 'a | b', '0'],
			'relation name' => [['owner' => self::UUID], 'x | owner', 'Grace'],
			'relation unknown uuid' => [['owner' => 'other-id'], 'owner', 'other-id'],
		];
	}//end fallbacks()

	/**
	 * Process a fallback chain.
	 *
	 * @param array $data The data.
	 * @param string $chain The chain.
	 * @param string|null $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('fallbacks')]
	public function testProcessFieldWithFallbacks(array $data, string $chain, ?string $expected): void {
		$props = ['owner' => ['$ref' => 'person']];
		$this->assertSame($expected, $this->handler()->processFieldWithFallbacks($data, $chain, $props));
	}//end testProcessFieldWithFallbacks()

	/**
	 * Property shapes and whether a UUID value in them resolves to a name.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function relationShapes(): array {
		return [
			'ref' => [['$ref' => 'p'], 'Grace'],
			'empty ref' => [['$ref' => ''], self::UUID],
			'uuid format' => [['format' => 'uuid'], 'Grace'],
			'array of ref' => [['type' => 'array', 'items' => ['$ref' => 'p']], 'Grace'],
			'array of uuid' => [['type' => 'array', 'items' => ['format' => 'uuid']], 'Grace'],
			'array of strings' => [['type' => 'array', 'items' => ['type' => 'string']], self::UUID],
			'array without items' => [['type' => 'array'], self::UUID],
			'object oneOf ref' => [['type' => 'object', 'items' => ['oneOf' => [['type' => 'string'], ['$ref' => 'p']]]], 'Grace'],
			'object oneOf without ref' => [['type' => 'object', 'items' => ['oneOf' => [['type' => 'string']]]], self::UUID],
			'plain string' => [['type' => 'string'], self::UUID],
		];
	}//end relationShapes()

	/**
	 * Relation detection through a template.
	 *
	 * @param array $property The property.
	 * @param string $expected The rendered value.
	 *
	 * @return void
	 */
	#[DataProvider('relationShapes')]
	public function testRelationShapes(array $property, string $expected): void {
		$this->assertSame(
			$expected,
			$this->handler()->processTwigLikeTemplate(['owner' => self::UUID], '{{ owner }}', ['owner' => $property])
		);
	}//end testRelationShapes()

	/**
	 * UUID extraction keys.
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function uuidValues(): array {
		return [
			'value key' => [['value' => self::UUID, 'id' => 'x'], 'Grace'],
			'id key' => [['id' => self::UUID], 'Grace'],
			'uuid key' => [['uuid' => self::UUID], 'Grace'],
			'self id key' => [['@self.id' => self::UUID], 'Grace'],
			'unknown id' => [['id' => 'zzz'], 'zzz'],
			'non string id' => [['id' => 5], null],
			'no known key' => [['x' => self::UUID], null],
		];
	}//end uuidValues()

	/**
	 * Extract a UUID from a structured value through a template placeholder.
	 *
	 * @param mixed $value The value.
	 * @param string|null $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('uuidValues')]
	public function testUuidValues(mixed $value, ?string $expected): void {
		$this->assertSame(
			$expected,
			$this->handler()->processTwigLikeTemplate(['owner' => $value], '{{ owner }}', ['owner' => ['$ref' => 'p']])
		);
	}//end testUuidValues()

	public function testFailedNameLookupLogsAndFallsBackToUuid(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->once())->method('getMultipleObjectNames')->with([self::UUID])
			->willThrowException(new \RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[MetadataHydrationHandler] Failed to resolve UUID to name',
			$this->callback(
				static fn (array $c): bool => $c['field'] === 'owner' && $c['uuid'] === self::UUID
					&& $c['error'] === 'down' && is_int($c['line']) === true && is_string($c['file']) === true
			)
		);
		$handler = new MetadataHydrationHandler($logger, $cache);
		$this->assertSame(self::UUID, $handler->processFieldWithFallbacks(['owner' => self::UUID], 'owner', ['owner' => ['$ref' => 'p']]));
	}//end testFailedNameLookupLogsAndFallsBackToUuid()

	public function testNameLookupIsSkippedWithoutSchemaPropertiesOrForPlainFields(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->never())->method('getMultipleObjectNames');
		$handler = new MetadataHydrationHandler($this->createMock(LoggerInterface::class), $cache);
		$this->assertSame(self::UUID, $handler->processFieldWithFallbacks(['owner' => self::UUID], 'owner'));
		$this->assertSame('x', $handler->processFieldWithFallbacks(['owner' => 'x'], 'owner', ['other' => ['$ref' => 'p']]));
		$this->assertNull($handler->processTwigLikeTemplate(['owner' => ['id' => 'q']], '{{ owner }}', ['owner' => ['type' => 'string']]));
	}//end testNameLookupIsSkippedWithoutSchemaPropertiesOrForPlainFields()

	public function testAFilteredPlaceholderStillLooksUpThePlainPathFirst(): void {
		$cache = $this->createMock(CacheHandlerInterface::class);
		$cache->expects($this->once())->method('getMultipleObjectNames')->with(['zz'])->willReturn([]);
		$handler = new MetadataHydrationHandler($this->createMock(LoggerInterface::class), $cache);
		$props = ['owner' => ['$ref' => 'p'], 'owner | map: a=b' => ['$ref' => 'p']];
		$data = ['owner' => self::UUID, 'owner | map: a=b' => 'zz'];
		$this->assertSame(self::UUID, $handler->processTwigLikeTemplate($data, '{{ owner | map: a=b }}', $props));
	}//end testAFilteredPlaceholderStillLooksUpThePlainPathFirst()

	/**
	 * Map filter cases.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string|null}>
	 */
	public static function maps(): array {
		return [
			'hit' => [['r' => 'AnaarB'], 'AnaarB=→, BnaarA=←', '→'],
			'miss keeps value' => [['r' => ' X '], 'AnaarB=→', 'X'],
			'empty uses first mapped' => [['r' => ''], 'a=1, b=2', '1'],
			'missing uses first mapped' => [[], 'junk, a = 1 ', '1'],
			'empty and no mapping' => [[], 'junk', null],
			'value with equals' => [['r' => 'k'], 'k=a=b', 'a=b'],
			'int value' => [['r' => 3], '3=three', 'three'],
		];
	}//end maps()

	/**
	 * Apply the map filter.
	 *
	 * @param array $data The data.
	 * @param string $map The map definition.
	 * @param string|null $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('maps')]
	public function testProcessMapFilter(array $data, string $map, ?string $expected): void {
		$this->assertSame($expected, $this->handler()->processMapFilter($data, 'r', $map));
	}//end testProcessMapFilter()

	/**
	 * IfFilled filter cases.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string|null}>
	 */
	public static function ifFilled(): array {
		return [
			'filled' => [['f' => 'x'], 'extern, intern', 'extern'],
			'empty string' => [['f' => ' '], 'extern, intern', 'intern'],
			'missing' => [[], 'extern, intern', 'intern'],
			'empty array' => [['f' => []], 'extern, intern', 'intern'],
			'filled array' => [['f' => ['a']], 'extern, intern', 'extern'],
			'zero is filled' => [['f' => 0], 'y, n', 'y'],
			'false reads as empty' => [['f' => false], 'y, n', 'n'],
			'single part' => [['f' => 'x'], ' only ', 'only'],
			'extra commas stay in second' => [[], 'a, b, c', 'b, c'],
		];
	}//end ifFilled()

	/**
	 * Apply the ifFilled filter.
	 *
	 * @param array $data The data.
	 * @param string $definition The definition.
	 * @param string|null $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('ifFilled')]
	public function testProcessIfFilledFilter(array $data, string $definition, ?string $expected): void {
		$this->assertSame($expected, $this->handler()->processIfFilledFilter($data, 'f', $definition));
	}//end testProcessIfFilledFilter()

	/**
	 * Slug cases.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function slugs(): array {
		return [
			'words' => ['Hello World', 'hello-world'],
			'underscores' => ['a_b c', 'a-b-c'],
			'specials' => ['Ça va? 100%!', 'a-va-100'],
			'collapse' => ['--a -- b--', 'a-b'],
			'empty' => ['', ''],
		];
	}//end slugs()

	/**
	 * Slugify.
	 *
	 * @param string $text The text.
	 * @param string $expected The slug.
	 *
	 * @return void
	 */
	#[DataProvider('slugs')]
	public function testCreateSlug(string $text, string $expected): void {
		$this->assertSame($expected, $this->handler()->createSlug($text));
	}//end testCreateSlug()
}//end class
