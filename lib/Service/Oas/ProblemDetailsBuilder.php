<?php

/**
 * ProblemDetailsBuilder — RFC 7807 problem-json response shape builder.
 *
 * RFC 7807 (https://datatracker.ietf.org/doc/html/rfc7807) standardises
 * machine-readable error payloads for HTTP APIs. Every problem document
 * carries:
 *
 *   - `type`     a URI identifying the problem class (default `about:blank`)
 *   - `title`    short human-readable summary
 *   - `status`   the HTTP status code, copied into the body
 *   - `detail`   per-occurrence explanation
 *   - `instance` URI identifying this specific occurrence
 *
 * Plus arbitrary custom extensions (e.g. `errors[]`, `code`).
 *
 * Errors emitted from OR controllers go through this builder so every
 * HTTP error response carries the same machine-readable shape and the
 * `Content-Type: application/problem+json` header.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md "API-46 Problem Details (RFC 7807)"
 * @spec openspec/specs/oas-validation/spec.md "Error responses include problem details (API-46 / RFC 7807)"
 * @spec openspec/specs/oas-validation/spec.md "API-46 RFC 7807 — retrofit cross-ref"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Builds RFC 7807 problem-details response payloads.
 *
 * @spec openspec/specs/oas-validation/spec.md "Error responses include problem details (API-46 / RFC 7807)"
 * @spec openspec/specs/oas-validation/spec.md "API-46 RFC 7807 — retrofit cross-ref"
 */
class ProblemDetailsBuilder implements ProblemDetailsBuilderInterface {

	public const DEFAULT_TYPE = 'about:blank';

	/**
	 * Build a problem document from a stated problem.
	 *
	 * @param ProblemStatement $statement Status, title, description and extension fields.
	 *
	 * @return array<string, mixed> The problem-json payload.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-10
	 */
	public function build(ProblemStatement $statement): array {
		$description = ($statement->description ?? new ProblemDescription());

		$problem = [
			'type' => $this->problemType(description: $description),
			'title' => $statement->title,
			'status' => $statement->status,
		];

		if ($description->detail !== '') {
			$problem['detail'] = $description->detail;
		}

		if ($description->instance !== '') {
			$problem['instance'] = $description->instance;
		}

		return $this->withExtensions(problem: $problem, extensions: $statement->extensions);
	}//end build()

	/**
	 * The problem-class URI, `about:blank` when the description names none.
	 *
	 * @param ProblemDescription $description The description.
	 *
	 * @return string
	 */
	private function problemType(ProblemDescription $description): string {
		if ($description->type !== '') {
			return $description->type;
		}

		return self::DEFAULT_TYPE;
	}//end problemType()

	/**
	 * Append the extension fields to a problem document.
	 *
	 * Extensions never overwrite the standard fields — RFC 7807 says custom
	 * fields MUST NOT clash. Filter them.
	 *
	 * @param array<string, mixed> $problem    The standard fields.
	 * @param array                $extensions Free-form extension fields.
	 *
	 * @return array<string, mixed>
	 */
	private function withExtensions(array $problem, array $extensions): array {
		foreach ($extensions as $key => $value) {
			if (in_array($key, ['type', 'title', 'status', 'detail', 'instance'], true) === true) {
				continue;
			}

			$problem[$key] = $value;
		}

		return $problem;
	}//end withExtensions()

	/**
	 * Wrap a list of validation errors as a 422 problem document.
	 *
	 * @param array              $errors      List of validation errors (each free-form, typically `{path, message}`).
	 * @param ProblemDescription $description The optional explanation and instance URI ('' = omit); its type is ignored.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-10
	 */
	public function validationFailed(array $errors, ProblemDescription $description = new ProblemDescription()): array {
		return $this->build(
			statement: new ProblemStatement(
				status: 422,
				title: 'Validation failed',
				description: new ProblemDescription(detail: $description->detail, type: 'about:blank', instance: $description->instance),
				extensions: ['errors' => $errors]
			)
		);

	}//end validationFailed()

	/**
	 * Build a "not found" problem document (404).
	 *
	 * @param string $detail Human-readable explanation.
	 * @param string $instance Instance URI, '' = omit.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-10
	 */
	public function notFound(string $detail = '', string $instance = ''): array {
		return $this->build(
			statement: new ProblemStatement(
				status: 404,
				title: 'Not found',
				description: new ProblemDescription(detail: $detail, instance: $instance)
			)
		);

	}//end notFound()

	/**
	 * Build a "conflict" problem document (409 — e.g. lock conflict, ETag mismatch).
	 *
	 * @param string $detail Human-readable explanation.
	 * @param string $instance Instance URI, '' = omit.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-10
	 */
	public function conflict(string $detail = '', string $instance = ''): array {
		return $this->build(
			statement: new ProblemStatement(
				status: 409,
				title: 'Conflict',
				description: new ProblemDescription(detail: $detail, instance: $instance)
			)
		);

	}//end conflict()
}//end class
