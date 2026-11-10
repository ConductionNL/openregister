<?php

/**
 * ConnectorRequestTrait: the router's call options and path, shaped the way the
 * connector's CallService hands them to Guzzle.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-05-messaging-dispatch-leaf/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Shapes one {@see ExternalIntegrationRouter} call for the connector.
 *
 * @spec openspec/changes/archive/2026-10-05-messaging-dispatch-leaf/tasks.md
 */
trait ConnectorRequestTrait {
	/**
	 * The endpoint to hand the connector for a path relative to the source.
	 *
	 * The connector (integriq) builds the URL as `location . endpoint` with no separator, and
	 * the seeded sources carry a location without a trailing slash
	 * (`https://rest.messagebird.com`). A relative path such as `messages`
	 * therefore became `https://rest.messagebird.commessages`. The path is
	 * joined with exactly one slash: led by one unless the location already
	 * ends in one.
	 *
	 * @param mixed $source The resolved source entity.
	 * @param string $path Path relative to the source base URL.
	 *
	 * @return string The endpoint to append to the source location.
	 *
	 * @spec openspec/changes/archive/2026-10-05-messaging-dispatch-leaf/tasks.md
	 */
	private function connectorEndpoint($source, string $path): string {
		$relative = ltrim($path, '/');
		if ($relative === '') {
			return $path;
		}

		if (str_ends_with(self::connectorLocation(source: $source), '/') === true) {
			return $relative;
		}

		return '/' . $relative;
	}//end connectorEndpoint()

	/**
	 * The base location a source declares, or '' when it declares none.
	 *
	 * @param mixed $source Resolved source entity, or its data array.
	 *
	 * @return string The location.
	 */
	private static function connectorLocation(mixed $source): string {
		$data = self::connectorSourceData(source: $source);
		if (is_array($data) === false) {
			return '';
		}

		return (string)($data['location'] ?? '');
	}//end connectorLocation()

	/**
	 * A source's data: its object, or just its location, or the source itself.
	 *
	 * @param mixed $source Resolved source entity, or its data array.
	 *
	 * @return mixed The data.
	 */
	private static function connectorSourceData(mixed $source): mixed {
		if (is_object($source) === true && method_exists($source, 'getObject') === true) {
			return $source->getObject();
		}

		if (is_object($source) === true && method_exists($source, 'getLocation') === true) {
			return ['location' => $source->getLocation()];
		}

		return $source;
	}//end connectorSourceData()

	/**
	 * Turn the router's call options into request options the connector's
	 * CallService can hand to Guzzle.
	 *
	 * {@see call()} accepts `body` as a scalar OR an array, but the connector
	 * passes the options to Guzzle unchanged, and Guzzle refuses an array
	 * under `body` ("Passing in the body request option as an array ... is not
	 * supported"). Every array body therefore failed on a real source; only a
	 * mock source, which never reaches the CallService, appeared to work. An
	 * array body is sent as `form_params` when the caller set a form
	 * Content-Type (Twilio), and as `json` otherwise (MessageBird, CM.com,
	 * Meta, BRP, OpenProject). A scalar body is sent as-is.
	 *
	 * @param array<string,mixed> $options Call options (query / body / headers).
	 *
	 * @return array<string,mixed> Guzzle request options.
	 *
	 * @spec openspec/changes/archive/2026-10-05-messaging-dispatch-leaf/tasks.md
	 */
	private function connectorOptions(array $options): array {
		if (isset($options['body']) === false || is_array($options['body']) === false) {
			return $options;
		}

		$body = $options['body'];
		unset($options['body']);

		$contentType = self::connectorContentType(headers: ($options['headers'] ?? []));
		if (str_starts_with($contentType, 'application/x-www-form-urlencoded') === true) {
			$options['form_params'] = $body;
			return $options;
		}

		$options['json'] = $body;
		return $options;
	}//end connectorOptions()

	/**
	 * The lower-cased Content-Type the caller set; the last spelling wins.
	 *
	 * @param mixed $headers The call's headers.
	 *
	 * @return string The content type, or ''.
	 */
	private static function connectorContentType(mixed $headers): string {
		$contentType = '';
		foreach ($headers as $name => $value) {
			if (strtolower((string)$name) === 'content-type') {
				$contentType = strtolower((string)$value);
			}
		}

		return $contentType;
	}//end connectorContentType()
}//end trait
