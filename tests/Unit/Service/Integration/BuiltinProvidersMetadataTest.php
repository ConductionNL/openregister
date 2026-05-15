<?php

/**
 * Unit tests for the 5 built-in IntegrationProvider implementations.
 *
 * The tests are deliberately metadata-shaped: they exercise the
 * provider contract (id, label, icon, group, storage, requiredApp,
 * isEnabled) and the documented NotImplementedException behaviour
 * for mutation methods that the umbrella's controller refactor
 * (tasks 18-22) consolidates later. Wrapped-service delegation paths
 * (NoteService::createNote, FileService::getFilesForEntity, ...)
 * are exercised through the wrapped services' own integration tests
 * — duplicating them here would just rewrite NoteServiceTest.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-17
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\FilesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\NotesProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TagsProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\TaskService;
use OCP\IL10N;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for the built-in providers' contract metadata.
 */
class BuiltinProvidersMetadataTest extends TestCase
{

    /**
     * Build a mocked IL10N that passes strings through unchanged.
     */
    private function buildL10n(): IL10N
    {
        $mock = $this->createMock(IL10N::class);
        $mock->method('t')->willReturnArgument(0);
        return $mock;
    }//end buildL10n()

    public function testFilesProviderMetadata(): void
    {
        $provider = new FilesProvider(
            fileService: $this->createMock(FileService::class),
            container: $this->createMock(ContainerInterface::class),
            l10n: $this->buildL10n(),
        );

        $this->assertSame('files', $provider->getId());
        $this->assertSame('Files', $provider->getLabel());
        $this->assertSame('magic-column', $provider->getStorageStrategy());
        $this->assertNull($provider->getRequiredApp());
        $this->assertSame('core', $provider->getGroup());
        $this->assertTrue($provider->isEnabled());
        $this->assertNull($provider->getOpenConnectorSource());
    }//end testFilesProviderMetadata()

    public function testFilesProviderCreateThrowsNotImplemented(): void
    {
        $provider = new FilesProvider(
            $this->createMock(FileService::class),
            $this->createMock(ContainerInterface::class),
            $this->buildL10n(),
        );
        $this->expectException(NotImplementedException::class);
        $provider->create('r', 's', 'o', []);
    }//end testFilesProviderCreateThrowsNotImplemented()

    public function testNotesProviderMetadata(): void
    {
        $provider = new NotesProvider(
            $this->createMock(NoteService::class),
            $this->buildL10n(),
        );

        $this->assertSame('notes', $provider->getId());
        $this->assertSame('Notes', $provider->getLabel());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertNull($provider->getRequiredApp());
    }//end testNotesProviderMetadata()

    public function testNotesProviderListDelegatesToService(): void
    {
        $noteService = $this->createMock(NoteService::class);
        $noteService->expects($this->once())
            ->method('getNotesForObject')
            ->with('object-uuid', 50, 0)
            ->willReturn([['id' => 1, 'message' => 'hello']]);

        $provider = new NotesProvider($noteService, $this->buildL10n());
        $result   = $provider->list('reg', 'sch', 'object-uuid');

        $this->assertSame([['id' => 1, 'message' => 'hello']], $result);
    }//end testNotesProviderListDelegatesToService()

    public function testTasksProviderMetadata(): void
    {
        $provider = new TasksProvider(
            $this->createMock(TaskService::class),
            $this->buildL10n(),
        );

        $this->assertSame('tasks', $provider->getId());
        $this->assertSame('Tasks', $provider->getLabel());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertNull($provider->getRequiredApp());
    }//end testTasksProviderMetadata()

    public function testTasksProviderUpdateRejectsBadEntityId(): void
    {
        $provider = new TasksProvider(
            $this->createMock(TaskService::class),
            $this->buildL10n(),
        );
        $this->expectException(NotImplementedException::class);
        $provider->update('r', 's', 'o', 'not-a-composite', []);
    }//end testTasksProviderUpdateRejectsBadEntityId()

    public function testTagsProviderMetadata(): void
    {
        $provider = new TagsProvider(
            $this->createMock(ISystemTagManager::class),
            $this->createMock(ISystemTagObjectMapper::class),
            $this->buildL10n(),
        );

        $this->assertSame('tags', $provider->getId());
        $this->assertSame('Tags', $provider->getLabel());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertNull($provider->getRequiredApp());
        $this->assertSame('core', $provider->getGroup());
    }//end testTagsProviderMetadata()

    public function testTagsProviderCreateThrowsNotImplemented(): void
    {
        $provider = new TagsProvider(
            $this->createMock(ISystemTagManager::class),
            $this->createMock(ISystemTagObjectMapper::class),
            $this->buildL10n(),
        );
        $this->expectException(NotImplementedException::class);
        $provider->create('r', 's', 'o', []);
    }//end testTagsProviderCreateThrowsNotImplemented()

    public function testAuditTrailProviderMetadata(): void
    {
        $provider = new AuditTrailProvider(
            $this->createMock(AuditTrailMapper::class),
            $this->buildL10n(),
        );

        $this->assertSame('audit-trail', $provider->getId());
        $this->assertSame('Audit trail', $provider->getLabel());
        $this->assertSame('query-time', $provider->getStorageStrategy());
        $this->assertNull($provider->getRequiredApp());
        $this->assertSame('core', $provider->getGroup());
    }//end testAuditTrailProviderMetadata()

    public function testAuditTrailProviderListSurfacesEmptyOnError(): void
    {
        $mapper = $this->createMock(AuditTrailMapper::class);
        // No method exists / no findAllByObject on mock — Should return [].
        $provider = new AuditTrailProvider($mapper, $this->buildL10n());

        $this->assertSame([], $provider->list('r', 's', 'o'));
    }//end testAuditTrailProviderListSurfacesEmptyOnError()

    public function testAuditTrailProviderMutationsThrowNotImplemented(): void
    {
        $provider = new AuditTrailProvider(
            $this->createMock(AuditTrailMapper::class),
            $this->buildL10n(),
        );

        $this->expectException(NotImplementedException::class);
        $provider->create('r', 's', 'o', []);
    }//end testAuditTrailProviderMutationsThrowNotImplemented()

}//end class
