<?php

/**
 * Characterisation of the install, publish, discover and fetch actions.
 *
 * Pins every branch of the four actions (gate, request validation, stored
 * credential, branch and visibility defaults, and the status each service
 * failure maps to) so their bodies can be split into named steps without
 * changing a response.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FederatedConfigController;
use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Service\Config\ConfigPublishTarget;
use OCA\OpenRegister\Service\Config\FederatedConfigAccess;
use OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FederatedConfigControllerFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Every branch of install, publish, discover and fetch.
 *
 * @covers \OCA\OpenRegister\Controller\FederatedConfigController
 * @uses   \OCA\OpenRegister\Controller\Support\ControllerSession
 */
class FederatedConfigControllerCharacterisationTest extends TestCase {

	/**
	 * The federation engine, mocked.
	 *
	 * @var FederatedConfigServiceInterface&MockObject
	 */
	private FederatedConfigServiceInterface&MockObject $service;

	/**
	 * The publish/install gate, mocked.
	 *
	 * @var FederatedConfigAccess&MockObject
	 */
	private FederatedConfigAccess&MockObject $access;

	/**
	 * The user's stored preferences, mocked.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * The user session, mocked.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The request parameters the next action reads.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Build mocks; the controller is built per test once the parameters are set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service     = $this->createMock(FederatedConfigServiceInterface::class);
		$this->access      = $this->createMock(FederatedConfigAccess::class);
		$this->config      = $this->createMock(IConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller over a request that answers from $this->params.
	 *
	 * @return FederatedConfigController The controller under test.
	 */
	private function controller(): FederatedConfigController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default=null): mixed => array_key_exists($key, $this->params) === true ? $this->params[$key] : $default
		);

		$session = new ControllerSession($this->userSession, $this->createMock(IGroupManager::class), $this->createMock(LoggerInterface::class));

		return FederatedConfigControllerFactory::create('openregister', $request, $this->service, $this->access, $session, $this->config);
	}//end controller()

	/**
	 * A signed-in user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function signIn(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}//end signIn()

	/**
	 * Install refused by the gate is a 403 and reads nothing more.
	 *
	 * @return void
	 */
	public function testInstallRefusedByTheGate(): void {
		$this->access->method('canInstall')->willReturn(false);
		$this->service->expects($this->never())->method('install');
		$this->params = ['type' => 'flows'];

		$response = $this->controller()->install();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'You are not allowed to install shared configuration.'], $response->getData());
	}//end testInstallRefusedByTheGate()

	/**
	 * Install without a type is a 400.
	 *
	 * @return void
	 */
	public function testInstallWithoutAType(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->service->expects($this->never())->method('install');
		$this->params = ['type' => '  '];

		$response = $this->controller()->install();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'An install needs a type.'], $response->getData());
	}//end testInstallWithoutAType()

	/**
	 * A successful install passes the trimmed type, the bundle and the trimmed source.
	 *
	 * @return void
	 */
	public function testInstallPassesTheRequestThrough(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->params = ['type' => ' flows ', 'bundle' => ['a' => 1], 'source' => ' org/repo '];
		$this->service->expects($this->once())
			->method('install')
			->with('flows', ['a' => 1], 'org/repo')
			->willReturn(['installed' => 2]);

		$response = $this->controller()->install();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['installed' => 2], $response->getData());
	}//end testInstallPassesTheRequestThrough()

	/**
	 * An unknown type is a 404 with the service's message.
	 *
	 * @return void
	 */
	public function testInstallOfAnUnknownTypeIsNotFound(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->params = ['type' => 'nope'];
		$this->service->method('install')->willThrowException(new UnexpectedValueException('Unknown type nope.'));

		$response = $this->controller()->install();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Unknown type nope.'], $response->getData());
	}//end testInstallOfAnUnknownTypeIsNotFound()

	/**
	 * Each trust failure marker turns an install failure into a 403.
	 *
	 * @return array<string, array{0: string}> The messages.
	 */
	public static function forbiddenInstallMessages(): array {
		return [
			'allowlist'         => ['Source is not on the allowlist.'],
			'trusted'           => ['Key is not trusted.'],
			'signature'         => ['Bad signature.'],
			'crossOrganisation' => ['Refused (crossOrganisation: true).'],
		];
	}//end forbiddenInstallMessages()

	/**
	 * A trust failure is a 403 carrying the message.
	 *
	 * @param string $message The failure message.
	 *
	 * @dataProvider forbiddenInstallMessages
	 *
	 * @return void
	 */
	public function testInstallTrustFailuresAreForbidden(string $message): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->params = ['type' => 'flows'];
		$this->service->method('install')->willThrowException(new RuntimeException($message));

		$response = $this->controller()->install();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => $message], $response->getData());
	}//end testInstallTrustFailuresAreForbidden()

	/**
	 * Any other install failure is a 500 carrying the message.
	 *
	 * @return void
	 */
	public function testInstallOtherFailuresAreServerErrors(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->params = ['type' => 'flows'];
		$this->service->method('install')->willThrowException(new RuntimeException('Disk full.'));

		$response = $this->controller()->install();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Disk full.'], $response->getData());
	}//end testInstallOtherFailuresAreServerErrors()

	/**
	 * Publish refused by the gate is a 403 and reads no credential.
	 *
	 * @return void
	 */
	public function testPublishRefusedByTheGate(): void {
		$this->signIn('alice');
		$this->access->method('canPublish')->willReturn(false);
		$this->config->expects($this->never())->method('getUserValue');
		$this->service->expects($this->never())->method('publish');
		$this->params = ['type' => 'flows', 'repo' => 'o/r', 'path' => 'p.json'];

		$response = $this->controller()->publish();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'You are not allowed to publish configuration.'], $response->getData());
	}//end testPublishRefusedByTheGate()

	/**
	 * A publish missing any of type, repo or path is a 400 and reads no credential.
	 *
	 * @return array<string, array{0: array<string, string>}> The incomplete requests.
	 */
	public static function incompletePublishes(): array {
		return [
			'no type' => [['type' => ' ', 'repo' => 'o/r', 'path' => 'p.json']],
			'no repo' => [['type' => 'flows', 'repo' => '', 'path' => 'p.json']],
			'no path' => [['type' => 'flows', 'repo' => 'o/r']],
		];
	}//end incompletePublishes()

	/**
	 * An incomplete publish is a 400.
	 *
	 * @param array<string, string> $params The request.
	 *
	 * @dataProvider incompletePublishes
	 *
	 * @return void
	 */
	public function testAnIncompletePublishIsABadRequest(array $params): void {
		$this->signIn('alice');
		$this->access->method('canPublish')->willReturn(true);
		$this->config->expects($this->never())->method('getUserValue');
		$this->service->expects($this->never())->method('publish');
		$this->params = $params;

		$response = $this->controller()->publish();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'A publish needs a type, repo and path.'], $response->getData());
	}//end testAnIncompletePublishIsABadRequest()

	/**
	 * A publish without a selected credential is a 400.
	 *
	 * @return void
	 */
	public function testPublishWithoutACredential(): void {
		$this->signIn('alice');
		$this->access->method('canPublish')->willReturn(true);
		$this->config->expects($this->once())
			->method('getUserValue')
			->with('alice', 'openregister', 'pref_federated-config-credential', '')
			->willReturn('');
		$this->service->expects($this->never())->method('publish');
		$this->params = ['type' => 'flows', 'repo' => 'o/r', 'path' => 'p.json'];

		$response = $this->controller()->publish();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['error' => 'No store credential selected. Choose a GitHub credential in the Configuration store settings.'],
			$response->getData()
		);
	}//end testPublishWithoutACredential()

	/**
	 * A publish with no branch goes to main, public by default.
	 *
	 * @return void
	 */
	public function testPublishDefaultsToMainAndPublic(): void {
		$this->signIn('alice');
		$this->access->method('canPublish')->willReturn(true);
		$this->config->method('getUserValue')->willReturn('cred-1');
		$this->params = ['type' => ' flows ', 'repo' => ' o/r ', 'path' => ' p.json ', 'branch' => '  ', 'selection' => ['x']];
		$this->service->expects($this->once())
			->method('publish')
			->with(
				'flows',
				['x'],
				$this->callback(
					fn (ConfigPublishTarget $t): bool => $t->repo === 'o/r' && $t->path === 'p.json' && $t->branch === 'main' && $t->private === false
				),
				'cred-1'
			)
			->willReturn(['published' => true]);

		$response = $this->controller()->publish();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['published' => true], $response->getData());
	}//end testPublishDefaultsToMainAndPublic()

	/**
	 * A publish with a branch and private visibility passes both on.
	 *
	 * @return void
	 */
	public function testPublishToABranchPrivately(): void {
		$this->signIn('alice');
		$this->access->method('canPublish')->willReturn(true);
		$this->config->method('getUserValue')->willReturn('cred-1');
		$this->params = ['type' => 'flows', 'repo' => 'o/r', 'path' => 'p.json', 'branch' => ' dev ', 'visibility' => ' private '];
		$this->service->expects($this->once())
			->method('publish')
			->with(
				'flows',
				[],
				$this->callback(fn (ConfigPublishTarget $t): bool => $t->branch === 'dev' && $t->private === true),
				'cred-1'
			)
			->willReturn(['published' => true]);

		$this->assertSame(200, $this->controller()->publish()->getStatus());
	}//end testPublishToABranchPrivately()

	/**
	 * An unknown type on publish is a 404; any other failure a 500.
	 *
	 * @return void
	 */
	public function testPublishFailures(): void {
		$this->signIn('alice');
		$this->access->method('canPublish')->willReturn(true);
		$this->config->method('getUserValue')->willReturn('cred-1');
		$this->params = ['type' => 'flows', 'repo' => 'o/r', 'path' => 'p.json'];
		$this->service->method('publish')->willThrowException(new UnexpectedValueException('Unknown type flows.'));

		$response = $this->controller()->publish();
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Unknown type flows.'], $response->getData());

		$this->setUp();
		$this->signIn('alice');
		$this->access->method('canPublish')->willReturn(true);
		$this->config->method('getUserValue')->willReturn('cred-1');
		$this->service->method('publish')->willThrowException(new RuntimeException('Signature: allowlist trusted.'));

		$response = $this->controller()->publish();
		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Signature: allowlist trusted.'], $response->getData());
	}//end testPublishFailures()

	/**
	 * Discover and fetch run anonymously when nobody is signed in.
	 *
	 * @return void
	 */
	public function testDiscoverAndFetchWithoutAUserAreAnonymous(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);
		$this->config->expects($this->never())->method('getUserValue');
		$this->params = ['topic' => ' t ', 'repo' => ' o/r ', 'path' => ' b.json '];
		$this->service->expects($this->once())->method('discover')->with('t', null)->willReturn([]);
		$this->service->expects($this->once())->method('fetchBundle')->with('o/r', 'b.json', null)->willReturn(['k' => 'v']);

		$controller = $this->controller();

		$this->assertSame(['results' => []], $controller->discover()->getData());
		$this->assertSame(['bundle' => ['k' => 'v']], $controller->fetch()->getData());
	}//end testDiscoverAndFetchWithoutAUserAreAnonymous()

	/**
	 * Discover and fetch use the stored credential when the user chose one.
	 *
	 * @return void
	 */
	public function testDiscoverAndFetchUseTheStoredCredential(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->signIn('bob');
		$this->config->method('getUserValue')
			->with('bob', 'openregister', 'pref_federated-config-credential', '')
			->willReturn('cred-9');
		$this->params = ['topic' => 't', 'repo' => 'o/r', 'path' => 'b.json'];
		$this->service->expects($this->once())->method('discover')->with('t', 'cred-9')->willReturn([]);
		$this->service->expects($this->once())->method('fetchBundle')->with('o/r', 'b.json', 'cred-9')->willReturn([]);

		$controller = $this->controller();
		$controller->discover();
		$controller->fetch();
	}//end testDiscoverAndFetchUseTheStoredCredential()

	/**
	 * A signed-in user without a stored credential searches and fetches anonymously.
	 *
	 * @return void
	 */
	public function testDiscoverAndFetchWithAnEmptyStoredCredential(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->signIn('bob');
		$this->config->method('getUserValue')->willReturn('');
		$this->params = ['topic' => 't', 'repo' => 'o/r', 'path' => 'b.json'];
		$this->service->expects($this->once())->method('discover')->with('t', null)->willReturn([]);
		$this->service->expects($this->once())->method('fetchBundle')->with('o/r', 'b.json', null)->willReturn([]);

		$controller = $this->controller();
		$controller->discover();
		$controller->fetch();
	}//end testDiscoverAndFetchWithAnEmptyStoredCredential()

	/**
	 * A fetch missing a repo or a path is a 400; a fetch failure is a 404.
	 *
	 * @return void
	 */
	public function testFetchValidationAndFailure(): void {
		$this->access->method('canInstall')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);
		$this->params = ['repo' => 'o/r', 'path' => ' '];
		$response = $this->controller()->fetch();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'A fetch needs a repo and a path.'], $response->getData());

		$this->params = ['repo' => '', 'path' => 'b.json'];
		$this->assertSame(400, $this->controller()->fetch()->getStatus());

		$this->params = ['repo' => 'o/r', 'path' => 'b.json'];
		$this->service->method('fetchBundle')->willThrowException(new RuntimeException('Gone.'));
		$response = $this->controller()->fetch();
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Gone.'], $response->getData());
	}//end testFetchValidationAndFailure()
}//end class
