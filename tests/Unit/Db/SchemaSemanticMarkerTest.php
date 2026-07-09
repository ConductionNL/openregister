<?php

declare(strict_types=1);

/**
 * Schema Semantic Marker Unit Tests
 *
 * Verifies that a schema-level `x-schema-org` marker survives hydration and
 * setConfiguration() so SemanticTypeResolver can discover the provider on the
 * LIVE schema (ADR-048). The fleet declares the marker as a TOP-LEVEL field
 * (a sibling of `properties`, matching the ADR-011 annotation convention); on
 * hydrate it must be folded into `configuration['x-schema-org']`, which is in
 * the passThrough allowlist and read by
 * JsonLdContextService::getImplementedTypes().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/cross-app-semantic-references/specs/semantic-schema-references/spec.md
 *   (Requirement: Schemas advertise the canonical types they implement)
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the x-schema-org marker persistence path.
 */
class SchemaSemanticMarkerTest extends TestCase
{


    /**
     * A TOP-LEVEL `x-schema-org` (sibling of `properties`) is folded into the
     * configuration column on hydrate and survives to getConfiguration().
     *
     * @return void
     */
    public function testTopLevelXSchemaOrgIsFoldedIntoConfigurationOnHydrate(): void
    {
        $schema = new Schema();
        $schema->hydrate(
            object: [
                'title'        => 'Payee',
                'x-schema-org' => 'schema:Organization',
                'properties'   => ['name' => ['type' => 'string']],
            ]
        );

        $config = $schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-schema-org', $config);
        $this->assertSame('schema:Organization', $config['x-schema-org']);
    }//end testTopLevelXSchemaOrgIsFoldedIntoConfigurationOnHydrate()


    /**
     * The folded top-level marker is not left as a stray entity property; only
     * the configuration copy persists (the serialized schema exposes it under
     * configuration, never as a bare top-level key).
     *
     * @return void
     */
    public function testTopLevelMarkerDoesNotLeakAsBareKey(): void
    {
        $schema = new Schema();
        $schema->hydrate(object: ['x-schema-org' => 'schema:Organization']);

        $serialized = $schema->jsonSerialize();

        $this->assertArrayNotHasKey('x-schema-org', $serialized);
        $this->assertIsArray($serialized['configuration']);
        $this->assertSame('schema:Organization', $serialized['configuration']['x-schema-org']);
    }//end testTopLevelMarkerDoesNotLeakAsBareKey()


    /**
     * An `x-schema-org` supplied directly under `configuration` survives the
     * hydrate round-trip unchanged (passThrough allowlist), with no top-level
     * form present.
     *
     * @return void
     */
    public function testConfigurationXSchemaOrgSurvivesHydrate(): void
    {
        $schema = new Schema();
        $schema->hydrate(
            object: [
                'configuration' => ['x-schema-org' => ['schema:Organization', 'schema:Person']],
            ]
        );

        $config = $schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertSame(['schema:Organization', 'schema:Person'], $config['x-schema-org']);
    }//end testConfigurationXSchemaOrgSurvivesHydrate()


    /**
     * When BOTH a top-level and a configuration `x-schema-org` are supplied, the
     * explicit configuration value wins (the top-level form is a convenience,
     * never an override).
     *
     * @return void
     */
    public function testExplicitConfigurationMarkerWinsOverTopLevel(): void
    {
        $schema = new Schema();
        $schema->hydrate(
            object: [
                'x-schema-org'  => 'schema:Person',
                'configuration' => ['x-schema-org' => 'schema:Organization'],
            ]
        );

        $config = $schema->getConfiguration();

        $this->assertSame('schema:Organization', $config['x-schema-org']);
    }//end testExplicitConfigurationMarkerWinsOverTopLevel()
}//end class
