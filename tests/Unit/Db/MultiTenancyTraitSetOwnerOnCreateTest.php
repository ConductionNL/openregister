<?php

/**
 * Unit tests for MultiTenancyTrait::setOwnerOnCreate()
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCP\AppFramework\Db\Entity;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * setOwnerOnCreate() guarded itself with method_exists() and therefore never ran.
 *
 * Nextcloud's Entity serves getters and setters through __call, declaring them
 * only as `@method`, and method_exists() is FALSE for those. Every entity in
 * lib/Db declares its owner accessors that way — not one has a real getOwner() —
 * so the guard returned early for EVERY entity and the method had never once set
 * an owner. It read as a safety net and was a no-op.
 *
 * `setOrganisationOnCreate()`, thirty lines above it in the same trait, already
 * used property_exists() and carried a comment explaining exactly why. The two
 * had simply drifted.
 *
 * Nothing visibly broke because the services that care set the owner explicitly
 * (SaveObject, FlowService, ViewService, OrganisationService, …). The hazard is
 * anything created straight through a mapper — repair steps, imports, new code —
 * which silently got a NULL owner in the one field half the private-scope
 * predicate is built on: owner OR admin OR granted.
 *
 * These tests drive the protected method by reflection and assert the OUTCOME on
 * the entity, because "was the setter reached" is the entire question.
 */
class MultiTenancyTraitSetOwnerOnCreateTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private IUserSession&MockObject $userSession;

    private IGroupManager&MockObject $groupManager;

    private IAppConfig&MockObject $appConfig;

    private WebhookMapper $mapper;


    /**
     * Build a mapper that uses the trait, with a logged-in user.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db           = $this->createMock(IDBConnection::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->mapper = new WebhookMapper(
            $this->db,
            $this->createMock(OrganisationMapper::class),
            $this->userSession,
            $this->groupManager,
            $this->appConfig
        );
    }//end setUp()


    /**
     * Invoke the protected method under test.
     *
     * @param Entity $entity The entity to pass in.
     *
     * @return void
     */
    private function callSetOwnerOnCreate(Entity $entity): void
    {
        $method = new ReflectionMethod($this->mapper, 'setOwnerOnCreate');
        $method->setAccessible(true);
        $method->invoke($this->mapper, $entity);
    }//end callSetOwnerOnCreate()


    /**
     * An entity with a magic owner setter gets the session user as its owner.
     *
     * This is the assertion the method existed for and never satisfied. It fails
     * against method_exists() and passes against property_exists().
     *
     * @return void
     */
    public function testOwnerIsSetFromTheSessionOnAnEntityWithMagicAccessors(): void
    {
        $entity = new ObjectEntity();

        $this->assertNull($entity->getOwner(), 'precondition: the fixture starts unowned');

        $this->callSetOwnerOnCreate(entity: $entity);

        $this->assertSame(
            'alice',
            $entity->getOwner(),
            'the owner was not set — method_exists() is false for __call setters, so the guard '
            .'returned early and this method did nothing at all'
        );
    }//end testOwnerIsSetFromTheSessionOnAnEntityWithMagicAccessors()


    /**
     * An owner already present is NOT overwritten.
     *
     * The docblock promises explicit owner assignment survives, and a mapper that
     * silently reassigned ownership on every create would be worse than one that
     * never assigned it.
     *
     * @return void
     */
    public function testAnExistingOwnerIsLeftAlone(): void
    {
        $entity = new ObjectEntity();
        $entity->setOwner('bob');

        $this->callSetOwnerOnCreate(entity: $entity);

        $this->assertSame('bob', $entity->getOwner(), 'an explicitly set owner must survive');
    }//end testAnExistingOwnerIsLeftAlone()


    /**
     * An entity with no owner property is left untouched, not fataled.
     *
     * property_exists() is what keeps this safe: calling setOwner() on an entity
     * without the property would create a field the mapper then tries to write to
     * a column that does not exist.
     *
     * @return void
     */
    public function testAnEntityWithoutAnOwnerPropertyIsUntouched(): void
    {
        $entity = new class extends Entity {
            protected ?string $unrelated = null;
        };

        $this->callSetOwnerOnCreate(entity: $entity);

        $this->assertFalse(
            property_exists($entity, 'owner'),
            'the trait must not invent an owner property on an entity that has none'
        );
    }//end testAnEntityWithoutAnOwnerPropertyIsUntouched()


}//end class
