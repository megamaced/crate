<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index',   'url' => '/',                   'verb' => 'GET'],
		['name' => 'artwork#get',  'url' => '/artwork/{itemId}',   'verb' => 'GET'],
	],
	'ocs' => [
		['name' => 'media#index',   'url' => '/api/v1/media',      'verb' => 'GET'],
		['name' => 'media#show',    'url' => '/api/v1/media/{id}', 'verb' => 'GET'],
		['name' => 'media#create',  'url' => '/api/v1/media',      'verb' => 'POST'],
		['name' => 'media#update',  'url' => '/api/v1/media/{id}', 'verb' => 'PUT'],
		['name' => 'media#destroy', 'url' => '/api/v1/media/{id}', 'verb' => 'DELETE'],

		['name' => 'settings#getDiscogsToken', 'url' => '/api/v1/settings/discogs-token', 'verb' => 'GET'],
		['name' => 'settings#setDiscogsToken', 'url' => '/api/v1/settings/discogs-token', 'verb' => 'POST'],

		['name' => 'discogs#search', 'url' => '/api/v1/discogs/search', 'verb' => 'GET'],
	],
];
