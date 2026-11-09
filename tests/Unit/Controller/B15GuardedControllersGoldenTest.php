<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ContentReportController;
use OCA\OpenRegister\Controller\ErasurePreviewController;
use OCA\OpenRegister\Controller\FlowRunMigrationController;
use OCA\OpenRegister\Controller\FlowTestRunController;
use OCA\OpenRegister\Controller\HardeningController;
use OCA\OpenRegister\Controller\HardeningStatementController;
use OCA\OpenRegister\Db\ContentReportMapper;
use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Service\Audit\ContentReportService;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuard;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewService;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStore;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunner;
use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use OCA\OpenRegister\Service\Hardening\ElevationService;
use OCA\OpenRegister\Service\Hardening\HardeningPolicy;
use OCA\OpenRegister\Service\Hardening\HardeningReportService;
use OCA\OpenRegister\Service\Hardening\HardeningSettingsService;
use OCA\OpenRegister\Service\Hardening\StatementService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ContentReportControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ErasurePreviewControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowRunMigrationControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FlowTestRunControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\HardeningControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\HardeningStatementControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the allowed and refused outcomes of the hardening, statement, flow-run
 * migration, flow test-run, content-report and erasure-preview controllers.
 *
 * The controllers are built through their test factories, so the same cases
 * run against the flat constructors and against the bundled ones.
 */
final class B15GuardedControllersGoldenTest extends TestCase {
	/**
	 * The route attributes of every action, which decide who reaches it at all.
	 *
	 * @return void
	 */
	public function testTheRouteAttributesAreUnchanged(): void {
		$expected = [
			HardeningController::class => [
				'report' => ['NoCSRFRequired'],
				'floors' => ['NoCSRFRequired'],
				'updateControls' => ['NoCSRFRequired'],
				'updateFloors' => ['NoCSRFRequired'],
				'elevate' => ['NoCSRFRequired', 'BruteForceProtection'],
			],
			HardeningStatementController::class => [
				'statement' => ['NoAdminRequired', 'NoCSRFRequired'],
				'acceptStatement' => ['NoAdminRequired', 'NoCSRFRequired'],
				'publishStatement' => ['NoCSRFRequired'],
				'withdrawStatement' => ['NoCSRFRequired'],
			],
			FlowRunMigrationController::class => [
				'migrate' => ['NoAdminRequired', 'NoCSRFRequired'],
				'migrateRuns' => ['NoAdminRequired', 'NoCSRFRequired'],
			],
			FlowTestRunController::class => [
				'test' => ['NoAdminRequired'],
			],
			ContentReportController::class => [
				'create' => [],
				'index' => [],
				'show' => [],
				'copy' => [],
				'update' => [],
			],
			ErasurePreviewController::class => [
				'create' => [],
				'show' => [],
				'approve' => [],
				'run' => [],
			],
		];
		$actual = [];
		foreach (array_keys($expected) as $class) {
			$actual[$class] = [];
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() === true) {
					continue;
				}

				$names = [];
				foreach ($method->getAttributes() as $attribute) {
					$parts = explode('\\', $attribute->getName());
					$names[] = end($parts);
				}

				$actual[$class][$method->getName()] = $names;
			}
		}

		$this->assertSame($expected, $actual);
	}//end testTheRouteAttributesAreUnchanged()

	/**
	 * A control write needs a fresh elevation; without one it is refused before any setting changes.
	 *
	 * @return void
	 */
	public function testControlWritesNeedElevation(): void {
		foreach ([true, false] as $elevated) {
			$elevation = $this->createMock(ElevationService::class);
			$settings = $this->createMock(HardeningSettingsService::class);
			if ($elevated === true) {
				$settings->expects($this->once())->method('setControl')->willReturn(1);
			} else {
				$elevation->method('requireElevated')->willThrowException(new ElevationRequiredException(300));
				$settings->expects($this->never())->method('setControl');
			}

			$controller = HardeningControllerFactory::create(
				'openregister',
				$this->request(['controls' => ['csp' => 1]]),
				$this->createMock(HardeningReportService::class),
				$settings,
				$this->createMock(HardeningPolicy::class),
				$elevation
			);
			$this->assertSame($elevated === true ? 200 : 403, $controller->updateControls()->getStatus());
		}
	}//end testControlWritesNeedElevation()

	/**
	 * A wrong password does not elevate.
	 *
	 * @return void
	 */
	public function testAWrongPasswordDoesNotElevate(): void {
		foreach ([true, false] as $accepted) {
			$elevation = $this->createMock(ElevationService::class);
			$elevation->method('elevate')->willReturn($accepted);
			$controller = HardeningControllerFactory::create(
				'openregister',
				$this->request(['password' => 'secret']),
				$this->createMock(HardeningReportService::class),
				$this->createMock(HardeningSettingsService::class),
				$this->createMock(HardeningPolicy::class),
				$elevation
			);
			$this->assertSame($accepted === true ? 200 : 401, $controller->elevate()->getStatus());
		}
	}//end testAWrongPasswordDoesNotElevate()

	/**
	 * Publishing and withdrawing the statement need a fresh elevation.
	 *
	 * @return void
	 */
	public function testStatementWritesNeedElevation(): void {
		foreach (['publishStatement', 'withdrawStatement'] as $action) {
			foreach ([true, false] as $elevated) {
				$elevation = $this->createMock(ElevationService::class);
				$statements = $this->createMock(StatementService::class);
				if ($action === 'publishStatement') {
					$statements->method('publish')->willReturn([]);
				}

				if ($elevated === false) {
					$elevation->method('requireElevated')->willThrowException(new ElevationRequiredException(300));
				}

				$controller = HardeningStatementControllerFactory::create(
					'openregister',
					$this->request([]),
					$statements,
					$elevation,
					$this->userSession(signedIn: true)
				);
				$this->assertSame($elevated === true ? 200 : 403, $controller->$action()->getStatus(), $action);
			}
		}
	}//end testStatementWritesNeedElevation()

	/**
	 * A run migration fails closed without the migration service, and otherwise follows the run guard.
	 *
	 * @return void
	 */
	public function testRunMigrationFailsClosedAndFollowsTheGuard(): void {
		$closed = FlowRunMigrationControllerFactory::create(
			'openregister',
			$this->request([]),
			$this->createMock(FlowRunMapperInterface::class),
			$this->userSession(signedIn: true),
			$this->createMock(FlowRunnableGuard::class)
		);
		$this->assertSame(503, $closed->migrate('run-1')->getStatus());

		$run = new FlowRun();
		$run->setFlowId('flow-1');
		$mapper = $this->createMock(FlowRunMapperInterface::class);
		$mapper->method('findByUuid')->willReturn($run);
		$guard = $this->createMock(FlowRunnableGuard::class);
		$guard->method('refusalUnlessRunnable')->willReturn(new JSONResponse(['error' => 'no'], 403));
		$migrations = $this->createMock(FlowRunMigrationServiceInterface::class);
		$migrations->expects($this->never())->method($this->anything());
		$refused = FlowRunMigrationControllerFactory::create(
			'openregister',
			$this->request([]),
			$mapper,
			$this->userSession(signedIn: true),
			$guard,
			$migrations
		);
		$this->assertSame(403, $refused->migrate('run-1')->getStatus());
		$this->assertSame(403, $refused->migrateRuns('flow-1')->getStatus());
	}//end testRunMigrationFailsClosedAndFollowsTheGuard()

	/**
	 * A test run is refused when the caller may not edit the flow, or may not run it.
	 *
	 * @return void
	 */
	public function testTestRunFollowsBothGuards(): void {
		$cases = [
			'may not edit' => [new JSONResponse(['error' => 'no'], 403), null, 403],
			'may not run' => [null, new JSONResponse(['error' => 'no'], 403), 403],
		];
		foreach ($cases as $label => [$editRefusal, $runRefusal, $status]) {
			$guard = $this->createMock(FlowRunnableGuard::class);
			$guard->method('refusalUnlessMayEditFlow')->willReturn($editRefusal);
			$guard->method('refusalUnlessRunnable')->willReturn($runRefusal);
			$runner = $this->createMock(FlowRunServiceInterface::class);
			$runner->expects($this->never())->method($this->anything());
			$controller = FlowTestRunControllerFactory::create(
				'openregister',
				$this->request(['flowId' => 'flow-1']),
				$runner,
				$this->createMock(FlowLocatorInterface::class),
				$this->userSession(signedIn: true),
				$guard
			);
			$this->assertSame($status, $controller->test()->getStatus(), $label);
		}
	}//end testTestRunFollowsBothGuards()

	/**
	 * Reading reported content needs a signed-in reviewer.
	 *
	 * @return void
	 */
	public function testReportedContentNeedsAReviewer(): void {
		$cases = [
			'anonymous' => [false, false, 401],
			'not a reviewer' => [true, false, 403],
			'reviewer' => [true, true, 200],
		];
		foreach ($cases as $label => [$signedIn, $reviewer, $status]) {
			$service = $this->createMock(ContentReportService::class);
			$service->method('reviewerGroup')->willReturn('reviewers');
			$service->method('mayReadCopy')->willReturn($reviewer);
			$reports = $this->createMock(ContentReportMapper::class);
			$reports->method('findAll')->willReturn([]);
			$controller = ContentReportControllerFactory::create(
				'openregister',
				$this->request([]),
				$reports,
				$service,
				$this->createMock(MagicObjectFinderInterface::class),
				$this->userSession(signedIn: $signedIn)
			);
			$this->assertSame($status, $controller->index()->getStatus(), $label);
		}
	}//end testReportedContentNeedsAReviewer()

	/**
	 * An erasure runs only for a preview the store calls runnable.
	 *
	 * @return void
	 */
	public function testAnErasureRunsOnlyWhenRunnable(): void {
		foreach ([true, false] as $runnable) {
			$store = $this->createMock(ErasurePreviewStore::class);
			$runner = $this->createMock(ErasureRunner::class);
			if ($runnable === true) {
				$store->method('requireRunnable')->willReturn(new ErasurePreview());
				$runner->expects($this->once())->method('run')->willReturn(['erased' => 1]);
			} else {
				$store->method('requireRunnable')->willThrowException(new ErasureRefusedException('approval', 'Not approved'));
				$runner->expects($this->never())->method('run');
			}

			$controller = ErasurePreviewControllerFactory::create(
				'openregister',
				$this->request([]),
				$this->createMock(ErasurePreviewService::class),
				$store,
				$runner
			);
			$this->assertSame($runnable === true ? 200 : 409, $controller->run('preview-1')->getStatus());
		}
	}//end testAnErasureRunsOnlyWhenRunnable()

	/**
	 * A request answering the given parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return $request;
	}//end request()

	/**
	 * A user session with or without a signed-in user.
	 *
	 * @param bool $signedIn Whether somebody is signed in.
	 *
	 * @return IUserSession
	 */
	private function userSession(bool $signedIn): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($signedIn === true ? $user : null);
		$userSession->method('isLoggedIn')->willReturn($signedIn);

		return $userSession;
	}//end userSession()
}//end class
