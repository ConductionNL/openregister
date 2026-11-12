<?php

/**
 * An MCP update of a schema's authorization whose audit write fails: the
 * failure is logged as a warning naming the schema, and the update itself
 * still succeeds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\Rbac\DefinitionWriteRights;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SchemasToolProvider audit failure.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\SchemasToolProvider
 * @uses \OCA\OpenRegister\Mcp\BuiltIn\AbstractBuiltInToolProvider
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Rbac\DefinitionWriteRights
 * @uses \OCA\OpenRegister\Service\AuthorizationChange
 * @uses \OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange
 * @uses \OCA\OpenRegister\Service\Rbac\ExternalGrantGuard
 * @uses \OCA\OpenRegister\Service\Rbac\OrphanCleanupFlag
 * @uses \OCA\OpenRegister\Service\Rbac\RbacChecks
 * @uses \OCA\OpenRegister\Service\Rbac\RbacRuleChecks
 */
class SchemasToolProviderAuditFailureTest extends TestCase {

	private function rights(): DefinitionWriteRights {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		return new DefinitionWriteRights($session, $groupManager);
	}//end rights()

	public function testAFailingAuditIsLoggedAndTheUpdateStillSucceeds(): void {
		$stored = new Schema();
		$stored->setId(5);
		$stored->setAuthorization(['manage' => ['beheer']]);
		$saved = new Schema();
		$saved->setId(5);
		$saved->setTitle('Edited');
		$saved->setAuthorization(['manage' => ['beheer'], 'read' => ['hr']]);

		$service = $this->createMock(SchemaMapper::class);
		$service->method('find')->willReturn($stored);
		$service->method('updateFromArray')->willReturn($saved);
		$audit = $this->createMock(AuthorizationAuditService::class);
		$audit->expects($this->once())->method('recordSchemaChange')->willThrowException(new RuntimeException('audit table locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[SchemasToolProvider] Schema authorization change not written to the audit trail',
			['schemaId' => 5, 'exception' => 'audit table locked']
		);

		$provider = new SchemasToolProvider($service, $this->rights(), $audit, $logger);
		$result   = $provider->invokeTool(
			SchemasToolProvider::TOOL_ID,
			['action' => 'update', 'id' => 5, 'data' => ['authorization' => ['manage' => ['beheer'], 'read' => ['hr']]]]
		);

		$this->assertSame('Edited', $result['title']);
		$this->assertSame(5, $result['id']);
	}//end testAFailingAuditIsLoggedAndTheUpdateStillSucceeds()
}//end class
