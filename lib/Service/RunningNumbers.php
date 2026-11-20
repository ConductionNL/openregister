<?php
/**
 * RunningNumbers: the instance-wide counters object save asks for, on the sequence service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Kernel\Contracts\Core\RunningNumbersInterface;

/**
 * Answers the Core running-number port with the per-scope sequence counters.
 *
 * An instance-wide counter is the sequence scope with register 0 and schema 0,
 * exactly the scope generated identifiers have always used.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
 */
final class RunningNumbers implements RunningNumbersInterface {

	/**
	 * Constructor.
	 *
	 * @param SequenceServiceInterface $sequences The per-scope counters.
	 */
	public function __construct(
		private readonly SequenceServiceInterface $sequences,
	) {
	}//end __construct()

	/**
	 * Reserve the next number of an instance-wide scope.
	 *
	 * @param string $scopeKey The scope key.
	 *
	 * @return int The reserved number.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
	 */
	public function reserveNext(string $scopeKey): int {
		return $this->sequences->reserveNext(self::instanceWide(scopeKey: $scopeKey));
	}//end reserveNext()

	/**
	 * Move an instance-wide scope's counter past a supplied number.
	 *
	 * @param string $scopeKey The scope key.
	 * @param int    $reserved The number now taken.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
	 */
	public function advanceTo(string $scopeKey, int $reserved): void {
		$this->sequences->advanceTo(self::instanceWide(scopeKey: $scopeKey), reserved: $reserved);
	}//end advanceTo()

	/**
	 * The sequence scope of an instance-wide counter.
	 *
	 * @param string $scopeKey The scope key.
	 *
	 * @return SequenceScope Register 0, schema 0, this key.
	 */
	private static function instanceWide(string $scopeKey): SequenceScope {
		return new SequenceScope(registerId: 0, schemaId: 0, scopeKey: $scopeKey);
	}//end instanceWide()
}//end class
