<?php

/**
 * Tests for DatabaseIntrospectionService against the real SQLite permits fixture.
 *
 * Covers:
 *  - the blueprint of the permits fixture matches the golden
 *    tests/fixtures/dbal/expected-introspection.json (typed properties, required
 *    excluding PK/nullable, maxLength, FK -> $ref relation dialect + inverse,
 *    view exposure, x-openregister-object-source annotation)
 *  - composite primary keys yield idColumns (joined-id contract)
 *  - a table with no primary key is read-list-only (idColumn null)
 *
 * These are real-introspection tests over a real SQLite database — no mocked
 * schema manager.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
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
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use Doctrine\DBAL\DriverManager;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Dbal\SqlTypeMapper;
use OCA\OpenRegister\Service\Schema\SchemaDiffService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Test class for DatabaseIntrospectionService.
 */
class DatabaseIntrospectionServiceTest extends TestCase
{

    /**
     * Path to the generated SQLite permits fixture.
     *
     * @var string
     */
    private static string $fixturePath;

    /**
     * Build the permits fixture once for the class.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        include_once __DIR__.'/../../../fixtures/dbal/build-permits-sqlite.php';
        self::$fixturePath = sys_get_temp_dir().'/or-dbal-introspection-test-permits.sqlite';
        build_permits_sqlite(path: self::$fixturePath);
    }//end setUpBeforeClass()

    /**
     * Remove the fixture after the class.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$fixturePath) === true) {
            unlink(self::$fixturePath);
        }
    }//end tearDownAfterClass()

    /**
     * Build the introspection service with mappers that are never touched by
     * buildBlueprint() (blueprint building is persistence-free).
     *
     * @return DatabaseIntrospectionService The service under test.
     */
    private function service(): DatabaseIntrospectionService
    {
        $store = new class implements CredentialStore {
            /**
             * {@inheritDoc}
             *
             * @param string $uuid   The credential UUID.
             * @param string $secret The secret.
             * @param string $scope  The scope.
             *
             * @return void
             */
            public function put(string $uuid, string $secret, string $scope='personal'): void
            {
            }//end put()

            /**
             * {@inheritDoc}
             *
             * @param string $uuid  The credential UUID.
             * @param string $scope The scope.
             *
             * @return string|null Always null (no credential configured).
             */
            public function get(string $uuid, string $scope='personal'): ?string
            {
                return null;
            }//end get()

            /**
             * {@inheritDoc}
             *
             * @param string $uuid  The credential UUID.
             * @param string $scope The scope.
             *
             * @return void
             */
            public function delete(string $uuid, string $scope='personal'): void
            {
            }//end delete()
        };

        return new DatabaseIntrospectionService(
            connectionFactory: new DbalConnectionFactory(credentialStore: $store, logger: new NullLogger()),
            typeMapper: new SqlTypeMapper(logger: new NullLogger()),
            registerMapper: (new ReflectionClass(RegisterMapper::class))->newInstanceWithoutConstructor(),
            schemaMapper: (new ReflectionClass(SchemaMapper::class))->newInstanceWithoutConstructor(),
            diffService: new SchemaDiffService(),
            logger: new NullLogger()
        );
    }//end service()

    /**
     * Build a database source pointing at a SQLite file.
     *
     * @param string $path The SQLite file path.
     *
     * @return Source The source.
     */
    private function source(string $path): Source
    {
        $source = new Source();
        $source->setId(7);
        $source->setUuid('00000000-0000-0000-0000-000000000000');
        $source->setTitle('Permits demo');
        $source->setType('database');
        $source->setAuthConfig(['driver' => 'pdo_sqlite', 'path' => $path]);

        return $source;
    }//end source()

    /**
     * The permits fixture blueprint matches the committed golden JSON exactly.
     *
     * @return void
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function testBlueprintMatchesGoldenIntrospection(): void
    {
        $blueprint = $this->service()->buildBlueprint(source: $this->source(path: self::$fixturePath));

        $golden = json_decode(
            (string) file_get_contents(__DIR__.'/../../../fixtures/dbal/expected-introspection.json'),
            true
        );

        // Round-trip through JSON so empty maps/lists normalise identically.
        $actual = json_decode((string) json_encode($blueprint), true);

        $this->assertSame($golden, $actual);
    }//end testBlueprintMatchesGoldenIntrospection()

    /**
     * The applicants schema has the spec-scenario shape: required excludes the
     * PK and nullable columns; email carries maxLength 255; the object-source
     * annotation names the dbal-source provider with idColumn id.
     *
     * @return void
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function testApplicantsSchemaShape(): void
    {
        $blueprint = $this->service()->buildBlueprint(source: $this->source(path: self::$fixturePath));

        $applicants = null;
        foreach ($blueprint['schemas'] as $schema) {
            if ($schema['title'] === 'applicants') {
                $applicants = $schema;
            }
        }

        $this->assertNotNull($applicants);
        $this->assertSame(['full_name', 'email'], $applicants['required']);
        $this->assertSame(255, $applicants['properties']['email']['maxLength']);
        $this->assertArrayHasKey('kvk_number', $applicants['properties']);

        $annotation = $applicants['configuration']['x-openregister-object-source'];
        $this->assertSame('dbal-source', $annotation['provider']);
        $this->assertSame('applicants', $annotation['config']['table']);
        $this->assertSame('id', $annotation['config']['idColumn']);
    }//end testApplicantsSchemaShape()

    /**
     * permits.applicant_id maps onto the canonical relation dialect and
     * applicants gains the inverse side with inversedBy.
     *
     * @return void
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function testForeignKeysMapToRelationDialect(): void
    {
        $blueprint = $this->service()->buildBlueprint(source: $this->source(path: self::$fixturePath));

        $byTitle = [];
        foreach ($blueprint['schemas'] as $schema) {
            $byTitle[$schema['title']] = $schema;
        }

        $applicantId = $byTitle['permits']['properties']['applicant_id'];
        $this->assertSame('string', $applicantId['type']);
        $this->assertSame('applicants', $applicantId['$ref']);
        $this->assertSame('related-object', $applicantId['objectConfiguration']['handling']);

        $inverse = $byTitle['applicants']['properties']['permits_via_applicant_id'];
        $this->assertSame('array', $inverse['type']);
        $this->assertSame('permits', $inverse['items']['$ref']);
        $this->assertSame('applicant_id', $inverse['items']['inversedBy']);
    }//end testForeignKeysMapToRelationDialect()

    /**
     * The active_permits view is exposed as a schema served by the same provider.
     *
     * @return void
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function testViewIsExposedAsSchema(): void
    {
        $blueprint = $this->service()->buildBlueprint(source: $this->source(path: self::$fixturePath));

        $view = null;
        foreach ($blueprint['schemas'] as $schema) {
            if ($schema['title'] === 'active_permits') {
                $view = $schema;
            }
        }

        $this->assertNotNull($view);
        $annotation = $view['configuration']['x-openregister-object-source'];
        $this->assertSame('dbal-source', $annotation['provider']);
        $this->assertTrue($annotation['config']['isView']);
        $this->assertNull($annotation['config']['idColumn']);
        $this->assertArrayHasKey('status', $view['properties']);
    }//end testViewIsExposedAsSchema()

    /**
     * A composite primary key yields idColumns in config; a no-PK table is
     * read-list-only with idColumn null.
     *
     * @return void
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function testCompositeAndMissingPrimaryKeys(): void
    {
        $path = sys_get_temp_dir().'/or-dbal-introspection-test-pk.sqlite';
        if (file_exists($path) === true) {
            unlink($path);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $connection->executeStatement(
            'CREATE TABLE tenant_codes (tenant_id INTEGER NOT NULL, code VARCHAR(16) NOT NULL, label VARCHAR(64), PRIMARY KEY (tenant_id, code))'
        );
        $connection->executeStatement('CREATE TABLE loose_rows (payload VARCHAR(64))');
        $connection->close();

        $blueprint = $this->service()->buildBlueprint(source: $this->source(path: $path));

        $byTitle = [];
        foreach ($blueprint['schemas'] as $schema) {
            $byTitle[$schema['title']] = $schema['configuration']['x-openregister-object-source']['config'];
        }

        $this->assertNull($byTitle['tenant_codes']['idColumn']);
        $this->assertSame(['tenant_id', 'code'], $byTitle['tenant_codes']['idColumns']);

        $this->assertNull($byTitle['loose_rows']['idColumn']);
        $this->assertArrayNotHasKey('idColumns', $byTitle['loose_rows']);

        unlink($path);
    }//end testCompositeAndMissingPrimaryKeys()


    /**
     * Engine-internal catalog objects are never introspected into schemas.
     *
     * Live-observed on PostgreSQL: listViews() surfaced `pg_user_mappings`
     * from BOTH pg_catalog and information_schema, colliding on one slug and
     * aborting introspection with a unique-constraint violation.
     *
     * @return void
     *
     * @spec openspec/specs/dbal-virtual-registers/spec.md
     */
    public function testSystemCatalogObjectsAreFiltered(): void
    {
        $service    = $this->service();
        $reflection = new \ReflectionMethod($service, 'isSystemObject');
        $reflection->setAccessible(true);

        $system = [
            'pg_catalog.pg_user_mappings',
            'information_schema.tables',
            'pg_toast.pg_toast_2618',
            'pg_user_mappings',
            'mysql.user',
            'performance_schema.threads',
            'sys.schema_auto_increment_columns',
            'sqlite_sequence',
        ];
        foreach ($system as $name) {
            $this->assertTrue($reflection->invoke($service, $name), $name.' must be filtered as a system object');
        }

        $userData = ['permits', 'public.applicants', 'active_permits', 'pgadmin_notes'];
        foreach ($userData as $name) {
            $this->assertFalse($reflection->invoke($service, $name), $name.' must NOT be filtered');
        }
    }//end testSystemCatalogObjectsAreFiltered()
}//end class
