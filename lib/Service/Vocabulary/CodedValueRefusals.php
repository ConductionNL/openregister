<?php

/**
 * OpenRegister CodedValueRefusals
 *
 * The three write refusals {@see CodedValueGuard} applies to one resolved
 * coded value — validity window, leaf-only, exclusive group — and the
 * messages they produce. Split out of the guard, which decides which values
 * are judged and collects what these rules say.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * Judges one resolved coded value against the write refusals.
 */
final class CodedValueRefusals {

	/**
	 * Constructor.
	 *
	 * @param ConceptLifecycle $lifecycle Answers window and group.
	 * @param ConceptHierarchy $hierarchy Walks broader/narrower and reads labels.
	 */
	public function __construct(
		private readonly ConceptLifecycle $lifecycle,
		private readonly ConceptHierarchy $hierarchy,
	) {

	}//end __construct()

	/**
	 * The refusal one resolved value earns on a property, or null when none.
	 *
	 * Runs the three write refusals in order — validity window, leaf-only,
	 * exclusive group — and returns the first message that applies. A value
	 * refused by an earlier rule never takes an exclusive group.
	 *
	 * @param CodedValueSubject $subject The property being judged.
	 * @param CodedValueMatch   $match   The submitted value and its concept.
	 *
	 * @return string|null The refusal message, or null when the value is allowed.
	 */
	public function violationFor(CodedValueSubject $subject, CodedValueMatch $match): ?string {
		return ($this->windowViolation(subject: $subject, match: $match)
			?? $this->leafViolation(subject: $subject, match: $match)
			?? $this->exclusiveGroupViolation(subject: $subject, match: $match));
	}//end violationFor()

	/**
	 * The refusal a value outside its validity window earns, or null.
	 *
	 * @param CodedValueSubject $subject The property being judged.
	 * @param CodedValueMatch   $match   The submitted value and its concept.
	 *
	 * @return string|null The refusal message, or null when the window holds.
	 */
	private function windowViolation(CodedValueSubject $subject, CodedValueMatch $match): ?string {
		if ($this->lifecycle->isWithinWindow(concept: $match->concept, at: $subject->check->instant) === true) {
			return null;
		}

		return sprintf(
			'The value "%s" on "%s" is outside its validity window (%s) and can no longer be chosen.',
			$this->labelOf(match: $match),
			$subject->property,
			$this->lifecycle->describeWindow(concept: $match->concept)
		);
	}//end windowViolation()

	/**
	 * The refusal a non-leaf value earns on a leaf-only property, or null.
	 *
	 * @param CodedValueSubject $subject The property being judged.
	 * @param CodedValueMatch   $match   The submitted value and its concept.
	 *
	 * @return string|null The refusal message, or null when leaves are not required or this is one.
	 */
	private function leafViolation(CodedValueSubject $subject, CodedValueMatch $match): ?string {
		if ($subject->declaration->leafOnly === false
			|| $this->hierarchy->isLeaf(uri: $match->uri(), conceptsByUri: $subject->conceptsByUri) === true
		) {
			return null;
		}

		return sprintf(
			'The value "%s" on "%s" has narrower values under it, and this field accepts only the most specific value.',
			$this->labelOf(match: $match),
			$subject->property
		);
	}//end leafViolation()

	/**
	 * The refusal an exclusive-group clash earns, recording the first holder.
	 *
	 * A concept with no exclusive group refuses nothing. The first concept
	 * seen for a scheme+group is remembered; a later one in the same group is
	 * the clash this refuses.
	 *
	 * @param CodedValueSubject $subject The property being judged.
	 * @param CodedValueMatch   $match   The submitted value and its concept.
	 *
	 * @return string|null The refusal message, or null when nothing clashes.
	 */
	private function exclusiveGroupViolation(CodedValueSubject $subject, CodedValueMatch $match): ?string {
		$group = $this->lifecycle->exclusiveGroupOf(concept: $match->concept);
		if ($group === null) {
			return null;
		}

		$already = $subject->check->claim(key: $subject->declaration->scheme."\0".$group, label: $this->labelOf(match: $match));
		if ($already === null) {
			return null;
		}

		$format = 'The values "%s" and "%s" both belong to the group "%s", of which only one may be held.';
		return sprintf($format, $already, $this->labelOf(match: $match), $group);
	}//end exclusiveGroupViolation()

	/**
	 * The Dutch label refusals name a value by.
	 *
	 * @param CodedValueMatch $match The submitted value and its concept.
	 *
	 * @return string The label.
	 */
	private function labelOf(CodedValueMatch $match): string {
		return $this->hierarchy->labelOf(concept: $match->concept, language: 'nl');
	}//end labelOf()
}//end class
