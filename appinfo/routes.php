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
		['name' => 'media#destroy',    'url' => '/api/v1/media/{id}', 'verb' => 'DELETE'],
		['name' => 'media#destroyAll', 'url' => '/api/v1/media',      'verb' => 'DELETE'],

		['name' => 'settings#getDiscogsToken', 'url' => '/api/v1/settings/discogs-token', 'verb' => 'GET'],
		['name' => 'settings#setDiscogsToken', 'url' => '/api/v1/settings/discogs-token', 'verb' => 'POST'],

		['name' => 'discogs#search',     'url' => '/api/v1/discogs/search',         'verb' => 'GET'],
		['name' => 'discogs#getRelease', 'url' => '/api/v1/discogs/release/{id}',   'verb' => 'GET'],
		['name' => 'discogs#getArtist',  'url' => '/api/v1/discogs/artist/{id}',    'verb' => 'GET'],

		['name' => 'media#enrich',       'url' => '/api/v1/media/{id}/enrich',      'verb' => 'POST'],

		['name' => 'import#preview', 'url' => '/api/v1/import/preview', 'verb' => 'POST'],
		['name' => 'import#commit',  'url' => '/api/v1/import/commit',  'verb' => 'POST'],
	],
];
