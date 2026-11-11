<?php

/**
 * OpenRegister CodedOptionsRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;

/**
 * One read of a coded property's options: what is read, the name it is reported under, and in which language.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class CodedOptionsRequest {
	/**
	 * Hold the read.
	 *
	 * @param CodedPropertyDeclaration $declaration The coded declaration.
	 * @param string                   $property    The name the answer reports.
	 * @param string                   $language    The BCP-47 tag.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function __construct(
		public readonly CodedPropertyDeclaration $declaration,
		public readonly string $property,
		public readonly string $language,
	) {
	}//end __construct()

	/**
	 * The keys every options answer starts with.
	 *
	 * @return array<string, string> The property, scheme and language.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function envelope(): array {
		return ['property' => $this->property, 'scheme' => $this->declaration->scheme, 'language' => $this->language];
	}//end envelope()
}//end class
