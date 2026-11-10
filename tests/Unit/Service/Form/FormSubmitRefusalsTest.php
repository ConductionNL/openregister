<?php

/**
 * A failed write answers with a 422, 403, 404 or 503 refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCA\OpenRegister\Service\Form\FormSubmitRefusals;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which refusal each failure becomes.
 */
class FormSubmitRefusalsTest extends TestCase {

	/** @var FormPayloadFindings&MockObject */
	private FormPayloadFindings $findings;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->findings = $this->createMock(FormPayloadFindings::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function refusals(): FormSubmitRefusals {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new FormSubmitRefusals(findings: $this->findings, l10n: $l10n, logger: $this->logger);
	}//end refusals()

	/**
	 * A plan for one write.
	 *
	 * @param bool $named Whether the submit has more than one write.
	 *
	 * @return array<string, mixed>
	 */
	private function plan(bool $named = false): array {
		$schema = new Schema();
		$schema->setSlug('case');

		return ['named' => $named, 'as' => 'case', 'schema' => $schema];
	}//end plan()

	public function testARefusalAlreadyMadeIsKept(): void {
		$refusal = new FormSubmitRefusedException(message: 'no', status: 409);

		$this->assertSame($refusal, $this->refusals()->refusalFor($refusal, $this->plan()));
	}//end testARefusalAlreadyMadeIsKept()

	public function testAPayloadRefusalIs422WithTheNamedWritesFindings(): void {
		$exception = new RuntimeException('bad');
		$this->findings->method('isPayloadRefusal')->willReturn(true);
		$this->findings->expects($this->once())->method('fromThrowable')->with($exception, 'case')->willReturn([['property' => 'name']]);

		$refusal = $this->refusals()->refusalFor($exception, $this->plan(named: true));

		$this->assertSame(422, $refusal->getStatus());
		$this->assertSame([['property' => 'name']], $refusal->getFindings());
	}//end testAPayloadRefusalIs422WithTheNamedWritesFindings()

	public function testAPermissionFailureIs403(): void {
		$refusal = $this->refusals()->refusalFor(new NotAuthorizedException(), $this->plan());

		$this->assertSame(403, $refusal->getStatus());
		$this->assertSame('not-allowed', $refusal->getFindings()[0]['code']);
	}//end testAPermissionFailureIs403()

	public function testAnythingElseIsALogged503(): void {
		$this->logger->expects($this->once())->method('error')->with('[FormSubmitService] A submit could not reach its destination');

		$refusal = $this->refusals()->refusalFor(new RuntimeException('down'), $this->plan());

		$this->assertSame(503, $refusal->getStatus());
	}//end testAnythingElseIsALogged503()

	public function testAGoneDraftIs404AndOnlyANamedWriteHasALabel(): void {
		$this->assertSame(404, $this->refusals()->draftGone()->getStatus());
		$this->assertNull($this->refusals()->writeLabelOf($this->plan()));
		$this->assertSame('case', $this->refusals()->writeLabelOf($this->plan(named: true)));
	}//end testAGoneDraftIs404AndOnlyANamedWriteHasALabel()
}//end class
