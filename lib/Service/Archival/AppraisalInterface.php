<?php

/**
 * AppraisalInterface: the appraisal values callers outside the component compare against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The MDTO `waardering` values read outside the component.
 *
 * Appraisal implements this, so `Appraisal::RETAIN_PERMANENTLY_ALIASES`
 * and `Appraisal::ANONYMISE` stay the one home of these values.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
interface AppraisalInterface {

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
	 * Keep the record, lose the person in it.
	 *
	 * The fourth word. A municipality that wants the case for its statistics
	 * and not the citizen in it had to choose between keeping personal data it
	 * no longer needs and destroying a record it still uses. Neither is lawful
	 * and neither is useful, so both were being answered by keeping.
	 *
	 * It is not a softer destroy. A destroyed record is gone and the destruction
	 * log says it was here; an anonymised record stays, and what it lost is
	 * gone from it, from everything derived from it, and from the values stored
	 * on its own audit trail. That last one is the exception to the rule that
	 * history is preserved, and it is deliberate: a trail that keeps the old
	 * name re-identifies the record it was removed from.
	 *
	 * @var string
	 */
	public const ANONYMISE = 'anonymise';
}//end interface
