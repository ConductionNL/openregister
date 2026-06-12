<?php

/**
 * Integration tests for URN resource addressing.
 *
 * Verifies the RFC 8141 URN service end-to-end: URN generation from
 * a real object, bidirectional URN ↔ URL resolution, bulk resolution,
 * and the @self.urn surface that flows through `RenderObject`.
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
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\UrnService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class UrnResourceAddressingIntegrationTest extends TestCase
{
    private UrnService $urnService;
    private RenderObject $renderObject;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;
    private IURLGenerator $urlGenerator;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    private ?ObjectEntity $testObject = null;
    private ?string $createdTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urnService     = \OC::$server->get(UrnService::class);
        $this->renderObject   = \OC::$server->get(RenderObject::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper   = \OC::$server->get(MagicMapper::class);
        $this->urlGenerator   = \OC::$server->get(IURLGenerator::class);

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

    public function testBuildForObjectProducesRfc8141Urn(): void
    {
        $urn = $this->urnService->buildForObject($this->testObject);

        $this->assertNotNull($urn);
        // RFC 8141 §2: URN syntax is `urn:<NID>:<NSS>` where NID is the
        // namespace identifier. Our default NID is `nl-or`.
        $this->assertStringStartsWith('urn:nl-or:', $urn);

        // The URN MUST embed the lower-cased uuid + register slug + schema slug.
        $this->assertStringContainsString(strtolower((string) $this->testObject->getUuid()), $urn);
        $this->assertStringContainsString(strtolower((string) $this->testRegister->getSlug()), $urn);
        $this->assertStringContainsString(strtolower((string) $this->testSchema->getSlug()), $urn);
    }

    public function testParseRoundTripsBuildOutput(): void
    {
        $urn   = $this->urnService->buildForObject($this->testObject);
        $parts = $this->urnService->parse($urn);

        $this->assertIsArray($parts);
        $this->assertSame($this->urnService->getInstanceSlug(),                $parts['instance']);
        $this->assertSame(strtolower((string) $this->testRegister->getSlug()), $parts['register']);
        $this->assertSame(strtolower((string) $this->testSchema->getSlug()),   $parts['schema']);
        $this->assertSame(strtolower((string) $this->testObject->getUuid()),   $parts['uuid']);
    }

    public function testParseRejectsMalformedUrns(): void
    {
        $this->assertNull($this->urnService->parse('not-a-urn-at-all'));
        $this->assertNull($this->urnService->parse('urn:wrong-nid:does:not:matter:00000000-0000-0000-0000-000000000000'));
        $this->assertNull($this->urnService->parse('urn:nl-or:only-two-parts'));
    }

    public function testResolveUrlReturnsCanonicalApiUrlForKnownObject(): void
    {
        $urn = $this->urnService->buildForObject($this->testObject);
        $url = $this->urnService->resolveUrl($urn);

        $this->assertNotNull($url);
        $this->assertStringContainsString('/apps/openregister/api/objects/', $url);
        $this->assertStringContainsString((string) $this->testRegister->getSlug(), $url);
        $this->assertStringContainsString((string) $this->testSchema->getSlug(), $url);
        $this->assertStringContainsString((string) $this->testObject->getUuid(), $url);
    }

    public function testResolveUrlReturnsNullForUnknownRegisterOrSchema(): void
    {
        // Build a URN against a register slug that does not exist.
        $bogus = $this->urnService->build(
            registerSlug: 'does-not-exist-register-' . uniqid(),
            schemaSlug: 'does-not-exist-schema-' . uniqid(),
            uuid: '00000000-0000-0000-0000-000000000000'
        );

        $this->assertNull(
            $this->urnService->resolveUrl($bogus),
            'resolveUrl MUST return null for a URN whose register/schema do not exist on this instance'
        );
    }

    public function testResolveUrlReturnsNullForCrossInstanceUrn(): void
    {
        // Build a URN against a different instance — federation is v1.1.
        $crossInstanceUrn = sprintf(
            'urn:nl-or:%s:%s:%s:%s',
            'some-other-instance-' . uniqid(),
            (string) $this->testRegister->getSlug(),
            (string) $this->testSchema->getSlug(),
            (string) $this->testObject->getUuid()
        );

        $this->assertNull(
            $this->urnService->resolveUrl($crossInstanceUrn),
            'resolveUrl MUST return null for a URN that belongs to a different instance'
        );
    }

    public function testUrnFromUrlIsTheReverseOfResolveUrl(): void
    {
        $urn       = $this->urnService->buildForObject($this->testObject);
        $url       = $this->urnService->resolveUrl($urn);
        $roundTrip = $this->urnService->urnFromUrl($url);

        $this->assertSame($urn, $roundTrip, 'urnFromUrl MUST be the exact inverse of resolveUrl');
    }

    public function testUrnFromUrlReturnsNullForUnrelatedUrl(): void
    {
        $base = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
        $this->assertNull($this->urnService->urnFromUrl("$base/some/unrelated/path"));
        $this->assertNull($this->urnService->urnFromUrl('not-a-url'));
    }

    public function testResolveBulkProducesPerInputMapping(): void
    {
        $known   = $this->urnService->buildForObject($this->testObject);
        $unknown = $this->urnService->build('phantom-register', 'phantom-schema', '00000000-0000-0000-0000-000000000000');
        $bogus   = 'not-a-urn-at-all';

        $resolved = $this->urnService->resolveBulk([$known, $unknown, $bogus]);

        $this->assertArrayHasKey($known,   $resolved);
        $this->assertArrayHasKey($unknown, $resolved);
        $this->assertArrayHasKey($bogus,   $resolved);

        $this->assertNotNull($resolved[$known], 'known URN MUST resolve');
        $this->assertNull($resolved[$unknown], 'unknown URN MUST resolve to null');
        $this->assertNull($resolved[$bogus],   'malformed URN MUST resolve to null');
    }

    public function testRenderObjectPopulatesUrnOnEntity(): void
    {
        // Reset the entity's transient state, then run it through
        // RenderObject and assert the URN was attached.
        $this->testObject->setUrn(null);

        $rendered = $this->renderObject->renderEntity(
            entity: $this->testObject,
            _extend: [],
            depth: 0,
            filter: [],
            fields: [],
            unset: [],
            registers: [],
            schemas: [],
            objects: [],
            visitedIds: [],
            _rbac: false,
            _multitenancy: false
        );

        $this->assertNotNull($rendered->getUrn(), 'RenderObject MUST populate $entity->getUrn() during render');
        $this->assertStringStartsWith('urn:nl-or:', $rendered->getUrn());
    }

    public function testJsonSerializeIncludesUrnInSelfEnvelopeWhenSet(): void
    {
        $urn = $this->urnService->buildForObject($this->testObject);
        $this->testObject->setUrn($urn);

        $serialized = $this->testObject->jsonSerialize();

        $this->assertArrayHasKey('@self', $serialized);
        $this->assertSame($urn, $serialized['@self']['urn'] ?? null, '@self MUST surface the URN when the entity carries one');
    }

    public function testJsonSerializeOmitsUrnWhenUnset(): void
    {
        // Default state: no urn → @self has no urn key.
        $this->testObject->setUrn(null);

        $serialized = $this->testObject->jsonSerialize();

        $this->assertArrayHasKey('@self', $serialized);
        $this->assertArrayNotHasKey(
            'urn',
            $serialized['@self'],
            'unrendered entities MUST NOT carry a URN in @self (omitted, not null)'
        );
    }

    public function testInstanceSlugIsDeterministicAcrossCalls(): void
    {
        // Two consecutive calls MUST produce the same instance slug —
        // any per-call variation would break URN cache keys.
        $first  = $this->urnService->getInstanceSlug();
        $second = $this->urnService->getInstanceSlug();
        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-urn-' . uniqid());
        $register->setDescription('URN resource addressing tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-urn-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-urn-schema-' . uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-urn-schema-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
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
        $entity->setObject(['title' => 'URN test object']);
        $this->testObject = $this->objectMapper->insertObjectEntity($entity, $this->testRegister, $this->testSchema, false);
    }
}
