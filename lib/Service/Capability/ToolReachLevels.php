<?php

/**
 * ToolReachLevels: the reach levels an MCP tool can declare, and the descriptor key that carries one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Capability
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

namespace OCA\OpenRegister\Service\Capability;

/**
 * The reach vocabulary. ToolReachResolver implements this interface, so
 * ToolReachResolver::ORDER, ::REACH_KEY and the REACH_ levels keep their
 * values; the MCP tool-declaration check and descriptor extras read them here.
 *
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-an-undeclared-or-unrecognised-reach-resolves-to-external
 */
interface ToolReachLevels {

	/**
	 * The agent's own state — memory, its own tool catalogue. Invisible to
	 * anyone else and undoable by the agent itself.
	 *
	 * @var string
	 */
	public const REACH_SELF = 'self';

	/**
	 * Data the ACTING USER could already reach themselves. The agent is acting
	 * as them, so nothing crosses a boundary the user does not already stand on.
	 *
	 * @var string
	 */
	public const REACH_USER = 'user';

	/**
	 * Other users on this Nextcloud can observe or be affected. Still inside the
	 * instance, still administrable, still auditable here.
	 *
	 * @var string
	 */
	public const REACH_INSTANCE = 'instance';

	/**
	 * Leaves the building. Outbound mail, calendar invitations to third parties,
	 * public share links, webhooks, fetching a caller-supplied URL. Nothing
	 * downstream of this is recallable by Nextcloud.
	 *
	 * @var string
	 */
	public const REACH_EXTERNAL = 'external';

	/**
	 * The total order, least to most far-reaching. Index IS the rank.
	 *
	 * @var array<int, string>
	 */
	public const ORDER = [
		self::REACH_SELF,
		self::REACH_USER,
		self::REACH_INSTANCE,
		self::REACH_EXTERNAL,
	];

	/**
	 * Descriptor key carrying a declared reach.
	 *
	 * @var string
	 */
	public const REACH_KEY = 'reach';
}//end interface
