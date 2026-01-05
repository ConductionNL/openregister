<?php
$json = file_get_contents('/tmp/config.json');
$config = json_decode($json, true);

$wrapped = [
    'title' => $config['info']['title'] ?? 'Software Catalog',
    'configuration' => $config
];

echo json_encode($wrapped, JSON_PRETTY_PRINT);

