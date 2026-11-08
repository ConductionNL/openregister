<?php

/**
 * OpenRegister vocabulary language
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

use OCP\IRequest;

/**
 * The language a vocabulary read is asked in, defaulting to Dutch.
 *
 * Dutch is the default rather than English because `prefLabel.nl` is the
 * one label the concept schema requires, so it is the only tag guaranteed
 * to resolve to something a person can read.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class VocabularyLanguage {
	/**
	 * The tag used when the caller names none.
	 *
	 * @var string
	 */
	private const DEFAULT_TAG = 'nl';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The BCP-47 tag: the `language` parameter, else the first Accept-Language entry, else Dutch.
	 *
	 * @return string The BCP-47 tag.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function tag(): string {
		$explicit = trim((string)$this->request->getParam('language', ''));
		if ($explicit !== '') {
			return $explicit;
		}

		return $this->firstAccepted(header: (string)$this->request->getHeader('Accept-Language'));
	}//end tag()

	/**
	 * The first language range of an Accept-Language header, without its quality.
	 *
	 * @param string $header The header value.
	 *
	 * @return string The tag, or the default when the header names none.
	 */
	private function firstAccepted(string $header): string {
		$range = trim(explode(',', trim($header))[0]);
		$tag = trim(explode(';', $range)[0]);
		if ($tag === '') {
			return self::DEFAULT_TAG;
		}

		return $tag;
	}//end firstAccepted()
}//end class
