<?php

/**
 * What the configuration bundle endpoints answer: the listing, one bundle,
 * binding and unbinding a subject, and copying a matrix into a draft set,
 * including the status a refusal chose and the admin-only posture.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ConfigurationBundleController;
use OCA\OpenRegister\Db\ConfigurationBinding;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationBundleService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for ConfigurationBundleController.
 *
 * @covers \OCA\OpenRegister\Controller\ConfigurationBundleController
 * @uses   \OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException
 */
final class ConfigurationBundleControllerTest extends TestCase {

	/**
	 * The bundle service.
	 *
	 * @var ConfigurationBundleService&MockObject
	 */
	private ConfigurationBundleService $bundles;

	protected function setUp(): void {
		parent::setUp();
		$this->bundles = $this->createMock(ConfigurationBundleService::class);
	}//end setUp()

	/**
	 * The controller over a request answering the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ConfigurationBundleController The controller.
	 */
	private function controller(array $params = []): ConfigurationBundleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return new ConfigurationBundleController(appName: 'openregister', request: $request, bundles: $this->bundles);
	}//end controller()

	public function testEveryRouteIsAdminOnly(): void {
		foreach (['index', 'show', 'bind', 'unbind', 'copy'] as $method) {
			$doc = (string) (new ReflectionMethod(ConfigurationBundleController::class, $method))->getDocComment();
			$this->assertStringNotContainsString('@NoAdminRequired', $doc, $method.' must stay admin-only');
			$this->assertStringNotContainsString('@PublicPage', $doc, $method.' must stay admin-only');
			$this->assertSame([], (new ReflectionMethod(ConfigurationBundleController::class, $method))->getAttributes(), $method);
		}
	}//end testEveryRouteIsAdminOnly()

	public function testTheIndexListsEveryBundleWithItsTotal(): void {
		$this->bundles->method('listBundles')->willReturn([['name' => 'gemeente'], ['name' => 'provincie']]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['name' => 'gemeente'], ['name' => 'provincie']], 'total' => 2], $response->getData());
	}//end testTheIndexListsEveryBundleWithItsTotal()

	public function testShowAnswersTheValuesAndBindingsOfOneBundle(): void {
		$this->bundles->expects($this->once())->method('valuesOf')->with('gemeente')->willReturn(['a.b' => 1]);
		$this->bundles->expects($this->once())->method('bindingsOf')->with('gemeente')->willReturn([['subject' => 's-1']]);

		$response = $this->controller()->show(id: 'gemeente');

		$this->assertSame(['name' => 'gemeente', 'values' => ['a.b' => 1], 'bindings' => [['subject' => 's-1']]], $response->getData());
	}//end testShowAnswersTheValuesAndBindingsOfOneBundle()

	public function testABindingIsCreatedForASchemaByDefault(): void {
		$binding = $this->createMock(ConfigurationBinding::class);
		$binding->method('jsonSerialize')->willReturn(['bundle' => 'gemeente', 'subject' => 's-1']);
		$this->bundles->expects($this->once())->method('bind')
			->with('gemeente', 's-1', ConfigurationBinding::TYPE_SCHEMA)
			->willReturn($binding);

		$response = $this->controller(params: ['subject' => 's-1', 'subjectType' => '   '])->bind(id: 'gemeente');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['bundle' => 'gemeente', 'subject' => 's-1'], $response->getData());
	}//end testABindingIsCreatedForASchemaByDefault()

	public function testABindingNamesItsSubjectType(): void {
		$binding = $this->createMock(ConfigurationBinding::class);
		$binding->method('jsonSerialize')->willReturn([]);
		$this->bundles->expects($this->once())->method('bind')->with('gemeente', '', 'register')->willReturn($binding);

		$this->controller(params: ['subjectType' => ' register '])->bind(id: 'gemeente');
	}//end testABindingNamesItsSubjectType()

	public function testARefusedBindingAnswersTheRefusalsStatus(): void {
		$this->bundles->method('bind')->willThrowException(
			new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_INVALID,
				message: 'a binding needs a bundle and a subject',
				statusCode: Http::STATUS_BAD_REQUEST
			)
		);

		$response = $this->controller()->bind(id: 'gemeente');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'invalid', 'message' => 'a binding needs a bundle and a subject', 'refusals' => [], 'applied' => 0],
			$response->getData()
		);
	}//end testARefusedBindingAnswersTheRefusalsStatus()

	public function testUnbindingASubjectThatFollowsNoBundleIsNotFound(): void {
		$this->bundles->expects($this->once())->method('unbind')->with('s-9')->willReturn(false);

		$response = $this->controller()->unbind(id: 'gemeente', subject: 's-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'unknown', 'message' => '"s-9" follows no bundle'], $response->getData());
	}//end testUnbindingASubjectThatFollowsNoBundleIsNotFound()

	public function testUnbindingASubjectAnswersItIsNoLongerBound(): void {
		$this->bundles->method('unbind')->willReturn(true);

		$response = $this->controller()->unbind(id: 'gemeente', subject: 's-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['bundle' => 'gemeente', 'subject' => 's-1', 'bound' => false], $response->getData());
	}//end testUnbindingASubjectAnswersItIsNoLongerBound()

	public function testACopyPassesItsAddressAndAnswersADraft(): void {
		$this->bundles->expects($this->once())->method('copyMatrix')
			->with('set-1', 'mail.', 'gemeente-a', 'gemeente-b', null)
			->willReturn([['key' => 'mail.from'], ['key' => 'mail.reply']]);

		$response = $this->controller(params: ['prefix' => 'mail.', 'from' => 'gemeente-a', 'to' => 'gemeente-b'])->copy(id: 'set-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			[
				'set' => 'set-1',
				'copied' => [['key' => 'mail.from'], ['key' => 'mail.reply']],
				'total' => 2,
				'message' => 'the copy is a draft, so the live matrix is unchanged until the set is deployed',
			],
			$response->getData()
		);
	}//end testACopyPassesItsAddressAndAnswersADraft()

	public function testACopyNamesItsLayer(): void {
		$this->bundles->expects($this->once())->method('copyMatrix')->with('set-1', '', '', '', 'organisation')->willReturn([]);

		$this->controller(params: ['layer' => 'organisation'])->copy(id: 'set-1');
	}//end testACopyNamesItsLayer()

	public function testARefusedCopyAnswersTheRefusalsStatus(): void {
		$this->bundles->method('copyMatrix')->willThrowException(
			new DeploymentRefusedException(reason: DeploymentRefusedException::REASON_EMPTY, message: 'nothing to copy', refusals: ['x'])
		);

		$response = $this->controller()->copy(id: 'set-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'empty', 'message' => 'nothing to copy', 'refusals' => ['x'], 'applied' => 0], $response->getData());
	}//end testARefusedCopyAnswersTheRefusalsStatus()
}//end class
