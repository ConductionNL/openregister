<?php

/**
 * OpenRegister ReferencePreviewInvalidation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCP\Collaboration\Reference\IReferenceManager;

/**
 * Clears the Smart Picker and reference-provider previews of one saved object.
 *
 * Every canonical URL shape comes from ObjectPreviewFormatter::buildCanonicalUrls(),
 * the same pattern list the providers match against, and each cache prefix is
 * invalidated once; the object's deep link is invalidated too.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ReferencePreviewInvalidation {

	/**
	 * Hold the collaborators of one invalidation.
	 *
	 * @param IReferenceManager       $references The reference cache owner.
	 * @param ObjectPreviewFormatter  $formatter  The source of the canonical URLs.
	 * @param DeepLinkRegistryService $deepLinks  The source of the object's deep link.
	 */
	public function __construct(
		private readonly IReferenceManager $references,
		private readonly ObjectPreviewFormatter $formatter,
		private readonly DeepLinkRegistryService $deepLinks
	) {
	}//end __construct()

	/**
	 * Clear the previews of the saved object.
	 *
	 * @param SavedObjectScope $saved The saved object.
	 *
	 * @return void
	 */
	public function invalidate(SavedObjectScope $saved): void {
		$this->invalidateCanonicalUrls(saved: $saved);
		$this->invalidateDeepLink(saved: $saved);
	}//end invalidate()

	/**
	 * Invalidate each distinct cache prefix of the object's canonical URLs.
	 *
	 * @param SavedObjectScope $saved The saved object.
	 *
	 * @return void
	 */
	private function invalidateCanonicalUrls(SavedObjectScope $saved): void {
		$invalidated = [];
		$urls        = $this->formatter->buildCanonicalUrls(
			ref: new ObjectRef(register: (int) $saved->registerId, schema: (int) $saved->schemaId, id: (string) $saved->object->getUuid())
		);
		foreach ($urls as $url) {
			$prefix = $this->formatter->resolveCachePrefix(referenceText: $url);
			if (isset($invalidated[$prefix]) === false) {
				$this->references->invalidateCache(cachePrefix: $prefix);
				$invalidated[$prefix] = true;
			}
		}
	}//end invalidateCanonicalUrls()

	/**
	 * Invalidate the object's deep link, built from its fields plus the identity triple.
	 *
	 * @param SavedObjectScope $saved The saved object.
	 *
	 * @return void
	 */
	private function invalidateDeepLink(SavedObjectScope $saved): void {
		$registerId = (int) $saved->registerId;
		$schemaId   = (int) $saved->schemaId;
		$identity   = ['uuid' => (string) $saved->object->getUuid(), 'register' => $registerId, 'schema' => $schemaId];
		$url        = $this->deepLinks->resolveUrl(
			registerId: $registerId,
			schemaId: $schemaId,
			objectData: array_merge($saved->object->getObject(), $identity)
		);
		if ($url !== null) {
			$this->references->invalidateCache(cachePrefix: $url);
		}
	}//end invalidateDeepLink()
}//end class
