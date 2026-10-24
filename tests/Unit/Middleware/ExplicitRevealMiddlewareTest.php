<?php

/**
 * Masking opens for the objects API only, with the properties `_reveal` names.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Middleware;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Middleware\ExplicitRevealMiddleware;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\ExplicitRevealMiddleware
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class ExplicitRevealMiddlewareTest extends TestCase {

	/**
	 * The middleware over a request with this `_reveal`.
	 *
	 * @param ExplicitReveal $reveal The masking state.
	 * @param string|null    $param  The `_reveal` parameter.
	 *
	 * @return ExplicitRevealMiddleware The middleware.
	 */
	private function middleware(ExplicitReveal $reveal, ?string $param): ExplicitRevealMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ($key === '_reveal' ? $param : null));

		return new ExplicitRevealMiddleware(reveal: $reveal, request: $request);
	}//end middleware()

	/**
	 * The objects API opens masking and names what to reveal.
	 *
	 * @return void
	 */
	public function testTheObjectsApiOpensMasking(): void {
		$reveal = new ExplicitReveal();
		$this->middleware($reveal, 'citizenServiceNumber')->beforeController($this->createMock(ObjectsController::class), 'show');

		$this->assertTrue($reveal->isActive());
		$this->assertTrue($reveal->isRequested(property: 'citizenServiceNumber'));
		$this->assertFalse($reveal->isRequested(property: 'displayName'));
	}//end testTheObjectsApiOpensMasking()

	/**
	 * Another controller leaves masking off.
	 *
	 * @return void
	 */
	public function testAnotherControllerLeavesMaskingOff(): void {
		$reveal = new ExplicitReveal();
		$this->middleware($reveal, null)->beforeController($this->createMock(Controller::class), 'index');

		$this->assertFalse($reveal->isActive());
	}//end testAnotherControllerLeavesMaskingOff()
}//end class
