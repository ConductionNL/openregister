<?php
/**
 * AgentInterface: what callers outside Integrations read of an Agent row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * An AI agent: its model, prompt, RAG settings, access and quotas.
 *
 * Implemented by \OCA\OpenRegister\Mcp\Db\Agent. Events and listeners name
 * this read interface instead of the Integrations entity (ADR-012 Rule 2).
 * The column accessors are served by Nextcloud's Entity::__call, so they are
 * listed as tags here.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method string|null getName()
 * @method string|null getDescription()
 * @method string|null getType()
 * @method string|null getProvider()
 * @method string|null getModel()
 * @method string|null getPrompt()
 * @method float|null getTemperature()
 * @method int|null getMaxTokens()
 * @method array|null getConfiguration()
 * @method string|null getOrganisation()
 * @method string|null getOwner()
 * @method bool getActive()
 * @method bool getEnableRag()
 * @method string|null getRagSearchMode()
 * @method int|null getRagNumSources()
 * @method bool getRagIncludeFiles()
 * @method bool getRagIncludeObjects()
 * @method int|null getRequestQuota()
 * @method int|null getTokenQuota()
 * @method array|null getViews()
 * @method bool|null getSearchFiles()
 * @method bool|null getSearchObjects()
 * @method bool|null getIsPrivate()
 * @method array|null getInvitedUsers()
 * @method array|null getGroups()
 * @method array|null getTools()
 * @method string|null getUser()
 * @method DateTime|null getCreated()
 * @method DateTime|null getUpdated()
 */
interface AgentInterface extends JsonSerializable {

	/**
	 * Check if a user is invited to access this private agent
	 *
	 * @param string $userId The user ID to check
	 *
	 * @return bool True if user is invited
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function hasInvitedUser(string $userId): bool;
}//end interface
