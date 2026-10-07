<?php

/**
 * Stamps the token a write was made with onto the audit row it produced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Writes the token, its owner and its consumer onto a row, in both places.
 *
 * The same two-write shape as {@see PurposeAttribution}, for the same two
 * reasons:
 *
 * - `resultSummary['token']` is INSIDE the canonical JSON the hash chain
 *   seals, so the credential a row names cannot be edited afterwards without
 *   breaking verification. That matters more here than anywhere: the field
 *   exists to answer "which integration did this", which is a question asked
 *   when somebody is already suspected of something.
 * - the `consumer` COLUMN is outside it, and exists because "everything this
 *   koppeling wrote last month" is a filter over the largest table this app
 *   has, and a JSON field cannot serve that portably.
 *
 * ⚠️ NO PAYLOAD, EVER. The requirement's second half is a prohibition, and the
 * way a prohibition is kept is by nothing ever writing the thing. This class
 * writes seven scalars and none of them is a body. {@see carriesPayload()} is
 * the check that makes the absence provable rather than merely intended, and
 * the test that calls it is the one that would catch a future contributor
 * adding a request body here because it seemed useful.
 *
 * MUST be applied BEFORE the row is inserted: `resultSummary` is part of the
 * canonical form, so a token added after the insert would sit outside the hash
 * the row is later given.
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */
class TokenAttribution {
	/**
	 * Keys that would each be a stored request or response payload.
	 *
	 * Named rather than inferred: a heuristic over "large string values" would
	 * have flagged `changed`, which is the before and after the requirement
	 * says to keep instead of a payload.
	 *
	 * @var string[]
	 */
	public const PAYLOAD_KEYS = [
		'body',
		'requestBody',
		'request_body',
		'payload',
		'requestPayload',
		'responseBody',
		'response_body',
		'responsePayload',
		'rawRequest',
		'rawResponse',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the request-scoped token context.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Stamp the calling token onto a row being built.
	 *
	 * Fail-soft. An audit row is evidence and must survive a bookkeeping
	 * problem; a row naming no token is honest about what it does not know.
	 *
	 * @param AuditTrail $auditTrail The row being built.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public function apply(AuditTrail $auditTrail): void {
		try {
			$context = $this->container->get(TokenContext::class);
		} catch (Throwable $contextUnavailable) {
			return;
		}

		if (($context instanceof TokenContext) === false) {
			return;
		}

		try {
			$identity = $context->identity();
		} catch (Throwable $resolutionFailed) {
			return;
		}

		if ($identity === null || $identity->isAttributable() === false) {
			return;
		}

		$auditTrail->setConsumer($identity->consumerName());

		// Merge rather than replace: the purpose attribution and an MCP tool
		// invocation both already write here, and neither may be erased.
		$summary = ($auditTrail->getResultSummary() ?? []);
		$summary['token'] = $identity->toArray();
		$auditTrail->setResultSummary($summary);
	}//end apply()

	/**
	 * Whether a row carries a stored request or response payload.
	 *
	 * The prohibition made checkable. `resultSummary` is the only place on an
	 * audit row where free-form structure is written, so it is the only place a
	 * payload could arrive; `changed` is the before and after, which the
	 * requirement asks for by name.
	 *
	 * @param AuditTrail $auditTrail The row to check.
	 *
	 * @return bool True when a payload key is present anywhere in the summary.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public static function carriesPayload(AuditTrail $auditTrail): bool {
		return self::hasPayloadKey(value: ($auditTrail->getResultSummary() ?? []));
	}//end carriesPayload()

	/**
	 * Whether a payload key appears anywhere in a nested structure.
	 *
	 * Recursive because the summary is nested: a payload tucked one level down
	 * inside a tool invocation's own result is exactly as stored as one at the
	 * top, and a shallow check would call it absent.
	 *
	 * @param mixed $value The value to search.
	 *
	 * @return bool True when a payload key is present.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private static function hasPayloadKey(mixed $value): bool {
		if (is_array($value) === false) {
			return false;
		}

		foreach ($value as $key => $nested) {
			if (is_string($key) === true && in_array($key, self::PAYLOAD_KEYS, true) === true) {
				return true;
			}

			if (self::hasPayloadKey(value: $nested) === true) {
				return true;
			}
		}

		return false;
	}//end hasPayloadKey()

	/**
	 * Whether a row's consumer column disagrees with its sealed consumer.
	 *
	 * The column is an unsealed index over a sealed value, so it CAN be edited
	 * without breaking the chain. This is the check that makes such an edit
	 * visible, exactly as {@see PurposeAttribution::disagrees()} does for the
	 * purpose.
	 *
	 * @param AuditTrail $auditTrail The row to check.
	 *
	 * @return bool True when the column and the sealed copy name different consumers.
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	public static function disagrees(AuditTrail $auditTrail): bool {
		$summary = ($auditTrail->getResultSummary() ?? []);
		$sealed = null;
		if (isset($summary['token']['consumer']) === true && is_string($summary['token']['consumer']) === true) {
			$sealed = $summary['token']['consumer'];
		}

		return $sealed !== $auditTrail->getConsumer();
	}//end disagrees()
}//end class
