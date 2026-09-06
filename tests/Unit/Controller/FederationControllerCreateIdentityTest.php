<?php

/**
 * FederationController::createObject() identity tests.
 *
 * A federated share grants its holder the right to ADD objects to the shared
 * register/schema. This pins that it grants nothing more.
 *
 * The path is `#[PublicPage]`, `#[NoCSRFRequired]` and calls `saveObject()`
 * with `_rbac: false` and `_multitenancy: false`, so nothing downstream will
 * refuse a write it lets through. `saveObject()` resolves its target FROM the
 * payload — `extractUuidAndNormalizeObject()` reads `@self.id` first, then
 * `id` — and the write is PUT-semantic, so every field the payload omits is
 * NULLED rather than left alone.
 *
 * The organisation pin already guarded the sibling half of this ("a federated
 * writer can never plant an object into another organisation"), but it merged
 * with `+`, which preserves the LEFT operand's keys, so a caller-supplied
 * `@self: {id: …}` survived it untouched.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FederationController;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A federated create may not address an existing object.
 */
class FederationControllerCreateIdentityTest extends TestCase {
	/**
	 * The share mapper.
	 *
	 * @var FederatedShareMapper&MockObject
	 */
	private $shareMapper;

	/**
	 * The object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The payload the controller handed to saveObject.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $written = null;

	/**
	 * Build the controller over a request carrying the given body.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return FederationController The controller.
	 */
	private function controller(array $params): FederationController {
		$share = new FederatedShare();
		$share->setDirection('outgoing');
		$share->setStatus('accepted');
		$share->setScope('collection');
		$share->setRegister('zaken');
		$share->setSchema('zaak');
		$share->setOrganisation('org-a');
		$share->setPermissions('read-write');

		$this->shareMapper = $this->createMock(FederatedShareMapper::class);
		$this->shareMapper->method('findByToken')->willReturn($share);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				// saveObject is called with named arguments; the object is the
				// first, however PHPUnit hands it back.
				$this->written = (array)($args[0] ?? []);
				return $this->createMock(ObjectEntity::class);
			}
		);

		return new FederationController(
			'openregister',
			$request,
			$this->shareMapper,
			$this->objectService,
			$this->createMock(FederationShareService::class),
			$this->createMock(IThrottler::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * 🔴 A caller-supplied `@self.id` must not reach the write.
	 *
	 * It is the key `saveObject()` reads FIRST, and this path disables RBAC, so
	 * nothing downstream would have refused the resulting update.
	 *
	 * @return void
	 */
	public function testASuppliedSelfIdDoesNotReachTheWrite(): void {
		$controller = $this->controller(
			[
				'title' => 'Federated addition',
				'@self' => ['id' => 'someone-elses-object'],
			]
		);

		$response = $controller->createObject(shareToken: 'tok');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertIsArray($this->written, 'the write must have happened');
		$this->assertArrayNotHasKey('id', $this->written['@self'] ?? []);
		$this->assertArrayNotHasKey('uuid', $this->written['@self'] ?? []);
	}//end testASuppliedSelfIdDoesNotReachTheWrite()

	/**
	 * A top-level `id` or `uuid` must not reach the write either.
	 *
	 * `@self.id` is read first, but `id` is the fallback, so stripping only the
	 * nested one would leave the same hole one key over.
	 *
	 * @return void
	 */
	public function testASuppliedTopLevelIdDoesNotReachTheWrite(): void {
		$controller = $this->controller(
			[
				'title' => 'Federated addition',
				'id' => 'someone-elses-object',
				'uuid' => 'someone-elses-object',
			]
		);

		$controller->createObject(shareToken: 'tok');

		$this->assertIsArray($this->written);
		$this->assertArrayNotHasKey('id', $this->written);
		$this->assertArrayNotHasKey('uuid', $this->written);
	}//end testASuppliedTopLevelIdDoesNotReachTheWrite()

	/**
	 * The organisation pin still holds, and the payload still arrives.
	 *
	 * The guard must not have traded one defect for another: stripping too much
	 * would break federated writes outright, and dropping the pin would let a
	 * writer plant an object into another organisation.
	 *
	 * @return void
	 */
	public function testTheOrganisationPinAndThePayloadSurvive(): void {
		$controller = $this->controller(
			[
				'title' => 'Federated addition',
				'@self' => ['id' => 'someone-elses-object', 'organisation' => 'org-attacker'],
			]
		);

		$controller->createObject(shareToken: 'tok');

		$this->assertIsArray($this->written);
		$this->assertSame('org-a', $this->written['@self']['organisation'] ?? null);
		$this->assertSame('Federated addition', $this->written['title'] ?? null);
	}//end testTheOrganisationPinAndThePayloadSurvive()
}//end class
