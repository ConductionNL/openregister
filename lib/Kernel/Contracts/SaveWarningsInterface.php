<?php

/**
 * SaveWarningsInterface: the warnings a request's saves raised, for its response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Collects the `warn` validations a request's saves raised, so the response
 * can carry them (task 4.3b). A save event only carries errors; a warning
 * saves, so it travels beside the event instead of on it.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
interface SaveWarningsInterface {

	/**
	 * Remember a warning one save raised.
	 *
	 * @param string|null          $object  The saved object's uuid, when it has one.
	 * @param array<string, mixed> $warning The warning: validation, message, properties.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function add(?string $object, array $warning): void;

	/**
	 * Hand over every warning collected so far and forget them.
	 *
	 * @return array<int, array<string, mixed>> The warnings, in the order they were raised, each naming its object.
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function drain(): array;
}//end interface
