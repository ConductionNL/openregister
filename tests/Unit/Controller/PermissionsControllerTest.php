<?php

/**
 * The permission catalogue, and the fact that it stays readable.
 *
 * PermissionsController used to hold two different auth postures: this
 * catalogue, which any signed-in caller may read, and three reports that
 * disclose the authorization model and are admin-only. The reports moved to
 * PermissionsAuditController; what is left is a vocabulary.
 *
 * Both halves matter. The catalogue is what a role editor reads to know which
 * verbs exist, so gating it would break the UI — and a split that tightened it
 * by accident is exactly the kind of mistake two postures in one class invite.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\PermissionsController;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PermissionsControllerTest extends TestCase {

	/**
	 * A controller in one deny-enforcement mode.
	 *
	 * @param string $mode One of DenyEnforcementMode::MODES.
	 *
	 * @return PermissionsController The controller under test.
	 */
	private function controllerFor(string $mode): PermissionsController {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($mode);

		return new PermissionsController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			catalogue: new PermissionCatalogue(),
			enforcement: new DenyEnforcementMode(appConfig: $appConfig, logger: new NullLogger())
		);
	}//end controllerFor()

	public function testTheCatalogueIsPublishedWithItsShape(): void {
		$response = $this->controllerFor(mode: DenyEnforcementMode::MODE_STAGING)->index();

		$this->assertSame(200, $response->getStatus());
		$body = $response->getData();

		$this->assertArrayHasKey('permissions', $body);
		$this->assertArrayHasKey('denyEnforcement', $body);
		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $body['denyEnforcement']);

		$verbs = array_column($body['permissions'], 'verb');
		$this->assertSame(['read', 'create', 'update', 'delete', 'destroy', 'list', 'manage'], $verbs);

		foreach ($body['permissions'] as $entry) {
			foreach (['verb', 'app', 'description', 'levels', 'canonical'] as $key) {
				$this->assertArrayHasKey($key, $entry, sprintf('a catalogue entry lost its "%s" key', $key));
			}
		}
	}//end testTheCatalogueIsPublishedWithItsShape()

	public function testTheCatalogueNeedsNoAdminGateToAnswer(): void {
		// It is built with no AdminGate at all, so a regression that started
		// gating it could not even construct. The catalogue is a vocabulary,
		// not a secret, and role editors read it as ordinary users.
		$response = $this->controllerFor(mode: DenyEnforcementMode::MODE_STAGING)->index();

		$this->assertSame(200, $response->getStatus());
	}//end testTheCatalogueNeedsNoAdminGateToAnswer()
}//end class
