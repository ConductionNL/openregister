<?php

/**
 * The public principal exports only what a schema's export grant gives it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/export-open-formats-and-public-download/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RuleRefusedException;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ExportRightService::class)]
#[UsesClass(ExportRefusedException::class)]
#[UsesClass(RuleRefusedException::class)]
#[UsesClass(Schema::class)]
final class ExportRightServiceAnonymousTest extends TestCase {

	/**
	 * The service with this authorization block, and no session.
	 *
	 * @param array|null $authorization The schema's resolved authorization, or null.
	 * @param bool       $throws        Whether resolving it fails.
	 *
	 * @return ExportRightService
	 */
	private function service(?array $authorization, bool $throws = false): ExportRightService {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		if ($throws === true) {
			$permissions->method('resolveAuthorization')->willThrowException(new RuntimeException('broken'));
		} else {
			$permissions->method('resolveAuthorization')->willReturn($authorization);
		}

		// The verdict for the public is read from the grant itself, never from
		// a per-user permission check that has no user to ask about.
		$permissions->expects($this->never())->method('hasPermission');

		return new ExportRightService(
			permissionHandler: $permissions,
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * The schema being exported.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('besluit');

		return $schema;
	}//end schema()

	/**
	 * An export list naming `public` lets the public export.
	 *
	 * @return void
	 */
	public function testAnExplicitPublicExportGrantAllows(): void {
		$this->assertNull(
			$this->service(authorization: ['read' => ['redactie'], 'export' => ['public']])->refusalForAnonymous(schema: $this->schema())
		);
	}//end testAnExplicitPublicExportGrantAllows()

	/**
	 * Without an export list the read list stands in, and `public` in it allows.
	 *
	 * @return void
	 */
	public function testAPublicReadGrantAllowsWhileExportIsUndeclared(): void {
		$this->assertNull(
			$this->service(authorization: ['read' => ['public', 'redactie']])->refusalForAnonymous(schema: $this->schema())
		);
	}//end testAPublicReadGrantAllowsWhileExportIsUndeclared()

	/**
	 * A rule entry for the public group counts like the bare name.
	 *
	 * @return void
	 */
	public function testAPublicRuleEntryAllows(): void {
		$this->assertNull(
			$this->service(authorization: ['export' => [['group' => 'public', 'match' => ['status' => 'gepubliceerd']]]])
				->refusalForAnonymous(schema: $this->schema())
		);
	}//end testAPublicRuleEntryAllows()

	/**
	 * Readable by the public but exportable only by an editor: 401 `not-public`, evaluating `export`.
	 *
	 * @return void
	 */
	public function testAReadableButNotExportableSchemaIsRefused(): void {
		$refusal = $this->service(authorization: ['read' => ['public'], 'export' => ['redactie']])
			->refusalForAnonymous(schema: $this->schema());

		$this->assertNotNull($refusal);
		$this->assertSame('not-public', $refusal->getRule());
		$this->assertSame(401, $refusal->getStatusCode());
		$this->assertSame('export', $refusal->toResponseBody()['evaluated']);
		$this->assertSame('Exporting this schema requires an account.', $refusal->getMessage());
	}//end testAReadableButNotExportableSchemaIsRefused()

	/**
	 * A schema without any public grant is refused, evaluating the read fallback.
	 *
	 * @return void
	 */
	public function testASchemaWithoutAPublicGrantIsRefused(): void {
		$refusal = $this->service(authorization: ['read' => ['medewerkers']])->refusalForAnonymous(schema: $this->schema());

		$this->assertNotNull($refusal);
		$this->assertSame('not-public', $refusal->getRule());
		$this->assertSame('read', $refusal->toResponseBody()['evaluated']);
	}//end testASchemaWithoutAPublicGrantIsRefused()

	/**
	 * No authorization block at all grants the public nothing.
	 *
	 * @return void
	 */
	public function testNoAuthorizationBlockIsRefused(): void {
		$refusal = $this->service(authorization: null)->refusalForAnonymous(schema: $this->schema());

		$this->assertNotNull($refusal);
		$this->assertSame(401, $refusal->getStatusCode());
	}//end testNoAuthorizationBlockIsRefused()

	/**
	 * An authorization that cannot be resolved refuses rather than grants.
	 *
	 * @return void
	 */
	public function testAnUnresolvableAuthorizationIsRefused(): void {
		$refusal = $this->service(authorization: null, throws: true)->refusalForAnonymous(schema: $this->schema());

		$this->assertNotNull($refusal);
		$this->assertSame('not-public', $refusal->getRule());
	}//end testAnUnresolvableAuthorizationIsRefused()
}//end class
