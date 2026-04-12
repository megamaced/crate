<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	],
	'ocs' => [
		['name' => 'media#index',   'url' => '/api/v1/media',      'verb' => 'GET'],
		['name' => 'media#show',    'url' => '/api/v1/media/{id}', 'verb' => 'GET'],
		['name' => 'media#create',  'url' => '/api/v1/media',      'verb' => 'POST'],
		['name' => 'media#update',  'url' => '/api/v1/media/{id}', 'verb' => 'PUT'],
		['name' => 'media#destroy', 'url' => '/api/v1/media/{id}', 'verb' => 'DELETE'],
	],
];
