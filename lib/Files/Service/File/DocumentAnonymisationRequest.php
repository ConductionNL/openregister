<?php

/**
 * OpenRegister DocumentAnonymisationRequest.
 *
 * One request to anonymise a document: the source node, the detected entities and the optional settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\Node;

/**
 * One request to anonymise a document.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DocumentAnonymisationRequest {
	/**
	 * Constructor.
	 *
	 * @param Node                                                                 $node     The file node to anonymise.
	 * @param array<int, array{text?: string, entityType?: string, key?: string}> $entities Detected entities.
	 * @param DocumentAnonymisationOptions                                         $options  Optional settings.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly Node $node,
		public readonly array $entities,
		public readonly DocumentAnonymisationOptions $options = new DocumentAnonymisationOptions(),
	) {
	}//end __construct()
}//end class
