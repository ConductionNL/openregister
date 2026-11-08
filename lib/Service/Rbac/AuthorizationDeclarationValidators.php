<?php

/**
 * AuthorizationDeclarationValidators: the RBAC checks a register or schema save builds, behind their interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;

/**
 * Builds the authorization-declaration checks the save paths run.
 *
 * The register and schema save paths live in the Db layer and have no
 * container: each builds its check with `new`, a fresh instance per call, with
 * the collaborators it holds. This class does exactly that, one method per
 * check, and hands the instance back typed against the check's interface, so
 * the Db layer names the interface and never the class that implements it.
 *
 * Types only: which check runs, with what, and in which order stays with the
 * caller.
 */
final class AuthorizationDeclarationValidators {

	/**
	 * A fresh permission catalogue, dispatching the catalogue event through the given dispatcher.
	 *
	 * @param IEventDispatcher|null $eventDispatcher The dispatcher the catalogue asks apps through.
	 *
	 * @return PermissionCatalogueInterface
	 */
	public static function permissionCatalogue(?IEventDispatcher $eventDispatcher): PermissionCatalogueInterface {
		return new PermissionCatalogue(eventDispatcher: $eventDispatcher);
	}//end permissionCatalogue()

	/**
	 * A fresh deny-block validator over a fresh deny resolver.
	 *
	 * @return AuthorizationDenyValidatorInterface
	 */
	public static function denyBlocks(): AuthorizationDenyValidatorInterface {
		return new AuthorizationDenyValidator(denyResolver: new DenyResolver());
	}//end denyBlocks()

	/**
	 * A fresh metadata-condition check.
	 *
	 * @param IDBConnection|null $db The connection the check reads stored blocks through.
	 *
	 * @return MetadataConditionFindingsInterface
	 */
	public static function metadataConditions(?IDBConnection $db = null): MetadataConditionFindingsInterface {
		return new MetadataConditionFindings(db: $db);
	}//end metadataConditions()

	/**
	 * A fresh unknown-rule-key check.
	 *
	 * @param IDBConnection|null $db The connection the check reads stored blocks through.
	 *
	 * @return UnknownRuleKeyFindingsInterface
	 */
	public static function unknownRuleKeys(?IDBConnection $db = null): UnknownRuleKeyFindingsInterface {
		return new UnknownRuleKeyFindings(db: $db);
	}//end unknownRuleKeys()

	/**
	 * A fresh department-matrix validator that also accepts the schema's declared actions.
	 *
	 * @param array<int, string> $declaredActions The schema's declared action names.
	 *
	 * @return DepartmentMatrixValidatorInterface
	 */
	public static function departmentMatrix(array $declaredActions): DepartmentMatrixValidatorInterface {
		return new DepartmentMatrixValidator(declaredActions: $declaredActions);
	}//end departmentMatrix()

	/**
	 * A fresh hierarchy annotation validator.
	 *
	 * @return HierarchyAnnotationValidatorInterface
	 */
	public static function hierarchy(): HierarchyAnnotationValidatorInterface {
		return new HierarchyAnnotationValidator();
	}//end hierarchy()
}//end class
