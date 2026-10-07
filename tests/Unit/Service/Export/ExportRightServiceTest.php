<?php

/**
 * Unit tests for ExportRightService — exporting is a right, not a consequence
 * of reading.
 *
 * The case that used to pass is the one that matters: a principal holding
 * `read` could take the whole schema off the instance as a file, because the
 * endpoint asked for nothing at all. It now asks for `export`, and the refusal
 * names the verb.
 *
 * The second case that matters is the upgrade. A schema that has never heard of
 * the verb must keep exporting for whoever could export yesterday, or the
 * control lands as an outage and gets turned off in a hurry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ExportRightServiceTest extends TestCase {
	/**
	 * The verbs the permission handler was asked about, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	private function service(
		?string $uid,
		bool $isAdmin,
		?array $authorization,
		array $holds,
	): ExportRightService {
		$this->asked = [];

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('resolveAuthorization')->willReturn($authorization);
		$permissions->method('hasPermission')->willReturnCallback(
			function (...$args) use ($holds): bool {
				// PHPUnit invokes the callback with the declared parameters in
				// order, so the verb is the second one whether the caller used
				// named arguments or not.
				$action = (string)($args[1] ?? '');
				$this->asked[] = $action;

				return (bool)($holds[$action] ?? false);
			}
		);

		return new ExportRightService($permissions, $session, $groups, new NullLogger());
	}//end service()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('zaken');

		return $schema;
	}//end schema()

	public function testAReaderWhoMayNotTakeTheDataIsRefused(): void {
		$refusal = $this->service(
			'behandelaar-1',
			false,
			['read' => ['behandelaars'], 'export' => ['recordmanagers']],
			['read' => true, 'export' => false]
		)->refusalFor($this->schema());

		self::assertNotNull($refusal);
		self::assertSame('export-right-missing', $refusal->getRule());
		self::assertSame(403, $refusal->getStatusCode());
		self::assertSame('export', $refusal->toResponseBody()['verb']);
		self::assertSame('export', $refusal->toResponseBody()['evaluated']);
		self::assertStringContainsString('separate grants', $refusal->getMessage());
	}//end testAReaderWhoMayNotTakeTheDataIsRefused()

	public function testTheDeclaredExportGrantIsWhatIsEvaluated(): void {
		$this->service(
			'behandelaar-1',
			false,
			['read' => ['behandelaars'], 'export' => ['recordmanagers']],
			['read' => true, 'export' => false]
		)->refusalFor($this->schema());

		// The read grant must not be consulted once export is declared. If it
		// were, an administrator could never take export away from a reader.
		self::assertSame(['export'], $this->asked);
	}//end testTheDeclaredExportGrantIsWhatIsEvaluated()

	public function testTheHolderOfTheExportGrantMay(): void {
		self::assertNull(
			$this->service(
				'recordmanager-1',
				false,
				['read' => ['behandelaars'], 'export' => ['recordmanagers']],
				['export' => true]
			)->refusalFor($this->schema())
		);
	}//end testTheHolderOfTheExportGrantMay()

	public function testAnUpgradedInstanceKeepsExportingThroughTheReadGrant(): void {
		// The schema has never heard of the verb. Whoever could export before
		// the upgrade still can, and the read grant is what is evaluated.
		self::assertNull(
			$this->service(
				'behandelaar-1',
				false,
				['read' => ['behandelaars']],
				['read' => true, 'export' => false]
			)->refusalFor($this->schema())
		);

		self::assertSame(['read'], $this->asked);
	}//end testAnUpgradedInstanceKeepsExportingThroughTheReadGrant()

	public function testTheFallbackNarrowsWithReadRatherThanOpeningUp(): void {
		$refusal = $this->service(
			'buitenstaander',
			false,
			['read' => ['behandelaars']],
			['read' => false]
		)->refusalFor($this->schema());

		self::assertNotNull($refusal);
		self::assertSame('read', $refusal->toResponseBody()['evaluated']);
	}//end testTheFallbackNarrowsWithReadRatherThanOpeningUp()

	public function testAnUnresolvableSchemaRefuses(): void {
		$refusal = $this->service('behandelaar-1', false, ['export' => ['x']], ['export' => true])
			->refusalFor(null);

		self::assertNotNull($refusal);
		self::assertSame('schema-unresolvable', $refusal->getRule());
	}//end testAnUnresolvableSchemaRefuses()

	public function testAnAnonymousCallerRefusesWith401(): void {
		$refusal = $this->service(null, false, ['export' => ['x']], ['export' => true])
			->refusalFor($this->schema());

		self::assertNotNull($refusal);
		self::assertSame('not-authenticated', $refusal->getRule());
		self::assertSame(401, $refusal->getStatusCode());
	}//end testAnAnonymousCallerRefusesWith401()

	public function testAnAdministratorStillBypassesSoNoInstanceLocksItselfOut(): void {
		self::assertNull(
			$this->service('admin', true, null, [])->refusalFor($this->schema())
		);
	}//end testAnAdministratorStillBypassesSoNoInstanceLocksItselfOut()

	public function testTheScheduledRunnerIsCheckedAgainstItsOwnerNotTheSession(): void {
		// The session is empty, as it is inside a background job. The owner is
		// handed in, and the verb still holds.
		$refusal = $this->service(
			null,
			false,
			['export' => ['recordmanagers']],
			['export' => false]
		)->refusalForUid($this->schema(), 'eigenaar-1');

		self::assertNotNull($refusal);
		self::assertSame('export-right-missing', $refusal->getRule());
		self::assertStringContainsString('eigenaar-1', $refusal->getMessage());
	}//end testTheScheduledRunnerIsCheckedAgainstItsOwnerNotTheSession()

	public function testExportIsACanonicalActionSoASchemaCanDeclareIt(): void {
		$reflection = new \ReflectionClass(PermissionHandler::class);
		$canonical = $reflection->getConstant('CANONICAL_ACTIONS');

		self::assertIsArray($canonical);
		self::assertContains(ExportRightService::ACTION, $canonical);
	}//end testExportIsACanonicalActionSoASchemaCanDeclareIt()

	public function testExportIsInTheCatalogueSoAnAdministratorCanGrantIt(): void {
		self::assertArrayHasKey(ExportRightService::ACTION, PermissionCatalogue::CANONICAL);
		self::assertNotSame('', PermissionCatalogue::CANONICAL[ExportRightService::ACTION]);
	}//end testExportIsInTheCatalogueSoAnAdministratorCanGrantIt()
}//end class
