<?php

/**
 * OpenRegister ObjectSlugLog
 *
 * The `[ObjectSlug]` warnings of the slug rules (#4037), kept apart from the
 * rules in ObjectSlugHandler so each one stays small.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Psr\Log\LoggerInterface;

/**
 * Writes the slug warnings, every line with the same context keys.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */
class ObjectSlugLog {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the warnings.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log a collision: the object was saved (or restored) without a slug.
	 *
	 * @param ObjectSlugClash $clash Who wanted which slug, who holds it, and the suffixed slug that was also taken, if tried.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function collision(ObjectSlugClash $clash): void {
		$want = $clash->want;
		$this->logger->warning(
			message: $this->heldMessage(want: $want, holderUuid: $clash->holderUuid)
				. sprintf('object %s %s%s', $want->objectUuid, $this->collisionOutcome(path: $want->path), $this->alsoTaken(suffixed: $clash->suffixed)),
			context: $this->heldContext(want: $want, holderUuid: $clash->holderUuid)
		);
	}//end collision()

	/**
	 * What a collision did to the object, as the log line says it per path.
	 *
	 * @param string $path single | bulk | backfill | restore | move | seed.
	 *
	 * @return string The outcome.
	 */
	private function collisionOutcome(string $path): string {
		return match ($path) {
			'backfill' => 'gets no slug from the backfill',
			'restore' => 'was restored without a slug',
			'seed' => 'was seeded without a slug',
			'move' => 'was moved without a slug',
			default => 'was saved without a slug',
		};
	}//end collisionOutcome()

	/**
	 * The note that the suffixed slug was taken too, empty when none was tried.
	 *
	 * @param string|null $suffixed The suffixed slug that was also taken, if tried.
	 *
	 * @return string The note.
	 */
	private function alsoTaken(string|null $suffixed): string {
		if ($suffixed === null) {
			return '';
		}

		return sprintf(' (suffixed "%s" also taken)', $suffixed);
	}//end alsoTaken()

	/**
	 * Log a restore, move or seed import that took the suffixed slug because the wanted one is held.
	 *
	 * The object silently changes its public slug there, so unlike a plain save it is
	 * always said.
	 *
	 * @param ObjectSlugClash $clash Who wanted which slug, who holds it, and the suffixed slug the object gets.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function suffixedInstead(ObjectSlugClash $clash): void {
		$want     = $clash->want;
		$suffixed = (string)$clash->suffixed;
		$this->logger->warning(
			message: $this->heldMessage(want: $want, holderUuid: $clash->holderUuid)
				. sprintf('object %s got the suffixed slug "%s" instead (%s)', $want->objectUuid, $suffixed, $want->path),
			context: $this->heldContext(want: $want, holderUuid: $clash->holderUuid) + ['suffixedSlug' => $suffixed]
		);
	}//end suffixedInstead()

	/**
	 * Log a client slug that was ignored because the object's slug is frozen.
	 *
	 * @param ObjectSlugWant $want The object and the slug that stays (`$want->slug`).
	 * @param string         $sent What the client sent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-an-object-slug-once-set-must-not-be-changed-by-a-later-save
	 */
	public function ignoredClientSlug(ObjectSlugWant $want, string $sent): void {
		$message = sprintf(
			'[ObjectSlug] Ignored client slug "%s" for object %s in %s: slug "%s" is frozen',
			$sent,
			$want->objectUuid,
			$want->scope->describe(),
			$want->slug
		);
		$context = $want->scope->context(path: $want->path) + ['slug' => $sent, 'objectUuid' => $want->objectUuid, 'frozenSlug' => $want->slug];
		$this->logger->warning(message: $message, context: $context);
	}//end ignoredClientSlug()

	/**
	 * Log a slug that was dropped because the object's register did not resolve.
	 *
	 * @param ObjectSlugWant $want The slug that would have been written, its object and its schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function registerUnresolved(ObjectSlugWant $want): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Slug "%s" for object %s (schema %s) was not written: its register did not resolve, so uniqueness could not be checked',
				$want->slug,
				$want->objectUuid,
				(string)$want->scope->schema->getSlug()
			),
			context: (new ObjectSlugScope(register: null, schema: $want->scope->schema))->context(path: 'single') + [
				'slug' => $want->slug,
				'objectUuid' => $want->objectUuid,
			]
		);
	}//end registerUnresolved()

	/**
	 * Log a register lookup that failed during a slug check.
	 *
	 * @param string $registerId The register id.
	 * @param string $reason     The error message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function registerLookupFailed(string $registerId, string $reason): void {
		$this->logger->warning(
			message: '[ObjectSlug] Could not resolve register ' . $registerId . ' for the slug check: ' . $reason,
			context: ['app' => 'openregister']
		);
	}//end registerLookupFailed()

	/**
	 * The start of a "slug is already held" line; what happened to the object follows it.
	 *
	 * @param ObjectSlugWant $want       Who wanted which slug, where, on which path.
	 * @param string         $holderUuid Who holds it.
	 *
	 * @return string The message up to and including `; `.
	 */
	private function heldMessage(ObjectSlugWant $want, string $holderUuid): string {
		return sprintf(
			'[ObjectSlug] Slug "%s" is already held by object %s in %s; ',
			$want->slug,
			$holderUuid,
			$want->scope->describe()
		);
	}//end heldMessage()

	/**
	 * The context keys of a "slug is already held" line.
	 *
	 * @param ObjectSlugWant $want       Who wanted which slug, where, on which path.
	 * @param string         $holderUuid Who holds it.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function heldContext(ObjectSlugWant $want, string $holderUuid): array {
		return $want->scope->context(path: $want->path) + [
			'slug' => $want->slug,
			'objectUuid' => $want->objectUuid,
			'holderUuid' => $holderUuid,
		];
	}//end heldContext()
}//end class
