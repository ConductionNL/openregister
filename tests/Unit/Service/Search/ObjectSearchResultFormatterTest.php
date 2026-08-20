<?php

/**
 * Unit tests for ObjectSearchResultFormatter.
 *
 * Covers icon precedence, subline composition, and excerpt slicing — the
 * per-result formatting logic extracted from ObjectsProvider::search().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Search\SearchResultEntry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectSearchResultFormatter.
 *
 * @covers \OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter
 */
class ObjectSearchResultFormatterTest extends TestCase {

	/**
	 * The formatter under test.
	 *
	 * @var ObjectSearchResultFormatter
	 */
	private ObjectSearchResultFormatter $formatter;

	/**
	 * Mock URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * Mock deep link registry.
	 *
	 * @var DeepLinkRegistryService&MockObject
	 */
	private DeepLinkRegistryService $deepLinkRegistry;

	/**
	 * Mock schema mapper (backs the shared preview formatter's icon lookup).
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $url) => 'https://cloud.example.com' . ($url === '/' ? '/' : $url));
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(function (string $route, array $params) {
			if ($route === 'openregister.icon.mdi') {
				return '/apps/openregister/api/icon/mdi/' . $params['name'];
			}

			return '/objects/' . ($params['id'] ?? '');
		});

		$this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);

		$objectService = $this->createMock(ObjectService::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$previewFormatter = new ObjectPreviewFormatter(
			$this->urlGenerator,
			$l10n,
			$objectService,
			$this->deepLinkRegistry,
			$this->schemaMapper,
			$registerMapper,
			$logger
		);

		$this->formatter = new ObjectSearchResultFormatter(
			$this->urlGenerator,
			$this->deepLinkRegistry,
			$previewFormatter
		);
	}//end setUp()

	// --- Icon precedence -----------------------------------------------------

	/**
	 * Test format() prefers the schema's own MDI icon as the thumbnail.
	 *
	 * @return void
	 */
	public function testFormatPrefersSchemaMdiIconAsThumbnail(): void {
		$schema = new Schema();
		$schema->setIcon('Dog');
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format(['title' => 'Rex', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => 2]], null);
		$serialised = $entry->jsonSerialize();

		$this->assertSame('/apps/openregister/api/icon/mdi/Dog', $serialised['thumbnailUrl']);
		$this->assertSame('icon-openregister', $serialised['icon']);
		$this->assertFalse($serialised['rounded']);
	}//end testFormatPrefersSchemaMdiIconAsThumbnail()

	/**
	 * Test format() falls back to the deep-link icon with rounded=true when
	 * the schema has no MDI icon and an owning app is claimed.
	 *
	 * @return void
	 */
	public function testFormatFallsBackToDeepLinkIconWhenNoSchemaIcon(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn('icon-pipelinq');
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn('Pipelinq');

		$entry = $this->formatter->format(['title' => 'Acme', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => 2]], null);
		$serialised = $entry->jsonSerialize();

		$this->assertSame('', $serialised['thumbnailUrl']);
		$this->assertSame('icon-pipelinq', $serialised['icon']);
		$this->assertTrue($serialised['rounded']);
	}//end testFormatFallsBackToDeepLinkIconWhenNoSchemaIcon()

	/**
	 * Test format() falls back to the generic icon, not rounded, when
	 * neither a schema icon nor a claimed app exists.
	 *
	 * @return void
	 */
	public function testFormatFallsBackToGenericIconWhenUnclaimed(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format(['title' => 'Audit', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => 5]], null);
		$serialised = $entry->jsonSerialize();

		$this->assertSame('icon-openregister', $serialised['icon']);
		$this->assertFalse($serialised['rounded']);
	}//end testFormatFallsBackToGenericIconWhenUnclaimed()

	// --- ObjectEntity normalisation -------------------------------------------

	/**
	 * Test format() accepts an ObjectEntity directly, normalising it via
	 * jsonSerialize() internally.
	 *
	 * @return void
	 */
	public function testFormatNormalisesObjectEntity(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn([
			'title' => 'Entity Object',
			'@self' => ['id' => 'uuid-456', 'register' => 1, 'schema' => 2],
		]);

		$entry = $this->formatter->format($entity, null);
		$this->assertSame('Entity Object', $entry->jsonSerialize()['title']);
	}//end testFormatNormalisesObjectEntity()

	// --- Subline composition --------------------------------------------------

	/**
	 * Test the subline is `{Owner} · {Register} · {Schema}` for a claimed pair.
	 *
	 * @return void
	 */
	public function testSublineStartsWithOwnerLabel(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn('Pipelinq');

		$entry = $this->formatter->format(['title' => 'Acme', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => 2]], null);
		$this->assertStringStartsWith('Pipelinq · ', $entry->jsonSerialize()['subline']);
	}//end testSublineStartsWithOwnerLabel()

	/**
	 * Test the subline falls back to "Open Register" for an unclaimed pair.
	 *
	 * @return void
	 */
	public function testSublineFallsBackToOpenRegisterLabel(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format(['title' => 'Audit', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => 5]], null);
		$this->assertStringStartsWith('Open Register · ', $entry->jsonSerialize()['subline']);
	}//end testSublineFallsBackToOpenRegisterLabel()

	// --- Excerpt slicing -------------------------------------------------------

	/**
	 * Test the excerpt is built around the matched term.
	 *
	 * @return void
	 */
	public function testExcerptAroundMatchedTerm(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format([
			'title' => 'Obj',
			'notes' => 'afspraak met mevrouw Jansen over de vergunning',
			'@self' => ['id' => 'e1', 'register' => 1, 'schema' => 2],
		], 'Jansen');

		$this->assertStringContainsString('Jansen', $entry->jsonSerialize()['subline']);
	}//end testExcerptAroundMatchedTerm()

	/**
	 * Test the excerpt falls back to summary when the term is not found in
	 * any string field (e.g. a numeric match).
	 *
	 * @return void
	 */
	public function testExcerptFallsBackToSummaryWhenTermNotInString(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format([
			'title' => 'Obj',
			'amount' => 42,
			'summary' => 'Kapvergunning eik Kerkstraat',
			'@self' => ['id' => 'e2', 'register' => 1, 'schema' => 2],
		], '42');

		$this->assertStringEndsWith('Kapvergunning eik Kerkstraat', $entry->jsonSerialize()['subline']);
	}//end testExcerptFallsBackToSummaryWhenTermNotInString()

	/**
	 * Test the excerpt slicer is multibyte-safe and ellipsises truncated edges.
	 *
	 * @return void
	 */
	public function testExcerptIsMultibyteSafe(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format([
			'title' => 'Obj',
			'notes' => str_repeat('é', 80) . ' bezoek aan het café in de buurt ' . str_repeat('ü', 80),
			'@self' => ['id' => 'e3', 'register' => 1, 'schema' => 2],
		], 'café');

		$subline = $entry->jsonSerialize()['subline'];
		$this->assertStringContainsString('café', $subline);
		$this->assertStringContainsString('…', $subline);
	}//end testExcerptIsMultibyteSafe()

	// --- Deep link URL / title -------------------------------------------------

	/**
	 * Test format() prefers the deep-link URL over the OpenRegister fallback.
	 *
	 * @return void
	 */
	public function testFormatPrefersDeepLinkUrl(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn('/apps/pipelinq/#/clients/u1');
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format(['title' => 'Acme', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => 2]], null);
		$this->assertSame('/apps/pipelinq/#/clients/u1', $entry->jsonSerialize()['resourceUrl']);
	}//end testFormatPrefersDeepLinkUrl()

	/**
	 * Test format() title fallback chain: title -> @self.name -> uuid.
	 *
	 * @return void
	 */
	public function testFormatTitleFallsBackToUuid(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$entry = $this->formatter->format(['@self' => ['id' => 'uuid-fallback', 'register' => 1, 'schema' => 2]], null);
		$this->assertSame('uuid-fallback', $entry->jsonSerialize()['title']);
	}//end testFormatTitleFallsBackToUuid()
}//end class
