<?php

/**
 * Unit tests for JsonLdContextService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\JsonLd
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\JsonLd;

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JsonLdContextServiceTest extends TestCase
{
    private IURLGenerator&MockObject $urlGenerator;
    private JsonLdContextService $service;


    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        // Deterministic context URL for assertions.
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
            function (string $route, array $params = []): string {
                $register = ($params['register'] ?? '');
                $schema   = ($params['schema'] ?? '');
                if ($route === 'openregister.contexts.schema') {
                    return "https://nc.test/index.php/apps/openregister/api/contexts/{$register}/{$schema}";
                }

                return "https://nc.test/index.php/apps/openregister/api/contexts/{$register}";
            }
        );

        $this->service = new JsonLdContextService($this->urlGenerator);
    }


    private function makeRegister(string $slug = 'personen'): Register
    {
        $register = new Register();
        $register->setSlug($slug);
        $register->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));
        return $register;
    }


    private function makeSchema(string $slug, array $properties, ?array $configuration = null, int $id = 1): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->setProperties($properties);
        $schema->setUpdated(new DateTime('2026-02-02T00:00:00+00:00'));
        if ($configuration !== null) {
            $schema->setConfiguration($configuration);
        }

        return $schema;
    }


    public function testZeroConfigDefaults(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            'persoon',
            [
                'name'      => ['type' => 'string'],
                'birthDate' => ['type' => 'string', 'format' => 'date'],
            ]
        );

        $context = $this->service->buildSchemaContext($register, $schema);

        $this->assertSame(JsonLdContextService::OR_NAMESPACE, $context['or']);
        $this->assertSame(JsonLdContextService::XSD_NAMESPACE, $context['xsd']);

        $contextUrl = 'https://nc.test/index.php/apps/openregister/api/contexts/personen/persoon';

        // name is a plain fragment term (no coercion).
        $this->assertSame($contextUrl.'#name', $context['name']);

        // birthDate carries an xsd:date coercion.
        $this->assertSame(
            ['@id' => $contextUrl.'#birthDate', '@type' => 'xsd:date'],
            $context['birthDate']
        );

        // Schema-class term defaults to a fragment term and @type defaults to the slug.
        $this->assertSame($contextUrl.'#persoon', $context['persoon']);
        $this->assertSame('persoon', $this->service->getTypeForSchema($schema));
    }


    public function testFullSchemaOrgMapping(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            'persoon',
            ['name' => ['type' => 'string']],
            [
                'jsonld' => [
                    '@vocab'     => 'https://schema.org/',
                    'type'       => 'https://schema.org/Person',
                    'properties' => ['name' => 'https://schema.org/name'],
                ],
            ]
        );

        $context = $this->service->buildSchemaContext($register, $schema);

        $this->assertSame('https://schema.org/', $context['@vocab']);
        $this->assertSame('https://schema.org/name', $context['name']);
        // The schema class term resolves to the mapped IRI.
        $this->assertSame('https://schema.org/Person', $context['persoon']);
        $this->assertSame('https://schema.org/Person', $this->service->getTypeForSchema($schema));
    }


    public function testPartialMappingMixesVocabularies(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            'persoon',
            [
                'name'          => ['type' => 'string'],
                'dossiernummer' => ['type' => 'string'],
            ],
            [
                'jsonld' => [
                    'properties' => ['name' => 'https://schema.org/name'],
                ],
            ]
        );

        $context    = $this->service->buildSchemaContext($register, $schema);
        $contextUrl = 'https://nc.test/index.php/apps/openregister/api/contexts/personen/persoon';

        $this->assertSame('https://schema.org/name', $context['name']);
        // Unmapped property keeps the zero-config fragment term.
        $this->assertSame($contextUrl.'#dossiernummer', $context['dossiernummer']);
    }


    public function testFormatCoercions(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            'event',
            [
                'startsAt' => ['type' => 'string', 'format' => 'date-time'],
                'homepage' => ['type' => 'string', 'format' => 'uri'],
                'label'    => ['type' => 'string'],
            ]
        );

        $context = $this->service->buildSchemaContext($register, $schema);

        $this->assertSame('xsd:dateTime', $context['startsAt']['@type']);
        $this->assertSame('@id', $context['homepage']['@type']);
        $this->assertIsString($context['label']);
    }


    public function testRelationPropertyIsNodeReference(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema(
            'persoon',
            [
                'employer' => ['type' => 'string', '$ref' => '#/schemas/organisation'],
                'members'  => ['type' => 'array', 'items' => ['$ref' => '#/schemas/persoon']],
            ]
        );

        $context = $this->service->buildSchemaContext($register, $schema);

        $this->assertSame('@id', $context['employer']['@type']);
        $this->assertSame('@id', $context['members']['@type']);
    }


    public function testCacheHitDoesNotRederive(): void
    {
        $register = $this->makeRegister();
        $schema   = $this->makeSchema('persoon', ['name' => ['type' => 'string']]);

        $first  = $this->service->buildSchemaContext($register, $schema);
        $second = $this->service->buildSchemaContext($register, $schema);

        $this->assertSame($first, $second);
    }


    public function testValidateMappingAcceptsValid(): void
    {
        $errors = $this->service->validateMapping(
            [
                '@vocab'     => 'https://schema.org/',
                'type'       => 'https://schema.org/Person',
                'properties' => ['name' => 'https://schema.org/name', 'age' => 'age'],
            ]
        );

        $this->assertSame([], $errors);
    }


    public function testValidateMappingRejectsNonIri(): void
    {
        // No @vocab declared, so a bare label cannot be resolved.
        $errors = $this->service->validateMapping(
            ['properties' => ['name' => 'just a label']]
        );

        $this->assertNotEmpty($errors);
    }


    public function testBuildRegisterContextMergesSchemas(): void
    {
        $register = $this->makeRegister();
        $schemaA  = $this->makeSchema('persoon', ['name' => ['type' => 'string']], null, 1);
        $schemaB  = $this->makeSchema('adres', ['street' => ['type' => 'string']], null, 2);

        $context = $this->service->buildRegisterContext($register, [$schemaA, $schemaB]);

        $this->assertArrayHasKey('or', $context);
        $this->assertArrayHasKey('name', $context);
        $this->assertArrayHasKey('street', $context);
    }
}
