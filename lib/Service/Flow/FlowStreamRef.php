<?php

/**
 * FlowStreamRef
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;


use OCA\OpenRegister\Db\FlowStream;

/**
 * One stream of a run: its id, and the ordinal path, parent and place its
 * row is minted with when none exists yet.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamRef {

	/**
	 * Constructor.
	 *
	 * @param string      $streamId The stream id.
	 * @param string      $path     The stream's ordinal path.
	 * @param string|null $parent   The parent stream id.
	 * @param string|null $place    The place holding the stream's token.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function __construct(
		public readonly string $streamId,
		public readonly string $path = FlowStream::ROOT_PATH,
		public readonly ?string $parent = null,
		public readonly ?string $place = null,
	) {

	}//end __construct()
}//end class
