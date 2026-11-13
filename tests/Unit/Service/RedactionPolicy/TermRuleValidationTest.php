<?php

/**
 * The shipped termRule schema refuses a match type other than the four.
 *
 * Objects are validated against their schema on save, so the schema is where
 * a `regex` match type is refused at write time (REQ-RPD-001).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TermRuleValidationTest extends TestCase {

	private function isValid(array $termRule): bool {
		$descriptor = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/lib/Settings/redaction_policy_register.json'));
		$schema     = $descriptor->components->schemas->termRule;

		return (new Validator())->validate(json_decode((string) json_encode($termRule)), $schema)->isValid();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function allowedTypes(): array {
		return ['exact' => ['exact'], 'normalized' => ['normalized'], 'bsn' => ['bsn'], 'kvk' => ['kvk']];
	}

	#[DataProvider('allowedTypes')]
	public function testTheFourMatchTypesAreAccepted(string $type): void {
		$this->assertTrue($this->isValid(['list' => 'always', 'matchRules' => [['type' => $type, 'value' => 'x']]]));
	}

	public function testRegexIsRefusedAsAMatchType(): void {
		$this->assertFalse($this->isValid(['list' => 'always', 'matchRules' => [['type' => 'regex', 'value' => '.*']]]));
	}

	public function testARuleWithoutMatchRulesOrWithAnUnknownListIsRefused(): void {
		$this->assertFalse($this->isValid(['list' => 'always', 'matchRules' => []]));
		$this->assertFalse($this->isValid(['list' => 'sometimes', 'matchRules' => [['type' => 'exact', 'value' => 'x']]]));
	}
}
