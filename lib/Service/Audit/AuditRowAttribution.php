<?php

/**
 * The steps every attribution of an audit row shares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Resolves an attribution's request-scoped context fail-soft, and merges what it
 * attributes into a row's result summary. Built by {@see PurposeAttribution} and
 * {@see TokenAttribution}; it holds nothing.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class AuditRowAttribution {

	/**
	 * The request-scoped context of a class, or null when it cannot be resolved or is not that class.
	 *
	 * Fail-soft: an audit row is evidence and must survive a bookkeeping problem.
	 *
	 * @param ContainerInterface $container The container holding the request-scoped context.
	 * @param class-string<T>    $class     The context class.
	 *
	 * @return T|null The context, or null.
	 *
	 * @template T of object
	 */
	public function requestContext(ContainerInterface $container, string $class): ?object {
		try {
			$context = $container->get($class);
		} catch (Throwable $contextUnavailable) {
			return null;
		}

		if (($context instanceof $class) === false) {
			return null;
		}

		return $context;
	}//end requestContext()

	/**
	 * Merge entries into a row's result summary rather than replace it: every other key stays where it is.
	 *
	 * @param AuditTrail           $auditTrail The row being built.
	 * @param array<string, mixed> $entries    The summary keys to set.
	 *
	 * @return void
	 */
	public function mergeIntoSummary(AuditTrail $auditTrail, array $entries): void {
		$summary = ($auditTrail->getResultSummary() ?? []);
		foreach ($entries as $key => $value) {
			$summary[$key] = $value;
		}

		$auditTrail->setResultSummary($summary);
	}//end mergeIntoSummary()
}//end class
