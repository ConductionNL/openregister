<?php

/**
 * OpenRegister file batch request
 *
 * The part of a batch file operation that is the same for every file in it:
 * the owning object, the action and the extra parameters.
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
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Immutable batch request shared by every file in one batch.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileBatchRequest {

	/**
	 * The object entity owning the files.
	 *
	 * @var ObjectEntity
	 */
	public readonly ObjectEntity $object;

	/**
	 * The batch action (publish|depublish|delete|label).
	 *
	 * @var string
	 */
	public readonly string $action;

	/**
	 * Additional parameters (e.g., labels for the label action).
	 *
	 * @var array
	 */
	public readonly array $params;

	/**
	 * Build a batch request.
	 *
	 * @param ObjectEntity $object The object entity owning the files.
	 * @param string       $action The batch action.
	 * @param array        $params Additional parameters.
	 */
	public function __construct(ObjectEntity $object, string $action, array $params) {
		$this->object = $object;
		$this->action = $action;
		$this->params = $params;
	}//end __construct()
}//end class
