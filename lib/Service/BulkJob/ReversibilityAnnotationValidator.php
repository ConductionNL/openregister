<?php

/**
 * Validate what a schema says about undoing its bulk actions.
 *
 * A schema declares its extra actions under
 * `configuration['x-openregister-action']`, a map of action key to
 * `{name, description}`. This validator reads the three keys this change
 * adds to that entry:
 *
 *  - `kind`: `write`, `destroy`, `dispatch` or `transfer`.
 *  - `reversible`: whether the action can be undone.
 *  - `reversalWindow`: for how long, in seconds.
 *
 * It refuses rather than warns. An action declared reversible that cannot be
 * does not fail at save: it fails on the day somebody undoes a hundred
 * deletions and gets an error, or worse, a job that reports success and
 * restores nothing. Refusing here is the difference between a typo and a
 * promise (D-4).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

/**
 * Class ReversibilityAnnotationValidator
 */
final class ReversibilityAnnotationValidator implements ReversibilityAnnotationValidatorInterface {

	/**
	 * The configuration key holding a schema's declared actions.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-action';

	/**
	 * The one kind of action that can be undone: it writes properties, and
	 * the values it wrote over are recoverable.
	 *
	 * @var string
	 */
	public const KIND_WRITE = 'write';

	/**
	 * The kinds that leave nothing to go back to.
	 *
	 * A destruction removes the object, a dispatch has already reached
	 * somebody's inbox, and an e-depot transfer has handed custody to another
	 * system. None of the three is undoable by writing a value back.
	 *
	 * @var array<int, string>
	 */
	public const IRREVERSIBLE_KINDS = ['destroy', 'dispatch', 'transfer'];

	/**
	 * The longest reversal window a declaration may name, in seconds.
	 *
	 * Ninety days. Past that the recorded prior values are an archive rather
	 * than an undo buffer, and the odds that restoring them destroys somebody
	 * else's later work approach one (D-2, D-3).
	 *
	 * @var int
	 */
	public const MAX_WINDOW = 7776000;

	/**
	 * Words in an action key that read as one of the irreversible kinds.
	 *
	 * Only consulted when the entry names no `kind` at all. An author who
	 * says `"kind": "write"` beside a key called `delete-draft` has been
	 * explicit, and this validator takes them at their word; the runtime
	 * authority is the registered action, which either implements
	 * {@see \OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface} or
	 * does not.
	 *
	 * @var array<int, string>
	 */
	private const IRREVERSIBLE_WORDS = [
		'delete',
		'destroy',
		'remove',
		'purge',
		'erase',
		'shred',
		'dispatch',
		'send',
		'notify',
		'mail',
		'transfer',
		'edepot',
	];

	/**
	 * Validate the declared actions of one schema.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals, empty when the declaration holds.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function validate(array $configuration): array {
		$declared = ($configuration[self::ANNOTATION] ?? null);

		if (is_array($declared) === false) {
			return [];
		}

		$errors = [];

		foreach ($declared as $key => $definition) {
			if (is_string($key) === false || $key === '' || is_array($definition) === false) {
				continue;
			}

			$errors = array_merge($errors, $this->validateEntry(key: $key, definition: $definition));
		}

		return $errors;
	}//end validate()

	/**
	 * Validate one declared action: its shape first, then — when it claims to be
	 * reversible — whether what it does can be undone at all.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function validateEntry(string $key, array $definition): array {
		$errors = $this->validateShape(key: $key, definition: $definition);

		if ($errors !== []) {
			return $errors;
		}

		if (($definition['reversible'] ?? false) !== true) {
			return [];
		}

		return $this->irreversibilityRefusals(key: $key, definition: $definition);
	}//end validateEntry()

	/**
	 * Refuse a reversible claim on an act that cannot be undone: a declared
	 * irreversible kind, or no kind on a key that reads as one.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declaration, declaring reversible true.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function irreversibilityRefusals(string $key, array $definition): array {
		$kind = ($definition['kind'] ?? null);

		if (is_string($kind) === true && in_array($kind, self::IRREVERSIBLE_KINDS, true) === true) {
			return [$this->irreversibleKindRefusal(key: $key, kind: $kind)];
		}

		if ($kind === null && $this->readsAsIrreversible(key: $key) === true) {
			return [$this->undeclaredKindRefusal(key: $key)];
		}

		return [];
	}//end irreversibilityRefusals()

	/**
	 * The refusal for an irreversible kind that declares itself reversible.
	 *
	 * @param string $key  The action key.
	 * @param string $kind The declared irreversible kind.
	 *
	 * @return array{code: string, message: string} The refusal.
	 */
	private function irreversibleKindRefusal(string $key, string $kind): array {
		return [
			'code' => 'reversibility-irreversible-kind',
			'message' => sprintf(
				'The action "%s" declares kind "%s" and reversible true. A %s leaves nothing to write '
				.'back, so it cannot be undone.',
				$key,
				$kind,
				$kind
			),
		];
	}//end irreversibleKindRefusal()

	/**
	 * The refusal for a reversible action without a kind whose key reads as irreversible.
	 *
	 * @param string $key The action key.
	 *
	 * @return array{code: string, message: string} The refusal.
	 */
	private function undeclaredKindRefusal(string $key): array {
		return [
			'code' => 'reversibility-undeclared-kind',
			'message' => sprintf(
				'The action "%s" declares reversible true and names no kind, and its key reads as an act '
				.'that cannot be undone. Add "kind" naming what it really does.',
				$key
			),
		];
	}//end undeclaredKindRefusal()

	/**
	 * Check the three keys hold the shapes they must hold: kind, reversible, reversal window.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function validateShape(string $key, array $definition): array {
		return array_merge(
			$this->validateKind(key: $key, definition: $definition),
			$this->validateReversibleFlag(key: $key, definition: $definition),
			$this->validateWindow(key: $key, definition: $definition)
		);
	}//end validateShape()

	/**
	 * Check a declared kind is one the vocabulary holds.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function validateKind(string $key, array $definition): array {
		$kind  = ($definition['kind'] ?? null);
		$known = array_merge([self::KIND_WRITE], self::IRREVERSIBLE_KINDS);
		if ($kind !== null && (is_string($kind) === false || in_array($kind, $known, true) === false)) {
			return [
				[
					'code' => 'reversibility-unknown-kind',
					'message' => sprintf(
						'The action "%s" names a kind the vocabulary does not hold. Use one of %s.',
						$key,
						implode(', ', $known)
					),
				],
			];
		}

		return [];
	}//end validateKind()

	/**
	 * Check a declared reversible flag is a boolean.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function validateReversibleFlag(string $key, array $definition): array {
		$reversible = ($definition['reversible'] ?? null);
		if ($reversible !== null && is_bool($reversible) === false) {
			return [
				[
					'code' => 'reversibility-not-a-boolean',
					'message' => sprintf('The action "%s" must declare reversible as true or false.', $key),
				],
			];
		}

		return [];
	}//end validateReversibleFlag()

	/**
	 * Check a declared reversal window is a bound somebody can act inside.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declaration, carrying the window and the reversibility.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals; none when no window is declared.
	 */
	private function validateWindow(string $key, array $definition): array {
		$window = ($definition['reversalWindow'] ?? null);
		if ($window === null) {
			return [];
		}

		if ($this->isWindowInRange(window: $window) === false) {
			return [$this->windowOutOfRangeRefusal(key: $key)];
		}

		if (($definition['reversible'] ?? null) === true) {
			return [];
		}

		return [$this->windowWithoutReversibleRefusal(key: $key)];
	}//end validateWindow()

	/**
	 * Whether a reversal window is a whole number of seconds between 1 and MAX_WINDOW.
	 *
	 * @param mixed $window The declared window.
	 *
	 * @return bool True when it is in range.
	 */
	private function isWindowInRange(mixed $window): bool {
		return is_int($window) === true && $window >= 1 && $window <= self::MAX_WINDOW;
	}//end isWindowInRange()

	/**
	 * The refusal for a reversal window outside its bounds.
	 *
	 * @param string $key The action key.
	 *
	 * @return array{code: string, message: string} The refusal.
	 */
	private function windowOutOfRangeRefusal(string $key): array {
		return [
			'code' => 'reversibility-window-out-of-range',
			'message' => sprintf(
				'The action "%s" must declare reversalWindow as a whole number of seconds between 1 and %d.',
				$key,
				self::MAX_WINDOW
			),
		];
	}//end windowOutOfRangeRefusal()

	/**
	 * The refusal for a reversal window on an action that is not reversible.
	 *
	 * @param string $key The action key.
	 *
	 * @return array{code: string, message: string} The refusal.
	 */
	private function windowWithoutReversibleRefusal(string $key): array {
		return [
			'code' => 'reversibility-window-without-reversible',
			'message' => sprintf(
				'The action "%s" names a reversalWindow but does not declare itself reversible, so the window '
				.'bounds nothing.',
				$key
			),
		];
	}//end windowWithoutReversibleRefusal()

	/**
	 * Whether an action key reads as one of the irreversible kinds.
	 *
	 * @param string $key The action key.
	 *
	 * @return bool True when the key names an act that cannot be undone.
	 */
	private function readsAsIrreversible(string $key): bool {
		$haystack = strtolower($key);

		foreach (self::IRREVERSIBLE_WORDS as $word) {
			if (str_contains($haystack, $word) === true) {
				return true;
			}
		}

		return false;
	}//end readsAsIrreversible()
}//end class
