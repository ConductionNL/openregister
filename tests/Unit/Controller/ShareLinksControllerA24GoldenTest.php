<?php

/**
 * Golden outcomes of {@see \OCA\OpenRegister\Controller\ShareLinksController}.
 *
 * Pins every allowed and refused answer (status and body) of the file-share
 * endpoints: the 404 for an unresolvable object before any share is touched,
 * the 400 without a file, the draft handed to the service, and the mapping of
 * service failures.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ShareLinksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\ShareDraft;
use OCA\OpenRegister\Service\ShareLinkService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of the file-share endpoints.
 */
class ShareLinksControllerA24GoldenTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Share link service mock.
	 *
	 * @var ShareLinkService&MockObject
	 */
	private ShareLinkService&MockObject $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(ShareLinkService::class);
	}

	private function controller(?ObjectEntity $object): ShareLinksController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default)
		);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('getObject')->willReturn($object);

		return new ShareLinksController(
			appName: 'openregister',
			request: $request,
			shareLinkService: $this->service,
			objectService: $objects
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');

		return $object;
	}

	private function assertOutcome(int $status, mixed $body, JSONResponse $response): void {
		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}

	public function testUnresolvableObjectIsNotFoundBeforeAnyShare(): void {
		$this->service->expects($this->never())->method($this->anything());
		$this->params = ['fileId' => 4];
		$controller = $this->controller(null);
		$notFound = ['error' => 'Object not found'];

		$this->assertOutcome(404, $notFound, $controller->index('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->create('r', 's', 'x'));
		$this->assertOutcome(404, $notFound, $controller->destroy('r', 's', 'x', '9'));
		$this->assertOutcome(404, $notFound, $controller->files('r', 's', 'x'));
	}

	public function testIndexAndFilesListTheResults(): void {
		$this->service->method('getLinkedShares')->with('uuid-1')->willReturn([['id' => 1]]);
		$this->service->method('getShareableFiles')->with('uuid-1')->willReturn([['fileId' => 2], ['fileId' => 3]]);
		$controller = $this->controller($this->object());

		$this->assertOutcome(200, ['results' => [['id' => 1]], 'total' => 1], $controller->index('r', 's', 'x'));
		$this->assertOutcome(200, ['results' => [['fileId' => 2], ['fileId' => 3]], 'total' => 2], $controller->files('r', 's', 'x'));
	}

	public function testCreateWithoutFileIsABadRequest(): void {
		$this->service->expects($this->never())->method('createShare');

		$this->assertOutcome(400, ['error' => 'fileId is required'], $this->controller($this->object())->create('r', 's', 'x'));
	}

	public function testCreateHandsTheFullDraftOver(): void {
		$this->params = ['fileId' => '4', 'shareType' => '3', 'permissions' => '19', 'shareWith' => 7, 'password' => 99, 'expiration' => 2030];
		$expected = (new ShareDraft(fileId: 4, shareType: 3))
			->withRecipient(shareWith: '7', permissions: 19)
			->withProtection(password: '99', expiration: '2030');
		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn('12');
		$share->method('getShareType')->willReturn(3);
		$share->method('getToken')->willReturn(null);
		$this->service->expects($this->once())->method('createShare')
			->with('uuid-1', $this->equalTo($expected))->willReturn($share);

		$this->assertOutcome(201, ['shareId' => '12', 'shareType' => 3, 'token' => ''], $this->controller($this->object())->create('r', 's', 'x'));
	}

	public function testCreateDefaultsTheOptionalFields(): void {
		$this->params = ['fileId' => 4];
		$expected = (new ShareDraft(fileId: 4, shareType: -1))
			->withRecipient(shareWith: null, permissions: 1)
			->withProtection(password: null, expiration: null);
		$share = $this->createMock(IShare::class);
		$share->method('getId')->willReturn('12');
		$share->method('getShareType')->willReturn(-1);
		$share->method('getToken')->willReturn('tok');
		$this->service->expects($this->once())->method('createShare')
			->with('uuid-1', $this->equalTo($expected))->willReturn($share);

		$this->assertOutcome(201, ['shareId' => '12', 'shareType' => -1, 'token' => 'tok'], $this->controller($this->object())->create('r', 's', 'x'));
	}

	public function testDestroyOutcomes(): void {
		$this->service->method('revokeShare')->with('uuid-1', '9')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new Exception('Not your share', 403)),
			$this->throwException(new Exception('Odd', 418))
		);
		$controller = $this->controller($this->object());

		$this->assertOutcome(200, ['success' => true], $controller->destroy('r', 's', 'x', '9'));
		$this->assertOutcome(403, ['error' => 'Not your share'], $controller->destroy('r', 's', 'x', '9'));
		$this->assertOutcome(400, ['error' => 'Odd'], $controller->destroy('r', 's', 'x', '9'));
	}
}
