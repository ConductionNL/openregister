<?php

/**
 * Where the lib/Db save paths get the authorization-declaration checks they run.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * Builds the authorization-declaration checks the register and schema save
 * paths in lib/Db run.
 *
 * Each method constructs exactly what the caller used to construct itself with
 * `new` — a fresh instance with the same arguments — so the checks run in the
 * same order with the same collaborators, while the callers name only this
 * class and the interfaces it returns. The annotation keys they read are
 * re-exported here for the same reason. Types only: no check lives here.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class AccessDeclarationValidators {

	/**
	 * The hierarchy annotation key.
	 *
	 * @var string
	 */
	public const HIERARCHY_ANNOTATION = HierarchyGrantExpander::ANNOTATION;

	/**
	 * The property-block flag that asks for every reveal to be audited.
	 *
	 * @var string
	 */
	public const REVEAL_AUDIT_KEY = RevealCollector::AUDIT_KEY;

	/**
	 * The permission catalogue, collecting declarations through the dispatcher.
	 *
	 * @param IEventDispatcher|null $eventDispatcher Collects the permission catalogue.
	 *
	 * @return PermissionCatalogueInterface A fresh catalogue.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function permissionCatalogue(?IEventDispatcher $eventDispatcher): PermissionCatalogueInterface {
		return new PermissionCatalogue(eventDispatcher: $eventDispatcher);
	}//end permissionCatalogue()

	/**
	 * The deny-against-grants check, over a fresh deny resolver.
	 *
	 * @return AuthorizationDenyValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function denyValidator(): AuthorizationDenyValidatorInterface {
		return new AuthorizationDenyValidator(denyResolver: new DenyResolver());
	}//end denyValidator()

	/**
	 * The hierarchy-annotation validator.
	 *
	 * @return HierarchyAnnotationValidatorInterface A fresh validator.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function hierarchy(): HierarchyAnnotationValidatorInterface {
		return new HierarchyAnnotationValidator();
	}//end hierarchy()
}//end class
