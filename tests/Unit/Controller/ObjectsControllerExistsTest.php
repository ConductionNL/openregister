<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\CrossRegisterExistenceService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The existence endpoint refuses an anonymous caller before it asks anything.
 *
 * 🔴 AN ANONYMOUS CALLER LEARNING THAT A REGISTER HOLDS NOTHING ABOUT A PERSON
 * HAS STILL LEARNED SOMETHING. The refusal therefore happens before the service
 * is reached at all, and the assertion is that the container was never asked
 * for it: checking only the 401 would pass on an implementation that queried
 * every register first and then discarded the answer.
 *
 * @package Unit\Controller
 *
 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-probe-is-authorised-as-the-read-it-replaces
 */
class ObjectsControllerExistsTest extends TestCase {

	private ContainerInterface&MockObject $container;

	/**
	 * Build the controller with a session the test decides.
	 *
	 * @param boolean $signedIn Whether a user is signed in.
	 *
	 * @return ObjectsController The controller.
	 */
	private function controller(bool $signedIn): ObjectsController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(
			(($signedIn === true) ? $this->createMock(IUser::class) : null)
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn([]);

		return new ObjectsController(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * A fresh container per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
	}

	/**
	 * 🔴 No session, no probe, and nothing is asked of any register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-probe-is-authorised-as-the-read-it-replaces
	 */
	public function testAnAnonymousCallerIsRefusedBeforeAnythingIsAsked(): void {
		// The assertion that separates "refused early" from "refused after
		// querying": the service is never even resolved.
		$this->container->expects(self::never())->method('get');

		$response = $this->controller(signedIn: false)->exists();

		self::assertSame(401, $response->getStatus());
	}

	/**
	 * A signed-in caller reaches the service.
	 *
	 * The control for the test above: without it, a controller that refused
	 * EVERYONE would pass it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function testASignedInCallerReachesTheService(): void {
		$service = $this->createMock(CrossRegisterExistenceService::class);
		$service->method('probe')->willReturn(['probes' => []]);
		$this->container->method('get')->willReturn($service);

		$response = $this->controller(signedIn: true)->exists();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['probes' => []], $response->getData());
	}

	/**
	 * A refusal from the service answers 422 rather than 200 with an error in it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cross-register-existence-query/specs/cross-register-existence-query/spec.md#requirement-a-caller-can-ask-whether-a-row-exists-without-reading-it
	 */
	public function testAServiceRefusalAnswers422(): void {
		$service = $this->createMock(CrossRegisterExistenceService::class);
		$service->method('probe')->willReturn(
			['error' => 'too-many-probes', 'message' => 'A call carries at most 10 probes']
		);
		$this->container->method('get')->willReturn($service);

		$response = $this->controller(signedIn: true)->exists();

		self::assertSame(422, $response->getStatus());
		self::assertSame('too-many-probes', $response->getData()['error']);
	}
}//end class
