<?php

/**
 * Where the OAuth2 flow sends the browser, and where it refuses to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\OAuth2Endpoints;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The flow's own URLs come from the router; a return URL is only ever a local path.
 *
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2Endpoints
 */
final class OAuth2EndpointsTest extends TestCase {

	private const FALLBACK = 'https://cloud.example/apps/openregister/';

	private IURLGenerator&MockObject $urls;

	private OAuth2Endpoints $endpoints;

	/**
	 * A URL generator rooted at cloud.example.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => match ($route) {
				'openregister.dashboard.page' => self::FALLBACK,
				default => 'https://cloud.example/route/' . $route,
			}
		);
		$this->urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example' . $path
		);
		$this->endpoints = new OAuth2Endpoints(urlGenerator: $this->urls);
	}//end setUp()

	/**
	 * The callback, client metadata and instance URLs are the router's absolute routes.
	 *
	 * @return void
	 */
	public function testTheFlowsOwnUrlsComeFromTheRouter(): void {
		$this->assertSame('https://cloud.example/route/openregister.credentialOauth2.callback', $this->endpoints->callbackUrl());
		$this->assertSame('https://cloud.example/route/openregister.credentialOauth2.clientMetadata', $this->endpoints->clientMetadataUrl());
		$this->assertSame('https://cloud.example/', $this->endpoints->instanceUrl());
	}//end testTheFlowsOwnUrlsComeFromTheRouter()

	/**
	 * A local absolute path is kept, made absolute on this instance, query included.
	 *
	 * @return void
	 */
	public function testALocalPathIsKept(): void {
		$this->assertSame(
			'https://cloud.example/apps/openregister/settings',
			$this->endpoints->safeReturnUrl(candidate: '  /apps/openregister/settings?tab=accounts ')
		);
	}//end testALocalPathIsKept()

	/**
	 * Anything that could leave the instance, or is not a path, falls back to the app page.
	 *
	 * @return void
	 */
	public function testAnythingThatCouldLeaveTheInstanceFallsBack(): void {
		$refused = [
			'',
			'   ',
			'https://evil.example/steal',
			'//evil.example/steal',
			'javascript:alert(1)',
			'relative/path',
			'http:///',
		];

		foreach ($refused as $candidate) {
			$this->assertSame(self::FALLBACK, $this->endpoints->safeReturnUrl(candidate: $candidate), 'Refused: ' . $candidate);
		}
	}//end testAnythingThatCouldLeaveTheInstanceFallsBack()
}//end class
