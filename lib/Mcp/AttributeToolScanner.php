<?php

/**
 * Reflection scanner for `#[McpTool]`-attributed service methods (ADR-063 chain 3/3).
 *
 * Given an app id and a list of that app's own declared scannable service
 * classes ({@see \OCA\OpenRegister\Mcp\IMcpScannableServices}), reflects each
 * class's PUBLIC methods for the `#[McpTool]` attribute and builds one tool
 * descriptor per attributed method — id `{appId}.{toolName}`, `inputSchema`
 * inferred from parameter type hints + docblock `@param` tags,
 * `outputSchema` best-effort from the return type + `@return`.
 *
 * Pure reflection: this class has no DI/container dependency and does not
 * instantiate the scanned classes — it only builds catalog-shaped
 * descriptors (plus `class`/`method`/`paramNames` invocation metadata) that
 * a caller (Application.php's MCP discovery) uses to instantiate the owning
 * service and wrap the result in {@see \OCA\OpenRegister\Mcp\BuiltIn\AttributeToolProvider}.
 *
 * Non-public, static, and abstract attributed methods are ignored with a
 * logged warning — a tool must be a directly callable instance method
 * (REQ-ATTR-001's "honoured only on public methods").
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
 *   (Requirement: REQ-ATTR-001 — The #[McpTool] service-method attribute)
 * @spec openspec/specs/ai-mcp/spec.md
 *   (Requirement: REQ-ATTR-002 — Reflection scanner registers attributed tools in the same catalog)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Service\Mcp\McpAnnotationValidator;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * AttributeToolScanner
 *
 * Reflects `#[McpTool]`-attributed public methods on an app's declared
 * scannable service classes and builds catalog-shaped tool descriptors.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 */
final class AttributeToolScanner {
	/**
	 * Scan every declared scannable class for one app and return every
	 * attributed-method descriptor found across all of them.
	 *
	 * @param string $appId The owning app id (id prefix of every emitted tool id).
	 * @param list<string> $classNames Candidate scannable service FQCNs (see {@see IMcpScannableServices}).
	 * @param LoggerInterface $logger PSR logger (malformed classes/methods are logged, not thrown).
	 *
	 * @return list<array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, class: class-string, method: string, paramNames: list<string>}>
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-002 — Reflection scanner registers attributed tools in the same catalog)
	 */
	public function scanClasses(string $appId, array $classNames, LoggerInterface $logger): array {
		$descriptors = [];

		foreach ($classNames as $className) {
			if (is_string($className) === false || $className === '') {
				continue;
			}

			foreach ($this->scanClass(appId: $appId, className: $className, logger: $logger) as $descriptor) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end scanClasses()

	/**
	 * Scan a single class for `#[McpTool]`-attributed public methods.
	 *
	 * @param string $appId The owning app id.
	 * @param string $className The FQCN to reflect.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return list<array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, class: class-string, method: string, paramNames: list<string>}>
	 */
	public function scanClass(string $appId, string $className, LoggerInterface $logger): array {
		if (class_exists($className) === false) {
			$logger->warning(
				'[AttributeToolScanner] Scannable class does not exist',
				['appId' => $appId, 'class' => $className]
			);
			return [];
		}

		try {
			$reflectionClass = new ReflectionClass($className);
		} catch (Throwable $e) {
			$logger->warning(
				'[AttributeToolScanner] Could not reflect scannable class',
				['appId' => $appId, 'class' => $className, 'error' => $e->getMessage()]
			);
			return [];
		}

		$descriptors = [];

		foreach ($reflectionClass->getMethods() as $method) {
			$attributes = $method->getAttributes(McpTool::class);
			if ($attributes === []) {
				continue;
			}

			if ($method->isPublic() === false) {
				$logger->warning(
					'[AttributeToolScanner] #[McpTool] on a non-public method is ignored',
					['appId' => $appId, 'class' => $className, 'method' => $method->getName()]
				);
				continue;
			}

			if ($method->isStatic() === true || $method->isAbstract() === true) {
				$logger->warning(
					'[AttributeToolScanner] #[McpTool] on a static or abstract method is ignored',
					['appId' => $appId, 'class' => $className, 'method' => $method->getName()]
				);
				continue;
			}

			try {
				$attributeInstance = $attributes[0]->newInstance();
			} catch (Throwable $e) {
				$logger->warning(
					'[AttributeToolScanner] Could not instantiate #[McpTool] attribute',
					[
						'appId' => $appId,
						'class' => $className,
						'method' => $method->getName(),
						'error' => $e->getMessage(),
					]
				);
				continue;
			}

			if ($this->hasUnknownScope(attribute: $attributeInstance) === true) {
				$logger->warning(
					'[AttributeToolScanner] #[McpTool] declares an unrecognised `scope`; tool skipped',
					[
						'appId' => $appId,
						'class' => $className,
						'method' => $method->getName(),
						'scope' => $attributeInstance->scope,
						'allowedScopes' => McpAnnotationValidator::SCOPES,
					]
				);
				continue;
			}

			$descriptors[] = $this->buildDescriptor(
				appId: $appId,
				className: $className,
				method: $method,
				attribute: $attributeInstance
			);
		}//end foreach

		return $descriptors;
	}//end scanClass()

	/**
	 * Build one descriptor for an attributed method.
	 *
	 * @param string $appId The owning app id.
	 * @param string $className The declaring class FQCN.
	 * @param ReflectionMethod $method The attributed method.
	 * @param McpTool $attribute The resolved attribute instance.
	 *
	 * @return array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, class: class-string, method: string, paramNames: list<string>,
	 *         readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, scope?: string}
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-001 — Attribute with defaults infers name and description)
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-005 — Attribute-declared hints/scope reach both MCP surfaces)
	 */
	private function buildDescriptor(string $appId, string $className, ReflectionMethod $method, McpTool $attribute): array {
		$docLines = $this->docblockLines(docComment: $method->getDocComment());
		$docParams = $this->parseParams(docLines: $docLines);
		$docReturn = $this->parseReturn(docLines: $docLines);

		$name = $attribute->name ?? $method->getName();
		$description = $attribute->description ?? $this->parseSummary(docLines: $docLines);
		if ($description === null || $description === '') {
			$description = sprintf('%s::%s (no description declared).', $className, $method->getName());
		}

		$descriptor = [
			'id' => $appId . '.' . $name,
			'name' => $name,
			'description' => $description,
			'inputSchema' => $this->inferInputSchema(method: $method, docParams: $docParams),
			'class' => $className,
			'method' => $method->getName(),
			'paramNames' => array_map(
				callback: static fn (ReflectionParameter $param): string => $param->getName(),
				array: $method->getParameters()
			),
		];

		$outputSchema = $this->inferOutputSchema(method: $method, docReturn: $docReturn);
		if ($outputSchema !== null) {
			$descriptor['outputSchema'] = $outputSchema;
		}

		// MCP 2025-11-25 annotation hints + advisory `scope` (REQ-ATTR-005).
		// Forwarded additively — a key is present ONLY when the author set
		// it on the attribute; nothing here ever infers or fabricates a
		// value (an invented `readOnlyHint: true` on an unannotated write
		// tool would be a dangerous lie). Both are advisory UX metadata
		// only — OpenRegister RBAC and the owning method's own
		// authorization remain the sole authoritative invoke-time gate.
		foreach (McpAnnotationValidator::HINT_KEYS as $hintKey) {
			$hintValue = $this->hintValue(attribute: $attribute, hintKey: $hintKey);
			if ($hintValue !== null) {
				$descriptor[$hintKey] = $hintValue;
			}
		}

		if ($attribute->scope !== null) {
			$descriptor['scope'] = $attribute->scope;
		}

		return ($descriptor + $this->taxonomyOf(attribute: $attribute));
	}//end buildDescriptor()

	/**
	 * The grant-matrix taxonomy an attribute declared, or an empty array.
	 *
	 * Forwarded on the same additive terms as the annotation hints: a key is
	 * present ONLY when the author declared it, never inferred from the method
	 * name. `ToolRegistryFacade::describeTools()` makes the same choice
	 * downstream, and for the same reason — an inferred subject is
	 * indistinguishable from a declared one, so a consumer handed one cannot
	 * know whether to trust it.
	 *
	 * ⚠️ The consequence is that an OMISSION HAS NO SYMPTOM: the tool simply
	 * arrives at the grant matrix ungroupable, with nothing failing anywhere.
	 * Apps are expected to pin this with a test, the way hermiq's provider
	 * does.
	 *
	 * Its own method rather than a loop inside `buildDescriptor()`, which was
	 * already at the cyclomatic-complexity ceiling — two more branches there
	 * tipped it over.
	 *
	 * @param McpTool $attribute The resolved attribute instance.
	 *
	 * @return array<string, string> The declared taxonomy keys only.
	 */
	private function taxonomyOf(McpTool $attribute): array {
		$taxonomy = [];

		if ($attribute->subject !== null && trim($attribute->subject) !== '') {
			$taxonomy['subject'] = $attribute->subject;
		}

		if ($attribute->action !== null && trim($attribute->action) !== '') {
			$taxonomy['action'] = $attribute->action;
		}

		return $taxonomy;

	}//end taxonomyOf()

	/**
	 * Read one boolean hint property off the attribute by its
	 * {@see McpAnnotationValidator::HINT_KEYS} name.
	 *
	 * @param McpTool $attribute The resolved attribute instance.
	 * @param string $hintKey One of `readOnlyHint`/`destructiveHint`/`idempotentHint`.
	 *
	 * @return bool|null The declared hint value, or null when the author did not set it.
	 */
	private function hintValue(McpTool $attribute, string $hintKey): ?bool {
		return match ($hintKey) {
			'readOnlyHint' => $attribute->readOnlyHint,
			'destructiveHint' => $attribute->destructiveHint,
			'idempotentHint' => $attribute->idempotentHint,
			default => null,
		};
	}//end hintValue()

	/**
	 * True when the attribute declares a non-null `scope` that is not one
	 * of {@see McpAnnotationValidator::SCOPES} (REQ-ATTR-005 — an unknown
	 * scope is rejected at scan time, mirroring how this scanner already
	 * fail-softs on other malformed attribute input).
	 *
	 * @param McpTool $attribute The resolved attribute instance.
	 *
	 * @return bool True when `scope` is set to an unrecognised value.
	 */
	private function hasUnknownScope(McpTool $attribute): bool {
		if ($attribute->scope === null) {
			return false;
		}

		return in_array($attribute->scope, McpAnnotationValidator::SCOPES, true) === false;
	}//end hasUnknownScope()

	/**
	 * Infer the `inputSchema` from the method's parameter type hints and
	 * docblock `@param` tags. Parameters without a default value are
	 * `required` (REQ-ATTR-001).
	 *
	 * @param ReflectionMethod $method The attributed method.
	 * @param array<string, string> $docParams Parameter name → docblock description.
	 *
	 * @return array<string, mixed> JSON-Schema-shaped input schema.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-001 — inputSchema is inferred from type hints and @param)
	 */
	private function inferInputSchema(ReflectionMethod $method, array $docParams): array {
		$properties = [];
		$required = [];

		foreach ($method->getParameters() as $param) {
			$property = $this->paramSchema(param: $param);

			$paramDescription = ($docParams[$param->getName()] ?? '');
			if ($paramDescription !== '') {
				$property['description'] = $paramDescription;
			}

			$properties[$param->getName()] = $property;

			if ($param->isOptional() === false) {
				$required[] = $param->getName();
			}
		}

		$schema = [
			'type' => 'object',
			'properties' => $properties,
		];

		if ($required !== []) {
			$schema['required'] = $required;
		}

		return $schema;
	}//end inferInputSchema()

	/**
	 * JSON-Schema-shaped property for a single parameter, from its PHP type hint.
	 *
	 * @param ReflectionParameter $param The parameter to describe.
	 *
	 * @return array<string, mixed> The property schema (possibly empty when the type cannot be inferred).
	 */
	private function paramSchema(ReflectionParameter $param): array {
		$jsonType = $this->mapReflectionType(type: $param->getType());
		if ($jsonType === null) {
			return [];
		}

		return ['type' => $jsonType];
	}//end paramSchema()

	/**
	 * Best-effort `outputSchema` from the return type + `@return` tag.
	 * Omitted (null) for untyped/void/mixed/bare-array returns per
	 * design.md's "best-effort" contract.
	 *
	 * @param ReflectionMethod $method The attributed method.
	 * @param string|null $docReturn The raw `@return` type token, when declared.
	 *
	 * @return array<string, mixed>|null The output schema, or null when not inferable.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-001 — outputSchema inferred from return type / @return where available)
	 */
	private function inferOutputSchema(ReflectionMethod $method, ?string $docReturn): ?array {
		unset($docReturn);

		$type = $method->getReturnType();
		if (($type instanceof ReflectionNamedType) === false) {
			return null;
		}

		$jsonType = $this->mapNamedType(type: $type);
		if ($jsonType === null || $jsonType === 'array') {
			// Untyped array / mixed / void — too little information to be
			// useful; deliberately omitted (design.md "best-effort").
			return null;
		}

		if ($type->allowsNull() === true) {
			return ['type' => [$jsonType, 'null']];
		}

		return ['type' => $jsonType];
	}//end inferOutputSchema()

	/**
	 * Map a (possibly nullable/union) reflection type to a JSON-Schema type.
	 *
	 * @param ReflectionType|null $type The parameter/return reflection type, or null when untyped.
	 *
	 * @return string|list<string>|null A JSON-Schema type, a `[type, 'null']` pair, or null when unconstrained.
	 */
	private function mapReflectionType(?ReflectionType $type): string|array|null {
		if ($type === null) {
			return null;
		}

		if ($type instanceof ReflectionUnionType) {
			$types = [];
			$nullable = false;

			foreach ($type->getTypes() as $member) {
				if (($member instanceof ReflectionNamedType) === false) {
					continue;
				}

				if ($member->getName() === 'null') {
					$nullable = true;
					continue;
				}

				$mapped = $this->mapNamedType(type: $member);
				if ($mapped !== null) {
					$types[] = $mapped;
				}
			}

			$types = array_values(array_unique($types));
			if ($nullable === true) {
				$types[] = 'null';
			}

			if (count($types) === 0) {
				return null;
			}

			if (count($types) === 1) {
				return $types[0];
			}

			return $types;
		}//end if

		if ($type instanceof ReflectionNamedType) {
			$jsonType = $this->mapNamedType(type: $type);
			if ($jsonType === null) {
				return null;
			}

			if ($type->allowsNull() === true) {
				return [$jsonType, 'null'];
			}

			return $jsonType;
		}

		// ReflectionIntersectionType or other exotic forms — unconstrained.
		return null;
	}//end mapReflectionType()

	/**
	 * Map one PHP named type to its JSON-Schema type, or null when the type
	 * carries no useful schema information (`mixed`, `void`, `never`).
	 *
	 * @param ReflectionNamedType $type The named type to map.
	 *
	 * @return string|null The JSON-Schema type, or null when unconstrained.
	 */
	private function mapNamedType(ReflectionNamedType $type): ?string {
		if ($type->isBuiltin() === false) {
			// A class/interface/enum type hint — best-effort "object".
			return 'object';
		}

		return match ($type->getName()) {
			'int' => 'integer',
			'float' => 'number',
			'string' => 'string',
			'bool' => 'boolean',
			'array', 'iterable' => 'array',
			default => null,
		};
	}//end mapNamedType()

	/**
	 * Split a docblock into trimmed, marker-stripped lines.
	 *
	 * @param string|false $docComment The raw `ReflectionMethod::getDocComment()` value.
	 *
	 * @return list<string> Trimmed lines, in order, trailing blank lines removed.
	 */
	private function docblockLines(string|false $docComment): array {
		if ($docComment === false || trim($docComment) === '') {
			return [];
		}

		$rawLines = preg_split('/\r\n|\r|\n/', $docComment);
		if ($rawLines === false) {
			return [];
		}

		$lines = [];
		foreach ($rawLines as $rawLine) {
			$line = trim($rawLine);
			$line = preg_replace('#^/\*\*#', '', $line) ?? $line;
			$line = preg_replace('#\*/$#', '', $line) ?? $line;
			$line = ltrim($line, '* ');
			$lines[] = rtrim($line);
		}

		while ($lines !== [] && end($lines) === '') {
			array_pop($lines);
		}

		return $lines;
	}//end docblockLines()

	/**
	 * The docblock summary — the first non-empty, non-tag line.
	 *
	 * @param list<string> $docLines Lines from {@see docblockLines()}.
	 *
	 * @return string|null The summary line, or null when absent.
	 */
	private function parseSummary(array $docLines): ?string {
		foreach ($docLines as $line) {
			if ($line === '') {
				continue;
			}

			if (str_starts_with($line, '@') === true) {
				return null;
			}

			return $line;
		}

		return null;
	}//end parseSummary()

	/**
	 * Every `@param $name description` tag, keyed by parameter name.
	 *
	 * @param list<string> $docLines Lines from {@see docblockLines()}.
	 *
	 * @return array<string, string> Parameter name → description (may be empty string).
	 */
	private function parseParams(array $docLines): array {
		$params = [];

		foreach ($docLines as $line) {
			if (preg_match('/^@param\s+\S+\s+\$(\w+)(?:\s+(.*))?$/', $line, $matches) === 1) {
				$params[$matches[1]] = trim($matches[2] ?? '');
			}
		}

		return $params;
	}//end parseParams()

	/**
	 * The raw `@return` type token, when declared.
	 *
	 * @param list<string> $docLines Lines from {@see docblockLines()}.
	 *
	 * @return string|null The `@return` type token, or null when absent.
	 */
	private function parseReturn(array $docLines): ?string {
		foreach ($docLines as $line) {
			if (preg_match('/^@return\s+(\S+)/', $line, $matches) === 1) {
				return $matches[1];
			}
		}

		return null;
	}//end parseReturn()
}//end class
