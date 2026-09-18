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
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

/**
 * Class ReversibilityAnnotationValidator
 */
final class ReversibilityAnnotationValidator {

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
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
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
	 * Validate one declared action.
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

		$kind = ($definition['kind'] ?? null);

		if (is_string($kind) === true && in_array($kind, self::IRREVERSIBLE_KINDS, true) === true) {
			return [
				[
					'code' => 'reversibility-irreversible-kind',
					'message' => sprintf(
						'The action "%s" declares kind "%s" and reversible true. A %s leaves nothing to write '
						.'back, so it cannot be undone.',
						$key,
						$kind,
						$kind
					),
				],
			];
		}

		if ($kind === null && $this->readsAsIrreversible(key: $key) === true) {
			return [
				[
					'code' => 'reversibility-undeclared-kind',
					'message' => sprintf(
						'The action "%s" declares reversible true and names no kind, and its key reads as an act '
						.'that cannot be undone. Add "kind" naming what it really does.',
						$key
					),
				],
			];
		}

		return [];
	}//end validateEntry()

	/**
	 * Check the three keys hold the shapes they must hold.
	 *
	 * @param string               $key        The action key.
	 * @param array<string, mixed> $definition The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function validateShape(string $key, array $definition): array {
		$errors = [];
		$kind = ($definition['kind'] ?? null);
		$reversible = ($definition['reversible'] ?? null);
		$window = ($definition['reversalWindow'] ?? null);

		$known = array_merge([self::KIND_WRITE], self::IRREVERSIBLE_KINDS);
		if ($kind !== null && (is_string($kind) === false || in_array($kind, $known, true) === false)) {
			$errors[] = [
				'code' => 'reversibility-unknown-kind',
				'message' => sprintf(
					'The action "%s" names a kind the vocabulary does not hold. Use one of %s.',
					$key,
					implode(', ', $known)
				),
			];
		}

		if ($reversible !== null && is_bool($reversible) === false) {
			$errors[] = [
				'code' => 'reversibility-not-a-boolean',
				'message' => sprintf('The action "%s" must declare reversible as true or false.', $key),
			];
		}

		if ($window !== null) {
			$errors = array_merge($errors, $this->validateWindow(key: $key, window: $window, reversible: $reversible));
		}

		return $errors;
	}//end validateShape()

	/**
	 * Check a reversal window is a bound somebody can act inside.
	 *
	 * @param string $key        The action key.
	 * @param mixed  $window     The declared window.
	 * @param mixed  $reversible The declared reversibility.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function validateWindow(string $key, mixed $window, mixed $reversible): array {
		if (is_int($window) === false || $window < 1 || $window > self::MAX_WINDOW) {
			return [
				[
					'code' => 'reversibility-window-out-of-range',
					'message' => sprintf(
						'The action "%s" must declare reversalWindow as a whole number of seconds between 1 and %d.',
						$key,
						self::MAX_WINDOW
					),
				],
			];
		}

		if ($reversible === true) {
			return [];
		}

		return [
			[
				'code' => 'reversibility-window-without-reversible',
				'message' => sprintf(
					'The action "%s" names a reversalWindow but does not declare itself reversible, so the window '
					.'bounds nothing.',
					$key
				),
			],
		];
	}//end validateWindow()

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
