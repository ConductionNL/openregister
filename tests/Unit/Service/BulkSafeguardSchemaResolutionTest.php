<?php

/**
 * A bulk row whose schema could not be loaded must not be written unchecked.
 *
 * `applyBulkSafeguards()` enforces per-row RBAC against the row's schema. When
 * no schema resolves it has nothing to enforce against, and it used to let the
 * row through — the same branch that legitimately passes a malformed row on to
 * the downstream prep so it can produce a proper "invalid" error.
 *
 * Those two cases were indistinguishable because the resolver swallowed every
 * `\Throwable` into a bare `null`. A transient failure loading the schema the
 * caller NAMED therefore silently disabled the RBAC gate for the whole batch,
 * while the write still went ahead: the single-schema fast path re-resolves
 * `$schema` downstream and never consults the safeguard's opinion. That is the
 * fail-open shape gate-8 (unsafe-auth-resolver) exists to catch — the caller
 * reading "could not resolve" as "nothing to check".
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The bulk safeguard's behaviour when the default schema cannot be resolved.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 */
class BulkSafeguardSchemaResolutionTest extends TestCase {

	/**
	 * The schema store, mocked.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The per-row RBAC gate, mocked.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private PermissionHandler&MockObject $permissionHandler;

	/**
	 * The service under test.
	 *
	 * @var SaveObjects
	 */
	private SaveObjects $service;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// The schema cache is STATIC, so a schema cached by an earlier test in
		// the same process would resolve here and the throw would never happen.
		SaveObjects::clearSchemaCache();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);

		$this->service = new SaveObjects(
			$this->createMock(MagicMapper::class),
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SaveObject::class),
			$this->createMock(IUserSession::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->permissionHandler,
			$this->createMock(ValidateObject::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(AuditTrailMapper::class)
		);

	}//end setUp()

	/**
	 * Invoke the private safeguard and return `[passedRows, result]`.
	 *
	 * @param array $objects The raw rows.
	 * @param Schema|string|int|null $schema The call-level schema argument.
	 *
	 * @return array{0: array, 1: array} The rows that passed, and the accumulator.
	 */
	private function runSafeguards(array $objects, Schema|string|int|null $schema): array {
		$result = [
			'invalid' => [],
			'errors' => [],
			'statistics' => ['invalid' => 0, 'errors' => 0],
		];

		$method = new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards');
		$method->setAccessible(true);

		$passed = $method->invokeArgs(
			$this->service,
			[$objects, null, $schema, true, false, &$result]
		);

		return [$passed, $result];
	}//end runSafeguards()

	/**
	 * THE SECURITY PROPERTY. A schema the caller named but that cannot be
	 * loaded means the RBAC gate could not run — so the row is REFUSED, not
	 * waved through.
	 *
	 * Both halves are asserted: the row must not reach the writer, and the
	 * permission check must not have silently been skipped-and-forgotten.
	 *
	 * @return void
	 */
	public function testARowIsRefusedWhenItsNamedSchemaCannotBeResolved(): void {
		$this->schemaMapper->method('find')
			->willThrowException(new \RuntimeException('transient database failure'));

		// The gate never gets the chance to run — that is exactly the problem
		// being fixed, so the refusal must come from the safeguard itself.
		$this->permissionHandler->expects($this->never())->method('hasPermission');

		[$passed, $result] = $this->runSafeguards([['title' => 'smuggled']], 'zaak');

		$this->assertSame([], $passed, 'a row whose permissions could not be checked must not be written');
		$this->assertSame(1, $result['statistics']['invalid']);
		$this->assertStringContainsString(
			'Schema could not be resolved',
			$result['invalid'][0]['error']
		);

	}//end testARowIsRefusedWhenItsNamedSchemaCannotBeResolved()

	/**
	 * The positive control on the OTHER side of the same branch: a genuine
	 * mixed-schema batch (the caller named no schema at all) still passes its
	 * rows through to the downstream prep, which is where a malformed row gets
	 * its proper "invalid" shape. Without this, the refusal above is equally
	 * satisfied by rejecting everything schema-less.
	 *
	 * @return void
	 */
	public function testAMixedSchemaBatchIsStillPassedThrough(): void {
		$this->schemaMapper->expects($this->never())->method('find');

		[$passed, $result] = $this->runSafeguards([['title' => 'mixed']], null);

		$this->assertCount(1, $passed);
		$this->assertSame(0, $result['statistics']['invalid']);

	}//end testAMixedSchemaBatchIsStillPassedThrough()

	/**
	 * And the ordinary path is untouched: a schema that DOES resolve reaches
	 * the per-row RBAC gate, and a row the gate allows is written.
	 *
	 * @return void
	 */
	public function testAResolvableSchemaStillReachesTheRbacGate(): void {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('zaak');

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->permissionHandler->expects($this->once())
			->method('hasPermission')
			->willReturn(true);

		[$passed, $result] = $this->runSafeguards([['title' => 'legitimate']], 'zaak');

		$this->assertCount(1, $passed);
		$this->assertSame(0, $result['statistics']['invalid']);

	}//end testAResolvableSchemaStillReachesTheRbacGate()

	/**
	 * The gate is still the gate: a resolvable schema whose permission check
	 * says no rejects the row.
	 *
	 * @return void
	 */
	public function testAResolvableSchemaStillRefusesADeniedRow(): void {
		$schema = new Schema();
		$schema->setId(8);
		$schema->setSlug('zaak');

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->permissionHandler->method('hasPermission')->willReturn(false);

		[$passed, $result] = $this->runSafeguards([['title' => 'denied']], 'zaak');

		$this->assertSame([], $passed);
		$this->assertSame(1, $result['statistics']['invalid']);

	}//end testAResolvableSchemaStillRefusesADeniedRow()
}//end class
