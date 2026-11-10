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
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
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
	 * The keys the MCP specification reserves for tool hints in a descriptor's
	 * `annotations` object. A client may auto-approve on them, so a free-form
	 * mark must not carry one: OpenRegister's own hint and grant checks decide
	 * what a tool is, and a mark is only a claim.
	 *
	 * @var list<string>
	 */
	public const RESERVED_ANNOTATION_KEYS = ['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint', 'title'];

	/**
	 * Scan every declared scannable class for one app and return every
	 * attributed-method descriptor found across all of them.
	 *
	 * @param McpLeafApp   $app        The owning app (its id prefixes every emitted tool id) and
	 *                                 its logger (malformed classes/methods are logged, not thrown).
	 * @param list<string> $classNames Candidate scannable service FQCNs (see {@see IMcpScannableServices}).
	 *
	 * @return list<array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, class: class-string, method: string, paramNames: list<string>}>
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-002 — Reflection scanner registers attributed tools in the same catalog)
	 */
	public function scanClasses(McpLeafApp $app, array $classNames): array {
		$descriptors = [];

		foreach ($classNames as $className) {
			if (is_string($className) === false || $className === '') {
				continue;
			}

			$subject = new ToolScanSubject(appId: $app->appId, className: $className, logger: $app->logger);
			foreach ($this->scanClass(subject: $subject) as $descriptor) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end scanClasses()

	/**
	 * Scan a single class for `#[McpTool]`-attributed public methods.
	 *
	 * @param ToolScanSubject $subject The owning app id, the FQCN to reflect and the logger.
	 *
	 * @return list<array{id: string, name: string, description: string, inputSchema: array,
	 *         outputSchema?: array, class: class-string, method: string, paramNames: list<string>}>
	 */
	public function scanClass(ToolScanSubject $subject): array {
		$reflectionClass = $this->reflect(subject: $subject);
		if ($reflectionClass === null) {
			return [];
		}

		$descriptors = [];
		foreach ($reflectionClass->getMethods() as $method) {
			$run       = new MethodScanRun(subject: $subject, method: $method);
			$attribute = $this->toolAttribute(run: $run);
			if ($attribute !== null) {
				$descriptors[] = $this->buildDescriptor(run: $run, attribute: $attribute);
			}
		}

		return $descriptors;
	}//end scanClass()

	/**
	 * Reflect the scanned class; a missing or unreflectable class is logged.
	 *
	 * @param ToolScanSubject $subject The class being scanned.
	 *
	 * @return ReflectionClass<object>|null The reflection, or null.
	 */
	private function reflect(ToolScanSubject $subject): ?ReflectionClass {
		if (class_exists($subject->className) === false) {
			$subject->warn(message: '[AttributeToolScanner] Scannable class does not exist');
			return null;
		}

		try {
			return new ReflectionClass($subject->className);
		} catch (Throwable $e) {
			$subject->warn(message: '[AttributeToolScanner] Could not reflect scannable class', context: ['error' => $e->getMessage()]);
			return null;
		}
	}//end reflect()

	/**
	 * The `#[McpTool]` attribute of a method, or null when the method is not a tool.
	 *
	 * A method is not a tool when it has no attribute, cannot be called directly,
	 * its attribute cannot be instantiated, or its attribute declares a value the
	 * scanner does not recognise; each of the last three is logged.
	 *
	 * @param MethodScanRun $run The class being scanned and the method.
	 *
	 * @return McpTool|null
	 */
	private function toolAttribute(MethodScanRun $run): ?McpTool {
		$reflected = $this->reflectedAttribute(run: $run);
		if ($reflected === null) {
			return null;
		}

		$attribute = $this->instantiate(run: $run, attribute: $reflected);
		if ($attribute === null || $this->isRecognised(run: $run, attribute: $attribute) === false) {
			return null;
		}

		return $attribute;
	}//end toolAttribute()

	/**
	 * The method's `#[McpTool]` attribute when the method may be a tool.
	 *
	 * @param MethodScanRun $run The class being scanned and the method.
	 *
	 * @return ReflectionAttribute<McpTool>|null The reflected attribute, or null.
	 */
	private function reflectedAttribute(MethodScanRun $run): ?ReflectionAttribute {
		$attributes = $run->method->getAttributes(McpTool::class);
		if ($attributes === [] || $this->isCallableTool(run: $run) === false) {
			return null;
		}

		return $attributes[0];
	}//end reflectedAttribute()

	/**
	 * Instantiate the attribute; a failure is logged.
	 *
	 * @param MethodScanRun                $run       The class being scanned and the attributed method.
	 * @param ReflectionAttribute<McpTool> $attribute The reflected attribute.
	 *
	 * @return McpTool|null The instance, or null.
	 */
	private function instantiate(MethodScanRun $run, ReflectionAttribute $attribute): ?McpTool {
		try {
			return $attribute->newInstance();
		} catch (Throwable $e) {
			$run->warn(message: '[AttributeToolScanner] Could not instantiate #[McpTool] attribute', context: ['error' => $e->getMessage()]);
			return null;
		}
	}//end instantiate()

	/**
	 * Whether every closed-vocabulary declaration is valid; logs the first one that is not.
	 *
	 * @param MethodScanRun $run       The class being scanned and the attributed method.
	 * @param McpTool       $attribute The attribute instance.
	 *
	 * @return bool
	 */
	private function isRecognised(MethodScanRun $run, McpTool $attribute): bool {
		$invalid = ToolDeclarationCheck::unrecognised(attribute: $attribute);
		if ($invalid === null) {
			return true;
		}

		$run->warn(
			message: sprintf('[AttributeToolScanner] #[McpTool] declares an unrecognised `%s`; tool skipped', $invalid['key']),
			context: [$invalid['key'] => $invalid['value'], 'allowed' => $invalid['allowed']]
		);
		return false;
	}//end isRecognised()

	/**
	 * Whether an attributed method is a public instance method; logs why when it is not.
	 *
	 * @param MethodScanRun $run The class being scanned and the attributed method.
	 *
	 * @return bool
	 */
	private function isCallableTool(MethodScanRun $run): bool {
		$method = $run->method;
		if ($method->isPublic() === false) {
			$run->warn(message: '[AttributeToolScanner] #[McpTool] on a non-public method is ignored');
			return false;
		}

		if ($method->isStatic() === true || $method->isAbstract() === true) {
			$run->warn(message: '[AttributeToolScanner] #[McpTool] on a static or abstract method is ignored');
			return false;
		}

		return true;
	}//end isCallableTool()

	/**
	 * Build one descriptor for an attributed method: the inferred core, the
	 * output schema when inferable, then the author-declared extras.
	 *
	 * @param MethodScanRun $run       The owning app, the declaring class and the attributed method.
	 * @param McpTool       $attribute The resolved attribute instance.
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
	private function buildDescriptor(MethodScanRun $run, McpTool $attribute): array {
		$method   = $run->method;
		$docLines = ToolDocblock::lines(docComment: $method->getDocComment());
		$name     = $attribute->name ?? $method->getName();

		$descriptor = [
			'id' => $run->subject->appId . '.' . $name,
			'name' => $name,
			'description' => $this->describe(run: $run, attribute: $attribute),
			'inputSchema' => $this->inferInputSchema(method: $method, docParams: ToolDocblock::params(docLines: $docLines)),
			'class' => $run->subject->className,
			'method' => $method->getName(),
			'paramNames' => $this->paramNames(method: $method),
		];

		return ($descriptor + $this->outputSchemaOf(method: $method) + ToolDescriptorExtras::of(attribute: $attribute, method: $method));
	}//end buildDescriptor()

	/**
	 * The declared description, else the docblock summary, else a placeholder.
	 *
	 * @param MethodScanRun $run       The declaring class and the attributed method.
	 * @param McpTool       $attribute The attribute instance.
	 *
	 * @return string The description.
	 */
	private function describe(MethodScanRun $run, McpTool $attribute): string {
		$docLines    = ToolDocblock::lines(docComment: $run->method->getDocComment());
		$description = $attribute->description ?? ToolDocblock::summary(docLines: $docLines);
		if ($description === null || $description === '') {
			$description = sprintf('%s::%s (no description declared).', $run->subject->className, $run->method->getName());
		}

		return $description;
	}//end describe()

	/**
	 * The method's parameter names, in order.
	 *
	 * @param ReflectionMethod $method The attributed method.
	 *
	 * @return list<string> The names.
	 */
	private function paramNames(ReflectionMethod $method): array {
		return array_map(
			callback: static fn (ReflectionParameter $param): string => $param->getName(),
			array: $method->getParameters()
		);
	}//end paramNames()

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
		$required   = [];
		foreach ($method->getParameters() as $param) {
			$properties[$param->getName()] = $this->paramSchema(param: $param, description: ($docParams[$param->getName()] ?? ''));
			if ($param->isOptional() === false) {
				$required[] = $param->getName();
			}
		}

		$schema = ['type' => 'object', 'properties' => $properties];
		if ($required !== []) {
			$schema['required'] = $required;
		}

		return $schema;
	}//end inferInputSchema()

	/**
	 * JSON-Schema-shaped property for a single parameter, from its PHP type
	 * hint and its docblock description.
	 *
	 * @param ReflectionParameter $param       The parameter to describe.
	 * @param string              $description The `@param` description, or ''.
	 *
	 * @return array<string, mixed> The property schema (possibly empty when nothing can be inferred).
	 */
	private function paramSchema(ReflectionParameter $param, string $description): array {
		$property = [];
		$jsonType = ReflectionJsonType::of(type: $param->getType());
		if ($jsonType !== null) {
			$property['type'] = $jsonType;
		}

		if ($description !== '') {
			$property['description'] = $description;
		}

		return $property;
	}//end paramSchema()

	/**
	 * The `outputSchema` descriptor key, when an output schema is inferable.
	 *
	 * @param ReflectionMethod $method The attributed method.
	 *
	 * @return array{outputSchema?: array<string, mixed>}
	 */
	private function outputSchemaOf(ReflectionMethod $method): array {
		$outputSchema = $this->inferOutputSchema(method: $method);
		if ($outputSchema === null) {
			return [];
		}

		return ['outputSchema' => $outputSchema];
	}//end outputSchemaOf()

	/**
	 * Best-effort `outputSchema` from the return type.
	 * Omitted (null) for untyped/void/mixed/bare-array returns per
	 * design.md's "best-effort" contract.
	 *
	 * @param ReflectionMethod $method The attributed method.
	 *
	 * @return array<string, mixed>|null The output schema, or null when not inferable.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 *   (Requirement: REQ-ATTR-001 — outputSchema inferred from return type / @return where available)
	 */
	private function inferOutputSchema(ReflectionMethod $method): ?array {
		$type = $method->getReturnType();
		if (($type instanceof ReflectionNamedType) === false) {
			return null;
		}

		$jsonType = ReflectionJsonType::named(type: $type);
		if ($jsonType === null || $jsonType === 'array') {
			// Untyped array / mixed / void — too little information to be
			// useful; deliberately omitted (design.md "best-effort").
			return null;
		}

		return ['type' => ReflectionJsonType::of(type: $type)];
	}//end inferOutputSchema()
}//end class
