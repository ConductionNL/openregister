<?php

/**
 * Who is reading flow runs, for the visibility rule of FlowRunMapper.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The caller of a run read and the flows that caller owns.
 *
 * The two always travel together: a run is visible when the caller triggered
 * it or when it belongs to one of the caller's flows. A null requester means
 * no scoping at all (administrators and system reads only).
 *
 * @spec openspec/specs/flow-run-subjects/spec.md
 */
final readonly class FlowRunViewer {
	/**
	 * Constructor.
	 *
	 * @param string|null        $requesterUid The caller, or null for no scoping.
	 * @param array<int, string> $ownedFlowIds Flow ids the caller owns.
	 *
	 * @return void
	 */
	public function __construct(
		public ?string $requesterUid,
		public array $ownedFlowIds,
	) {
	}//end __construct()
}//end class
