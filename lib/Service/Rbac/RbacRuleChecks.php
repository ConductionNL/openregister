<?php

/**
 * RbacRuleChecks: builds the definition-write, cross-organisation, orphan-cleanup and aggregate checks callers built with `new`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Builds the Rbac checks the register and schema controllers, the MCP schema
 * tool and the aggregate read paths in Search constructed themselves.
 *
 * Each method constructs exactly what the caller used to construct itself with
 * `new`: a fresh instance with the same arguments. The checks therefore run in
 * the same order, with the same collaborators, while the callers name only this
 * class and the interfaces it returns. Types only: no check lives here.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacRuleChecks {

	/**
	 * Who may write a register or schema definition.
	 *
	 * @param IUserSession  $userSession  The session.
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @return DefinitionWriteRightsInterface A fresh check.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function definitionWriteRights(IUserSession $userSession, IGroupManager $groupManager): DefinitionWriteRightsInterface {
		return new DefinitionWriteRights(userSession: $userSession, groupManager: $groupManager);
	}//end definitionWriteRights()

	/**
	 * The cross-organisation marker comparison.
	 *
	 * @return CrossOrganisationMarkerChangeInterface A fresh check.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function crossOrganisationMarkerChange(): CrossOrganisationMarkerChangeInterface {
		return new CrossOrganisationMarkerChange();
	}//end crossOrganisationMarkerChange()

	/**
	 * Whether a save changes the orphan-cleanup flag.
	 *
	 * @return OrphanCleanupFlagInterface A fresh check.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function orphanCleanupFlag(): OrphanCleanupFlagInterface {
		return new OrphanCleanupFlag();
	}//end orphanCleanupFlag()

	/**
	 * Whether an aggregate may summarise a property.
	 *
	 * @param PropertyRbacHandlerInterface|null $rbac   The property-level read check.
	 * @param LoggerInterface|null              $logger The logger.
	 *
	 * @return AggregateVisibilityInterface A fresh check.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function aggregateVisibility(
		?PropertyRbacHandlerInterface $rbac=null,
		?LoggerInterface $logger=null
	): AggregateVisibilityInterface {
		return new AggregateVisibility(rbac: $rbac, logger: $logger);
	}//end aggregateVisibility()
}//end class
