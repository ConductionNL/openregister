<?php

/**
 * Integriq event stubs for the unit test bootstraps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// Integriq's connection-registry events (adopt-connection-registry) and its
// opt-out decision event (opt-out-before-send). ConnectionReporter and
// OptOutAuthority send them by class-name string so OpenRegister stays
// installable without integriq. The stubs mirror the contract constructors
// verbatim and load only when the real classes are absent.
foreach (['ConnectionStatusReportedEvent', 'ConnectionRefreshRequestedEvent', 'OutboundSendDecisionRequestedEvent'] as $integriqStubEvent) {
	if (class_exists('\\OCA\\Integriq\\Event\\' . $integriqStubEvent) === false) {
		require_once __DIR__ . '/Integriq/Event/' . $integriqStubEvent . '.php';
	}
}
