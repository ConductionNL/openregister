<?php

/**
 * ConsentEnvelopeEvaluators: hands out the consent envelope evaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consent;

/**
 * Builds the consent envelope evaluator for callers outside the Service component.
 *
 * The evaluator is pure and has no Nextcloud dependency, so a caller owns the
 * one instance it needs rather than resolving it from the container. This class
 * builds that instance and hands it back typed as ConsentEnvelopeEvaluatorInterface,
 * so the consent on-save listener need not name the evaluator class.
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */
final class ConsentEnvelopeEvaluators {
	/**
	 * A new consent envelope evaluator.
	 *
	 * @return ConsentEnvelopeEvaluatorInterface The evaluator.
	 *
	 * @spec openspec/specs/consent-evidence-envelope/spec.md
	 */
	public static function evaluator(): ConsentEnvelopeEvaluatorInterface {
		return new ConsentEnvelopeEvaluator();
	}//end evaluator()
}//end class
