<?php

/**
 * Where in a scheduled-trigger `filter` the parser is, for its errors.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * The rule, the dotted path, the combinator depth and the entry key of one
 * place in a filter, and the structured errors reported there.
 *
 * The key is the field an entry tests, or the combinator it names.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ScheduledFilterPosition {

	/**
	 * Constructor.
	 *
	 * @param string $ruleKey Notification rule name.
	 * @param string $path    Dotted path for error reporting.
	 * @param int    $depth   Combinator nesting depth.
	 * @param string $key     The field or combinator of this entry.
	 */
	public function __construct(
		public readonly string $ruleKey,
		public readonly string $path,
		public readonly int $depth,
		public readonly string $key,
	) {
	}//end __construct()

	/**
	 * The position of one clause in this combinator's list.
	 *
	 * @param int $index The clause index.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function clause(int $index): self {
		return new self(ruleKey: $this->ruleKey, path: sprintf('%s[%d]', $this->path, $index), depth: $this->depth, key: $this->key);
	}//end clause()

	/**
	 * The position of a combinator nested in this clause, one level deeper.
	 *
	 * @param string $combinator Either `all` or `any`.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function nested(string $combinator): self {
		return new self(
			ruleKey: $this->ruleKey,
			path: sprintf('%s.%s', $this->path, $combinator),
			depth: ($this->depth + 1),
			key: $combinator
		);
	}//end nested()

	/**
	 * This position, testing the given field.
	 *
	 * @param string $field The field the clause tests.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function testing(string $field): self {
		return new self(ruleKey: $this->ruleKey, path: $this->path, depth: $this->depth, key: $field);
	}//end testing()

	/**
	 * The `trigger.filter.<path>` reference, with an optional suffix.
	 *
	 * @param string $suffix Appended as is, e.g. `.operator`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function field(string $suffix = ''): string {
		return sprintf('trigger.filter.%s', $this->path).$suffix;
	}//end field()

	/**
	 * A message that names the rule and this position, followed by the diagnosis.
	 *
	 * @param string $diagnosis Appended as is, starting with its own separator.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function message(string $diagnosis): string {
		return sprintf('Notification "%s" trigger.filter.%s', $this->ruleKey, $this->path).$diagnosis;
	}//end message()

	/**
	 * One structured error in the shape the validator reports.
	 *
	 * @param string                                          $code  Machine-readable error code.
	 * @param array{field: string, value: mixed, message: string} $issue The offending key, value and diagnosis.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function error(string $code, array $issue): array {
		return [
			'code'    => $code,
			'ruleKey' => $this->ruleKey,
			'field'   => $issue['field'],
			'value'   => $issue['value'],
			'message' => $issue['message'],
		];
	}//end error()
}//end class
