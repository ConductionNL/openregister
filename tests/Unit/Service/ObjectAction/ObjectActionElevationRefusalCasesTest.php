<?php

/**
 * Every refusal of an elevated object action, each named exactly, and the
 * elevation entries read back from a definition.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevation;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Refusal cases for {@see ObjectActionElevation}.
 */
class ObjectActionElevationRefusalCasesTest extends TestCase {

	/**
	 * Definitions, authorization and the refusals they draw.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<int, string>}>
	 */
	public static function cases(): array {
		$entry = ['register' => 'cases', 'schema' => 'case', 'verbs' => ['update']];
		$grant = 'Action "act": "elevate" needs an authorization rule granting "act"; an elevated action open to everyone is a privilege escalation.';

		return [
			'elevate not a list' => [['elevate' => ['register' => 'cases']], null, ['Action "act": "elevate" must be a non-empty list of {register, schema, verbs}.']],
			'elevate empty' => [['elevate' => []], null, ['Action "act": "elevate" must be a non-empty list of {register, schema, verbs}.']],
			'async elevation' => [['elevate' => [$entry], 'mode' => 'async'], null, ['Action "act": an elevated action runs sync; a queued run would execute after its elevation has ended.']],
			'scalar entry' => [['elevate' => ['cases']], null, ['Action "act": every "elevate" entry must be {register, schema, verbs}.']],
			'wildcard schema' => [['elevate' => [['register' => 'cases', 'schema' => '*', 'verbs' => ['update']]]], null, ['Action "act": "elevate" may not use a wildcard.']],
			'wildcard verb' => [['elevate' => [['register' => 'cases', 'schema' => 'case', 'verbs' => ['*']]]], null, ['Action "act": "elevate" may not use a wildcard.']],
			'missing schema' => [['elevate' => [['register' => 'cases', 'schema' => ' ', 'verbs' => ['update']]]], null, ['Action "act": every "elevate" entry must name a register and a schema.']],
			'unknown verb' => [['elevate' => [['register' => 'cases', 'schema' => 'case', 'verbs' => ['read']]]], null, ['Action "act": "elevate" verbs may only be create, update, delete.']],
			'no verbs' => [['elevate' => [['register' => 'cases', 'schema' => 'case', 'verbs' => []]]], null, ['Action "act": "elevate" verbs may only be create, update, delete.']],
			'no authorization block' => [['elevate' => [$entry]], null, []],
			'no rule for the action' => [['elevate' => [$entry]], ['other' => ['admin']], [$grant]],
			'rule granting the public' => [['elevate' => [$entry]], ['act' => ['admin', 'public']], [$grant]],
			'rule granting authenticated by object' => [['elevate' => [$entry]], ['act' => [['group' => ' authenticated ']]], [$grant]],
			'rule granting a group' => [['elevate' => [$entry]], ['act' => [['group' => 'clerks'], 42]], []],
		];
	}//end cases()

	/**
	 * Each elevated definition draws exactly its refusals.
	 *
	 * @param array<string, mixed> $definition The action definition.
	 * @param array<string, mixed>|null $authorization The schema authorization.
	 * @param array<int, string> $expected The refusals.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testEachElevationDrawsExactlyItsRefusals(array $definition, ?array $authorization, array $expected): void {
		$this->assertSame($expected, ObjectActionElevation::refusals(action: new ObjectActionEntry(key: 'act', definition: $definition), authorization: $authorization));
	}//end testEachElevationDrawsExactlyItsRefusals()

	/**
	 * Entries read back normalised, skipping a non-object entry.
	 *
	 * @return void
	 */
	public function testEntriesReadBackNormalisedSkippingNonObjects(): void {
		$this->assertSame(
			[['register' => 'cases', 'schema' => '', 'verbs' => ['update', '1']]],
			ObjectActionElevation::entriesOf(definition: ['elevate' => ['skip', ['register' => 'cases', 'verbs' => ['update', 1]]]])
		);
	}//end testEntriesReadBackNormalisedSkippingNonObjects()
}//end class
