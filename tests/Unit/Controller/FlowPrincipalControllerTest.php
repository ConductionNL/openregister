<?php

/**
 * Which kinds of principal an editor may offer.
 *
 * 🔑 THE SERVER DECIDES WHAT IS VALID, NOT THE EDITOR. An editor that offered
 * only what it can SEARCH would silently refuse a position, a function or a
 * case role — types contributed by apps whose search it does not know — and the
 * author would have no way to say what they meant.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FlowPrincipalController;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see FlowPrincipalController}.
 *
 * @covers \OCA\OpenRegister\Controller\FlowPrincipalController
 */
final class FlowPrincipalControllerTest extends TestCase {

	/**
	 * The endpoint answers with what THIS instance can resolve.
	 *
	 * @return void
	 */
	public function testItAnswersWithTheInstancesOwnTypes(): void {
		$principals = $this->createMock(PrincipalResolverRegistry::class);
		$principals->method('types')->willReturn(['agent', 'group', 'position', 'user']);

		$controller = new FlowPrincipalController(
			'openregister',
			$this->createMock(IRequest::class),
			$principals
		);

		$response = $controller->types();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		// `position` is contributed by another app; the editor cannot search it
		// and must still be able to offer it.
		$this->assertSame(['agent', 'group', 'position', 'user'], $data['results']);
		$this->assertSame(4, $data['total']);
	}//end testItAnswersWithTheInstancesOwnTypes()

	/**
	 * An instance that resolves nothing answers with nothing, not an error.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoResolversAnswersEmpty(): void {
		$principals = $this->createMock(PrincipalResolverRegistry::class);
		$principals->method('types')->willReturn([]);

		$controller = new FlowPrincipalController(
			'openregister',
			$this->createMock(IRequest::class),
			$principals
		);

		$this->assertSame(['results' => [], 'total' => 0], $controller->types()->getData());
	}//end testAnInstanceWithNoResolversAnswersEmpty()
}//end class
