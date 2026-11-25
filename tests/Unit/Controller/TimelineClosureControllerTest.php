<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\TimelineClosureController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryClosureService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelinePermissionException;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineValidationException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * `/api/objects/{register}/{schema}/{id}/entries-closure`: an object the caller may not read is a 404
 * before anything else, closing answers the closure, and the service's refusals keep their status.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class TimelineClosureControllerTest extends TestCase {

	private const CLOSURE = ['by' => 'behandelaar', 'at' => '2026-11-25T10:00:00+00:00', 'reason' => 'beroep ingesteld'];

	/** @var array<string, mixed> */
	private array $params = [];

	private function controller(bool $readable, EntryClosureService $closures): TimelineClosureController {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('setObject')->willReturnCallback(
			function () use ($readable, $objects): ObjectOperationsInterface {
				if ($readable === false) {
					throw new RuntimeException('not readable');
				}

				return $objects;
			}
		);
		$objects->method('getObject')->willReturn($object);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $name, mixed $default = null): mixed => ($this->params[$name] ?? $default));

		return new TimelineClosureController(request: $request, objects: $objects, closures: $closures, logger: new NullLogger());
	}

	public function testAnUnreadableObjectIs404AndNothingIsAsked(): void {
		$closures = $this->createMock(EntryClosureService::class);
		$closures->expects($this->never())->method($this->anything());

		foreach (['show', 'close', 'reopen'] as $action) {
			$response = $this->controller(readable: false, closures: $closures)->$action('zaken', 'zaak', 'case-1');
			$this->assertSame(404, $response->getStatus(), $action);
		}
	}

	public function testAnOpenRecordReadsAsOpen(): void {
		$closures = $this->createMock(EntryClosureService::class);
		$closures->method('closureOf')->with('case-1')->willReturn(null);

		$response = $this->controller(readable: true, closures: $closures)->show('zaken', 'zaak', 'case-1');

		$this->assertSame([200, ['closed' => false, 'closure' => null]], [$response->getStatus(), $response->getData()]);
	}

	public function testClosingAnswersTheClosure(): void {
		$this->params = ['reason' => 'beroep ingesteld'];
		$closures = $this->createMock(EntryClosureService::class);
		$closures->expects($this->once())->method('close')
			->with($this->callback(fn (ObjectEntity $object): bool => $object->getUuid() === 'case-1'), 'beroep ingesteld')
			->willReturn(self::CLOSURE);

		$response = $this->controller(readable: true, closures: $closures)->close('zaken', 'zaak', 'case-1');

		$this->assertSame([200, ['closed' => true, 'closure' => self::CLOSURE]], [$response->getStatus(), $response->getData()]);
	}

	public function testAReasonThatIsNotTextIsNoReason(): void {
		$this->params = ['reason' => ['x']];
		$closures = $this->createMock(EntryClosureService::class);
		$closures->expects($this->once())->method('close')->with($this->anything(), '')
			->willThrowException(new TimelineValidationException(['reason' => 'Closing a record to new entries needs a reason']));

		$response = $this->controller(readable: true, closures: $closures)->close('zaken', 'zaak', 'case-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['reason' => 'Closing a record to new entries needs a reason'], $response->getData()['errors']);
	}

	public function testACallerWhoMayNotManageGets403OnCloseAndReopen(): void {
		$closures = $this->createMock(EntryClosureService::class);
		$closures->method('close')->willThrowException(new TimelinePermissionException('no'));
		$closures->method('reopen')->willThrowException(new TimelinePermissionException('no'));

		$this->assertSame(403, $this->controller(readable: true, closures: $closures)->close('zaken', 'zaak', 'case-1')->getStatus());
		$this->assertSame(403, $this->controller(readable: true, closures: $closures)->reopen('zaken', 'zaak', 'case-1')->getStatus());
	}

	public function testReopeningAnswersAnOpenRecord(): void {
		$closures = $this->createMock(EntryClosureService::class);
		$closures->expects($this->once())->method('reopen')->willReturn(true);

		$response = $this->controller(readable: true, closures: $closures)->reopen('zaken', 'zaak', 'case-1');

		$this->assertSame([200, ['closed' => false, 'closure' => null]], [$response->getStatus(), $response->getData()]);
	}

	public function testAnythingElseIsAGeneric500(): void {
		$this->params = ['reason' => 'r'];
		$closures = $this->createMock(EntryClosureService::class);
		$closures->method('close')->willThrowException(new RuntimeException('SQLSTATE secret'));

		$response = $this->controller(readable: true, closures: $closures)->close('zaken', 'zaak', 'case-1');

		$this->assertSame([500, ['message' => 'The closure could not be changed']], [$response->getStatus(), $response->getData()]);
	}
}
