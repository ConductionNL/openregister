<?php

/**
 * A webhook destination outside the administered outbound allowlist is refused at save.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-its-own-rate-limit-and-outbound-destinations-are-allowlisted-req-sat-005
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Outbound\OutboundAllowlist;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\WebhooksControllerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\WebhooksController
 * @uses   \OCA\OpenRegister\Service\Outbound\OutboundAllowlist
 * @uses   \OCA\OpenRegister\Controller\Support\WebhooksControllerServices
 * @uses   \OCA\OpenRegister\Controller\Support\WebhooksMappers
 * @uses   \OCA\OpenRegister\Controller\Support\TasksPlatform
 * @uses   \OCA\OpenRegister\Db\Webhook
 */
class WebhooksControllerAllowlistTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var WebhookMapper&MockObject
	 */
	private WebhookMapper&MockObject $webhookMapper;

	private function controller(string $allowlist, bool $withAllowlist = true): WebhooksController {
		$this->request       = $this->createMock(IRequest::class);
		$this->webhookMapper = $this->createMock(WebhookMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($allowlist);
		$service = null;
		if ($withAllowlist === true) {
			$service = new OutboundAllowlist(appConfig: $config);
		}

		return WebhooksControllerFactory::create(
			appName: 'openregister',
			request: $this->request,
			webhookMapper: $this->webhookMapper,
			webhookLogMapper: $this->createMock(WebhookLogMapper::class),
			webhookService: $this->createMock(WebhookService::class),
			logger: $this->createMock(LoggerInterface::class),
			userSession: $session,
			groupManager: $groups,
			allowlist: $service,
		);
	}//end controller()

	private function webhook(): Webhook {
		$webhook = new Webhook();
		$webhook->setName('Hook');
		$webhook->setUrl('https://hooks.supplier.nl/in');

		return $webhook;
	}//end webhook()

	public function testCreatingADestinationOffTheListIsRefusedNamingTheAllowlist(): void {
		$controller = $this->controller(allowlist: '["hooks.supplier.nl"]');
		$this->request->method('getParams')->willReturn(['name' => 'Hook', 'url' => 'https://evil.example/in']);
		$this->webhookMapper->expects($this->never())->method('createFromArray');

		$response = $controller->create();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			['error' => 'url: the destination host "evil.example" is not on the outbound allowlist (outboundAllowlist)', 'field' => 'url'],
			$response->getData()
		);
	}//end testCreatingADestinationOffTheListIsRefusedNamingTheAllowlist()

	public function testCreatingADestinationOnTheListIsSaved(): void {
		$controller = $this->controller(allowlist: '["hooks.supplier.nl"]');
		$this->request->method('getParams')->willReturn(['name' => 'Hook', 'url' => 'https://hooks.supplier.nl/in']);
		$this->webhookMapper->expects($this->once())->method('createFromArray')
			->with(['name' => 'Hook', 'url' => 'https://hooks.supplier.nl/in'])
			->willReturn($this->webhook());

		$this->assertSame(201, $controller->create()->getStatus());
	}//end testCreatingADestinationOnTheListIsSaved()

	public function testWithoutAnAllowlistServiceNothingIsChecked(): void {
		$controller = $this->controller(allowlist: '["hooks.supplier.nl"]', withAllowlist: false);
		$this->request->method('getParams')->willReturn(['name' => 'Hook', 'url' => 'https://evil.example/in']);
		$this->webhookMapper->expects($this->once())->method('createFromArray')->willReturn($this->webhook());

		$this->assertSame(201, $controller->create()->getStatus());
	}//end testWithoutAnAllowlistServiceNothingIsChecked()

	public function testUpdatingTheDestinationOffTheListIsRefused(): void {
		$controller = $this->controller(allowlist: '*.supplier.nl');
		$this->request->method('getParams')->willReturn(['url' => 'https://supplier.nl.evil.example/in']);
		$this->webhookMapper->expects($this->never())->method('updateFromArray');

		$response = $controller->update(id: 4);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('url', $response->getData()['field']);
	}//end testUpdatingTheDestinationOffTheListIsRefused()

	public function testAnUpdateThatLeavesTheDestinationAloneIsNotChecked(): void {
		$controller = $this->controller(allowlist: '["hooks.supplier.nl"]');
		$this->request->method('getParams')->willReturn(['id' => 4, 'name' => 'Renamed']);
		$this->webhookMapper->expects($this->once())->method('updateFromArray')
			->with(4, ['name' => 'Renamed'])
			->willReturn($this->webhook());

		$this->assertSame(200, $controller->update(id: 4)->getStatus());
	}//end testAnUpdateThatLeavesTheDestinationAloneIsNotChecked()
}//end class
