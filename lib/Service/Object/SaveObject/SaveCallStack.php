<?php

/**
 * OpenRegister SaveCallStack
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * The saves in flight, outermost first, so a reference back to one of them is seen as a cycle.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class SaveCallStack {

	/**
	 * The frames, outermost first.
	 *
	 * @var list<SaveCallFrame>
	 */
	private array $frames = [];

	/**
	 * The depth of each frame, by its key.
	 *
	 * @var array<string, int>
	 */
	private array $depths = [];

	/**
	 * Push a frame.
	 *
	 * A create without a uuid cannot be referred back to, and a frame already on the
	 * stack is not pushed twice, so pops stay balanced.
	 *
	 * @param SaveCallFrame $frame The frame.
	 *
	 * @return string|null The key to pop it with, or null when nothing was pushed.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function push(SaveCallFrame $frame): ?string {
		$key = $frame->key();
		if ($frame->uuid === '' || array_key_exists($key, $this->depths) === true) {
			return null;
		}

		$this->frames[] = $frame;
		$this->depths[$key] = (count($this->frames) - 1);

		return $key;
	}//end push()

	/**
	 * Pop a frame and every frame above it, so a pop an exception skipped cannot leak.
	 *
	 * @param string|null $key The key push() returned; null and unknown keys do nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function pop(?string $key): void {
		if ($key === null || array_key_exists($key, $this->depths) === false) {
			return;
		}

		foreach (array_splice($this->frames, $this->depths[$key]) as $frame) {
			unset($this->depths[$frame->key()]);
		}
	}//end pop()

	/**
	 * The stack when this uuid is being saved on it, under any schema; else null.
	 *
	 * @param string $uuid The referenced uuid.
	 *
	 * @return list<SaveCallFrame>|null The frames.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function cycleThrough(string $uuid): ?array {
		foreach ($this->frames as $frame) {
			if ($frame->uuid === $uuid && $uuid !== '') {
				return $this->frames;
			}
		}

		return null;
	}//end cycleThrough()
}//end class
