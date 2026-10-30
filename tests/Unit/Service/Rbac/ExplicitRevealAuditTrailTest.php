<?php

/**
 * The single audit entry and every audit export show an explicit-reveal value
 * masked, for administrators too.
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
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\LogService
 * @covers \OCA\OpenRegister\Controller\AuditTrailController
 * @covers \OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker
 */
class ExplicitRevealAuditTrailTest extends TestCase {

	private const BSN = '123457609';

	private const MASK = '*****7609';

	/**
	 * An audit entry changing the explicit-reveal BSN.
	 *
	 * @return AuditTrail The entry.
	 */
	private function entry(): AuditTrail {
		$entry = new AuditTrail();
		$entry->setId(1);
		$entry->setSchema(7);
		$entry->setAction('update');
		$entry->setChanged(['citizenServiceNumber' => ['old' => null, 'new' => self::BSN]]);

		return $entry;
	}//end entry()

	/**
	 * A log service whose schema mapper knows the person schema.
	 *
	 * @param AuditTrailMapper $trails The audit mapper.
	 *
	 * @return LogService The service.
	 */
	private function logService(AuditTrailMapper $trails): LogService {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(
			[
				'displayName' => ['type' => 'string'],
				'citizenServiceNumber' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']],
			]
		);
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		return new LogService(
			$trails,
			$this->createMock(originalClassName: MagicMapper::class),
			$this->createMock(originalClassName: RegisterMapper::class),
			$schemas
		);
	}//end logService()

	/**
	 * GET /api/audit-trails/{id} shows the explicit value masked to an administrator.
	 *
	 * @return void
	 */
	public function testTheSingleEntryShowsItMasked(): void {
		$trails = $this->createMock(originalClassName: AuditTrailMapper::class);
		$trails->method('find')->willReturn($this->entry());
		$logService = $this->logService(trails: $trails);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$controller = new AuditTrailController(
			'openregister',
			$this->createMock(originalClassName: IRequest::class),
			$logService,
			$trails,
			$this->createMock(originalClassName: AuditHashService::class),
			$session,
			$groups,
			$this->createMock(originalClassName: ReadableAuditTrailLister::class),
			$this->createMock(originalClassName: AuditTrailPageService::class),
			$this->createMock(originalClassName: ObjectHistoryForReaders::class)
		);

		$response = $controller->show(id: 1);
		$encoded  = (string)json_encode(value: $response->getData());

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertStringNotContainsString(needle: self::BSN, haystack: $encoded);
		$this->assertStringContainsString(needle: self::MASK, haystack: $encoded);
	}//end testTheSingleEntryShowsItMasked()

	/**
	 * Every export format (the list export, the page export and the export job
	 * all render through exportRows) carries the mask, not the value.
	 *
	 * @return void
	 */
	public function testTheExportShowsItMasked(): void {
		$logService = $this->logService(trails: $this->createMock(originalClassName: AuditTrailMapper::class));

		foreach (['csv', 'json', 'xml', 'txt'] as $format) {
			$file = $logService->exportRows(format: $format, logs: [$this->entry()], config: ['includeChanges' => true]);

			$this->assertStringNotContainsString(needle: self::BSN, haystack: (string)$file['content'], message: $format);
			$this->assertStringContainsString(needle: self::MASK, haystack: (string)$file['content'], message: $format);
		}
	}//end testTheExportShowsItMasked()

	/**
	 * The list export reads its rows itself and masks them the same way.
	 *
	 * @return void
	 */
	public function testTheListExportShowsItMasked(): void {
		$trails = $this->createMock(originalClassName: AuditTrailMapper::class);
		$trails->method('findAll')->willReturn([$this->entry()]);

		$file = $this->logService(trails: $trails)->exportLogs(format: 'json', config: ['includeChanges' => true]);

		$this->assertStringNotContainsString(needle: self::BSN, haystack: (string)$file['content']);
	}//end testTheListExportShowsItMasked()

	/**
	 * The person schema, its BSN explicit-reveal.
	 *
	 * @return Schema The schema.
	 */
	private function personSchema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['citizenServiceNumber' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']]]);

		return $schema;
	}//end personSchema()

	/**
	 * An entry naming no schema masks every value, a list value included.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutASchemaAndAListValueAreMasked(): void {
		$masker = new ExplicitRevealTrailMasker(schemaMapper: $this->createMock(originalClassName: SchemaMapper::class));
		$rows   = $masker->mask(entries: [['schema' => null, 'changed' => ['pin' => '4321']], ['schema' => '', 'changed' => ['codes' => ['123457609']]]]);

		$this->assertSame(expected: '*****', actual: $rows[0]['changed']['pin']);
		$this->assertSame(expected: '*****', actual: $rows[1]['changed']['codes']);

		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn($this->personSchema());
		$listed = (new ExplicitRevealTrailMasker(schemaMapper: $schemas))->mask(
			entries: [['schema' => 7, 'changed' => ['citizenServiceNumber' => ['123457609', '987654321']]]]
		);
		$this->assertSame(expected: '*****', actual: $listed[0]['changed']['citizenServiceNumber']);
	}//end testAnEntryWithoutASchemaAndAListValueAreMasked()

	/**
	 * The scoped trail a non-administrator reads (`readable`) shows it masked.
	 *
	 * @return void
	 */
	public function testTheReadableTrailShowsItMasked(): void {
		$entry = $this->entry();
		$entry->setObjectUuid('obj-1');
		$trails = $this->createMock(originalClassName: AuditTrailMapper::class);
		$trails->method('findAll')->willReturnCallback(
			static function (?int $limit = null, ?int $offset = null) use ($entry): array {
				if (($offset ?? 0) > 0) {
					return [];
				}

				return [$entry];
			}
		);
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');
		$objects = $this->createMock(originalClassName: MagicMapper::class);
		$objects->method('findMultipleAcrossAllMagicTables')->willReturn([$object]);
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn($this->personSchema());
		$permissions = $this->createMock(originalClassName: PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn(true);
		$reads = $this->createMock(originalClassName: ReadEventVisibility::class);
		$reads->method('maySeeReadEvents')->willReturn(true);

		$lister = new ReadableAuditTrailLister(
			auditTrailMapper: $trails,
			objectMapper: $objects,
			schemaMapper: $schemas,
			permissionHandler: $permissions,
			readEvents: $reads
		);
		$encoded = (string)json_encode(value: $lister->page(userId: 'alice', limit: 20));

		$this->assertStringContainsString(needle: 'obj-1', haystack: $encoded);
		$this->assertStringNotContainsString(needle: self::BSN, haystack: $encoded);
	}//end testTheReadableTrailShowsItMasked()
}//end class
