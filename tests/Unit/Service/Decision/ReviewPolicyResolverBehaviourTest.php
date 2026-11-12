<?php

/**
 * Behaviour tests for ReviewPolicyResolver: which review policy governs a relation, failing closed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Decision
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Decision;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Decision\ReviewPolicyFileOwners;
use OCA\OpenRegister\Service\Decision\ReviewPolicyResolver;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Decision\ReviewPolicyResolver
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicyFileOwners
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Db\EntityRelation
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ReviewPolicyResolverBehaviourTest extends TestCase
{
    private const CLOSED = ['ownership' => 'decider', 'supervisors' => [], 'hideOthers' => true];

    /** @var SchemaMapperInterface&MockObject */
    private SchemaMapperInterface $schemas;

    /** @var FileSettingsHandler&MockObject */
    private FileSettingsHandler $settings;

    /** @var IRootFolder&MockObject */
    private IRootFolder $root;

    /** @var ManagedFileOwnerLookup&MockObject */
    private ManagedFileOwnerLookup $owners;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->schemas  = $this->createMock(SchemaMapperInterface::class);
        $this->settings = $this->createMock(FileSettingsHandler::class);
        $this->root     = $this->createMock(IRootFolder::class);
        $this->owners   = $this->createMock(ManagedFileOwnerLookup::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
    }//end setUp()

    private function resolve(EntityRelation $relation): array
    {
        $resolved = (new ReviewPolicyResolver(
            $this->schemas,
            $this->settings,
            new ReviewPolicyFileOwners($this->root, $this->owners),
            $this->logger
        ))->forRelation($relation);

        return ['policy' => $resolved['policy']->toArray(), 'subject' => $resolved['subject']];
    }//end resolve()

    private function relation(?string $schemaId, ?int $fileId): EntityRelation
    {
        $relation = new EntityRelation();
        $relation->setId(12);
        $relation->setSchemaId($schemaId);
        $relation->setRegisterId('r-1');
        $relation->setFileId($fileId);
        $relation->setObjectId(4);
        $relation->setObjectUuid('obj-own');
        return $relation;
    }//end relation()

    private function schemaWith(?array $configuration): Schema
    {
        // A double: the real setter runs the configuration validator, which is not under test here.
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn($configuration);
        return $schema;
    }//end schemaWith()

    public function testTheSchemaDeclarationGovernsAndTheSubjectNamesTheRelation(): void
    {
        $this->schemas->expects($this->once())
            ->method('find')
            ->with('s-1', $this->anything(), false, false)
            ->willReturn($this->schemaWith(['x-openregister-review' => ['ownership' => 'decider', 'supervisors' => [' legal ', 'legal', ''], 'hideOthers' => true]]));
        $this->settings->expects($this->never())->method('getFileSettingsOnly');
        $this->root->expects($this->never())->method('getFirstNodeById');

        $resolved = $this->resolve($this->relation('s-1', 99));

        $this->assertSame(['ownership' => 'decider', 'supervisors' => ['legal'], 'hideOthers' => true], $resolved['policy']);
        $this->assertSame(
            ['subjectType' => 'entity-relation', 'subjectId' => '12', 'fileId' => 99, 'objectId' => 4, 'register' => 'r-1', 'schema' => 's-1', 'objectUuid' => 'obj-own'],
            $resolved['subject']
        );
    }//end testTheSchemaDeclarationGovernsAndTheSubjectNamesTheRelation()

    public function testASchemaWithoutADeclarationFallsBackToTheInstanceDefault(): void
    {
        $this->schemas->method('find')->willReturn($this->schemaWith(['other' => true]));
        $this->settings->method('getFileSettingsOnly')->willReturn(['anonymisationReview' => ['ownership' => 'decider']]);

        $this->assertSame(
            ['ownership' => 'decider', 'supervisors' => [], 'hideOthers' => false],
            $this->resolve($this->relation('s-1', null))['policy']
        );
    }//end testASchemaWithoutADeclarationFallsBackToTheInstanceDefault()

    public function testASchemaWithNullConfigurationAndNoInstanceSettingIsOpen(): void
    {
        $this->schemas->method('find')->willReturn($this->schemaWith(null));
        $this->settings->method('getFileSettingsOnly')->willReturn([]);

        $this->assertSame(
            ['ownership' => 'open', 'supervisors' => [], 'hideOthers' => false],
            $this->resolve($this->relation('s-1', null))['policy']
        );
    }//end testASchemaWithNullConfigurationAndNoInstanceSettingIsOpen()

    public function testAnUnreadableSchemaFailsClosedAndIsLogged(): void
    {
        $this->schemas->method('find')->willThrowException(new RuntimeException('gone'));
        $this->settings->expects($this->never())->method('getFileSettingsOnly');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('could not be read'), ['schema' => 's-1', 'error' => 'gone']);

        $this->assertSame(self::CLOSED, $this->resolve($this->relation('s-1', null))['policy']);
    }//end testAnUnreadableSchemaFailsClosedAndIsLogged()

    public function testNoSchemaAndNoFileUsesTheInstanceDefaultWithoutAnyLookup(): void
    {
        $this->root->expects($this->never())->method('getFirstNodeById');
        $this->schemas->expects($this->never())->method('find');
        $this->settings->method('getFileSettingsOnly')->willReturn(['anonymisationReview' => ['hideOthers' => true]]);

        $resolved = $this->resolve($this->relation(null, 0));

        $this->assertSame(['ownership' => 'open', 'supervisors' => [], 'hideOthers' => true], $resolved['policy']);
        $this->assertNull($resolved['subject']['schema']);
    }//end testNoSchemaAndNoFileUsesTheInstanceDefaultWithoutAnyLookup()

    public function testAFileTakesTheRegisterSchemaAndUuidOfItsOwningObject(): void
    {
        $node = $this->createMock(File::class);
        $this->root->method('getFirstNodeById')->with(55)->willReturn($node);
        $object = new ObjectEntity();
        $object->setRegister('r-9');
        $object->setSchema('s-9');
        $object->setUuid('obj-9');
        $this->owners->method('find')->with($node)->willReturn($object);
        $this->schemas->method('find')->with('s-9')
            ->willReturn($this->schemaWith(['x-openregister-review' => ['hideOthers' => true]]));

        $resolved = $this->resolve($this->relation(null, 55));

        $this->assertSame(['ownership' => 'open', 'supervisors' => [], 'hideOthers' => true], $resolved['policy']);
        $this->assertSame(
            ['register' => 'r-9', 'schema' => 's-9', 'objectUuid' => 'obj-9'],
            array_intersect_key($resolved['subject'], ['register' => 1, 'schema' => 1, 'objectUuid' => 1])
        );
        $this->assertArrayNotHasKey('lookupFailed', $resolved['subject']);
    }//end testAFileTakesTheRegisterSchemaAndUuidOfItsOwningObject()

    public function testAFileWithoutAnOwningObjectUsesTheInstanceDefault(): void
    {
        $this->root->method('getFirstNodeById')->willReturn($this->createMock(File::class));
        $this->owners->method('find')->willReturn(null);
        $this->schemas->expects($this->never())->method('find');
        $this->settings->method('getFileSettingsOnly')->willReturn([]);

        $resolved = $this->resolve($this->relation('', 55));

        $this->assertSame(['ownership' => 'open', 'supervisors' => [], 'hideOthers' => false], $resolved['policy']);
        $this->assertSame('obj-own', $resolved['subject']['objectUuid']);
    }//end testAFileWithoutAnOwningObjectUsesTheInstanceDefault()

    public function testAFileThatCannotBeFoundFailsClosedWithoutTheLookupFlag(): void
    {
        $this->root->method('getFirstNodeById')->willReturn(null);
        $this->owners->expects($this->never())->method('find');
        $this->settings->expects($this->never())->method('getFileSettingsOnly');

        $resolved = $this->resolve($this->relation(null, 55));

        $this->assertSame(self::CLOSED, $resolved['policy']);
        $this->assertArrayNotHasKey('lookupFailed', $resolved['subject']);
    }//end testAFileThatCannotBeFoundFailsClosedWithoutTheLookupFlag()

    public function testAThrowingOwnerLookupFailsClosedAndIsLogged(): void
    {
        $this->root->method('getFirstNodeById')->willReturn($this->createMock(File::class));
        $this->owners->method('find')->willThrowException(new RuntimeException('db down'));
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('could not be found'), ['fileId' => 55, 'error' => 'db down']);

        $this->assertSame(self::CLOSED, $this->resolve($this->relation(null, 55))['policy']);
    }//end testAThrowingOwnerLookupFailsClosedAndIsLogged()
}//end class
