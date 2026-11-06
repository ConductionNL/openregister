<?php

/**
 * MagicDroppedProperties: say so when a property the caller sent is thrown away.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Say so when a property the caller sent is about to be thrown away.
 *
 * The row is built by walking the schema's declared properties, a whitelist by
 * omission. Anything the caller sent that the schema does not declare is never
 * read, and there is no `object` JSON blob column to fall back on, so it is
 * gone: no error, no warning, no trace. The write succeeds and the field is
 * missing.
 *
 * Measured 2026-08-02: the hydra flow documents carry `description`, the
 * agentflow schema had no such property, and every flow's description was
 * discarded on save. The graphs table then showed "—" for all ten, which
 * reads exactly like ten flows that were never given a description.
 *
 * This does not reject: rejecting at the DB write boundary is far too late for
 * a clean 400 (the entity is built, folders may exist, cascades have run) and
 * would break every caller that harmlessly posts an extra key. It makes the
 * loss visible, which is the whole difference between a schema that needs a
 * property added and a field that quietly evaporates.
 *
 * @spec openspec/changes/or-silent-field-loss/specs/silent-field-loss/spec.md
 */
final class MagicDroppedProperties {
	/**
	 * Key names that are no user data: the caller echoing back an identifier.
	 *
	 * @var array<string>
	 */
	private const IDENTIFIER_KEYS = ['', 'id', 'uuid'];

	/**
	 * The properties reported so far, by schema.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $reported = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the warning.
	 *
	 * @spec openspec/changes/or-silent-field-loss/specs/silent-field-loss/spec.md
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * Warn about the undeclared properties of one write.
	 *
	 * Once per schema and property per process. One import or bulk write of
	 * thousands of objects with the same undeclared key logged one warning per
	 * object (a throwaway instance wrote a 28 GB log). The first discard of each
	 * property is still a warning, so schema drift stays visible; a new
	 * undeclared property on the same schema warns again.
	 *
	 * @param array<string, mixed> $data   The caller's data, minus `@self`.
	 * @param Schema               $schema The schema being written to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-silent-field-loss/specs/silent-field-loss/spec.md
	 */
	public function report(array $data, Schema $schema): void {
		$schemaKey = (string)($schema->getId() ?? $schema->getSlug() ?? '');
		$fresh = array_diff($this->undeclared(data: $data, declared: $schema->getProperties()), array_keys($this->reported[$schemaKey] ?? []));
		if ($fresh === []) {
			return;
		}

		$this->reported[$schemaKey] = ($this->reported[$schemaKey] ?? []) + array_fill_keys($fresh, true);
		$this->warn(dropped: array_values($fresh), schema: $schema);
	}//end report()

	/**
	 * The user data keys the schema does not declare.
	 *
	 * @param array $data     The caller's data.
	 * @param array $declared The schema's properties.
	 *
	 * @return array<string>
	 */
	private function undeclared(array $data, array $declared): array {
		$dropped = [];
		foreach (array_keys($data) as $key) {
			$name = (string)$key;
			if (array_key_exists($name, $declared) === false && $this->isUserData(name: $name) === true) {
				$dropped[] = $name;
			}
		}

		return $dropped;
	}//end undeclared()

	/**
	 * Whether a key is user data: `@`-prefixed keys are envelope or metadata
	 * (`@self` is already gone), `_`-prefixed keys are OpenRegister's own
	 * metadata columns. Warning about those would drown the signal in noise on
	 * every save.
	 *
	 * @param string $name The key.
	 *
	 * @return bool
	 */
	private function isUserData(string $name): bool {
		return in_array($name, self::IDENTIFIER_KEYS, true) === false && $name[0] !== '@' && $name[0] !== '_';
	}//end isUserData()

	/**
	 * Log the warning.
	 *
	 * @param array<string> $dropped The property names.
	 * @param Schema        $schema  The schema.
	 *
	 * @return void
	 */
	private function warn(array $dropped, Schema $schema): void {
		$this->logger->warning(
			message: sprintf(
				'[MagicMapper] Discarding %d propert%s the schema "%s" does not declare: %s. '
				. 'They are NOT stored anywhere — add them to the schema or stop sending them. '
				. 'Reported once per property per request.',
				count($dropped),
				$this->pluralSuffix(count: count($dropped)),
				(string)($schema->getTitle() ?? $schema->getSlug() ?? (string)$schema->getId()),
				implode(', ', $dropped)
			),
			context: ['schema' => $schema->getId(), 'schemaSlug' => $schema->getSlug(), 'dropped' => $dropped]
		);
	}//end warn()

	/**
	 * The ending of "property" for a count.
	 *
	 * @param int $count The count.
	 *
	 * @return string
	 */
	private function pluralSuffix(int $count): string {
		if ($count === 1) {
			return 'y';
		}

		return 'ies';
	}//end pluralSuffix()
}//end class
