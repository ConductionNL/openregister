<?php

/**
 * FlowSemanticVersionInterface: the FlowSemanticVersion as the semantic-version backfill repair step reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The back-filled semantic version, typed for the repair step that stamps it.
 *
 * Declares only what callers outside the Service component use; the rest of the
 * class stays its own. Bound in ContractBindings to the same shared instance.
 */
interface FlowSemanticVersionInterface {

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
	public function backfilled(int $ordinalPosition): string;
}//end interface
