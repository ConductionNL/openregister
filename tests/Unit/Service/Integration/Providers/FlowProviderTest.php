<?php

/**
 * Unit tests for FlowProvider.
 *
 * Covers:
 *  - metadata (id / label / icon / group / requiredApp / storage strategy
 *    / requiresPermission='admin')
 *  - isEnabled() mirrors IAppManager::isInstalled('workflowengine')
 *  - list() returns [] when the app is disabled (happy graceful-degrade)
 *  - list() returns [] when getManager() can't resolve
 *  - list() returns [] when getAllOperations throws
 *  - list() returns flattened rows when admin-scoped operations exist
 *  - list() narrows to marker-matched rows when any are present
 *  - list() honours _search filter
 *  - health() reports unavailable when the app is missing,
 *    degraded when the Manager can't resolve, ok otherwise
 *
 * The provider's runtime dependency is `OCA\WorkflowEngine\Manager`,
 * which lives in NC's `workflowengine` app and isn't ergonomic to
 * instantiate directly (8 constructor args). We use a PHPUnit mock of
 * that class (constructor disabled) and override `getManager()` in a
 * per-test subclass so we can inject it without touching `Server::get`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-flow/tasks.md
 *
 * @group requires-app-workflowengine
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\Providers\FlowProvider;
use OCA\WorkflowEngine\Manager as FlowManager;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for FlowProvider.
 */
class FlowProviderTest extends TestCase
{

    /**
     * @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject
     */
    private $db;

    /**
     * @var IAppManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private $appManager;

    /**
     * @var IL10N&\PHPUnit\Framework\MockObject\MockObject
     */
    private $l10n;

    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists(FlowManager::class) === false) {
            $this->markTestSkipped('workflowengine app not on the classpath');
        }

        $this->db         = $this->createMock(IDBConnection::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->l10n       = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);
    }//end setUp()

    /**
     * Build a FlowProvider whose getManager() is overridden to return
     * the supplied stand-in. Pass null to simulate "app disabled / class
     * not on classpath / container resolution failed".
     *
     * @param FlowManager|null $manager Stand-in Manager (must expose
     *                                  getAllOperations) or null.
     *
     * @return FlowProvider
     */
    private function buildProvider(?FlowManager $manager): FlowProvider
    {
        return new class ($this->db, $this->appManager, $this->l10n, $manager) extends FlowProvider {
            public function __construct(
                IDBConnection $db,
                IAppManager $appManager,
                IL10N $l10n,
                private ?FlowManager $stub,
            ) {
                parent::__construct($db, $appManager, $l10n);
            }//end __construct()

            protected function getManager(): ?FlowManager
            {
                return $this->stub;
            }//end getManager()
        };
    }//end buildProvider()

    public function testMetadataMatchesLeafSpec(): void
    {
        $provider = $this->buildProvider(manager: null);

        $this->assertSame('flow', $provider->getId());
        $this->assertSame('Automation', $provider->getLabel());
        $this->assertSame('RobotOutline', $provider->getIcon());
        $this->assertSame('workflow', $provider->getGroup());
        $this->assertSame('workflowengine', $provider->getRequiredApp());
        $this->assertSame('link-table', $provider->getStorageStrategy());
        $this->assertSame('admin', $provider->requiresPermission());
    }//end testMetadataMatchesLeafSpec()

    public function testIsEnabledMirrorsWorkflowEngineInstall(): void
    {
        $this->appManager->method('isInstalled')
            ->with('workflowengine')
            ->willReturn(true);

        $provider = $this->buildProvider(manager: null);
        $this->assertTrue($provider->isEnabled());

        $appManager2 = $this->createMock(IAppManager::class);
        $appManager2->method('isInstalled')->with('workflowengine')->willReturn(false);
        $disabled    = new FlowProvider($this->db, $appManager2, $this->l10n);
        $this->assertFalse($disabled->isEnabled());
    }//end testIsEnabledMirrorsWorkflowEngineInstall()

    public function testListReturnsEmptyWhenAppDisabled(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(false);

        // Provide a manager that would fail if called — proves we
        // short-circuited at the isEnabled() gate.
        $manager = $this->createMock(FlowManager::class);
        $manager->expects($this->never())->method('getAllOperations');

        $provider = $this->buildProvider(manager: $manager);
        $this->assertSame([], $provider->list('reg', 'sch', 'obj-1'));
    }//end testListReturnsEmptyWhenAppDisabled()

    public function testListReturnsEmptyWhenManagerUnavailable(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);
        $provider = $this->buildProvider(manager: null);

        $this->assertSame([], $provider->list('reg', 'sch', 'obj-1'));
    }//end testListReturnsEmptyWhenManagerUnavailable()

    public function testListReturnsEmptyWhenGetAllOperationsThrows(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);

        $manager = $this->createMock(FlowManager::class);
        $manager->method('getAllOperations')->willThrowException(new RuntimeException('DB drift'));

        $provider = $this->buildProvider(manager: $manager);
        $this->assertSame([], $provider->list('reg', 'sch', 'obj-1'));
    }//end testListReturnsEmptyWhenGetAllOperationsThrows()

    public function testListFlattensAdminScopedOperationsWhenNoMarkerRows(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);

        $manager = $this->createMock(FlowManager::class);
        $manager->method('getAllOperations')->willReturn([
            'OCA\\Some\\Op' => [
                ['id' => 1, 'name' => 'On upload notify', 'class' => 'OCA\\Some\\Op', 'entity' => 'OCA\\WorkflowEngine\\Entity\\File', 'operation' => 'webhook'],
                ['id' => 2, 'name' => 'On tag set', 'class' => 'OCA\\Some\\Op', 'entity' => 'OCA\\WorkflowEngine\\Entity\\File', 'operation' => 'webhook'],
            ],
        ]);

        $provider = $this->buildProvider(manager: $manager);
        $rows     = $provider->list('reg', 'sch', 'obj-1');

        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0]['id']);
        $this->assertSame('On upload notify', $rows[0]['title']);
        $this->assertSame('OCA\\Some\\Op', $rows[0]['class']);
        $this->assertSame('webhook', $rows[0]['operation']);
        $this->assertFalse($rows[0]['hasMarker']);
        $this->assertSame('/index.php/settings/admin/workflow', $rows[0]['url']);
    }//end testListFlattensAdminScopedOperationsWhenNoMarkerRows()

    public function testListNarrowsToMarkerMatchedRowsWhenPresent(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);

        $manager = $this->createMock(FlowManager::class);
        $manager->method('getAllOperations')->willReturn([
            'OCA\\Some\\Op' => [
                ['id' => 10, 'name' => 'Plain rule', 'class' => 'OCA\\Some\\Op'],
                ['id' => 11, 'name' => 'Targeted [or:obj-1] rule', 'class' => 'OCA\\Some\\Op'],
                ['id' => 12, 'name' => 'Other [or:obj-2] rule', 'class' => 'OCA\\Some\\Op'],
            ],
        ]);

        $provider = $this->buildProvider(manager: $manager);
        $rows     = $provider->list('reg', 'sch', 'obj-1');

        $this->assertCount(1, $rows);
        $this->assertSame('11', $rows[0]['id']);
        $this->assertTrue($rows[0]['hasMarker']);
    }//end testListNarrowsToMarkerMatchedRowsWhenPresent()

    public function testListHonoursSearchFilter(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);

        $manager = $this->createMock(FlowManager::class);
        $manager->method('getAllOperations')->willReturn([
            'OCA\\Some\\Op' => [
                ['id' => 100, 'name' => 'Email to manager'],
                ['id' => 101, 'name' => 'Slack notify'],
                ['id' => 102, 'name' => 'Email finance'],
            ],
        ]);

        $provider = $this->buildProvider(manager: $manager);
        $rows     = $provider->list('reg', 'sch', 'obj-1', ['_search' => 'email']);

        $this->assertCount(2, $rows);
        $names = array_column($rows, 'title');
        $this->assertContains('Email to manager', $names);
        $this->assertContains('Email finance', $names);
        $this->assertNotContains('Slack notify', $names);
    }//end testListHonoursSearchFilter()

    public function testListReturnsEmptyArrayWhenGetAllOperationsReturnsNoRows(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);

        $manager = $this->createMock(FlowManager::class);
        $manager->method('getAllOperations')->willReturn([]);

        $provider = $this->buildProvider(manager: $manager);
        $this->assertSame([], $provider->list('reg', 'sch', 'obj-1'));
    }//end testListReturnsEmptyArrayWhenGetAllOperationsReturnsNoRows()

    public function testHealthReportsUnavailableWhenAppMissing(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(false);
        $provider = $this->buildProvider(manager: null);

        $health = $provider->health();
        $this->assertSame('unavailable', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertStringContainsString('workflowengine', (string) $health['message']);
    }//end testHealthReportsUnavailableWhenAppMissing()

    public function testHealthReportsDegradedWhenManagerUnresolvable(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);
        $provider = $this->buildProvider(manager: null);

        $health = $provider->health();
        $this->assertSame('degraded', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertStringContainsString('Manager', (string) $health['message']);
    }//end testHealthReportsDegradedWhenManagerUnresolvable()

    public function testHealthReportsOkWhenAppAndManagerAvailable(): void
    {
        $this->appManager->method('isInstalled')->with('workflowengine')->willReturn(true);

        $manager = $this->createMock(FlowManager::class);
        $provider = $this->buildProvider(manager: $manager);

        $health = $provider->health();
        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReportsOkWhenAppAndManagerAvailable()

}//end class
