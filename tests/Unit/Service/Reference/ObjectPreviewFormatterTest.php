<?php

/**
 * Unit tests for ObjectPreviewFormatter.
 *
 * Covers the shared URL-recognition/URL-building pattern list (design.md
 * D4), rich-preview building, icon resolution, and — since
 * ObjectService::saveObject()'s cache-invalidation hook is thin glue over
 * these same methods plus a real `\OC::$server` container (untestable in
 * isolation, matching the established pattern for the sibling
 * contact-matching invalidation block) — the dedup/prefix-collapsing
 * behaviour the invalidation hook itself relies on.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Reference
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

namespace OCA\OpenRegister\Tests\Unit\Service\Reference;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCP\Collaboration\Reference\IReferenceManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectPreviewFormatter.
 *
 * @covers \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 */
class ObjectPreviewFormatterTest extends TestCase {

	/**
	 * The formatter under test.
	 *
	 * @var ObjectPreviewFormatter
	 */
	private ObjectPreviewFormatter $formatter;

	/**
	 * Mock URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * Mock l10n service.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N $l10n;

	/**
	 * Mock object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock deep link registry.
	 *
	 * @var DeepLinkRegistryService&MockObject
	 */
	private DeepLinkRegistryService $deepLinkRegistry;

	/**
	 * Mock schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Mock register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(function (string $url): string {
				if ($url === '/') {
					return 'https://cloud.example.com/';
				}

				return 'https://cloud.example.com' . $url;
			});

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(function (string $text): string {
			return $text;
		});

		$this->objectService = $this->createMock(ObjectService::class);
		$this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->formatter = new ObjectPreviewFormatter(
			$this->urlGenerator,
			$this->l10n,
			$this->objectService,
			$this->deepLinkRegistry,
			$this->schemaMapper,
			$this->registerMapper,
			$this->logger
		);
	}//end setUp()

	// --- parseReference() ---------------------------------------------------

	/**
	 * Test parseReference recognises the hash-routed UI pattern.
	 *
	 * @return void
	 */
	public function testParseReferenceHashRoutedUrl(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$parsed = $this->formatter->parseReference($url);

		$this->assertNotNull($parsed);
		$this->assertSame(5, $parsed['registerId']);
		$this->assertSame(12, $parsed['schemaId']);
		$this->assertSame('550e8400-e29b-41d4-a716-446655440000', $parsed['uuid']);
	}//end testParseReferenceHashRoutedUrl()

	/**
	 * Test parseReference recognises the API endpoint pattern.
	 *
	 * @return void
	 */
	public function testParseReferenceApiUrl(): void {
		$url = 'https://cloud.example.com/apps/openregister/api/objects/10/20/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$parsed = $this->formatter->parseReference($url);

		$this->assertNotNull($parsed);
		$this->assertSame(10, $parsed['registerId']);
		$this->assertSame(20, $parsed['schemaId']);
		$this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $parsed['uuid']);
	}//end testParseReferenceApiUrl()

	/**
	 * Test parseReference recognises the direct object route pattern.
	 *
	 * @return void
	 */
	public function testParseReferenceDirectRouteUrl(): void {
		$url = 'https://cloud.example.com/apps/openregister/objects/7/8/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$parsed = $this->formatter->parseReference($url);

		$this->assertNotNull($parsed);
		$this->assertSame(7, $parsed['registerId']);
		$this->assertSame(8, $parsed['schemaId']);
		$this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $parsed['uuid']);
	}//end testParseReferenceDirectRouteUrl()

	/**
	 * Test parseReference returns null for a non-matching URL.
	 *
	 * @return void
	 */
	public function testParseReferenceNonMatchingUrl(): void {
		$this->assertNull($this->formatter->parseReference('https://cloud.example.com/apps/files/'));
		$this->assertNull($this->formatter->parseReference('not a url'));
	}//end testParseReferenceNonMatchingUrl()

	// --- buildCanonicalUrls() round-tripping through parseReference() ------

	/**
	 * Test buildCanonicalUrls produces a URL for each of the three patterns,
	 * and every one of them round-trips back through parseReference() to
	 * the exact same (registerId, schemaId, uuid) triple — the guarantee
	 * design.md D4 exists to make structural rather than conventional.
	 *
	 * @return void
	 */
	public function testBuildCanonicalUrlsRoundTripThroughParseReference(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$urls = $this->formatter->buildCanonicalUrls(5, 12, $uuid);

		$this->assertCount(3, $urls);

		foreach ($urls as $url) {
			$parsed = $this->formatter->parseReference($url);
			$this->assertNotNull($parsed, 'Every built canonical URL must be recognised by parseReference(): ' . $url);
			$this->assertSame(5, $parsed['registerId']);
			$this->assertSame(12, $parsed['schemaId']);
			$this->assertSame($uuid, $parsed['uuid']);
		}
	}//end testBuildCanonicalUrlsRoundTripThroughParseReference()

	// --- resolveCachePrefix() ------------------------------------------------

	/**
	 * Test resolveCachePrefix collapses a matching URL to the
	 * "{registerId}/{schemaId}/{uuid}" prefix.
	 *
	 * @return void
	 */
	public function testResolveCachePrefixCollapsesMatchingUrl(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$this->assertSame(
			'5/12/550e8400-e29b-41d4-a716-446655440000',
			$this->formatter->resolveCachePrefix($url)
		);
	}//end testResolveCachePrefixCollapsesMatchingUrl()

	/**
	 * Test resolveCachePrefix passes through unrecognised text unchanged.
	 *
	 * @return void
	 */
	public function testResolveCachePrefixPassesThroughUnmatchedText(): void {
		$url = 'https://cloud.example.com/apps/files/';
		$this->assertSame($url, $this->formatter->resolveCachePrefix($url));
	}//end testResolveCachePrefixPassesThroughUnmatchedText()

	/**
	 * Test every canonical URL for one object collapses to the SAME cache
	 * prefix via resolveCachePrefix() — the dedup guarantee the cache
	 * invalidation hook (ObjectService::saveObject()) relies on to issue a
	 * single invalidateCache() call per unique prefix rather than one per
	 * pattern.
	 *
	 * @return void
	 */
	public function testAllCanonicalUrlsCollapseToTheSameCachePrefix(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$urls = $this->formatter->buildCanonicalUrls(5, 12, $uuid);

		$prefixes = array_map(
			fn (string $url): string => $this->formatter->resolveCachePrefix($url),
			$urls
		);

		$this->assertSame(['5/12/' . $uuid, '5/12/' . $uuid, '5/12/' . $uuid], $prefixes);
		$this->assertCount(1, array_unique($prefixes));
	}//end testAllCanonicalUrlsCollapseToTheSameCachePrefix()

	/**
	 * Test the cache-invalidation hook's dedup + separate deep-link call
	 * pattern: iterating buildCanonicalUrls() through resolveCachePrefix()
	 * with a dedup guard issues exactly one invalidateCache() call for the
	 * canonical prefix, and the deep-link URL — a distinct string — issues
	 * a second, separate invalidateCache() call. This mirrors
	 * ObjectService::saveObject()'s hook exactly, without needing the real
	 * `\OC::$server` container the hook itself resolves services from.
	 *
	 * @return void
	 */
	public function testInvalidationDedupsCanonicalPrefixesAndFiresASeparateDeepLinkCall(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$deepLinkUrl = '/apps/pipelinq/#/leads/' . $uuid;

		$referenceManager = $this->createMock(IReferenceManager::class);
		$invalidated = [];
		$referenceManager->method('invalidateCache')
			->willReturnCallback(function (string $cachePrefix) use (&$invalidated): void {
				$invalidated[] = $cachePrefix;
			});

		$invalidatedPrefixes = [];
		foreach ($this->formatter->buildCanonicalUrls(5, 12, $uuid) as $canonicalUrl) {
			$prefix = $this->formatter->resolveCachePrefix($canonicalUrl);
			if (isset($invalidatedPrefixes[$prefix]) === false) {
				$referenceManager->invalidateCache($prefix);
				$invalidatedPrefixes[$prefix] = true;
			}
		}

		$referenceManager->invalidateCache($deepLinkUrl);

		$this->assertSame(['5/12/' . $uuid, $deepLinkUrl], $invalidated);
	}//end testInvalidationDedupsCanonicalPrefixesAndFiresASeparateDeepLinkCall()

	// --- buildReference() / rich preview shape ------------------------------

	/**
	 * Test buildReference produces a rich reference for a valid object.
	 *
	 * @return void
	 */
	public function testBuildReferenceSuccess(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/' . $uuid;

		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn([
			'@self' => ['name' => 'Test Object', 'updated' => '2026-03-25T10:00:00Z'],
			'status' => 'Active',
			'category' => 'Test',
			'priority' => 1,
		]);

		$this->objectService->method('find')->willReturn($object);

		$schema = new Schema();
		$schema->setTitle('Producten');
		$this->schemaMapper->method('find')->willReturn($schema);

		$register = new Register();
		$register->setTitle('Gemeente');
		$this->registerMapper->method('find')->willReturn($register);

		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);

		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/openregister/objects/5/12/' . $uuid);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app-dark.svg');

		$reference = $this->formatter->buildReference($url);

		$this->assertNotNull($reference);
		$richObject = $reference->jsonSerialize()['richObject'] ?? null;
		$this->assertIsArray($richObject);
	}//end testBuildReferenceSuccess()

	/**
	 * Test buildReference returns null for a non-matching URL.
	 *
	 * @return void
	 */
	public function testBuildReferenceNonMatchingUrl(): void {
		$this->assertNull($this->formatter->buildReference('https://cloud.example.com/apps/files/'));
	}//end testBuildReferenceNonMatchingUrl()

	/**
	 * Test buildReference returns null when the object is not found.
	 *
	 * @return void
	 */
	public function testBuildReferenceObjectNotFound(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$this->objectService->method('find')->willReturn(null);

		$this->assertNull($this->formatter->buildReference($url));
	}//end testBuildReferenceObjectNotFound()

	/**
	 * Test buildReference returns null (never leaks metadata) on an RBAC
	 * denial or any other exception from ObjectService::find().
	 *
	 * @return void
	 */
	public function testBuildReferenceReturnsNullOnException(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$this->objectService->method('find')->willThrowException(new \RuntimeException('Access denied'));

		$this->assertNull($this->formatter->buildReference($url));
	}//end testBuildReferenceReturnsNullOnException()

	// --- Icon resolution helpers ---------------------------------------------

	/**
	 * Test resolveSchemaIconName returns null when the schema has no icon.
	 *
	 * @return void
	 */
	public function testResolveSchemaIconNameReturnsNullWhenUnset(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->assertNull($this->formatter->resolveSchemaIconName(12));
	}//end testResolveSchemaIconNameReturnsNullWhenUnset()

	/**
	 * Test resolveSchemaIconName returns the configured icon reference.
	 *
	 * @return void
	 */
	public function testResolveSchemaIconNameReturnsConfiguredIcon(): void {
		$schema = new Schema();
		$schema->setIcon('Dog');
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->assertSame('Dog', $this->formatter->resolveSchemaIconName(12));
	}//end testResolveSchemaIconNameReturnsConfiguredIcon()

	/**
	 * Test resolveMdiIconUrl resolves through the openregister.icon.mdi
	 * route for a recognised icon, and falls back to null for an
	 * unrecognised one.
	 *
	 * @return void
	 */
	public function testResolveMdiIconUrlUsesTheMdiRouteForARecognisedIcon(): void {
		$schema = new Schema();
		$schema->setIcon('Dog');
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->urlGenerator->method('linkToRoute')
			->with('openregister.icon.mdi', ['name' => 'Dog'])
			->willReturn('/apps/openregister/api/icon/mdi/Dog');

		$this->assertSame('/apps/openregister/api/icon/mdi/Dog', $this->formatter->resolveMdiIconUrl(12));
	}//end testResolveMdiIconUrlUsesTheMdiRouteForARecognisedIcon()

	/**
	 * Test resolveMdiIconUrl returns null for an unrecognised icon name.
	 *
	 * @return void
	 */
	public function testResolveMdiIconUrlReturnsNullForUnrecognisedIcon(): void {
		$schema = new Schema();
		$schema->setIcon('totally-unknown-icon-xyz');
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->assertNull($this->formatter->resolveMdiIconUrl(12));
	}//end testResolveMdiIconUrlReturnsNullForUnrecognisedIcon()

	/**
	 * Test resolveDeepLinkOrDefaultIconUrl prefers the deep link icon.
	 *
	 * @return void
	 */
	public function testResolveDeepLinkOrDefaultIconUrlPrefersDeepLinkIcon(): void {
		$this->deepLinkRegistry->method('resolveIcon')->willReturn('icon-pipelinq');
		$this->assertSame('icon-pipelinq', $this->formatter->resolveDeepLinkOrDefaultIconUrl(5, 12));
	}//end testResolveDeepLinkOrDefaultIconUrlPrefersDeepLinkIcon()

	/**
	 * Test resolveDeepLinkOrDefaultIconUrl falls back to the app icon.
	 *
	 * @return void
	 */
	public function testResolveDeepLinkOrDefaultIconUrlFallsBackToAppIcon(): void {
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app-dark.svg');

		$this->assertSame(
			'/apps/openregister/img/app-dark.svg',
			$this->formatter->resolveDeepLinkOrDefaultIconUrl(5, 12)
		);
	}//end testResolveDeepLinkOrDefaultIconUrlFallsBackToAppIcon()

	// --- Name resolution fallbacks -------------------------------------------

	/**
	 * Test resolveSchemaName falls back to a translated placeholder when
	 * the schema cannot be resolved.
	 *
	 * @return void
	 */
	public function testResolveSchemaNameFallsBackOnException(): void {
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('not found'));
		$this->assertSame('Unknown Schema', $this->formatter->resolveSchemaName(999));
	}//end testResolveSchemaNameFallsBackOnException()

	/**
	 * Test resolveRegisterName falls back to a translated placeholder when
	 * the register cannot be resolved.
	 *
	 * @return void
	 */
	public function testResolveRegisterNameFallsBackOnException(): void {
		$this->registerMapper->method('find')->willThrowException(new \RuntimeException('not found'));
		$this->assertSame('Unknown Register', $this->formatter->resolveRegisterName(999));
	}//end testResolveRegisterNameFallsBackOnException()
}//end class
