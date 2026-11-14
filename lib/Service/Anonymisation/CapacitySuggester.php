<?php

/**
 * CapacitySuggester
 *
 * Suggests the capacity a detected person acts in, and the decision it implies,
 * from an officials list and Dutch role words (REQ-DKP-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The officials list first, then role words in the person's own sentence.
 *
 * A suggestion only: a person confirms it before it counts, and an unconfirmed
 * one is anonymised (REQ-DKP-002).
 *
 * @spec openspec/changes/detection-knows-the-person/specs
 */
class CapacitySuggester {

	/**
	 * The version of the role-word list, so a run can report which list it used.
	 *
	 * @var string
	 */
	public const ROLE_WORDS_VERSION = '2026-11-1';

	/**
	 * Dutch role words per capacity, matched as whole words.
	 *
	 * Professional roles are tried before public office: when a sentence names
	 * both ("behandeld door J. Bakker namens de burgemeester") the suggestion is
	 * the one that withholds, because a wrong release cannot be taken back.
	 *
	 * @var array<string, list<string>>
	 */
	public const ROLE_WORDS = [
		CapacityPolicy::PROFESSIONAL => [
			'behandeld door', 'behandelaar', 'casemanager', 'consulent', 'beleidsmedewerker', 'beleidsadviseur',
			'adviseur', 'jurist', 'inspecteur', 'toezichthouder', 'medewerker', 'ambtenaar', 'teamleider',
			'afdelingshoofd', 'projectleider',
		],
		CapacityPolicy::PUBLIC_OFFICE => [
			'burgemeester', 'wethouder', 'raadslid', 'gemeenteraadslid', 'griffier', 'gemeentesecretaris',
			'gedeputeerde', 'statenlid', 'commissaris van de koning', 'dijkgraaf', 'heemraad',
			'minister', 'staatssecretaris', 'kamerlid',
		],
	];

	/**
	 * Suggest a capacity for a detected person.
	 *
	 * @param string                                       $name      The detected name.
	 * @param string                                       $context   The sentence or signature block it stands in.
	 * @param list<array{name: string, capacity: string}> $officials The officials source, as name and capacity.
	 * @param CapacityPolicy                               $policy    The organisation's policy.
	 *
	 * @return array{capacity: string, capacitySource: string, decision: string, ground: string|null}
	 *
	 * @spec openspec/changes/detection-knows-the-person/specs
	 */
	public function suggest(string $name, string $context, array $officials, CapacityPolicy $policy): array {
		[$capacity, $source] = (self::fromOfficials(name: $name, officials: $officials)
			?? self::fromRoleWords(context: $context)
			?? [CapacityPolicy::UNKNOWN, 'none']);

		return array_merge(['capacity' => $capacity, 'capacitySource' => $source], $policy->ruleFor(capacity: $capacity));
	}//end suggest()

	/**
	 * The capacity the officials source gives this name, or null.
	 *
	 * @param string                                       $name      The detected name.
	 * @param list<array{name: string, capacity: string}> $officials The officials.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function fromOfficials(string $name, array $officials): ?array {
		$wanted = self::normalised(text: $name);
		if ($wanted === '') {
			return null;
		}

		foreach ($officials as $official) {
			$capacity = (string) ($official['capacity'] ?? '');
			if (in_array($capacity, CapacityPolicy::CAPACITIES, true) === true && self::normalised(text: (string) ($official['name'] ?? '')) === $wanted) {
				return [$capacity, 'officials-register'];
			}
		}

		return null;
	}//end fromOfficials()

	/**
	 * The capacity a role word in the context gives, professional roles first, or null.
	 *
	 * @param string $context The sentence or signature block.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function fromRoleWords(string $context): ?array {
		foreach (self::ROLE_WORDS as $capacity => $words) {
			foreach ($words as $word) {
				if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/iu', $context) === 1) {
					return [$capacity, 'role-words'];
				}
			}
		}

		return null;
	}//end fromRoleWords()

	/**
	 * A name folded for comparison: case, surrounding space and inner runs of space.
	 *
	 * @param string $text The name.
	 *
	 * @return string
	 */
	private static function normalised(string $text): string {
		return (string) preg_replace('/\s+/u', ' ', trim(mb_convert_case($text, MB_CASE_FOLD)));
	}//end normalised()
}//end class
