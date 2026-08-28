<?php

/**
 * Unit tests for ObjectReferenceProvider.
 *
 * Tests URL matching, reference resolution, caching, and error handling
 * for the OpenRegister Smart Picker reference provider. The provider now
 * delegates URL parsing and preview formatting to ObjectPreviewFormatter
 * (see ObjectPreviewFormatterTest for that logic's own unit coverage); this
 * suite constructs a real formatter from mocked lower-level dependencies so
 * the provider's own, unchanged public behavior is verified end-to-end.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Reference;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Reference\ObjectReferenceProvider;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectReferenceProvider.
 *
 * @covers \OCA\OpenRegister\Reference\ObjectReferenceProvider
 */
class ObjectReferenceProviderTest extends TestCase {

	/**
	 * The provider under test.
	 *
	 * @var ObjectReferenceProvider
	 */
	private ObjectReferenceProvider $provider;

	/**
	 * The shared formatter the provider delegates to.
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

		$this->provider = new ObjectReferenceProvider(
			$this->urlGenerator,
			$this->l10n,
			$this->formatter,
			'test-user'
		);
	}//end setUp()

	/**
	 * Test getId returns correct identifier.
	 *
	 * @return void
	 */
	public function testGetIdReturnsCorrectIdentifier(): void {
		$this->assertSame('openregister-ref-objects', $this->provider->getId());
	}//end testGetIdReturnsCorrectIdentifier()

	/**
	 * Test getTitle returns translated string.
	 *
	 * @return void
	 */
	public function testGetTitleReturnsTranslatedString(): void {
		$this->assertSame('Register Objects', $this->provider->getTitle());
	}//end testGetTitleReturnsTranslatedString()

	/**
	 * Test getOrder returns 10.
	 *
	 * @return void
	 */
	public function testGetOrderReturns10(): void {
		$this->assertSame(10, $this->provider->getOrder());
	}//end testGetOrderReturns10()

	/**
	 * Test getSupportedSearchProviderIds returns the objects provider ID.
	 *
	 * @return void
	 */
	public function testGetSupportedSearchProviderIds(): void {
		$this->assertSame(['openregister_objects'], $this->provider->getSupportedSearchProviderIds());
	}//end testGetSupportedSearchProviderIds()

	/**
	 * Test matchReference with hash-routed UI URL.
	 *
	 * @return void
	 */
	public function testMatchReferenceHashRoutedUrl(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$this->assertTrue($this->provider->matchReference($url));
	}//end testMatchReferenceHashRoutedUrl()

	/**
	 * Test matchReference with hash-routed UI URL with index.php prefix.
	 *
	 * @return void
	 */
	public function testMatchReferenceHashRoutedUrlWithIndexPhp(): void {
		$url = 'https://cloud.example.com/index.php/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$this->assertTrue($this->provider->matchReference($url));
	}//end testMatchReferenceHashRoutedUrlWithIndexPhp()

	/**
	 * Test matchReference with API object URL.
	 *
	 * @return void
	 */
	public function testMatchReferenceApiUrl(): void {
		$url = 'https://cloud.example.com/apps/openregister/api/objects/5/12/550e8400-e29b-41d4-a716-446655440000';
		$this->assertTrue($this->provider->matchReference($url));
	}//end testMatchReferenceApiUrl()

	/**
	 * Test matchReference with API URL with index.php prefix.
	 *
	 * @return void
	 */
	public function testMatchReferenceApiUrlWithIndexPhp(): void {
		$url = 'https://cloud.example.com/index.php/apps/openregister/api/objects/5/12/550e8400-e29b-41d4-a716-446655440000';
		$this->assertTrue($this->provider->matchReference($url));
	}//end testMatchReferenceApiUrlWithIndexPhp()

	/**
	 * Test matchReference with direct object show route.
	 *
	 * @return void
	 */
	public function testMatchReferenceDirectUrl(): void {
		$url = 'https://cloud.example.com/apps/openregister/objects/5/12/550e8400-e29b-41d4-a716-446655440000';
		$this->assertTrue($this->provider->matchReference($url));
	}//end testMatchReferenceDirectUrl()

	/**
	 * Test matchReference with direct URL with index.php prefix.
	 *
	 * @return void
	 */
	public function testMatchReferenceDirectUrlWithIndexPhp(): void {
		$url = 'https://cloud.example.com/index.php/apps/openregister/objects/5/12/550e8400-e29b-41d4-a716-446655440000';
		$this->assertTrue($this->provider->matchReference($url));
	}//end testMatchReferenceDirectUrlWithIndexPhp()

	/**
	 * Test matchReference returns false for non-matching URLs.
	 *
	 * @return void
	 */
	public function testMatchReferenceNonMatchingUrl(): void {
		$this->assertFalse($this->provider->matchReference('https://cloud.example.com/apps/files/'));
		$this->assertFalse($this->provider->matchReference('https://cloud.example.com/apps/openregister/'));
		$this->assertFalse($this->provider->matchReference('https://other-server.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000'));
		$this->assertFalse($this->provider->matchReference('not a url'));
	}//end testMatchReferenceNonMatchingUrl()

	/**
	 * Test resolveReference with a valid object.
	 *
	 * @return void
	 */
	public function testResolveReferenceSuccess(): void {
		$uuid = '550e8400-e29b-41d4-a716-446655440000';
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/' . $uuid;

		// Create a mock ObjectEntity.
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn([
			'@self' => ['name' => 'Test Object', 'updated' => '2026-03-25T10:00:00Z'],
			'status' => 'Active',
			'category' => 'Test',
			'priority' => 1,
		]);

		$this->objectService->method('find')
			->willReturn($object);

		// Use real Schema/Register instances (magic __call getters can't be mocked).
		$schema = new Schema();
		$schema->setTitle('Producten');
		$this->schemaMapper->method('find')->willReturn($schema);

		// Mock register.
		$register = new Register();
		$register->setTitle('Gemeente');
		$this->registerMapper->method('find')->willReturn($register);

		// Mock deep link (no deep link registered).
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);

		// Mock linkToRoute.
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/openregister/objects/5/12/' . $uuid);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app-dark.svg');

		$reference = $this->provider->resolveReference($url);

		$this->assertNotNull($reference);
		$this->assertInstanceOf(Reference::class, $reference);
	}//end testResolveReferenceSuccess()

	/**
	 * Test resolveReference returns null when object not found.
	 *
	 * @return void
	 */
	public function testResolveReferenceObjectNotFound(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';

		$this->objectService->method('find')->willReturn(null);

		$reference = $this->provider->resolveReference($url);
		$this->assertNull($reference);
	}//end testResolveReferenceObjectNotFound()

	/**
	 * Test resolveReference returns null on authorization exception.
	 *
	 * @return void
	 */
	public function testResolveReferenceAuthorizationException(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';

		$this->objectService->method('find')
			->willThrowException(new \RuntimeException('Access denied'));

		$reference = $this->provider->resolveReference($url);
		$this->assertNull($reference);
	}//end testResolveReferenceAuthorizationException()

	/**
	 * Test resolveReference returns null for non-matching URL.
	 *
	 * @return void
	 */
	public function testResolveReferenceNonMatchingUrl(): void {
		$reference = $this->provider->resolveReference('https://cloud.example.com/apps/files/');
		$this->assertNull($reference);
	}//end testResolveReferenceNonMatchingUrl()

	/**
	 * Test getCachePrefix returns correct format.
	 *
	 * @return void
	 */
	public function testGetCachePrefixReturnsCorrectFormat(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$prefix = $this->provider->getCachePrefix($url);
		$this->assertSame('5/12/550e8400-e29b-41d4-a716-446655440000', $prefix);
	}//end testGetCachePrefixReturnsCorrectFormat()

	/**
	 * Test getCachePrefix returns URL for non-matching reference.
	 *
	 * @return void
	 */
	public function testGetCachePrefixFallsBackToUrl(): void {
		$url = 'https://cloud.example.com/apps/files/';
		$prefix = $this->provider->getCachePrefix($url);
		$this->assertSame($url, $prefix);
	}//end testGetCachePrefixFallsBackToUrl()

	/**
	 * Test getCacheKey returns user ID.
	 *
	 * @return void
	 */
	public function testGetCacheKeyReturnsUserId(): void {
		$key = $this->provider->getCacheKey('any-url');
		$this->assertSame('test-user', $key);
	}//end testGetCacheKeyReturnsUserId()

	/**
	 * Test getCacheKey returns empty string for anonymous user.
	 *
	 * @return void
	 */
	public function testGetCacheKeyReturnsEmptyForAnonymous(): void {
		$anonProvider = new ObjectReferenceProvider(
			$this->urlGenerator,
			$this->l10n,
			$this->formatter,
			null
		);

		$key = $anonProvider->getCacheKey('any-url');
		$this->assertSame('', $key);
	}//end testGetCacheKeyReturnsEmptyForAnonymous()

	/**
	 * Test getIconUrl uses URL generator.
	 *
	 * @return void
	 */
	public function testGetIconUrlUsesUrlGenerator(): void {
		$this->urlGenerator->method('imagePath')
			->with('openregister', 'app-dark.svg')
			->willReturn('/apps/openregister/img/app-dark.svg');

		$this->assertSame('/apps/openregister/img/app-dark.svg', $this->provider->getIconUrl());
	}//end testGetIconUrlUsesUrlGenerator()

	/**
	 * Test resolveReferencePublic delegates to resolveReference.
	 *
	 * @return void
	 */
	public function testResolveReferencePublicDelegatesToResolveReference(): void {
		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';

		$this->objectService->method('find')->willReturn(null);

		$reference = $this->provider->resolveReferencePublic($url, 'some-token');
		$this->assertNull($reference);
	}//end testResolveReferencePublicDelegatesToResolveReference()

	/**
	 * Test getCacheKeyPublic returns the sharing token.
	 *
	 * @return void
	 */
	public function testGetCacheKeyPublicReturnsSharingToken(): void {
		$key = $this->provider->getCacheKeyPublic('any-url', 'token-123');
		$this->assertSame('token-123', $key);
	}//end testGetCacheKeyPublicReturnsSharingToken()
}//end class
