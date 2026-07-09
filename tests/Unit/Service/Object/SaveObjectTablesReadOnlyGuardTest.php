<?php

/**
 * OpenRegister - SaveObject read-only-projection guard test (tables provider)
 *
 * Pins the tables-object-source-provider contract that a schema bound to the
 * `tables` object-source provider is a read-only projection: calling
 * `SaveObject::saveObject()` for such a schema MUST throw the
 * read-only-projection RuntimeException before anything is persisted (Nextcloud
 * Tables stays authoritative — OpenRegister never becomes a second write path).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/tables-object-source-provider/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Twig\Loader\ArrayLoader;

/**
 * Unit test for the read-only-projection write-guard on a tables-bound schema.
 */
class SaveObjectTablesReadOnlyGuardTest extends TestCase
{

    /**
     * Build a SaveObject with all collaborators mocked (the guard fires before
     * any of them are used for persistence).
     *
     * @return SaveObject The handler under test.
     */
    private function handler(): SaveObject
    {
        return new SaveObject(
            $this->createMock(MagicMapper::class),
            $this->createMock(MagicMapper::class),
            $this->createMock(MetadataHydrationHandler::class),
            $this->createMock(FilePropertyHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class),
            $this->createMock(IUserSession::class),
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(OrganisationService::class),
            $this->createMock(CacheHandler::class),
            $this->createMock(SettingsService::class),
            $this->createMock(PropertyRbacHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
            $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(\OCA\OpenRegister\Service\TmloService::class),
            $this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
            new ArrayLoader()
        );
    }//end handler()

    /**
     * Saving an object into a tables-bound schema throws the read-only-
     * projection error before any persistence.
     *
     * @return void
     */
    public function testSaveIntoTablesBoundSchemaIsRejected(): void
    {
        $schema = new Schema();
        $schema->setId(300);
        $schema->setSlug('nc-inspecties-t7');
        $schema->setConfiguration(
            [
                'x-openregister-object-source' => [
                    'provider' => 'tables',
                    'readOnly' => true,
                    'config'   => ['tableId' => 7, 'managed' => true],
                ],
            ]
        );

        $register = new Register();
        $register->setId(30);
        $register->setSlug('tables');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/read-only projection.*tables/');

        $this->handler()->saveObject(
            data: ['name' => 'should never persist'],
            schema: $schema,
            register: $register
        );
    }//end testSaveIntoTablesBoundSchemaIsRejected()
}//end class
