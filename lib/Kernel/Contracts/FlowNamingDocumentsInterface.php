<?php
/**
 * FlowNamingDocumentsInterface: the flows as documents a rename preview reads for the old name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Hands the schema migration preview (Core) every flow as a plain document.
 *
 * The preview of a rename lists what still names the old property or slug
 * without rewriting it. The Processes area answers and is bound to this contract in
 * its own wiring, so the preview never names that area's classes.
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
interface FlowNamingDocumentsInterface {

	/**
	 * Every flow, as the part of it that can name a property or a schema slug.
	 *
	 * An unreadable source answers with what it could read, never throws.
	 *
	 * @return list<array{kind: string, id: mixed, name: string|null, content: array<mixed>}>
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
	 */
	public function namingDocuments(): array;
}//end interface
