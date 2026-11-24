<?php

/**
 * The headers an object response carries when its schema was named by a slug it has since given up.
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
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-former-slug-keeps-answering-and-says-where-to-go
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCP\AppFramework\Http\JSONResponse;

/**
 * A renamed schema keeps answering to its former slug (design D-3), and the
 * answer says where to go: `Deprecation: true` and a `Link` to the same
 * listing under the current slug, `rel="successor-version"`.
 *
 * Decided from what the resolution already loaded: the path's schema
 * segment is not the schema's current slug and is one of its former slugs.
 * A numeric id, a uuid and the current slug never carry the signal.
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-former-slug-keeps-answering-and-says-where-to-go
 */
final class FormerSlugSignal {
	/**
	 * The headers for a path pair and the schema it resolved to.
	 *
	 * @param string $register The register segment of the path, as the caller wrote it.
	 * @param string $schema   The schema segment of the path, as the caller wrote it.
	 * @param mixed  $resolved The schema the segment resolved to.
	 *
	 * @return array<string, string> Deprecation and Link, or nothing.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-former-slug-keeps-answering-and-says-where-to-go
	 */
	public static function headers(string $register, string $schema, mixed $resolved): array {
		if ($resolved instanceof SchemaInterface === false) {
			return [];
		}

		$current = (string)($resolved->getSlug() ?? '');
		if ($current === '' || $schema === $current || in_array($schema, ($resolved->getFormerSlugs() ?? []), true) === false) {
			return [];
		}

		return [
			'Deprecation' => 'true',
			'Link'        => sprintf('</api/objects/%s/%s>; rel="successor-version"', rawurlencode($register), rawurlencode($current)),
		];
	}//end headers()

	/**
	 * Add the headers to a response.
	 *
	 * @param JSONResponse          $response The response.
	 * @param array<string, string> $headers  The headers from {@see self::headers()}.
	 *
	 * @return JSONResponse The same response.
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md#requirement-a-former-slug-keeps-answering-and-says-where-to-go
	 */
	public static function apply(JSONResponse $response, array $headers): JSONResponse {
		foreach ($headers as $name => $value) {
			$response->addHeader($name, $value);
		}

		return $response;
	}//end apply()
}//end class
