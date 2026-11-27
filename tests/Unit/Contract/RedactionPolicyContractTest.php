<?php

/**
 * RedactionPolicyContractTest: what filinq relies on when it hands its lists to the engine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Command\ImportFilinqPolicyCommand;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RedactionPolicy\FilinqPolicyImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The command filinq runs, and the termRule fields each filinq field lands in.
 *
 * A rename here breaks `filinq/redaction-guarantees-from-the-engine`, which
 * runs the command and then retires its own copies of the lists.
 */
#[CoversClass(ImportFilinqPolicyCommand::class)]
#[CoversClass(FilinqPolicyImporter::class)]
class RedactionPolicyContractTest extends TestCase {

	public function testTheCommandNameTakesNoArgumentsOrOptions(): void {
		$command    = new ImportFilinqPolicyCommand(new FilinqPolicyImporter($this->createMock(ObjectOperationsInterface::class)));
		$definition = $command->getDefinition();

		$this->assertSame('openregister:redaction-policy:import-filinq', $command->getName());
		$this->assertSame([], $definition->getArguments());
		$this->assertSame([], $definition->getOptions());
	}

	public function testTheRegisterAndSchemasItReads(): void {
		$this->assertSame('filinq', FilinqPolicyImporter::FILINQ_REGISTER);
		$this->assertSame('publicationProhibition', FilinqPolicyImporter::PROHIBITION);
		$this->assertSame('publicationConsent', FilinqPolicyImporter::CONSENT);
	}

	public function testAProhibitionMapsFieldForField(): void {
		$rule = FilinqPolicyImporter::ruleFor(
			row: [
				'id'             => 'p-1',
				'primaryName'    => 'A',
				'entityType'     => 'ORGANIZATION',
				'matchRules'     => [['type' => 'kvk', 'value' => '12345678']],
				'reason'         => 'r',
				'legalAuthority' => 'la',
				'bases'          => ['5.1.1d'],
				'validFrom'      => '2026-01-01T00:00:00+00:00',
				'validUntil'     => '2027-01-01T00:00:00+00:00',
				'active'         => true,
			],
			list: 'always'
		);

		$this->assertSame(
			['list', 'entityType', 'matchRules', 'reason', 'legalAuthority', 'bases', 'validFrom', 'validUntil', 'active', 'scope', 'layer', 'source'],
			array_keys($rule)
		);
		$this->assertSame(['always', 'ORGANIZATION', 'r', 'la', 'local', 'p-1'], [$rule['list'], $rule['entityType'], $rule['reason'], $rule['legalAuthority'], $rule['layer'], $rule['source']]);
	}

	public function testAStandingConsentTakesItsAuthorityFromLegalBasisAndItsReasonFromNotes(): void {
		$rule = FilinqPolicyImporter::ruleFor(
			row: ['id' => 'c-1', 'scope' => 'entity', 'entityText' => 'B', 'legalBasis' => 'lb', 'notes' => 'n'],
			list: 'never'
		);

		$this->assertSame('never', $rule['list']);
		$this->assertSame('lb', $rule['legalAuthority']);
		$this->assertSame('n', $rule['reason']);
		$this->assertSame([['type' => 'exact', 'value' => 'B']], $rule['matchRules']);
	}
}//end class
