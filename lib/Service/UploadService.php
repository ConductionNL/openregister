<?php

namespace OCA\OpenRegister\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use OCP\IURLGenerator;
use Symfony\Component\Uid\Uuid;

class UploadService
{
	public function __construct() {}

	public function upload()
	{
		// @todo this is placeholder code
	}

	/**
	 * Abstract function to map a php array (decoded from json input) to an array that can be used to create new Objects (like a Schema).
	 *
	 * @param array $input
	 *
	 * @return array
	 */
	public function mapJsonSchema(array $input): array
	{
		// @todo maybe do a switch for $input['$schema'] here? Or just do custom mappings in the Controllers?

		// @todo we probably should make sure every Entity we create and want to upload/download has these properties?
		return [
			'title' => $input['title'] ?? '',
			'reference' => $input['$id'] ?? '',
			'version' => $input['version'] ?? '',
			'description' => $input['description'] ?? ''
		];
	}

}
