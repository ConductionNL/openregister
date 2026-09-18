<?php

/**
 * Why a write happened, in words a filter can use.
 *
 * 🔴 A CLOSED VOCABULARY, DERIVED ON THE SERVER (D-1). An open string would be
 * filled with whatever each caller felt like and the filter would be useless
 * within a month: "import", "Import", "bulk import", "IMPORT-2026" and
 * "migration script" would all mean the same thing and none of them would
 * match. Six values, fixed, and a value outside them is not stored.
 *
 * 🔴 IT IS NEVER READ FROM THE REQUEST, AND THAT IS A SECURITY PROPERTY RATHER
 * THAN TIDINESS. A client that can claim its write was a `migration` can hide a
 * write: an administrator filtering out the noise of a bulk load would filter
 * out exactly the entry somebody wanted buried. So the cause comes from the
 * ACTING CONTEXT, a request that supplies one is ignored, and the attempt is
 * recorded — because a caller trying to label its own writes is itself worth
 * knowing about.
 *
 * 🔑 THE RUN IS THE SECOND HALF, AND WITHOUT IT THE CAUSE IS NEARLY USELESS.
 * "This entry was caused by an import" does not say WHICH import. A cause that
 * is a run names it, so the eight hundred entries of one load are reachable as
 * a set and one row's failure is reachable from the entry it produced (D-2).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The cause of the write currently being made, as an ambient frame.
 *
 * An ambient context rather than a threaded argument, for the reason
 * {@see SystemOperationContext} is one: the alternative is a parameter on every
 * save signature in the app and on every caller of those, and a single caller
 * that forgot to pass it would produce entries that are silently uncaused.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
 */
final class WriteCause {

	/**
	 * A person acting directly, through the interface or the API.
	 *
	 * The DEFAULT, and deliberately so: an unlabelled write is somebody's, and
	 * assuming otherwise would let a real person's change read as machinery.
	 *
	 * @var string
	 */
	public const PERSON = 'person';

	/**
	 * A scheduled job: cron, a sweep, a retention pass.
	 *
	 * @var string
	 */
	public const SCHEDULED = 'scheduled';

	/**
	 * A load of data from a file or a feed.
	 *
	 * @var string
	 */
	public const IMPORT = 'import';

	/**
	 * A migration or a repair step.
	 *
	 * @var string
	 */
	public const MIGRATION = 'migration';

	/**
	 * A declared rule firing: a flow node, an action, a trigger.
	 *
	 * @var string
	 */
	public const RULE = 'rule';

	/**
	 * A consequence of another write, such as a referential cascade.
	 *
	 * @var string
	 */
	public const CASCADE = 'cascade';

	/**
	 * The whole vocabulary. Nothing outside it is ever stored.
	 *
	 * @var array<int, string>
	 */
	public const ALL = [
		self::PERSON,
		self::SCHEDULED,
		self::IMPORT,
		self::MIGRATION,
		self::RULE,
		self::CASCADE,
	];

	/**
	 * The frames currently open, innermost last.
	 *
	 * A STACK, not a single value: an import that fires a rule that cascades is
	 * three causes deep, and the entry a write produces is caused by the
	 * innermost one. Flattening it to a single value would make the cascade
	 * inside an import read as an import, and the import would then appear to
	 * have written rows it never touched.
	 *
	 * @var array<int, array{cause: string, run: string|null}>
	 */
	private static array $frames = [];

	/**
	 * Whether a request tried to name its own cause this request.
	 *
	 * @var boolean
	 */
	private static bool $clientAttempted = false;

	/**
	 * Run something with a cause on the stack.
	 *
	 * @param string      $cause     One of {@see ALL}.
	 * @param string|null $run       The run this write belongs to, when there is one.
	 * @param callable    $operation The work.
	 *
	 * @return mixed Whatever the work returned.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public static function as(string $cause, ?string $run, callable $operation): mixed {
		self::$frames[] = ['cause' => self::normalise(cause: $cause), 'run' => $run];

		try {
			return $operation();
		} finally {
			// 🔑 `finally`, ALWAYS. A frame left on the stack by a throwing
			// operation would label every later write in the same request with
			// a cause that had already finished, and the request would look
			// like one long import.
			array_pop(self::$frames);
		}
	}

	/**
	 * The cause of the write being made now.
	 *
	 * @return array{cause: string, run: string|null} The innermost frame.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public static function current(): array {
		$frame = end(self::$frames);
		if ($frame === false) {
			// An unlabelled write is somebody's.
			return ['cause' => self::PERSON, 'run' => null];
		}

		return $frame;
	}

	/**
	 * Note that a request tried to name its own cause.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public static function noteClientAttempt(): void {
		self::$clientAttempted = true;
	}

	/**
	 * Whether a request tried to name its own cause this request.
	 *
	 * @return boolean True when one did.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public static function clientAttempted(): bool {
		return self::$clientAttempted;
	}

	/**
	 * A value from the vocabulary, or the default.
	 *
	 * 🔴 AN UNKNOWN VALUE BECOMES `person`, IT DOES NOT PASS THROUGH. Storing a
	 * word nobody declared is how the closed vocabulary stops being closed, one
	 * caller at a time, and nothing would report it.
	 *
	 * @param string $cause The value.
	 *
	 * @return string The normalised cause.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md#requirement-every-audit-entry-names-the-cause-of-the-write-req-rcn-001
	 */
	public static function normalise(string $cause): string {
		$cause = strtolower(trim($cause));

		if (in_array($cause, self::ALL, true) === true) {
			return $cause;
		}

		return self::PERSON;
	}

	/**
	 * Forget every frame. For tests and for a worker between jobs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/enhanced-audit-trail/spec.md
	 */
	public static function reset(): void {
		self::$frames = [];
		self::$clientAttempted = false;
	}
}//end class
