<?php

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests proving RegisterMapper and SchemaMapper re-enabled their
 * verifyRbacPermission() guard on insert()/update()/delete().
 *
 * These call sites had previously been commented out; the re-enablement fix
 * restored the `$this->verifyRbacPermission('<action>', '<entityType>');`
 * line as the FIRST statement of each method. We verify this by partial-
 * mocking verifyRbacPermission() (a protected method contributed by
 * MultiTenancyTrait) on the mapper under test:
 *
 * - It must be invoked exactly once, with the expected action/entityType.
 * - When it throws (permission denied), the mapper method must abort
 *   BEFORE doing any further work (no DB calls happen), i.e. the guard is
 *   not a no-op / not bypassed.
 *
 * Note on hasRbacPermission() itself: that method's internal group/role
 * decision logic depends on an `$this->organisationService` property that
 * neither RegisterMapper nor SchemaMapper declares or injects (only
 * OrganisationMapper is injected). Because PHP's isset() on an undefined
 * dynamic property silently returns false, hasRbacPermission() takes the
 * "no organisation service, allow access (backward compatibility)" branch
 * for any authenticated non-admin caller — i.e. under the current wiring it
 * cannot actually deny a non-admin request. That is a separate, pre-existing
 * coupling issue in hasRbacPermission()'s data source, not something these
 * two re-enablement changes touch. These tests therefore verify the thing
 * the fix actually changed: that verifyRbacPermission() is called (the
 * guard is wired back in), not the full group-membership decision tree.
 */
class RegisterSchemaRbacTest extends TestCase
{

    private IDBConnection&MockObject $db;
    private SchemaMapper&MockObject $schemaMapperDependency;
    private IEventDispatcher&MockObject $eventDispatcher;
    private ContainerInterface&MockObject $container;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IAppConfig&MockObject $appConfig;

    private PropertyValidatorHandler&MockObject $validator;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->db                     = $this->createMock(IDBConnection::class);
        $this->schemaMapperDependency  = $this->createMock(SchemaMapper::class);
        $this->eventDispatcher         = $this->createMock(IEventDispatcher::class);
        $this->container               = $this->createMock(ContainerInterface::class);
        $this->organisationMapper      = $this->createMock(OrganisationMapper::class);
        $this->userSession              = $this->createMock(IUserSession::class);
        $this->groupManager             = $this->createMock(IGroupManager::class);
        $this->appConfig                = $this->createMock(IAppConfig::class);
        $this->validator                = $this->createMock(PropertyValidatorHandler::class);
        $this->logger                   = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build a RegisterMapper with verifyRbacPermission() partially mocked so
     * we can observe/stub only that call while leaving the rest of the class
     * real (constructor-injected dependencies are mocks so no real DB I/O
     * ever happens, matching the pattern used by RegisterMapperTest).
     */
    private function makeRegisterMapper(): RegisterMapper&MockObject
    {
        return $this->getMockBuilder(RegisterMapper::class)
            ->onlyMethods(['verifyRbacPermission'])
            ->setConstructorArgs(
                [
                    $this->db,
                    $this->schemaMapperDependency,
                    $this->eventDispatcher,
                    $this->container,
                    $this->organisationMapper,
                    $this->userSession,
                    $this->groupManager,
                    $this->appConfig,
                ]
            )
            ->getMock();
    }//end makeRegisterMapper()

    private function makeSchemaMapper(): SchemaMapper&MockObject
    {
        return $this->getMockBuilder(SchemaMapper::class)
            ->onlyMethods(['verifyRbacPermission'])
            ->setConstructorArgs(
                [
                    $this->db,
                    $this->eventDispatcher,
                    $this->validator,
                    $this->organisationMapper,
                    $this->userSession,
                    $this->groupManager,
                    $this->appConfig,
                    $this->logger,
                ]
            )
            ->getMock();
    }//end makeSchemaMapper()

    // -------------------------------------------------------------------------
    // RegisterMapper
    // -------------------------------------------------------------------------

    public function testRegisterInsertInvokesRbacGuardWithCreateAction(): void
    {
        $mapper = $this->makeRegisterMapper();
        $mapper->expects($this->once())
            ->method('verifyRbacPermission')
            ->with('create', 'register')
            ->willThrowException(new Exception('Access denied', 403));

        $register = new Register();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Access denied');
        $mapper->insert($register);
    }//end testRegisterInsertInvokesRbacGuardWithCreateAction()

    public function testRegisterUpdateInvokesRbacGuardWithUpdateAction(): void
    {
        $mapper = $this->makeRegisterMapper();
        $mapper->expects($this->once())
            ->method('verifyRbacPermission')
            ->with('update', 'register')
            ->willThrowException(new Exception('Access denied', 403));

        $register = new Register();
        $register->setId(1);

        $this->expectException(Exception::class);
        $mapper->update($register);
    }//end testRegisterUpdateInvokesRbacGuardWithUpdateAction()

    public function testRegisterDeleteInvokesRbacGuardWithDeleteAction(): void
    {
        $mapper = $this->makeRegisterMapper();
        $mapper->expects($this->once())
            ->method('verifyRbacPermission')
            ->with('delete', 'register')
            ->willThrowException(new Exception('Access denied', 403));

        $register = new Register();
        $register->setId(1);

        $this->expectException(Exception::class);
        $mapper->delete($register);
    }//end testRegisterDeleteInvokesRbacGuardWithDeleteAction()

    /**
     * When the RBAC guard passes (no exception), insert() must proceed past
     * the guard. We can't drive insert() all the way through a real DB
     * insert with mocks, but we CAN assert the guard was actually consulted
     * (not skipped) even on the "allowed" path, by making it return
     * normally and observing that execution proceeds to the next step
     * (setOrganisationOnCreate -> reads organisationMapper), which throws a
     * distinguishable exception we can assert on instead of a DB error.
     */
    public function testRegisterInsertProceedsPastRbacGuardWhenPermitted(): void
    {
        $mapper = $this->makeRegisterMapper();
        $mapper->expects($this->once())
            ->method('verifyRbacPermission')
            ->with('create', 'register');

        // getActiveOrganisationUuid() (called by setOrganisationOnCreate) finds no
        // logged-in user (mocked IUserSession::getUser() returns null by default),
        // so it falls back to getDefaultOrganisationUuid() ->
        // $this->organisationMapper->getDefaultOrganisationFromConfig(). Force a
        // distinguishable failure there to prove control flow passed the guard.
        $this->organisationMapper->method('getDefaultOrganisationFromConfig')
            ->willThrowException(new Exception('reached-past-rbac-guard'));

        $register = new Register();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('reached-past-rbac-guard');
        $mapper->insert($register);
    }//end testRegisterInsertProceedsPastRbacGuardWhenPermitted()

    // -------------------------------------------------------------------------
    // SchemaMapper
    // -------------------------------------------------------------------------

    public function testSchemaInsertInvokesRbacGuardWithCreateAction(): void
    {
        $mapper = $this->makeSchemaMapper();
        $mapper->expects($this->once())
            ->method('verifyRbacPermission')
            ->with('create', 'schema')
            ->willThrowException(new Exception('Access denied', 403));

        $schema = new Schema();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Access denied');
        $mapper->insert($schema);
    }//end testSchemaInsertInvokesRbacGuardWithCreateAction()

    public function testSchemaUpdateInvokesRbacGuardWithUpdateAction(): void
    {
        $mapper = $this->makeSchemaMapper();
        $mapper->expects($this->once())
            ->method('verifyRbacPermission')
            ->with('update', 'schema')
            ->willThrowException(new Exception('Access denied', 403));

        $schema = new Schema();
        $schema->setId(1);

        $this->expectException(Exception::class);
        $mapper->update($schema);
    }//end testSchemaUpdateInvokesRbacGuardWithUpdateAction()

    public function testSchemaDeleteInvokesRbacGuardWithDeleteAction(): void
    {
        $mapper = $this->makeSchemaMapper();
        $mapper->expects($this->once())
            ->method('verifyRbacPermission')
            ->with('delete', 'schema')
            ->willThrowException(new Exception('Access denied', 403));

        $schema = new Schema();
        $schema->setId(1);

        $this->expectException(Exception::class);
        $mapper->delete($schema);
    }//end testSchemaDeleteInvokesRbacGuardWithDeleteAction()
}//end class
