<?php

/**
 * SaveObjects holds bulk creates to a schema's per-organisation cap (object-quota-per-organisation 4.1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md#requirement-a-create-past-an-organisations-cap-is-refused-req-oqp-002
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkRowRules;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SafeguardBatchRun;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkObjectQuota;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSaveRun;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Quota\ObjectQuotaServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The safeguard screen tells the quota which rows are updates; the quota's refusals land in the bulk result.
 */
class SaveObjectsBulkQuotaTest extends TestCase {

	private const STORED = '11111111-1111-4111-8111-111111111111';

	private const NEW = '33333333-3333-4333-8333-333333333333';

	private ObjectQuotaServiceInterface&MockObject $quotas;

	private ?BulkObjectQuota $quota;

	protected function setUp(): void {
		SaveObjects::clearSchemaCache();
		$this->quotas = $this->createMock(ObjectQuotaServiceInterface::class);
		$this->quotas->method('limitFor')->willReturn(1);
		$this->quota = new BulkObjectQuota($this->quotas, new NullLogger());
	}

	private function handler(): SaveObjects {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$stored = new ObjectEntity();
		$stored->setUuid(self::STORED);
		$stored->setOwner('alice');
		$stored->setOrganisation('org-a');
		$stored->setObject(['title' => 'stored']);
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('findObjectsByUuidsInRegisterSchema')->willReturn([self::STORED => $stored]);
		$mapper->method('callerOrganisationsHold')->willReturn(true);
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn(true);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(static function ($id): Schema {
			$schema = new Schema();
			$schema->setId((int)$id);
			$schema->setSlug('schedule');
			return $schema;
		});
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(static function ($id): Register {
			$register = new Register();
			$register->setId((int)$id);
			return $register;
		});

		return new SaveObjects(
			stores: new BulkSaveStores(catalog: new SchemaCatalog(registers: $registers, schemas: $schemas, tables: $mapper), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $session, organisationService: $this->createMock(OrganisationService::class), groupManager: $this->createMock(IGroupManager::class)),
			reporting: new BulkSaveReporting(logger: new NullLogger()),
			checks: new BulkRowChecks(permissionHandler: $permissions, rowRules: new BulkRowRules(quota: $this->quota))
		);
	}

	private static function prepared(string $uuid): array {
		return ['title' => $uuid, '@self' => ['uuid' => $uuid, 'id' => $uuid, 'register' => 10, 'schema' => 681, 'organisation' => 'org-a']];
	}

	private static function bulkRun(?array $storedUuids): BulkSaveRun {
		$run = new BulkSaveRun();
		$run->storedUuids = $storedUuids;
		$run->result = ['invalid' => [], 'errors' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]];

		return $run;
	}

	public function testTheSafeguardScreenNotesTheRowsThatUpdateAStoredObject(): void {
		$schema = new Schema();
		$schema->setId(681);
		$schema->setSlug('schedule');
		$register = new Register();
		$register->setId(10);
		$batch = new SafeguardBatchRun(
			objects: [['id' => self::STORED, 'title' => 'update'], ['title' => 'create']],
			defaults: new BulkBatchDefaults($register, $schema),
			flags: ['rbac' => true, 'multitenancy' => true, 'validation' => false],
			result: ['invalid' => [], 'statistics' => ['invalid' => 0, 'errors' => 0]]
		);

		$passed = (new ReflectionMethod(SaveObjects::class, 'applyBulkSafeguards'))->invoke($this->handler(), $batch);

		$this->assertCount(2, $passed);
		$this->assertTrue($batch->screened);
		$this->assertSame([self::STORED => true], $batch->storedUuids);
	}

	public function testCreatesPastTheCapLandInTheResultAndUpdatesPass(): void {
		$this->quotas->method('count')->with(10, $this->isInstanceOf(Schema::class), 'org-a')->willReturn(1);
		$run = self::bulkRun([self::STORED => true]);

		$passed = (new ReflectionMethod(SaveObjects::class, 'applyBulkQuota'))->invoke($this->handler(), $run, [self::prepared(self::STORED), self::prepared(self::NEW)]);

		$this->assertSame([self::STORED], array_map(static fn (array $row): string => $row['@self']['uuid'], $passed));
		$this->assertCount(1, $run->result['invalid']);
		$this->assertSame(self::NEW, $run->result['invalid'][0]['object']['@self']['uuid']);
		$this->assertSame('object-quota-exceeded', $run->result['invalid'][0]['code']);
		$this->assertSame('ObjectQuotaException', $run->result['invalid'][0]['type']);
		$this->assertSame('This organisation already holds 1 of at most 1 objects of schema "schedule".', $run->result['errors'][0]['error']);
		$this->assertSame(['invalid' => 1, 'errors' => 1], $run->result['statistics']);
	}

	public function testWithoutTheSafeguardScreenTheQuotaIsNotGuessed(): void {
		$this->quotas->expects($this->never())->method('count');
		$rows = [self::prepared(self::NEW)];

		$passed = (new ReflectionMethod(SaveObjects::class, 'applyBulkQuota'))->invoke($this->handler(), self::bulkRun(null), $rows);

		$this->assertSame($rows, $passed);
	}

	public function testWithoutAQuotaRuleTheRowsPass(): void {
		$this->quota = null;
		$rows = [self::prepared(self::NEW)];

		$passed = (new ReflectionMethod(SaveObjects::class, 'applyBulkQuota'))->invoke($this->handler(), self::bulkRun([]), $rows);

		$this->assertSame($rows, $passed);
	}
}
