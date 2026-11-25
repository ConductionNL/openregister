<?php

/**
 * SaveWarnings: the warnings a request's saves raised, for its response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\Kernel\Contracts\SaveWarningsInterface;

/**
 * The request's shared collector of save warnings.
 *
 * Bounded: a background job or an import saves many objects in one process
 * and nothing drains the collector there, so it keeps the first
 * {@see self::LIMIT} warnings and drops the rest instead of growing without end.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
final class SaveWarnings implements SaveWarningsInterface {

	/**
	 * The most warnings kept until the next drain.
	 */
	public const LIMIT = 100;

	/**
	 * The warnings collected since the last drain.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Remember a warning one save raised, while under the limit.
	 *
	 * @param string|null          $object  The saved object's uuid, when it has one.
	 * @param array<string, mixed> $warning The warning: validation, message, properties.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function add(?string $object, array $warning): void {
		if (count($this->warnings) >= self::LIMIT) {
			return;
		}

		$this->warnings[] = array_merge($warning, ['object' => $object]);
	}//end add()

	/**
	 * Hand over every warning collected so far and forget them.
	 *
	 * @return array<int, array<string, mixed>> The warnings, in the order they were raised, each naming its object.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function drain(): array {
		$warnings       = $this->warnings;
		$this->warnings = [];

		return $warnings;
	}//end drain()
}//end class
