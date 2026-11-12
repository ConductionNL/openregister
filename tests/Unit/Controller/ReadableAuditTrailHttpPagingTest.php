<?php

/**
 * The scoped audit list pages over HTTP: page two resumes where page one stopped.
 *
 * The request parameters reach the real lister through the real controller,
 * over a trail that honours the object filter, the offset and the limit, so a
 * cursor that does not open under the second request's scope shows up as a
 * second page that starts at the top again.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Support\AuditTrailListing;
use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Tests\Support\BuildsAuditCursors;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Two pages of the scoped list, as a client walks them.
 */
final class ReadableAuditTrailHttpPagingTest extends TestCase {
	use BuildsAuditCursors;

	/**
	 * The object whose trail is walked.
	 *
	 * @var string
	 */
	private const OBJECT = 'obj-own';

	/**
	 * A lister over a trail that answers per object filter, offset and limit.
	 *
	 * Thirty entries on one object, numbered in trail order, so a page that
	 * resumes at the wrong offset is visible.
	 *
	 * @return ReadableAuditTrailLister The lister.
	 */
	private function lister(): ReadableAuditTrailLister {
		$trail = [];
		for ($i = 0; $i < 30; $i++) {
			$entry = new AuditTrail();
			$entry->setUuid('audit-' . $i);
			$entry->setObjectUuid(self::OBJECT);
			$entry->setAction('update');
			$trail[] = $entry;
		}

		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findTrail')->willReturnCallback(AuditTrailListing::positional(
			static function (?int $limit = null, ?int $offset = null, ?array $filters = []) use ($trail): array {
				if (($filters['object_uuid'] ?? null) !== self::OBJECT) {
					return [];
				}

				return array_slice($trail, (int)$offset, (int)$limit);
			}
		));

		$object = new ObjectEntity();
		$object->setUuid(self::OBJECT);
		$object->setRegister('5');
		$object->setSchema('7');
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('findMultipleAcrossAllMagicTables')->willReturn([$object]);

		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('readerColumns')->willReturnArgument(0);
		$history->method('readerEntries')->willReturnCallback(
			static fn (ObjectEntity $located, array $rows): array => array_map(
				static fn (AuditTrail $row): array => ['uuid' => $row->getUuid()],
				$rows
			)
		);

		return new ReadableAuditTrailLister(
			auditTrailMapper: $mapper,
			objectMapper: $objects,
			history: $history,
			cursors: $this->auditCursors()
		);
	}//end lister()

	/**
	 * One request to the scoped list.
	 *
	 * @param ReadableAuditTrailLister $lister The lister the controller uses.
	 * @param array                    $params The query parameters.
	 *
	 * @return array The response body.
	 */
	private function get(ReadableAuditTrailLister $lister, array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$controller = AuditTrailControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(LogService::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(AuditHashService::class),
			$session,
			$this->createMock(IGroupManager::class),
			$lister,
			$this->createMock(AuditTrailPageService::class),
			$this->createMock(ObjectHistoryForReaders::class)
		);

		return $controller->readable()->getData();
	}//end get()

	public function testTheSecondPageResumesWhereTheFirstStopped(): void {
		$lister = $this->lister();

		$first = $this->get(lister: $lister, params: ['object_uuid' => self::OBJECT, 'limit' => '5']);
		$second = $this->get(
			lister: $lister,
			params: ['object_uuid' => self::OBJECT, 'limit' => '5', 'cursor' => $first['nextCursor']]
		);

		$this->assertSame(['audit-0', 'audit-1', 'audit-2', 'audit-3', 'audit-4'], array_column($first['results'], 'uuid'));
		$this->assertSame(['audit-5', 'audit-6', 'audit-7', 'audit-8', 'audit-9'], array_column($second['results'], 'uuid'));
	}//end testTheSecondPageResumesWhereTheFirstStopped()

	public function testTheUnderscoreCursorResumesToo(): void {
		$lister = $this->lister();

		$first = $this->get(lister: $lister, params: ['object_uuid' => self::OBJECT, 'limit' => '5']);
		$second = $this->get(
			lister: $lister,
			params: ['object_uuid' => self::OBJECT, 'limit' => '5', '_cursor' => $first['nextCursor']]
		);

		$this->assertSame('audit-5', $second['results'][0]['uuid']);
	}//end testTheUnderscoreCursorResumesToo()

	public function testACursorDoesNotResumeAWalkUnderAnotherFilter(): void {
		$lister = $this->lister();

		$first = $this->get(lister: $lister, params: ['object_uuid' => self::OBJECT, 'limit' => '5']);
		$other = $this->get(lister: $lister, params: ['object_uuid' => self::OBJECT, 'action' => 'update', 'limit' => '5', 'cursor' => $first['nextCursor']]);

		$this->assertSame('audit-0', $other['results'][0]['uuid']);
	}//end testACursorDoesNotResumeAWalkUnderAnotherFilter()
}//end class
