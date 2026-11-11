<?php

/**
 * MdmAnnotationChecks
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
 * The checks the merge and survivorship schema annotations share.
 *
 * Both must be objects when present, and both accept the same optional
 * `sourceLink` block: a `reverseFk` mode requires `sourceSchema` and
 * `referenceField`; anything else is accepted (embedded mode). Errors are
 * non-fatal (surfaced as warnings by the caller). Codes and messages carry
 * the annotation's own name.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#1.1
 */
final class MdmAnnotationChecks {

	/**
	 * Constructor.
	 *
	 * @param string $annotation The annotation's short name, `merge` or `survivorship`.
	 */
	public function __construct(private readonly string $annotation) {
	}//end __construct()

	/**
	 * The errors that end validation before the annotation's own checks.
	 *
	 * @param mixed $annotation The annotation value, or null when absent.
	 *
	 * @return array<int, array{code: string, message: string}>|null No errors when it is absent,
	 *                                                                 the not-an-object error, or
	 *                                                                 null when it is an object to check further.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#1.1
	 */
	public function shapeErrors(mixed $annotation): ?array {
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false) {
			return [
				[
					'code' => $this->annotation . '.not-object',
					'message' => 'x-openregister-' . $this->annotation . ' must be an object.',
				],
			];
		}

		return null;
	}//end shapeErrors()

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

		return $this->reverseFkErrors(sourceLink: $sourceLink);
	}//end errors()

	/**
	 * A reverseFk sourceLink must name both its source schema and its reference field.
	 *
	 * Any other mode (embedded is the default) has nothing to check here.
	 *
	 * @param array<mixed> $sourceLink The `sourceLink` block.
	 *
	 * @return array<int, array{code: string, message: string}> Errors (empty when valid).
	 */
	private function reverseFkErrors(array $sourceLink): array {
		if ((string)($sourceLink['mode'] ?? 'embedded') !== 'reverseFk'
			|| ((string)($sourceLink['sourceSchema'] ?? '') !== '' && (string)($sourceLink['referenceField'] ?? '') !== '')
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
