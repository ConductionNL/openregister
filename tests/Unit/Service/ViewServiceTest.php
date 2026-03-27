<?php

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\ViewService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class ViewServiceTest extends TestCase
{
    private ViewService $service;
    private ViewMapper&MockObject $viewMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->viewMapper = $this->createMock(ViewMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ViewService($this->viewMapper, $this->logger);
    }

    private function createView(int $id, string $owner, bool $isPublic = false, bool $isDefault = false): View
    {
        $view = new View();
        // Use reflection to set the id since Entity IDs can't be set via setter.
        $ref = new ReflectionClass($view);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($view, $id);

        $view->setName('Test View');
        $view->setDescription('A test view');
        $view->setOwner($owner);
        $view->setIsPublic($isPublic);
        $view->setIsDefault($isDefault);
        $view->setQuery([]);
        $view->setFavoredBy([]);

        return $view;
    }

    // ── find ──

    public function testFindReturnsOwnedView(): void
    {
        $view = $this->createView(1, 'user1');
        $this->viewMapper->method('find')->willReturn($view);

        $result = $this->service->find(1, 'user1');
        $this->assertSame($view, $result);
    }

    public function testFindReturnsPublicViewForOtherUser(): void
    {
        $view = $this->createView(1, 'user1', true);
        $this->viewMapper->method('find')->willReturn($view);

        $result = $this->service->find(1, 'user2');
        $this->assertSame($view, $result);
    }

    public function testFindThrowsForPrivateViewOfOtherUser(): void
    {
        $view = $this->createView(1, 'user1', false);
        $this->viewMapper->method('find')->willReturn($view);

        $this->expectException(DoesNotExistException::class);

        $this->service->find(1, 'user2');
    }

    // ── findAll ──

    public function testFindAllDelegatesToMapper(): void
    {
        $views = [$this->createView(1, 'user1'), $this->createView(2, 'user1')];
        $this->viewMapper->method('findAll')->willReturn($views);

        $result = $this->service->findAll('user1');
        $this->assertCount(2, $result);
    }

    // ── create ──

    public function testCreateReturnsInsertedView(): void
    {
        $this->viewMapper->method('insert')->willReturnCallback(function (View $view) {
            $ref = new ReflectionClass($view);
            $prop = $ref->getProperty('id');
            $prop->setAccessible(true);
            $prop->setValue($view, 1);
            return $view;
        });

        $result = $this->service->create('My View', 'Description', 'user1', false, false, []);

        $this->assertInstanceOf(View::class, $result);
        $this->assertSame('My View', $result->getName());
        $this->assertSame('user1', $result->getOwner());
    }

    public function testCreateClearsDefaultWhenSettingDefault(): void
    {
        // Existing default view.
        $existingDefault = $this->createView(1, 'user1', false, true);
        $this->viewMapper->method('findAll')->willReturn([$existingDefault]);
        $this->viewMapper->expects($this->atLeastOnce())->method('update');
        $this->viewMapper->method('insert')->willReturnCallback(function (View $view) {
            return $view;
        });

        $this->service->create('New Default', 'Desc', 'user1', false, true, []);
    }

    public function testCreateThrowsAndLogsOnFailure(): void
    {
        $this->viewMapper->method('insert')
            ->willThrowException(new Exception('DB error'));
        $this->logger->expects($this->once())->method('error');

        $this->expectException(Exception::class);

        $this->service->create('View', 'Desc', 'user1', false, false, []);
    }

    // ── update ──

    public function testUpdateReturnsUpdatedView(): void
    {
        $view = $this->createView(1, 'user1');
        $this->viewMapper->method('find')->willReturn($view);
        $this->viewMapper->method('update')->willReturnCallback(function (View $view) {
            return $view;
        });

        $result = $this->service->update(1, 'Updated', 'Desc', 'user1', false, false, ['key' => 'val']);

        $this->assertSame('Updated', $result->getName());
    }

    public function testUpdateWithFavoredBy(): void
    {
        $view = $this->createView(1, 'user1');
        $this->viewMapper->method('find')->willReturn($view);
        $this->viewMapper->method('update')->willReturnCallback(function (View $view) {
            return $view;
        });

        $result = $this->service->update(1, 'View', 'Desc', 'user1', false, false, [], ['user1', 'user2']);

        $this->assertSame(['user1', 'user2'], $result->getFavoredBy());
    }

    public function testUpdateClearsDefaultWhenSwitchingToDefault(): void
    {
        $existingDefault = $this->createView(2, 'user1', false, true);
        $view = $this->createView(1, 'user1', false, false);

        $this->viewMapper->method('find')->willReturn($view);
        $this->viewMapper->method('findAll')->willReturn([$existingDefault, $view]);

        $updatedViews = [];
        $this->viewMapper->method('update')->willReturnCallback(function (View $v) use (&$updatedViews) {
            $updatedViews[] = $v;
            return $v;
        });

        $result = $this->service->update(1, 'View', 'Desc', 'user1', false, true, []);

        // The existing default should have been cleared (updated to isDefault=false).
        $this->assertTrue($result->getIsDefault());
        $this->assertGreaterThanOrEqual(2, count($updatedViews));
    }

    public function testUpdateThrowsForPrivateViewOfOtherUser(): void
    {
        $view = $this->createView(1, 'user1', false);
        $this->viewMapper->method('find')->willReturn($view);

        $this->expectException(DoesNotExistException::class);

        $this->service->update(1, 'View', 'Desc', 'user2', false, false, []);
    }

    // ── delete ──

    public function testDeleteRemovesView(): void
    {
        $view = $this->createView(1, 'user1');
        $this->viewMapper->method('find')->willReturn($view);
        $this->viewMapper->expects($this->once())->method('delete');

        $this->service->delete(1, 'user1');
    }

    public function testDeleteThrowsForPrivateViewOfOtherUser(): void
    {
        $view = $this->createView(1, 'user1', false);
        $this->viewMapper->method('find')->willReturn($view);

        $this->expectException(DoesNotExistException::class);

        $this->service->delete(1, 'user2');
    }

    public function testDeleteThrowsAndLogsOnFailure(): void
    {
        $this->viewMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->logger->expects($this->once())->method('error');

        $this->expectException(Exception::class);

        $this->service->delete(999, 'user1');
    }
}
