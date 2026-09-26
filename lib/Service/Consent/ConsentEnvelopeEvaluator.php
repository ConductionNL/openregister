<?php

/**
 * OpenRegister ConsentEnvelopeEvaluator
 *
 * Pure, NC-independent evaluation of one `x-openregister-consent` property
 * write: append-only enforcement plus evidence fill on newly appended
 * entries. Holds no Nextcloud dependency (IRequest/IUserSession are resolved
 * by the caller and passed in as plain values) so it is directly unit
 * testable without event/session mocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/consent-evidence-envelope/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

use DateTimeImmutable;

/**
 * Evaluates one consent-shaped property write: append-only check, then evidence fill.
 */
final class ConsentEnvelopeEvaluator {

	/**
	 * Fields the platform fills on every newly appended entry; a
	 * caller-supplied value under any of these keys is discarded.
	 *
	 * @var array<int, string>
	 */
	private const EVIDENCE_FIELDS = ['by', 'timestamp', 'ip', 'userAgent', 'contentHash'];

	/**
	 * Evaluate one consent-shaped property's write.
	 *
	 * @param string $name The property name (used only in the refusal message).
	 * @param array<string, mixed> $annotation The property's `x-openregister-consent` declaration.
	 * @param mixed $incoming The caller-submitted value for this property.
	 * @param mixed $persisted The previously persisted value for this property.
	 * @param string|null $actingIdentity The resolved acting identity ("by"), already resolved by the caller.
	 * @param string|null $ipAddress The caller's remote address, already resolved by the caller.
	 * @param string|null $userAgent The caller's User-Agent header, already resolved by the caller.
	 *
	 * @return array{refused: bool, value: array<int, array<string, mixed>>, message: string|null}
	 *     `refused` is true when an existing entry was mutated or dropped — `value` is then the
	 *     normalised-but-unfilled incoming array and `message` names the violation. Otherwise
	 *     `value` is the array to persist (with evidence filled on every newly appended entry)
	 *     and `message` is null.
	 *
	 * @spec openspec/changes/consent-evidence-envelope/specs/consent-evidence-envelope/spec.md
	 */
	public function evaluate(
		string $name,
		array $annotation,
		mixed $incoming,
		mixed $persisted,
		?string $actingIdentity,
		?string $ipAddress,
		?string $userAgent
	): array {
		$incoming = $this->normaliseArray(value: $incoming);
		$persisted = $this->normaliseArray(value: $persisted);

		$violation = $this->appendOnlyViolation(name: $name, incoming: $incoming, persisted: $persisted);
		if ($violation !== null) {
			return ['refused' => true, 'value' => $incoming, 'message' => $violation];
		}

		$purpose = (string)($annotation['purpose'] ?? '');
		$filled = $this->fillNewEntries(
			incoming: $incoming,
			persistedCount: count($persisted),
			purpose: $purpose,
			actingIdentity: $actingIdentity,
			ipAddress: $ipAddress,
			userAgent: $userAgent
		);

		return ['refused' => false, 'value' => $filled, 'message' => null];
	}//end evaluate()

	/**
	 * Normalise a caller-submitted property value into a re-indexed array.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<int, mixed>
	 */
	private function normaliseArray(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values($value);
	}//end normaliseArray()

	/**
	 * The append-only violation message, or null when the incoming array is
	 * a valid append (or equal to) the persisted array.
	 *
	 * @param string $name The property name.
	 * @param array<int, mixed> $incoming The caller-submitted, normalised array.
	 * @param array<int, mixed> $persisted The previously persisted, normalised array.
	 *
	 * @return string|null
	 */
	private function appendOnlyViolation(string $name, array $incoming, array $persisted): ?string {
		$persistedCount = count($persisted);
		$incomingCount = count($incoming);

		if ($incomingCount < $persistedCount) {
			return sprintf(
				'Property "%s" is append-only (x-openregister-consent): the submitted value has fewer entries than the persisted value.',
				$name
			);
		}

		for ($index = 0; $index < $persistedCount; $index++) {
			if (($incoming[$index] ?? null) !== $persisted[$index]) {
				return sprintf(
					'Property "%s" is append-only (x-openregister-consent): entry %d cannot be changed, only new entries may be appended.',
					$name,
					$index
				);
			}
		}

		return null;
	}//end appendOnlyViolation()

	/**
	 * Fill evidence on every entry appended beyond the previously persisted length.
	 *
	 * @param array<int, mixed> $incoming The normalised, append-only-verified array.
	 * @param int $persistedCount The number of previously persisted entries (the append boundary).
	 * @param string $purpose The property's declared purpose.
	 * @param string|null $actingIdentity The resolved acting identity ("by").
	 * @param string|null $ipAddress The caller's remote address.
	 * @param string|null $userAgent The caller's User-Agent header.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fillNewEntries(
		array $incoming,
		int $persistedCount,
		string $purpose,
		?string $actingIdentity,
		?string $ipAddress,
		?string $userAgent
	): array {
		$incomingCount = count($incoming);
		for ($index = $persistedCount; $index < $incomingCount; $index++) {
			$entry = $incoming[$index];
			if (is_array($entry) === false) {
				continue;
			}

			$incoming[$index] = $this->fillEvidence(
				entry: $entry,
				purpose: $purpose,
				actingIdentity: $actingIdentity,
				ipAddress: $ipAddress,
				userAgent: $userAgent
			);
		}

		return $incoming;
	}//end fillNewEntries()

	/**
	 * Fill the read-only evidentiary fields on one newly appended entry.
	 *
	 * @param array<string, mixed> $entry The caller-submitted entry.
	 * @param string $purpose The property's declared purpose.
	 * @param string|null $actingIdentity The resolved acting identity ("by").
	 * @param string|null $ipAddress The caller's remote address.
	 * @param string|null $userAgent The caller's User-Agent header.
	 *
	 * @return array<string, mixed> The entry with evidence fields filled.
	 */
	private function fillEvidence(array $entry, string $purpose, ?string $actingIdentity, ?string $ipAddress, ?string $userAgent): array {
		foreach (self::EVIDENCE_FIELDS as $field) {
			unset($entry[$field]);
		}

		$decision = (string)($entry['decision'] ?? '');
		$evidenceOf = (string)($entry['evidenceOf'] ?? '');
		$timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

		$entry['by'] = $actingIdentity;
		$entry['timestamp'] = $timestamp;
		$entry['ip'] = $ipAddress;
		$entry['userAgent'] = $userAgent;
		$entry['contentHash'] = hash('sha256', $purpose . $decision . $evidenceOf);

		$entry['withdrawnAt'] = null;
		if ($decision === 'withdrawn') {
			$entry['withdrawnAt'] = $timestamp;
		}

		return $entry;
	}//end fillEvidence()
}//end class
