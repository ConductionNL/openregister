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

use OCA\OpenRegister\Db\Schema;
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
	 * @param ObjectSlugWant $want       Who wanted which slug, where, on which path.
	 * @param string         $holderUuid Who holds it.
	 * @param string|null    $suffixed   The suffixed slug that was also taken, if tried.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function collision(ObjectSlugWant $want, string $holderUuid, ?string $suffixed): void {
		$alsoTaken = '';
		if ($suffixed !== null) {
			$alsoTaken = sprintf(' (suffixed "%s" also taken)', $suffixed);
		}

		$outcome = match ($want->path) {
			'backfill' => 'gets no slug from the backfill',
			'restore' => 'was restored without a slug',
			'seed' => 'was seeded without a slug',
			'move' => 'was moved without a slug',
			default => 'was saved without a slug',
		};

		$this->held(
			want: $want,
			holderUuid: $holderUuid,
			tail: sprintf('object %s %s%s', $want->objectUuid, $outcome, $alsoTaken),
			extra: []
		);
	}//end collision()

	/**
	 * Log a restore, move or seed import that took the suffixed slug because the wanted one is held.
	 *
	 * The object silently changes its public slug there, so unlike a plain save it is
	 * always said.
	 *
	 * @param ObjectSlugWant $want       Who wanted which slug, where, on which path.
	 * @param string         $holderUuid Who holds it.
	 * @param string         $suffixed   The suffixed slug the object gets.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function suffixedInstead(ObjectSlugWant $want, string $holderUuid, string $suffixed): void {
		$this->held(
			want: $want,
			holderUuid: $holderUuid,
			tail: sprintf('object %s got the suffixed slug "%s" instead (%s)', $want->objectUuid, $suffixed, $want->path),
			extra: ['suffixedSlug' => $suffixed]
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
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Ignored client slug "%s" for object %s in %s: slug "%s" is frozen',
				$sent,
				$want->objectUuid,
				$want->scope->describe(),
				$want->slug
			),
			context: $want->scope->context(path: $want->path) + [
				'slug' => $sent,
				'objectUuid' => $want->objectUuid,
				'frozenSlug' => $want->slug,
			]
		);
	}//end ignoredClientSlug()

	/**
	 * Log a slug that was dropped because the object's register did not resolve.
	 *
	 * @param Schema $schema     The schema.
	 * @param string $objectUuid The object.
	 * @param string $slug       The slug that would have been written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function registerUnresolved(Schema $schema, string $objectUuid, string $slug): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Slug "%s" for object %s (schema %s) was not written: its register did not resolve, so uniqueness could not be checked',
				$slug,
				$objectUuid,
				(string)$schema->getSlug()
			),
			context: (new ObjectSlugScope(register: null, schema: $schema))->context(path: 'single') + [
				'slug' => $slug,
				'objectUuid' => $objectUuid,
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
	 * Write a "slug is already held" line.
	 *
	 * @param ObjectSlugWant       $want       Who wanted which slug, where, on which path.
	 * @param string               $holderUuid Who holds it.
	 * @param string               $tail       What happened to the object instead.
	 * @param array<string, mixed> $extra      Context keys on top of the common ones.
	 *
	 * @return void
	 */
	private function held(ObjectSlugWant $want, string $holderUuid, string $tail, array $extra): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Slug "%s" is already held by object %s in %s; %s',
				$want->slug,
				$holderUuid,
				$want->scope->describe(),
				$tail
			),
			context: $want->scope->context(path: $want->path) + [
				'slug' => $want->slug,
				'objectUuid' => $want->objectUuid,
				'holderUuid' => $holderUuid,
			] + $extra
		);
	}//end held()
}//end class
