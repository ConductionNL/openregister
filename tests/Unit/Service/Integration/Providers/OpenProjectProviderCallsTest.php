<?php

/**
 * Unit tests for OpenProjectProvider's metadata and router calls.
 *
 * Pins the descriptor methods, the enable check, the router probe and the
 * get/create/update/delete calls (method, encoded path, context query or
 * body, headers) the provider sends through the external router.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-openproject/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\Providers\ConnectorRouting;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OpenProjectProvider's metadata and router calls.
 */
class OpenProjectProviderCallsTest extends TestCase {

	private ExternalIntegrationRouter&MockObject $router;

	private IAppManager&MockObject $appManager;

	private OpenProjectProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->router     = $this->createMock(ExternalIntegrationRouter::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->provider = new OpenProjectProvider(new ConnectorRouting($this->router, $this->appManager, $l10n));
	}//end setUp()

	public function testMetadata(): void {
		$this->assertSame('openproject', $this->provider->getId());
		$this->assertSame('Projects', $this->provider->getLabel());
		$this->assertSame('external', $this->provider->getGroup());
		$this->assertSame('integriq', $this->provider->getRequiredApp());
		$this->assertSame('external', $this->provider->getStorageStrategy());
		$this->assertSame('openproject', $this->provider->getOpenConnectorSource());
	}//end testMetadata()

	public function testIsEnabledFollowsTheConnectorInstall(): void {
		$this->appManager->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $id === 'openconnector');
		$this->assertTrue($this->provider->isEnabled());
	}//end testIsEnabledFollowsTheConnectorInstall()

	public function testIsDisabledWithoutTheConnector(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->assertFalse($this->provider->isEnabled());
	}//end testIsDisabledWithoutTheConnector()

	public function testHealthIsTheRouterProbe(): void {
		$probe = ['status' => 'ok', 'authStatus' => 'ok', 'message' => null];
		$this->router->expects($this->once())->method('probe')->with($this->provider)->willReturn($probe);
		$this->assertSame($probe, $this->provider->health());
	}//end testHealthIsTheRouterProbe()

	public function testGetSendsTheEncodedIdWithTheContextQuery(): void {
		$this->router->expects($this->once())->method('call')
			->with(
				$this->provider,
				new UpstreamRequest(
					method: 'GET',
					path: rawurlencode('12/3'),
					options: ['query' => ['register' => 'r', 'schema' => 's', 'object' => 'o'], 'headers' => ['Accept' => 'application/json']]
				)
			)
			->willReturn(['id' => '12', 'subject' => 'Fix']);
		$row = $this->provider->get(new ObjectRef(register: 'r', schema: 's', id: 'o'), '12/3');
		$this->assertSame('12', $row['id']);
		$this->assertSame('Fix', $row['title']);
	}//end testGetSendsTheEncodedIdWithTheContextQuery()

	public function testCreatePostsThePayloadWithTheContext(): void {
		$this->router->expects($this->once())->method('call')
			->with(
				$this->provider,
				new UpstreamRequest(
					method: 'POST',
					path: '',
					options: [
						'body' => ['subject' => 'New', 'register' => 'r', 'schema' => 's', 'object' => 'o'],
						'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
					]
				)
			)
			->willReturn(['id' => '7', 'subject' => 'New']);
		$this->assertSame('7', $this->provider->create(new ObjectRef(register: 'r', schema: 's', id: 'o'), ['subject' => 'New', 'object' => 'x'])['id']);
	}//end testCreatePostsThePayloadWithTheContext()

	public function testUpdatePutsThePayloadToTheEncodedId(): void {
		$this->router->expects($this->once())->method('call')
			->with(
				$this->provider,
				new UpstreamRequest(
					method: 'PUT',
					path: rawurlencode('a b'),
					options: [
						'body' => ['status' => 'done', 'register' => 'r', 'schema' => 's', 'object' => 'o'],
						'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
					]
				)
			)
			->willReturn(['id' => 'a b']);
		$this->assertSame('a b', $this->provider->update(new ObjectRef(register: 'r', schema: 's', id: 'o'), 'a b', ['status' => 'done'])['id']);
	}//end testUpdatePutsThePayloadToTheEncodedId()

	public function testDeleteSendsTheContextQuery(): void {
		$this->router->expects($this->once())->method('call')
			->with(
				$this->provider,
				new UpstreamRequest(
					method: 'DELETE',
					path: rawurlencode('9'),
					options: ['query' => ['register' => 'r', 'schema' => 's', 'object' => 'o'], 'headers' => ['Accept' => 'application/json']]
				)
			)
			->willReturn([]);
		$this->provider->delete(new ObjectRef(register: 'r', schema: 's', id: 'o'), '9');
	}//end testDeleteSendsTheContextQuery()
}//end class
