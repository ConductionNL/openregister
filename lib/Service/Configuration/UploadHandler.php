<?php

/**
 * OpenRegister Upload Handler
 *
 * This file contains the handler class for processing file uploads
 * and parsing JSON/YAML data in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Configuration;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Parser;

/**
 * Class UploadHandler
 *
 * Handles file uploads and JSON/YAML parsing for configuration imports.
 *
 * @package OCA\OpenRegister\Service\Configuration
 */
class UploadHandler {

	/**
	 * HTTP Client for making external requests.
	 *
	 * @var Client The HTTP client instance.
	 */
	private readonly Client $client;

	/**
	 * Logger instance for logging operations.
	 *
	 * @var LoggerInterface The logger instance.
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor for UploadHandler.
	 *
	 * @param Client $client The HTTP client.
	 * @param LoggerInterface $logger The logger interface.
	 */
	public function __construct(
		Client $client,
		LoggerInterface $logger,
	) {
		$this->client = $client;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Gets the uploaded json from the request data and returns it as a PHP array.
	 *
	 * Will first try to find an uploaded 'file', then if an 'url' is present in the body,
	 * and lastly if a 'json' dump has been posted.
	 *
	 * @param array $data All request params.
	 * @param array|null $uploadedFiles The uploaded files array.
	 *
	 * @return JSONResponse|array A PHP array with the uploaded json data or a JSONResponse in case of an error.
	 *
	 * @throws Exception
	 * @throws GuzzleException
	 *
	 * @psalm-return JSONResponse<400, array{error?: string, 'MIME-type'?: string,
	 *     message?: 'Expected only 1 file.', 'Content-Type'?: string},
	 *     array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function getUploadedJson(array $data, ?array $uploadedFiles): array|JSONResponse {
		// An uploaded file wins over a url or a json blob in the post body.
		if (empty($uploadedFiles) === false) {
			return $this->getJSONfromFiles(uploadedFiles: $uploadedFiles);
		}

		if (array_intersect_key($data, array_flip(['url', 'json'])) === []) {
			$errorMessage = 'Missing required keys in POST body: url, json, or file in form-data.';
			return new JSONResponse(data: ['error' => $errorMessage], statusCode: 400);
		}

		if (empty($data['url']) === false) {
			return $this->getJSONfromURL(url: $data['url']);
		}

		return $this->getJSONfromBody(phpArray: $data['json']);
	}//end getUploadedJson()

	/**
	 * Gets the content of the one uploaded file as PHP array.
	 *
	 * @param array $uploadedFiles The uploaded files array; holds at least one file.
	 *
	 * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
	 *
	 * @psalm-return JSONResponse<400, array{error?: string, 'MIME-type'?: string,
	 *     message?: 'Expected only 1 file.'}, array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getJSONfromFiles(array $uploadedFiles): array|JSONResponse {
		if (count($uploadedFiles) !== 1) {
			return new JSONResponse(data: ['message' => 'Expected only 1 file.'], statusCode: 400);
		}

		return $this->getJSONfromFile(uploadedFile: $uploadedFiles[array_key_first($uploadedFiles)]);
	}//end getJSONfromFiles()

	/**
	 * Decode file content or URL response.
	 *
	 * This method decodes JSON or YAML data based on the content type.
	 *
	 * @param string $data The file content or response body content.
	 * @param string|null $type The file MIME type or response Content-Type header.
	 *
	 * @return array|null The decoded data or null.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function decode(string $data, ?string $type): ?array {
		$phpArray = match ($type) {
			'application/json' => json_decode(json: $data, associative: true),
			'application/yaml' => (new Parser())->parse(value: $data),
			// If Content-Type is not specified or not recognized, try to parse as JSON first, then YAML.
			default            => $this->decodeJsonOrYaml(data: $data),
		};

		if (is_array($phpArray) === false) {
			return null;
		}

		// Ensure all data is consistently arrays by converting any stdClass objects.
		return (new ArrayStructure())->toArrays(data: $phpArray);
	}//end decode()

	/**
	 * Decode data as JSON, or as YAML when it is not JSON.
	 *
	 * @param string $data The file content or response body content.
	 *
	 * @return mixed The decoded value, or null when it is neither.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function decodeJsonOrYaml(string $data): mixed {
		$phpArray = json_decode(json: $data, associative: true);
		if ($phpArray !== null && $phpArray !== false) {
			return $phpArray;
		}

		try {
			return (new Parser())->parse(value: $data);
		} catch (Exception $exception) {
			return null;
		}
	}//end decodeJsonOrYaml()

	/**
	 * Gets uploaded file content from a file in the api request as PHP array.
	 *
	 * @param array $uploadedFile The uploaded file.
	 *
	 * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
	 *
	 * @psalm-return JSONResponse<400, array{error: string, 'MIME-type'?: string}, array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getJSONfromFile(array $uploadedFile): array|JSONResponse {
		if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
			return new JSONResponse(data: ['error' => 'File upload error: ' . $uploadedFile['error']], statusCode: 400);
		}

		$fileExtension = pathinfo(path: $uploadedFile['name'], flags: PATHINFO_EXTENSION);
		$phpArray      = $this->decode(data: file_get_contents(filename: $uploadedFile['tmp_name']), type: $fileExtension);
		if ($phpArray === null) {
			$error = ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => $fileExtension];
			return new JSONResponse(data: $error, statusCode: 400);
		}

		return $phpArray;
	}//end getJSONfromFile()

	/**
	 * Uses Guzzle to call the given URL and returns response as PHP array.
	 *
	 * @param string $url The URL to call.
	 *
	 * @return JSONResponse|array
	 *
	 * @throws GuzzleException
	 *
	 * @psalm-return JSONResponse<400, array{error: string, 'Content-Type'?: string}, array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getJSONfromURL(string $url): array|JSONResponse {
		try {
			$response = $this->client->request('GET', $url);
		} catch (GuzzleException $e) {
			$error = ['error' => 'Failed to do a GET api-call on url: ' . $url . ' ' . $e->getMessage()];
			return new JSONResponse(data: $error, statusCode: 400);
		}

		$contentType = $response->getHeaderLine('Content-Type');
		$phpArray    = $this->decode(data: $response->getBody()->getContents(), type: $contentType);
		if ($phpArray === null) {
			$error = ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => $contentType];
			return new JSONResponse(data: $error, statusCode: 400);
		}

		return $phpArray;
	}//end getJSONfromURL()

	/**
	 * Uses the given string or array as PHP array for creating/updating an object.
	 *
	 * @param array|string $phpArray An array or string containing a json blob of data.
	 *
	 * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error.
	 *
	 * @psalm-return JSONResponse<400, array{error: 'Failed to decode JSON input'}, array<never, never>>|array
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function getJSONfromBody(array|string $phpArray): array|JSONResponse {
		if (is_string($phpArray) === true) {
			$phpArray = json_decode($phpArray, associative: true);
		}

		if (is_array($phpArray) === false) {
			return new JSONResponse(
				data: ['error' => 'Failed to decode JSON input'],
				statusCode: 400
			);
		}

		// Ensure all data is consistently arrays by converting any stdClass objects.
		$phpArray = (new ArrayStructure())->toArrays(data: $phpArray);

		return $phpArray;
	}//end getJSONfromBody()
}//end class
