<?php

/**
 * OpenRegister MDTO Value Reader
 *
 * Reads raw archival values out of an object's blocks, in the lexical forms
 * MDTO's XSD types accept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The primitives every archival read shares.
 *
 * Two layers carry the facts: the `retention` block under the abstract
 * English key, and the `tmlo` block under TMLO's own Dutch spelling. Reading
 * both is what lets one generator serve the e-Depot export and the TMLO
 * export endpoint.
 *
 * Kept apart from {@see MdtoSourceReader} because these answer "what does the
 * object literally store", while that answers "what does MDTO get". A value
 * only leaves here in a form the schema's type accepts, so a malformed date
 * cannot reach a document.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoValueReader {

	/**
	 * An `xsd:date`.
	 */
	public const XSD_DATE = '/^(\d{4}-\d{2}-\d{2})$/';

	/**
	 * The `xsd:gYear`, `xsd:gYearMonth` or `xsd:date` union the XSD declares.
	 */
	public const XSD_DATE_UNION = '/^(\d{4}(?:-\d{2}(?:-\d{2})?)?)$/';

	/**
	 * The date at the head of an ISO-8601 timestamp.
	 */
	public const XSD_DATE_PREFIX = '/^(\d{4}-\d{2}-\d{2})/';

	/**
	 * Read a declared value from the retention block, the TMLO block, or the schema.
	 *
	 * Three layers, nearest first: the object's own `retention` block is the
	 * per-object override, the `tmlo` block carries TMLO's spelling, and the
	 * schema's evaluated `x-openregister-archival` annotation is the default
	 * every row of that schema inherits. The annotation layer is what makes a
	 * fact declarable once instead of on every object; see
	 * `RetentionEvaluator::declaredFacts()`, which resolves it for the row.
	 *
	 * @param ObjectEntity $object The source object.
	 * @param string $abstractKey The English key, used on the retention block and the annotation.
	 * @param string $tmloKey The Dutch key on the TMLO block.
	 *
	 * @return mixed The declared value, or null when no layer carries one.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function declared(ObjectEntity $object, string $abstractKey, string $tmloKey): mixed {
		$retention = ($object->getRetention() ?? []);
		if (is_array($retention) === true && isset($retention[$abstractKey]) === true) {
			return $retention[$abstractKey];
		}

		$fromTmlo = $this->valueAt(value: $object->getTmlo(), key: $tmloKey);
		if ($fromTmlo !== null) {
			return $fromTmlo;
		}

		return $this->valueAt(value: $this->valueAt(value: $retention, key: 'annotation'), key: $abstractKey);
	}//end declared()

	/**
	 * A scalar read back as a non-empty string.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The string, or null when it is absent or empty.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function text(mixed $value): ?string {
		if (is_scalar($value) === false || (string)$value === '') {
			return null;
		}

		return (string)$value;
	}//end text()

	/**
	 * Read a value at a key, when the container is an array.
	 *
	 * @param mixed $value The container, which need not be an array.
	 * @param string $key The key to read.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function valueAt(mixed $value, string $key): mixed {
		if (is_array($value) === false) {
			return null;
		}

		return ($value[$key] ?? null);
	}//end at()

	/**
	 * Read a non-empty string at a key of an array value.
	 *
	 * @param mixed $value The container, which need not be an array.
	 * @param string $key The key to read.
	 *
	 * @return string|null The string, or null when it is absent or empty.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function textAt(mixed $value, string $key): ?string {
		return $this->text(value: $this->valueAt(value: $value, key: $key));
	}//end textAt()

	/**
	 * The begripLabel of a declared value, which may be a bare string.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The label, or null when there is none.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public function label(mixed $value): ?string {
		return ($this->text(value: $value) ?? $this->textAt(value: $value, key: 'label') ?? $this->textAt(value: $value, key: 'type'));
	}//end label()

	/**
	 * Return a value's first capture group when it matches, else null.
	 *
	 * One place where a stored value is checked against the lexical form its
	 * XSD type demands.
	 *
	 * @param mixed $value The stored value.
	 * @param string $pattern The pattern, whose first group is the result.
	 *
	 * @return string|null The matched text, or null when it does not match.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function matching(mixed $value, string $pattern): ?string {
		$text = $this->text(value: $value);
		if ($text !== null && preg_match($pattern, $text, $matches) === 1) {
			return $matches[1];
		}

		return null;
	}//end matching()
}//end class
