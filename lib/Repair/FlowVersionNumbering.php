<?php

/**
 * The version rows and the numbering a semantic-version backfill stamps them with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\FlowVersionMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersionInterface;

/**
 * Where published flow versions are read and written, and how they are numbered.
 *
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-existing-published-versions-are-stamped-once-and-honestly
 */
final class FlowVersionNumbering {

	/**
	 * Constructor.
	 *
	 * @param FlowVersionMapperInterface   $versions The version rows.
	 * @param FlowSemanticVersionInterface $semver   The numbering.
	 */
	public function __construct(
		public readonly FlowVersionMapperInterface $versions,
		public readonly FlowSemanticVersionInterface $semver,
	) {
	}//end __construct()
}//end class
