<?php

/**
 * The listener that finally calls PermissionHandler's two cache evictors.
 *
 * Both were fully implemented, documented with the invariant they enforce, and
 * called by nothing outside the test suite — the shape gate-57
 * (orphaned-write-capability) exists to catch. PermissionHandlerAuthorizationCacheTest
 * proves the stale verdict is real; this proves the eviction actually fires, and
 * fires on the events that can change the policy.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\AuthorizationCacheInvalidationListener;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Eviction on schema and register policy writes.
 *
 * @covers \OCA\OpenRegister\Listener\AuthorizationCacheInvalidationListener
 */
class AuthorizationCacheInvalidationListenerTest extends TestCase
{

    /**
     * The evaluator whose memos are evicted, mocked.
     *
     * @var PermissionHandler&MockObject
     */
    private PermissionHandler&MockObject $permissionHandler;

    /**
     * The listener under test.
     *
     * @var AuthorizationCacheInvalidationListener
     */
    private AuthorizationCacheInvalidationListener $listener;

    /**
     * Build the listener over a mocked permission handler.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->permissionHandler = $this->createMock(PermissionHandler::class);
        $this->listener          = new AuthorizationCacheInvalidationListener($this->permissionHandler);

    }//end setUp()

    /**
     * A schema with a known id.
     *
     * @param integer $id The schema id.
     *
     * @return Schema The schema.
     */
    private function schema(int $id): Schema
    {
        $schema = new Schema();
        $schema->setId($id);

        return $schema;

    }//end schema()

    /**
     * A register with a known id.
     *
     * @param integer $id The register id.
     *
     * @return Register The register.
     */
    private function register(int $id): Register
    {
        $register = new Register();
        $register->setId($id);

        return $register;

    }//end register()

    /**
     * Editing a schema evicts THAT schema's inheritance verdict, plus every
     * memoised permission verdict — the permission memo's keys are opaque, so a
     * partial evict could not be proven correct.
     *
     * @return void
     */
    public function testASchemaUpdateEvictsThatSchemaAndTheVerdictMemo(): void
    {
        $this->permissionHandler->expects($this->once())
            ->method('clearInheritFromPublicCache')
            ->with(7);
        $this->permissionHandler->expects($this->once())->method('clearPermissionCache');

        $this->listener->handle(new SchemaUpdatedEvent(newSchema: $this->schema(id: 7), oldSchema: $this->schema(id: 7)));

    }//end testASchemaUpdateEvictsThatSchemaAndTheVerdictMemo()

    /**
     * Deleting a schema is a policy change too: a verdict memoised against a
     * schema that no longer exists must not survive the request.
     *
     * @return void
     */
    public function testASchemaDeleteEvictsThatSchema(): void
    {
        $this->permissionHandler->expects($this->once())
            ->method('clearInheritFromPublicCache')
            ->with(9);
        $this->permissionHandler->expects($this->once())->method('clearPermissionCache');

        $this->listener->handle(new SchemaDeletedEvent(schema: $this->schema(id: 9)));

    }//end testASchemaDeleteEvictsThatSchema()

    /**
     * A register's authorization is the fallback for EVERY schema under it, and
     * the memo keys on schema id only — so the whole map goes. `null` is the
     * argument that means "all"; asserting it pins the difference from the
     * targeted schema case above.
     *
     * @return void
     */
    public function testARegisterUpdateEvictsEverySchema(): void
    {
        $this->permissionHandler->expects($this->once())
            ->method('clearInheritFromPublicCache')
            ->with(null);
        $this->permissionHandler->expects($this->once())->method('clearPermissionCache');

        $this->listener->handle(
            new RegisterUpdatedEvent(newRegister: $this->register(id: 3), oldRegister: $this->register(id: 3))
        );

    }//end testARegisterUpdateEvictsEverySchema()

    /**
     * Same for a register deletion.
     *
     * @return void
     */
    public function testARegisterDeleteEvictsEverySchema(): void
    {
        $this->permissionHandler->expects($this->once())
            ->method('clearInheritFromPublicCache')
            ->with(null);
        $this->permissionHandler->expects($this->once())->method('clearPermissionCache');

        $this->listener->handle(new RegisterDeletedEvent(register: $this->register(id: 3)));

    }//end testARegisterDeleteEvictsEverySchema()

    /**
     * The negative control. An object write does not change authorization
     * POLICY, and evicting on every object write would quietly turn a
     * per-request memo into no cache at all — the performance property the memo
     * exists for, deleted by an over-broad listener.
     *
     * @return void
     */
    public function testAnObjectEventEvictsNothing(): void
    {
        $this->permissionHandler->expects($this->never())->method('clearInheritFromPublicCache');
        $this->permissionHandler->expects($this->never())->method('clearPermissionCache');

        $this->listener->handle(new ObjectCreatedEvent(object: new \OCA\OpenRegister\Db\ObjectEntity()));

    }//end testAnObjectEventEvictsNothing()
}//end class
