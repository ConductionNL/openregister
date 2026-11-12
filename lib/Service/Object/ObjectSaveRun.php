<?php

/**
 * OpenRegister ObjectSaveRun
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
 * @spec exclude State of one ObjectService::saveObject() call; write behaviour owned by the save handler.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * What one ObjectService::saveObject() call carries from step to step.
 *
 * @spec exclude State of one ObjectService::saveObject() call; write behaviour owned by the save handler.
 */
final class ObjectSaveRun {

	/**
	 * Whether this save creates an object: the identifier was still null after the permission check.
	 *
	 * @var boolean
	 */
	public bool $isCreate = false;

	/**
	 * Constructor.
	 *
	 * @param array       $object  The object data being saved.
	 * @param string|null $uuid    The identifier the save writes to.
	 * @param array       $options The arguments of the saveObject() call.
	 *
	 * @phpstan-param array<array-key, mixed> $object
	 * @phpstan-param array<string, mixed>    $options
	 * @psalm-param   array<array-key, mixed> $object
	 * @psalm-param   array<string, mixed>    $options
	 *
	 * @spec exclude State of one ObjectService::saveObject() call; write behaviour owned by the save handler.
	 */
	public function __construct(
		public array $object,
		public ?string $uuid,
		public array $options,
	) {
	}//end __construct()
}//end class
