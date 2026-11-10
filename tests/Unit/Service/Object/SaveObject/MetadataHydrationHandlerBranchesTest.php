<?php

/**
 * MetadataHydrationHandler branches: configured paths, templates, filters, fallbacks and relation names.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Object\SaveObject\TemplateSource;

/**
 * What each path through the metadata hydration decides.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\TemplateSource
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\MetadataField
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\FieldValue
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\MapFilterDefinition
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\RelationPropertyShape
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\UuidReference
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\LanguageMapText
 * @uses \OCA\OpenRegister\Service\Object\LanguageKeyedMap
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
class MetadataHydrationHandlerBranchesTest extends TestCase {

	private const UUID = '0b0e9f3c-1d2a-4c5b-8e6f-7a8b9c0d1e2f';

	/**
	 * The object-name cache.
	 *
	 * @var CacheHandlerInterface&MockObject
	 */
	private CacheHandlerInterface $cache;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cache = $this->createMock(CacheHandlerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return MetadataHydrationHandler
	 */
	private function handler(): MetadataHydrationHandler {
		return new MetadataHydrationHandler($this->logger, $this->cache);
	}//end handler()

	/**
	 * A schema with a configuration and properties.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 * @param array<string, mixed> $properties    The properties.
	 *
	 * @return Schema
	 */
	private function schema(array $configuration, array $properties = []): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * Configured paths win, a nested "object" envelope is unwrapped, a name template resolves a relation through the cache, and values are trimmed.
	 *
	 * @return void
	 */
	public function testConfiguredPathsAndARelationNameAreHydrated(): void {
		$this->cache->expects($this->once())->method('getMultipleObjectNames')->with([self::UUID])->willReturn([self::UUID => 'Acme B.V.']);
		$entity = new ObjectEntity();
		$entity->setObject(['object' => ['company' => self::UUID, 'about' => '  long text  ', 'short' => 'brief', 'name' => 'ignored']]);
		$schema = $this->schema(
			configuration: ['objectNameField' => '{{ company }}', 'objectDescriptionField' => 'about', 'objectSummaryField' => '{{ short }} ({{ missing }})'],
			properties: ['company' => ['type' => 'string', 'format' => 'uuid']]
		);

		$this->handler()->hydrateObjectMetadata($entity, $schema);

		$this->assertSame('Acme B.V.', $entity->getName());
		$this->assertSame('long text', $entity->getDescription());
		$this->assertSame('brief ()', $entity->getSummary());
	}//end testConfiguredPathsAndARelationNameAreHydrated()

	/**
	 * An empty configured path falls back to the common field names; nothing matching leaves the column alone.
	 *
	 * @return void
	 */
	public function testEmptyConfiguredValuesFallBackToCommonFields(): void {
		$entity = new ObjectEntity();
		$entity->setName('kept');
		$entity->setObject(['label' => '', 'titel' => 'Titel', 'omschrijving' => '   ', 'samenvatting' => 'Kort']);

		$this->handler()->hydrateObjectMetadata($entity, $this->schema(configuration: ['objectNameField' => 'label', 'objectDescriptionField' => 'nope']));

		$this->assertSame('Titel', $entity->getName());
		$this->assertNull($entity->getDescription());
		$this->assertSame('Kort', $entity->getSummary());
	}//end testEmptyConfiguredValuesFallBackToCommonFields()

	/**
	 * A relation whose name the cache lacks keeps the uuid; one whose lookup throws is logged and keeps the uuid.
	 *
	 * @return void
	 */
	public function testAnUnresolvedRelationKeepsItsUuid(): void {
		$properties = ['owner' => ['$ref' => '#/person']];
		$this->cache->method('getMultipleObjectNames')->willReturnOnConsecutiveCalls([], $this->throwException(new Exception('cache down')));
		$this->logger->expects($this->once())->method('warning')->with(
			'[MetadataHydrationHandler] Failed to resolve UUID to name',
			$this->callback(fn (array $c): bool => $c['field'] === 'owner' && $c['uuid'] === self::UUID && $c['error'] === 'cache down')
		);
		$handler = $this->handler();

		$this->assertSame(self::UUID, $handler->renderTemplate(template: '{{ owner }}', source: new TemplateSource(data: ['owner' => self::UUID], schemaProperties: $properties)));
		$this->assertSame(self::UUID, $handler->processFieldWithFallbacks(['owner' => self::UUID], 'owner', $properties));
	}//end testAnUnresolvedRelationKeepsItsUuid()

	/**
	 * A template with no placeholders, or whose placeholders are all empty, renders nothing; whitespace is collapsed.
	 *
	 * @return void
	 */
	public function testTemplatesRenderOnlyWhenSomethingIsFilled(): void {
		$handler = $this->handler();

		$this->assertNull($handler->renderTemplate(template: 'no placeholders', source: new TemplateSource(data: ['a' => 'x'], schemaProperties: [])));
		$this->assertNull($handler->renderTemplate(template: '{{ a }} {{ b }}', source: new TemplateSource(data: ['a' => ''], schemaProperties: [])));
		$this->assertSame('x - y', $handler->renderTemplate(template: "{{ a }}   -\n {{ b }}", source: new TemplateSource(data: ['a' => ' x ', 'b' => 'y'], schemaProperties: [])));
	}//end testTemplatesRenderOnlyWhenSomethingIsFilled()

	/**
	 * Inside a template: ifFilled picks by whether the field is filled, map translates a code, and a bare pipe is a fallback chain.
	 *
	 * @return void
	 */
	public function testTemplateFilters(): void {
		$handler = $this->handler();
		$data = ['vip' => 'yes', 'none' => '', 'list' => [], 'code' => 'nl', 'other' => 'de', 'b' => 'Bee'];

		$this->assertSame('Gold', $handler->renderTemplate(template: '{{ vip | ifFilled: Gold, Basic }}', source: new TemplateSource(data: $data, schemaProperties: [])));
		$this->assertSame('Basic', $handler->renderTemplate(template: '{{ none | ifFilled: Gold, Basic }}', source: new TemplateSource(data: $data, schemaProperties: [])));
		$this->assertSame('Basic', $handler->renderTemplate(template: '{{ list | ifFilled: Gold, Basic }}', source: new TemplateSource(data: $data, schemaProperties: [])));
		$this->assertSame('Nederlands', $handler->renderTemplate(template: '{{ code | map: nl=Nederlands, en=English }}', source: new TemplateSource(data: $data, schemaProperties: [])));
		$this->assertSame('de', $handler->renderTemplate(template: '{{ other | map: nl=Nederlands }}', source: new TemplateSource(data: $data, schemaProperties: [])));
		$this->assertSame('Nederlands', $handler->renderTemplate(template: '{{ none | map: nl=Nederlands, en=English }}', source: new TemplateSource(data: $data, schemaProperties: [])));
		$this->assertSame('Bee', $handler->renderTemplate(template: '{{ a | b }}', source: new TemplateSource(data: $data, schemaProperties: [])));
	}//end testTemplateFilters()

	/**
	 * The public filter entry points: an ifFilled without an else answers its only part, a map without entries answers null for an empty field.
	 *
	 * @return void
	 */
	public function testThePublicFilterEntryPoints(): void {
		$handler = $this->handler();

		$this->assertSame('Only', $handler->processIfFilledFilter(['x' => 'v'], 'x', 'Only'));
		$this->assertSame('Yes', $handler->processIfFilledFilter(['x' => 0], 'x', 'Yes, No'));
		$this->assertSame('No', $handler->processIfFilledFilter([], 'x', 'Yes, No'));
		$this->assertNull($handler->processMapFilter([], 'x', 'garbage'));
		$this->assertSame('One', $handler->processMapFilter(['x' => ' 1 '], 'x', '1=One'));
	}//end testThePublicFilterEntryPoints()

	/**
	 * A bare fallback chain skips empty and blank links and returns the first filled one, trimmed; none filled is null.
	 *
	 * @return void
	 */
	public function testAFallbackChain(): void {
		$handler = $this->handler();
		$data = ['a' => '', 'b' => '  ', 'c' => ' third ', 'n' => 0];

		$this->assertSame('third', $handler->extractMetadataValue($data, 'a | b || c'));
		$this->assertSame('0', $handler->processFieldWithFallbacks($data, 'missing | n'));
		$this->assertNull($handler->processFieldWithFallbacks($data, 'a | b'));
	}//end testAFallbackChain()

	/**
	 * Plain paths: dot paths are followed, scalars become strings, a language map yields its first filled value, any other array is JSON.
	 *
	 * @return void
	 */
	public function testPlainPathValues(): void {
		$handler = $this->handler();
		$data = [
			'deep' => ['er' => ['est' => 42]],
			'title' => ['nl' => '', 'en' => 'Hello'],
			'blank' => ['nl' => '', 'en' => ''],
			'list' => [1, 2],
			'scalar' => 'x',
		];

		$this->assertSame('42', $handler->extractMetadataValue($data, 'deep.er.est'));
		$this->assertNull($handler->extractMetadataValue($data, 'scalar.below'));
		$this->assertNull($handler->extractMetadataValue($data, 'deep.nope'));
		$this->assertSame('Hello', $handler->extractMetadataValue($data, 'title'));
		$this->assertSame('{"nl":"","en":""}', $handler->extractMetadataValue($data, 'blank'));
		$this->assertSame('[1,2]', $handler->extractMetadataValue($data, 'list'));
		$this->assertSame(['nl' => '', 'en' => 'Hello'], $handler->getValueFromPath($data, 'title'));
	}//end testPlainPathValues()
	/**
	 * A slug is lower-case, hyphenated, stripped of other characters, with hyphen runs collapsed and trimmed.
	 *
	 * @return void
	 */
	public function testCreateSlug(): void {
		$this->assertSame('hello-world-2026', $this->handler()->createSlug('  Hello_World -- 2026! '));
		$this->assertSame('', $this->handler()->createSlug('***'));
	}//end testCreateSlug()
}//end class
