<?php

/**
 * Golden characterisation test for the append-only refusal of a consent property.
 *
 * Pins, on the code before the append-only check was split into named steps, the
 * exact refusal for a shortened list and for a changed entry (the first changed
 * index is named, not the last), and that an untouched list with appended entries
 * is allowed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consent
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Consent;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluator;
use OCA\OpenRegister\Service\Consent\ConsentEvidenceSource;
use OCA\OpenRegister\Service\Consent\ConsentPropertyWrite;
use PHPUnit\Framework\TestCase;

final class ConsentAppendOnlyRefusalGoldenTest extends TestCase {

	private function evaluate(array $incoming, array $persisted): array {
		return (new ConsentEnvelopeEvaluator())->evaluate(
			write: new ConsentPropertyWrite(name: 'toestemming', annotation: ['purpose' => 'x'], incoming: $incoming, persisted: $persisted),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);
	}

	public function testEachRefusalCarriesItsExactMessage(): void {
		$a = ['decision' => 'granted', 'by' => 'a', 'timestamp' => 't1'];
		$b = ['decision' => 'granted', 'by' => 'b', 'timestamp' => 't2'];
		$c = ['decision' => 'withdrawn', 'by' => 'a', 'timestamp' => 't3'];

		$shortened = $this->evaluate(incoming: [$a], persisted: [$a, $b]);
		$this->assertTrue($shortened['refused']);
		$this->assertSame(
			'Property "toestemming" is append-only (x-openregister-consent): the submitted value has fewer entries than the persisted value.',
			$shortened['message']
		);

		$changed = $this->evaluate(incoming: [$a, $c, $c], persisted: [$a, $b, $b]);
		$this->assertTrue($changed['refused']);
		$this->assertSame(
			'Property "toestemming" is append-only (x-openregister-consent): entry 1 cannot be changed, only new entries may be appended.',
			$changed['message']
		);

		$this->assertFalse($this->evaluate(incoming: [$a, $b, $c], persisted: [$a, $b])['refused']);
		$this->assertFalse($this->evaluate(incoming: [], persisted: [])['refused']);
	}
}//end class
