<?php

/**
 * C12 cases: the export-profile services the endpoint and the whole-set bulk action reach.
 *
 * ExportProfilesController, its services bundle and ExportWholeSetAction type
 * against the interfaces; the container resolves each to the same shared
 * instance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Export;

return [
	'classes' => [
		Export\ExportProfileService::class => Export\ExportProfileServiceInterface::class,
		Export\ExportProfileWriter::class => Export\ExportProfileWriterInterface::class,
	],
	'kept' => [],
];
