<?php

/**
 * ErasureScopeCounter — what destroying an erasable object would take with it.
 *
 * Reads the destruction scope the run will later act through, so the preview's
 * file and timeline counts are the run's own. A scope that cannot be read is
 * counted as nothing and logged; it never makes an object look protected or
 * erasable, because the bucket is decided elsewhere.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts the files and timeline rows that go with an object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureScopeCounter {
	/**
	 * Wire the counter.
	 *
	 * @param DestructionScopeService $scopeService What goes with an object.
	 * @param ErasureSchemaResolver   $schemas      Schema resolution for the scope.
	 * @param LoggerInterface         $logger       PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DestructionScopeService $scopeService,
		private readonly ErasureSchemaResolver $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The destruction scope counts for an object, as files and timeline rows.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, int> Files and timeline counts.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function scopeCounts(ObjectEntity $object): array {
		try {
			$scope = $this->scopeService->preview(object: $object, schema: $this->schemas->schemaOf(object: $object));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ErasurePreview] Could not read the destruction scope',
				context: ['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
			$scope = [];
		}

		return [
			ErasureBucket::FILES => (int)($scope['counts']['files'] ?? 0),
			ErasureBucket::TIMELINE => (int)($scope['counts']['timeline'] ?? 0),
		];
	}//end scopeCounts()
}//end class
