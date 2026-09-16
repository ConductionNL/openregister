<?php

/**
 * A log line names the tenant, not the person, and never the secret.
 *
 * WHY THIS EXISTS. A shared back office logs for several legal entities at
 * once, and the log is read by people who work for one of them. A line that
 * carries a caseworker's name and e-mail address tells the other entity who is
 * doing what, which is a disclosure nobody asked for, and a line that carries a
 * bearer token is a credential sitting in a file that gets shipped to a log
 * aggregator. So: the organisation UUID and a pseudonymous actor reference, and
 * no secret, ever.
 *
 * 🔴 REDACTION FAILS CLOSED, AND THAT IS THE WHOLE DESIGN (D-5). Redaction that
 * fails open writes the secret. When this class cannot establish that a payload
 * is clean it returns null, the caller writes nothing, and a counter records
 * the drop. A missing log line is an incident somebody investigates. A logged
 * token is a breach somebody rotates every credential over.
 *
 * 🔑 THE PSEUDONYM IS STABLE AND ONE-WAY. The same user is the same reference
 * across lines, so a support engineer can still follow one person's actions
 * through a log, and the reference cannot be turned back into a user id without
 * the instance's own salt. That is the property that makes the log usable AND
 * publishable to whoever operates the shared instance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IAppConfig;
use Throwable;

/**
 * Redacts a log context, pseudonymises the actor, and drops what it cannot clear.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class TenantLogRedactor {

	/**
	 * What replaces a redacted value.
	 */
	public const REDACTED = '[redacted]';

	/**
	 * The app config key counting dropped lines.
	 */
	public const DROPPED_COUNTER = 'tenant_log_dropped_lines';

	/**
	 * The app config key holding the pseudonymisation salt.
	 */
	public const PSEUDONYM_SALT = 'tenant_log_pseudonym_salt';

	/**
	 * Context keys whose VALUE is a secret, matched case-insensitively as a substring.
	 *
	 * Substring rather than exact, because the names that carry secrets are not
	 * a closed set: `token`, `access_token`, `refreshToken` and
	 * `x-openregister-token` are all the same thing wearing four spellings, and
	 * a list of exact names goes stale the first time somebody adds a fifth.
	 *
	 * @var array<int, string>
	 */
	private const SECRET_KEY_FRAGMENTS = [
		'token',
		'password',
		'passwd',
		'secret',
		'credential',
		'authorization',
		'apikey',
		'api_key',
		'private_key',
		'privatekey',
		'client_secret',
		'clientsecret',
		'bearer',
		'session_key',
		'signature',
	];

	/**
	 * Value patterns that are a secret whatever the key is called.
	 *
	 * A token pasted into a `message` or a `url` is still a token.
	 *
	 * @var array<int, string>
	 */
	private const SECRET_VALUE_PATTERNS = [
		// An Authorization header value, however it was captured.
		'/\bBearer\s+[A-Za-z0-9\-._~+\/]{8,}=*/i',
		'/\bBasic\s+[A-Za-z0-9+\/]{8,}=*/i',
		// A JWT: three base64url segments separated by dots.
		'/\beyJ[A-Za-z0-9\-_]{4,}\.[A-Za-z0-9\-_]{4,}\.[A-Za-z0-9\-_]{4,}\b/',
		// A credential in a URL, e.g. https://user:secret@host.
		'/:\/\/[^\/\s:@]+:[^\/\s@]+@/',
	];

	/**
	 * How deep a context may nest before it is dropped rather than walked.
	 *
	 * Depth is a refusal, not a truncation: a payload this class stopped
	 * reading is a payload it cannot say is clean.
	 */
	private const MAX_DEPTH = 6;

	/**
	 * Build the redactor.
	 *
	 * The app config is optional so the redactor can be used from a mapper that
	 * does not have one. Without it the drop counter cannot be persisted and
	 * the pseudonym falls back to an instance-less salt, both of which are
	 * degradations of the record, never of the refusal: a line that cannot be
	 * cleared is still dropped.
	 *
	 * @param IAppConfig|null $appConfig The app config, when available.
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * Clean one log context, or refuse it.
	 *
	 * @param array<string, mixed> $context The context about to be logged.
	 *
	 * @return array<string, mixed>|null The cleaned context, or null when the line must be dropped.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function line(array $context): ?array {
		try {
			$clean = $this->walk(value: $context, depth: 0);
		} catch (Throwable $e) {
			// Anything at all going wrong inside the walk means this class
			// cannot say the payload is clean. That is a drop, not a rethrow:
			// a logging helper must never turn a log call into an exception.
			$this->countDrop();
			return null;
		}

		if (is_array($clean) === false) {
			$this->countDrop();
			return null;
		}

		return $clean;

	}//end line()

	/**
	 * Redact a context without the refusal, for a caller that has no line to drop.
	 *
	 * @param array<string, mixed> $context The context.
	 *
	 * @return array<string, mixed> The cleaned context, or an explicit refusal marker.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function redact(array $context): array {
		$clean = $this->line(context: $context);

		if ($clean === null) {
			return ['redactionFailed' => true];
		}

		return $clean;

	}//end redact()

	/**
	 * How many lines have been dropped on this instance.
	 *
	 * @return integer The count.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function droppedLines(): int {
		if ($this->appConfig === null) {
			return 0;
		}

		try {
			return $this->appConfig->getValueInt('openregister', self::DROPPED_COUNTER, 0);
		} catch (Throwable $e) {
			return 0;
		}

	}//end droppedLines()

	/**
	 * A stable, one-way reference to a user, for a log line.
	 *
	 * @param string|null $userId The Nextcloud user id, or null for an anonymous or system actor.
	 *
	 * @return string The pseudonymous reference.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	public function pseudonym(?string $userId): string {
		if ($userId === null || $userId === '') {
			return 'actor:anonymous';
		}

		return 'actor:' . substr(hash_hmac('sha256', $userId, $this->salt()), 0, 16);

	}//end pseudonym()

	/**
	 * Walk one value, redacting what is secret and refusing what cannot be read.
	 *
	 * @param mixed $value The value.
	 * @param integer $depth How deep the walk already is.
	 *
	 * @return mixed The cleaned value.
	 *
	 * @throws \RuntimeException When the value cannot be established as clean.
	 */
	private function walk(mixed $value, int $depth): mixed {
		if ($depth > self::MAX_DEPTH) {
			throw new \RuntimeException('log context nests deeper than the redactor reads');
		}

		if ($value === null || is_bool($value) === true || is_int($value) === true || is_float($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return $this->redactString(value: $value);
		}

		if (is_array($value) === true) {
			$clean = [];
			foreach ($value as $key => $item) {
				if ($this->isSecretKey(key: (string)$key) === true) {
					$clean[$key] = self::REDACTED;
					continue;
				}

				$clean[$key] = $this->walk(value: $item, depth: ($depth + 1));
			}

			return $clean;
		}//end if

		if (is_object($value) === true && ($value instanceof \Stringable) === true) {
			return $this->redactString(value: (string)$value);
		}

		// A resource, a closure, or an object with no safe string form. This
		// class cannot read it, so it cannot say it is clean, so the line goes.
		throw new \RuntimeException('log context holds a value the redactor cannot read');

	}//end walk()

	/**
	 * Redact the secrets inside one string.
	 *
	 * @param string $value The string.
	 *
	 * @return string The redacted string.
	 *
	 * @throws \RuntimeException When the string is not valid UTF-8.
	 */
	private function redactString(string $value): string {
		if ($value !== '' && mb_check_encoding($value, 'UTF-8') === false) {
			// Binary, or a broken encoding. A pattern cannot be matched against
			// it reliably, so it cannot be cleared.
			throw new \RuntimeException('log context holds a string the redactor cannot decode');
		}

		$redacted = $value;
		foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
			$result = preg_replace($pattern, self::REDACTED, $redacted);
			if ($result === null) {
				throw new \RuntimeException('redaction pattern failed against this value');
			}

			$redacted = $result;
		}

		return $redacted;

	}//end redactString()

	/**
	 * Whether a context key names something whose value is a secret.
	 *
	 * @param string $key The key.
	 *
	 * @return boolean True when the value must be redacted whatever it is.
	 */
	private function isSecretKey(string $key): bool {
		$normalised = strtolower($key);

		foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
			if (str_contains($normalised, $fragment) === true) {
				return true;
			}
		}

		return false;

	}//end isSecretKey()

	/**
	 * Record that a line was dropped.
	 *
	 * @return void
	 */
	private function countDrop(): void {
		if ($this->appConfig === null) {
			return;
		}

		try {
			$current = $this->appConfig->getValueInt('openregister', self::DROPPED_COUNTER, 0);
			$this->appConfig->setValueInt('openregister', self::DROPPED_COUNTER, ($current + 1));
		} catch (Throwable $e) {
			// The counter is the record of the refusal, not the refusal. Losing
			// it must not resurrect the line.
			return;
		}

	}//end countDrop()

	/**
	 * The instance salt the pseudonym is derived from.
	 *
	 * Generated once and kept, so the same user reads as the same reference
	 * across restarts. An instance where it cannot be stored still pseudonymises
	 * within the request, which is worse for correlation and no worse for
	 * disclosure.
	 *
	 * @return string The salt.
	 */
	private function salt(): string {
		if ($this->appConfig === null) {
			return 'openregister-tenant-log';
		}

		try {
			$salt = $this->appConfig->getValueString('openregister', self::PSEUDONYM_SALT, '');
			if ($salt !== '') {
				return $salt;
			}

			$salt = bin2hex(random_bytes(16));
			$this->appConfig->setValueString('openregister', self::PSEUDONYM_SALT, $salt);

			return $salt;
		} catch (Throwable $e) {
			return 'openregister-tenant-log';
		}

	}//end salt()
}//end class
