<?php

/**
 * OpenRegister AutomationRecordFilter
 *
 * What a read of automation run records asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use DateTimeZone;
use Throwable;

/**
 * The filters of an automation record read: kind, key prefix, schema, object
 * and a time range, plus a page. Empty strings mean "not filtered".
 *
 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
 */
final class AutomationRecordFilter {

	public const DEFAULT_LIMIT = 50;

	public const MAX_LIMIT = 500;

	/**
	 * The stored kind each outcome-route kind names.
	 *
	 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
	 */
	public const OUTCOME_KINDS = [
		'notification' => AutomationRecord::KIND_NOTIFICATION,
		'lifecycle'    => AutomationRecord::KIND_LIFECYCLE_ACTION,
	];

	/**
	 * Constructor.
	 *
	 * @param string        $kind      The kind, or '' for both.
	 * @param string        $keyPrefix The key prefix, or '' for any key.
	 * @param string        $schema    The schema id, or ''.
	 * @param string        $object    The object uuid, or ''.
	 * @param DateTime|null $since     The earliest moment (inclusive).
	 * @param DateTime|null $until     The latest moment (inclusive).
	 * @param int           $limit     The page size.
	 * @param int           $offset    The page start.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function __construct(
		public readonly string $kind = '',
		public readonly string $keyPrefix = '',
		public readonly string $schema = '',
		public readonly string $object = '',
		public readonly ?DateTime $since = null,
		public readonly ?DateTime $until = null,
		public readonly int $limit = self::DEFAULT_LIMIT,
		public readonly int $offset = 0,
	) {
	}//end __construct()

	/**
	 * A filter from request parameters; unknown kinds, bad dates and limits are normalised.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 *
	 * @return self The filter.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public static function fromParams(array $params): self {
		$kind = self::text(value: ($params['kind'] ?? ''));
		if (in_array($kind, AutomationRecord::KINDS, true) === false) {
			$kind = '';
		}

		$limit = (int)($params['limit'] ?? self::DEFAULT_LIMIT);
		if ($limit <= 0) {
			$limit = self::DEFAULT_LIMIT;
		}

		return new self(
			kind: $kind,
			keyPrefix: self::text(value: ($params['key'] ?? '')),
			schema: self::text(value: ($params['schema'] ?? '')),
			object: self::text(value: ($params['object'] ?? '')),
			since: self::moment(value: ($params['since'] ?? null)),
			until: self::moment(value: ($params['until'] ?? null)),
			limit: min($limit, self::MAX_LIMIT),
			offset: max(0, (int)($params['offset'] ?? 0))
		);
	}//end fromParams()

	/**
	 * A filter from the outcome route's parameters: `keyPrefix` names the key
	 * prefix (`key` is still read when it is absent), and `kind` takes the
	 * outcome kinds `notification` and `lifecycle`.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 *
	 * @return self The filter.
	 *
	 * @spec openspec/changes/a-consuming-app-reads-what-a-notification-and-a-lifecycle-action-did/specs/notificatie-engine/spec.md
	 */
	public static function fromOutcomeParams(array $params): self {
		if (array_key_exists('keyPrefix', $params) === true) {
			$params['key'] = $params['keyPrefix'];
		}

		if (is_string($params['kind'] ?? null) === true) {
			$kind           = trim($params['kind']);
			$params['kind'] = (self::OUTCOME_KINDS[$kind] ?? $kind);
		}

		return self::fromParams(params: $params);
	}//end fromOutcomeParams()

	/**
	 * The same filter at another page.
	 *
	 * @param int $limit  The page size.
	 * @param int $offset The page start.
	 *
	 * @return self The filter.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function page(int $limit, int $offset): self {
		return new self(
			kind: $this->kind,
			keyPrefix: $this->keyPrefix,
			schema: $this->schema,
			object: $this->object,
			since: $this->since,
			until: $this->until,
			limit: $limit,
			offset: $offset
		);
	}//end page()

	/**
	 * A scalar parameter as trimmed text.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return string The text, '' for a non-scalar.
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * A parameter as a UTC moment, or null when absent or unreadable.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return DateTime|null The moment.
	 */
	private static function moment(mixed $value): ?DateTime {
		$text = self::text(value: $value);
		if ($text === '') {
			return null;
		}

		try {
			$moment = new DateTime($text, new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			unset($e);
			return null;
		}

		return $moment->setTimezone(new DateTimeZone('UTC'));
	}//end moment()
}//end class
