<?php

/**
 * The per-object access report: which blocks, which roles, which trail.
 *
 * The report owns the reading of the rules so a controller cannot become the
 * place they are interpreted. These tests pin which register and schema it
 * resolves, which role definitions it hands on, what it reports when either
 * cannot be resolved, and how it reads the trail for the history.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\ObjectAccessHistory;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReportSources;
use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectAccessReport
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectAccessReportSources
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectAccessHistory
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyEnforcementMode
 * @uses   \OCA\OpenRegister\Service\Rbac\ReportedPlace
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses   \OCA\OpenRegister\Service\Rbac\DenyResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\HoldersRun
 * @uses   \OCA\OpenRegister\Service\Rbac\PermissionCatalogue
 * @uses   \OCA\OpenRegister\Service\Rbac\RoleActionMap
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleOrigin
 */
class ObjectAccessReportTest extends TestCase {

	/**
	 * The register lookup.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;

	/**
	 * The schema lookup.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * The audit trail.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $trail;

	/**
	 * The app configuration the deny mode reads.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The report under test.
	 *
	 * @var ObjectAccessReport
	 */
	private ObjectAccessReport $report;

	/**
	 * Build the report over mocked lookups and the real rule readers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registers = $this->createMock(RegisterMapperInterface::class);
		$this->schemas   = $this->createMock(SchemaMapperInterface::class);
		$this->trail     = $this->createMock(AuditTrailMapperInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($this->registers);
		$catalog->method('schemas')->willReturn($this->schemas);

		$this->report = new ObjectAccessReport(
			sources: new ObjectAccessReportSources(catalog: $catalog, auditTrailMapper: $this->trail),
			holders: new ObjectPermissionsResolver(),
			history: new ObjectAccessHistory(),
			enforcement: new DenyEnforcementMode($this->appConfig, new NullLogger())
		);
	}//end setUp()

	/**
	 * An object with a uuid, an owner and its own authorization block.
	 *
	 * @param array|null $authorization The object's own block.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(?array $authorization): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('5b8f0c3e-7f6a-4a2c-9d1e-3c2b1a0f9e8d');
		$object->setOwner('ana');
		$object->setAuthorization($authorization);

		return $object;
	}//end object()

	/**
	 * The set is read from all three levels, with the register's role definitions.
	 *
	 * @return void
	 */
	public function testTheSetIsReadFromObjectSchemaAndRegisterWithTheRegistersRoles(): void {
		$register = new Register();
		$register->setSlug('zaken');
		$register->setAuthorization(['read' => ['directie']]);
		$register->setConfiguration(['roles' => [['name' => 'behandelaar', 'actions' => ['read', 'update']]]]);

		$schema = new Schema();
		$schema->setSlug('dossier');
		$schema->setAuthorization(['roles' => ['behandelaar' => ['behandelaars']]]);

		$this->registers->expects($this->once())->method('find')->with('zaken')->willReturn($register);
		$this->schemas->expects($this->once())->method('find')->with(7)->willReturn($schema);
		$this->appConfig->method('getValueString')->willReturn('enforcing');

		$set = $this->report->setFor(
			object: $this->object(['read' => ['stagiairs']]),
			table: new RegisterSchemaRef(register: 'zaken', schema: 7)
		);

		$this->assertSame('5b8f0c3e-7f6a-4a2c-9d1e-3c2b1a0f9e8d', $set['object']);
		$this->assertSame('zaken', $set['register']);
		$this->assertSame('dossier', $set['schema']);
		$this->assertSame('ana', $set['owner']);
		$this->assertSame('enforcing', $set['denyEnforcement']);
		$this->assertSame([], $set['denied']);

		$byPrincipal = [];
		foreach ($set['holders'] as $holder) {
			$byPrincipal[$holder['principal']] = $holder;
		}

		$this->assertSame(['stagiairs', 'behandelaars', 'directie'], array_keys($byPrincipal));
		$this->assertSame('object', $byPrincipal['stagiairs']['rules'][0]['level']);
		$this->assertSame('register', $byPrincipal['directie']['rules'][0]['level']);

		// The role's verbs come from the register's own definition of it.
		$this->assertSame(['read', 'update'], $byPrincipal['behandelaars']['verbs']);
		$this->assertSame('behandelaar', $byPrincipal['behandelaars']['rules'][0]['role']);
	}//end testTheSetIsReadFromObjectSchemaAndRegisterWithTheRegistersRoles()

	/**
	 * A register or schema that cannot be resolved is reported as null, not thrown.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterAndSchemaLeaveOnlyTheObjectsOwnBlock(): void {
		$this->registers->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->schemas->method('find')->willThrowException(new \RuntimeException('database down'));
		$this->appConfig->method('getValueString')->willReturn('off');

		$set = $this->report->setFor(
			object: $this->object(['update' => ['redactie']]),
			table: new RegisterSchemaRef(register: 'weg', schema: 'weg')
		);

		$this->assertNull($set['register']);
		$this->assertNull($set['schema']);
		$this->assertSame('off', $set['denyEnforcement']);
		$this->assertSame(['redactie'], array_column($set['holders'], 'principal'));
		$this->assertSame(['update'], $set['holders'][0]['verbs']);
	}//end testAnUnresolvableRegisterAndSchemaLeaveOnlyTheObjectsOwnBlock()

	/**
	 * A deny on the object is reported beside the holders.
	 *
	 * @return void
	 */
	public function testADenyIsReportedBesideTheHolders(): void {
		$register = new Register();
		$register->setSlug('zaken');

		$this->registers->method('find')->willReturn($register);
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->appConfig->method('getValueString')->willReturn('nonsense');

		$set = $this->report->setFor(
			object: $this->object(['read' => ['waarnemers'], 'deny' => ['read' => ['waarnemers']]]),
			table: new RegisterSchemaRef(register: 1, schema: 2)
		);

		$this->assertSame('zaken', $set['register']);
		$this->assertSame(['waarnemers'], array_column($set['holders'], 'principal'));
		$this->assertSame(['waarnemers'], array_column($set['denied'], 'principal'));

		// An unknown configured mode falls back to the default, never to a stronger one.
		$this->assertSame(DenyEnforcementMode::DEFAULT_MODE, $set['denyEnforcement']);
	}//end testADenyIsReportedBesideTheHolders()

	/**
	 * The history reads up to the limit from the trail and reports the set as of a moment.
	 *
	 * @return void
	 */
	public function testTheHistoryReadsTheTrailUpToTheLimitAndReportsTheMoment(): void {
		$grant = new AuditTrail();
		$grant->setAction('update');
		$grant->setUser('ana');
		$grant->setCreated(new DateTime('2026-03-01T09:00:00+01:00'));
		$grant->setChanged(['authorization' => ['old' => null, 'new' => ['read' => ['juristen']]]]);

		$dataOnly = new AuditTrail();
		$dataOnly->setAction('update');
		$dataOnly->setUser('ana');
		$dataOnly->setCreated(new DateTime('2026-03-02T09:00:00+01:00'));
		$dataOnly->setChanged(['object' => ['old' => ['title' => 'oud'], 'new' => ['title' => 'nieuw']]]);

		$this->trail->expects($this->once())
			->method('findObjectActions')
			->with(
				'5b8f0c3e-7f6a-4a2c-9d1e-3c2b1a0f9e8d',
				$this->callback(
					static function (ActionLookupOptions $options): bool {
						return $options->actions === [] && $options->limit === ObjectAccessReport::HISTORY_LIMIT;
					}
				)
			)
			->willReturn([$grant, $dataOnly]);

		$history = $this->report->historyFor(object: $this->object(null), moment: '2026-04-01T00:00:00+02:00');

		$this->assertSame('5b8f0c3e-7f6a-4a2c-9d1e-3c2b1a0f9e8d', $history['object']);
		$this->assertSame('2026-04-01T00:00:00+02:00', $history['at']);
		$this->assertSame(2, $history['entriesRead']);
		$this->assertSame(200, $history['limit']);
		$this->assertCount(1, $history['changes'], 'only the change to the rules is a change in access');
		$this->assertSame(['juristen'], array_column($history['asOf']['holders'], 'principal'));
	}//end testTheHistoryReadsTheTrailUpToTheLimitAndReportsTheMoment()

	/**
	 * A trail that cannot be read is not turned into an empty history.
	 *
	 * @return void
	 */
	public function testAnUnreadableTrailIsNotReportedAsAnEmptyHistory(): void {
		$this->trail->method('findObjectActions')->willThrowException(new \RuntimeException('trail unavailable'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('trail unavailable');

		$this->report->historyFor(object: $this->object(null), moment: null);
	}//end testAnUnreadableTrailIsNotReportedAsAnEmptyHistory()

	/**
	 * The public schema lookup returns the schema, or null when it cannot be found.
	 *
	 * @return void
	 */
	public function testTheSchemaLookupReturnsNullRatherThanThrowing(): void {
		$schema = new Schema();
		$schema->setSlug('dossier');

		$this->schemas->method('find')->willReturnCallback(
			static function (string|int $id) use ($schema): Schema {
				if ($id === 'dossier') {
					return $schema;
				}

				throw new DoesNotExistException('no such schema');
			}
		);

		$this->assertSame($schema, $this->report->schema(reference: 'dossier'));
		$this->assertNull($this->report->schema(reference: 'onbekend'));
	}//end testTheSchemaLookupReturnsNullRatherThanThrowing()
}//end class
