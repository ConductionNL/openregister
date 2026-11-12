<?php

/**
 * MagicFacetFieldLabel: the human-readable label of one facet value.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Kernel\Contracts\ObjectNameCacheInterface;
use OCA\OpenRegister\Formats\UuidFormat;
use OCP\IDBConnection;

/**
 * Turn a register id, schema id, organisation or object uuid into its name.
 *
 * A failing lookup falls through to the next source: a facet stays usable
 * with a shortened uuid or a numbered title.
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */
final class MagicFacetFieldLabel {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection                 $db    The connection.
	 * @param ObjectNameCacheInterface|null $names The object name lookup, when available.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(private readonly IDBConnection $db, private readonly ?ObjectNameCacheInterface $names) {
	}//end __construct()

	/**
	 * The label of a value of a field.
	 *
	 * @param string $field The field name.
	 * @param mixed  $value The field value.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function labelOf(string $field, mixed $value): string {
		$numbered = ['_register' => ['openregister_registers', 'Register'], '_schema' => ['openregister_schemas', 'Schema']];
		if (isset($numbered[$field]) === true && is_numeric($value) === true) {
			[$table, $noun] = $numbered[$field];
			return ($this->titleIn(table: $table, id: (int)$value) ?? "{$noun} {$value}");
		}

		if (is_string($value) === true && $this->namesAnObject(field: $field, value: $value) === true) {
			return $this->objectName(field: $field, uuid: $value);
		}

		return (string)$value;
	}//end labelOf()

	/**
	 * Whether a string value is looked up as an organisation or an object.
	 *
	 * @param string $field The field name.
	 * @param string $value The value.
	 *
	 * @return bool True for a non-empty organisation or any uuid.
	 */
	private function namesAnObject(string $field, string $value): bool {
		return (($field === '_organisation' && $value !== '') || UuidFormat::isCanonical(value: $value) === true);
	}//end namesAnObject()

	/**
	 * The name of an organisation or object, or its shortened uuid.
	 *
	 * @param string $field The field name.
	 * @param string $uuid  The uuid.
	 *
	 * @return string The label.
	 */
	private function objectName(string $field, string $uuid): string {
		$name = null;
		if ($field === '_organisation') {
			$name = $this->organisationName(uuid: $uuid);
		}

		return ($name ?? $this->names?->getMultipleObjectNames([$uuid])[$uuid] ?? (substr($uuid, 0, 8) . '...'));
	}//end objectName()

	/**
	 * The title of a register or schema.
	 *
	 * @param string $table The table.
	 * @param int    $id    The id.
	 *
	 * @return string|null The title, or null when there is none to read.
	 */
	private function titleIn(string $table, int $id): ?string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('title')->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
			$title = $qb->executeQuery()->fetchOne();
		} catch (\Exception $e) {
			return null;
		}

		if ($title === false) {
			return null;
		}

		return (string)$title;
	}//end titleIn()

	/**
	 * The name in the system organisations table.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return string|null The name, or null when there is none to read.
	 */
	private function organisationName(string $uuid): ?string {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('name')->from('openregister_organisations')->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
			$name = $qb->executeQuery()->fetchOne();
		} catch (\Exception $e) {
			return null;
		}

		if ($name === false || $name === null) {
			return null;
		}

		return (string)$name;
	}//end organisationName()
}//end class
