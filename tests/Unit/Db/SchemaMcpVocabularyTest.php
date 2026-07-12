<?php

declare(strict_types=1);

/*
 * Schema MCP Annotation Vocabulary Unit Tests
 *
 * Verifies that x-openregister-mcp is registered in
 * Schema::ANNOTATION_VOCABULARY and survives the
 * validateConfigurationArray() round-trip instead of being silently
 * dropped as an unknown x-openregister-* key. Mirrors
 * {@see \Unit\Db\SchemaAnnotationVocabularyTest} for the archival/seed
 * dialect pair.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
 */

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for Schema::ANNOTATION_VOCABULARY + configuration round-trip of
 * `x-openregister-mcp`.
 *
 * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
 *   (Requirement: REQ-DIALECT-001 — The x-openregister-mcp schema dialect)
 */
class SchemaMcpVocabularyTest extends TestCase
{

    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new Schema();
    }//end setUp()

    /**
     * ANNOTATION_VOCABULARY constant contains x-openregister-mcp.
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Scenario: Dialect key is retained into configuration on import)
     */
    public function testAnnotationVocabularyContainsMcp(): void
    {
        $vocabulary = (new ReflectionClass(Schema::class))->getConstant('ANNOTATION_VOCABULARY');

        $this->assertIsArray($vocabulary);
        $this->assertContains('x-openregister-mcp', $vocabulary);
    }//end testAnnotationVocabularyContainsMcp()

    /**
     * A well-formed x-openregister-mcp block survives setConfiguration() →
     * getConfiguration() unchanged (fold, not drop).
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Scenario: A well-formed full block saves and round-trips)
     */
    public function testMcpAnnotationSurvivesRoundTrip(): void
    {
        $mcp = [
            'enabled' => true,
            'tools'   => [
                'search' => [
                    'description' => 'Search cases.',
                    'filters'     => ['status'],
                    'scope'       => 'read',
                ],
            ],
        ];

        $this->schema->setConfiguration(['x-openregister-mcp' => $mcp]);

        $config = $this->schema->getConfiguration();

        $this->assertNotNull($config);
        $this->assertArrayHasKey('x-openregister-mcp', $config);
        $this->assertSame($mcp, $config['x-openregister-mcp']);
    }//end testMcpAnnotationSurvivesRoundTrip()

    /**
     * `enabled:false` is a valid opt-out and still round-trips verbatim.
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Scenario: enabled:false is a valid opt-out)
     */
    public function testDisabledMcpAnnotationSurvivesRoundTrip(): void
    {
        $this->schema->setConfiguration(['x-openregister-mcp' => ['enabled' => false]]);

        $config = $this->schema->getConfiguration();

        $this->assertArrayHasKey('x-openregister-mcp', $config);
        $this->assertSame(['enabled' => false], $config['x-openregister-mcp']);
    }//end testDisabledMcpAnnotationSurvivesRoundTrip()

    /**
     * A schema with no x-openregister-mcp key has no such entry in
     * configuration (default OFF — nothing is auto-populated).
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Scenario: Default OFF — absent block exposes nothing)
     */
    public function testAbsentMcpAnnotationIsNotPresent(): void
    {
        $this->schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]);

        $config = $this->schema->getConfiguration();

        $this->assertArrayNotHasKey('x-openregister-mcp', $config);
    }//end testAbsentMcpAnnotationIsNotPresent()

    /**
     * A typo'd key (`x-openregister-mc`) is still dropped, while the
     * correctly-spelled sibling survives — proves the vocabulary gate is
     * exact-match, not prefix-match.
     *
     * @spec openspec/changes/or-mcp-schema-dialect/specs/ai-mcp/spec.md
     *   (Requirement: REQ-DIALECT-001 — The x-openregister-mcp schema dialect)
     */
    public function testTypoedMcpKeyIsDropped(): void
    {
        $this->schema->setConfiguration(
            [
                'x-openregister-mc'  => ['enabled' => true],
                'x-openregister-mcp' => ['enabled' => true],
            ]
        );

        $config = $this->schema->getConfiguration();

        $this->assertArrayNotHasKey('x-openregister-mc', $config);
        $this->assertArrayHasKey('x-openregister-mcp', $config);
    }//end testTypoedMcpKeyIsDropped()
}//end class
