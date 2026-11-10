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
	 * @param array<string, mixed> $payload The record's properties.
	 * @param SaltedProfile        $profile The rules, property name to its rule, and the salt for the pseudonym.
	 *
	 * @return array<string, mixed> The anonymised payload.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function apply(array $payload, SaltedProfile $profile): array {
		$pass = new AnonymisationPass(profile: $profile, payload: $payload);

		foreach ($profile->rules as $property => $rule) {
			if (array_key_exists($property, $pass->payload) === false) {
				continue;
			}

			if (($rule['treatment'] ?? null) === AnonymisationProfile::REMOVE) {
				unset($pass->payload[$property]);
				continue;
			}

			$pass->payload[$property] = $this->treat(pass: $pass, property: (string)$property);
		}

		return $pass->payload;
	}//end apply()

	/**
	 * The report: what changed, and what was deliberately left in.
	 *
	 * Both lists, always. A report that names only what it removed invites the
	 * reader to assume the rest was not personal, and the rest is where a
	 * re-identification comes from: a date of birth, a postcode and a case
	 * number identify most people without a name anywhere in sight.
	 *
	 * @param PayloadChange $change      The payload as it was and as it is now.
	 * @param string        $profileName What the profile was called.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function report(PayloadChange $change, string $profileName = ''): array {
		$split = $this->split(change: $change);
		$changed = $split['changed'];
		$kept = $split['kept'];
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
	 * Split the properties a payload had into those that changed and those that were kept.
	 *
	 * @param PayloadChange $change The payload as it was and as it is now.
	 *
	 * @return array{changed: array<string, mixed>, kept: string[]} Both, unsorted.
	 */
	private function split(PayloadChange $change): array {
		$split = ['changed' => [], 'kept' => []];
		foreach (array_keys($change->before) as $property) {
			$name = (string)$property;
			$outcome = $this->outcomeOf(change: $change, property: $property);
			if ($outcome['changed'] === true) {
				$split['changed'][$name] = $outcome['value'];
				continue;
			}

			$split['kept'][] = $name;
		}

		return $split;
	}//end split()

	/**
	 * Whether one property changed, and what the report says it became.
	 *
	 * @param PayloadChange $change   The payload as it was and as it is now.
	 * @param int|string    $property The property, as a key of the payload before.
	 *
	 * @return array{changed: bool, value: mixed} The outcome; a removed property reads as REMOVED.
	 */
	private function outcomeOf(PayloadChange $change, int|string $property): array {
		$name = (string)$property;
		if (array_key_exists($name, $change->after) === false) {
			return ['changed' => true, 'value' => self::REMOVED];
		}

		return ['changed' => ($change->after[$name] !== $change->before[$property]), 'value' => $change->after[$name]];
	}//end outcomeOf()

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
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function pseudonymFor(string $value, string $salt): string {
		return 'anon-'.substr(hash('sha256', $salt.'::'.mb_strtolower(trim($value))), 0, 16);
	}//end pseudonymFor()

	/**
	 * One treatment on one value.
	 *
	 * @param AnonymisationPass $pass     The pass: its rules, salt and the payload so far.
	 * @param string            $property The property to treat, present in the payload.
	 *
	 * @return mixed The treated value.
	 */
	private function treat(AnonymisationPass $pass, string $property): mixed {
		$rule = $pass->profile->rules[$property];
		$treatment = (string)($rule['treatment'] ?? null);
		$value = $pass->payload[$property];
		if ($treatment === AnonymisationProfile::FIXED) {
			return ($rule['value'] ?? null);
		}

		if ($treatment === AnonymisationProfile::PSEUDONYM) {
			return $this->pseudonymFor(value: $this->scalarText(value: $value), salt: $pass->profile->salt);
		}

		if ($treatment === AnonymisationProfile::GENERALISE) {
			return $this->generalise(value: $value, grain: (string)($rule['grain'] ?? ''));
		}

		return $value;
	}//end treat()

	/**
	 * A scalar value as text, and anything else as the empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private function scalarText(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end scalarText()

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
		$text = trim($this->scalarText(value: $value));
		if ($text === '') {
			return null;
		}

		if ($grain === AnonymisationProfile::GRAIN_POSTCODE_DISTRICT) {
			preg_match('/\d{4}/', $text, $matches);
			return ($matches[0] ?? null);
		}

		return $this->coarseDate(text: $text, grain: $grain);
	}//end generalise()

	/**
	 * A date coarsened to its year, or to its month for the month grain.
	 *
	 * @param string $text  The trimmed value.
	 * @param string $grain The declared grain.
	 *
	 * @return string|null The coarser date, or null when the value is not a date.
	 */
	private function coarseDate(string $text, string $grain): string|null {
		$timestamp = strtotime($text);
		if ($timestamp === false) {
			return null;
		}

		$format = 'Y';
		if ($grain === AnonymisationProfile::GRAIN_MONTH) {
			$format = 'Y-m';
		}

		return date($format, $timestamp);
	}//end coarseDate()
}//end class
