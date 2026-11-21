<?php

/**
 * ConnectionReporterInterface: the part of ConnectionReporter that the settings controllers use.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/app-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Connection;

use OCA\OpenRegister\Kernel\Contracts\Compliance\ConnectionStatusReporterInterface;

/**
 * The methods of ConnectionReporter that callers outside its component use.
 * Callers depend on this interface, not on the class.
 *
 * The Compliance contract this interface extends declares report(); its
 * key is one of {@see ConnectionReporter::KEYS} and its status one of
 * {@see ConnectionReporter::STATUSES}.
 */
interface ConnectionReporterInterface extends ConnectionStatusReporterInterface {

	/**
	 * Ask integriq to resolve every connection whose config keys a save wrote.
	 *
	 * A save that names none of a connection's keys leaves that connection
	 * alone. Integriq reads the saved values itself and decides the status.
	 *
	 * @param array<int, string> $savedKeys The app-config keys the save wrote.
	 *
	 * @return array<int, string> The connection keys a refresh was sent for.
	 *
	 * @spec openspec/specs/app-connections/spec.md
	 */
	public function refreshFromSave(array $savedKeys): array;

	/**
	 * Report which LLM providers a settings save left chosen.
	 *
	 * With no provider nothing answers: chat throws a 503. So an empty choice is
	 * `unconfigured`, never `simulated`. One of the two is `limited`, both is
	 * `configured`. A save tests nothing, and the message says so
	 * (adopt-connection-registry design D2).
	 *
	 * @param mixed $chatProvider      The saved `chatProvider` value.
	 * @param mixed $embeddingProvider The saved `embeddingProvider` value.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/specs/app-connections/spec.md
	 */
	public function reportLlmProviders(mixed $chatProvider, mixed $embeddingProvider): bool;
}//end interface
