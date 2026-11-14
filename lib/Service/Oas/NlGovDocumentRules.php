<?php

/**
 * The NLGov path, query and URI version rules, checked on a generated OpenAPI document.
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
 * Checks /core/no-trailing-slash, /core/path-segments-kebab-case,
 * /core/query-keys-camel-case and /core/uri-version, and writes each finding
 * to the report as a warning with code `nlgov_rule` and the rule's id.
 *
 * A finding of a rule the catalogue declares a deviation for says so, with
 * the reason, so it is never mistaken for an unknown failure or for a pass.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovDocumentRules {

	/**
	 * Constructor.
	 *
	 * @param OasValidationReport $report The report the findings go to.
	 */
	public function __construct(
		private readonly OasValidationReport $report,
	) {
	}//end __construct()

	/**
	 * Check a whole document.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function check(array $oas): void {
		$this->checkUriVersion(oas: $oas);

		$paths = ($oas['paths'] ?? null);
		if (is_array($paths) === false) {
			return;
		}

		foreach ($paths as $pathName => $pathItem) {
			$this->checkPathName(pathName: (string)$pathName);
			if (is_array($pathItem) === true) {
				$this->checkQueryKeys(oas: $oas, pathName: (string)$pathName, pathItem: $pathItem);
			}
		}
	}//end check()

	/**
	 * /core/uri-version: the first server URL ends in `/v{major}`.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	private function checkUriVersion(array $oas): void {
		$url = ($oas['servers'][0]['url'] ?? null);
		if (is_string($url) === false || preg_match('#/v\d+/?$#', $url) === 1) {
			return;
		}

		$this->finding(
			ruleId: '/core/uri-version',
			path: 'servers.0.url',
			message: sprintf('The server URL "%s" does not end in the major version (/v{major}).', $url)
		);
	}//end checkUriVersion()

	/**
	 * /core/no-trailing-slash and /core/path-segments-kebab-case for one path.
	 *
	 * @param string $pathName The path, for example `/objects/{register}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	private function checkPathName(string $pathName): void {
		if ($pathName !== '/' && str_ends_with($pathName, '/') === true) {
			$this->finding(
				ruleId: '/core/no-trailing-slash',
				path: 'paths.'.$pathName,
				message: sprintf('The path "%s" ends in a slash.', $pathName)
			);
		}

		foreach (explode('/', $pathName) as $segment) {
			if ($segment === '' || str_contains($segment, '{') === true) {
				continue;
			}

			if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment) !== 1) {
				$this->finding(
					ruleId: '/core/path-segments-kebab-case',
					path: 'paths.'.$pathName,
					message: sprintf('The path segment "%s" of "%s" is not kebab-case.', $segment, $pathName)
				);
			}
		}
	}//end checkPathName()

	/**
	 * /core/query-keys-camel-case for every query parameter of a path and its operations.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document, to resolve parameter references.
	 * @param string $pathName The path.
	 * @param array<string, mixed> $pathItem The path item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	private function checkQueryKeys(array $oas, string $pathName, array $pathItem): void {
		$parameters = ['paths.'.$pathName.'.parameters' => ($pathItem['parameters'] ?? [])];
		foreach ($pathItem as $method => $operation) {
			if (is_array($operation) === true && in_array(strtolower((string)$method), NlGovRuleCatalogue::ALLOWED_HTTP_METHODS, true) === true) {
				$parameters['paths.'.$pathName.'.'.$method.'.parameters'] = ($operation['parameters'] ?? []);
			}
		}

		foreach ($parameters as $location => $list) {
			if (is_array($list) === false) {
				continue;
			}

			foreach ($list as $index => $parameter) {
				$parameter = $this->resolveParameter(oas: $oas, parameter: $parameter);
				if (($parameter['in'] ?? null) === 'query' && is_string($parameter['name'] ?? null) === true) {
					$this->checkQueryKey(name: $parameter['name'], path: $location.'.'.$index);
				}
			}
		}
	}//end checkQueryKeys()

	/**
	 * Check one query key: camelCase passes, an underscore-prefixed camelCase key
	 * is the declared deviation, anything else fails.
	 *
	 * The part of a key before `[` is what is checked, so `_order[title]` reads as `_order`.
	 *
	 * @param string $name The query key.
	 * @param string $path Where the parameter is in the document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	private function checkQueryKey(string $name, string $path): void {
		$base = explode('[', $name, 2)[0];
		if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $base) === 1) {
			return;
		}

		$message = sprintf('The query key "%s" is not camelCase.', $name);
		if (preg_match('/^_[a-z][a-zA-Z0-9]*$/', $base) === 1) {
			$this->finding(ruleId: '/core/query-keys-camel-case', path: $path, message: $message);
			return;
		}

		$this->finding(ruleId: '/core/query-keys-camel-case', path: $path, message: $message, deviates: false);
	}//end checkQueryKey()

	/**
	 * A parameter, with a `#/components/parameters/...` reference followed once.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 * @param mixed $parameter The parameter or reference.
	 *
	 * @return array<string, mixed> The parameter, or an empty array when it does not resolve.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	private function resolveParameter(array $oas, mixed $parameter): array {
		if (is_array($parameter) === false) {
			return [];
		}

		$ref = ($parameter['$ref'] ?? null);
		if (is_string($ref) === false) {
			return $parameter;
		}

		$prefix = '#/components/parameters/';
		if (str_starts_with($ref, $prefix) === false) {
			return [];
		}

		$resolved = ($oas['components']['parameters'][substr($ref, strlen($prefix))] ?? null);
		if (is_array($resolved) === false) {
			return [];
		}

		return $resolved;
	}//end resolveParameter()

	/**
	 * Write one finding as a warning, naming the deviation when the rule declares one.
	 *
	 * @param string $ruleId The rule.
	 * @param string $path Where in the document.
	 * @param string $message What was found.
	 * @param boolean $deviates Whether the rule's declared deviation covers this finding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	private function finding(string $ruleId, string $path, string $message, bool $deviates = true): void {
		$reason = NlGovRuleCatalogue::deviation(ruleId: $ruleId);
		if ($deviates === true && $reason !== null) {
			$message = 'Declared deviation from NLGov '.$ruleId.': '.$message.' '.$reason;
		} else {
			$message = 'Violates NLGov '.$ruleId.': '.$message;
		}

		$this->report->addWarning(
			issue: new OasIssue(path: $path, message: $message, code: OasValidationReport::CODE_NLGOV_RULE, rule: $ruleId)
		);
	}//end finding()
}//end class
