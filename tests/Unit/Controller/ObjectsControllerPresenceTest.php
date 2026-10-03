<?php

declare(strict_types=1);

/**
 * The three presence endpoints, on the wire.
 *
 * `PUT`, `DELETE` and `GET` on an object's `/presence` shipped publicly
 * reachable with no contract test of any kind, which is what gate-25 reports:
 * the wire contract of a new endpoint was known only to its implementation.
 * What matters here is the authorisation, because presence tells a caller who
 * else has a record open, and answering that to somebody who cannot read the
 * record is a disclosure. The read IS the check: a caller who cannot read the
 * object gets 404 and learns nothing, including whether it exists.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PresenceService;
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
 * Contract tests for objects#presenceBeat, presenceDepart and presenceList.
 */
class ObjectsControllerPresenceTest extends TestCase {
	private const CALLER = 'alice';

	private ObjectsController $controller;
	private ContainerInterface&MockObject $container;
	private ObjectService&MockObject $objectService;
	private IUserSession&MockObject $userSession;
	private PresenceService&MockObject $presence;

	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->presence = $this->createMock(PresenceService::class);

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === PresenceService::class) {
					return $this->presence;
				}

				if ($id === 'userId') {
					return self::CALLER;
				}

				return null;
			}
		);

		$this->controller = new ObjectsController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$this->userSession,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A signed-in caller.
	 *
	 * @return void
	 */
	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::CALLER);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedIn()

	/**
	 * The object read succeeds, so the caller may be told who is present.
	 *
	 * @return void
	 */
	private function objectIsReadable(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-123');
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willReturn($object);
	}//end objectIsReadable()

	/**
	 * The object read fails, which is what "not allowed" looks like here.
	 *
	 * @return void
	 */
	private function objectIsUnreadable(): void {
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('find')->willThrowException(new \RuntimeException('refused'));
	}//end objectIsUnreadable()

	/**
	 * An anonymous caller is told nothing about who is present.
	 *
	 * @return void
	 */
	public function testEveryPresenceEndpointRefusesAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame(401, $this->controller->presenceBeat('reg', 'sch', 'uuid-123')->getStatus());
		$this->assertSame(401, $this->controller->presenceDepart('reg', 'sch', 'uuid-123')->getStatus());
		$this->assertSame(401, $this->controller->presenceList('reg', 'sch', 'uuid-123')->getStatus());
	}//end testEveryPresenceEndpointRefusesAnAnonymousCaller()

	/**
	 * A caller who cannot read the object is answered 404 and learns nothing,
	 * not even that it exists. This is the endpoint's authorisation, so it is
	 * the assertion that matters most.
	 *
	 * @return void
	 */
	public function testABeatOnAnObjectTheCallerCannotReadAnswers404AndNoNames(): void {
		$this->signedIn();
		$this->objectIsUnreadable();
		$this->presence->expects($this->never())->method('present');

		$response = $this->controller->presenceBeat('reg', 'sch', 'uuid-123');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('present', $response->getData());
	}//end testABeatOnAnObjectTheCallerCannotReadAnswers404AndNoNames()

	/**
	 * The same refusal on the read endpoint.
	 *
	 * @return void
	 */
	public function testListingPresenceOnAnObjectTheCallerCannotReadAnswers404(): void {
		$this->signedIn();
		$this->objectIsUnreadable();
		$this->presence->expects($this->never())->method('present');

		$response = $this->controller->presenceList('reg', 'sch', 'uuid-123');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('present', $response->getData());
	}//end testListingPresenceOnAnObjectTheCallerCannotReadAnswers404()

	/**
	 * A renewed beat answers the others who are present and the interval the
	 * client should beat at, and pushes nothing: a renewal changed nothing,
	 * so there is no arrival to tell anybody about.
	 *
	 * @return void
	 */
	public function testARenewedBeatAnswersTheOthersAndTheIntervalWithoutPushing(): void {
		$this->signedIn();
		$this->objectIsReadable();
		$this->presence->method('heartbeat')->willReturn(['arrived' => false]);
		$this->presence->method('present')->willReturn([['userId' => 'bob']]);

		$response = $this->controller->presenceBeat('reg', 'sch', 'uuid-123');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['userId' => 'bob']], $response->getData()['present']);
		$this->assertSame(PresenceService::BEAT_SECONDS, $response->getData()['beatSeconds']);
	}//end testARenewedBeatAnswersTheOthersAndTheIntervalWithoutPushing()

	/**
	 * Departing when the caller was not there changes nothing and still
	 * answers who is left. A page unmounting twice is ordinary.
	 *
	 * @return void
	 */
	public function testDepartingWhenNotPresentStillAnswersWhoIsLeft(): void {
		$this->signedIn();
		$this->presence->method('depart')->willReturn(false);
		$this->presence->method('present')->willReturn([['userId' => 'bob']]);

		$response = $this->controller->presenceDepart('reg', 'sch', 'uuid-123');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['userId' => 'bob']], $response->getData()['present']);
	}//end testDepartingWhenNotPresentStillAnswersWhoIsLeft()

	/**
	 * The list answers everybody except the caller: a reader asking who else
	 * has the record open does not need telling about themselves.
	 *
	 * @return void
	 */
	public function testTheListExcludesTheCaller(): void {
		$this->signedIn();
		$this->objectIsReadable();
		$this->presence->expects($this->once())
			->method('present')
			->with('uuid-123', self::CALLER)
			->willReturn([['userId' => 'bob']]);

		$response = $this->controller->presenceList('reg', 'sch', 'uuid-123');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['userId' => 'bob']], $response->getData()['present']);
	}//end testTheListExcludesTheCaller()
}//end class
