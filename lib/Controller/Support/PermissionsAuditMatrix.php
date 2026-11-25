<?php

/**
 * OpenRegister permissions audit matrix bundle
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Authorization\PermissionMatrixExporter;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixSubjects;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * What the matrix export needs: the exporter, the subjects, and the provenance sources.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
final class PermissionsAuditMatrix {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionMatrixExporter $exporter    Generates and seals the rows.
	 * @param PermissionMatrixSubjects $subjects    The groups or users to list.
	 * @param IEventDispatcher         $dispatcher  Carries the administrative audit row.
	 * @param IUserSession             $userSession Who asked.
	 * @param IConfig                  $config      The instance id.
	 * @param ITimeFactory             $time        When.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		public readonly PermissionMatrixExporter $exporter,
		public readonly PermissionMatrixSubjects $subjects,
		public readonly IEventDispatcher $dispatcher,
		public readonly IUserSession $userSession,
		public readonly IConfig $config,
		public readonly ITimeFactory $time,
	) {
	}//end __construct()
}//end class
