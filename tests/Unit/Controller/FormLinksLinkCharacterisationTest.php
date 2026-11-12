<?php

/**
 * FormLinksController link / create characterisation tests.
 *
 * Pins what linking a form (or one submission) and creating a form answer
 * and hand the form-link service on every branch, so both bodies can be
 * split into smaller units without an outcome moving.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\FormLinksController;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormSubmissionRef;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FormLinkScope;
use OCA\OpenRegister\Service\FormLinkService;
use OCA\OpenRegister\Service\NewForm;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FormLinksControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Characterisation of FormLinksController::link() and ::create().
 */
class FormLinksLinkCharacterisationTest extends TestCase {

	private IRequest&MockObject $request;

	private FormLinkService&MockObject $service;

	private ObjectService&MockObject $objectService;

	private FormLinksController $controller;

	private array $params = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$this->service = $this->createMock(FormLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = FormLinksControllerFactory::create(
			'openregister',
			$this->request,
			$this->service,
			$this->objectService,
			$l10n,
			new NullLogger()
		);
	}

	private function resolvesTo(int|string $schema): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('3');
		$object->setSchema((string)$schema);
		$this->objectService->method('getObject')->willReturn($object);
	}

	private function answer(\OCP\AppFramework\Http\JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}

	public function testAnUnresolvedObjectIs404ForBoth(): void {
		$this->objectService->method('getObject')->willReturnOnConsecutiveCalls(
			null,
			null,
			$this->throwException(new DoesNotExistException('x')),
			$this->throwException(new DoesNotExistException('x'))
		);
		$this->service->expects($this->never())->method($this->anything());

		$expected = [404, ['error' => 'Object not found']];
		$this->assertSame($expected, $this->answer($this->controller->link('r', 's', 'o')));
		$this->assertSame($expected, $this->answer($this->controller->create('r', 's', 'o')));
		$this->assertSame($expected, $this->answer($this->controller->link('r', 's', 'o')));
		$this->assertSame($expected, $this->answer($this->controller->create('r', 's', 'o')));
	}

	public function testALinkWithoutAPositiveFormIdIs400(): void {
		$this->resolvesTo(5);
		$this->service->expects($this->never())->method('linkFormTo');

		foreach ([[], ['formId' => '0'], ['formId' => '-2', 'submissionId' => '4'], ['formId' => 'abc']] as $params) {
			$this->params = $params;
			$this->assertSame([400, ['error' => 'formId is required']], $this->answer($this->controller->link('r', 's', 'o')));
		}
	}

	public function testALinkWithASubmissionLinksTheSubmission(): void {
		$this->resolvesTo(5);
		$this->params = ['formId' => '7', 'submissionId' => '11'];
		$this->service->expects($this->never())->method('linkFormTo');
		$this->service->expects($this->once())->method('linkSubmissionTo')
			->with($this->equalTo((new FormLinkScope('obj-1', 3))->inSchema(5)), $this->equalTo(new FormSubmissionRef(7, 11)))
			->willReturn($link = new FormLink());

		$this->assertSame([201, $link], $this->answer($this->controller->link('r', 's', 'o')));
	}

	public function testALinkWithoutASubmissionLinksTheFormWithoutASchemaForSchemaZero(): void {
		$this->resolvesTo(0);
		$this->params = ['formId' => 7, 'submissionId' => '0'];
		$this->service->expects($this->never())->method('linkSubmissionTo');
		$this->service->expects($this->once())->method('linkFormTo')
			->with($this->equalTo((new FormLinkScope('obj-1', 3))->inSchema(null)), 7)
			->willReturn($link = new FormLink());

		$this->assertSame([201, $link], $this->answer($this->controller->link('r', 's', 'o')));
	}

	public function testALinkFailureAnswersWithItsCodeOr400(): void {
		$this->resolvesTo(5);
		$this->params = ['formId' => '7'];
		$this->service->method('linkFormTo')->willReturnOnConsecutiveCalls(
			$this->throwException(new Exception('dup', 409)),
			$this->throwException(new Exception('bad'))
		);

		$this->assertSame([409, ['error' => 'dup']], $this->answer($this->controller->link('r', 's', 'o')));
		$this->assertSame([400, ['error' => 'bad']], $this->answer($this->controller->link('r', 's', 'o')));
	}

	public function testACreateNeedsATitle(): void {
		$this->resolvesTo(5);
		$this->params = ['title' => '   ', 'description' => 'd'];
		$this->service->expects($this->never())->method('createAndLinkForm');

		$this->assertSame([400, ['error' => 'title is required']], $this->answer($this->controller->create('r', 's', 'o')));
	}

	public function testACreateHandsTheTrimmedTitleAndDescription(): void {
		$this->resolvesTo('-1');
		$this->params = ['title' => ' Intake ', 'description' => 12];
		$this->service->expects($this->once())->method('createAndLinkForm')
			->with($this->equalTo((new FormLinkScope('obj-1', 3))->inSchema(null)), $this->equalTo(new NewForm('Intake', '12')))
			->willReturn($link = new FormLink());

		$this->assertSame([201, $link], $this->answer($this->controller->create('r', 's', 'o')));
	}

	public function testACreateFailureAnswersWithItsCodeOr400(): void {
		$this->resolvesTo(5);
		$this->params = ['title' => 'Intake'];
		$this->service->method('createAndLinkForm')->willReturnOnConsecutiveCalls(
			$this->throwException(new Exception('forms app missing', 503)),
			$this->throwException(new Exception('nope'))
		);

		$this->assertSame([503, ['error' => 'forms app missing']], $this->answer($this->controller->create('r', 's', 'o')));
		$this->assertSame([400, ['error' => 'nope']], $this->answer($this->controller->create('r', 's', 'o')));
	}
}
