<?php

/**
 * Unit tests for JsonLdSerializer.
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
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializer;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JsonLdSerializerTest extends TestCase
{
    private IURLGenerator&MockObject $urlGenerator;
    private JsonLdSerializer $serializer;
    private Register $register;
    private Schema $schema;


    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
            function (string $route, array $params = []): string {
                if ($route === 'openregister.contexts.schema') {
                    return 'https://nc.test/api/contexts/'.$params['register'].'/'.$params['schema'];
                }

                if ($route === 'openregister.objects.show') {
                    return 'https://nc.test/api/objects/'.$params['register'].'/'.$params['schema'].'/'.$params['id'];
                }

                return 'https://nc.test/'.$route;
            }
        );

        $contextService   = new JsonLdContextService($this->urlGenerator);
        $this->serializer = new JsonLdSerializer($contextService, $this->urlGenerator);

        $this->register = new Register();
        $this->register->setSlug('personen');

        $this->schema = new Schema();
        $this->schema->setId(1);
        $this->schema->setSlug('persoon');
        $this->schema->setProperties(['name' => ['type' => 'string']]);
        $this->schema->setUpdated(new DateTime('2026-02-02T00:00:00+00:00'));
    }


    private function rendered(array $self, array $data = ['name' => 'Jansen']): array
    {
        return array_merge($data, ['@self' => $self]);
    }


    public function testSingleObjectHappyPath(): void
    {
        $object = $this->rendered(
            [
                'id'       => '550e8400',
                'uri'      => 'https://nc.test/api/objects/personen/persoon/550e8400',
                'register' => 'personen',
                'schema'   => 'persoon',
                'urn'      => 'urn:nl:openregister:personen:persoon:550e8400',
            ]
        );

        $doc = $this->serializer->serialize($object, $this->schema, $this->register);

        $this->assertSame('https://nc.test/api/contexts/personen/persoon', $doc['@context']);
        $this->assertSame('https://nc.test/api/objects/personen/persoon/550e8400', $doc['@id']);
        $this->assertSame('persoon', $doc['@type']);
        $this->assertSame('Jansen', $doc['name']);
        $this->assertSame('personen', $doc['or:register']);
        $this->assertSame('persoon', $doc['or:schema']);
        $this->assertSame('urn:nl:openregister:personen:persoon:550e8400', $doc['or:urn']);
        // @self is lifted, never emitted; uri is exposed only as @id.
        $this->assertArrayNotHasKey('@self', $doc);
        $this->assertArrayNotHasKey('or:uri', $doc);
    }


    public function testMissingUriFallsBackToShowRoute(): void
    {
        $object = $this->rendered(['id' => 'abc-123', 'register' => 'personen', 'schema' => 'persoon']);

        $doc = $this->serializer->serialize($object, $this->schema, $this->register);

        $this->assertSame('https://nc.test/api/objects/personen/persoon/abc-123', $doc['@id']);
    }


    public function testAtPrefixedDataKeyIsEscaped(): void
    {
        $object = $this->rendered(
            ['id' => 'x', 'register' => 'personen', 'schema' => 'persoon'],
            ['name' => 'Jansen', '@weird' => 'value']
        );

        $doc = $this->serializer->serialize($object, $this->schema, $this->register);

        $this->assertArrayNotHasKey('@weird', $doc);
        $this->assertSame('value', $doc['or:raw#weird']);
    }


    public function testCollectionGraphShape(): void
    {
        $result = [
            'results' => [
                $this->rendered(['id' => 'a', 'uri' => 'https://nc.test/o/a', 'register' => 'personen', 'schema' => 'persoon'], ['name' => 'A']),
                $this->rendered(['id' => 'b', 'uri' => 'https://nc.test/o/b', 'register' => 'personen', 'schema' => 'persoon'], ['name' => 'B']),
            ],
            'total'   => 2,
            'page'    => 1,
            'pages'   => 1,
            'limit'   => 20,
        ];

        $doc = $this->serializer->serializeCollection($result, $this->schema, $this->register);

        $this->assertSame('https://nc.test/api/contexts/personen/persoon', $doc['@context']);
        $this->assertCount(2, $doc['@graph']);
        $this->assertSame('https://nc.test/o/a', $doc['@graph'][0]['@id']);
        $this->assertSame(2, $doc['or:total']);
        $this->assertSame(1, $doc['or:page']);
        // Each node carries @id/@type but no repeated @context.
        $this->assertArrayNotHasKey('@context', $doc['@graph'][0]);
        $this->assertSame('persoon', $doc['@graph'][0]['@type']);
    }


    public function testPaginatedNextLink(): void
    {
        $result = [
            'results' => [],
            'total'   => 50,
            'page'    => 1,
            'pages'   => 3,
            'limit'   => 20,
            '@self'   => ['self' => 'https://nc.test/api/objects/personen/persoon?_limit=20'],
        ];

        $doc = $this->serializer->serializeCollection($result, $this->schema, $this->register);

        $this->assertSame('https://nc.test/api/objects/personen/persoon?_limit=20&_page=2', $doc['or:next']);
    }


    public function testWantsJsonLdNegotiationMatrix(): void
    {
        $this->assertTrue($this->wantsFor('application/ld+json'));
        $this->assertFalse($this->wantsFor('application/json'));
        $this->assertFalse($this->wantsFor('*/*'));
        $this->assertFalse($this->wantsFor(''));
        // q-weighted: ld+json highest wins.
        $this->assertTrue($this->wantsFor('application/json;q=0.5, application/ld+json;q=0.9'));
        // q-weighted: json highest wins.
        $this->assertFalse($this->wantsFor('application/ld+json;q=0.4, application/json;q=0.8'));
        // Explicit type beats subtype wildcard at equal q.
        $this->assertTrue($this->wantsFor('application/ld+json, application/*'));
    }


    private function wantsFor(string $accept): bool
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn($accept);
        return $this->serializer->wantsJsonLd($request);
    }
}
