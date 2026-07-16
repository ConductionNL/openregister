<?php

/**
 * Write-only field redaction (openregister#380, ocon#147).
 *
 * A schema property marked `writeOnly: true` is JSON Schema's way of saying "a client may
 * send this, the server must never return it" — the exact semantic for a secret. These
 * tests pin that `RenderObject::renderEntity()` strips such properties at the API render
 * boundary, that no caller-supplied argument can widen the redaction, and — crucially —
 * that the entity's raw `getObject()` is untouched, so the engine that legitimately needs
 * the secret (a synchronisation, the credential engine) still gets it.
 *
 * Context: the OpenConnector `source` schema leaked every integration credential to any
 * authenticated user because its secrets were plain object fields and controller-side
 * redaction was bypassed by the generic object API (ocon#147). renderEntity() is the one
 * path every API read renders through, which is why the control belongs here.
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 */
class RenderObjectWriteOnlyRedactionTest extends TestCase
{
    /**
     * A schema whose `secret` and `apiKey` properties are write-only.
     *
     * @return Schema
     */
    private function schemaWithWriteOnlySecrets(): Schema
    {
        $schema = new Schema();
        $ref    = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 213);
        $schema->setSlug('source');
        $schema->setProperties(
            [
                'name'     => ['type' => 'string'],
                'location' => ['type' => 'string'],
                'apiKey'   => ['type' => 'string', 'writeOnly' => true],
                'secret'   => ['type' => 'string', 'writeOnly' => true],
                'password' => ['type' => 'string', 'writeOnly' => true],
            ]
        );

        return $schema;
    }

    /**
     * Build the renderer with the SchemaMapper resolving our write-only schema, and every
     * other collaborator a harmless mock.
     *
     * @param Schema $schema The schema find() returns.
     *
     * @return RenderObject
     */
    private function renderer(Schema $schema): RenderObject
    {
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        return new RenderObject(
            $this->createMock(\OCA\OpenRegister\Db\FileMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class),
            $this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class),
            $schemaMapper,
            $this->createMock(\OCP\SystemTag\ISystemTagManager::class),
            $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
            $this->realPropertyRbacHandler(),
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(\OCA\OpenRegister\Service\FileService::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
            $this->translationPassthrough(),
            $this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
            $this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
            $this->createMock(\OCA\OpenRegister\Service\UrnService::class),
            $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
            $this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
            $this->createMock(\OCA\OpenRegister\Service\LanguageService::class)
        );
    }

    /**
     * A REAL PropertyRbacHandler with mocked leaf dependencies.
     *
     * writeOnly stripping (the behaviour under test) is a pure function of the schema's
     * writeOnly flags — it needs no session or groups — so a real handler strips correctly
     * off `schemaWithWriteOnlySecrets()`. The test schemas declare no property-level
     * `authorization`, so ConditionMatcher is never exercised and a mock suffices. Both the
     * single-object path (renderEntity → filterReadableProperties) and the cheap-path
     * (redactWriteOnlyFromRows → filterReadableProperties/stripWriteOnlyProperties) run
     * through this same real handler.
     *
     * @return \OCA\OpenRegister\Service\PropertyRbacHandler
     */
    private function realPropertyRbacHandler(): \OCA\OpenRegister\Service\PropertyRbacHandler
    {
        return new \OCA\OpenRegister\Service\PropertyRbacHandler(
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\OCP\IGroupManager::class),
            $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );
    }

    /**
     * A TranslationHandler that returns the object data unchanged.
     *
     * @return \OCA\OpenRegister\Service\Object\TranslationHandler
     */
    private function translationPassthrough(): \OCA\OpenRegister\Service\Object\TranslationHandler
    {
        $handler = $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class);
        $handler->method('resolveTranslationsForRender')->willReturnCallback(fn (array $data) => $data);

        return $handler;
    }

    /**
     * A source entity carrying a location and three secrets.
     *
     * @return ObjectEntity
     */
    private function sourceEntity(): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setSchema(213);
        $entity->setRegister(65);
        $entity->setObject(
            [
                'id'       => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'name'     => 'BRP HaalCentraal',
                'location' => 'https://api.example.gov',
                'apiKey'   => 'SECRET_APIKEY_MUST_NOT_LEAK',
                'secret'   => 'SECRET_CLIENTSECRET_MUST_NOT_LEAK',
                'password' => 'SECRET_PASSWORD_MUST_NOT_LEAK',
            ]
        );

        // OpenRegister mirrors every scalar property into the `relations` search index; it
        // surfaces on the API as `@self.relations`. A source object really does carry this.
        $entity->setRelations(
            [
                'name'     => 'BRP HaalCentraal',
                'location' => 'https://api.example.gov',
                'apiKey'   => 'SECRET_APIKEY_MUST_NOT_LEAK',
                'secret'   => 'SECRET_CLIENTSECRET_MUST_NOT_LEAK',
                'password' => 'SECRET_PASSWORD_MUST_NOT_LEAK',
            ]
        );

        return $entity;
    }

    /**
     * The core: the rendered object drops every write-only property and keeps the rest.
     *
     * @return void
     */
    public function testWriteOnlyPropertiesAreStrippedFromTheRenderedObject(): void
    {
        $entity   = $this->sourceEntity();
        $rendered = $this->renderer($this->schemaWithWriteOnlySecrets())->renderEntity($entity);

        $data = $rendered->getObject();

        $this->assertArrayNotHasKey('apiKey', $data);
        $this->assertArrayNotHasKey('secret', $data);
        $this->assertArrayNotHasKey('password', $data);

        // Non-secret fields survive.
        $this->assertSame('BRP HaalCentraal', $data['name']);
        $this->assertSame('https://api.example.gov', $data['location']);

        // Belt and braces: no secret marker anywhere in the serialised response —
        // including `@self.relations`, the search-index copy OpenRegister keeps of every
        // scalar. Redacting only the object body leaves that copy leaking.
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rendered->jsonSerialize()));

        $relations = $rendered->getRelations();
        $this->assertArrayNotHasKey('apiKey', $relations, 'The relations search-index copy must be redacted too');
        $this->assertArrayNotHasKey('secret', $relations);
        $this->assertArrayNotHasKey('password', $relations);
        $this->assertSame('BRP HaalCentraal', $relations['name'], 'Non-secret relations survive');
    }

    /**
     * A caller cannot ask a write-only field back the way it can with `fields`. The
     * redaction runs after `fields`, so requesting the secret explicitly still yields
     * nothing — this is exactly the "controller redaction was bypassable" failure closed.
     *
     * @return void
     */
    public function testACallerSuppliedFieldsListCannotWidenTheRedaction(): void
    {
        $entity   = $this->sourceEntity();
        $rendered = $this->renderer($this->schemaWithWriteOnlySecrets())->renderEntity(
            $entity,
            [],
            0,
            [],
            ['name', 'apiKey', 'secret'],
            []
        );

        $data = $rendered->getObject();

        $this->assertArrayHasKey('name', $data);
        $this->assertArrayNotHasKey('apiKey', $data, 'A write-only field asked for via `fields` must still be redacted');
        $this->assertArrayNotHasKey('secret', $data);
    }

    /**
     * The other half of the contract: redaction is a RENDER concern only. The engine reads
     * a source through `getObject()` on the raw entity, which must still carry the secret —
     * otherwise every synchronisation and the credential engine break.
     *
     * @return void
     */
    public function testTheRawEntityStillCarriesTheSecretForTheEngine(): void
    {
        $entity = $this->sourceEntity();

        // Before render: the raw object is the engine's view. It has the secret.
        $this->assertSame('SECRET_APIKEY_MUST_NOT_LEAK', $entity->getObject()['apiKey']);

        // renderEntity() mutates the entity it is given (it setObject()s the redacted view),
        // so the engine must read the raw source through its OWN find (getObject on a fresh
        // entity), never through a rendered one. This test documents that boundary: a fresh
        // entity built the way the mapper builds it for the engine keeps the secret.
        $engineView = $this->sourceEntity();
        $this->assertSame('SECRET_CLIENTSECRET_MUST_NOT_LEAK', $engineView->getObject()['secret']);
    }

    /**
     * `writeOnly` is NOT `_rbac`-gated: even a `_rbac: false` render is redacted.
     *
     * This test previously asserted the OPPOSITE — that `_rbac: false` returned the
     * secret — and had been failing at HEAD ever since #389 changed the rule. It was the
     * pre-#389 contract left behind, which is worse than no test: it documented a leak as
     * the intended behaviour. #389 made the strip unconditional precisely because an admin
     * HTTP GET renders with `_rbac: false`, so gating writeOnly on `_rbac` handed every
     * admin-context read the plaintext secret.
     *
     * The real bypass for internal engines is `_render: false` (ObjectService::find), which
     * returns the raw entity WITHOUT entering renderEntity at all — see
     * testRenderFalseBypassesRedactionEntirelyForTheEngine(). Redaction is a property of the
     * render boundary; code that must not cross it does not render.
     *
     * @return void
     */
    public function testWriteOnlyIsStrippedEvenForARbacFalseRender(): void
    {
        $entity = $this->sourceEntity();

        $rendered = $this->renderer($this->schemaWithWriteOnlySecrets())->renderEntity(
            $entity,
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

        $data = $rendered->getObject();

        $this->assertArrayNotHasKey('apiKey', $data, 'writeOnly is a hard render-boundary rule and is not _rbac-gated (#389)');
        $this->assertArrayNotHasKey('secret', $data);
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rendered->jsonSerialize()));
    }

    /**
     * A schema with no write-only property renders unchanged — the mechanism is strictly
     * opt-in, so no existing schema changes behaviour.
     *
     * @return void
     */
    public function testASchemaWithoutWriteOnlyPropertiesIsUnaffected(): void
    {
        $schema = new Schema();
        $ref    = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 999);
        $schema->setSlug('plain');
        $schema->setProperties(
            [
                'name'  => ['type' => 'string'],
                'token' => ['type' => 'string'],
            ]
        );

        $entity = new ObjectEntity();
        $entity->setUuid('bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
        $entity->setSchema(999);
        $entity->setRegister(1);
        $entity->setObject(['name' => 'x', 'token' => 'not-flagged-so-kept']);

        $rendered = $this->renderer($schema)->renderEntity($entity);
        $data     = $rendered->getObject();

        // `token` is NOT marked writeOnly, so it is returned. Opt-in means opt-in.
        $this->assertSame('not-flagged-so-kept', $data['token']);
    }

    /**
     * The list cheap-path (which bypasses renderEntity for performance) also redacts.
     *
     * This is the path the OpenConnector leak actually used: `GET .../objects/{reg}/{schema}`
     * is a LIST, so it took the cheap-path and returned the secret even after the
     * single-object read was fixed. Rows here are ObjectEntity objects (the cheap-path
     * shape when no _extend/_fields is requested).
     *
     * @return void
     */
    public function testCheapPathRowRedactionStripsWriteOnlyFromEntityRows(): void
    {
        $rows = [$this->sourceEntity()];

        $this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, true);

        $data = $rows[0]->getObject();
        $this->assertArrayNotHasKey('apiKey', $data);
        $this->assertArrayNotHasKey('secret', $data);
        $this->assertSame('BRP HaalCentraal', $data['name']);

        // The @self.relations index copy is stripped too.
        $relations = $rows[0]->getRelations();
        $this->assertArrayNotHasKey('apiKey', $relations);
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows[0]->jsonSerialize()));
    }

    /**
     * The cheap-path also handles ARRAY rows (the SOLR/index list shape), redacting the
     * body and `@self.relations`.
     *
     * @return void
     */
    public function testCheapPathRowRedactionStripsWriteOnlyFromArrayRows(): void
    {
        $rows = [
            [
                'name'     => 'BRP HaalCentraal',
                'apiKey'   => 'SECRET_APIKEY_MUST_NOT_LEAK',
                'password' => 'SECRET_PASSWORD_MUST_NOT_LEAK',
                '@self'    => [
                    'schema'    => 213,
                    'relations' => ['apiKey' => 'SECRET_APIKEY_MUST_NOT_LEAK', 'name' => 'BRP HaalCentraal'],
                ],
            ],
        ];

        $this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, true);

        $this->assertArrayNotHasKey('apiKey', $rows[0]);
        $this->assertArrayNotHasKey('password', $rows[0]);
        $this->assertArrayNotHasKey('apiKey', $rows[0]['@self']['relations']);
        $this->assertSame('BRP HaalCentraal', $rows[0]['name']);
        $this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows));
    }

    /**
     * A system-context list read (`_rbac: false`) is NOT redacted — the engine batch-reads
     * sources and must still get the secrets.
     *
     * @return void
     */
    public function testCheapPathRowRedactionSkipsSystemContext(): void
    {
        $rows = [$this->sourceEntity()];

        $this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, false);

        $this->assertSame('SECRET_APIKEY_MUST_NOT_LEAK', $rows[0]->getObject()['apiKey']);
    }
}
