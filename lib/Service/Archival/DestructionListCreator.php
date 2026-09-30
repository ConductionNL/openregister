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

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An app names the records it wants destroyed; OpenRegister decides which may be.
 *
 * Every uuid is judged by RetentionService::destructionRefusal(), the rule the
 * daily DestructionCheckJob applies, so an app cannot put a held, kept, frozen
 * or not-yet-due record on a list. The list is saved exactly as the sweep saves
 * one, so the review, approval and execution that follow do not know or care
 * who asked for it.
 */
class DestructionListCreator {

	/**
	 * A uuid the caller named that does not resolve to an object.
	 */
	public const REFUSAL_NOT_FOUND = 'not_found';

	/**
	 * Constructor.
	 *
	 * @param RetentionService       $retention       Judges each object and builds the list.
	 * @param MagicMapper            $objectMapper    Loads the named objects.
	 * @param SaveObject             $saveObject      Stores the list as the sweep does.
	 * @param ObjectRetentionHandler $settingsHandler Names the destruction-list register and schema.
	 * @param LoggerInterface        $logger          Records who asked for a list.
	 */
	public function __construct(
		private readonly RetentionService $retention,
		private readonly MagicMapper $objectMapper,
		private readonly SaveObject $saveObject,
		private readonly ObjectRetentionHandler $settingsHandler,
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
		$settings = $this->settingsHandler->getArchivalSettingsOnly();
		$registerId = ($settings['destructionListRegister'] ?? null);
		$schemaId = ($settings['destructionListSchema'] ?? null);
		if (empty($registerId) === true || empty($schemaId) === true) {
			throw new InvalidArgumentException(
				'No destruction list register and schema are configured, so there is nowhere to keep a list. '
				. 'Set them under Retention settings first.'
			);
		}

		$today = (new DateTime())->format('Y-m-d');
		$onOpenLists = $this->retention->getObjectsOnPendingDestructionLists();

		$eligible = [];
		$refused = [];
		$seen = [];
		foreach ($uuids as $uuid) {
			$uuid = trim((string)$uuid);
			if (isset($seen[$uuid]) === true) {
				continue;
			}

			$seen[$uuid] = true;
			$object = $this->load(uuid: $uuid);
			if ($object === null) {
				$refused[] = ['uuid' => $uuid, 'reason' => self::REFUSAL_NOT_FOUND];
				continue;
			}

			$reason = $this->retention->destructionRefusal(object: $object, today: $today, excludeUuids: $onOpenLists);
			if ($reason !== null) {
				$refused[] = ['uuid' => $uuid, 'reason' => $reason];
				continue;
			}

			$eligible[] = $object;
		}//end foreach

		if ($eligible === []) {
			return ['list' => null, 'refused' => $refused];
		}

		$listData = $this->retention->createDestructionList($eligible);
		if ($listData === null) {
			throw new InvalidArgumentException('The destruction list could not be built from the archival settings.');
		}

		// The sweep's own save call (DestructionCheckJob::run()): no RBAC or
		// multitenancy on the list, persisted, silent.
		$saved = $this->saveObject->saveObject(
			$registerId,
			$schemaId,
			$listData,
			null,
			null,
			false,
			false,
			true,
			true
		);

		$this->logger->info(
			'[DestructionListCreator] Created destruction list ' . (string)$saved->getUuid()
			. ' with ' . count($eligible) . ' entries, ' . count($refused) . ' refused'
		);

		return [
			'list' => (($saved->getObject() ?? $listData) + ['uuid' => $saved->getUuid()]),
			'refused' => $refused,
		];
	}//end createFor()

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
			return $this->objectMapper->find($uuid, null, null, false, false, false);
		} catch (Throwable $e) {
			return null;
		}
	}//end load()
}//end class
