<?php

/**
 * OpenRegister ObjectSlugScope
 *
 * The register and schema a slug must be unique in (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
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

/**
 * A register and schema pair: the unit slug uniqueness is checked in.
 *
 * The register is null only on a single save whose register did not resolve;
 * the slug is then not written and the log line names the schema alone.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
 */
final class ObjectSlugScope {

	/**
	 * Constructor.
	 *
	 * @param Register|null $register The register.
	 * @param Schema        $schema   The schema.
	 */
	public function __construct(
		public readonly ?Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * Whether the schema appends a date-time suffix to a colliding slug.
	 *
	 * @return bool True when `objectSlugSuffix` is on.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function suffixOn(): bool {
		return ((($this->schema->getConfiguration() ?? [])['objectSlugSuffix'] ?? false) === true);
	}//end suffixOn()

	/**
	 * The scope as a log line names it: `register <slug> (<id>) / schema <slug> (<id>)`.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function describe(): string {
		return sprintf(
			'register %s (%s) / schema %s (%s)',
			(string)$this->register?->getSlug(),
			(string)$this->register?->getId(),
			(string)$this->schema->getSlug(),
			(string)$this->schema->getId()
		);
	}//end describe()

	/**
	 * The context keys every [ObjectSlug] log line carries.
	 *
	 * @param string $path single | bulk | backfill | restore | move | seed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-object-slugs-must-be-unique-within-a-register-and-schema-and-a-colliding-slug-must-not-be-written
	 */
	public function context(string $path): array {
		return [
			'app' => 'openregister',
			'path' => $path,
			'registerId' => $this->register?->getId(),
			'registerSlug' => $this->register?->getSlug(),
			'schemaId' => $this->schema->getId(),
			'schemaSlug' => $this->schema->getSlug(),
		];
	}//end context()
}//end class
