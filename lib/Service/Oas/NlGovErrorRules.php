<?php

/**
 * The NLGov error handling rules, checked on the operations of a generated OpenAPI document.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Checks /core/error-handling/problem-details (every 4xx and 5xx response
 * declares `application/problem+json` with the `Error` component) and
 * /core/error-handling/invalid-input (every POST, PUT and PATCH declares a 400).
 *
 * A generated document repeats the same error responses on every operation,
 * so each rule writes ONE finding: its path is the first response that breaks
 * the rule, and its message gives the count and the first few paths. One
 * warning per response would put hundreds of identical lines in the log on
 * every generation.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovErrorRules {

	/**
	 * The media type of a problem details body (RFC 7807).
	 *
	 * @var string
	 */
	private const PROBLEM_JSON = 'application/problem+json';

	/**
	 * The component a problem details body references.
	 *
	 * @var string
	 */
	private const ERROR_REF = '#/components/schemas/Error';

	/**
	 * The methods whose body can be invalid input.
	 *
	 * @var list<string>
	 */
	private const BODY_METHODS = ['post', 'put', 'patch'];

	/**
	 * How many paths a finding's message names.
	 *
	 * @var integer
	 */
	private const EXAMPLES = 3;

	/**
	 * Constructor.
	 *
	 * @param NlGovFindings $findings Where the findings go.
	 */
	public function __construct(
		private readonly NlGovFindings $findings,
	) {
	}//end __construct()

	/**
	 * Check every operation of a document.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function check(array $oas): void {
		$notProblem = [];
		$noBadRequest = [];
		foreach ($this->operations(oas: $oas) as $path => [$method, $operation]) {
			$responses = self::listOf(value: ($operation['responses'] ?? null));
			foreach ($responses as $code => $response) {
				if (preg_match('/^[45]/', (string)$code) === 1
					&& $this->isProblemDetails(response: $this->resolve(oas: $oas, response: $response)) === false
				) {
					$notProblem[] = $path.'.responses.'.$code;
				}
			}

			if (in_array($method, self::BODY_METHODS, true) === true && isset($responses['400']) === false) {
				$noBadRequest[] = $path.'.responses';
			}
		}

		$this->report(
			ruleId: '/core/error-handling/problem-details',
			paths: $notProblem,
			what: 'error responses do not declare '.self::PROBLEM_JSON.' with the Error schema'
		);
		$this->report(ruleId: '/core/error-handling/invalid-input', paths: $noBadRequest, what: 'POST, PUT or PATCH operations declare no 400 response');
	}//end check()

	/**
	 * Every operation of the document, by its path, with its lower-case method.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}> The operations.
	 */
	private function operations(array $oas): array {
		$operations = [];
		foreach (self::listOf(value: ($oas['paths'] ?? null)) as $pathName => $pathItem) {
			foreach (self::listOf(value: $pathItem) as $method => $operation) {
				$method = strtolower((string)$method);
				if (is_array($operation) === true && in_array($method, NlGovRuleCatalogue::ALLOWED_HTTP_METHODS, true) === true) {
					$operations['paths.'.$pathName.'.'.$method] = [$method, $operation];
				}
			}
		}

		return $operations;
	}//end operations()

	/**
	 * A response, with a `#/components/responses/...` reference followed once.
	 *
	 * @param array<string, mixed> $oas      The OpenAPI document.
	 * @param mixed                $response The response or reference.
	 *
	 * @return array<string, mixed> The response, or an empty array when it does not resolve.
	 */
	private function resolve(array $oas, mixed $response): array {
		if (is_array($response) === false) {
			return [];
		}

		$ref = ($response['$ref'] ?? null);
		$prefix = '#/components/responses/';
		if (is_string($ref) === false) {
			return $response;
		}

		if (str_starts_with($ref, $prefix) === false) {
			return [];
		}

		$resolved = ($oas['components']['responses'][substr($ref, strlen($prefix))] ?? null);
		if (is_array($resolved) === false) {
			return [];
		}

		return $resolved;
	}//end resolve()

	/**
	 * Whether a response declares a problem details body.
	 *
	 * @param array<string, mixed> $response The response.
	 *
	 * @return boolean True when it has application/problem+json referencing the Error component.
	 */
	private function isProblemDetails(array $response): bool {
		return (($response['content'][self::PROBLEM_JSON]['schema']['$ref'] ?? null) === self::ERROR_REF);
	}//end isProblemDetails()

	/**
	 * Write one finding for a rule, when any path breaks it.
	 *
	 * @param string       $ruleId The rule.
	 * @param list<string> $paths  The paths that break it, in document order.
	 * @param string       $what   What they do wrong, after the count.
	 *
	 * @return void
	 */
	private function report(string $ruleId, array $paths, string $what): void {
		if ($paths === []) {
			return;
		}

		$examples = implode(', ', array_slice($paths, 0, self::EXAMPLES));
		if (count($paths) > self::EXAMPLES) {
			$examples .= ', ...';
		}

		$this->findings->add(ruleId: $ruleId, path: $paths[0], message: sprintf('%d %s (%s).', count($paths), $what, $examples));
	}//end report()

	/**
	 * The value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed> The array.
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listOf()
}//end class
