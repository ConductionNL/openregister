<?php

/**
 * CapacitySuggester: the capacity a detected person acts in, its source and the decision it suggests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Anonymisation\CapacityPolicy;
use OCA\OpenRegister\Service\Anonymisation\CapacitySuggester;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Anonymisation\CapacitySuggester
 * @covers \OCA\OpenRegister\Service\Anonymisation\CapacityPolicy
 */
class CapacitySuggesterTest extends TestCase {

	private const OFFICIALS = [
		['name' => 'P. de Vries', 'capacity' => 'public-office'],
		['name' => 'J. Bakker', 'capacity' => 'professional'],
	];

	private function suggest(string $name, string $context, array $officials=self::OFFICIALS, mixed $policy=null): array {
		return (new CapacitySuggester())->suggest($name, $context, $officials, CapacityPolicy::fromStored($policy));
	}

	public function testAnAldermanFromTheRegisterIsPublicOffice(): void {
		$this->assertSame(
			['capacity' => 'public-office', 'capacitySource' => 'officials-register', 'decision' => 'release', 'ground' => null],
			$this->suggest('p.  de VRIES', 'Hoogachtend, P. de Vries, wethouder')
		);
	}

	public function testAProfessionalIsSuggestedWithheldUnder512e(): void {
		$this->assertSame(
			['capacity' => 'professional', 'capacitySource' => 'officials-register', 'decision' => 'withhold', 'ground' => '5.1.2e'],
			$this->suggest('J. Bakker', 'behandeld door: J. Bakker')
		);
	}

	public function testRoleWordsWithoutARegister(): void {
		$this->assertSame(
			['capacity' => 'public-office', 'capacitySource' => 'role-words', 'decision' => 'release', 'ground' => null],
			$this->suggest('A. Jansen', 'A. Jansen, Burgemeester van Zuiddrecht', [])
		);
		$this->assertSame('professional', $this->suggest('K. Smit', 'Uw beleidsmedewerker K. Smit', [])['capacity']);
	}

	public function testABothRoleSentenceSuggestsWithholding(): void {
		$suggestion = $this->suggest('K. Smit', 'Behandeld door K. Smit namens de burgemeester', []);

		$this->assertSame('professional', $suggestion['capacity']);
		$this->assertSame('withhold', $suggestion['decision']);
	}

	public function testARoleWordInsideAnotherWordDoesNotCount(): void {
		$this->assertSame('unknown', $this->suggest('L. Kok', 'De wethouderszaal is gesloten, zegt L. Kok.', [])['capacity']);
		$this->assertSame('unknown', $this->suggest('T. de Boer', 'Het jeugdraadslid T. de Boer sprak in.', [])['capacity']);
	}

	public function testNoEvidenceIsUnknown(): void {
		$this->assertSame(
			['capacity' => 'unknown', 'capacitySource' => 'none', 'decision' => 'withhold', 'ground' => '5.1.2e'],
			$this->suggest('M. Visser', 'Mevrouw M. Visser diende een bezwaar in.')
		);
	}

	public function testTheRegisterWinsOverRoleWords(): void {
		$this->assertSame('officials-register', $this->suggest('J. Bakker', 'J. Bakker, wethouder')['capacitySource']);
	}

	public function testAnOfficialWithAnUnknownCapacityIsIgnored(): void {
		$this->assertSame('none', $this->suggest('Z. Zee', 'Z. Zee schreef.', [['name' => 'Z. Zee', 'capacity' => 'king']])['capacitySource']);
	}

	public function testTheShippedDefault(): void {
		$policy = CapacityPolicy::fromStored(null);

		$this->assertSame(['decision' => 'release', 'ground' => null], $policy->ruleFor('public-office'));
		foreach (['professional', 'private', 'unknown', 'anything'] as $capacity) {
			$this->assertSame(['decision' => 'withhold', 'ground' => '5.1.2e'], $policy->ruleFor($capacity), $capacity);
		}
	}

	public function testAnOrganisationPolicyIsApplied(): void {
		$policy = [
			'professional' => ['decision' => 'release'],
			'private'      => ['decision' => 'withhold'],
			'unknown'      => ['decision' => 'publish'],
			'public-office' => ['decision' => 'withhold', 'ground' => '5.1.2i'],
		];

		$this->assertSame(['capacity' => 'professional', 'capacitySource' => 'officials-register', 'decision' => 'release', 'ground' => null], $this->suggest('J. Bakker', '', self::OFFICIALS, $policy));
		$this->assertSame(['decision' => 'withhold', 'ground' => '5.1.2e'], CapacityPolicy::fromStored($policy)->ruleFor('private'));
		$this->assertSame(['decision' => 'withhold', 'ground' => '5.1.2e'], CapacityPolicy::fromStored($policy)->ruleFor('unknown'));
		$this->assertSame(['decision' => 'withhold', 'ground' => '5.1.2i'], CapacityPolicy::fromStored($policy)->ruleFor('public-office'));
	}
}
