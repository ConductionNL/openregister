<?php

/**
 * Behaviour test for ErasurePreviewController: preview, approve and run an erasure.
 *
 * Asserts that a preview needs a subject, which type, mode and request id are
 * passed on (blank ones dropped), and that every refusal from the store or the
 * runner answers with its own status and body instead of erasing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ErasurePreviewController;
use OCA\OpenRegister\Controller\Support\ErasurePreviewControllerServices;
use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewServiceInterface;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasurePreviewStoreInterface;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRefusedException;
use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureRunnerInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ErasurePreviewControllerBehaviourTest extends TestCase {

	/** @var ErasurePreviewServiceInterface&MockObject The preview builder. */
	private ErasurePreviewServiceInterface $previews;

	/** @var ErasurePreviewStoreInterface&MockObject The preview store. */
	private ErasurePreviewStoreInterface $store;

	/** @var ErasureRunnerInterface&MockObject The erasure runner. */
	private ErasureRunnerInterface $runner;

	protected function setUp(): void {
		$this->previews = $this->createMock(ErasurePreviewServiceInterface::class);
		$this->store = $this->createMock(ErasurePreviewStoreInterface::class);
		$this->runner = $this->createMock(ErasureRunnerInterface::class);
	}

	private function controller(array $params = []): ErasurePreviewController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => $params[$key] ?? null);
		return new ErasurePreviewController($request, new ErasurePreviewControllerServices($this->previews, $this->store, $this->runner));
	}

	private function record(string $uuid, string $status = 'pending'): ErasurePreview {
		$record = new ErasurePreview();
		$record->setUuid($uuid);
		$record->setStatus($status);
		return $record;
	}

	private function refusal(): ErasureRefusedException {
		return new ErasureRefusedException('not-approved', 'The preview is not approved', ['preview' => 'p1']);
	}

	public function testABlankSubjectIsRefused(): void {
		$this->previews->expects($this->never())->method('preview');
		$response = $this->controller(['subject' => '   '])->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'subject is required'], $response->getData());
	}

	public function testCreateDefaultsToPseudonymiseWithoutTypeOrRequest(): void {
		$this->previews->expects($this->once())->method('preview')->with(new \OCA\OpenRegister\Service\Gdpr\SubjectQuery('alice'), 'pseudonymise')->willReturn(['objects' => 1]);
		$this->store->expects($this->once())->method('record')->with(['objects' => 1], null)->willReturn($this->record('p1'));

		$data = $this->controller(['subject' => ' alice ', 'type' => '  ', 'request' => ''])->create()->getData();
		$this->assertSame('p1', $data['uuid']);
		$this->assertSame('pending', $data['status']);
	}

	public function testCreatePassesTypeModeAndRequestTrimmed(): void {
		$this->previews->expects($this->once())->method('preview')->with(new \OCA\OpenRegister\Service\Gdpr\SubjectQuery('alice', 'email'), 'delete')->willReturn([]);
		$this->store->expects($this->once())->method('record')->with([], 'dsr-9')->willReturn($this->record('p2'));
		$params = ['subject' => 'alice', 'type' => ' email ', 'eraseMode' => 'delete', 'request' => ' dsr-9 '];
		$this->assertSame('p2', $this->controller($params)->create()->getData()['uuid']);
	}

	public function testShowReturnsTheStoredPreview(): void {
		$this->store->method('load')->with('p1')->willReturn($this->record('p1'));
		$this->assertSame('p1', $this->controller()->show('p1')->getData()['uuid']);
	}

	public function testShowAnswersTheRefusal(): void {
		$this->store->method('load')->willThrowException($this->refusal());
		$response = $this->controller()->show('p1');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			['error' => 'ERASURE_REFUSED', 'rule' => 'not-approved', 'message' => 'The preview is not approved', 'preview' => 'p1'],
			$response->getData()
		);
	}

	public function testApproveReturnsTheApprovedPreview(): void {
		$this->store->expects($this->once())->method('approve')->with('p1')->willReturn($this->record('p1', 'approved'));
		$this->assertSame('approved', $this->controller()->approve('p1')->getData()['status']);
	}

	public function testApproveAnswersTheRefusal(): void {
		$this->store->method('approve')->willThrowException($this->refusal());
		$this->assertSame(Http::STATUS_CONFLICT, $this->controller()->approve('p1')->getStatus());
	}

	public function testRunErasesOnlyARunnablePreview(): void {
		$record = $this->record('p1', 'approved');
		$this->store->expects($this->once())->method('requireRunnable')->with('p1')->willReturn($record);
		$this->runner->expects($this->once())->method('run')->with($record)->willReturn(['erased' => 3]);
		$this->assertSame(['erased' => 3], $this->controller()->run('p1')->getData());
	}

	public function testRunDoesNotEraseWhenThePreviewIsNotRunnable(): void {
		$this->store->method('requireRunnable')->willThrowException($this->refusal());
		$this->runner->expects($this->never())->method('run');
		$response = $this->controller()->run('p1');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('not-approved', $response->getData()['rule']);
	}

	public function testRunAnswersARefusalFromTheRunner(): void {
		$this->store->method('requireRunnable')->willReturn($this->record('p1', 'approved'));
		$this->runner->method('run')->willThrowException(new ErasureRefusedException('digest-changed', 'Data changed since the preview'));
		$response = $this->controller()->run('p1');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('Data changed since the preview', $response->getData()['message']);
	}
}
