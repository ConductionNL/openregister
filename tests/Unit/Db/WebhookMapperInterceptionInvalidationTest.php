<?php

/**
 * WebhookMapper interception-cache invalidation tests.
 *
 * Proves every webhook CRUD operation (insert, update, delete) invalidates
 * the distributed "has interception webhooks" flag cache, and that the
 * mapper stays functional without a cache backend.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Webhook\WebhookInterceptionCache;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests proving webhook CRUD invalidates the interception-flag cache.
 */
class WebhookMapperInterceptionInvalidationTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IAppConfig&MockObject $appConfig;
    private WebhookInterceptionCache&MockObject $interceptionCache;
    private WebhookMapper $mapper;

    /**
     * Set up mapper with a fluent query-builder stub so QBMapper's CRUD
     * paths execute without a real database.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->interceptionCache = $this->createMock(WebhookInterceptionCache::class);

        // No user session → CLI trust path in the RBAC guard.
        $this->userSession->method('getUser')->willReturn(null);

        $this->db->method('getQueryBuilder')->willReturnCallback(
            fn (): IQueryBuilder => $this->makeFluentQueryBuilder()
        );

        $this->mapper = new WebhookMapper(
            $this->db,
            $this->organisationMapper,
            $this->userSession,
            $this->groupManager,
            $this->appConfig,
            $this->interceptionCache
        );
    }//end setUp()

    /**
     * Build a fluent IQueryBuilder stub for QBMapper CRUD paths.
     *
     * @return IQueryBuilder&MockObject
     */
    private function makeFluentQueryBuilder(): IQueryBuilder&MockObject
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('id = :id');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('insert')->willReturnSelf();
        $qb->method('update')->willReturnSelf();
        $qb->method('delete')->willReturnSelf();
        $qb->method('setValue')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn($this->createMock(IParameter::class));
        $qb->method('executeStatement')->willReturn(1);
        $qb->method('getLastInsertId')->willReturn(7);

        return $qb;
    }//end makeFluentQueryBuilder()

    /**
     * Build a minimal webhook entity.
     *
     * @param int|null $id Entity id (null for insert).
     *
     * @return Webhook
     */
    private function makeWebhook(?int $id=null): Webhook
    {
        $webhook = new Webhook();
        if ($id !== null) {
            $webhook->setId($id);
        }

        $webhook->setName('Hook');
        $webhook->setUrl('https://example.com/hook');
        $webhook->setMethod('POST');
        $webhook->setEnabled(true);

        return $webhook;
    }//end makeWebhook()

    /**
     * Inserting a webhook invalidates the interception-flag cache.
     *
     * @return void
     */
    public function testInsertInvalidatesInterceptionCache(): void
    {
        $this->interceptionCache->expects($this->once())->method('invalidate');

        $this->mapper->insert($this->makeWebhook());
    }//end testInsertInvalidatesInterceptionCache()

    /**
     * Updating a webhook invalidates the interception-flag cache.
     *
     * @return void
     */
    public function testUpdateInvalidatesInterceptionCache(): void
    {
        $this->interceptionCache->expects($this->once())->method('invalidate');

        $this->mapper->update($this->makeWebhook(id: 7));
    }//end testUpdateInvalidatesInterceptionCache()

    /**
     * Deleting a webhook invalidates the interception-flag cache.
     *
     * @return void
     */
    public function testDeleteInvalidatesInterceptionCache(): void
    {
        $this->interceptionCache->expects($this->once())->method('invalidate');

        $this->mapper->delete($this->makeWebhook(id: 7));
    }//end testDeleteInvalidatesInterceptionCache()

    /**
     * The mapper stays functional without a cache backend (nullable DI).
     *
     * @return void
     */
    public function testInsertWorksWithoutCacheBackend(): void
    {
        $mapper = new WebhookMapper(
            $this->db,
            $this->organisationMapper,
            $this->userSession,
            $this->groupManager,
            $this->appConfig
        );

        $inserted = $mapper->insert($this->makeWebhook());

        $this->assertInstanceOf(Webhook::class, $inserted);
    }//end testInsertWorksWithoutCacheBackend()
}//end class
