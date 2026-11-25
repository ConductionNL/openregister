<?php

/**
 * ObjectTransferController: the preview and transfer routes.
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
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-an-object-graph-moves-between-organisations-under-a-per-type-policy-req-sle-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectTransferController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Organisation\ObjectGraphTransfer;
use OCA\OpenRegister\Service\Organisation\ObjectGraphTransferRefusal;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for the transfer routes.
 */
#[CoversClass(ObjectTransferController::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectGraphTransferRefusal::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions::class)]
#[UsesClass(\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget::class)]
class ObjectTransferControllerTest extends TestCase {

	/**
	 * The controller over a request body, a read path and a transfer.
	 *
	 * @param array<string, mixed>      $body     The request parameters.
	 * @param ObjectOperationsInterface $objects  The read path.
	 * @param ObjectGraphTransfer       $transfer The transfer.
	 *
	 * @return ObjectTransferController
	 */
	private function controller(array $body, ObjectOperationsInterface $objects, ObjectGraphTransfer $transfer): ObjectTransferController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $body[$key] ?? $default);

		return new ObjectTransferController(
			request: $request,
			objects: $objects,
			transfer: $transfer,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A read path answering the given root.
	 *
	 * @param ObjectEntity|null $root The root, or null for unreadable.
	 *
	 * @return ObjectOperationsInterface
	 */
	private function objects(?ObjectEntity $root): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturn($root);
		return $objects;
	}//end objects()

	/**
	 * The preview passes the body through and answers the plan.
	 *
	 * @return void
	 */
	public function testThePreviewAnswersThePlan(): void {
		$root = new ObjectEntity();
		$transfer = $this->createMock(ObjectGraphTransfer::class);
		$transfer->expects($this->once())->method('preview')->with($root, 'org-b', ['10' => 'move', '11' => ''], 2)->willReturn(['digest' => 'abc']);

		$response = $this->controller(
			body: ['targetOrganisation' => 'org-b', 'policies' => ['10' => 'move', '11' => ['nested']], 'depth' => '2'],
			objects: $this->objects(root: $root),
			transfer: $transfer
		)->preview('zaken', 'zaak', 'case-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['digest' => 'abc'], $response->getData());
	}

	/**
	 * The transfer sends the approval along; a refusal answers its own status and message.
	 *
	 * @return void
	 */
	public function testTheTransferSendsTheApprovalAndMapsARefusal(): void {
		$root = new ObjectEntity();
		$transfer = $this->createMock(ObjectGraphTransfer::class);
		$transfer->expects($this->once())->method('transfer')->with($root, 'org-b', [], 'digest-1', 1)
			->willThrowException(new ObjectGraphTransferRefusal('The graph or the policies changed since the approved preview', 409));

		$response = $this->controller(
			body: ['targetOrganisation' => 'org-b', 'policies' => 'not-an-object', 'approval' => 'digest-1'],
			objects: $this->objects(root: $root),
			transfer: $transfer
		)->run('zaken', 'zaak', 'case-1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['message' => 'The graph or the policies changed since the approved preview'], $response->getData());
	}

	/**
	 * A write failure answers 500 without its message; an unreadable root is 404 before any transfer.
	 *
	 * @return void
	 */
	public function testAFailureIs500AndAnUnreadableRootIs404(): void {
		$failing = $this->createMock(ObjectGraphTransfer::class);
		$failing->method('transfer')->willThrowException(new RuntimeException('secret table name'));
		$response = $this->controller(body: [], objects: $this->objects(root: new ObjectEntity()), transfer: $failing)->run('zaken', 'zaak', 'case-1');
		$this->assertSame(500, $response->getStatus());
		$this->assertStringNotContainsString('secret', (string)json_encode($response->getData()));

		$untouched = $this->createMock(ObjectGraphTransfer::class);
		$untouched->expects($this->never())->method('preview');
		$untouched->expects($this->never())->method('transfer');
		$this->assertSame(404, $this->controller(body: [], objects: $this->objects(root: null), transfer: $untouched)->preview('zaken', 'zaak', 'x')->getStatus());
		$this->assertSame(404, $this->controller(body: [], objects: $this->objects(root: null), transfer: $untouched)->run('zaken', 'zaak', 'x')->getStatus());
	}

	/**
	 * Both routes are administrator-only: neither carries NoAdminRequired.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdministratorOnly(): void {
		foreach (['preview', 'run'] as $method) {
			$attributes = (new ReflectionMethod(ObjectTransferController::class, $method))->getAttributes();
			$names = array_map(static fn ($attribute): string => $attribute->getName(), $attributes);
			$this->assertNotContains(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class, $names, $method);
		}
	}
}
