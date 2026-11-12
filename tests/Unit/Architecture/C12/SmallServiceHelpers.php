<?php

/**
 * C12 cases: small helpers the MCP, integrations, version-guard and reference-options code reach.
 *
 * McpController and GrantableRightsInvalidationListener type against
 * GrantableRightsIndexInterface; the version guard reads the conflict
 * constants from ConflictReportInterface and looks the report up (under its
 * class id) through ConflictReports; the reference-options answer builds its
 * reader through ReferenceOptionsReaders; the integrations endpoint builds the
 * pagination envelope through PaginatedResults. PaginatedResult has no
 * interface: the endpoint never needs its type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Authorization;
use OCA\OpenRegister\Service\Integration;
use OCA\OpenRegister\Service\Object as ObjectService;
use OCA\OpenRegister\Service\Schemas;

return [
	'classes' => [
		Authorization\GrantableRightsIndex::class => Authorization\GrantableRightsIndexInterface::class,
		Integration\PaginatedResult::class => null,
		ObjectService\ConflictReport::class => ObjectService\ConflictReportInterface::class,
		Schemas\ReferenceOptionsReader::class => Schemas\ReferenceOptionsReaderInterface::class,
	],
	'kept' => [],
];
