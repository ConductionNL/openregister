<?php

/**
 * OpenRegister StreamedTextResponse
 *
 * A response whose body is written chunk by chunk from a generator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Streams text chunks, so a large body is never held in memory.
 *
 * Nextcloud 33 ships StreamTraversableResponse for this; the app still runs on
 * 32, so it carries its own.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */
class StreamedTextResponse extends Response implements ICallbackResponse {

	/**
	 * Constructor.
	 *
	 * @param iterable<string>      $chunks  The body, chunk by chunk.
	 * @param array<string, string> $headers The headers.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function __construct(
		private readonly iterable $chunks,
		array $headers = [],
	) {
		parent::__construct();
		foreach ($headers as $name => $value) {
			$this->addHeader($name, $value);
		}
	}//end __construct()

	/**
	 * Write the chunks to the output as they are generated.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
	 */
	public function callback(IOutput $output): void {
		foreach ($this->chunks as $chunk) {
			$output->setOutput($chunk);
		}
	}//end callback()
}//end class
