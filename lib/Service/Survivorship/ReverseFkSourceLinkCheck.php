<?php

/**
 * ReverseFkSourceLinkCheck
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * Checks the optional `sourceLink` block of an MDM schema annotation.
 *
 * The merge and survivorship annotations both accept the same block. A
 * `reverseFk` mode requires `sourceSchema` and `referenceField`; anything
 * else is accepted (embedded mode). Errors are non-fatal (surfaced as
 * warnings by the caller). Codes and messages carry the annotation's own name.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#1.1
 */
final class ReverseFkSourceLinkCheck {

	/**
	 * Constructor.
	 *
	 * @param string $annotation The annotation's short name, `merge` or `survivorship`.
	 */
	public function __construct(private readonly string $annotation) {
	}//end __construct()

	/**
	 * The errors for a `sourceLink` value.
	 *
	 * @param mixed $sourceLink The `sourceLink` value, or null when absent.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty when valid or absent).
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#1.1
	 */
	public function errors(mixed $sourceLink): array {
		if ($sourceLink === null) {
			return [];
		}

		if (is_array($sourceLink) === false) {
			return [
				[
					'code' => $this->annotation . '.source-link-not-object',
					'message' => 'x-openregister-' . $this->annotation . ' "sourceLink" must be an object.',
				],
			];
		}

		if ((string)($sourceLink['mode'] ?? 'embedded') !== 'reverseFk') {
			return [];
		}

		return $this->reverseFkErrors(sourceLink: $sourceLink);
	}//end errors()

	/**
	 * A reverseFk sourceLink must name both its source schema and its reference field.
	 *
	 * @param array<mixed> $sourceLink The `sourceLink` block.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty when valid).
	 */
	private function reverseFkErrors(array $sourceLink): array {
		if ((string)($sourceLink['sourceSchema'] ?? '') !== ''
			&& (string)($sourceLink['referenceField'] ?? '') !== ''
		) {
			return [];
		}

		return [
			[
				'code' => $this->annotation . '.source-link-reverse-fk-incomplete',
				'message' => 'x-openregister-' . $this->annotation
					. ' reverseFk "sourceLink" requires "sourceSchema" and "referenceField".',
			],
		];
	}//end reverseFkErrors()
}//end class
