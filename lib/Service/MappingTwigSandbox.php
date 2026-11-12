<?php

/**
 * OpenRegister Mapping Twig Sandbox
 *
 * The sandbox every user-authored mapping template renders in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityPolicy;

/**
 * Builds the Twig sandbox for mapping templates.
 *
 * SSTI hardening (SEC-SVC-3): user-authored mapping templates are compiled
 * and rendered by MappingService, so they MUST run inside a Twig sandbox.
 * Only the tags, filters and functions actually used by mappings are
 * allowlisted; method/property access on objects is denied entirely.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class MappingTwigSandbox {

	/**
	 * The tags a mapping template may use.
	 *
	 * @var array<int, string>
	 */
	private const TAGS = ['if', 'for', 'set', 'apply'];

	/**
	 * The filters a mapping template may use.
	 *
	 * `json_encode` is Twig core's own filter, and the counterpart to
	 * `json_decode`. It was missing, so a stored mapping that encodes a value
	 * to JSON failed at RENDER with a Twig SecurityError naming a filter,
	 * nowhere near the mapping the operator was looking at. dossiq stores
	 * several ZGW properties as JSON text and encodes them in twelve of its
	 * default mappings; two of those sit in the setUp of the VNG ZGW contract
	 * collections, so the zaaktype create 400ed and took every later request
	 * in the collection with it. See #3663. It is a pure value transform. The
	 * policy already denies every method and property, so this widens nothing
	 * an attacker can reach.
	 *
	 * @var array<int, string>
	 */
	private const FILTERS = [
		'date', 'date_modify', 'upper', 'lower', 'trim', 'length', 'default',
		'number_format', 'round', 'abs', 'split', 'join', 'slice', 'first', 'last',
		'replace', 'format', 'merge', 'keys', 'escape', 'raw', 'b64enc', 'b64dec',
		'json_decode', 'json_encode', 'zgw_enum', 'zgw_enum_reverse', 'zgw_extract_uuid',
	];

	/**
	 * The functions a mapping template may call, before contributed ones.
	 *
	 * `json_decode` and `jsonDecode` are ported from OpenConnector's copy so
	 * its mappings keep working once that copy is retired: `json_decode` is the
	 * name OpenConnector's templates use, OpenRegister's own runtime spells it
	 * `jsonDecode`. Both are exposed rather than renaming either, because a
	 * mapping is authored data — renaming a function silently breaks stored
	 * templates.
	 *
	 * @var array<int, string>
	 */
	private const FUNCTIONS = [
		'max', 'min', 'range', 'executeMapping', 'generateUuid', 'json_decode', 'jsonDecode',
		'createSlug', 'getFileContents', 'getFiles', 'b64enc', 'b64dec',
		'zgwEnum', 'zgwEnumReverse', 'zgwExtractUuid',
	];

	/**
	 * The sandbox extension, enabled for every template.
	 *
	 * @param array<int, string> $contributedNames Function names other apps contributed, allowlisted as well.
	 *
	 * @return SandboxExtension The sandbox extension.
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function extension(array $contributedNames): SandboxExtension {
		$policy = new SecurityPolicy(
			allowedTags: self::TAGS,
			allowedFilters: self::FILTERS,
			allowedMethods: [],
			allowedProperties: [],
			allowedFunctions: array_merge(self::FUNCTIONS, $contributedNames)
		);

		return new SandboxExtension($policy, sandboxed: true);
	}//end extension()
}//end class
