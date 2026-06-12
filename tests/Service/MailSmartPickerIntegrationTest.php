<?php

/**
 * Integration tests for the Smart Picker reference provider.
 *
 * Replaces the four manual UI smokes (Mail / Text / Talk widget
 * verification) with automated coverage of the underlying
 * `ObjectReferenceProvider` — every code path the Smart Picker
 * exercises (URL matching across the 3 supported URL shapes,
 * resolve into a rich reference, cache prefix derivation, per-user
 * cache key) is covered against a real schema + persisted object.
 *
 * UI rendering of the resulting reference card in Mail / Text / Talk
 * is standard Nextcloud Reference Provider behaviour — once the
 * provider returns a correctly-shaped IReference, the widgets render
 * it automatically. That UI layer is out of scope for OR's tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Reference\ObjectReferenceProvider;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Collaboration\Reference\IReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class MailSmartPickerIntegrationTest extends TestCase
{
    private ObjectReferenceProvider $provider;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    private ?ObjectEntity $testObject = null;
    private ?string $createdTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider       = \OC::$server->get(ObjectReferenceProvider::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);

        // Login as admin so RegisterMapper / SchemaMapper queries see
        // the test fixtures even when multi-tenancy / RBAC is on.
        $userManager = \OC::$server->get(IUserManager::class);
        $userSession = \OC::$server->get(IUserSession::class);
        $admin       = $userManager->get('admin');
        if ($admin instanceof IUser) {
            $userSession->setUser($admin);
        }

        $this->createTestFixture();
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        if ($this->testSchema !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testSchema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->createdTable !== null) {
            try {
                $db->prepare("DROP TABLE IF EXISTS \"{$this->createdTable}\"")->execute();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        parent::tearDown();
    }

    public function testProviderMetadataMatchesContract(): void
    {
        $this->assertSame('openregister-ref-objects', $this->provider->getId());
        $this->assertNotEmpty($this->provider->getTitle());
        $this->assertSame(10, $this->provider->getOrder());
        $this->assertNotEmpty($this->provider->getIconUrl());
        $this->assertContains('openregister_objects', $this->provider->getSupportedSearchProviderIds());
    }

    public function testMatchReferenceMatchesAllThreeUrlShapes(): void
    {
        $registerId = (string) $this->testRegister->getId();
        $schemaId   = (string) $this->testSchema->getId();
        $uuid       = (string) $this->testObject->getUuid();

        // The matcher anchors to the local instance's base URL; use the
        // generator the provider itself uses so the test remains stable
        // across overwrite.cli.url settings.
        $base = rtrim(\OC::$server->get(\OCP\IURLGenerator::class)->getAbsoluteURL('/'), '/');

        // 1. Hash-routed UI URL.
        $hashUrl = "$base/apps/openregister/#/registers/{$registerId}/schemas/{$schemaId}/objects/{$uuid}";
        $this->assertTrue($this->provider->matchReference($hashUrl), 'hash-routed UI URL MUST match');

        // 2. API endpoint URL.
        $apiUrl = "$base/apps/openregister/api/objects/{$registerId}/{$schemaId}/{$uuid}";
        $this->assertTrue($this->provider->matchReference($apiUrl), 'API endpoint URL MUST match');

        // 3. Direct route URL.
        $directUrl = "$base/apps/openregister/objects/{$registerId}/{$schemaId}/{$uuid}";
        $this->assertTrue($this->provider->matchReference($directUrl), 'direct route URL MUST match');

        // 4. With /index.php/ prefix — also supported.
        $indexUrl = "$base/index.php/apps/openregister/api/objects/{$registerId}/{$schemaId}/{$uuid}";
        $this->assertTrue($this->provider->matchReference($indexUrl), 'index.php-prefixed URL MUST match');

        // 5. Negative cases — random URL MUST NOT match.
        $this->assertFalse(
            $this->provider->matchReference("$base/something/unrelated"),
            'unrelated URL MUST NOT be a Smart Picker reference'
        );
        $this->assertFalse(
            $this->provider->matchReference('not-a-url-at-all'),
            'non-URL strings MUST NOT match'
        );
    }

    public function testResolveReferenceProducesRichReference(): void
    {
        $registerId = (string) $this->testRegister->getId();
        $schemaId   = (string) $this->testSchema->getId();
        $uuid       = (string) $this->testObject->getUuid();

        $base = rtrim(\OC::$server->get(\OCP\IURLGenerator::class)->getAbsoluteURL('/'), '/');
        $url  = "$base/apps/openregister/api/objects/{$registerId}/{$schemaId}/{$uuid}";

        $reference = $this->provider->resolveReference($url);

        $this->assertInstanceOf(IReference::class, $reference);
        // Title MUST surface from the object data.
        $this->assertNotEmpty($reference->getTitle(), 'reference MUST carry a title from the object');
        // Description MUST be populated (extracted from object data).
        $this->assertNotNull($reference->getDescription());
        // URL MUST be populated for navigation.
        $this->assertNotEmpty($reference->getUrl());
    }

    public function testResolveReferenceReturnsNullForUnknownUuid(): void
    {
        $registerId = (string) $this->testRegister->getId();
        $schemaId   = (string) $this->testSchema->getId();

        $base = rtrim(\OC::$server->get(\OCP\IURLGenerator::class)->getAbsoluteURL('/'), '/');
        $url  = "$base/apps/openregister/api/objects/{$registerId}/{$schemaId}/00000000-0000-0000-0000-000000000000";

        $this->assertNull(
            $this->provider->resolveReference($url),
            'reference resolve MUST return null for a UUID that does not exist'
        );
    }

    public function testCachePrefixIsContentAddressedByObjectIdentity(): void
    {
        $registerId = (string) $this->testRegister->getId();
        $schemaId   = (string) $this->testSchema->getId();
        $uuid       = (string) $this->testObject->getUuid();

        $base       = rtrim(\OC::$server->get(\OCP\IURLGenerator::class)->getAbsoluteURL('/'), '/');
        $url        = "$base/apps/openregister/api/objects/{$registerId}/{$schemaId}/{$uuid}";
        $prefix     = $this->provider->getCachePrefix($url);

        // The cache prefix MUST identify the object uniquely so cache
        // invalidation can target the right entries when the object updates.
        $this->assertStringContainsString($registerId, $prefix);
        $this->assertStringContainsString($schemaId,   $prefix);
        $this->assertStringContainsString($uuid,       $prefix);
    }

    public function testCacheKeyIsPerUserSoRbacResolutionStaysIsolated(): void
    {
        // The provider was constructed by DI with whatever uid was in
        // session at construction time (typically null in CLI tests),
        // so verify the contract by constructing a fresh provider with
        // the desired uid — that's the same path NC's reference manager
        // takes on each request.
        $providerForAdmin = new ObjectReferenceProvider(
            \OC::$server->get(\OCP\IURLGenerator::class),
            \OC::$server->get(\OCP\L10N\IFactory::class)->get('openregister'),
            \OC::$server->get(\OCA\OpenRegister\Service\ObjectService::class),
            \OC::$server->get(\OCA\OpenRegister\Service\DeepLinkRegistryService::class),
            \OC::$server->get(\OCA\OpenRegister\Db\SchemaMapper::class),
            \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class),
            \OC::$server->get(\Psr\Log\LoggerInterface::class),
            'admin'
        );

        $providerForAnon = new ObjectReferenceProvider(
            \OC::$server->get(\OCP\IURLGenerator::class),
            \OC::$server->get(\OCP\L10N\IFactory::class)->get('openregister'),
            \OC::$server->get(\OCA\OpenRegister\Service\ObjectService::class),
            \OC::$server->get(\OCA\OpenRegister\Service\DeepLinkRegistryService::class),
            \OC::$server->get(\OCA\OpenRegister\Db\SchemaMapper::class),
            \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class),
            \OC::$server->get(\Psr\Log\LoggerInterface::class),
            null
        );

        $this->assertSame('admin', $providerForAdmin->getCacheKey('any-ref-id'));
        $this->assertSame('',      $providerForAnon->getCacheKey('any-ref-id'));
        $this->assertNotSame(
            $providerForAdmin->getCacheKey('any-ref-id'),
            $providerForAnon->getCacheKey('any-ref-id'),
            'authenticated and anonymous callers MUST get distinct cache keys'
        );
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-smartpicker-' . uniqid());
        $register->setDescription('Smart Picker reference provider tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-smartpicker-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-smartpicker-schema-' . uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-smartpicker-schema-' . uniqid());
        $schema->setProperties([
            'title'       => ['type' => 'string', 'title' => 'Title'],
            'description' => ['type' => 'string', 'title' => 'Description'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);
        $this->createdTable = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->testSchema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $this->testRegister->getId());
        $entity->setSchema((string) $this->testSchema->getId());
        $entity->setObject([
            'title'       => 'Smart Picker test object',
            'description' => 'Used by the integration test for the reference provider',
        ]);
        $this->testObject = $this->objectMapper->insertObjectEntity($entity, $this->testRegister, $this->testSchema, false);
    }
}
