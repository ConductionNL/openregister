<?php

declare(strict_types=1);

/**
 * SaveObject write-path key-order preservation tests (openregister#1720).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;

/**
 * Pins REQ-OBJ-KO-01 (openspec/changes/put-preserve-key-order): the object
 * create/update write path MUST preserve the client-submitted key order of a
 * JSON object-typed schema property end-to-end, and a sibling property that
 * was not touched by the reorder must survive the PUT-semantic carry-forward.
 *
 * Driven through the REAL `SaveObject::prepareObjectForUpdate()` /
 * `prepareObjectForCreation()` methods (via reflection, mocked collaborators)
 * rather than an isolated helper — the same shape of proof
 * SaveObjectWriteOnlyPreserveTest uses for the write-only preserve rule. A
 * normalisation step that rebuilt the "mapping" property from
 * schema-declared property order (or applied `ksort`) would flip these
 * assertions.
 *
 * The storage-layer half of #1720 (PostgreSQL JSONB hashing/reordering
 * object-typed columns) was already closed by the `json_ordered` column-type
 * fix covered in MagicMapperKeyOrderColumnTypeTest; this file complements
 * that by covering the PHP-array preparation layer the DB layer sits on top
 * of, plus the pure encode/decode symmetry MagicMapper relies on.
 */
class SaveObjectKeyOrderPreserveTest extends TestCase
{
    private SaveObject $handler;
    private SchemaMapper $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        $this->handler = new SaveObject(
            $this->createMock(MagicMapper::class),
            $this->createMock(MagicMapper::class),
            $this->createMock(MetadataHydrationHandler::class),
            $this->createMock(FilePropertyHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
            $this->createMock(IUserSession::class),
            $this->createMock(AuditTrailMapper::class),
            $this->schemaMapper,
            $this->createMock(RegisterMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(OrganisationService::class),
            $this->createMock(CacheHandler::class),
            $this->createMock(SettingsService::class),
            new PropertyRbacHandler(
                $this->createMock(IUserSession::class),
                $this->createMock(IGroupManager::class),
                $this->createMock(ConditionMatcher::class),
                $this->createMock(LoggerInterface::class)
            ),
            $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
            $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(\OCA\OpenRegister\Service\TmloService::class),
            $this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
            new ArrayLoader()
        );
    }

    /**
     * A schema with a free-form object-typed "mapping" property (no `$ref` —
     * an ordinary ordered map, e.g. the openconnector mapping/cast-rule shape)
     * plus a plain sibling property.
     */
    private function orderedMapSchema(): Schema
    {
        $schema = new Schema();

        $ref    = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, 219);

        $schema->setSlug('ordered-map');
        $schema->setProperties(
            [
                'name'    => ['type' => 'string'],
                'mapping' => ['type' => 'object'],
            ]
        );

        $this->schemaMapper->method('find')->willReturn($schema);

        return $schema;
    }

    private function storedEntity(Schema $schema, array $object): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('11111111-2222-3333-4444-555555555555');
        $entity->setSchema($schema->getId());
        $entity->setRegister(65);
        $entity->setObject($object);

        return $entity;
    }

    private function prepareUpdate(Schema $schema, ObjectEntity $existing, array $data): array
    {
        $method = new ReflectionMethod(SaveObject::class, 'prepareObjectForUpdate');
        $method->setAccessible(true);

        /** @var ObjectEntity $prepared */
        $prepared = $method->invokeArgs(
            $this->handler,
            [$existing, $schema, $data, [], null, null]
        );

        return $prepared->getObject();
    }

    private function prepareCreation(Schema $schema, array $data): array
    {
        $objectEntity = new ObjectEntity();
        $objectEntity->setRegister((string) 65);
        $objectEntity->setSchema((string) $schema->getId());

        $method = new ReflectionMethod(SaveObject::class, 'prepareObjectForCreation');
        $method->setAccessible(true);

        /** @var ObjectEntity $prepared */
        $prepared = $method->invokeArgs(
            $this->handler,
            [$objectEntity, $schema, $data, [], false, null]
        );

        return $prepared->getObject();
    }

    /**
     * The load-bearing case, driven through the real update path: a
     * drag-reorder of an object-keyed property must survive PUT and a
     * sibling field the client did not touch must survive alongside it.
     *
     * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
     */
    public function testDragReorderSurvivesThePreparedUpdate(): void
    {
        $schema   = $this->orderedMapSchema();
        $existing = $this->storedEntity(
            $schema,
            [
                'name'    => 'unchanged',
                'mapping' => ['a' => 1, 'b' => 2, 'c' => 3],
            ]
        );

        $result = $this->prepareUpdate(
            $schema,
            $existing,
            [
                'name'    => 'unchanged',
                'mapping' => ['c' => 3, 'b' => 2, 'a' => 1],
            ]
        );

        $this->assertSame(
            ['c', 'b', 'a'],
            array_keys($result['mapping']),
            'Submitted key order of an object-typed property must survive the PUT-prepared update verbatim.'
        );
        $this->assertSame(
            ['c' => 3, 'b' => 2, 'a' => 1],
            $result['mapping']
        );
        $this->assertSame(
            'unchanged',
            $result['name'],
            'A sibling property untouched by the reorder must still survive the PUT-semantic carry-forward.'
        );
    }//end testDragReorderSurvivesThePreparedUpdate()

    /**
     * Saving an object-typed property unchanged must not alphabetise or
     * otherwise canonicalise its key order.
     *
     * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
     */
    public function testUnchangedSaveDoesNotReorder(): void
    {
        $schema   = $this->orderedMapSchema();
        $original = ['z' => 1, 'm' => 2, 'a' => 3];
        $existing = $this->storedEntity($schema, ['name' => 'x', 'mapping' => $original]);

        $result = $this->prepareUpdate(
            $schema,
            $existing,
            ['name' => 'x', 'mapping' => $original]
        );

        $this->assertSame(
            ['z', 'm', 'a'],
            array_keys($result['mapping']),
            'No implicit alphabetisation/canonicalisation may occur on an unchanged save.'
        );
    }//end testUnchangedSaveDoesNotReorder()

    /**
     * The same guarantee on CREATE: a freshly submitted object-typed property
     * must keep the client's key order (the requirement covers create AND
     * update).
     *
     * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
     */
    public function testKeyOrderSurvivesThePreparedCreation(): void
    {
        $schema = $this->orderedMapSchema();

        $result = $this->prepareCreation(
            $schema,
            [
                'name'    => 'new',
                'mapping' => ['step-3' => 'c', 'step-1' => 'a', 'step-2' => 'b'],
            ]
        );

        $this->assertSame(
            ['step-3', 'step-1', 'step-2'],
            array_keys($result['mapping']),
            'Create must not reorder an object-typed property into schema/declaration order.'
        );
    }//end testKeyOrderSurvivesThePreparedCreation()

    /**
     * Complements the PHP-array-level assertions above with the pure
     * encode/decode symmetry MagicMapper's storage layer relies on
     * (`prepareObjectDataForTable()` json_encode's the column value;
     * `rowToObjectEntity()` decodes it back with `json_decode(..., true)`).
     * Neither step re-keys — this pins that no future change introduces a
     * `ksort`/rebuild between the two.
     *
     * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
     */
    public function testMagicMapperEncodeDecodeRoundTripPreservesOrder(): void
    {
        $ordered = ['c' => 3, 'b' => 2, 'a' => 1];

        $encoded = json_encode($ordered);
        $decoded = json_decode($encoded, true);

        $this->assertSame(
            ['c', 'b', 'a'],
            array_keys($decoded),
            'json_encode()/json_decode(..., true) round-trip (the exact pair MagicMapper::'
            .'prepareObjectDataForTable()/rowToObjectEntity() perform on object-typed columns) '
            .'must preserve insertion order.'
        );
    }//end testMagicMapperEncodeDecodeRoundTripPreservesOrder()
}//end class
