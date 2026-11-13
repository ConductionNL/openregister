<?php

/**
 * OpenRegister AutomationRecordService
 *
 * Reads automation run records for the signed-in caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * The read of automation run records (D-4), for the route and for an app in process.
 *
 * An admin sees every record. Anyone else sees a record only when they may
 * read its object under the ordinary rules: the object is looked up with RBAC
 * and multitenancy on, and a lookup that refuses or fails hides the record
 * (fail closed). Nobody signed in sees nothing. Records never name recipients.
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
class AutomationRecordService {

	/**
	 * How many stored pages a non-admin read scans to fill one visible page.
	 */
	private const MAX_SCANNED_PAGES = 5;

	/**
	 * Whether the caller may read an object, per uuid, for one read.
	 *
	 * @var array<string, bool>
	 */
	private array $readable = [];

	/**
	 * Constructor.
	 *
	 * @param AutomationRecordMapper    $mapper       The record store.
	 * @param ObjectOperationsInterface $objects      Looks objects up under the caller's rules.
	 * @param IUserSession              $userSession  The signed-in caller.
	 * @param IGroupManager             $groupManager Tells an admin apart.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function __construct(
		private readonly AutomationRecordMapper $mapper,
		private readonly ObjectOperationsInterface $objects,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The records the signed-in caller may see, newest first.
	 *
	 * The page's offset counts stored records; a non-admin's page may hold fewer
	 * than `limit` records when some are hidden from them.
	 *
	 * @param AutomationRecordFilter $filter The filters and page.
	 *
	 * @return array<int, AutomationRecord> The visible records.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md#requirement-every-dispatch-of-a-declared-notification-leaves-a-readable-record
	 */
	public function find(AutomationRecordFilter $filter): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return $this->mapper->findFiltered($filter);
		}

		return $this->visiblePage(filter: $filter);
	}//end find()

	/**
	 * Fill one page with records whose object the caller may read.
	 *
	 * @param AutomationRecordFilter $filter The filters and page.
	 *
	 * @return array<int, AutomationRecord> The visible records.
	 */
	private function visiblePage(AutomationRecordFilter $filter): array {
		$visible = [];
		$offset  = $filter->offset;
		for ($page = 0; $page < self::MAX_SCANNED_PAGES && count($visible) < $filter->limit; $page++) {
			$stored = $this->mapper->findFiltered($filter->page(limit: $filter->limit, offset: $offset));
			foreach ($stored as $record) {
				if (count($visible) < $filter->limit && $this->mayRead(record: $record) === true) {
					$visible[] = $record;
				}
			}

			if (count($stored) < $filter->limit) {
				break;
			}

			$offset += $filter->limit;
		}

		return $visible;
	}//end visiblePage()

	/**
	 * Whether the caller may read a record's object.
	 *
	 * @param AutomationRecord $record The record.
	 *
	 * @return bool True when the object answers this caller.
	 */
	private function mayRead(AutomationRecord $record): bool {
		$uuid = (string)$record->getObjectUuid();
		if ($uuid === '') {
			return false;
		}

		if (array_key_exists($uuid, $this->readable) === false) {
			$this->readable[$uuid] = $this->lookUp(uuid: $uuid);
		}

		return $this->readable[$uuid];
	}//end mayRead()

	/**
	 * Look an object up under the caller's rules.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return bool True when it answers.
	 */
	private function lookUp(string $uuid): bool {
		try {
			$object = $this->objects->find(
				id: $uuid,
				options: new ObjectFindOptions(render: false, audit: false),
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $refused) {
			unset($refused);
			return false;
		}

		return $object instanceof ObjectEntity;
	}//end lookUp()
}//end class
