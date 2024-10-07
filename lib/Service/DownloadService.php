<?php

namespace OCA\OpenRegister\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use OCP\IURLGenerator;
use Symfony\Component\Uid\Uuid;

class DownloadService
{
	public function __construct() {}

	public function download(string $type)
	{
		switch ($type) {
			case 'json':
				// @todo this is placeholder code
				break;
			default:
				// @todo some logging
				return null;
		}
	}

}
