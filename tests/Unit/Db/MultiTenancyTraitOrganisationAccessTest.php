<?php

/**
 * Cross-tenant write enforcement in MultiTenancyTrait::verifyOrganisationAccess().
 *
 * The guard at the top of that method decides whether enforcement runs at all.
 * It probed method_exists($entity, 'getOrganisation'), and Nextcloud's Entity
 * serves get*() through __call() while declaring the accessors only as
 * `@method` — so the probe was FALSE for eight of the twelve mappers that use
 * this trait (Schema, Register, Configuration, Action, Mapping, Webhook, Agent
 * declare `@method string|null getOrganisation()`; Endpoint declares nothing at
 * all) and the method returned before comparing anything. No organisation
 * comparison, no `cross_tenant_access_denied` audit line, no 403 — across the
 * 24 update()/delete() call sites in lib/Db.
 *
 * Only Source, View and Application define getOrganisation() concretely, which
 * is why enforcement was live for those three and nothing looked broken.
 *
 * All twelve entities declare `protected $organisation`, so property_exists() —
 * the test Entity::getter() itself performs — covers every one of them and does
 * not depend on a getter being written by hand. The two sibling helpers in the
 * same file, setOrganisationOnCreate() and setOwnerOnCreate(), already use it.
 *
 * Each test below names, in its own docblock, whether it survives a revert of
 * that one-line change.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\Action;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MultiTenancyTrait;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\Webhook;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A minimal host for the trait, standing in for the twelve mappers that use it.
 *
 * Only the session lookup is overridden — verifyOrganisationAccess() itself is
 * the real trait code.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 */
final class TenancyGuardHost
{
    use MultiTenancyTrait;

    /**
     * Logger the trait audit branch writes to; the trait checks isset() first.
     *
     * @var LoggerInterface|null
     */
    public ?LoggerInterface $logger = null;

    /**
     * The organisation the "session" is currently acting in.
     *
     * @var string|null
     */
    private ?string $activeOrganisation;

    /**
     * @param string|null          $activeOrganisation Active organisation uuid, or null for none.
     * @param LoggerInterface|null $logger             Optional logger.
     */
    public function __construct(?string $activeOrganisation, ?LoggerInterface $logger=null)
    {
        $this->activeOrganisation = $activeOrganisation;
        $this->logger             = $logger;
    }//end __construct()

    /**
     * Stand in for the session/OrganisationMapper lookup.
     *
     * @return string|null
     */
    protected function getActiveOrganisationUuid(): ?string
    {
        return $this->activeOrganisation;
    }//end getActiveOrganisationUuid()

    /**
     * Expose the protected guard under test.
     *
     * @param Entity $entity The entity being written.
     *
     * @return void
     */
    public function verify(Entity $entity): void
    {
        $this->verifyOrganisationAccess(entity: $entity);
    }//end verify()
}//end class

/**
 * Enforcement coverage across all twelve trait-using entity types.
 *
 * @covers \OCA\OpenRegister\Db\MultiTenancyTrait
 */
class MultiTenancyTraitOrganisationAccessTest extends TestCase
{

    /**
     * Every entity class whose mapper uses MultiTenancyTrait.
     *
     * @return array<string, array{0: class-string<Entity>}>
     */
    public static function tenantEntityProvider(): array
    {
        $entities = [];
        foreach (self::accessorShapeProvider() as $label => $row) {
            $entities[$label] = [$row[0]];
        }

        return $entities;
    }//end tenantEntityProvider()

    /**
     * The same entity classes, paired with whether getOrganisation() is written
     * out concretely.
     *
     * @return array<string, array{0: class-string<Entity>, 1: bool}>
     */
    public static function accessorShapeProvider(): array
    {
        return [
            // Accessor served through Entity::__call() — enforcement was OFF.
            'Schema (@method)'        => [Schema::class, false],
            'Register (@method)'      => [Register::class, false],
            'Configuration (@method)' => [Configuration::class, false],
            'Action (@method)'        => [Action::class, false],
            'Mapping (@method)'       => [Mapping::class, false],
            'Webhook (@method)'       => [Webhook::class, false],
            'Agent (@method)'         => [Agent::class, false],
            'Endpoint (undeclared)'   => [Endpoint::class, false],
            // Concrete getter — enforcement was already live for these three.
            'Source (concrete)'       => [Source::class, true],
            'View (concrete)'         => [View::class, true],
            'Application (concrete)'  => [Application::class, true],
        ];
    }//end accessorShapeProvider()

    /**
     * The premise, stated as an assertion rather than assumed: for the eight
     * entities above, method_exists() is FALSE while the backing property is
     * present. If this ever stops holding, the tests below stop meaning what
     * their names say.
     *
     * REVERT: stays GREEN. This asserts the framework's behaviour, not the fix.
     *
     * @param class-string<Entity> $entityClass   Entity under test.
     * @param bool                 $hasConcreteGetter Whether the getter is written out.
     *
     * @dataProvider accessorShapeProvider
     *
     * @return void
     */
    public function testOrganisationAccessorShapeIsWhatTheGuardAssumes(
        string $entityClass,
        bool $hasConcreteGetter
    ): void {
        $entity = new $entityClass();

        $this->assertTrue(
            property_exists($entity, 'organisation'),
            $entityClass.' must declare $organisation for the guard to read it'
        );
        $this->assertSame(
            $hasConcreteGetter,
            method_exists($entity, 'getOrganisation'),
            $entityClass.': method_exists() disagrees with the recorded accessor shape'
        );
    }//end testOrganisationAccessorShapeIsWhatTheGuardAssumes()

    /**
     * A write from organisation B against an entity owned by organisation A is
     * refused with 403, for every trait-using entity type.
     *
     * REVERT: goes RED for the eight `@method`/undeclared entities — the guard
     * returns early and no exception is thrown. The three concrete ones stay
     * GREEN, which is the control that the harness itself works.
     *
     * @param class-string<Entity> $entityClass Entity under test.
     *
     * @dataProvider tenantEntityProvider
     *
     * @return void
     */
    public function testCrossOrganisationWriteIsRefused(string $entityClass): void
    {
        $entity = new $entityClass();
        $entity->setOrganisation('org-a');

        $host = new TenancyGuardHost('org-b');

        try {
            $host->verify($entity);
            $this->fail($entityClass.': cross-tenant write was allowed through');
        } catch (Exception $e) {
            $this->assertSame(Response::HTTP_FORBIDDEN, $e->getCode());
            $this->assertStringContainsString('different organisation', $e->getMessage());
        }
    }//end testCrossOrganisationWriteIsRefused()

    /**
     * A write inside the entity's own organisation is allowed.
     *
     * REVERT: stays GREEN — the broken guard also allowed it, by returning
     * early. This is here so the suite pins BOTH branches: without it, deleting
     * the comparison entirely and always throwing would still pass the test
     * above.
     *
     * @param class-string<Entity> $entityClass Entity under test.
     *
     * @dataProvider tenantEntityProvider
     *
     * @return void
     */
    public function testSameOrganisationWriteIsAllowed(string $entityClass): void
    {
        $entity = new $entityClass();
        $entity->setOrganisation('org-a');

        $host = new TenancyGuardHost('org-a');
        $host->verify($entity);

        $this->addToAssertionCount(1);
    }//end testSameOrganisationWriteIsAllowed()

    /**
     * An entity carrying no organisation is untouched — pre-tenancy rows must
     * keep working.
     *
     * REVERT: stays GREEN.
     *
     * @param class-string<Entity> $entityClass Entity under test.
     *
     * @dataProvider tenantEntityProvider
     *
     * @return void
     */
    public function testEntityWithoutAnOrganisationIsAllowed(string $entityClass): void
    {
        $host = new TenancyGuardHost('org-b');
        $host->verify(new $entityClass());

        $this->addToAssertionCount(1);
    }//end testEntityWithoutAnOrganisationIsAllowed()

    /**
     * FAIL-CLOSED, and deliberately pinned because it is the blast radius of
     * this fix: when there is no active organisation at all — no session, or an
     * install with no default organisation configured — a write against an
     * entity that HAS one is refused. Repair steps, imports and CLI paths that
     * touch a Schema or Register belonging to an organisation now hit this.
     *
     * That behaviour is not new; it has been live for Source, View and
     * Application all along. Turning the guard on extends it to the other eight.
     * If this ever needs to become fail-open, it should be a deliberate change
     * against this test, not a silent property of a broken probe.
     *
     * REVERT: goes RED for Schema (the guard returns early instead).
     *
     * @return void
     */
    public function testNoActiveOrganisationStillRefusesAnEntityThatHasOne(): void
    {
        $schema = new Schema();
        $schema->setOrganisation('org-a');

        $host = new TenancyGuardHost(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(Response::HTTP_FORBIDDEN);
        $host->verify($schema);
    }//end testNoActiveOrganisationStillRefusesAnEntityThatHasOne()

    /**
     * The refusal writes the `cross_tenant_access_denied` audit line. The 403
     * and the audit trail are two separate obligations and the broken guard
     * dropped both.
     *
     * REVERT: goes RED — the logger is never called.
     *
     * @return void
     */
    public function testCrossOrganisationRefusalIsAudited(): void
    {
        $schema = new Schema();
        $schema->setId(5);
        $schema->setOrganisation('org-a');

        $captured = [];
        $logger   = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string $message, array $context = []) use (&$captured): void {
                $captured[] = $context;
            }
        );

        $host = new TenancyGuardHost('org-b', $logger);

        try {
            $host->verify($schema);
        } catch (Exception $e) {
            unset($e);
        }

        $this->assertCount(1, $captured);
        $this->assertSame('cross_tenant_access_denied', $captured[0]['type']);
        $this->assertSame('org-b', $captured[0]['sourceOrganisation']);
        $this->assertSame('org-a', $captured[0]['targetOrganisation']);
        $this->assertSame(Schema::class, $captured[0]['entityType']);
        $this->assertSame(5, $captured[0]['entityId']);
    }//end testCrossOrganisationRefusalIsAudited()

    /**
     * An Entity subclass with no organisation property at all must be waved
     * through rather than fatalling — the guard has to stay a real membership
     * test. is_callable() would be unconditionally TRUE here and the following
     * getOrganisation() call would raise BadFunctionCallException.
     *
     * REVERT: stays GREEN.
     *
     * @return void
     */
    public function testEntityWithoutAnOrganisationPropertyIsWavedThrough(): void
    {
        $entity = new class extends Entity {
            /**
             * A column that is deliberately not `organisation`.
             *
             * @var string|null
             */
            protected ?string $label = null;
        };

        $host = new TenancyGuardHost('org-b');
        $host->verify($entity);

        $this->addToAssertionCount(1);
    }//end testEntityWithoutAnOrganisationPropertyIsWavedThrough()
}//end class
