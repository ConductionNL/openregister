<?php

/**
 * The semantic version a publish produces, from the previous one and a verdict.
 *
 * Kept apart from {@see FlowGraphDiff} because they answer different questions
 * and fail differently. The diff reads two graphs and says what was taken
 * away; this reads a verdict and a previous version and says what to call the
 * next one. Putting both in one class would mean a change to the numbering
 * could break the comparison, and the comparison is the part with evidence
 * behind it.
 *
 * 🔴 PATCH IS ALWAYS ZERO. Nothing in a graph distinguishes a fix from a
 * feature, so a derived patch level would be a guess wearing three digits —
 * and three digits look far more precise than two. It stays 0 until something
 * can honestly set it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use UnexpectedValueException;

/**
 * Computes the next semantic version.
 */
class FlowSemanticVersion {

	/**
	 * Constructor.
	 *
	 * Takes the comparison rather than leaving callers to orchestrate it. A
	 * caller asking "what is the next version" should not also have to know
	 * that answering it means reading two graphs.
	 *
	 * @param FlowGraphDiff $diff What a publish takes away.
	 */
	public function __construct(
		private readonly FlowGraphDiff $diff = new FlowGraphDiff(),
	) {

	}//end __construct()

	/**
	 * What the first publish of a flow is called.
	 *
	 * @var string
	 */
	public const FIRST = '1.0.0';

	/**
	 * Derived from a graph comparison.
	 *
	 * @var string
	 */
	public const SOURCE_DERIVED = 'derived';

	/**
	 * Assigned by the back-fill, which had no graphs to compare.
	 *
	 * @var string
	 */
	public const SOURCE_BACKFILL = 'backfill';

	/**
	 * The next version, given the last one and what the diff found.
	 *
	 * @param string|null $previous The last published semantic version, or null.
	 * @param string $verdict FlowGraphDiff::MAJOR or ::MINOR.
	 *
	 * @return string The next semantic version.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-taking-something-away-is-major-everything-else-is-minor
	 */
	public function next(?string $previous, string $verdict): string {
		$parts = $this->parse(value: $previous);
		if ($parts === null) {
			// No previous version to build on: this is the flow's first
			// published version whatever the diff says, and calling a first
			// publish 2.0.0 because it "removed" everything from nothing would
			// be arithmetic rather than meaning.
			return self::FIRST;
		}

		if ($verdict === FlowGraphDiff::MAJOR) {
			return ($parts[0] + 1) . '.0.0';
		}

		return $parts[0] . '.' . ($parts[1] + 1) . '.0';
	}//end next()

	/**
	 * Reconcile the diff's verdict with what the author asked for.
	 *
	 * The asymmetry is the point. The diff is EVIDENCE: it saw a node
	 * disappear, and an author's optimism does not change what the graph says.
	 * The author's knowledge, on the other hand, exceeds the diff — they know
	 * which values a consumer reads — so they may add a major the diff did not
	 * find.
	 *
	 * Evidence can be added to, never argued down. Allowing both directions
	 * would make the version mean "what somebody felt like", which is the
	 * state this leaves behind.
	 *
	 * @param string $derived The diff's verdict.
	 * @param string|null $requested What the author asked for, or null.
	 * @param string $removed A summary of what was removed, for the refusal.
	 *
	 * @return string The verdict to use.
	 *
	 * @throws UnexpectedValueException When the author asked to call a removal minor.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-an-author-may-raise-the-verdict-and-never-lower-it
	 */
	public function reconcile(string $derived, ?string $requested, string $removed = ''): string {
		$asked = strtolower(trim((string)$requested));
		if ($asked === '') {
			return $derived;
		}

		if ($asked === FlowGraphDiff::MAJOR) {
			return FlowGraphDiff::MAJOR;
		}

		if ($asked !== FlowGraphDiff::MINOR) {
			throw new UnexpectedValueException(
				sprintf('A version bump is "major" or "minor"; "%s" is neither.', (string)$requested)
			);
		}

		if ($derived === FlowGraphDiff::MAJOR) {
			$detail = $removed;
			if ($detail === '') {
				$detail = 'Something a consumer could depend on was removed.';
			}

			throw new UnexpectedValueException(
				'This publish cannot be minor. ' . $detail
			);
		}

		return FlowGraphDiff::MINOR;
	}//end reconcile()

	/**
	 * The sequence a back-fill assigns: 1.0.0, 1.1.0, 1.2.0, …
	 *
	 * Minor throughout, because the repair does not know. It cannot compare
	 * the graphs — they are the ones it is being run to describe, and older
	 * definition rows may have been pruned — so it says so through
	 * `SOURCE_BACKFILL` rather than inventing majors.
	 *
	 * @param int $ordinalPosition The version's position among its flow's
	 *                             published versions, counting from zero.
	 *
	 * @return string The back-filled semantic version.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
	 */
	public function backfilled(int $ordinalPosition): string {
		return '1.' . max(0, $ordinalPosition) . '.0';
	}//end backfilled()

	/**
	 * The version a publish should produce, from the two graphs and the
	 * author's request.
	 *
	 * The one call a publisher makes. It compares, reconciles the verdict with
	 * what the author asked for, and numbers the result — so the caller holds
	 * one collaborator rather than two and never sees a verdict it has no use
	 * for.
	 *
	 * A null previous graph means there is nothing to have broken, so the
	 * answer is the first version whatever else is true. That covers both a
	 * flow's first publish and a previous definition that can no longer be
	 * read.
	 *
	 * @param array|null $publishedGraph The graph that was live, or null.
	 * @param array $candidateGraph The graph being published.
	 * @param string|null $previousSemver The last semantic version, if any.
	 * @param string|null $requested `major`, `minor`, or null.
	 *
	 * @return string The semantic version to store.
	 *
	 * @throws UnexpectedValueException When the author asked to call a removal minor.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
	 */
	public function forPublish(
		?array $publishedGraph,
		array $candidateGraph,
		?string $previousSemver,
		?string $requested
	): string {
		if ($publishedGraph === null) {
			return self::FIRST;
		}

		$comparison = $this->diff->compare(published: $publishedGraph, candidate: $candidateGraph);

		$verdict = $this->reconcile(
			derived: $comparison['verdict'],
			requested: $requested,
			removed: $this->diff->summarise(diff: $comparison)
		);

		return $this->next(previous: $previousSemver, verdict: $verdict);

	}//end forPublish()

	/**
	 * Read a semantic version into its three components.
	 *
	 * @param string|null $value The stored value.
	 *
	 * @return array{0: int, 1: int, 2: int}|null The parts, or null when unreadable.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
	 */
	private function parse(?string $value): ?array {
		$trimmed = trim((string)$value);
		if ($trimmed === '') {
			return null;
		}

		if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $trimmed, $matches) !== 1) {
			// Unreadable rather than absent. Treating it as absent would reset
			// a flow to 1.0.0 and lose whatever history the value was
			// recording, so the caller is told nothing is there and decides.
			return null;
		}

		return [(int)$matches[1], (int)$matches[2], (int)$matches[3]];
	}//end parse()
}//end class
