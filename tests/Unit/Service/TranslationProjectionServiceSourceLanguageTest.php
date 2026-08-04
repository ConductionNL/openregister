<?php

/**
 * Source-language resolution tests for TranslationProjectionService.
 *
 * Covers the resolution chain:
 *   1. object body `_translationMeta.<prop>.sourceLanguage`
 *   2. schema `properties.<prop>.sourceLanguage`
 *   3. supplied register default
 *   4. hardcoded `'nl'` fallback
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verifies `TranslationProjectionService::resolveSourceLanguage`.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TranslationProjectionServiceSourceLanguageTest extends TestCase
{

    private TranslationProjectionService $service;
    private Schema $schema;
    private ObjectEntity $object;

    /**
     * Build a service instance with mocked collaborators.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TranslationProjectionService(
            $this->createMock(TranslationMapper::class),
            $this->createMock(TranslationHandler::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(IUserSession::class),
            new NullLogger()
        );

        $this->schema = new Schema();
        $this->schema->setProperties([
            'title' => [
                'type'           => 'string',
                'translatable'   => true,
                'sourceLanguage' => 'fr',
            ],
            'body'  => [
                'type'         => 'string',
                'translatable' => true,
            ],
        ]);

        $this->object = new ObjectEntity();
    }//end setUp()

    /**
     * Object body `_translationMeta.<prop>.sourceLanguage` wins over schema.
     */
    public function testObjectLevelOverrideWins(): void
    {
        $this->object->setObject([
            'title' => ['fr' => 'Bonjour', 'en' => 'Hello'],
            '_translationMeta' => [
                'title' => ['sourceLanguage' => 'en'],
            ],
        ]);

        $resolved = $this->service->resolveSourceLanguage(
            schema: $this->schema,
            object: $this->object,
            property: 'title',
            registerDefault: 'nl'
        );

        $this->assertSame('en', $resolved);
    }//end testObjectLevelOverrideWins()

    /**
     * Schema property modifier is applied when no object override is present.
     */
    public function testSchemaDefaultInheritance(): void
    {
        $this->object->setObject([
            'title' => ['fr' => 'Bonjour'],
        ]);

        $resolved = $this->service->resolveSourceLanguage(
            schema: $this->schema,
            object: $this->object,
            property: 'title',
            registerDefault: 'nl'
        );

        $this->assertSame('fr', $resolved);
    }//end testSchemaDefaultInheritance()

    /**
     * Register default is used when neither object nor schema declare a source.
     */
    public function testRegisterDefaultFallback(): void
    {
        $this->object->setObject([
            'body' => ['nl' => 'Hallo'],
        ]);

        $resolved = $this->service->resolveSourceLanguage(
            schema: $this->schema,
            object: $this->object,
            property: 'body',
            registerDefault: 'nl'
        );

        $this->assertSame('nl', $resolved);
    }//end testRegisterDefaultFallback()

    /**
     * Hardcoded `'nl'` is used when the register default is empty.
     */
    public function testHardcodedNlFallback(): void
    {
        $this->object->setObject([
            'body' => ['nl' => 'x'],
        ]);

        $resolved = $this->service->resolveSourceLanguage(
            schema: $this->schema,
            object: $this->object,
            property: 'body',
            registerDefault: ''
        );

        $this->assertSame('nl', $resolved);
    }//end testHardcodedNlFallback()
}//end class
