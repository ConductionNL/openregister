<?php

/**
 * Regression tests for ObjectService::paginateObjectSource() (design D4b).
 *
 * Covers:
 *  - a provider with a real count(): page-2/limit-50 over 120 rows returns
 *    total 120, pages 3, page 2, and working next/prev markers
 *  - a provider whose count() throws falls back to the pre-existing in-memory
 *    behaviour (total = returned count, single page) without an error
 *  - a provider whose count() returns a value inconsistent with the returned
 *    window (smaller than offset + results) also falls back
 *  - a schema without an object source returns null (dispatch untouched)
 *
 * The ObjectService is instantiated without its (huge) constructor and only the
 * fields the dispatch reads are injected via reflection — the method under test
 * runs its REAL logic against stub providers.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Test class for the object-source pagination dispatch.
 */
class PaginateObjectSourceTest extends TestCase
{
    /**
     * Build a stub provider over a fixed row count.
     *
     * @param int  $totalRows    The full dataset size.
     * @param bool $countThrows  Whether count() throws (no count support).
     * @param int  $countReturns Override for count()'s return (-1 = real total).
     *
     * @return ObjectSourceProvider The stub provider.
     */
    private function stubProvider(int $totalRows, bool $countThrows=false, int $countReturns=-1): ObjectSourceProvider
    {
        return new class ($totalRows, $countThrows, $countReturns) implements ObjectSourceProvider {
            /**
             * @param int  $totalRows    Dataset size.
             * @param bool $countThrows  Whether count() throws.
             * @param int  $countReturns Count override (-1 = real).
             */
            public function __construct(
                private readonly int $totalRows,
                private readonly bool $countThrows,
                private readonly int $countReturns,
            ) {
            }//end __construct()

            /**
             * {@inheritDoc}
             *
             * @return string The provider id.
             */
            public function getId(): string
            {
                return 'stub-source';
            }//end getId()

            /**
             * {@inheritDoc}
             *
             * @return bool Always enabled.
             */
            public function isEnabled(): bool
            {
                return true;
            }//end isEnabled()

            /**
             * {@inheritDoc}
             *
             * @param Register             $register The register.
             * @param Schema               $schema   The schema.
             * @param string               $id       The id.
             * @param array<string, mixed> $config   The config.
             *
             * @return ObjectEntity|null Always null (unused here).
             */
            public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
            {
                return null;
            }//end find()

            /**
             * {@inheritDoc}
             *
             * Applies limit/offset over the synthetic dataset like a SQL page.
             *
             * @param Register             $register The register.
             * @param Schema               $schema   The schema.
             * @param array<string, mixed> $query    The query.
             * @param array<string, mixed> $config   The config.
             *
             * @return ObjectEntity[] The page of objects.
             */
            public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
            {
                $limit  = (int) ($query['limit'] ?? $this->totalRows);
                $offset = (int) ($query['offset'] ?? 0);

                $objects = [];
                $end     = min($this->totalRows, ($offset + $limit));
                for ($i = ($offset + 1); $i <= $end; $i++) {
                    $entity = new ObjectEntity();
                    $entity->setUuid((string) $i);
                    $entity->setObject(['id' => $i]);
                    $objects[] = $entity;
                }

                return $objects;
            }//end findAll()

            /**
             * {@inheritDoc}
             *
             * @param Register             $register The register.
             * @param Schema               $schema   The schema.
             * @param array<string, mixed> $query    The query.
             * @param array<string, mixed> $config   The config.
             *
             * @return int The (possibly overridden) total.
             */
            public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
            {
                if ($this->countThrows === true) {
                    throw new \RuntimeException('count not supported');
                }

                if ($this->countReturns >= 0) {
                    return $this->countReturns;
                }

                return $this->totalRows;
            }//end count()
        };
    }//end stubProvider()

    /**
     * Invoke the real private paginateObjectSource() with injected context.
     *
     * @param ObjectSourceProvider|null $provider The provider to register, or null.
     * @param Schema|null               $schema   The current schema, or null.
     * @param array<string, mixed>      $query    The query.
     *
     * @return array<string, mixed>|null The dispatch result.
     */
    private function paginate(
        ?ObjectSourceProvider $provider,
        ?Schema $schema,
        array $query,
        ?PermissionHandler $permissionHandler=null
    ): ?array {
        $registry = new ObjectSourceRegistry(logger: new NullLogger());
        if ($provider !== null) {
            $registry->addProvider($provider);
        }

        $register = new Register();
        $register->setId(1);

        // A permissive handler by default: checkPermission() is a void method,
        // so the bare mock allows every action unless a test overrides it.
        if ($permissionHandler === null) {
            $permissionHandler = $this->createMock(PermissionHandler::class);
        }

        $reflection = new ReflectionClass(ObjectService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'objectSourceRegistry' => $registry,
            'logger'               => new NullLogger(),
            'currentRegister'      => $register,
            'currentSchema'        => $schema,
            'permissionHandler'    => $permissionHandler,
        ] as $property => $value
        ) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        $method = $reflection->getMethod('paginateObjectSource');
        $method->setAccessible(true);

        return $method->invoke($service, $query);
    }//end paginate()

    /**
     * Build a schema bound to the stub provider.
     *
     * @return Schema The sourced schema.
     */
    private function sourcedSchema(): Schema
    {
        $schema = new Schema();
        $schema->setId(5);
        $schema->setSlug('stub');
        $schema->setConfiguration(
            ['x-openregister-object-source' => ['provider' => 'stub-source', 'config' => []]]
        );

        return $schema;
    }//end sourcedSchema()

    /**
     * Page 2 / limit 50 over 120 rows: total 120, pages 3, page 2, next+prev set.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testTruePaginationMetadataFromProviderCount(): void
    {
        $result = $this->paginate(
            provider: $this->stubProvider(totalRows: 120),
            schema: $this->sourcedSchema(),
            query: ['limit' => 50, 'offset' => 50]
        );

        $this->assertNotNull($result);
        $this->assertCount(50, $result['results']);
        $this->assertSame(120, $result['total']);
        $this->assertSame(120, $result['@self']['total']);
        $this->assertSame(2, $result['@self']['page']);
        $this->assertSame(3, $result['@self']['pages']);
        $this->assertSame(3, $result['@self']['next']);
        $this->assertSame(1, $result['@self']['prev']);
        $this->assertSame(50, $result['@self']['limit']);
    }//end testTruePaginationMetadataFromProviderCount()

    /**
     * A provider whose count() throws falls back to the pre-existing in-memory
     * behaviour: total = returned count, single page, no error.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testCountThrowingFallsBackToInMemoryBehaviour(): void
    {
        $result = $this->paginate(
            provider: $this->stubProvider(totalRows: 120, countThrows: true),
            schema: $this->sourcedSchema(),
            query: ['limit' => 50, 'offset' => 50]
        );

        $this->assertNotNull($result);
        $this->assertCount(50, $result['results']);
        $this->assertSame(50, $result['total']);
        $this->assertSame(1, $result['@self']['page']);
        $this->assertSame(1, $result['@self']['pages']);
        $this->assertNull($result['@self']['next']);
        $this->assertNull($result['@self']['prev']);
    }//end testCountThrowingFallsBackToInMemoryBehaviour()

    /**
     * A count() inconsistent with the returned window (smaller than
     * offset + results) signals no real count support and falls back.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testInconsistentCountFallsBack(): void
    {
        $result = $this->paginate(
            provider: $this->stubProvider(totalRows: 120, countReturns: 10),
            schema: $this->sourcedSchema(),
            query: ['limit' => 50, 'offset' => 50]
        );

        $this->assertNotNull($result);
        $this->assertSame(50, $result['total']);
        $this->assertSame(1, $result['@self']['pages']);
    }//end testInconsistentCountFallsBack()

    /**
     * The native-provider shape is preserved for an unpaginated query: the
     * count() equals the full result set, so a single page with the real total.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testUnpaginatedQueryKeepsSinglePageShape(): void
    {
        $result = $this->paginate(
            provider: $this->stubProvider(totalRows: 8),
            schema: $this->sourcedSchema(),
            query: []
        );

        $this->assertNotNull($result);
        $this->assertCount(8, $result['results']);
        $this->assertSame(8, $result['total']);
        $this->assertSame(1, $result['@self']['page']);
        $this->assertSame(1, $result['@self']['pages']);
        $this->assertNull($result['@self']['next']);
        $this->assertNull($result['@self']['prev']);
    }//end testUnpaginatedQueryKeepsSinglePageShape()

    /**
     * A schema without an object source returns null — the dispatch is a no-op
     * for native schemas.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testNativeSchemaIsUntouched(): void
    {
        $schema = new Schema();
        $schema->setId(6);
        $schema->setSlug('native');

        $this->assertNull(
            $this->paginate(provider: $this->stubProvider(totalRows: 3), schema: $schema, query: [])
        );
    }//end testNativeSchemaIsUntouched()

    /**
     * A missing provider degrades to an empty single-page result.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testMissingProviderDegradesToEmptyList(): void
    {
        $result = $this->paginate(provider: null, schema: $this->sourcedSchema(), query: ['limit' => 10]);

        $this->assertNotNull($result);
        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(1, $result['@self']['pages']);
    }//end testMissingProviderDegradesToEmptyList()

    /**
     * Read access parity: a denied user is rejected BEFORE the provider — and
     * therefore the external database — is consulted, so the response reveals
     * nothing about whether matching external rows exist (no enumeration
     * oracle). The same NotAuthorizedException a native schema raises is used.
     *
     * @return void
     *
     * @spec openspec/changes/dbal-virtual-registers/specs/dbal-virtual-registers/spec.md
     */
    public function testDeniedReadRejectsBeforeProviderIsConsulted(): void
    {
        $provider = new class implements ObjectSourceProvider {

            /**
             * Whether any read method was invoked.
             *
             * @var boolean
             */
            public bool $touched = false;

            /**
             * The provider id.
             *
             * @return string The id.
             */
            public function getId(): string
            {
                return 'stub-source';
            }//end getId()

            /**
             * Always enabled.
             *
             * @return bool True.
             */
            public function isEnabled(): bool
            {
                return true;
            }//end isEnabled()

            /**
             * Record the (forbidden) touch and return null.
             *
             * @param Register    $register The register.
             * @param Schema      $schema   The schema.
             * @param string      $id       The object id.
             * @param array       $config   The source config.
             *
             * @return ObjectEntity|null Always null.
             */
            public function find(Register $register, Schema $schema, string $id, array $config=[]): ?ObjectEntity
            {
                $this->touched = true;

                return null;
            }//end find()

            /**
             * Record the (forbidden) touch and return no rows.
             *
             * @param Register $register The register.
             * @param Schema   $schema   The schema.
             * @param array    $query    The query.
             * @param array    $config   The source config.
             *
             * @return array Always empty.
             */
            public function findAll(Register $register, Schema $schema, array $query=[], array $config=[]): array
            {
                $this->touched = true;

                return [];
            }//end findAll()

            /**
             * Record the (forbidden) touch and return zero.
             *
             * @param Register $register The register.
             * @param Schema   $schema   The schema.
             * @param array    $query    The query.
             * @param array    $config   The source config.
             *
             * @return int Always zero.
             */
            public function count(Register $register, Schema $schema, array $query=[], array $config=[]): int
            {
                $this->touched = true;

                return 0;
            }//end count()
        };

        $denyingHandler = $this->createMock(PermissionHandler::class);
        $denyingHandler->expects($this->once())
            ->method('checkPermission')
            ->willThrowException(new NotAuthorizedException('denied'));

        try {
            $this->paginate(
                provider: $provider,
                schema: $this->sourcedSchema(),
                query: ['limit' => 10],
                permissionHandler: $denyingHandler
            );
            $this->fail('Expected NotAuthorizedException was not thrown.');
        } catch (NotAuthorizedException $e) {
            // Expected: denial surfaces exactly like a native schema's.
        }

        $this->assertFalse(
            $provider->touched,
            'The provider (external database) must not be consulted for a denied read.'
        );
    }//end testDeniedReadRejectsBeforeProviderIsConsulted()
}//end class
