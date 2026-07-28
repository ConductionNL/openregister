<?php

declare(strict_types=1);

/**
 * ContentProvider unit tests (context-chat-provider).
 *
 * Proves `getId`/`getAppId` identity, `getItemUrl()` deep-link resolution
 * and its `openregister.objects.show` fallback, and that
 * `triggerInitialImport()`/`reindex()` walk every opted-in (register,
 * schema) pair in batches, delegating the per-object eligibility decision to
 * `ContextChatSubmissionListener`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\ContextChat
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\ContextChat;

use OCA\OpenRegister\ContextChat\ContentProvider;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\ContextChat\ContentProvider
 */
class ContentProviderTest extends TestCase
{
    private ContextChatSubmissionListener $submissionListener;

    private SchemaMapper $schemaMapper;

    private RegisterMapper $registerMapper;

    private MagicMapper $magicMapper;

    private DeepLinkRegistryService $deepLinkRegistry;

    private IURLGenerator $urlGenerator;

    private ContentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissionListener = $this->createMock(ContextChatSubmissionListener::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->registerMapper     = $this->createMock(RegisterMapper::class);
        $this->magicMapper        = $this->createMock(MagicMapper::class);
        $this->deepLinkRegistry   = $this->createMock(DeepLinkRegistryService::class);
        $this->urlGenerator       = $this->createMock(IURLGenerator::class);

        $this->provider = new ContentProvider(
            $this->submissionListener,
            $this->schemaMapper,
            $this->registerMapper,
            $this->magicMapper,
            $this->deepLinkRegistry,
            $this->urlGenerator,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testGetId(): void
    {
        $this->assertSame('openregister_objects', $this->provider->getId());
    }

    public function testGetAppId(): void
    {
        $this->assertSame('openregister', $this->provider->getAppId());
    }

    private function makeObject(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('obj-uuid-1');
        $object->setRegister('1');
        $object->setSchema('2');
        $object->setObject(['name' => 'Test']);

        return $object;
    }

    /**
     * 6.6: a claimed deep link wins over the generic fallback route.
     */
    public function testGetItemUrlUsesDeepLinkWhenRegistered(): void
    {
        $this->magicMapper->method('find')->willReturn($this->makeObject());
        $this->deepLinkRegistry->method('resolveUrl')->willReturn('/apps/procest/#/cases/obj-uuid-1');

        $this->urlGenerator->expects($this->never())->method('linkToRoute');

        $url = $this->provider->getItemUrl('obj-uuid-1');
        $this->assertSame('/apps/procest/#/cases/obj-uuid-1', $url);
    }

    /**
     * 6.6: with no claimed deep link, falls back to `openregister.objects.show`.
     */
    public function testGetItemUrlFallsBackToGenericRoute(): void
    {
        $this->magicMapper->method('find')->willReturn($this->makeObject());
        $this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
        $this->urlGenerator->method('linkToRoute')->willReturn('/apps/openregister/objects/1/2/obj-uuid-1');

        $url = $this->provider->getItemUrl('obj-uuid-1');
        $this->assertSame('/apps/openregister/objects/1/2/obj-uuid-1', $url);
    }

    /**
     * getItemUrl falls back gracefully when the object cannot be resolved
     * (e.g. it was hard-deleted between submission and lookup).
     */
    public function testGetItemUrlFallsBackWhenObjectNotFound(): void
    {
        $this->magicMapper->method('find')->willThrowException(new \RuntimeException('not found'));
        $this->urlGenerator->method('linkToRoute')->willReturn('/apps/openregister/objects/0/0/missing');

        $url = $this->provider->getItemUrl('missing');
        $this->assertSame('/apps/openregister/objects/0/0/missing', $url);
    }

    private function optedInSchema(int $id): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setConfiguration(['x-openregister-contextchat' => true]);

        return $schema;
    }

    private function optedOutSchema(int $id): Schema
    {
        $schema = new Schema();
        $schema->setId($id);

        return $schema;
    }

    /**
     * 6.1/task 5.1: triggerInitialImport() walks every opted-in
     * (register, schema) pair and submits each published object; opted-out
     * schemas are skipped entirely.
     */
    public function testTriggerInitialImportCoversAllOptedInSchemas(): void
    {
        $optedIn  = $this->optedInSchema(10);
        $optedOut = $this->optedOutSchema(11);
        $this->schemaMapper->method('findAll')->willReturn([$optedIn, $optedOut]);

        $this->registerMapper->method('getAllRegisterIdsWithSchema')
            ->willReturnCallback(function (int $schemaId) {
                return ($schemaId === 10) ? [100] : [];
            });

        $register = new Register();
        $register->setId(100);
        $this->registerMapper->method('find')->willReturn($register);

        $objects = [$this->makeObject(), $this->makeObject()];
        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturnOnConsecutiveCalls($objects, []);

        $this->submissionListener->expects($this->exactly(2))
            ->method('submitIfEligible')
            ->willReturn(true);

        $this->provider->triggerInitialImport();
    }

    /**
     * reindex() scoped to a single schema id only walks that schema's pairs.
     */
    public function testReindexScopedToSchemaOnlyWalksThatSchema(): void
    {
        $optedIn = $this->optedInSchema(20);
        $other   = $this->optedInSchema(21);
        $this->schemaMapper->method('findAll')->willReturn([$optedIn, $other]);

        $this->registerMapper->method('getAllRegisterIdsWithSchema')->willReturn([200]);
        $register = new Register();
        $register->setId(200);
        $this->registerMapper->method('find')->willReturn($register);

        $this->magicMapper->method('findAllInRegisterSchemaTable')
            ->willReturnOnConsecutiveCalls([$this->makeObject()], []);

        $this->submissionListener->expects($this->once())
            ->method('submitIfEligible')
            ->willReturn(true);

        $submitted = $this->provider->reindex(registerId: null, schemaId: 20);
        $this->assertSame(1, $submitted);
    }
}
