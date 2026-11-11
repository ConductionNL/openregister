<?php

/**
 * C12 cases: the RBAC helpers the query layer and the bulk endpoint reach.
 *
 * MagicRbacHandler and RbacResolvers type against the interfaces and get the
 * instances through RbacCollaborators (the grant resolver still under its
 * class id in the container); BulkController builds the manage gate there.
 * McpScope has no interface: only its static strip() is delegated.
 *
 * DenyResolver is not listed: RegisterMapper and AuthorizationBlockCheck
 * still build it on development until #6971 lands; after that only
 * lib/Service names it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Rbac;

return [
	'classes' => [
		Rbac\McpScope::class => null,
		Rbac\ObjectGrantResolver::class => Rbac\ObjectGrantResolverInterface::class,
		Rbac\SchemaManageGate::class => Rbac\SchemaManageGateInterface::class,
	],
	'kept' => [],
];
