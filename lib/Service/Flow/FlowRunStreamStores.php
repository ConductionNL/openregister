<?php

/**
 * The stream rows, place claims and locked delta commit a stream walk persists through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowStreamMapperInterface;

/**
 * Where a stream walk persists: the stream rows, the claim protocol and the locked delta commit.
 *
 * All three are needed for a stream walk; when any is null the run walks a single
 * in-memory stream exactly as before.
 *
 * @spec openspec/specs/flow-runs/spec.md
 */
final readonly class FlowRunStreamStores {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowStreamMapperInterface|null $streamMapper Stream rows.
	 * @param FlowPlaceClaims|null           $claims       The claim protocol.
	 * @param FlowRunCommit|null             $commit       The locked delta commit path.
	 *
	 * @spec openspec/specs/flow-runs/spec.md
	 */
	public function __construct(
		public ?FlowStreamMapperInterface $streamMapper = null,
		public ?FlowPlaceClaims $claims = null,
		public ?FlowRunCommit $commit = null,
	) {
	}//end __construct()
}//end class
