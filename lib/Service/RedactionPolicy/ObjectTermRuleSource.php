<?php

/**
 * OpenRegister ObjectTermRuleSource: the termRule objects of the redaction-policy register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RedactionPolicy;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Reads the termRule objects through the object service, so the caller's
 * organisation and read rights apply as on any other object read.
 *
 * A failed read is not answered with an empty list: an empty policy would
 * release every term on the always list. The exception reaches the caller,
 * and PolicyIndexCache keeps no index, so the next use reads again.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
class ObjectTermRuleSource implements TermRuleSourceInterface {

	/**
	 * Slug of the redaction-policy register.
	 *
	 * @var string
	 */
	public const REGISTER = 'redaction-policy';

	/**
	 * Slug of the termRule schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'termRule';

	/**
	 * The objects read per page.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService The object read path (RBAC and tenant scoped).
	 */
	public function __construct(private readonly ObjectOperationsInterface $objectService) {
	}//end __construct()

	/**
	 * Every termRule object of the caller's organisation, page by page, as arrays with their `@self`.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function termRuleObjects(): array {
		$objects = [];
		$offset  = 0;
		do {
			$page = $this->objectService->findAll(
				[
					'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA],
					'limit'   => self::PAGE,
					'offset'  => $offset,
				]
			);
			foreach ($page as $object) {
				$row = self::asArray(object: $object);
				if ($row !== null) {
					$objects[] = $row;
				}
			}

			$offset  += self::PAGE;
			$fullPage = count($page) === self::PAGE;
		} while ($fullPage === true);

		return $objects;
	}//end termRuleObjects()

	/**
	 * An object as the array the index reads, or null for anything else.
	 *
	 * @param mixed $object An entry of a findAll() page.
	 *
	 * @return array<string, mixed>|null The object with its `@self`.
	 */
	private static function asArray(mixed $object): ?array {
		if ($object instanceof ObjectEntity) {
			return $object->jsonSerialize();
		}

		if (is_array($object) === true) {
			return $object;
		}

		return null;
	}//end asArray()
}//end class
