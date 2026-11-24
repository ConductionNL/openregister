<?php

/**
 * RegisterAccessChangeLogBindingTest: the Core register access-change log resolves to the authorization audit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\RegisterAccessChangeLogInterface;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\ComplianceServiceBindings;
use PHPUnit\Framework\TestCase;

/**
 * The register write paths tell the Core port; the authorization audit answers it.
 */
final class RegisterAccessChangeLogBindingTest extends TestCase {

	public function testThePortIsBoundToTheAuthorizationAudit(): void {
		$this->assertSame(AuthorizationAuditService::class, ComplianceServiceBindings::BINDINGS[RegisterAccessChangeLogInterface::class] ?? null);
	}

	public function testTheAuthorizationAuditIsThePort(): void {
		$this->assertTrue(is_subclass_of(AuthorizationAuditService::class, RegisterAccessChangeLogInterface::class));
	}
}//end class
