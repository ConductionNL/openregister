<?php

/**
 * IMcpScannableServices — per-app opt-in list of #[McpTool]-scannable classes.
 *
 * Reflecting every class of every installed app on every boot is too
 * expensive and fragile, so `#[McpTool]` discovery is opt-in: an app that
 * wants OpenRegister to scan its own service classes for `#[McpTool]`
 * methods registers an implementation of this interface under the alias key
 * `OCA\OpenRegister\Mcp\IMcpScannableServices::<appId>` (mirrors the existing
 * `OCA\OpenRegister\Mcp\IMcpToolProvider::<appId>` per-app discovery
 * convention — see {@see \OCA\OpenRegister\AppInfo\Application::buildMcpProviderCandidates()}).
 *
 * This is the PROVISIONAL resolution of design.md's "Discovery scope"
 * DEFERRED_QUESTION — the design lists a DI-declared scannable-service list
 * as the explicit/cheapest of two candidate mechanisms; this interface is
 * that mechanism. A future change may add the conventional-namespace-scan
 * alternative alongside it without breaking this contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-002 — Reflection scanner registers attributed tools in the same catalog)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

/**
 * IMcpScannableServices
 *
 * Contract for the per-app scannable-service declaration. An app registers
 * one implementation under `OCA\OpenRegister\Mcp\IMcpScannableServices::<appId>`
 * (a container alias, exactly like the per-app IMcpToolProvider convention).
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 */
interface IMcpScannableServices {
	/**
	 * The app's own service classes eligible for `#[McpTool]` reflection.
	 *
	 * @return list<class-string> Fully-qualified service class names owned by this app.
	 */
	public function getScannableServiceClasses(): array;
}//end interface
