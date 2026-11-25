<?php

/**
 * Starting an identifier scheme migration and reading its progress: administrators only.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\IdentifierSchemeMigrationsController;
use OCA\OpenRegister\Controller\Support\AdministratorOnlyGate;
use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationRunningException;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller over a mocked migration service and a real administrator gate.
 */
#[CoversClass(IdentifierSchemeMigrationsController::class)]
#[UsesClass(AdministratorOnlyGate::class)]
#[UsesClass(IdentifierSchemeRun::class)]
#[UsesClass(IdentifierSchemeMigrationRunningException::class)]
class IdentifierSchemeMigrationsControllerTest extends TestCase {

	/**
	 * The migrations.
	 *
	 * @var IdentifierSchemeMigrationService&MockObject
	 */
	private IdentifierSchemeMigrationService&MockObject $migrations;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->migrations = $this->createMock(IdentifierSchemeMigrationService::class);
		$this->request    = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnMap(
			[
				['register', 0, '3'],
				['property', '', ' identifier '],
				['previousFormat', '', '{year}-{seq:4}'],
			]
		);
	}//end setUp()

	/**
	 * An administrator starts a migration and gets 202 with the run.
	 *
	 * @return void
	 */
	public function testAnAdministratorStartsAMigration(): void {
		$run = new IdentifierSchemeRun();
		$run->setId(4);
		$this->migrations->expects($this->once())->method('start')
			->with(3, 9, 'identifier', '{year}-{seq:4}', 'admin')->willReturn($run);

		$response = $this->controller(admin: true)->create(id: 9);

		$this->assertSame(202, $response->getStatus());
		$this->assertSame($run, $response->getData());
	}//end testAnAdministratorStartsAMigration()

	/**
	 * A second migration of the sequence answers 409 naming the running one.
	 *
	 * @return void
	 */
	public function testARunningMigrationAnswers409NamingIt(): void {
		$running = new IdentifierSchemeRun();
		$running->setId(2);
		$this->migrations->method('start')->willThrowException(new IdentifierSchemeMigrationRunningException(sequence: 'case', running: $running));

		$response = $this->controller(admin: true)->create(id: 9);

		$this->assertSame(409, $response->getStatus());
		$this->assertSame($running, $response->getData()['running']);
		$this->assertStringContainsString('Sequence "case" is already being migrated (run 2', $response->getData()['error']);
	}//end testARunningMigrationAnswers409NamingIt()

	/**
	 * An unusable request answers 400 and an unknown schema 404.
	 *
	 * @return void
	 */
	public function testUnusableRequestsAndUnknownSchemas(): void {
		$this->migrations->method('start')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('"title" is not a generated identifier.')),
			$this->throwException(new DoesNotExistException('no schema'))
		);
		$controller = $this->controller(admin: true);

		$bad = $controller->create(id: 9);
		$missing = $controller->create(id: 99);

		$this->assertSame([400, ['error' => '"title" is not a generated identifier.']], [$bad->getStatus(), $bad->getData()]);
		$this->assertSame([404, ['error' => 'Schema not found']], [$missing->getStatus(), $missing->getData()]);
	}//end testUnusableRequestsAndUnknownSchemas()

	/**
	 * The progress of a run, or 404.
	 *
	 * @return void
	 */
	public function testShowAnswersTheRunOr404(): void {
		$run = new IdentifierSchemeRun();
		$run->setId(4);
		$this->migrations->method('find')->willReturnMap([[4, $run], [5, null]]);
		$controller = $this->controller(admin: true);

		$this->assertSame($run, $controller->show(run: 4)->getData());
		$this->assertSame(404, $controller->show(run: 5)->getStatus());
	}//end testShowAnswersTheRunOr404()

	/**
	 * A caller who is not an administrator is refused before anything runs.
	 *
	 * @return void
	 */
	public function testANonAdministratorIsRefused(): void {
		$this->migrations->expects($this->never())->method('start');
		$this->migrations->expects($this->never())->method('find');
		$controller = $this->controller(admin: false);

		$this->assertSame(403, $controller->create(id: 9)->getStatus());
		$this->assertSame(403, $controller->show(run: 4)->getStatus());
		$this->assertSame(['error' => 'Only an administrator may migrate identifiers'], $controller->show(run: 4)->getData());
	}//end testANonAdministratorIsRefused()

	/**
	 * The controller for user `admin`.
	 *
	 * @param bool $admin Whether `admin` is an administrator.
	 *
	 * @return IdentifierSchemeMigrationsController
	 */
	private function controller(bool $admin): IdentifierSchemeMigrationsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('admin')->willReturn($admin);

		return new IdentifierSchemeMigrationsController(
			$this->request,
			new AdministratorOnlyGate(userSession: $session, groupManager: $groups),
			$this->migrations,
			$session
		);
	}//end controller()
}//end class
