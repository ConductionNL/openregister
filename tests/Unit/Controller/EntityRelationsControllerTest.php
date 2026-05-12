<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\EntityRelationsController;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for `EntityRelationsController::update` (PATCH /api/entity-relations/{id}).
 *
 * Verifies the HTTP-side contract: auth (401/403), 404 on missing,
 * 400 propagation from the mapper's validation exceptions, 200 happy
 * path. The mapper itself is mocked; mapper-internal contract is
 * covered by `EntityRelationMapperUpdateDecisionMetadataTest`.
 *
 * @spec openspec/changes/entity-relation-grondslagen/specs/entity-relation-grondslagen/spec.md
 */
class EntityRelationsControllerTest extends TestCase
{
    private IRequest&MockObject $request;
    private EntityRelationMapper&MockObject $mapper;
    private IUserSession&MockObject $userSession;
    private IRootFolder&MockObject $rootFolder;
    private LoggerInterface&MockObject $logger;
    private IUser&MockObject $user;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->mapper = $this->createMock(EntityRelationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->user = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('alice');
    }

    private function makeController(): EntityRelationsController
    {
        return new EntityRelationsController(
            'openregister',
            $this->request,
            $this->mapper,
            $this->userSession,
            $this->rootFolder,
            $this->logger
        );
    }

    public function testReturns401WhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $controller = $this->makeController();
        $response = $controller->update(42);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }

    public function testReturns404WhenRelationNotFound(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user);
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('not found'));

        $controller = $this->makeController();
        $response = $controller->update(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testReturns403WhenFileNotWritable(): void
    {
        $relation = new EntityRelation();
        $relation->setFileId(7);

        $this->userSession->method('getUser')->willReturn($this->user);
        $this->mapper->method('find')->willReturn($relation);

        $file = $this->createMock(File::class);
        $file->method('isUpdateable')->willReturn(false);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$file]);

        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $controller = $this->makeController();
        $response = $controller->update(42);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testReturns403WhenFileNotPresentInUserFolder(): void
    {
        $relation = new EntityRelation();
        $relation->setFileId(7);

        $this->userSession->method('getUser')->willReturn($this->user);
        $this->mapper->method('find')->willReturn($relation);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $controller = $this->makeController();
        $response = $controller->update(42);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testReturns400WhenMapperRaisesValidationException(): void
    {
        $relation = new EntityRelation();
        $relation->setFileId(7);

        $this->userSession->method('getUser')->willReturn($this->user);
        $this->mapper->method('find')->willReturn($relation);

        $file = $this->createMock(File::class);
        $file->method('isUpdateable')->willReturn(true);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$file]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $this->request->method('getParams')->willReturn(['anonymized' => true]);

        $this->mapper
            ->method('updateDecisionMetadata')
            ->willThrowException(new CustomValidationException(
                'Field not editable: anonymized',
                ['field' => 'anonymized']
            ));

        $controller = $this->makeController();
        $response = $controller->update(42);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $body = $response->getData();
        $this->assertSame('Field not editable: anonymized', $body['error']);
        $this->assertSame('anonymized', $body['details']['field']);
    }

    public function testReturns200OnSuccessfulPatch(): void
    {
        $relation = new EntityRelation();
        $relation->setFileId(7);

        $updatedRelation = new EntityRelation();
        $updatedRelation->setFileId(7);
        $updatedRelation->setBases(['uuid-a']);

        $this->userSession->method('getUser')->willReturn($this->user);
        $this->mapper->method('find')->willReturn($relation);

        $file = $this->createMock(File::class);
        $file->method('isUpdateable')->willReturn(true);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$file]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $this->request->method('getParams')->willReturn([
            'id'      => 42, // injected by the routing framework — controller strips it
            '_route'  => 'openregister.entityRelations.update', // ditto
            'bases'   => ['uuid-a'],
        ]);

        $this->mapper
            ->expects($this->once())
            ->method('updateDecisionMetadata')
            ->with(
                $this->identicalTo($relation),
                $this->equalTo(['bases' => ['uuid-a']]),
                $this->identicalTo($this->user)
            )
            ->willReturn($updatedRelation);

        $controller = $this->makeController();
        $response = $controller->update(42);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $body = $response->getData();
        $this->assertSame(['uuid-a'], $body['bases']);
    }

    public function testReturns500OnUnexpectedException(): void
    {
        $relation = new EntityRelation();
        $relation->setFileId(7);

        $this->userSession->method('getUser')->willReturn($this->user);
        $this->mapper->method('find')->willReturn($relation);

        $file = $this->createMock(File::class);
        $file->method('isUpdateable')->willReturn(true);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('getById')->willReturn([$file]);
        $this->rootFolder->method('getUserFolder')->willReturn($userFolder);

        $this->request->method('getParams')->willReturn(['bases' => ['uuid-a']]);
        $this->mapper
            ->method('updateDecisionMetadata')
            ->willThrowException(new \RuntimeException('boom'));

        $controller = $this->makeController();
        $response = $controller->update(42);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }
}
