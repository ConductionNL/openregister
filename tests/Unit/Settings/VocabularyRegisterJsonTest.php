<?php

/**
 * Vocabulary register descriptor validity + schema-shape test
 * (skos-concept-registers, SKOS-001).
 *
 * Guards the register JSON itself: valid JSON, the `conceptScheme`/`concept`
 * schemas exist with their minimum required fields, every property carries
 * an English `title` + `description` (hydra-gate-schema-property-titles /
 * gate 28), and `broader`/`narrower`/`related`/`inScheme` use the canonical
 * relation dialect (ADR-062: `type:string`|array + `format:uuid` + `$ref`).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/skos-concept-registers/specs/skos-concept-registers/spec.md#skos-001
 */

declare(strict_types=1);

namespace Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class VocabularyRegisterJsonTest extends TestCase
{

    /**
     * The decoded vocabulary register descriptor.
     *
     * @var array<string, mixed>
     */
    private array $register;

    /**
     * Load the shipped register descriptor.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $path = __DIR__.'/../../../lib/Settings/vocabulary_register.json';
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'vocabulary_register.json must be valid JSON');
        $this->register = $decoded;
    }//end setUp()

    /**
     * The register declares the vocabulary register with both schemas.
     *
     * @return void
     */
    public function testRegisterDeclaresBothSchemas(): void
    {
        $register = ($this->register['components']['registers']['vocabulary'] ?? null);
        $this->assertIsArray($register, 'components.registers.vocabulary must be declared');
        $this->assertSame(['conceptScheme', 'concept'], ($register['schemas'] ?? null));
    }//end testRegisterDeclaresBothSchemas()

    /**
     * conceptScheme carries the SKOS-001 minimum required fields.
     *
     * @return void
     */
    public function testConceptSchemeHasMinimumRequiredFields(): void
    {
        $schema = ($this->register['components']['schemas']['conceptScheme'] ?? null);
        $this->assertIsArray($schema, 'conceptScheme schema must exist');

        foreach (['uri', 'title', 'publisher', 'version', 'source'] as $field) {
            $this->assertContains($field, ($schema['required'] ?? []), "conceptScheme.required must include {$field}");
            $this->assertArrayHasKey($field, ($schema['properties'] ?? []), "conceptScheme.properties must declare {$field}");
        }

        $this->assertSame(['public'], ($schema['authorization']['read'] ?? null), 'conceptScheme reads must be public');
        $this->assertArrayNotHasKey('write', ($schema['authorization'] ?? []), 'writes stay admin-gated by omission');
    }//end testConceptSchemeHasMinimumRequiredFields()

    /**
     * concept carries the SKOS-001 minimum required fields and relations.
     *
     * @return void
     */
    public function testConceptHasMinimumRequiredFieldsAndRelations(): void
    {
        $schema = ($this->register['components']['schemas']['concept'] ?? null);
        $this->assertIsArray($schema, 'concept schema must exist');

        foreach (['uri', 'prefLabel', 'inScheme'] as $field) {
            $this->assertContains($field, ($schema['required'] ?? []), "concept.required must include {$field}");
        }

        foreach (['uri', 'prefLabel', 'altLabel', 'definition', 'notation', 'deprecated', 'inScheme', 'broader', 'narrower', 'related'] as $field) {
            $this->assertArrayHasKey($field, ($schema['properties'] ?? []), "concept.properties must declare {$field}");
        }

        $this->assertContains('nl', ($schema['properties']['prefLabel']['required'] ?? []), 'prefLabel.nl must be required');

        $this->assertSame(['public'], ($schema['authorization']['read'] ?? null), 'concept reads must be public');
    }//end testConceptHasMinimumRequiredFieldsAndRelations()

    /**
     * broader/narrower/related/inScheme use the canonical relation dialect
     * (ADR-062): format:uuid + $ref resolving to a schema in this register.
     *
     * @return void
     */
    public function testRelationsUseCanonicalDialect(): void
    {
        $schema = $this->register['components']['schemas']['concept'];
        $schemaKeys = array_keys($this->register['components']['schemas']);

        $singleRelations = ['inScheme' => 'conceptScheme'];
        foreach ($singleRelations as $property => $expectedRef) {
            $prop = $schema['properties'][$property];
            $this->assertSame('string', $prop['type']);
            $this->assertSame('uuid', $prop['format']);
            $this->assertSame($expectedRef, $prop['$ref']);
            $this->assertContains($prop['$ref'], $schemaKeys);
        }

        $arrayRelations = ['broader', 'narrower', 'related'];
        foreach ($arrayRelations as $property) {
            $prop = $schema['properties'][$property];
            $this->assertSame('array', $prop['type']);
            $this->assertSame('string', $prop['items']['type']);
            $this->assertSame('uuid', $prop['items']['format']);
            $this->assertSame('concept', $prop['items']['$ref']);
            $this->assertContains($prop['items']['$ref'], $schemaKeys);
        }
    }//end testRelationsUseCanonicalDialect()

    /**
     * Every property of every schema (including nested object sub-properties)
     * carries a title + description (gate 28).
     *
     * @return void
     */
    public function testEveryPropertyHasTitleAndDescription(): void
    {
        foreach (($this->register['components']['schemas'] ?? []) as $schemaKey => $schema) {
            $this->assertPropertiesHaveTitleAndDescription(
                properties: ($schema['properties'] ?? []),
                context: $schemaKey
            );
        }
    }//end testEveryPropertyHasTitleAndDescription()

    /**
     * Recursively assert title+description on a properties map, including
     * nested object properties and array item properties.
     *
     * @param array<string, mixed> $properties The properties map to check.
     * @param string                $context    Human-readable context for failure messages.
     *
     * @return void
     */
    private function assertPropertiesHaveTitleAndDescription(array $properties, string $context): void
    {
        foreach ($properties as $name => $prop) {
            if (is_array($prop) === false) {
                continue;
            }

            $this->assertArrayHasKey('title', $prop, "{$context}.{$name} must have a title");
            $this->assertArrayHasKey('description', $prop, "{$context}.{$name} must have a description");
            $this->assertNotSame('', trim((string) ($prop['title'] ?? '')), "{$context}.{$name} title must not be empty");
            $this->assertNotSame('', trim((string) ($prop['description'] ?? '')), "{$context}.{$name} description must not be empty");

            if (isset($prop['properties']) === true && is_array($prop['properties']) === true) {
                $this->assertPropertiesHaveTitleAndDescription(
                    properties: $prop['properties'],
                    context: "{$context}.{$name}"
                );
            }

            if (isset($prop['items']['properties']) === true && is_array($prop['items']['properties']) === true) {
                $this->assertPropertiesHaveTitleAndDescription(
                    properties: $prop['items']['properties'],
                    context: "{$context}.{$name}.items"
                );
            }
        }
    }//end assertPropertiesHaveTitleAndDescription()
}//end class
