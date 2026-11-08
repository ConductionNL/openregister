<?php

/**
 * C12 cases: the RBAC declaration checks the register and schema save paths build.
 *
 * The Db layer reaches them through AuthorizationDeclarationValidators, typed
 * against their interfaces, and reads their constants from the interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Rbac;

return [
	'classes' => [
		Rbac\AuthorizationDenyValidator::class => Rbac\AuthorizationDenyValidatorInterface::class,
		Rbac\DepartmentMatrixCompiler::class => Rbac\DepartmentMatrixCompilerInterface::class,
		Rbac\DepartmentMatrixValidator::class => Rbac\DepartmentMatrixValidatorInterface::class,
		Rbac\HierarchyAnnotationValidator::class => Rbac\HierarchyAnnotationValidatorInterface::class,
		Rbac\HierarchyGrantExpander::class => Rbac\HierarchyGrantExpanderInterface::class,
		Rbac\MetadataConditionFindings::class => Rbac\MetadataConditionFindingsInterface::class,
		Rbac\PermissionCatalogue::class => Rbac\PermissionCatalogueInterface::class,
		Rbac\RevealCollector::class => Rbac\RevealCollectorInterface::class,
		Rbac\UnknownRuleKeyFindings::class => Rbac\UnknownRuleKeyFindingsInterface::class,
	],
	'kept' => [],
];
