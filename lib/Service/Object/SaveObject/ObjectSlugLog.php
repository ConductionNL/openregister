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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Writes the slug warnings, every line with the same context keys.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
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
	 * @param Register    $register   The register.
	 * @param Schema      $schema     The schema.
	 * @param string      $slug       The wanted slug.
	 * @param string      $holderUuid Who holds it.
	 * @param string      $objectUuid Who wanted it.
	 * @param string|null $suffixed   The suffixed slug that was also taken, if tried.
	 * @param string      $path       single | bulk | backfill | restore | move | seed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function collision(
		Register $register,
		Schema $schema,
		string $slug,
		string $holderUuid,
		string $objectUuid,
		?string $suffixed,
		string $path,
	): void {
		$alsoTaken = '';
		if ($suffixed !== null) {
			$alsoTaken = sprintf(' (suffixed "%s" also taken)', $suffixed);
		}

		$outcome = match ($path) {
			'backfill' => 'gets no slug from the backfill',
			'restore' => 'was restored without a slug',
			'seed' => 'was seeded without a slug',
			'move' => 'was moved without a slug',
			default => 'was saved without a slug',
		};

		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Slug "%s" is already held by object %s in register %s (%s) / schema %s (%s); object %s ' . $outcome . '%s',
				$slug,
				$holderUuid,
				(string)$register->getSlug(),
				(string)$register->getId(),
				(string)$schema->getSlug(),
				(string)$schema->getId(),
				$objectUuid,
				$alsoTaken
			),
			context: $this->context(register: $register, schema: $schema, path: $path) + [
				'slug' => $slug,
				'objectUuid' => $objectUuid,
				'holderUuid' => $holderUuid,
			]
		);
	}//end collision()

	/**
	 * Log a restore or seed import that took the suffixed slug because the wanted one is held.
	 *
	 * The object silently changes its public slug there, so unlike a plain save it is
	 * always said.
	 *
	 * @param Register $register   The register.
	 * @param Schema   $schema     The schema.
	 * @param string   $slug       The wanted slug.
	 * @param string   $holderUuid Who holds it.
	 * @param string   $objectUuid Who wanted it.
	 * @param string   $suffixed   The suffixed slug the object gets.
	 * @param string   $path       restore | move | seed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function suffixedInstead(
		Register $register,
		Schema $schema,
		string $slug,
		string $holderUuid,
		string $objectUuid,
		string $suffixed,
		string $path,
	): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Slug "%s" is already held by object %s in register %s (%s) / schema %s (%s); object %s got the suffixed slug "%s" instead (%s)',
				$slug,
				$holderUuid,
				(string)$register->getSlug(),
				(string)$register->getId(),
				(string)$schema->getSlug(),
				(string)$schema->getId(),
				$objectUuid,
				$suffixed,
				$path
			),
			context: $this->context(register: $register, schema: $schema, path: $path) + [
				'slug' => $slug,
				'objectUuid' => $objectUuid,
				'holderUuid' => $holderUuid,
				'suffixedSlug' => $suffixed,
			]
		);
	}//end suffixedInstead()

	/**
	 * Log a client slug that was ignored because the object's slug is frozen.
	 *
	 * @param Register|null $register   The register.
	 * @param Schema        $schema     The schema.
	 * @param string        $objectUuid The object.
	 * @param string        $sent       What the client sent.
	 * @param string        $frozen     The slug that stays.
	 * @param string        $path       single | bulk.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-an-object-slug-once-set-must-not-be-changed-by-a-later-save
	 */
	public function ignoredClientSlug(
		?Register $register,
		Schema $schema,
		string $objectUuid,
		string $sent,
		string $frozen,
		string $path,
	): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Ignored client slug "%s" for object %s in register %s (%s) / schema %s (%s): slug "%s" is frozen',
				$sent,
				$objectUuid,
				(string)$register?->getSlug(),
				(string)$register?->getId(),
				(string)$schema->getSlug(),
				(string)$schema->getId(),
				$frozen
			),
			context: $this->context(register: $register, schema: $schema, path: $path) + [
				'slug' => $sent,
				'objectUuid' => $objectUuid,
				'frozenSlug' => $frozen,
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
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function registerUnresolved(Schema $schema, string $objectUuid, string $slug): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSlug] Slug "%s" for object %s (schema %s) was not written: its register did not resolve, so uniqueness could not be checked',
				$slug,
				$objectUuid,
				(string)$schema->getSlug()
			),
			context: $this->context(register: null, schema: $schema, path: 'single') + [
				'slug' => $slug,
				'objectUuid' => $objectUuid,
			]
		);
	}//end registerUnresolved()

	/**
	 * The context keys every [ObjectSlug] line carries.
	 *
	 * @param Register|null $register The register.
	 * @param Schema        $schema   The schema.
	 * @param string        $path     single | bulk | backfill | restore | move | seed | lookup.
	 *
	 * @return array<string, mixed>
	 */
	private function context(?Register $register, Schema $schema, string $path): array {
		return [
			'app' => 'openregister',
			'path' => $path,
			'registerId' => $register?->getId(),
			'registerSlug' => $register?->getSlug(),
			'schemaId' => $schema->getId(),
			'schemaSlug' => $schema->getSlug(),
		];
	}//end context()
}//end class
