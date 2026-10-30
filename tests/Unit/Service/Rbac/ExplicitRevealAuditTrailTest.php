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
}//end class
