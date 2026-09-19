<?php

/**
 * Which objects a picker may offer to save a file into.
 *
 * 🔑 A PICKER OFFERS WHAT THE CALLER CAN ACTUALLY DO. A list that includes a
 * schema they may not write to is a list where most of the entries fail on
 * click, and the person saving a file learns that one entry at a time.
 *
 * 🔴 AN UNWRITABLE TARGET IS NOT OFFERED, AND IT IS NOT COUNTED EITHER — and
 * that is the opposite of what {@see ContactCasesPanel} does, for a reason
 * worth writing down. There, a reader asking "what is this contact involved
 * in" is owed a true TOTAL, so a row they may not read is counted and never
 * named: dropping it would answer "two" where the truth is "five". Here,
 * nobody is owed a count of registers they cannot write to. "Three places you
 * may not save to" tells the caller nothing they can act on and tells them
 * which registers exist, which is an oracle with no reader it serves.
 *
 * 🔴 THE VERDICTS ARE THE PLATFORM'S AND ARE READ, NOT DERIVED. This class is
 * handed what the caller may do with each candidate and filters on it. It
 * never infers a right from a schema's shape, because a second opinion about
 * who may write is how a picker comes to offer a target the write then
 * refuses. And on a schema that configures no authorization the platform's
 * answer is "everyone", so a picker on such a register offers it to every
 * authenticated account — which is the schema's decision to change, not this
 * filter's to second-guess.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Narrows the attach picker's targets to what the caller may write.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */
class AttachTargetFilter {

	/**
	 * The manifest key a consuming app pins the picker with.
	 *
	 * @var string
	 */
	public const DECLARATION = 'attachTargets';

	/**
	 * How many targets a search answers with.
	 *
	 * A picker is a search box, not an export: a caller who types three
	 * letters and matches nine hundred objects is choosing from the first
	 * screen either way, and answering all nine hundred is a read nobody
	 * looks at.
	 *
	 * @var int
	 */
	public const MAX_RESULTS = 25;

	/**
	 * The schemas the picker may offer.
	 *
	 * @param array<int,array<string,mixed>> $candidates Each: `schema`, `register`, `label`, `writable`, `hasFilesLeaf`.
	 * @param array<int,string>|null         $declared   The consuming manifest's `attachTargets`, or null when it pinned none.
	 *
	 * @return array<int,array<string,mixed>> The offerable schemas, in the declared order when one was given.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public function offerableSchemas(array $candidates, ?array $declared = null): array {
		$offerable = $this->offerableBySchema(candidates: $candidates);

		if (is_array($declared) === false) {
			return array_values($offerable);
		}

		// A declaration NARROWS and never widens. An app that pins a schema
		// the caller may not write to does not thereby grant it: the pin says
		// which of the caller's targets this app cares about, and a pin that
		// could add one would be a manifest handing out write access.
		$pinned = [];
		foreach ($declared as $wanted) {
			$wanted = trim((string)$wanted);
			if ($wanted !== '' && isset($offerable[$wanted]) === true) {
				$pinned[] = $offerable[$wanted];
			}
		}

		return $pinned;
	}//end offerableSchemas()

	/**
	 * The candidates that may actually be offered, keyed by schema.
	 *
	 * The two conditions are different failures and both are silent without
	 * this: a schema the caller cannot write fails on click, and a schema with
	 * no files leaf accepts the pick and then has nowhere to put the file.
	 *
	 * @param array<int,array<string,mixed>> $candidates Each: `schema`, `register`, `label`, `writable`, `hasFilesLeaf`.
	 *
	 * @return array<string,array<string,mixed>> The offerable schemas, keyed by slug.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	private function offerableBySchema(array $candidates): array {
		$offerable = [];

		foreach ($candidates as $candidate) {
			if (is_array($candidate) === false) {
				continue;
			}

			if (($candidate['writable'] ?? false) !== true || ($candidate['hasFilesLeaf'] ?? false) !== true) {
				continue;
			}

			$schema = trim((string)($candidate['schema'] ?? ''));
			if ($schema === '') {
				continue;
			}

			$offerable[$schema] = [
				'schema' => $schema,
				'register' => (string)($candidate['register'] ?? ''),
				'label' => trim((string)($candidate['label'] ?? $schema)),
			];
		}

		return $offerable;
	}//end offerableBySchema()

	/**
	 * Whether this caller may attach this file at all.
	 *
	 * Attaching copies a file INTO an object, so the caller must be able to
	 * read the file as well as write the object. A caller who cannot read it
	 * is refused here rather than at the copy, where the failure would arrive
	 * after the picker has already promised the save.
	 *
	 * @param array<string,mixed> $node   The node: `readable`, `id`.
	 * @param array<string,mixed> $target The chosen target: `writable`, `hasFilesLeaf`, `schema`.
	 *
	 * @return string The refusal, or '' when the attach may proceed.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public function whyRefused(array $node, array $target): string {
		if (($node['readable'] ?? false) !== true) {
			// Said plainly, because the caller already knows they cannot open
			// it: this is not an oracle, it is the answer to what they just
			// tried to do.
			return 'You cannot open this file, so it cannot be saved to an object.';
		}

		if (($target['writable'] ?? false) !== true) {
			// Named WITHOUT confirming the target exists beyond what the
			// caller was already offered: they picked from a list this class
			// built, so a target that is not writable now is one that changed
			// under them.
			return 'You may not add files to this object.';
		}

		if (($target['hasFilesLeaf'] ?? false) !== true) {
			return 'This kind of object does not hold files.';
		}

		return '';
	}//end whyRefused()

	/**
	 * A search result list, bounded and stripped of anything unofferable.
	 *
	 * @param array<int,array<string,mixed>> $hits     The title matches.
	 * @param array<int,string>              $offerable The schemas the picker may offer.
	 *
	 * @return array<int,array<string,mixed>> The results, at most {@see self::MAX_RESULTS}.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
	 */
	public function searchResults(array $hits, array $offerable): array {
		$results = [];

		foreach ($hits as $hit) {
			if (is_array($hit) === false) {
				continue;
			}

			// A hit outside the offerable schemas is dropped in silence and
			// NOT counted. Nobody is owed a tally of objects they may not
			// write to, and a count of them names the registers they live in.
			if (in_array((string)($hit['schema'] ?? ''), $offerable, true) === false) {
				continue;
			}

			$results[] = [
				'objectUuid' => (string)($hit['objectUuid'] ?? ''),
				'title' => trim((string)($hit['title'] ?? '')),
				'schema' => (string)($hit['schema'] ?? ''),
				'register' => (string)($hit['register'] ?? ''),
			];

			if (count($results) >= self::MAX_RESULTS) {
				break;
			}
		}

		return $results;
	}//end searchResults()
}//end class
