<?php

/**
 * What the next destruction run would list, and what it holds back by inheritance
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\DisposalSweep;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the destruction rule without writing anything.
 *
 * It asks the same question the destruction check asks
 * ({@see RetentionServiceInterface::destructionRefusalIn()}, with the objects
 * already on a pending list excluded) and reports two things: the objects the
 * next run would put on a destruction list, and every object held back only
 * because an ancestor is kept permanently or could not be read, naming that
 * ancestor and its appraisal.
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */
class DestructionDryRun {

	/**
	 * Constructor.
	 *
	 * @param RetentionServiceInterface  $retention The destruction rule and the retention scan.
	 * @param MagicObjectFinderInterface $objects   Reads an ancestor to name it.
	 * @param LoggerInterface            $logger    Logs an ancestor that could not be named.
	 */
	public function __construct(
		private readonly RetentionServiceInterface $retention,
		private readonly MagicObjectFinderInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The objects the next run would list and those held back by inheritance.
	 *
	 * @return array{eligible: array<int, array<string, string>>, heldBack: array<int, array<string, mixed>>, scanned: int, truncated: bool}
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
	 */
	public function run(): array {
		$sweep    = new DisposalSweep(
			today: (new DateTime())->format('Y-m-d'),
			excludeUuids: $this->retention->getObjectsOnPendingDestructionLists()
		);
		$heldBack = [];
		$scan     = $this->retention->scanObjectsWithRetention(
			accept: function (ObjectEntity $object) use ($sweep, &$heldBack): bool {
				$refusal = $this->retention->destructionRefusalIn(object: $object, sweep: $sweep);
				if ($refusal !== null && self::isInherited(reason: $refusal) === true) {
					$heldBack[] = $this->heldBackEntry(object: $object, reason: $refusal);
				}

				return $refusal === null;
			}
		);

		$eligible = [];
		foreach ($scan['objects'] as $object) {
			$eligible[] = self::summary(object: $object);
		}

		return [
			'eligible'  => $eligible,
			'heldBack'  => $heldBack,
			'scanned'   => (int) $scan['scanned'],
			'truncated' => (bool) $scan['truncated'],
		];
	}//end run()

	/**
	 * Whether a refusal comes from an inherited appraisal.
	 *
	 * @param string $reason The refusal.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
	 */
	public static function isInherited(string $reason): bool {
		return str_starts_with($reason, InheritedAppraisal::REASON_PERMANENT_ANCESTOR.':') === true
			|| str_starts_with($reason, InheritedAppraisal::REASON_UNRESOLVED_ANCESTOR.':') === true;
	}//end isInherited()

	/**
	 * One held-back object with the ancestor that holds it.
	 *
	 * @param ObjectEntity $object The object held back.
	 * @param string       $reason The InheritedAppraisal reason.
	 *
	 * @return array<string, mixed>
	 */
	private function heldBackEntry(ObjectEntity $object, string $reason): array {
		$entry = self::summary(object: $object);
		$parts = explode(':', $reason, 3);
		$entry['reason'] = $parts[0];

		if ($parts[0] === InheritedAppraisal::REASON_UNRESOLVED_ANCESTOR) {
			$entry['ancestor'] = [
				'uuid'      => (string) ($parts[2] ?? ''),
				'name'      => null,
				'appraisal' => null,
				'property'  => (string) ($parts[1] ?? ''),
			];

			return $entry;
		}

		$entry['ancestor'] = $this->ancestor(uuid: (string) ($parts[1] ?? ''));

		return $entry;
	}//end heldBackEntry()

	/**
	 * Name an ancestor kept permanently; its appraisal is read from the ancestor itself.
	 *
	 * @param string $uuid The ancestor's uuid.
	 *
	 * @return array{uuid: string, name: string|null, appraisal: string|null, property: null}
	 */
	private function ancestor(string $uuid): array {
		$ancestor = ['uuid' => $uuid, 'name' => null, 'appraisal' => null, 'property' => null];
		try {
			$found = $this->objects->findAcrossAllSources(identifier: $uuid, includeDeleted: false, _rbac: false, _multitenancy: false)['object'];
		} catch (Throwable $failure) {
			$this->logger->info(
				'[DestructionDryRun] The ancestor holding an object back could not be read to name it',
				['ancestor' => $uuid, 'exception' => $failure->getMessage()]
			);
			return $ancestor;
		}

		if ($found instanceof ObjectEntity === false) {
			return $ancestor;
		}

		$ancestor['name'] = $found->getName();
		$appraisal        = (($found->getRetention() ?? [])['archiefnominatie'] ?? null);
		if (is_string($appraisal) === true) {
			$ancestor['appraisal'] = $appraisal;
		}

		return $ancestor;
	}//end ancestor()

	/**
	 * The uuid, name and schema of an object.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, string>
	 */
	private static function summary(ObjectEntity $object): array {
		return [
			'uuid'   => (string) $object->getUuid(),
			'name'   => (string) ($object->getName() ?? ''),
			'schema' => (string) $object->getSchema(),
		];
	}//end summary()
}//end class
