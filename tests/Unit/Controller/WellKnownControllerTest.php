<?php

/**
 * WellKnownControllerTest — the contract test for the discovery paths
 * (gate 25).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WellKnownController;
use OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\WellKnownController
 */
class WellKnownControllerTest extends TestCase {

	/**
	 * Build the controller over administered values.
	 *
	 * @param array<string, string> $values The administered configuration.
	 *
	 * @return WellKnownController The controller.
	 */
	private function controller(array $values = []): WellKnownController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => ('https://gemeente.test' . $path)
		);

		return new WellKnownController(
			'openregister',
			$this->createMock(IRequest::class),
			new SecurityTxtBuilder($appConfig),
			$urlGenerator,
		);
	}//end controller()

	public function testAResponsibleDisclosureContactIsServedAsPlainText(): void {
		$response = $this->controller(
			[SecurityTxtBuilder::CONTACT_KEY => 'security@gemeente.test']
		)->securityTxt();

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			'text/plain; charset=utf-8',
			$response->getHeaders()['Content-Type'],
			'Served as anything else, a researcher pointing a standard tool at it reads nothing.'
		);
		$this->assertStringContainsString('Contact: mailto:security@gemeente.test', $response->render());
	}//end testAResponsibleDisclosureContactIsServedAsPlainText()

	public function testAnUnadministeredInstanceAnswersNotFound(): void {
		$response = $this->controller()->securityTxt();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$response->getStatus(),
			'A placeholder contact reads as a working channel and swallows the report.'
		);
	}//end testAnUnadministeredInstanceAnswersNotFound()

	public function testTheIndexNamesTheServedPathsAndTheRewrite(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame(
			'https://gemeente.test/apps/openregister/.well-known/security.txt',
			$data['paths']['security.txt']
		);
		$this->assertStringContainsString(
			'/.well-known/security.txt',
			$data['note'],
			'The instruction belongs where somebody looking at the problem will be.'
		);
	}//end testTheIndexNamesTheServedPathsAndTheRewrite()
}//end class
