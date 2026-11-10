<?php

/**
 * OpenRegister AggregationPresentation
 *
 * How aggregation results are presented and reported: translated group keys, the negotiated language and diagnostics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use Psr\Log\LoggerInterface;

/**
 * How aggregation results are presented and reported: translated group keys, the negotiated language and diagnostics.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final readonly class AggregationPresentation {
	/**
	 * Bundle the collaborators.
	 *
	 * @param TranslationHandler   $translationHandler Resolves translatable group keys to the negotiated language.
	 * @param LanguageService      $languageService    Request-scoped language negotiation (Accept-Language / _lang).
	 * @param LoggerInterface|null $logger             Optional logger for diagnostics.
	 */
	public function __construct(
		public TranslationHandler $translationHandler,
		public LanguageService $languageService,
		public ?LoggerInterface $logger = null,
	) {
	}//end __construct()
}//end class
