<?php

/**
 * RbacChecks: builds the Rbac checks that callers outside lib/Service used to construct with `new`.
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

use OCP\IDBConnection;

/**
 * Builds the metadata-condition, unknown-rule-key, external-grant and
 * view-share checks for the mappers, the command, the job, the schema
 * controller and the MCP schema tool.
 *
 * Each method constructs exactly what the caller used to construct itself with
 * `new`: a fresh instance with the same arguments. The checks therefore run in
 * the same order, with the same collaborators, while the callers name only this
 * class and the interfaces it returns. Types only: no check lives here.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacChecks {

	/**
	 * The metadata-condition findings.
	 *
	 * @param IDBConnection|null $db Reads the stored block when a save has findings; without it a save is judged as new.
	 *
	 * @return MetadataConditionFindingsInterface A fresh check.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function metadataConditions(?IDBConnection $db=null): MetadataConditionFindingsInterface {
		return new MetadataConditionFindings(db: $db);
	}//end metadataConditions()

	/**
	 * The unknown-rule-key findings.
	 *
	 * @param IDBConnection|null $db Reads the stored block when a save has findings; without it a save is judged as new.
	 *
	 * @return UnknownRuleKeyFindingsInterface A fresh check.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function unknownRuleKeys(?IDBConnection $db=null): UnknownRuleKeyFindingsInterface {
		return new UnknownRuleKeyFindings(db: $db);
	}//end unknownRuleKeys()

	/**
	 * The external-grant guard.
	 *
	 * @return ExternalGrantGuardInterface A fresh guard.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function externalGrants(): ExternalGrantGuardInterface {
		return new ExternalGrantGuard();
	}//end externalGrants()

	/**
	 * The saved-view share resolver.
	 *
	 * @return ViewShareResolverInterface A fresh resolver.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public static function viewShares(): ViewShareResolverInterface {
		return new ViewShareResolver();
	}//end viewShares()
}//end class
