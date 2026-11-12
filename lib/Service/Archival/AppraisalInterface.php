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
 * stays the one home of these values.
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
}//end interface
