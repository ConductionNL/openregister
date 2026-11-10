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
class FlowSemanticVersion implements FlowSemanticVersionInterface {

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

	// The provenance values (SOURCE_DERIVED, SOURCE_BACKFILL) are declared on FlowSemanticVersionInterface.

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
	 * @param FlowGraphFinding $found     The diff's verdict and a summary of what was removed, for the refusal.
	 * @param string|null      $requested What the author asked for, or null.
	 *
	 * @return string The verdict to use.
	 *
	 * @throws UnexpectedValueException When the author asked to call a removal minor.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-an-author-may-raise-the-verdict-and-never-lower-it
	 */
	public function reconcile(FlowGraphFinding $found, ?string $requested): string {
		$asked = self::askedBump(requested: $requested);
		if ($asked === '') {
			return $found->verdict;
		}

		if ($asked === FlowGraphDiff::MINOR && $found->verdict === FlowGraphDiff::MAJOR) {
			throw self::notMinor(removed: $found->removed);
		}

		return $asked;
	}//end reconcile()

	/**
	 * The bump the author asked for: '', `major` or `minor`.
	 *
	 * @param string|null $requested What the author asked for, or null.
	 *
	 * @return string The normalised bump, '' when none was asked.
	 *
	 * @throws UnexpectedValueException When the request is neither major nor minor.
	 */
	private static function askedBump(?string $requested): string {
		$asked = strtolower(trim((string)$requested));
		if (in_array($asked, ['', FlowGraphDiff::MAJOR, FlowGraphDiff::MINOR], true) === false) {
			throw new UnexpectedValueException(
				sprintf('A version bump is "major" or "minor"; "%s" is neither.', (string)$requested)
			);
		}

		return $asked;
	}//end askedBump()

	/**
	 * The refusal of a minor bump over a removal the diff found.
	 *
	 * @param string $removed A summary of what was removed, or ''.
	 *
	 * @return UnexpectedValueException
	 */
	private static function notMinor(string $removed): UnexpectedValueException {
		$detail = $removed;
		if ($detail === '') {
			$detail = 'Something a consumer could depend on was removed.';
		}

		return new UnexpectedValueException('This publish cannot be minor. ' . $detail);
	}//end notMinor()

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
		$revision = new FlowGraphRevision(published: $publishedGraph, candidate: $candidateGraph, previousSemver: $previousSemver);

		return $this->versionFor(revision: $revision, requested: $requested);
	}//end forPublish()

	/**
	 * The semantic version a publish of this revision stores.
	 *
	 * @param FlowGraphRevision $revision  The live graph, the candidate, and the last semantic version.
	 * @param string|null       $requested `major`, `minor`, or null.
	 *
	 * @return string The semantic version to store.
	 *
	 * @throws UnexpectedValueException When the author asked to call a removal minor.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
	 */
	public function versionFor(FlowGraphRevision $revision, ?string $requested): string {
		if ($revision->published === null) {
			return self::FIRST;
		}

		$comparison = $this->diff->compare(published: $revision->published, candidate: $revision->candidate);
		$found = new FlowGraphFinding(verdict: $comparison['verdict'], removed: $this->diff->summarise(diff: $comparison));
		$verdict = $this->reconcile(found: $found, requested: $requested);

		return $this->next(previous: $revision->previousSemver, verdict: $verdict);
	}//end versionFor()


	/**
	 * What publishing WOULD do, without doing it.
	 *
	 * 🔴 IT NEVER THROWS AND IT NEVER REFUSES. A preflight is a question, and
	 * an author asking "what would this be called" before they have chosen a
	 * bump has done nothing wrong. The refusal belongs at the publish, where
	 * the author has actually asserted something; raising it here would make
	 * the editor unable to ASK without first committing to an answer.
	 *
	 * It reads the same comparison {@see versionFor()} does, deliberately. A
	 * preflight computed a second way is a second opinion, and the first time
	 * the two disagree the author learns to believe neither.
	 *
	 * @param FlowGraphRevision $revision The live graph (or null), the candidate, and the last semantic version.
	 *
	 * @return array{
	 *     verdict: string,
	 *     next: string,
	 *     removed: string,
	 *     removedNodes: array<int, string>,
	 *     removedEdges: array<int, string>,
	 *     removedKeys: array<int, string>,
	 *     first: bool
	 * } What the publish would be called and why.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-the-author-is-told-what-it-will-be-before-publishing
	 */
	public function previewOf(FlowGraphRevision $revision): array {
		if ($revision->published === null) {
			// Nothing to have broken. The first publish is not "minor over
			// nothing", it is the first publish, and saying so is the
			// difference between a number and an explanation.
			return [
				'verdict'      => FlowGraphDiff::MINOR,
				'next'         => self::FIRST,
				'removed'      => '',
				'removedNodes' => [],
				'removedEdges' => [],
				'removedKeys'  => [],
				'first'        => true,
			];
		}

		$comparison = $this->diff->compare(published: $revision->published, candidate: $revision->candidate);

		return [
			'verdict'      => $comparison['verdict'],
			'next'         => $this->next(previous: $revision->previousSemver, verdict: $comparison['verdict']),
			'removed'      => $this->diff->summarise(diff: $comparison),
			'removedNodes' => $comparison['removedNodes'],
			'removedEdges' => $comparison['removedEdges'],
			'removedKeys'  => $comparison['removedKeys'],
			'first'        => false,
		];

	}//end previewOf()

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
