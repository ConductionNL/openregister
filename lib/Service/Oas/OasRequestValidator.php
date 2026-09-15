<?php

/**
 * OasRequestValidator — runtime request-body validation against an OAS schema.
 *
 * Wraps `opis/json-schema` to validate decoded request bodies against the
 * OAS operation's `requestBody.content."application/json".schema`. The
 * primitive is pure-PHP (no NC framework dep) so it can be unit-tested in
 * isolation; the NC middleware that wires it into `before-controller` is
 * a thin adapter on top.
 *
 * Validation errors are emitted in a flat list of `{ path, message }`
 * tuples ready to be wrapped by `ProblemDetailsBuilder::validationFailed`.
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
 * @spec openspec/specs/oas-validation/spec.md "Request/Response Validation Against OAS Schema"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Validates a request body against a JSON-Schema.
 */
class OasRequestValidator {
	/**
	 * Validate `$body` against `$schema`. Returns the list of errors —
	 * empty array on success. Each error is `{ path, message }`.
	 *
	 * @param mixed $body The decoded request body (array / scalar / null).
	 * @param array $schema The JSON-Schema to validate against (decoded).
	 *
	 * @return array<int, array{path: string, message: string}>
	 *
	 * @spec openspec/specs/oas-validation/spec.md#requirement-request-validation-against-oas-schema
	 */
	public function validate(mixed $body, array $schema): array {
		// The opis/json-schema library operates on object-shaped values; convert
		// the schema + body via JSON round-trip so we get the right
		// PHP shape (stdClass for objects, array for lists).
		$schemaJson = (string)json_encode($this->bindLocalDynamicRefs(schema: $schema));
		$schemaObj = json_decode($schemaJson);
		$bodyJson = (string)json_encode($body);
		$bodyObj = json_decode($bodyJson);

		// Validation MUST NOT write into the data it judges. opis applies
		// `default` values by default, and a default injected inside an
		// `if`/`then` branch is then refused by `unevaluatedProperties` as a
		// property the caller never sent. The OAS 3.1 meta-schema does exactly
		// that with a query parameter's `allowEmptyValue`.
		$validator = new Validator();
		$validator->parser()->setOption('allowDefaults', false);
		$result = $validator->validate(data: $bodyObj, schema: $schemaObj);
		if ($result->isValid() === true) {
			return [];
		}

		$errors = [];
		$error = $result->error();
		if ($error === null) {
			return [];
		}

		$this->collectErrors(error: $error, errors: $errors);
		return $errors;
	}//end validate()

	/**
	 * Test whether `$body` validates against `$schema`.
	 *
	 * @param mixed $body The decoded request body.
	 * @param array $schema The JSON-Schema.
	 *
	 * @return bool
	 */
	public function isValid(mixed $body, array $schema): bool {
		return ($this->validate(body: $body, schema: $schema) === []);
	}//end isValid()

	/**
	 * Recursively walk the opis error tree and flatten into the
	 * `{ path, message }` shape consumers expect.
	 *
	 * @param mixed $error The opis ValidationError or sub-error.
	 * @param array $errors The accumulator (mutated by reference).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Defensive guards against opis
	 *                                               ValidationError shape variability
	 *                                               across versions; each method_exists
	 *                                               branch is one independent fallback.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same: each fallback is one branch.
	 */
	private function collectErrors(mixed $error, array &$errors): void {
		if (is_object($error) === false) {
			return;
		}

		$path = '';
		if (method_exists($error, 'data') === true) {
			$data = $error->data();
			if (is_object($data) === true && method_exists($data, 'fullPath') === true) {
				$path = '/' . implode('/', (array)$data->fullPath());
			}
		}

		$message = '';
		if (method_exists($error, 'keyword') === true) {
			$message = (string)$error->keyword();
		}

		// `message()` is a TEMPLATE ("The required properties ({missing}) are
		// missing"); the formatter fills its placeholders from the error's args.
		// Reporting the raw template told a reader which rule failed but never
		// which property, type or keyword it failed on.
		if ($error instanceof ValidationError === true) {
			$message = (new ErrorFormatter())->formatErrorMessage(error: $error);
		}

		$errorPath = '/';
		if ($path !== '') {
			$errorPath = $path;
		}

		$errorMessage = 'value does not validate';
		if ($message !== '') {
			$errorMessage = $message;
		}

		$errors[] = [
			'path' => $errorPath,
			'message' => $errorMessage,
		];

		if (method_exists($error, 'subErrors') === true) {
			foreach ((array)$error->subErrors() as $sub) {
				$this->collectErrors(error: $sub, errors: $errors);
			}
		}

	}//end collectErrors()

	/**
	 * Bind each `$dynamicRef: "#name"` to the `$dynamicAnchor` it names.
	 *
	 * The opis/json-schema 2.x library resolves `$dynamicRef` through its
	 * `$recursiveRef` machinery, which only looks for the anchor on schema
	 * RESOURCE roots. The OpenAPI 3.1 meta-schema declares
	 * `$dynamicAnchor: meta` inside `$defs/schema`, not on a root, so opis
	 * bound every `{$dynamicRef: "#meta"}` to the document root and demanded
	 * `openapi` and `info` inside each parameter's schema.
	 *
	 * For a schema that is ONE resource (no nested `$id`), the dynamic scope
	 * holds nothing but that resource, so a JSON Schema 2020-12 validator
	 * resolves `#name` to the single place that resource declares
	 * `$dynamicAnchor: name`. Rewriting the reference to a plain `$ref` at
	 * that JSON pointer gives the same answer without depending on opis's
	 * dynamic scope. Anything that is not that unambiguous case (nested
	 * resources, an anchor declared twice or not at all) is left untouched.
	 *
	 * @param array $schema The decoded schema.
	 *
	 * @return array The schema with local dynamic references bound.
	 *
	 * @spec openspec/specs/oas-validation/spec.md#requirement-request-validation-against-oas-schema
	 */
	private function bindLocalDynamicRefs(array $schema): array {
		$anchors = [];
		$nestedIds = 0;
		$this->collectDynamicAnchors(node: $schema, pointer: '', anchors: $anchors, nestedIds: $nestedIds);
		if ($nestedIds > 0 || $anchors === []) {
			return $schema;
		}

		$targets = [];
		foreach ($anchors as $name => $pointers) {
			if (count($pointers) === 1) {
				$targets['#' . $name] = '#' . $pointers[0];
			}
		}

		return $this->rewriteDynamicRefs(node: $schema, targets: $targets);
	}//end bindLocalDynamicRefs()

	/**
	 * Record where each `$dynamicAnchor` is declared, and count nested `$id`s.
	 *
	 * @param mixed  $node      The schema node being walked.
	 * @param string $pointer   The JSON pointer of that node ('' is the root).
	 * @param array  $anchors   Anchor name => list of JSON pointers (by reference).
	 * @param int    $nestedIds Number of `$id`s below the root (by reference).
	 *
	 * @return void
	 */
	private function collectDynamicAnchors(mixed $node, string $pointer, array &$anchors, int &$nestedIds): void {
		if (is_array($node) === false) {
			return;
		}

		if ($pointer !== '' && isset($node['$id']) === true) {
			$nestedIds++;
		}

		if (isset($node['$dynamicAnchor']) === true && is_string($node['$dynamicAnchor']) === true) {
			$anchors[$node['$dynamicAnchor']][] = $pointer;
		}

		foreach ($node as $key => $child) {
			$segment = str_replace(['~', '/'], ['~0', '~1'], (string)$key);
			$this->collectDynamicAnchors(node: $child, pointer: $pointer . '/' . $segment, anchors: $anchors, nestedIds: $nestedIds);
		}
	}//end collectDynamicAnchors()

	/**
	 * Replace `$dynamicRef` values that have a bound target with a plain `$ref`.
	 *
	 * @param mixed $node    The schema node being rewritten.
	 * @param array $targets `#name` => `#/json/pointer`.
	 *
	 * @return mixed The rewritten node.
	 */
	private function rewriteDynamicRefs(mixed $node, array $targets): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		$ref = $node['$dynamicRef'] ?? null;
		if (is_string($ref) === true && isset($targets[$ref]) === true) {
			unset($node['$dynamicRef']);
			$node['$ref'] = $targets[$ref];
		}

		foreach ($node as $key => $child) {
			$node[$key] = $this->rewriteDynamicRefs(node: $child, targets: $targets);
		}

		return $node;
	}//end rewriteDynamicRefs()
}//end class
