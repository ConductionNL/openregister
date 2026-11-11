<?php

/**
 * Creates a destruction list for the objects another app names
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
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Service\DisposalSweep;
use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\SavePolicy;

/**
 * An app names the records it wants destroyed; OpenRegister decides which may be.
 *
 * Every uuid is judged by RetentionService::destructionRefusalIn(), the rule the
 * daily DestructionCheckJob applies, so an app cannot put a held, kept, frozen
 * or not-yet-due record on a list. The list is saved exactly as the sweep saves
 * one, so the review, approval and execution that follow do not know or care
 * who asked for it.
 */
class DestructionListCreator implements DestructionListCreatorInterface {

	/**
	 * A uuid the caller named that does not resolve to an object.
	 */
	public const REFUSAL_NOT_FOUND = 'not_found';

	/**
	 * Constructor.
	 *
	 * @param RetentionServiceInterface       $retention       Judges each object and builds the list.
	 * @param DestructionListWriter $writer Reads the eligible objects and saves the new list.
	 * @param ArchivalSettingsReaderInterface $settingsHandler Names the destruction-list register and schema.
	 * @param LoggerInterface        $logger          Records who asked for a list.
	 */
	public function __construct(
		private readonly RetentionServiceInterface $retention,
		private readonly DestructionListWriter $writer,
		private readonly ArchivalSettingsReaderInterface $settingsHandler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a destruction list for the eligible objects among these uuids.
	 *
	 * @param array<int, string> $uuids The objects the caller wants destroyed.
	 *
	 * @return array{list: array<string, mixed>|null, refused: array<int, array{uuid: string, reason: string}>}
	 *         The stored list (with its `uuid`), or null when nothing was eligible, and every refused uuid.
	 *
	 * @throws InvalidArgumentException When no destruction-list register and schema are configured.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function createFor(array $uuids): array {
		$location = $this->listLocation();
		$judged = $this->judge(uuids: $uuids);
		if ($judged['eligible'] === []) {
			return ['list' => null, 'refused' => $judged['refused']];
		}

		$listData = $this->retention->createDestructionList($judged['eligible']);
		if ($listData === null) {
			throw new InvalidArgumentException('The destruction list could not be built from the archival settings.');
		}

		$saved = $this->store(location: $location, listData: $listData);
		$this->logCreated(saved: $saved, judged: $judged);

		return ['list' => (($saved->getObject() ?? $listData) + ['uuid' => $saved->getUuid()]), 'refused' => $judged['refused']];
	}//end createFor()

	/**
	 * Save a built list exactly as the sweep saves one.
	 *
	 * @param array{register: mixed, schema: mixed} $location The configured register and schema.
	 * @param array<string, mixed>                  $listData The built list.
	 *
	 * @return ObjectEntity The stored list.
	 */
	private function store(array $location, array $listData): ObjectEntity {
		// The sweep's own save call (DestructionCheckJob::run()): no RBAC or
		// multitenancy on the list, persisted, silent.
		$target  = new ObjectFindTarget(register: $location['register'], schema: $location['schema']);
		$options = new ObjectSaveOptions(target: $target, policy: new SavePolicy(silent: true));

		return $this->writer->saveObject->saveObject(data: $listData, options: $options, _rbac: false, _multitenancy: false);
	}//end store()

	/**
	 * Log the stored list with how many uuids went on it and how many were refused.
	 *
	 * @param ObjectEntity                     $saved  The stored list.
	 * @param array<string, array<int, mixed>> $judged The judged uuids.
	 *
	 * @return void
	 */
	private function logCreated(ObjectEntity $saved, array $judged): void {
		$this->logger->info(
			'[DestructionListCreator] Created destruction list ' . (string)$saved->getUuid()
			. ' with ' . count($judged['eligible']) . ' entries, ' . count($judged['refused']) . ' refused'
		);
	}//end logCreated()

	/**
	 * The register and schema destruction lists are kept in.
	 *
	 * @return array{register: mixed, schema: mixed} The configured register and schema.
	 *
	 * @throws InvalidArgumentException When no destruction-list register and schema are configured.
	 */
	private function listLocation(): array {
		$settings = $this->settingsHandler->getArchivalSettingsOnly();
		$registerId = ($settings['destructionListRegister'] ?? null);
		$schemaId = ($settings['destructionListSchema'] ?? null);
		if (empty($registerId) === true || empty($schemaId) === true) {
			throw new InvalidArgumentException(
				'No destruction list register and schema are configured, so there is nowhere to keep a list. '
				. 'Set them under Retention settings first.'
			);
		}

		return ['register' => $registerId, 'schema' => $schemaId];
	}//end listLocation()

	/**
	 * Judge every named uuid once, by the sweep's own rule.
	 *
	 * @param array<int, string> $uuids The objects the caller wants destroyed.
	 *
	 * @return array{eligible: array<int, ObjectEntity>, refused: array<int, array{uuid: string, reason: string}>}
	 *         The eligible objects and the refused uuids, both in the order named.
	 */
	private function judge(array $uuids): array {
		$sweep = new DisposalSweep(today: (new DateTime())->format('Y-m-d'), excludeUuids: $this->retention->getObjectsOnPendingDestructionLists());

		$judged = ['eligible' => [], 'refused' => []];
		foreach (array_unique(array_map(static fn ($uuid): string => trim((string)$uuid), $uuids)) as $uuid) {
			$object = $this->load(uuid: $uuid);
			$reason = $this->refusalOf(object: $object, sweep: $sweep);
			if ($reason !== null) {
				$judged['refused'][] = ['uuid' => $uuid, 'reason' => $reason];
				continue;
			}

			$judged['eligible'][] = $object;
		}

		return $judged;
	}//end judge()

	/**
	 * Why a requested object may not be put on a destruction list, or null when it may.
	 *
	 * @param ObjectEntity|null $object The loaded object, null when it was not found.
	 * @param DisposalSweep     $sweep  Today, and the objects already on an open list.
	 *
	 * @return string|null The refusal reason.
	 */
	private function refusalOf(?ObjectEntity $object, DisposalSweep $sweep): ?string {
		if ($object === null) {
			return self::REFUSAL_NOT_FOUND;
		}

		return $this->retention->destructionRefusalIn(object: $object, sweep: $sweep);
	}//end refusalOf()

	/**
	 * One object by uuid, or null when there is none.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function load(string $uuid): ?ObjectEntity {
		if ($uuid === '') {
			return null;
		}

		try {
			return $this->writer->objectMapper->find($uuid, null, null, false, false, false);
		} catch (Throwable $e) {
			return null;
		}
	}//end load()
}//end class
