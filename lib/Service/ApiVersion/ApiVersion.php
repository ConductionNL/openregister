<?php

/**
 * One declared version of this instance's own API.
 *
 * WHY THIS EXISTS. A gemeente runs five leveranciers against one API and
 * cannot move them on the same day. Until now a version was not a thing this
 * codebase had: 831 routes answered with no version on them at all, so a
 * breaking change was a coordinated outage and the only way a consumer learnt
 * about one was by breaking.
 *
 * A version here is a declaration with three possible statuses and no fourth:
 *
 *  - SUPPORTED  answers, and is what a caller gets when it asks for nothing.
 *  - DEPRECATED answers normally and says when it stops, in every response it
 *               sends, so a client learns the deadline from the calls it
 *               already makes rather than from a mailing list (design D-3).
 *  - WITHDRAWN  refuses with 410 and names its successor, because a 404 reads
 *               as a bug in the caller and sends an integrator hunting.
 *
 * FAIL-CLOSED ON THE DECLARATION, NOT ON THE TRAFFIC. A malformed declaration
 * is refused here, at construction, rather than accepted and half-applied: a
 * deprecated version with no end date is a deprecation nobody can plan around,
 * and a withdrawn version with no successor is an outage with a status code.
 * What the catalogue does with the refusal is its own decision, and it is
 * deliberately the opposite direction: see {@see ApiVersionCatalogue}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiVersion;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A declared API version: its identifier, its status, and the dates that
 * belong to that status.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Reason: named constructors and PHP's own date API. `ApiVersion::fromArray()`,
 *         `ApiVersion::toHttpDate()` and `ApiVersionRefusedException::withdrawn()`
 *         / `::unknown()` are static factories, which is the pattern phpmd's
 *         StaticAccess rule cannot distinguish from a hidden dependency, and
 *         `DateTimeImmutable::createFromFormat()` has no instance form. Injecting
 *         a factory object for either would add a seam with nothing behind it.
 *         The repo carries the same suppression shape on VocabularyController,
 *         CodedValueGuard and DestructionScopeService for the same reason.
 */
class ApiVersion implements JsonSerializable {

	/**
	 * Served, and the answer to a caller that asks for no version in particular.
	 *
	 * @var string
	 */
	public const STATUS_SUPPORTED = 'supported';

	/**
	 * Served, with an end date carried in every response it sends.
	 *
	 * @var string
	 */
	public const STATUS_DEPRECATED = 'deprecated';

	/**
	 * No longer served. Refused with 410 and the successor named.
	 *
	 * @var string
	 */
	public const STATUS_WITHDRAWN = 'withdrawn';

	/**
	 * The three statuses, in the order a version passes through them.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		self::STATUS_SUPPORTED,
		self::STATUS_DEPRECATED,
		self::STATUS_WITHDRAWN,
	];

	/**
	 * The version identifier a consumer names, such as `1` or `2`.
	 *
	 * Digits only. A version called `2.1.3` invites a caller to ask for `2.1`
	 * and expect an answer, and a contract version is not a release number:
	 * `flow-semantic-versions` numbers a flow graph, this numbers a promise.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * One of {@see STATUSES}.
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * The date the deprecation took effect, ISO 8601, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $deprecatedOn;

	/**
	 * The date the version stops answering, ISO 8601, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $sunset;

	/**
	 * The identifier of the version a caller should move to, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $successor;

	/**
	 * Path prefixes this version serves, each relative to the app's API root.
	 *
	 * This is what makes "each document describes only its own routes" a
	 * mechanism rather than a sentence. A version declaring `['/']` serves the
	 * whole surface, which is what the first version does, because the whole
	 * surface is what it has always answered.
	 *
	 * @var array<int, string>
	 */
	public readonly array $pathPrefixes;

	/**
	 * A sentence an administrator reads beside the version.
	 *
	 * @var string
	 */
	public readonly string $description;

	/**
	 * Constructor.
	 *
	 * @param string $id Version identifier, digits only.
	 * @param string $status One of STATUSES.
	 * @param string|null $deprecatedOn ISO 8601 date the deprecation took effect.
	 * @param string|null $sunset ISO 8601 date the version stops answering.
	 * @param string|null $successor Identifier of the version to move to.
	 * @param array<int, string> $pathPrefixes Path prefixes this version serves.
	 * @param string $description A sentence an administrator reads.
	 *
	 * @throws InvalidArgumentException When the declaration cannot be honoured.
	 *
	 * @return void
	 */
	public function __construct(
		string $id,
		string $status,
		?string $deprecatedOn = null,
		?string $sunset = null,
		?string $successor = null,
		array $pathPrefixes = ['/'],
		string $description = '',
	) {
		$this->id = self::requireIdentifier(value: $id, field: 'id');
		$this->status = self::requireStatus(value: $status);
		$this->deprecatedOn = self::normaliseDate(value: $deprecatedOn, field: 'deprecatedOn');
		$this->sunset = self::normaliseDate(value: $sunset, field: 'sunset');

		// Assigned through a helper rather than inline. Each of the three obvious
		// spellings is refused by a different analyser: phpmd refuses the else,
		// phpstan refuses two guarded ifs because a readonly property needs one
		// assignment it can prove always happens, and phpcs refuses the ternary.
		// A helper that returns the value satisfies all three and reads better
		// than any of them.
		$this->successor = self::optionalIdentifier(value: $successor, field: 'successor');

		$this->pathPrefixes = self::normalisePrefixes(values: $pathPrefixes);
		$this->description = trim($description);

		$this->requireStatusIsComplete();

	}//end __construct()

	/**
	 * Build a version from an administered declaration.
	 *
	 * @param array<string, mixed> $declaration The decoded declaration.
	 *
	 * @throws InvalidArgumentException When the declaration cannot be honoured.
	 *
	 * @return self The version.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public static function fromArray(array $declaration): self {
		$prefixes = ($declaration['pathPrefixes'] ?? ['/']);
		if (is_array($prefixes) === false) {
			throw new InvalidArgumentException('pathPrefixes must be a list of path prefixes.');
		}

		return new self(
			id: self::stringOrEmpty(value: ($declaration['id'] ?? null)),
			status: self::stringOrEmpty(value: ($declaration['status'] ?? null)),
			deprecatedOn: self::nullableString(value: ($declaration['deprecatedOn'] ?? null)),
			sunset: self::nullableString(value: ($declaration['sunset'] ?? null)),
			successor: self::nullableString(value: ($declaration['successor'] ?? null)),
			pathPrefixes: array_values($prefixes),
			description: self::stringOrEmpty(value: ($declaration['description'] ?? null)),
		);

	}//end fromArray()

	/**
	 * Whether this version answers at all.
	 *
	 * @return bool True for supported and deprecated, false for withdrawn.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function isServed(): bool {
		return ($this->status !== self::STATUS_WITHDRAWN);

	}//end isServed()

	/**
	 * Whether this version answers and carries an end date while doing so.
	 *
	 * @return bool True when the status is deprecated.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function isDeprecated(): bool {
		return ($this->status === self::STATUS_DEPRECATED);

	}//end isDeprecated()

	/**
	 * Whether a path belongs to this version's declared surface.
	 *
	 * @param string $path A path relative to the app's API root, leading slash included.
	 *
	 * @return bool True when one of the declared prefixes matches.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function servesPath(string $path): bool {
		$candidate = ('/' . ltrim($path, '/'));
		foreach ($this->pathPrefixes as $prefix) {
			if ($prefix === '/') {
				return true;
			}

			if (str_starts_with($candidate, $prefix) === true) {
				return true;
			}
		}

		return false;

	}//end servesPath()

	/**
	 * An HTTP-date rendering of a stored ISO date, for RFC 8594 headers.
	 *
	 * @param string|null $isoDate The stored ISO 8601 date, or null.
	 *
	 * @return string|null The HTTP-date, or null when there is no date.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public static function toHttpDate(?string $isoDate): ?string {
		if ($isoDate === null) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, new DateTimeZone('UTC'));
		if ($parsed === false) {
			return null;
		}

		return $parsed->format(DateTimeInterface::RFC7231);

	}//end toHttpDate()

	/**
	 * The published shape of this version.
	 *
	 * Keys carrying null are dropped rather than published empty: a consumer
	 * reading `sunset: null` has to know that null means "not deprecated",
	 * and an absent key says the same thing without the lesson.
	 *
	 * @return array<string, mixed> The published declaration.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function jsonSerialize(): array {
		$published = [
			'version' => $this->id,
			'status' => $this->status,
		];

		if ($this->description !== '') {
			$published['description'] = $this->description;
		}

		if ($this->deprecatedOn !== null) {
			$published['deprecatedOn'] = $this->deprecatedOn;
		}

		if ($this->sunset !== null) {
			$published['sunset'] = $this->sunset;
		}

		if ($this->successor !== null) {
			$published['successor'] = $this->successor;
		}

		return $published;

	}//end jsonSerialize()

	/**
	 * Refuse a declaration whose status is missing the part that makes it usable.
	 *
	 * @throws InvalidArgumentException When a required date or successor is absent.
	 *
	 * @return void
	 */
	private function requireStatusIsComplete(): void {
		if ($this->status === self::STATUS_DEPRECATED && $this->sunset === null) {
			throw new InvalidArgumentException(
				'Version ' . $this->id . ' is deprecated without a sunset date; a deprecation nobody can plan around is a removal.'
			);
		}

		if ($this->status === self::STATUS_WITHDRAWN && $this->successor === null) {
			throw new InvalidArgumentException(
				'Version ' . $this->id . ' is withdrawn without a successor; a refusal that names no way forward is an outage.'
			);
		}

		if ($this->status === self::STATUS_WITHDRAWN && $this->successor === $this->id) {
			throw new InvalidArgumentException('Version ' . $this->id . ' cannot succeed itself.');
		}

	}//end requireStatusIsComplete()

	/**
	 * Validate a version identifier.
	 *
	 * @param string $value The candidate identifier.
	 * @param string $field The field name, for the message.
	 *
	 * @throws InvalidArgumentException When the identifier is not digits.
	 *
	 * @return string The identifier.
	 */
	private static function requireIdentifier(string $value, string $field): string {
		$trimmed = trim($value);
		if (preg_match('/^[0-9]{1,3}$/', $trimmed) !== 1) {
			throw new InvalidArgumentException(
				'API version ' . $field . ' must be one to three digits, got "' . $value . '".'
			);
		}

		return $trimmed;

	}//end requireIdentifier()

	/**
	 * Validate an identifier that may be absent.
	 *
	 * @param string|null $value The candidate identifier, or null.
	 * @param string $field The field name, for the message.
	 *
	 * @throws InvalidArgumentException When a present identifier is not digits.
	 *
	 * @return string|null The identifier, or null.
	 */
	private static function optionalIdentifier(?string $value, string $field): ?string {
		if ($value === null) {
			return null;
		}

		return self::requireIdentifier(value: $value, field: $field);

	}//end optionalIdentifier()

	/**
	 * Validate a status.
	 *
	 * @param string $value The candidate status.
	 *
	 * @throws InvalidArgumentException When the status is not one of STATUSES.
	 *
	 * @return string The status.
	 */
	private static function requireStatus(string $value): string {
		$trimmed = strtolower(trim($value));
		if (in_array($trimmed, self::STATUSES, true) === false) {
			throw new InvalidArgumentException(
				'API version status must be one of ' . implode(', ', self::STATUSES) . ', got "' . $value . '".'
			);
		}

		return $trimmed;

	}//end requireStatus()

	/**
	 * Validate and normalise a date.
	 *
	 * @param string|null $value The candidate date.
	 * @param string $field The field name, for the message.
	 *
	 * @throws InvalidArgumentException When the value is not an ISO 8601 date.
	 *
	 * @return string|null The `Y-m-d` date, or null.
	 */
	private static function normaliseDate(?string $value, string $field): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}

		$trimmed = trim($value);
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($trimmed, 0, 10), new DateTimeZone('UTC'));
		if ($parsed === false || $parsed->format('Y-m-d') !== substr($trimmed, 0, 10)) {
			throw new InvalidArgumentException(
				'API version ' . $field . ' must be an ISO 8601 date, got "' . $value . '".'
			);
		}

		return $parsed->format('Y-m-d');

	}//end normaliseDate()

	/**
	 * Normalise declared path prefixes.
	 *
	 * @param array<int, mixed> $values The declared prefixes.
	 *
	 * @throws InvalidArgumentException When no usable prefix remains.
	 *
	 * @return array<int, string> The normalised prefixes.
	 */
	private static function normalisePrefixes(array $values): array {
		$normalised = [];
		foreach ($values as $value) {
			if (is_string($value) === false) {
				continue;
			}

			$trimmed = trim($value);
			if ($trimmed === '') {
				continue;
			}

			$normalised[] = ('/' . trim($trimmed, '/'));
		}

		if ($normalised === []) {
			throw new InvalidArgumentException('An API version must declare at least one path prefix.');
		}

		return array_values(array_unique($normalised));

	}//end normalisePrefixes()

	/**
	 * Coerce a declaration value to a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The string, or the empty string.
	 */
	private static function stringOrEmpty(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		if (is_int($value) === true) {
			return (string)$value;
		}

		return '';

	}//end stringOrEmpty()

	/**
	 * Coerce a declaration value to a string or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string, or null.
	 */
	private static function nullableString(mixed $value): ?string {
		$coerced = self::stringOrEmpty(value: $value);
		if ($coerced === '') {
			return null;
		}

		return $coerced;

	}//end nullableString()
}//end class
