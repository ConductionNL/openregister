<?php

/**
 * Unit tests for stamping the accepted purpose onto an audit row.
 *
 * Covers the spec delta's "the audit entry names the purpose" and the
 * countability the report needs: the sealed copy inside the canonical JSON,
 * the indexed column beside it, and the disagreement check that makes the
 * column's unsealed status something you can test rather than merely admit.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Service\Audit\PurposeAttribution;
use OCA\OpenRegister\Service\Audit\PurposeContext;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class PurposeAttributionTest extends TestCase {
	private function context(?string $code): PurposeContext {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);

		$context = new PurposeContext($request);
		if ($code === null) {
			return $context;
		}

		$purpose = new ProcessingPurpose();
		$purpose->setCode($code);
		$purpose->setUuid('purpose-uuid');

		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('activity-uuid');
		$activity->setCode('VA-01');

		$context->accept($purpose, $activity);

		return $context;
	}//end context()

	private function attribution(?PurposeContext $context): PurposeAttribution {
		$container = $this->createMock(ContainerInterface::class);
		if ($context === null) {
			$container->method('get')->willThrowException(new \RuntimeException('not registered'));

			return new PurposeAttribution($container);
		}

		$container->method('get')->willReturn($context);

		return new PurposeAttribution($container);
	}//end attribution()

	public function testTheAcceptedPurposeIsWrittenSealedAndIndexed(): void {
		$entry = new AuditTrail();
		$this->attribution($this->context('brp-adresonderzoek'))->apply($entry);

		// The indexed projection, which is what a count per purpose groups on.
		self::assertSame('brp-adresonderzoek', $entry->getPurpose());

		// The sealed copy, inside resultSummary and therefore inside the
		// canonical JSON the hash chain covers.
		$summary = $entry->getResultSummary();
		self::assertSame('brp-adresonderzoek', $summary['purpose']['code']);
		self::assertSame('VA-01', $summary['purpose']['activity']);
		self::assertSame('activity-uuid', $summary['purpose']['activityUuid']);

		// The sealed copy really is in the serialized form the canonicaliser
		// reads. If it ever stops being, the purpose stops being tamper-evident.
		self::assertArrayHasKey('resultSummary', $entry->jsonSerialize());
		self::assertSame(
			'brp-adresonderzoek',
			$entry->jsonSerialize()['resultSummary']['purpose']['code']
		);
	}//end testTheAcceptedPurposeIsWrittenSealedAndIndexed()

	public function testThePurposeColumnStaysOutOfTheCanonicalForm(): void {
		// Adding a key to jsonSerialize() changes the canonical form of every
		// row ever written and invalidates the whole chain. This test is the
		// tripwire on that, not a restatement of the implementation.
		$entry = new AuditTrail();
		$this->attribution($this->context('brp-adresonderzoek'))->apply($entry);

		self::assertArrayNotHasKey('purpose', $entry->jsonSerialize());
	}//end testThePurposeColumnStaysOutOfTheCanonicalForm()

	public function testAnExistingResultSummaryIsMergedIntoNotReplaced(): void {
		// An MCP tool invocation already carries its outcome here, and a read
		// made inside one must not erase it.
		$entry = new AuditTrail();
		$entry->setResultSummary(['count' => 3, 'isError' => false]);

		$this->attribution($this->context('brp-adresonderzoek'))->apply($entry);

		$summary = $entry->getResultSummary();
		self::assertSame(3, $summary['count']);
		self::assertFalse($summary['isError']);
		self::assertSame('brp-adresonderzoek', $summary['purpose']['code']);
	}//end testAnExistingResultSummaryIsMergedIntoNotReplaced()

	public function testAWriteThatAlreadyNamedItsActivityKeepsIt(): void {
		$entry = new AuditTrail();
		$entry->setProcessingActivityId('activity-from-the-schema-annotation');

		$this->attribution($this->context('brp-adresonderzoek'))->apply($entry);

		self::assertSame('activity-from-the-schema-annotation', $entry->getProcessingActivityId());
	}//end testAWriteThatAlreadyNamedItsActivityKeepsIt()

	public function testARowWithNoActivityOfItsOwnTakesThePurposes(): void {
		$entry = new AuditTrail();
		$this->attribution($this->context('brp-adresonderzoek'))->apply($entry);

		self::assertSame('activity-uuid', $entry->getProcessingActivityId());
	}//end testARowWithNoActivityOfItsOwnTakesThePurposes()

	public function testARowWrittenWithNoAcceptedPurposeIsLeftAlone(): void {
		$entry = new AuditTrail();
		$this->attribution($this->context(null))->apply($entry);

		self::assertNull($entry->getPurpose());
		self::assertNull($entry->getResultSummary());
	}//end testARowWrittenWithNoAcceptedPurposeIsLeftAlone()

	public function testAnUnresolvableContextLeavesTheRowUnattributedRatherThanFailing(): void {
		// An audit row is evidence and must survive a bookkeeping problem.
		$entry = new AuditTrail();
		$this->attribution(null)->apply($entry);

		self::assertNull($entry->getPurpose());
	}//end testAnUnresolvableContextLeavesTheRowUnattributedRatherThanFailing()

	public function testAnEditedPurposeColumnDisagreesWithTheSealedCopy(): void {
		$entry = new AuditTrail();
		$this->attribution($this->context('brp-adresonderzoek'))->apply($entry);

		self::assertFalse(PurposeAttribution::disagrees($entry));

		// The column is outside the hash, so it CAN be edited without breaking
		// verification. This is what makes such an edit visible.
		$entry->setPurpose('something-else');
		self::assertTrue(PurposeAttribution::disagrees($entry));
	}//end testAnEditedPurposeColumnDisagreesWithTheSealedCopy()
}//end class
