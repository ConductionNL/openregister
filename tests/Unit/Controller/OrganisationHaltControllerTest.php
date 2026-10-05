<?php

/**
 * The admin routes for per-organisation halts.
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
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\OrganisationHaltController;
use OCA\OpenRegister\Service\Flow\Oversight\OrganisationHaltService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * 201 on engage, 400 with the reason on a refusal, 404 on releasing nothing.
 */
class OrganisationHaltControllerTest extends TestCase {

	/**
	 * The three routes over a service double.
	 *
	 * @return void
	 */
	public function testTheRoutesAnswerWhatTheServiceDecided(): void {
		$halts = $this->createMock(OrganisationHaltService::class);
		$halts->method('engage')->willReturnCallback(
			static function (string $organisation, string $app, string $reason, ?string $nodeTypePrefix = null): array {
				if ($reason === '') {
					throw new InvalidArgumentException('A halt carries a reason.');
				}

				return ['id' => 'h1', 'organisation' => $organisation, 'app' => $app, 'nodeTypePrefix' => ($nodeTypePrefix ?? $app . '.'), 'reason' => $reason];
			}
		);
		$halts->method('list')->willReturn([['id' => 'h1']]);
		$halts->method('release')->willReturnCallback(static fn (string $id): bool => ($id === 'h1'));

		$controller = new OrganisationHaltController(appName: 'openregister', request: $this->createMock(IRequest::class), halts: $halts);

		$created = $controller->create(uuid: 'org-a', app: 'hermiq', reason: 'incident');
		$this->assertSame(201, $created->getStatus());
		$this->assertSame('hermiq.', $created->getData()['nodeTypePrefix']);

		$refused = $controller->create(uuid: 'org-a', app: 'hermiq', reason: '');
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame('A halt carries a reason.', $refused->getData()['error']);

		$this->assertSame([['id' => 'h1']], $controller->index(uuid: 'org-a')->getData()['results']);
		$this->assertSame(200, $controller->destroy(id: 'h1')->getStatus());
		$this->assertSame(404, $controller->destroy(id: 'nope')->getStatus());
	}//end testTheRoutesAnswerWhatTheServiceDecided()
}//end class
