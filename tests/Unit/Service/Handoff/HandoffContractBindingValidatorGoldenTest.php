<?php

/**
 * Golden test for {@see \OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator}.
 *
 * Pins the exact error list `validate()` returns, and the verdict of
 * `isCompleteBinding()`, over every branch: absent and malformed blocks,
 * unimplemented and unknown kinds, empty maps, foreign contract fields,
 * missing and non-string own properties, omitted mandatory fields, and each
 * source of implemented types (`implements`, `jsonld.type`, `x-schema-org`).
 * Written against the code before its refactor and left unchanged by it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidator;
use PHPUnit\Framework\TestCase;

/**
 * @no-named-arguments
 */
class HandoffContractBindingValidatorGoldenTest extends TestCase {

	private const NS = 'https://openregister.app/ns#';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function properties(): array {
		return [
			'onderwerp' => ['type' => 'string'],
			'samenvatting' => ['type' => 'string'],
			'kanaal' => ['type' => 'string'],
			'bron' => ['type' => 'string'],
			'prio' => ['type' => 'string'],
		];
	}//end properties()

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$case = self::NS.'Case';
		$full = ['title' => 'onderwerp', 'summary' => 'samenvatting', 'channel' => 'kanaal', 'source' => 'bron'];

		return [
			'no block' => ['properties' => self::properties()],
			'scalar block' => ['handoffContract' => 'nope'],
			'complete' => ['properties' => self::properties(), 'implements' => [$case], 'handoffContract' => [$case => $full]],
			'not implemented' => ['properties' => self::properties(), 'handoffContract' => [$case => $full]],
			'unknown kind' => ['implements' => ['urn:x', '', 7], 'handoffContract' => ['urn:x' => $full]],
			'empty map' => ['implements' => [$case], 'handoffContract' => [$case => []]],
			'scalar map' => ['implements' => [$case], 'handoffContract' => [$case => 'onderwerp']],
			'foreign and bad targets' => [
				'properties' => self::properties(),
				'implements' => [$case],
				'handoffContract' => [$case => ['title' => 'onderwerp', 'colour' => 'bron', 'summary' => 'ontbreekt', 'channel' => 12, 'priority' => null]],
			],
			'properties not an array' => ['properties' => 'x', 'implements' => [$case], 'handoffContract' => [$case => $full]],
			'jsonld type' => ['properties' => self::properties(), 'jsonld' => ['type' => $case], 'handoffContract' => [$case => $full, self::NS.'Quote' => []]],
			'jsonld ignored when implements set' => [
				'properties' => self::properties(),
				'implements' => [self::NS.'Quote'],
				'jsonld' => ['type' => $case],
				'handoffContract' => [$case => $full, self::NS.'Quote' => ['title' => 'onderwerp']],
			],
			'schema-org prefix' => ['x-schema-org' => 'schema:Invoice', 'handoffContract' => ['https://schema.org/Invoice' => []]],
			'schema-org full uri' => ['x-schema-org' => $case, 'implements' => [$case, $case], 'properties' => self::properties(), 'handoffContract' => [$case => $full]],
			'numeric kind key' => ['implements' => ['5'], 'handoffContract' => [5 => $full]],
		];
	}//end schemas()

	/**
	 * @return void
	 */
	public function testValidateErrors(): void {
		$validator = new HandoffContractBindingValidator();
		$actual = [];
		foreach (self::schemas() as $name => $schema) {
			$actual[$name] = $validator->validate($schema);
		}

		$this->assertSame(self::expectedErrors(), $actual);
	}//end testValidateErrors()

	/**
	 * @return void
	 */
	public function testIsCompleteBinding(): void {
		$case = self::NS.'Case';
		$full = ['title' => 'onderwerp', 'summary' => 'samenvatting', 'channel' => 'kanaal', 'source' => 'bron'];
		$props = self::properties();
		$cases = [
			[$case, [$case => $full], $props],
			[$case, [$case => $full + ['priority' => 'prio']], $props],
			[$case, [$case => $full + ['colour' => 'prio']], $props],
			[$case, [$case => $full + ['priority' => 'ontbreekt']], $props],
			[$case, [$case => $full + ['priority' => 3]], $props],
			[$case, [$case => ['title' => 'onderwerp']], $props],
			[$case, [$case => array_merge($full, ['source' => 'ontbreekt'])], $props],
			[$case, [$case => []], $props],
			[$case, [$case => 'x'], $props],
			[$case, [], $props],
			[$case, [$case => $full], []],
			['urn:unknown', ['urn:unknown' => ['title' => 'onderwerp']], $props],
			[self::NS.'Invoice', [self::NS.'Invoice' => ['counterparty' => 'onderwerp', 'currency' => 'kanaal', 'totalAmount' => 'prio', 'source' => 'bron']], $props],
		];

		$actual = [];
		foreach ($cases as [$kind, $binding, $properties]) {
			$actual[] = HandoffContractBindingValidator::isCompleteBinding($kind, $binding, $properties);
		}

		$this->assertSame([true, true, false, false, false, false, false, false, false, false, false, false, true], $actual);
	}//end testIsCompleteBinding()

	/**
	 * @return array<string, array<int, array{code: string, message: string}>>
	 */
	private static function expectedErrors(): array {
		return [
			'no block' => [],
			'scalar block' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract must be an object mapping kind URIs to {contractField: ownProperty} bindings.',
				],
			],
			'complete' => [],
			'not implemented' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract binds kind "https://openregister.app/ns#Case" which the schema does not implement (declare it in `implements`).',
				],
			],
			'unknown kind' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract binds unknown kind "urn:x" (known contract kinds: https://openregister.app/ns#Case, https://openregister.app/ns#Quote, https://openregister.app/ns#Contract, https://openregister.app/ns#Invoice).',
				],
			],
			'empty map' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"] must be a non-empty {contractField: ownProperty} map; missing mandatory fields: title, summary, channel, source.',
				],
			],
			'scalar map' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"] must be a non-empty {contractField: ownProperty} map; missing mandatory fields: title, summary, channel, source.',
				],
			],
			'foreign and bad targets' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"] binds "colour" which is not a contract field (fields: title, summary, channel, source, requester, priority, caseType).',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"]["summary"] must name an existing own property; "ontbreekt" is not declared in `properties`.',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"]["channel"] must name an existing own property; "integer" is not declared in `properties`.',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"]["priority"] must name an existing own property; "NULL" is not declared in `properties`.',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"] omits mandatory contract field(s): source.',
				],
			],
			'properties not an array' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"]["title"] must name an existing own property; "onderwerp" is not declared in `properties`.',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"]["summary"] must name an existing own property; "samenvatting" is not declared in `properties`.',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"]["channel"] must name an existing own property; "kanaal" is not declared in `properties`.',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Case"]["source"] must name an existing own property; "bron" is not declared in `properties`.',
				],
			],
			'jsonld type' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract binds kind "https://openregister.app/ns#Quote" which the schema does not implement (declare it in `implements`).',
				],
			],
			'jsonld ignored when implements set' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract binds kind "https://openregister.app/ns#Case" which the schema does not implement (declare it in `implements`).',
				],
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract["https://openregister.app/ns#Quote"] omits mandatory contract field(s): counterparty, currency, totalAmount, source.',
				],
			],
			'schema-org prefix' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract binds unknown kind "https://schema.org/Invoice" (known contract kinds: https://openregister.app/ns#Case, https://openregister.app/ns#Quote, https://openregister.app/ns#Contract, https://openregister.app/ns#Invoice).',
				],
			],
			'schema-org full uri' => [],
			'numeric kind key' => [
				[
					'code' => 'handoff-contract-incomplete',
					'message' => 'handoffContract binds unknown kind "5" (known contract kinds: https://openregister.app/ns#Case, https://openregister.app/ns#Quote, https://openregister.app/ns#Contract, https://openregister.app/ns#Invoice).',
				],
			],
		];
	}//end expectedErrors()
}//end class
