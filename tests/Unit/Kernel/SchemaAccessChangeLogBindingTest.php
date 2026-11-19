<?php

/**
 * Tests for the schema access-change log port: bound to the authorization audit, same event type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Core\SchemaAccessChangeLogInterface;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Rbac\ReachRevocationLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Core tells the port about a schema's authorization change; the authorization audit answers it.
 */
final class SchemaAccessChangeLogBindingTest extends TestCase {

	/**
	 * The port is bound to the authorization audit.
	 *
	 * @return void
	 */
	public function testThePortIsBoundToTheAuthorizationAudit(): void {
		$this->assertSame(AuthorizationAuditService::class, ContractBindings::BINDINGS[SchemaAccessChangeLogInterface::class] ?? null);
		$this->assertTrue(is_subclass_of(AuthorizationAuditService::class, SchemaAccessChangeLogInterface::class));
	}//end testThePortIsBoundToTheAuthorizationAudit()

	/**
	 * The event type log readers filter on is the same string as before.
	 *
	 * @return void
	 */
	public function testTheEventTypeIsUnchanged(): void {
		$this->assertSame('openregister_authorization', SchemaAccessChangeLogInterface::EVENT_TYPE);
		$this->assertSame('openregister_authorization', AuthorizationAuditService::EVENT_TYPE);
	}//end testTheEventTypeIsUnchanged()

	/**
	 * The reach revocation log takes the port, so the audit is reached without naming it.
	 *
	 * @return void
	 */
	public function testTheRevocationLogTakesThePort(): void {
		$port = $this->createMock(SchemaAccessChangeLogInterface::class);

		$this->assertSame($port, (new ReachRevocationLog(audit: $port, logger: new NullLogger()))->audit);
	}//end testTheRevocationLogTakesThePort()
}//end class
