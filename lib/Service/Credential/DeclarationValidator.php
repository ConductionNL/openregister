<?php

/**
 * DeclarationValidator — validates one app-declared credential provider entry.
 *
 * An app may ship `lib/Settings/credential-providers.json` in its own app
 * directory. Each entry in it is a REQUEST for a bounded egress capability, and
 * this class decides whether the entry is well-formed and, for an `extends`
 * declaration, whether it is provably narrower than the reviewed base entry it
 * names. It never admits anything by itself: admission is the resolver's job.
 *
 * The narrowing test is deliberately conservative. A declared rule is narrower
 * than a base rule only when the method is identical and the base pattern can
 * be shown to match every path the declared pattern can produce, treating each
 * `*` in the declared pattern as "could be anything". Anything that cannot be
 * shown is rejected, never escalated to an approval request.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Validates app-declared provider entries and computes their digest.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-declaration-that-widens-beyond-its-base-is-rejected-never-escalated
 */
class DeclarationValidator {

	/**
	 * The admission lane of a declaration that extends a reviewed entry and grants nothing beyond it.
	 *
	 * @var string
	 */
	public const LANE_NARROWING = 'narrowing';

	/**
	 * The admission lane of a declaration that needs an administrator's approval.
	 *
	 * @var string
	 */
	public const LANE_APPROVAL = 'approval';

	/**
	 * The fields a declaration entry may carry. Anything else is refused.
	 *
	 * @var array<int, string>
	 */
	private const ENTRY_FIELDS = ['identifier', 'title', 'baseUrl', 'authScheme', 'allowRules', 'extends', '$why'];

	/**
	 * The fields one allow-rule may carry.
	 *
	 * @var array<int, string>
	 */
	private const RULE_FIELDS = ['method', 'pathPattern', '$why'];

	/**
	 * The fields an auth scheme may carry: one header carrying one secret.
	 *
	 * @var array<int, string>
	 */
	private const AUTH_FIELDS = ['header', 'template'];

	/**
	 * A local provider id: lower-case, starting with a letter or digit.
	 *
	 * @var string
	 */
	private const LOCAL_ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,62}$/';

	/**
	 * Validate one declared entry of an app.
	 *
	 * @param string               $appId     The declaring app.
	 * @param string               $key       The entry's key in the declaration file's `providers` map.
	 * @param mixed                $entry     The declared entry.
	 * @param array<string, mixed> $catalogue The reviewed catalogue (`identifier => entry`).
	 *
	 * @return array{valid: bool, reason: string, lane: string} The outcome; `reason` is secret-free.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-declaration-must-be-a-host-locked-proxy-entry
	 */
	public function validate(string $appId, string $key, mixed $entry, array $catalogue): array {
		if (is_array($entry) === false) {
			return $this->invalid(reason: 'the entry is not an object');
		}

		$shapeError = $this->shapeError(appId: $appId, key: $key, entry: $entry);
		if ($shapeError !== null) {
			return $this->invalid(reason: $shapeError);
		}

		if (array_key_exists('extends', $entry) === false) {
			return ['valid' => true, 'reason' => '', 'lane' => self::LANE_APPROVAL];
		}

		$narrowingError = $this->narrowingError(entry: $entry, catalogue: $catalogue);
		if ($narrowingError !== null) {
			return $this->invalid(reason: $narrowingError);
		}

		return ['valid' => true, 'reason' => '', 'lane' => self::LANE_NARROWING];
	}//end validate()

	/**
	 * A digest of one entry over a canonical serialisation (sorted keys, no whitespace).
	 *
	 * Computed per entry, never over the whole file, so adding an unrelated entry to
	 * a declaration file leaves every other entry's approval standing.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return string `sha256:` followed by the hex digest.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-is-pinned-to-a-digest-of-the-approved-declaration
	 */
	public function digest(array $entry): string {
		$canonical = json_encode(
			$this->canonical(value: $entry),
			(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
		);

		return 'sha256:' . hash('sha256', (string)$canonical);
	}//end digest()

	/**
	 * Whether a base `fnmatch()` pattern matches every path a declared pattern can produce.
	 *
	 * Each `*` in the declared pattern may stand for anything, so only a `*` in the base
	 * pattern can absorb it. A declared pattern holding any other glob syntax (`?`, `[`,
	 * `\`), a `..` or an empty segment, or not rooted at `/`, is never provably narrower.
	 * A base pattern holding such syntax only covers an identical declared pattern.
	 *
	 * @param string $basePattern     The reviewed rule's pattern.
	 * @param string $declaredPattern The declared rule's pattern.
	 *
	 * @return bool True only when the inclusion is shown.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-declaration-that-widens-beyond-its-base-is-rejected-never-escalated
	 */
	public function covers(string $basePattern, string $declaredPattern): bool {
		if ($this->isProvablePattern(pattern: $declaredPattern) === false || $basePattern === '') {
			return false;
		}

		if ($basePattern === $declaredPattern) {
			return true;
		}

		if (strpbrk($basePattern, '?[\\') !== false) {
			return false;
		}

		return $this->absorbs(base: $basePattern, declared: $declaredPattern);
	}//end covers()

	/**
	 * Whether the base pattern's `*`s can absorb the declared pattern, literal for literal.
	 *
	 * @param string $base     The base pattern (literals and `*` only).
	 * @param string $declared The declared pattern (literals and `*` only).
	 *
	 * @return bool True when every declared token is consumed.
	 */
	private function absorbs(string $base, string $declared): bool {
		$baseLength = strlen($base);
		$declaredLength = strlen($declared);

		// $reach[$j] — the base prefix of length $i can consume the declared prefix of length $j.
		$reach = array_fill(0, ($declaredLength + 1), false);
		$reach[0] = true;
		for ($i = 1; $i <= $baseLength; $i++) {
			$next = array_fill(0, ($declaredLength + 1), false);
			$baseChar = $base[($i - 1)];
			for ($j = 0; $j <= $declaredLength; $j++) {
				if ($baseChar === '*') {
					// A base `*` consumes nothing, or extends over the next declared token.
					$next[$j] = ($reach[$j] === true || ($j > 0 && $next[($j - 1)] === true));
					continue;
				}

				// A base literal only matches the same declared literal, never a declared `*`.
				$next[$j] = ($j > 0 && $reach[($j - 1)] === true && $declared[($j - 1)] === $baseChar);
			}

			$reach = $next;
		}

		return $reach[$declaredLength];
	}//end absorbs()

	/**
	 * Whether a declared pattern is simple enough to reason about.
	 *
	 * @param string $pattern The declared pattern.
	 *
	 * @return bool True for a `/`-rooted pattern of literals and `*` with no traversal.
	 */
	private function isProvablePattern(string $pattern): bool {
		if ($pattern === '' || $pattern[0] !== '/' || strpbrk($pattern, '?[\\') !== false) {
			return false;
		}

		$segments = explode('/', substr($pattern, 1));
		foreach ($segments as $index => $segment) {
			if ($segment === '..' || $segment === '.' || ($segment === '' && $index < (count($segments) - 1))) {
				return false;
			}
		}

		return str_contains($pattern, '..') === false;
	}//end isProvablePattern()

	/**
	 * The first shape problem of an entry, or null when the shape is sound.
	 *
	 * @param string               $appId The declaring app.
	 * @param string               $key   The entry's key in the file.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string|null The problem.
	 */
	private function shapeError(string $appId, string $key, array $entry): ?string {
		if (($entry['inject_only'] ?? false) === true) {
			return 'a declared provider cannot be inject_only';
		}

		$unknown = array_diff(array_keys($entry), self::ENTRY_FIELDS);
		if ($unknown !== []) {
			return 'unknown field "' . (string)reset($unknown) . '"';
		}

		$identifierError = $this->identifierError(appId: $appId, key: $key, entry: $entry);
		if ($identifierError !== null) {
			return $identifierError;
		}

		if (is_string($entry['title'] ?? null) === false || trim($entry['title']) === '') {
			return 'the entry has no title';
		}

		if ($this->isHttpsBaseUrl(value: ($entry['baseUrl'] ?? null)) === false) {
			return 'the entry has no https baseUrl';
		}

		$authError = $this->authSchemeError(scheme: ($entry['authScheme'] ?? null));
		if ($authError !== null) {
			return $authError;
		}

		return $this->rulesError(rules: ($entry['allowRules'] ?? null));
	}//end shapeError()

	/**
	 * Whether the identifier is `<appId>:<localId>` for the declaring app, matching its key.
	 *
	 * @param string               $appId The declaring app.
	 * @param string               $key   The entry's key in the file.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string|null The problem.
	 */
	private function identifierError(string $appId, string $key, array $entry): ?string {
		$identifier = ($entry['identifier'] ?? null);
		if (is_string($identifier) === false || $identifier !== $key) {
			return 'the identifier must equal its key';
		}

		$prefix = $appId . ':';
		if (str_starts_with($identifier, $prefix) === false) {
			return 'the identifier must be namespaced as "' . $prefix . '<localId>"';
		}

		if (preg_match(self::LOCAL_ID_PATTERN, substr($identifier, strlen($prefix))) !== 1) {
			return 'the local id must be lower-case letters, digits, dots, dashes or underscores';
		}

		return null;
	}//end identifierError()

	/**
	 * Whether a value is an absolute https URL with a host and no credentials, query or fragment.
	 *
	 * @param mixed $value The baseUrl.
	 *
	 * @return bool True when usable as a host-lock.
	 */
	private function isHttpsBaseUrl(mixed $value): bool {
		if (is_string($value) === false) {
			return false;
		}

		$parts = parse_url($value);
		if (is_array($parts) === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
			return false;
		}

		return array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) === [];
	}//end isHttpsBaseUrl()

	/**
	 * The problem with an auth scheme, or null: one header, a template carrying `{secret}`.
	 *
	 * @param mixed $scheme The auth scheme.
	 *
	 * @return string|null The problem.
	 */
	private function authSchemeError(mixed $scheme): ?string {
		if (is_array($scheme) === false || array_diff(array_keys($scheme), self::AUTH_FIELDS) !== []) {
			return 'the authScheme must carry only a header and a template';
		}

		$header = ($scheme['header'] ?? null);
		$template = ($scheme['template'] ?? null);
		if (is_string($header) === false || preg_match('/^[A-Za-z0-9-]+$/', $header) !== 1
			|| is_string($template) === false || str_contains($template, '{secret}') === false
		) {
			return 'the authScheme must name a header and a template carrying {secret}';
		}

		return null;
	}//end authSchemeError()

	/**
	 * The problem with an allow-rule list, or null when every rule is sound.
	 *
	 * @param mixed $rules The allow-rules.
	 *
	 * @return string|null The problem.
	 */
	private function rulesError(mixed $rules): ?string {
		if (is_array($rules) === false || $rules === [] || array_is_list($rules) === false) {
			return 'the entry has no allowRules';
		}

		foreach ($rules as $rule) {
			if (is_array($rule) === false || array_diff(array_keys($rule), self::RULE_FIELDS) !== []) {
				return 'an allow-rule may carry only a method and a pathPattern';
			}

			$method = ($rule['method'] ?? null);
			$pattern = ($rule['pathPattern'] ?? null);
			if (is_string($method) === false || preg_match('/^[A-Z]+$/', $method) !== 1) {
				return 'an allow-rule must name an upper-case method';
			}

			if (is_string($pattern) === false || $pattern === '' || $pattern[0] !== '/') {
				return 'an allow-rule must carry a /-rooted pathPattern';
			}
		}

		return null;
	}//end rulesError()

	/**
	 * The problem with an `extends` declaration, or null when it is provably narrower.
	 *
	 * @param array<string, mixed> $entry     The entry.
	 * @param array<string, mixed> $catalogue The reviewed catalogue.
	 *
	 * @return string|null The problem.
	 */
	private function narrowingError(array $entry, array $catalogue): ?string {
		$baseId = ($entry['extends'] ?? null);
		$base = null;
		if (is_string($baseId) === true) {
			$base = ($catalogue[$baseId] ?? null);
		}

		if (is_array($base) === false) {
			return 'extends names no reviewed provider';
		}

		if (($base['inject_only'] ?? false) === true || isset($base['kind']) === true) {
			return 'extends names a provider that cannot be narrowed';
		}

		if (($entry['baseUrl'] ?? null) !== ($base['baseUrl'] ?? null)
			|| ($entry['authScheme'] ?? null) !== ($base['authScheme'] ?? null)
		) {
			return 'extends must keep the base baseUrl and authScheme';
		}

		foreach ($entry['allowRules'] as $rule) {
			if ($this->ruleIsCovered(rule: $rule, baseRules: ($base['allowRules'] ?? [])) === false) {
				return 'the rule ' . $rule['method'] . ' ' . $rule['pathPattern'] . ' is not narrower than the base';
			}
		}

		return null;
	}//end narrowingError()

	/**
	 * Whether one declared rule is covered by a base rule with the same method.
	 *
	 * @param array<string, mixed> $rule      The declared rule.
	 * @param mixed                $baseRules The base entry's allow-rules.
	 *
	 * @return bool True when a base rule covers it.
	 */
	private function ruleIsCovered(array $rule, mixed $baseRules): bool {
		if (is_array($baseRules) === false) {
			return false;
		}

		foreach ($baseRules as $baseRule) {
			if (is_array($baseRule) === true
				&& strtoupper((string)($baseRule['method'] ?? '')) === $rule['method']
				&& $this->covers(basePattern: (string)($baseRule['pathPattern'] ?? ''), declaredPattern: $rule['pathPattern']) === true
			) {
				return true;
			}
		}

		return false;
	}//end ruleIsCovered()

	/**
	 * A value with every map's keys sorted, recursively; lists keep their order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The canonical value.
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			$out[$key] = $this->canonical(value: $item);
		}

		if (array_is_list($out) === false) {
			ksort($out, SORT_STRING);
		}

		return $out;
	}//end canonical()

	/**
	 * An invalid outcome.
	 *
	 * @param string $reason The secret-free reason.
	 *
	 * @return array{valid: bool, reason: string, lane: string}
	 */
	private function invalid(string $reason): array {
		return ['valid' => false, 'reason' => $reason, 'lane' => ''];
	}//end invalid()
}//end class
