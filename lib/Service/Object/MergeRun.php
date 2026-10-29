<?php

/**
 * MergeRun
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One merge in progress: the two objects, the target's register and schema, the
 * caller's merge options, and the report the steps fill in.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MergeRun {
	/**
	 * The report a merge starts from.
	 *
	 * @var array<string, mixed>
	 */
	public const EMPTY_REPORT = [
		'success' => false,
		'sourceObject' => null,
		'targetObject' => null,
		'mergedObject' => null,
		'actions' => [
			'properties' => [],
			'files' => [],
			'relations' => [],
			'references' => [],
		],
		'statistics' => [
			'propertiesChanged' => 0,
			'filesTransferred' => 0,
			'filesDeleted' => 0,
			'relationsTransferred' => 0,
			'relationsDropped' => 0,
			'referencesUpdated' => 0,
		],
		'warnings' => [],
		'errors' => [],
	];

	/**
	 * The object merged away.
	 *
	 * @var ObjectEntity
	 */
	public readonly ObjectEntity $source;

	/**
	 * The object merged into.
	 *
	 * @var ObjectEntity
	 */
	public readonly ObjectEntity $target;

	/**
	 * The target's register.
	 *
	 * @var mixed
	 */
	public readonly mixed $register;

	/**
	 * The target's schema.
	 *
	 * @var mixed
	 */
	public readonly mixed $schema;

	/**
	 * The merge report the steps fill in.
	 *
	 * @var array<string, mixed>
	 */
	public array $report = self::EMPTY_REPORT;

	/**
	 * Start a merge.
	 *
	 * @param array{source: ObjectEntity, target: ObjectEntity, register: mixed, schema: mixed} $pair    The objects
	 * @param array<string, mixed>                                                               $options The merge data:
	 *                                                                                                    object, fileAction,
	 *                                                                                                    relationAction,
	 *                                                                                                    referenceAction
	 */
	public function __construct(array $pair, public readonly array $options) {
		$this->source   = $pair['source'];
		$this->target   = $pair['target'];
		$this->register = $pair['register'];
		$this->schema   = $pair['schema'];
	}//end __construct()

	/**
	 * The merge option, or its default.
	 *
	 * @param string $name    The option
	 * @param mixed  $default The default
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function option(string $name, mixed $default): mixed {
		return $this->options[$name] ?? $default;
	}//end option()
}//end class
