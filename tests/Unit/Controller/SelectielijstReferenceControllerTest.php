<?php

/**
 * GET /api/archival/selectielijst/resolve.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SelectielijstReferenceController;
use OCA\OpenRegister\Service\Archival\SelectielijstReferenceResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * SelectielijstReferenceController.
 */
class SelectielijstReferenceControllerTest extends TestCase {

	/**
	 * The controller for a request carrying the given url.
	 *
	 * @param string     $url      The url parameter.
	 * @param array|null $resolved What the resolver answers.
	 *
	 * @return SelectielijstReferenceController
	 */
	private function controller(string $url, ?array $resolved): SelectielijstReferenceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($url);
		$resolver = $this->createMock(SelectielijstReferenceResolver::class);
		$resolver->method('isResolvable')->willReturn(str_starts_with($url, 'https://selectielijst.openzaak.nl/'));
		$resolver->method('resolve')->willReturn($resolved);

		return new SelectielijstReferenceController('openregister', $request, $resolver);
	}//end controller()

	/**
	 * A resolvable URL answers its code and name; others are refused or not found.
	 *
	 * @return void
	 */
	public function testAnswersRefusesAndNotFound(): void {
		$url = 'https://selectielijst.openzaak.nl/api/v1/resultaten/3fc7002b-31b6-4abe-82af-b6d46a9a1736';
		$ok  = $this->controller(url: $url, resolved: ['url' => $url, 'code' => '6.1', 'name' => 'Ingewilligd'])->resolve();
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame('6.1', $ok->getData()['code']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(url: 'https://example.org/x', resolved: null)->resolve()->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(url: $url, resolved: null)->resolve()->getStatus());
	}//end testAnswersRefusesAndNotFound()
}//end class
