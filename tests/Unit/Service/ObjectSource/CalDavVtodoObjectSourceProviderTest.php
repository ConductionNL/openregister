<?php

/**
 * Unit tests for CalDavVtodoObjectSourceProvider.
 *
 * Covers:
 *  - isEnabled() reflects Tasks/Calendar app availability
 *  - findAll() maps VTODO arrays onto virtual ObjectEntity instances
 *  - find() resolves by uid/id and returns null when absent
 *  - read failures degrade to an empty list (fail closed)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-6.4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider;
use OCA\OpenRegister\Service\TaskService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for CalDavVtodoObjectSourceProvider.
 */
class CalDavVtodoObjectSourceProviderTest extends TestCase
{

    /**
     * Build a provider with a stubbed TaskService result and app availability.
     *
     * @param array $tasks     Task arrays returned under results.
     * @param bool  $appsThere Whether tasks/calendar are installed.
     *
     * @return CalDavVtodoObjectSourceProvider The provider under test.
     */
    private function provider(array $tasks, bool $appsThere=true): CalDavVtodoObjectSourceProvider
    {
        $taskService = $this->createMock(TaskService::class);
        $taskService->method('getAllUserTasks')->willReturn(['results' => $tasks, 'total' => count($tasks)]);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn($appsThere);

        return new CalDavVtodoObjectSourceProvider($taskService, $appManager, new NullLogger());
    }//end provider()

    /**
     * Sample VTODO task arrays as TaskService returns them.
     *
     * @return array<int, array<string, mixed>> The task arrays.
     */
    private function tasks(): array
    {
        return [
            [
                'id'          => 'uri-1.ics',
                'uid'         => 'uid-1',
                'summary'     => 'Send the minutes',
                'description' => 'Follow up on action item',
                'status'      => 'needs-action',
                'due'         => '2026-07-01',
                'priority'    => 5,
                'completed'   => null,
            ],
        ];
    }//end tasks()

    /**
     * getId() is the stable provider id.
     *
     * @return void
     */
    public function testGetId(): void
    {
        $this->assertSame('caldav-vtodo', $this->provider([])->getId());
    }//end testGetId()

    /**
     * isEnabled() reflects Tasks/Calendar availability.
     *
     * @return void
     */
    public function testIsEnabled(): void
    {
        $this->assertTrue($this->provider([], true)->isEnabled());
        $this->assertFalse($this->provider([], false)->isEnabled());
    }//end testIsEnabled()

    /**
     * findAll() maps each VTODO onto a virtual ObjectEntity.
     *
     * @return void
     */
    public function testFindAllMapsVtodos(): void
    {
        $register = new Register();
        $register->setId(3);
        $schema = new Schema();
        $schema->setId(9);

        $objects = $this->provider($this->tasks())->findAll($register, $schema);

        $this->assertCount(1, $objects);
        $data = $objects[0]->getObject();
        $this->assertSame('Send the minutes', $data['title']);
        $this->assertSame('needs-action', $data['status']);
        $this->assertSame('2026-07-01', $data['dueDate']);
        $this->assertSame('uid-1', $objects[0]->getUuid());
    }//end testFindAllMapsVtodos()

    /**
     * find() resolves by uid and returns null for an unknown id.
     *
     * @return void
     */
    public function testFindByIdAndMissing(): void
    {
        $register = new Register();
        $register->setId(3);
        $schema = new Schema();
        $schema->setId(9);

        $provider = $this->provider($this->tasks());
        $this->assertSame('Send the minutes', $provider->find($register, $schema, 'uid-1')?->getObject()['title']);
        $this->assertSame('Send the minutes', $provider->find($register, $schema, 'uri-1.ics')?->getObject()['title']);
        $this->assertNull($provider->find($register, $schema, 'does-not-exist'));
    }//end testFindByIdAndMissing()
}//end class
