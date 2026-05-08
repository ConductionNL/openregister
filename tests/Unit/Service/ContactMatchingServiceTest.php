<?php

declare(strict_types=1);

/**
 * ContactMatchingService Unit Tests
 *
 * Tests the contact-entity matching service including email, name,
 * organization matching, combined matching, cache behavior, and invalidation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ContactMatchingService.
 */
class ContactMatchingServiceTest extends TestCase
{

    private ObjectService&MockObject $objectService;
    private SchemaMapper&MockObject $schemaMapper;
    private RegisterMapper&MockObject $registerMapper;
    private ICacheFactory&MockObject $cacheFactory;
    private ICache&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private ContactMatchingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService  = $this->createMock(ObjectService::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->cacheFactory   = $this->createMock(ICacheFactory::class);
        $this->cache          = $this->createMock(ICache::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->cacheFactory->method('createDistributed')
            ->with('openregister_contacts')
            ->willReturn($this->cache);

        $this->service = new ContactMatchingService(
            $this->objectService,
            $this->schemaMapper,
            $this->registerMapper,
            $this->cacheFactory,
            $this->logger
        );
    }

    // -------------------------------------------------------------------------
    // matchByEmail
    // -------------------------------------------------------------------------

    public function testMatchByEmailReturnsResultsWithConfidenceOne(): void
    {
        $email = 'jan@example.nl';

        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->once())->method('set');

        $schema = new Schema();
        $schema->setTitle('Medewerkers');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Gemeente');
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self'   => ['uuid' => 'abc-123', 'schema' => 1, 'register' => 2],
                    'email'   => 'jan@example.nl',
                    'naam'    => 'Jan de Vries',
                    'functie' => 'Beleidsmedewerker',
                ],
            ]);

        $results = $this->service->matchByEmail($email);

        $this->assertCount(1, $results);
        $this->assertSame(1.0, $results[0]['confidence']);
        $this->assertSame('email', $results[0]['matchType']);
        $this->assertSame('abc-123', $results[0]['uuid']);
        $this->assertFalse($results[0]['cached']);
    }

    public function testMatchByEmailIsCaseInsensitive(): void
    {
        $this->cache->method('get')->willReturn(null);

        $schema = new Schema();
        $schema->setTitle('People');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Main');
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self' => ['uuid' => 'def-456', 'schema' => 1, 'register' => 1],
                    'email' => 'JAN@EXAMPLE.NL',
                    'name'  => 'Jan',
                ],
            ]);

        $results = $this->service->matchByEmail('jan@example.nl');

        $this->assertCount(1, $results);
        $this->assertSame(1.0, $results[0]['confidence']);
    }

    public function testMatchByEmailReturnsEmptyArrayForNoMatch(): void
    {
        $this->cache->method('get')->willReturn(null);

        $this->objectService->method('searchObjects')->willReturn([]);

        $results = $this->service->matchByEmail('nobody@example.nl');

        $this->assertCount(0, $results);
    }

    public function testMatchByEmailReturnsCachedResultsWithoutDbQuery(): void
    {
        $cachedData = json_encode([
            [
                'uuid'       => 'cached-uuid',
                'register'   => ['id' => 1, 'title' => 'Test'],
                'schema'     => ['id' => 1, 'title' => 'Test'],
                'title'      => 'Cached Result',
                'matchType'  => 'email',
                'confidence' => 1.0,
                'properties' => [],
                'cached'     => false,
            ],
        ]);

        $this->cache->method('get')->willReturn($cachedData);

        // ObjectService should NOT be called when cache hits.
        $this->objectService->expects($this->never())->method('searchObjects');

        $results = $this->service->matchByEmail('cached@example.nl');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['cached']);
    }

    public function testMatchByEmailWithEmptyStringReturnsEmpty(): void
    {
        $results = $this->service->matchByEmail('');
        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // matchByName
    // -------------------------------------------------------------------------

    public function testMatchByNameFullMatchReturnsConfidencePointSeven(): void
    {
        $this->cache->method('get')->willReturn(null);

        $schema = new Schema();
        $schema->setTitle('Personen');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Gemeente');
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self'      => ['uuid' => 'name-123', 'schema' => 1, 'register' => 1],
                    'voornaam'   => 'Jan',
                    'achternaam' => 'Vries',
                ],
            ]);

        $results = $this->service->matchByName('Jan Vries');

        $this->assertCount(1, $results);
        $this->assertSame(0.7, $results[0]['confidence']);
        $this->assertSame('name', $results[0]['matchType']);
    }

    public function testMatchByNamePartialMatchReturnsConfidencePointFour(): void
    {
        $this->cache->method('get')->willReturn(null);

        $schema = new Schema();
        $schema->setTitle('Personen');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Main');
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self'      => ['uuid' => 'partial-456', 'schema' => 1, 'register' => 1],
                    'voornaam'   => 'Jan',
                    'achternaam' => 'de Boer',
                ],
            ]);

        // Only "Jan" matches, not "Vries".
        $results = $this->service->matchByName('Jan Vries');

        $this->assertCount(1, $results);
        $this->assertSame(0.4, $results[0]['confidence']);
    }

    public function testMatchByNameNoMatchReturnsEmptyArray(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->objectService->method('searchObjects')->willReturn([]);

        $results = $this->service->matchByName('Nobody');
        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // matchByOrganization
    // -------------------------------------------------------------------------

    public function testMatchByOrganizationExactMatchReturnsConfidencePointFive(): void
    {
        $this->cache->method('get')->willReturn(null);

        $schema = new Schema();
        $schema->setTitle('Organisaties');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Main');
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self'       => ['uuid' => 'org-789', 'schema' => 1, 'register' => 1],
                    'schema'      => ['title' => 'Organisaties'],
                    'organisatie' => 'Gemeente Tilburg',
                    'naam'        => 'Gemeente Tilburg',
                ],
            ]);

        $results = $this->service->matchByOrganization('Gemeente Tilburg');

        $this->assertCount(1, $results);
        $this->assertSame(0.5, $results[0]['confidence']);
        $this->assertSame('organization', $results[0]['matchType']);
    }

    public function testMatchByOrganizationNoMatchReturnsEmptyArray(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->objectService->method('searchObjects')->willReturn([]);

        $results = $this->service->matchByOrganization('Nonexistent Corp');
        $this->assertCount(0, $results);
    }

    public function testMatchByOrganizationFiltersToOrgTypedSchemasOnly(): void
    {
        $this->cache->method('get')->willReturn(null);

        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self'       => ['uuid' => 'person-1', 'schema' => 1, 'register' => 1],
                    'naam'        => 'Gemeente Tilburg',
                ],
            ]);

        // Schema title is "Personen" which does NOT match org patterns.
        $schema = new Schema();
        $schema->setTitle('Personen');
        $this->schemaMapper->method('find')->willReturn($schema);

        $results = $this->service->matchByOrganization('Gemeente Tilburg');

        // Should be filtered out because schema is not org-typed.
        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // matchContact (combined)
    // -------------------------------------------------------------------------

    public function testMatchContactDeduplicatesByUuidKeepingHighestConfidence(): void
    {
        $this->cache->method('get')->willReturn(null);

        $schema = new Schema();
        $schema->setTitle('Medewerkers');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Main');
        $this->registerMapper->method('find')->willReturn($register);

        // Same object matched by both email and name search.
        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self'      => ['uuid' => 'shared-uuid', 'schema' => 1, 'register' => 1],
                    'email'      => 'jan@example.nl',
                    'voornaam'   => 'Jan',
                    'achternaam' => 'de Vries',
                ],
            ]);

        $results = $this->service->matchContact('jan@example.nl', 'Jan de Vries');

        // Should be deduplicated: only one result.
        $this->assertCount(1, $results);
        // Email confidence (1.0) should be kept over name confidence (0.7).
        $this->assertSame(1.0, $results[0]['confidence']);
    }

    public function testMatchContactEmptyEmailWithNameOnly(): void
    {
        $this->cache->method('get')->willReturn(null);

        $schema = new Schema();
        $schema->setTitle('Personen');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Main');
        $this->registerMapper->method('find')->willReturn($register);

        $this->objectService->method('searchObjects')
            ->willReturn([
                [
                    '@self'    => ['uuid' => 'name-only', 'schema' => 1, 'register' => 1],
                    'voornaam' => 'Jan',
                    'achternaam' => 'de Vries',
                ],
            ]);

        $results = $this->service->matchContact('', 'Jan de Vries');

        $this->assertCount(1, $results);
        $this->assertSame('name', $results[0]['matchType']);
    }

    public function testMatchContactAllThreeParametersProvided(): void
    {
        $this->cache->method('get')->willReturn(null);

        $schema = new Schema();
        $schema->setTitle('Organisaties');
        $this->schemaMapper->method('find')->willReturn($schema);

        $register = new Register();
        $register->setTitle('Main');
        $this->registerMapper->method('find')->willReturn($register);

        // Different objects for email and org.
        $callCount = 0;
        $this->objectService->method('searchObjects')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // Email search.
                    return [
                        [
                            '@self'  => ['uuid' => 'email-uuid', 'schema' => 1, 'register' => 1],
                            'email'  => 'info@gemeente.nl',
                            'naam'   => 'Info Account',
                        ],
                    ];
                }
                if ($callCount === 2) {
                    // Name search.
                    return [];
                }
                // Org search.
                return [
                    [
                        '@self'       => ['uuid' => 'org-uuid', 'schema' => 1, 'register' => 1],
                        'organisatie' => 'Gemeente Tilburg',
                        'naam'        => 'Gemeente Tilburg',
                    ],
                ];
            });

        $results = $this->service->matchContact(
            'info@gemeente.nl',
            'Info Account',
            'Gemeente Tilburg'
        );

        // Both email match and org match should appear.
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    // -------------------------------------------------------------------------
    // getRelatedObjectCounts
    // -------------------------------------------------------------------------

    public function testGetRelatedObjectCountsGroupsBySchemaTitle(): void
    {
        $matches = [
            ['schema' => ['title' => 'Zaken']],
            ['schema' => ['title' => 'Zaken']],
            ['schema' => ['title' => 'Zaken']],
            ['schema' => ['title' => 'Leads']],
            ['schema' => ['title' => 'Documenten']],
            ['schema' => ['title' => 'Documenten']],
        ];

        $counts = $this->service->getRelatedObjectCounts($matches);

        $this->assertSame(3, $counts['Zaken']);
        $this->assertSame(1, $counts['Leads']);
        $this->assertSame(2, $counts['Documenten']);
    }

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    public function testInvalidateCacheRemovesCacheEntry(): void
    {
        $email    = 'jan@example.nl';
        $cacheKey = 'or_contact_match_email_' . hash('sha256', strtolower($email));

        $this->cache->expects($this->once())
            ->method('remove')
            ->with($cacheKey);

        $this->service->invalidateCache($email);
    }

    public function testInvalidateCacheForObjectExtractsEmailProperties(): void
    {
        $object = [
            'email'   => 'jan@example.nl',
            'naam'    => 'Jan de Vries',
            'functie' => 'Developer',
        ];

        // Should call remove once for the email property.
        $this->cache->expects($this->once())
            ->method('remove');

        $this->service->invalidateCacheForObject($object);
    }

    public function testInvalidateCacheForObjectIgnoresNonEmailProperties(): void
    {
        $object = [
            'naam'    => 'Jan de Vries',
            'functie' => 'Developer',
        ];

        // Should not call remove for non-email properties.
        $this->cache->expects($this->never())
            ->method('remove');

        $this->service->invalidateCacheForObject($object);
    }
}
