<?php

/**
 * ViewService Unit Test
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

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

/**
 * Unit tests for ViewService.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */
class ViewServiceTest extends TestCase
{

    /**
     * The view service under test.
     *
     * @var ViewService
     */
    private ViewService $service;

    /**
     * Mock view mapper.
     *
     * @var ViewMapper&MockObject
     */
    private ViewMapper&MockObject $viewMapper;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->viewMapper = $this->createMock(originalClassName: ViewMapper::class);
        $this->logger     = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new ViewService(viewMapper: $this->viewMapper, logger: $this->logger);
    }//end setUp()

    /**
     * Create a test View entity with the given properties.
     *
     * Entity setters use magic __call() and do NOT support named parameters —
     * always use positional arguments when calling setters on Entity instances.
     *
     * @param int    $id        The view ID to inject via reflection
     * @param string $owner     The owner user ID
     * @param bool   $isPublic  Whether the view is public
     * @param bool   $isDefault Whether the view is the user's default
     *
     * @return View The configured view entity
     */
    private function createView(int $id, string $owner, bool $isPublic=false, bool $isDefault=false): View
    {
        $view = new View();
        // Use reflection to set the id since Entity IDs can't be set via setter.
        $ref  = new ReflectionClass($view);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($view, $id);

        // Entity setters go through __call() magic — positional args ONLY.
        $view->setName('Test View');
        $view->setDescription('A test view');
        $view->setOwner($owner);
        $view->setIsPublic($isPublic);
        $view->setIsDefault($isDefault);
        $view->setQuery([]);
        $view->setFavoredBy([]);

        return $view;
    }//end createView()

    // ── find ──

    /**
     * Test that find() returns the view when the requester is the owner.
     *
     * @return void
     */
    public function testFindReturnsOwnedView(): void
    {
        $view = $this->createView(id: 1, owner: 'user1');
        $this->viewMapper->method('find')->willReturn(value: $view);

        $result = $this->service->find(id: 1, owner: 'user1');
        $this->assertSame(expected: $view, actual: $result);
    }//end testFindReturnsOwnedView()

    /**
     * Test that find() returns a public view even when the requester is not the owner.
     *
     * @return void
     */
    public function testFindReturnsPublicViewForOtherUser(): void
    {
        $view = $this->createView(id: 1, owner: 'user1', isPublic: true);
        $this->viewMapper->method('find')->willReturn(value: $view);

        $result = $this->service->find(id: 1, owner: 'user2');
        $this->assertSame(expected: $view, actual: $result);
    }//end testFindReturnsPublicViewForOtherUser()

    /**
     * Test that find() throws when a non-owner requests a private view.
     *
     * @return void
     */
    public function testFindThrowsForPrivateViewOfOtherUser(): void
    {
        $view = $this->createView(id: 1, owner: 'user1', isPublic: false);
        $this->viewMapper->method('find')->willReturn(value: $view);

        $this->expectException(exception: DoesNotExistException::class);

        $this->service->find(id: 1, owner: 'user2');
    }//end testFindThrowsForPrivateViewOfOtherUser()

    // ── findAll ──

    /**
     * Test that findAll() delegates to the mapper.
     *
     * @return void
     */
    public function testFindAllDelegatesToMapper(): void
    {
        $views = [$this->createView(id: 1, owner: 'user1'), $this->createView(id: 2, owner: 'user1')];
        $this->viewMapper->method('findAll')->willReturn(value: $views);

        $result = $this->service->findAll(owner: 'user1');
        $this->assertCount(expectedCount: 2, haystack: $result);
    }//end testFindAllDelegatesToMapper()

    // ── create ──

    /**
     * Test that create() returns the inserted view entity.
     *
     * @return void
     */
    public function testCreateReturnsInsertedView(): void
    {
        $this->viewMapper->method('insert')->willReturnCallback(
            function (View $view) {
                $ref  = new ReflectionClass($view);
                $prop = $ref->getProperty('id');
                $prop->setAccessible(true);
                $prop->setValue($view, 1);
                return $view;
            }
        );

        $result = $this->service->create(
            name: 'My View',
            description: 'Description',
            owner: 'user1',
            isPublic: false,
            isDefault: false,
            query: []
        );

        $this->assertInstanceOf(expected: View::class, actual: $result);
        $this->assertSame(expected: 'My View', actual: $result->getName());
        $this->assertSame(expected: 'user1', actual: $result->getOwner());
    }//end testCreateReturnsInsertedView()

    /**
     * Regression test for #1947: ViewMapper::insert() MUST stamp the active
     * organisation UUID onto created views so that subsequent findAll() /
     * find() calls (which apply applyOrganisationFilter) can locate them.
     *
     * At the service layer the mapper is mocked, so we simulate what
     * ViewMapper::insert → setOrganisationOnCreate() does: it sets the
     * organisation on the entity before persisting. The service MUST return
     * whatever the mapper returns — i.e. the organisation value set by the
     * mapper must survive back to the caller.
     *
     * Root cause: ViewMapper was missing OrganisationMapper + IAppConfig
     * injections, so MultiTenancyTrait::setOrganisationOnCreate() silently
     * skipped the org-stamp on every insert. Views were stored with NULL
     * organisation and then invisible to applyOrganisationFilter queries.
     *
     * @return void
     */
    public function testCreatePreservesOrganisationSetByMapper(): void
    {
        $expectedOrg = 'org-uuid-from-session';

        $this->viewMapper->method('insert')->willReturnCallback(
            // Entity setters go through __call() magic — positional args ONLY.
            function (View $view) use ($expectedOrg) {
                // Simulate ViewMapper::insert → setOrganisationOnCreate() stamping the org.
                $view->setOrganisation($expectedOrg);
                $ref  = new ReflectionClass($view);
                $prop = $ref->getProperty('id');
                $prop->setAccessible(true);
                $prop->setValue($view, 42);
                return $view;
            }
        );

        $result = $this->service->create(
            name: 'Org View',
            description: 'Desc',
            owner: 'user1',
            isPublic: false,
            isDefault: false,
            query: []
        );

        $this->assertInstanceOf(expected: View::class, actual: $result);
        $this->assertSame(
            expected: $expectedOrg,
            actual: $result->getOrganisation(),
            message: 'ViewService::create() must return the view with the organisation set by ViewMapper::insert() — '
                .'failing this means views will be invisible on reload (organisation-filter returns them as null/missing). '
                .'Root cause fix: ViewMapper must inject OrganisationMapper + IAppConfig so setOrganisationOnCreate() works.'
        );
    }//end testCreatePreservesOrganisationSetByMapper()

    /**
     * Test that create() clears the existing default view when isDefault=true.
     *
     * @return void
     */
    public function testCreateClearsDefaultWhenSettingDefault(): void
    {
        // Existing default view.
        $existingDefault = $this->createView(id: 1, owner: 'user1', isPublic: false, isDefault: true);
        $this->viewMapper->method('findAll')->willReturn(value: [$existingDefault]);
        $this->viewMapper->expects($this->atLeastOnce())->method('update');
        $this->viewMapper->method('insert')->willReturnCallback(
            function (View $view) {
                return $view;
            }
        );

        $this->service->create(
            name: 'New Default',
            description: 'Desc',
            owner: 'user1',
            isPublic: false,
            isDefault: true,
            query: []
        );
    }//end testCreateClearsDefaultWhenSettingDefault()

    /**
     * Test that create() throws and logs when the mapper throws an exception.
     *
     * @return void
     */
    public function testCreateThrowsAndLogsOnFailure(): void
    {
        $this->viewMapper->method('insert')
            ->willThrowException(new Exception('DB error'));
        $this->logger->expects($this->once())->method('error');

        $this->expectException(exception: Exception::class);

        $this->service->create(
            name: 'View',
            description: 'Desc',
            owner: 'user1',
            isPublic: false,
            isDefault: false,
            query: []
        );
    }//end testCreateThrowsAndLogsOnFailure()

    // ── update ──

    /**
     * Test that update() returns the updated view entity.
     *
     * @return void
     */
    public function testUpdateReturnsUpdatedView(): void
    {
        $view = $this->createView(id: 1, owner: 'user1');
        $this->viewMapper->method('find')->willReturn(value: $view);
        $this->viewMapper->method('update')->willReturnCallback(
            function (View $view) {
                return $view;
            }
        );

        $result = $this->service->update(
            id: 1,
            name: 'Updated',
            description: 'Desc',
            owner: 'user1',
            isPublic: false,
            isDefault: false,
            query: ['key' => 'val']
        );

        $this->assertSame(expected: 'Updated', actual: $result->getName());
    }//end testUpdateReturnsUpdatedView()

    /**
     * Test that update() correctly sets the favoredBy array.
     *
     * @return void
     */
    public function testUpdateWithFavoredBy(): void
    {
        $view = $this->createView(id: 1, owner: 'user1');
        $this->viewMapper->method('find')->willReturn(value: $view);
        $this->viewMapper->method('update')->willReturnCallback(
            function (View $view) {
                return $view;
            }
        );

        $result = $this->service->update(
            id: 1,
            name: 'View',
            description: 'Desc',
            owner: 'user1',
            isPublic: false,
            isDefault: false,
            query: [],
            favoredBy: ['user1', 'user2']
        );

        $this->assertSame(expected: ['user1', 'user2'], actual: $result->getFavoredBy());
    }//end testUpdateWithFavoredBy()

    /**
     * Test that update() clears the existing default when switching to default.
     *
     * @return void
     */
    public function testUpdateClearsDefaultWhenSwitchingToDefault(): void
    {
        $existingDefault = $this->createView(id: 2, owner: 'user1', isPublic: false, isDefault: true);
        $view            = $this->createView(id: 1, owner: 'user1', isPublic: false, isDefault: false);

        $this->viewMapper->method('find')->willReturn(value: $view);
        $this->viewMapper->method('findAll')->willReturn(value: [$existingDefault, $view]);

        $updatedViews = [];
        $this->viewMapper->method('update')->willReturnCallback(
            function (View $v) use (&$updatedViews) {
                $updatedViews[] = $v;
                return $v;
            }
        );

        $result = $this->service->update(
            id: 1,
            name: 'View',
            description: 'Desc',
            owner: 'user1',
            isPublic: false,
            isDefault: true,
            query: []
        );

        // The existing default should have been cleared (updated to isDefault=false).
        $this->assertTrue(condition: $result->getIsDefault());
        $this->assertGreaterThanOrEqual(expected: 2, actual: count($updatedViews));
    }//end testUpdateClearsDefaultWhenSwitchingToDefault()

    /**
     * Test that update() throws when the requester is not the owner of a private view.
     *
     * @return void
     */
    public function testUpdateThrowsForPrivateViewOfOtherUser(): void
    {
        $view = $this->createView(id: 1, owner: 'user1', isPublic: false);
        $this->viewMapper->method('find')->willReturn(value: $view);

        $this->expectException(exception: DoesNotExistException::class);

        $this->service->update(
            id: 1,
            name: 'View',
            description: 'Desc',
            owner: 'user2',
            isPublic: false,
            isDefault: false,
            query: []
        );
    }//end testUpdateThrowsForPrivateViewOfOtherUser()

    // ── delete ──

    /**
     * Test that delete() removes the view via the mapper.
     *
     * @return void
     */
    public function testDeleteRemovesView(): void
    {
        $view = $this->createView(id: 1, owner: 'user1');
        $this->viewMapper->method('find')->willReturn(value: $view);
        $this->viewMapper->expects($this->once())->method('delete');

        $this->service->delete(id: 1, owner: 'user1');
    }//end testDeleteRemovesView()

    /**
     * Test that delete() throws when the requester is not the owner of a private view.
     *
     * @return void
     */
    public function testDeleteThrowsForPrivateViewOfOtherUser(): void
    {
        $view = $this->createView(id: 1, owner: 'user1', isPublic: false);
        $this->viewMapper->method('find')->willReturn(value: $view);

        $this->expectException(exception: DoesNotExistException::class);

        $this->service->delete(id: 1, owner: 'user2');
    }//end testDeleteThrowsForPrivateViewOfOtherUser()

    /**
     * Test that delete() throws and logs when the mapper throws an exception.
     *
     * @return void
     */
    public function testDeleteThrowsAndLogsOnFailure(): void
    {
        $this->viewMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->logger->expects($this->once())->method('error');

        $this->expectException(exception: Exception::class);

        $this->service->delete(id: 999, owner: 'user1');
    }//end testDeleteThrowsAndLogsOnFailure()
}//end class
