<?php

/**
 * The MDTO appraisal vocabulary, in one place.
 *
 * 🔴 THIS EXISTS BECAUSE THE APPRAISAL ALIASES HAD NO SHARED HOME. The list
 * lived as a private constant on
 * {@see \OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver}, which
 * reads objects and answers questions about them. Everything that had to ACT on
 * an appraisal — the destruction sweep, the e-Depot transfer sweep — could not
 * see it, so each wrote its own string literal instead, and each picked exactly
 * one spelling.
 *
 * 🔴 MATCHING ONLY THE ENGLISH SPELLING SKIPS EVERY PRE-EXISTING RECORD, AND
 * SKIPS IT SILENTLY. Stored data carries whatever spelling was current when it
 * was written and there is no migration, so a destruction sweep that compares
 * against `destroy` alone finds nothing on an install whose records say
 * `vernietigen`. That is the direction that keeps personal data past its lawful
 * term: the sweep reports "no objects eligible", which is indistinguishable
 * from a clean run. The reverse mistake — matching only Dutch — hides every
 * record written since the vocabulary landed. Both spellings, always, on READ.
 *
 * WRITES STILL PICK ONE. This class is a read-side vocabulary, exactly like
 * {@see RecordState}: it says which stored spellings mean the same decision. It
 * does not say which spelling a new record should be written with.
 *
 * CONSTANTS ONLY, NO METHODS, for the same reason RecordState has none: phpmd's
 * StaticAccess rule refuses static helper calls, and a helper injected purely to
 * compare two strings is not worth the wiring. The alias lists are the API.
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
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The MDTO `waardering` values, and every spelling that means each one.
 */
final class Appraisal {

	/**
	 * Keep forever. The record goes to an e-Depot, it is never destroyed.
	 */
	public const RETAIN_PERMANENTLY = 'retain_permanently';

	/**
	 * Destroy once the disposal date has passed and nothing holds it.
	 */
	public const DESTROY = 'destroy';

	/**
	 * No decision has been recorded yet. Neither sweep may act on this.
	 */
	public const NOT_YET_DETERMINED = 'not_yet_determined';

	/**
	 * The appraisals, canonical spellings only.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::RETAIN_PERMANENTLY,
		self::DESTROY,
		self::NOT_YET_DETERMINED,
	];

	/**
	 * Every spelling that means RETAIN_PERMANENTLY.
	 *
	 * Three occur in the wild for "keep forever": MDTO and the selectielijst use
	 * `blijvend_bewaren`, a ZGW resultaattype may carry the shorter `bewaren`,
	 * and TMLO's own constant is `blijvend_bewaren` again. They mean the same
	 * thing to an archivist and must not reach a reader as two appraisals.
	 *
	 * @var string[]
	 */
	public const RETAIN_PERMANENTLY_ALIASES = ['retain_permanently', 'bewaren', 'blijvend_bewaren'];

	/**
	 * Every spelling that means DESTROY.
	 *
	 * @var string[]
	 */
	public const DESTROY_ALIASES = ['destroy', 'vernietigen'];

	/**
	 * Every spelling that means NOT_YET_DETERMINED.
	 *
	 * @var string[]
	 */
	public const NOT_YET_DETERMINED_ALIASES = ['not_yet_determined', 'nog_niet_bepaald'];

	/**
	 * Every accepted spelling of every appraisal.
	 *
	 * @var string[]
	 */
	public const ALL_ALIASES = [
		'retain_permanently',
		'bewaren',
		'blijvend_bewaren',
		'destroy',
		'vernietigen',
		'not_yet_determined',
		'nog_niet_bepaald',
	];

	/**
	 * Each accepted spelling mapped to its canonical one.
	 *
	 * The alias lists above answer "does this stored value mean X", which is
	 * what a sweep asks. This map answers "what is this stored value, in one
	 * word", which is what a reader normalising an object for output asks. The
	 * canonical spellings map to themselves so a value that is already
	 * canonical resolves rather than falling through unchanged by accident.
	 *
	 * @var array<string, string>
	 */
	public const CANONICAL = [
		'retain_permanently' => self::RETAIN_PERMANENTLY,
		'bewaren' => self::RETAIN_PERMANENTLY,
		'blijvend_bewaren' => self::RETAIN_PERMANENTLY,
		'destroy' => self::DESTROY,
		'vernietigen' => self::DESTROY,
		'not_yet_determined' => self::NOT_YET_DETERMINED,
		'nog_niet_bepaald' => self::NOT_YET_DETERMINED,
	];
}//end class
