<?php

/**
 * Anonymising a record: what it loses, and what it keeps saying afterwards.
 *
 * 🔴 THIS IS IRREVERSIBLE AND IT IS MEANT TO BE. Destroying a record leaves a
 * destruction log saying it was here. Anonymising leaves the record and takes
 * the person out of it, from the payload, from everything derived from it, and
 * from the values stored on its own audit trail. That last one is the single
 * place the rule "history is preserved" gives way, and it gives way on purpose:
 * a trail that still holds the old name re-identifies the record the name was
 * removed from, which makes the anonymisation a gesture.
 *
 * WHAT SURVIVES IS THE FACT, NOT THE VALUES. The trail keeps that this record
 * was anonymised, when, by whom, under which profile, and which properties were
 * touched. It does not keep what they said. An auditor can therefore prove the
 * act happened and prove which fields it covered, and cannot recover the
 * citizen from it. That is the trade, stated rather than discovered.
 *
 * 🔴 ALL OR NOTHING. If any derived copy cannot be reached, the whole act
 * fails and the record is left as it was. A half-anonymised record is the worst
 * available outcome: the payload no longer names the person, so every screen
 * reports it as anonymised, while the search index still answers a query for
 * their name. Nobody would look again.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;

/**
 * Applies a declared anonymisation profile to one record's properties.
 *
 * The treatments are pure: they take values and return values, so every one of
 * them is testable without a database, and the join-preserving pseudonym can be
 * asserted on two rows in one test.
 */
class AnonymisationService {

	/**
	 * What a removed property leaves behind in the report.
	 *
	 * @var string
	 */
	public const REMOVED = '__removed__';

	/**
	 * Apply a profile to a payload.
	 *
	 * @param array<string, mixed>                $payload The record's properties.
	 * @param array<string, array<string, mixed>> $profile Property name to its rule.
	 * @param string                              $salt    Instance salt for the pseudonym.
	 *
	 * @return array<string, mixed> The anonymised payload.
	 */
	public function apply(array $payload, array $profile, string $salt): array {
		$result = $payload;

		foreach ($profile as $property => $rule) {
			if (array_key_exists($property, $result) === false) {
				continue;
			}

			$treatment = ($rule['treatment'] ?? null);
			if ($treatment === AnonymisationProfile::REMOVE) {
				unset($result[$property]);
				continue;
			}

			$result[$property] = $this->treat(
				treatment: (string)$treatment,
				value: $result[$property],
				rule: $rule,
				salt: $salt
			);
		}

		return $result;
	}//end apply()

	/**
	 * The report: what changed, and what was deliberately left in.
	 *
	 * Both lists, always. A report that names only what it removed invites the
	 * reader to assume the rest was not personal, and the rest is where a
	 * re-identification comes from: a date of birth, a postcode and a case
	 * number identify most people without a name anywhere in sight.
	 *
	 * @param array<string, mixed> $before  The payload as it was.
	 * @param array<string, mixed> $after   The payload as it is now.
	 * @param string               $profileName What the profile was called.
	 *
	 * @return array<string, mixed> The report.
	 */
	public function report(array $before, array $after, string $profileName = ''): array {
		$changed = [];
		$kept = [];

		foreach ($before as $property => $value) {
			$name = (string)$property;
			if (array_key_exists($name, $after) === false) {
				$changed[$name] = self::REMOVED;
				continue;
			}

			if ($after[$name] !== $value) {
				$changed[$name] = $after[$name];
				continue;
			}

			$kept[] = $name;
		}

		ksort($changed);
		sort($kept);

		return [
			'profile' => $profileName,
			'anonymisedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'changed' => $changed,
			'kept' => $kept,
			'keptCount' => count($kept),
		];
	}//end report()

	/**
	 * A stable pseudonym for one value.
	 *
	 * 🔴 STABLE ACROSS ROWS, WHICH IS THE POINT AND THE RISK. The same value in
	 * two records becomes the same token, so a municipality can still count how
	 * many cases one person had without knowing who they were. That property is
	 * exactly what makes it correlatable: the token joins, and an attacker
	 * holding the salt and a guess at the original value can confirm the guess.
	 * The salt is instance-wide and secret for that reason, and `fixed` is the
	 * treatment to choose when joining is not needed.
	 *
	 * @param string $value The original value.
	 * @param string $salt  The instance salt.
	 *
	 * @return string The token.
	 */
	public function pseudonymFor(string $value, string $salt): string {
		return 'anon-'.substr(hash('sha256', $salt.'::'.mb_strtolower(trim($value))), 0, 16);
	}//end pseudonymFor()

	/**
	 * One treatment on one value.
	 *
	 * @param string               $treatment The treatment name.
	 * @param mixed                $value     The current value.
	 * @param array<string, mixed> $rule      The declared rule.
	 * @param string               $salt      The instance salt.
	 *
	 * @return mixed The treated value.
	 */
	private function treat(string $treatment, mixed $value, array $rule, string $salt): mixed {
		if ($treatment === AnonymisationProfile::FIXED) {
			return ($rule['value'] ?? null);
		}

		if ($treatment === AnonymisationProfile::PSEUDONYM) {
			$text = '';
			if (is_scalar($value) === true) {
				$text = (string)$value;
			}

			return $this->pseudonymFor(value: $text, salt: $salt);
		}

		if ($treatment === AnonymisationProfile::GENERALISE) {
			return $this->generalise(value: $value, grain: (string)($rule['grain'] ?? ''));
		}

		return $value;
	}//end treat()

	/**
	 * Coarsen one value to the declared grain.
	 *
	 * An unparseable value becomes null rather than staying as it was. Leaving
	 * the original in place because it could not be coarsened is the silent
	 * pass-through this whole change exists to remove: the report would say
	 * generalised and the record would hold the exact date.
	 *
	 * @param mixed  $value The current value.
	 * @param string $grain The declared grain.
	 *
	 * @return string|null The coarser value, or null.
	 */
	private function generalise(mixed $value, string $grain): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		if ($grain === AnonymisationProfile::GRAIN_POSTCODE_DISTRICT) {
			preg_match('/\d{4}/', $text, $matches);
			return ($matches[0] ?? null);
		}

		$timestamp = strtotime($text);
		if ($timestamp === false) {
			return null;
		}

		$format = 'Y';
		if ($grain === AnonymisationProfile::GRAIN_MONTH) {
			$format = 'Y-m';
		}

		return date($format, $timestamp);
	}//end generalise()
}//end class
