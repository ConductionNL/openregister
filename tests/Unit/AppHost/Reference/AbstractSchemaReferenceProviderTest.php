<?php

/**
 * Unit tests for AbstractSchemaReferenceProvider.
 *
 * Exercised via a minimal concrete test-double subclass (the only
 * configuration surface the abstract class allows: getRegisterSlug()/
 * getSchemaSlug()), matching how a real consuming app (e.g. Pipelinq's
 * LeadReferenceProvider, out of scope here) would subclass it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Reference
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

namespace OCA\OpenRegister\Tests\Unit\AppHost\Reference;

use OCA\OpenRegister\AppHost\Reference\AbstractSchemaReferenceProvider;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal concrete subclass — the only configuration surface a consuming
 * app has: two slug methods, nothing else.
 */
final class TestLeadReferenceProvider extends AbstractSchemaReferenceProvider {
	public function getRegisterSlug(): string {
		return 'pipelinq';
	}//end getRegisterSlug()

	public function getSchemaSlug(): string {
		return 'lead';
	}//end getSchemaSlug()
}//end class

/**
 * Tests for AbstractSchemaReferenceProvider.
 *
 * @covers \OCA\OpenRegister\AppHost\Reference\AbstractSchemaReferenceProvider
 */
class AbstractSchemaReferenceProviderTest extends TestCase {

	/**
	 * Mock URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urlGenerator;

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
	 * The formatter used by the provider under test.
	 *
	 * @var ObjectPreviewFormatter
	 */
	private ObjectPreviewFormatter $formatter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(fn (string $url) => 'https://cloud.example.com' . ($url === '/' ? '/' : $url));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);

		$objectService = $this->createMock(ObjectService::class);
		$deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$this->formatter = new ObjectPreviewFormatter(
			$this->urlGenerator,
			$l10n,
			$objectService,
			$deepLinkRegistry,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Build the provider under test with a fresh logger mock.
	 *
	 * @param string|null $userId The current user id.
	 *
	 * @return TestLeadReferenceProvider
	 */
	private function buildProvider(?string $userId = 'test-user'): TestLeadReferenceProvider {
		return new TestLeadReferenceProvider(
			$this->formatter,
			$this->registerMapper,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class),
			$userId
		);
	}//end buildProvider()

	// --- Computed id / search-provider id -------------------------------------

	/**
	 * Test getId() derives the id from the slugs.
	 *
	 * @return void
	 */
	public function testGetIdIsDerivedFromSlugs(): void {
		$this->assertSame('openregister-ref-pipelinq-lead', $this->buildProvider()->getId());
	}//end testGetIdIsDerivedFromSlugs()

	/**
	 * Test getSupportedSearchProviderIds() derives the paired search-provider id.
	 *
	 * @return void
	 */
	public function testGetSupportedSearchProviderIdsIsDerivedFromSlugs(): void {
		$this->assertSame(['openregister_objects_pipelinq_lead'], $this->buildProvider()->getSupportedSearchProviderIds());
	}//end testGetSupportedSearchProviderIdsIsDerivedFromSlugs()

	// --- Title / icon sourced live from SchemaMapper ---------------------------

	/**
	 * Test getTitle() reads the schema's current title live.
	 *
	 * @return void
	 */
	public function testGetTitleReadsSchemaTitleLive(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));

		$schema = new Schema();
		$schema->setTitle('Sales Lead');
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->assertSame('Sales Lead', $this->buildProvider()->getTitle());
	}//end testGetTitleReadsSchemaTitleLive()

	/**
	 * Test getTitle() falls back to the raw slug when the schema cannot be resolved.
	 *
	 * @return void
	 */
	public function testGetTitleFallsBackToSlugWhenUnresolvable(): void {
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('not found'));
		$this->assertSame('lead', $this->buildProvider()->getTitle());
	}//end testGetTitleFallsBackToSlugWhenUnresolvable()

	/**
	 * Test getIconUrl() resolves through the MDI route for a configured icon.
	 *
	 * @return void
	 */
	public function testGetIconUrlUsesMdiRouteWhenSchemaHasIcon(): void {
		$schema = new Schema();
		$schema->setIcon('Dog');
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->urlGenerator->method('linkToRoute')
			->with('openregister.icon.mdi', ['name' => 'Dog'])
			->willReturn('/apps/openregister/api/icon/mdi/Dog');

		$this->assertSame('/apps/openregister/api/icon/mdi/Dog', $this->buildProvider()->getIconUrl());
	}//end testGetIconUrlUsesMdiRouteWhenSchemaHasIcon()

	/**
	 * Test getIconUrl() falls back to the app icon without a configured icon.
	 *
	 * @return void
	 */
	public function testGetIconUrlFallsBackToAppIcon(): void {
		$schema = new Schema();
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->urlGenerator->method('imagePath')->willReturn('/apps/openregister/img/app-dark.svg');

		$this->assertSame('/apps/openregister/img/app-dark.svg', $this->buildProvider()->getIconUrl());
	}//end testGetIconUrlFallsBackToAppIcon()

	// --- Schema-match guard ------------------------------------------------------

	/**
	 * Test matchReference() returns true for a URL matching this provider's
	 * own (register, schema) pair, with the flag enabled.
	 *
	 * @return void
	 */
	public function testMatchReferenceTrueForOwnSchema(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, true));

		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$this->assertTrue($this->buildProvider()->matchReference($url));
	}//end testMatchReferenceTrueForOwnSchema()

	/**
	 * Test matchReference() returns false for a syntactically valid
	 * OpenRegister object URL belonging to a DIFFERENT schema.
	 *
	 * @return void
	 */
	public function testMatchReferenceFalseForDifferentSchema(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, true));

		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/20/objects/550e8400-e29b-41d4-a716-446655440000';
		$provider = $this->buildProvider();

		$this->assertFalse($provider->matchReference($url));
		$this->assertNull($provider->resolveReference($url));
	}//end testMatchReferenceFalseForDifferentSchema()

	// --- smartPickerEnabled gate -------------------------------------------------

	/**
	 * Test matchReference()/resolveReference() are functionally inert when
	 * smartPickerEnabled is false, even for the provider's own schema.
	 *
	 * @return void
	 */
	public function testDisabledFlagMakesProviderInert(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, false));

		$url = 'https://cloud.example.com/apps/openregister/#/registers/5/schemas/12/objects/550e8400-e29b-41d4-a716-446655440000';
		$provider = $this->buildProvider();

		$this->assertFalse($provider->matchReference($url));
		$this->assertNull($provider->resolveReference($url));
		$this->assertSame($url, $provider->getCachePrefix($url));
	}//end testDisabledFlagMakesProviderInert()

	/**
	 * Test the class remains instantiable/registered (jsonSerialize still
	 * works) while the flag is off — it is "listed but inert", not absent.
	 *
	 * @return void
	 */
	public function testProviderRemainsDiscoverableWhileDisabled(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, false));

		$provider = $this->buildProvider();
		$json = $provider->jsonSerialize();

		$this->assertSame('openregister-ref-pipelinq-lead', $json['id']);
		$this->assertArrayHasKey('search_providers_ids', $json);
	}//end testProviderRemainsDiscoverableWhileDisabled()

	// --- getCacheKey --------------------------------------------------------------

	/**
	 * Test getCacheKey() returns the user id.
	 *
	 * @return void
	 */
	public function testGetCacheKeyReturnsUserId(): void {
		$this->assertSame('test-user', $this->buildProvider('test-user')->getCacheKey('any'));
	}//end testGetCacheKeyReturnsUserId()

	/**
	 * Test getCacheKey() returns an empty string for an anonymous user.
	 *
	 * @return void
	 */
	public function testGetCacheKeyReturnsEmptyForAnonymous(): void {
		$this->assertSame('', $this->buildProvider(null)->getCacheKey('any'));
	}//end testGetCacheKeyReturnsEmptyForAnonymous()

	// --- Helpers --------------------------------------------------------------------

	/**
	 * Build a Register entity with a fixed id.
	 *
	 * @param int $id The register id.
	 *
	 * @return Register
	 */
	private function makeRegister(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end makeRegister()

	/**
	 * Build a Schema entity with a fixed id and smartPickerEnabled flag.
	 *
	 * @param int $id The schema id.
	 * @param bool $smartPickerEnabled The smartPickerEnabled flag.
	 *
	 * @return Schema
	 */
	private function makeSchema(int $id, bool $smartPickerEnabled): Schema {
		$schema = new Schema();
		$schema->setSmartPickerEnabled($smartPickerEnabled);
		$schema->setId($id);
		return $schema;
	}//end makeSchema()
}//end class
