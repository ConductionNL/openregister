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
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

use DateTimeImmutable;

/**
 * Evaluates one consent-shaped property write: append-only check, then evidence fill.
 */
final class ConsentEnvelopeEvaluator implements ConsentEnvelopeEvaluatorInterface {

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
	 * @param ConsentPropertyWrite  $write  The write: the property's name and declaration, the submitted and the stored value.
	 * @param ConsentEvidenceSource $source Who writes and from where, already resolved by the caller.
	 *
	 * @return array{refused: bool, value: array<int, array<string, mixed>>, message: string|null}
	 *     `refused` is true when an existing entry was mutated or dropped — `value` is then the
	 *     normalised-but-unfilled incoming array and `message` names the violation. Otherwise
	 *     `value` is the array to persist (with evidence filled on every newly appended entry)
	 *     and `message` is null.
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public function evaluate(ConsentPropertyWrite $write, ConsentEvidenceSource $source): array {
		$violation = $this->appendOnlyViolation(write: $write);
		if ($violation !== null) {
			return ['refused' => true, 'value' => $write->incomingEntries(), 'message' => $violation];
		}

		$stamp = new ConsentEvidenceStamp(purpose: (string)($write->annotation['purpose'] ?? ''), source: $source);
		$filled = $this->fillNewEntries(write: $write, stamp: $stamp);

		return ['refused' => false, 'value' => $filled, 'message' => null];
	}//end evaluate()

	/**
	 * The append-only violation message, or null when the incoming array is
	 * a valid append (or equal to) the persisted array.
	 *
	 * @param ConsentPropertyWrite $write The write.
	 *
	 * @return string|null
	 */
	private function appendOnlyViolation(ConsentPropertyWrite $write): ?string {
		$incoming  = $write->incomingEntries();
		$persisted = $write->persistedEntries();

		if (count($incoming) < count($persisted)) {
			return $this->shortenedRefusal(name: $write->name);
		}

		$changed = $this->firstChangedEntry(incoming: $incoming, persisted: $persisted);
		if ($changed === null) {
			return null;
		}

		return $this->changedEntryRefusal(name: $write->name, index: $changed);
	}//end appendOnlyViolation()

	/**
	 * The index of the first persisted entry the submitted value does not repeat unchanged.
	 *
	 * @param array<int, mixed> $incoming  The submitted entries.
	 * @param array<int, mixed> $persisted The persisted entries.
	 *
	 * @return int|null The index, or null when every persisted entry is kept as it was.
	 */
	private function firstChangedEntry(array $incoming, array $persisted): ?int {
		$persistedCount = count($persisted);
		for ($index = 0; $index < $persistedCount; $index++) {
			if (($incoming[$index] ?? null) !== $persisted[$index]) {
				return $index;
			}
		}

		return null;
	}//end firstChangedEntry()

	/**
	 * The refusal for a submitted value with fewer entries than the persisted one.
	 *
	 * @param string $name The property.
	 *
	 * @return string The refusal.
	 */
	private function shortenedRefusal(string $name): string {
		return sprintf(
			'Property "%s" is append-only (x-openregister-consent): the submitted value has fewer entries than the persisted value.',
			$name
		);
	}//end shortenedRefusal()

	/**
	 * The refusal for a persisted entry the submitted value changes.
	 *
	 * @param string $name  The property.
	 * @param int    $index The changed entry.
	 *
	 * @return string The refusal.
	 */
	private function changedEntryRefusal(string $name, int $index): string {
		return sprintf(
			'Property "%s" is append-only (x-openregister-consent): entry %d cannot be changed, only new entries may be appended.',
			$name,
			$index
		);
	}//end changedEntryRefusal()

	/**
	 * Fill evidence on every entry appended beyond the previously persisted length.
	 *
	 * The write has already passed the append-only check, so its persisted
	 * length is the append boundary.
	 *
	 * @param ConsentPropertyWrite $write The append-only-verified write.
	 * @param ConsentEvidenceStamp $stamp The purpose and source every new entry is stamped with.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fillNewEntries(ConsentPropertyWrite $write, ConsentEvidenceStamp $stamp): array {
		$incoming = $write->incomingEntries();
		$incomingCount = count($incoming);
		for ($index = count($write->persistedEntries()); $index < $incomingCount; $index++) {
			$entry = $incoming[$index];
			if (is_array($entry) === false) {
				continue;
			}

			$incoming[$index] = $this->fillEvidence(entry: $entry, stamp: $stamp);
		}

		return $incoming;
	}//end fillNewEntries()

	/**
	 * Fill the read-only evidentiary fields on one newly appended entry.
	 *
	 * @param array<string, mixed> $entry The caller-submitted entry.
	 * @param ConsentEvidenceStamp $stamp The purpose and source to stamp.
	 *
	 * @return array<string, mixed> The entry with evidence fields filled.
	 */
	private function fillEvidence(array $entry, ConsentEvidenceStamp $stamp): array {
		foreach (self::EVIDENCE_FIELDS as $field) {
			unset($entry[$field]);
		}

		$decision = (string)($entry['decision'] ?? '');
		$evidenceOf = (string)($entry['evidenceOf'] ?? '');
		$timestamp = (new DateTimeImmutable())->format(DATE_ATOM);

		$entry['by'] = $stamp->source->actingIdentity;
		$entry['timestamp'] = $timestamp;
		$entry['ip'] = $stamp->source->ipAddress;
		$entry['userAgent'] = $stamp->source->userAgent;
		$entry['contentHash'] = hash('sha256', $stamp->purpose . $decision . $evidenceOf);

		$entry['withdrawnAt'] = null;
		if ($decision === 'withdrawn') {
			$entry['withdrawnAt'] = $timestamp;
		}

		return $entry;
	}//end fillEvidence()
}//end class
