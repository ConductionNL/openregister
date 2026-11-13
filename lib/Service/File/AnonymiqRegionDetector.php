<?php

/**
 * The anonymiq ExApp as a region detector.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use InvalidArgumentException;
use OCP\App\IAppManager;
use RuntimeException;

/**
 * Calls anonymiq's `POST /api/v1/image/regions` with `{image: <base64>, page}`.
 *
 * The answer must be `{regions: [{page, box: {x, y, w, h}, entityType,
 * confidence}], detector: {name, version}}`; anything else is refused, so a
 * malformed answer is recorded as a failed run and never as "no regions".
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */
class AnonymiqRegionDetector implements IImageRegionDetector {

	/**
	 * The ExApp id.
	 */
	public const APP_ID = 'anonymiq';

	/**
	 * The ExApp route.
	 */
	public const ROUTE = '/api/v1/image/regions';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $apps      Whether AppAPI and anonymiq are enabled.
	 * @param ExAppJsonRequester $requester The signed request.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function __construct(
		private readonly IAppManager $apps,
		private readonly ExAppJsonRequester $requester,
	) {
	}//end __construct()

	/**
	 * The detector's name.
	 *
	 * @return string `anonymiq`.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function name(): string {
		return self::APP_ID;
	}//end name()

	/**
	 * Whether AppAPI and the anonymiq ExApp are both enabled.
	 *
	 * @return bool True when it can be called.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function isInstalled(): bool {
		return $this->apps->isEnabledForUser('app_api') === true && $this->apps->isEnabledForUser(self::APP_ID) === true;
	}//end isInstalled()

	/**
	 * Ask anonymiq for the regions of one image.
	 *
	 * @param string   $imageBytes The image.
	 * @param int|null $page       Its page, null for a standalone image.
	 *
	 * @return array{regions: list<array<string, mixed>>, detector: array{name: string, version: string}} The regions and the detector.
	 *
	 * @throws RuntimeException When the call fails or the answer is malformed.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function detect(string $imageBytes, ?int $page): array {
		$answer = $this->requester->request(
			appId: self::APP_ID,
			route: self::ROUTE,
			params: ['image' => base64_encode($imageBytes), 'page' => $page]
		);

		$detector = ($answer['detector'] ?? null);
		if (is_array($detector) === false
			|| is_string($detector['name'] ?? null) === false
			|| is_string($detector['version'] ?? null) === false
		) {
			throw new RuntimeException('anonymiq answered without detector.name and detector.version.');
		}

		$regions = ($answer['regions'] ?? null);
		if (is_array($regions) === false || array_is_list($regions) === false) {
			throw new RuntimeException('anonymiq answered without a regions list.');
		}

		$source = $detector['name'] . ' ' . $detector['version'];
		$out = [];
		foreach ($regions as $index => $region) {
			try {
				$out[] = ImageRegion::fromDetector(region: $region, source: $source)->toArray();
			} catch (InvalidArgumentException $e) {
				throw new RuntimeException(sprintf('anonymiq region %d is malformed: %s', $index, $e->getMessage()), 0, $e);
			}
		}

		return ['regions' => $out, 'detector' => ['name' => $detector['name'], 'version' => $detector['version']]];
	}//end detect()
}//end class
