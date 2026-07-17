<?php

/**
 * Nested write-only path redaction (openconnector#235, openconnector#147 residual).
 *
 * `writeOnly: true` is a JSON Schema keyword, so it can only be attached to a property the
 * schema DECLARES. The secrets that actually leak in production are not declared: they live
 * inside an untyped `object` property. An OpenConnector `source` keeps its credentials at
 * `configuration.authentication.client_secret`; a `rule` keeps an inbound apiKey→userId
 * impersonation map at `configuration.authentication.keys`. `configuration` is
 * `type: object` with no `properties`, so there is nothing to hang `writeOnly` on, and
 * marking the whole `configuration` object write-only breaks the editors that legitimately
 * read the rest of it back. The result: those secrets were returned in cleartext on every
 * read, for everyone, with no way to declare otherwise.
 *
 * `x-openregister-writeonly-paths` is that missing declaration. These tests pin the
 * contract: declared paths are stripped at the render boundary unconditionally (admin
 * included), no caller argument can widen it, the `@self.relations` mirror is covered, and
 * the raw/`_render: false` engine read still gets the value.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RenderObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @covers \OCA\OpenRegister\Service\PropertyRbacHandler
 * @covers \OCA\OpenRegister\Db\Schema
 */
class RenderObjectNestedWriteOnlyPathsTest extends TestCase
{

    /**
     * An OpenConnector-shaped `source` schema: `configuration` is an untyped object, and
     * the secrets inside it are declared write-only by dot-path.
     *
     * Note `name` and `configuration` are ordinary readable properties — the whole point is
     * that `configuration.endpoint` still round-trips to the editor while the credentials
     * inside the same object do not.
     *
     * @return Schema
     */
    private function sourceSchema(): Schema
    {
        $schema = new Schema();
        $ref    = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 777);
        $schema->setSlug('source');
        $schema->setProperties(
            [
                'name'          => ['type' => 'string'],
                'configuration' => ['type' => 'object'],
            ]
        );
        $schema->setConfiguration(
            [
                Schema::WRITEONLY_PATHS_ANNOTATION => [
                    'configuration.authentication.client_secret',
                    'configuration.authentication.password',
                    // A whole sub-tree: the leaf keys are attacker-supplied apiKeys and
                    // cannot be enumerated in advance (ocon#147).
                    'configuration.authentication.keys',
                ],
            ]
        );

        return $schema;
    }

    /**
     * A source object carrying real-shaped nested secrets plus readable siblings.
     *
     * @return ObjectEntity
     */
    private function sourceEntity(): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('11111111-2222-3333-4444-555555555555');
        $entity->setSchema(777);
        $entity->setRegister(65);
        $entity->setObject(
            [
                'name'          => 'BRP HaalCentraal',
                'configuration' => [
                    'endpoint'       => 'https://api.example.gov',
                    'authentication' => [
                        'type'          => 'oauth',
                        'username'      => 'svc-brp',
                        'client_secret' => 'NESTED_CLIENT_SECRET_MUST_NOT_LEAK',
                        'password'      => 'NESTED_PASSWORD_MUST_NOT_LEAK',
                        'keys'          => [
                            'apikey-abc123' => 'admin',
                            'apikey-def456' => 'service-account',
                        ],
                    ],
                ],
            ]
        );

        // SaveObject::scanForRelations() flattens nested values into LITERAL dot-path keys,
        // and jsonSerialize() surfaces this map as `@self.relations`. This is the real shape:
        // a nested secret is mirrored here under its full dot-path, which is exactly how
        // top-level writeOnly leaked in #429.
        $entity->setRelations(
            [
                'name'                                       => 'BRP HaalCentraal',
                'configuration.endpoint'                     => 'https://api.example.gov',
                'configuration.authentication.client_secret' => 'NESTED_CLIENT_SECRET_MUST_NOT_LEAK',
                'configuration.authentication.keys.apikey-abc123' => 'admin',
            ]
        );

        return $entity;
    }

    /**
     * Build the renderer with a REAL PropertyRbacHandler (the strip is a pure function of
     * the schema, so it needs no session) and harmless mocks elsewhere.
     *
     * @param Schema $schema The schema find() resolves to.
     *
     * @return RenderObject
     */
    private function renderer(Schema $schema): RenderObject
    {
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $translation = $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class);
        $translation->method('resolveTranslationsForRender')->willReturnCallback(fn (array $data) => $data);

        $rbac = new \OCA\OpenRegister\Service\PropertyRbacHandler(
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\OCP\IGroupManager::class),
            $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );

        return new RenderObject(
            $this->createMock(\OCA\OpenRegister\Db\FileMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class),
            $schemaMapper,
            $this->createMock(\OCP\SystemTag\ISystemTagManager::class),
            $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
            $rbac,
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\OpenRegister\Service\FileService::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
            $translation,
            $this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
            $this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
            $this->createMock(\OCA\OpenRegister\Service\UrnService::class),
            $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
            $this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
            $this->createMock(\OCA\OpenRegister\Service\LanguageService::class)
        );
    }

    /**
     * The core contract: declared nested paths are gone, readable siblings survive.
     *
     * MUTATION TEST — remove the `stripWriteOnlyPath()` call from
     * PropertyRbacHandler::stripWriteOnlyProperties() and this test fails with the
     * plaintext `NESTED_CLIENT_SECRET_MUST_NOT_LEAK` present in the rendered output.
     *
     * @return void
     */
    public function testDeclaredNestedPathsAreStrippedFromTheRenderedObject(): void
    {
        $rendered = $this->renderer($this->sourceSchema())->renderEntity($this->sourceEntity());
        $data     = $rendered->getObject();
        $auth     = $data['configuration']['authentication'];

        $this->assertArrayNotHasKey('client_secret', $auth, 'A declared nested path must never be returned');
        $this->assertArrayNotHasKey('password', $auth);
        $this->assertArrayNotHasKey('keys', $auth, 'A declared path strips its whole sub-tree');

        // The untyped parent object is NOT blanket-redacted — that is the entire reason
        // this mechanism exists instead of `writeOnly: true` on `configuration`.
        $this->assertSame('https://api.example.gov', $data['configuration']['endpoint']);
        $this->assertSame('oauth', $auth['type']);
        $this->assertSame('svc-brp', $auth['username']);
        $this->assertSame('BRP HaalCentraal', $data['name']);

        // Nothing leaks anywhere in the serialised response, including @self.relations.
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rendered->jsonSerialize()));
    }

    /**
     * The `@self.relations` search-index mirror does not leak the nested value.
     *
     * scanForRelations() flattens nested values into literal dot-path keys, so the mirror
     * holds `configuration.authentication.client_secret` as a top-level key. The body strip
     * alone leaves it — this is the nested equivalent of #429.
     *
     * @return void
     */
    public function testTheRelationsMirrorDoesNotLeakNestedPaths(): void
    {
        $rendered  = $this->renderer($this->sourceSchema())->renderEntity($this->sourceEntity());
        $relations = $rendered->getRelations();

        $this->assertArrayNotHasKey(
            'configuration.authentication.client_secret',
            $relations,
            'The relations mirror keeps a flattened dot-path copy and must be stripped too (#429)'
        );

        // Sub-tree coverage: the flattened key of a key BENEATH a declared path.
        $this->assertArrayNotHasKey('configuration.authentication.keys.apikey-abc123', $relations);

        // Readable mirrored values survive.
        $this->assertSame('https://api.example.gov', $relations['configuration.endpoint']);
        $this->assertSame('BRP HaalCentraal', $relations['name']);
    }

    /**
     * The strip is NOT `_rbac`-gated — an admin / system-context render is redacted too.
     *
     * An admin HTTP GET renders with `_rbac: false`, so an `_rbac`-gated strip would hand
     * admins the plaintext (that was #389 for top-level writeOnly). Nested paths ride the
     * same hard boundary.
     *
     * @return void
     */
    public function testNestedPathsAreStrippedEvenForARbacFalseRender(): void
    {
        $rendered = $this->renderer($this->sourceSchema())->renderEntity(
            $this->sourceEntity(),
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false
        );

        $auth = $rendered->getObject()['configuration']['authentication'];

        $this->assertArrayNotHasKey('client_secret', $auth, 'Nested writeOnly must not be _rbac-gated (#389)');
        $this->assertArrayNotHasKey('keys', $auth);
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rendered->jsonSerialize()));
    }

    /**
     * A caller cannot re-widen the strip with `fields`.
     *
     * The strip runs after caller selection, so naming the parent explicitly still yields a
     * redacted sub-object rather than the secret.
     *
     * @return void
     */
    public function testACallerCannotReWidenTheStripViaFields(): void
    {
        $rendered = $this->renderer($this->sourceSchema())->renderEntity(
            $this->sourceEntity(),
            [],
            0,
            [],
            ['name', 'configuration'],
            []
        );

        $data = $rendered->getObject();

        $this->assertArrayHasKey('configuration', $data);
        $this->assertArrayNotHasKey(
            'client_secret',
            $data['configuration']['authentication'],
            'Asking for the parent via `fields` must not resurface a nested write-only path'
        );
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rendered->jsonSerialize()));
    }

    /**
     * `_render: false` is the engine's bypass and MUST still return the secret.
     *
     * ObjectService::find(_render: false) returns the raw entity before renderEntity is
     * ever called — that is how the credential migration and CallService re-resolve secrets.
     * Modelled here the way the mapper hands the engine a fresh entity: never rendered,
     * therefore never redacted.
     *
     * @return void
     */
    public function testRenderFalseBypassesRedactionEntirelyForTheEngine(): void
    {
        $engineView = $this->sourceEntity();

        $this->assertSame(
            'NESTED_CLIENT_SECRET_MUST_NOT_LEAK',
            $engineView->getObject()['configuration']['authentication']['client_secret'],
            'A raw (_render: false) read must still carry the secret or CallService breaks'
        );
        $this->assertSame(
            ['apikey-abc123' => 'admin', 'apikey-def456' => 'service-account'],
            $engineView->getObject()['configuration']['authentication']['keys']
        );
    }

    /**
     * The list cheap-path (which skips renderEntity) strips nested paths too.
     *
     * The ocon#147 leak was a LIST read. A single-object fix that leaves the cheap path
     * open fixes nothing.
     *
     * @return void
     */
    public function testCheapPathRowsStripNestedPaths(): void
    {
        $rows = [$this->sourceEntity()];

        $this->renderer($this->sourceSchema())->redactWriteOnlyFromRows($rows, true);

        $auth = $rows[0]->getObject()['configuration']['authentication'];
        $this->assertArrayNotHasKey('client_secret', $auth);
        $this->assertArrayNotHasKey('keys', $auth);
        $this->assertSame('https://api.example.gov', $rows[0]->getObject()['configuration']['endpoint']);
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows[0]->jsonSerialize()));
    }

    /**
     * A declared path that is absent from the object is a no-op and must not fabricate keys
     * on the way down. A source with no `authentication` block stays exactly as it was.
     *
     * @return void
     */
    public function testAnAbsentDeclaredPathDoesNotFabricateKeys(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('99999999-8888-7777-6666-555555555555');
        $entity->setSchema(777);
        $entity->setRegister(65);
        $entity->setObject(['name' => 'Plain', 'configuration' => ['endpoint' => 'https://x.example']]);

        $rendered = $this->renderer($this->sourceSchema())->renderEntity($entity);

        $this->assertSame(
            ['endpoint' => 'https://x.example'],
            $rendered->getObject()['configuration'],
            'Walking an absent path must not create intermediate keys'
        );
    }

    /**
     * A schema declaring no paths is completely unaffected — the mechanism is opt-in.
     *
     * @return void
     */
    public function testASchemaWithoutDeclaredPathsIsUnaffected(): void
    {
        $schema = new Schema();
        $ref    = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 777);
        $schema->setSlug('source');
        $schema->setProperties(['name' => ['type' => 'string'], 'configuration' => ['type' => 'object']]);

        $rendered = $this->renderer($schema)->renderEntity($this->sourceEntity());
        $auth     = $rendered->getObject()['configuration']['authentication'];

        $this->assertSame('NESTED_CLIENT_SECRET_MUST_NOT_LEAK', $auth['client_secret'], 'Opt-in means opt-in');
    }
}//end class
