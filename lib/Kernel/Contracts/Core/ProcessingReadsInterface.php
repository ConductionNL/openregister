<?php
/**
 * ProcessingReadsInterface: the processing-log registration of object reads, as the object read path asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The AVG processing-log registration of reads, as the object read path (Core) asks it.
 *
 * The read path registers every single read and every list or search read;
 * the area that keeps the read registration binds its answer in its own wiring.
 * Both registrations are fail-soft: a logging failure never breaks the read.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
 */
interface ProcessingReadsInterface {

	/**
	 * Register a read of one object.
	 *
	 * @param ObjectEntity $object The object that was read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
	 */
	public function registerProcessingRead(ObjectEntity $object): void;

	/**
	 * Register a list or search read, with the query parameters it was asked with.
	 *
	 * @param array<int|string, mixed> $objects The listed objects: ObjectEntity or rendered arrays.
	 * @param array<string, mixed>     $query   The query parameters of the list or search.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
	 */
	public function registerQueriedRead(array $objects, array $query): void;
}//end interface
