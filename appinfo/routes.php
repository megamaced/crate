<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index',     'url' => '/',                   'verb' => 'GET'],
		['name' => 'artwork#get',    'url' => '/artwork/{itemId}',   'verb' => 'GET'],
		['name' => 'artwork#upload', 'url' => '/artwork/{itemId}',   'verb' => 'POST'],
		['name' => 'artwork#delete', 'url' => '/artwork/{itemId}',   'verb' => 'DELETE'],
		['name' => 'export#export',  'url' => '/export',             'verb' => 'GET'],
	],
	'ocs' => [
		// ── Media items ────────────────────────────────────────────────────────
		['name' => 'media#index',   'url' => '/api/v1/media',      'verb' => 'GET'],
		['name' => 'media#show',    'url' => '/api/v1/media/{id}', 'verb' => 'GET'],
		['name' => 'media#create',  'url' => '/api/v1/media',      'verb' => 'POST'],
		['name' => 'media#update',  'url' => '/api/v1/media/{id}', 'verb' => 'PUT'],
		['name' => 'media#destroy',    'url' => '/api/v1/media/{id}', 'verb' => 'DELETE'],
		['name' => 'media#destroyAll', 'url' => '/api/v1/media',      'verb' => 'DELETE'],

		// ── Discogs enrichment ─────────────────────────────────────────────────
		['name' => 'media#enrich',           'url' => '/api/v1/media/{id}/enrich',       'verb' => 'POST'],
		['name' => 'media#stripEnrich',      'url' => '/api/v1/media/{id}/enrich',       'verb' => 'DELETE'],

		// ── Market values ──────────────────────────────────────────────────────
		['name' => 'media#fetchMarketValue', 'url' => '/api/v1/media/{id}/market-value', 'verb' => 'POST'],

		// ── Settings ───────────────────────────────────────────────────────────
		['name' => 'settings#getDiscogsToken',  'url' => '/api/v1/settings/discogs-token', 'verb' => 'GET'],
		['name' => 'settings#setDiscogsToken',  'url' => '/api/v1/settings/discogs-token', 'verb' => 'POST'],
		['name' => 'settings#getTmdbToken',     'url' => '/api/v1/settings/tmdb-token',    'verb' => 'GET'],
		['name' => 'settings#setTmdbToken',     'url' => '/api/v1/settings/tmdb-token',    'verb' => 'POST'],
		['name' => 'settings#getRawgKey',             'url' => '/api/v1/settings/rawg-key',             'verb' => 'GET'],
		['name' => 'settings#setRawgKey',             'url' => '/api/v1/settings/rawg-key',             'verb' => 'POST'],
		['name' => 'settings#getComicVineKey',        'url' => '/api/v1/settings/comicvine-key',        'verb' => 'GET'],
		['name' => 'settings#setComicVineKey',        'url' => '/api/v1/settings/comicvine-key',        'verb' => 'POST'],
		['name' => 'settings#getPriceChartingToken',  'url' => '/api/v1/settings/pricecharting-token',  'verb' => 'GET'],
		['name' => 'settings#setPriceChartingToken',  'url' => '/api/v1/settings/pricecharting-token',  'verb' => 'POST'],
		['name' => 'settings#getMarketSettings', 'url' => '/api/v1/settings/market',       'verb' => 'GET'],
		['name' => 'settings#setMarketSettings', 'url' => '/api/v1/settings/market',       'verb' => 'POST'],
		['name' => 'settings#getSupportedCurrencies', 'url' => '/api/v1/settings/currencies', 'verb' => 'GET'],

		// ── Discogs API proxy ──────────────────────────────────────────────────
		['name' => 'discogs#search',       'url' => '/api/v1/discogs/search',            'verb' => 'GET'],
		['name' => 'discogs#barcodeSearch', 'url' => '/api/v1/discogs/barcode/{barcode}', 'verb' => 'GET'],
		['name' => 'discogs#getRelease',    'url' => '/api/v1/discogs/release/{id}',      'verb' => 'GET'],
		['name' => 'discogs#getArtist',     'url' => '/api/v1/discogs/artist/{id}',       'verb' => 'GET'],

		// ── TMDB API proxy (films) ─────────────────────────────────────────────
		['name' => 'tmdb#search',    'url' => '/api/v1/tmdb/search',     'verb' => 'GET'],
		['name' => 'tmdb#getMovie',  'url' => '/api/v1/tmdb/movie/{id}', 'verb' => 'GET'],

		// ── Open Library proxy (books) ─────────────────────────────────────────
		['name' => 'openLibrary#search',  'url' => '/api/v1/openlibrary/search',    'verb' => 'GET'],
		['name' => 'openLibrary#getWork', 'url' => '/api/v1/openlibrary/work/{id}', 'verb' => 'GET'],

		// ── RAWG API proxy (games) ─────────────────────────────────────────────
		['name' => 'rawg#search',   'url' => '/api/v1/rawg/search',    'verb' => 'GET'],
		['name' => 'rawg#getGame',  'url' => '/api/v1/rawg/game/{id}', 'verb' => 'GET'],

		// ── ComicVine API proxy (comics) ───────────────────────────────────────────
		['name' => 'comicVine#search',    'url' => '/api/v1/comicvine/search',      'verb' => 'GET'],
		['name' => 'comicVine#getVolume', 'url' => '/api/v1/comicvine/volume/{id}', 'verb' => 'GET'],

		// ── Import ─────────────────────────────────────────────────────────────
		['name' => 'import#preview', 'url' => '/api/v1/import/preview', 'verb' => 'POST'],
		['name' => 'import#commit',  'url' => '/api/v1/import/commit',  'verb' => 'POST'],

		// ── Playlists ──────────────────────────────────────────────────────────
		['name' => 'playlist#index',      'url' => '/api/v1/playlists',                         'verb' => 'GET'],
		['name' => 'playlist#create',     'url' => '/api/v1/playlists',                         'verb' => 'POST'],
		['name' => 'playlist#show',       'url' => '/api/v1/playlists/{id}',                    'verb' => 'GET'],
		['name' => 'playlist#update',     'url' => '/api/v1/playlists/{id}',                    'verb' => 'PUT'],
		['name' => 'playlist#destroy',    'url' => '/api/v1/playlists/{id}',                    'verb' => 'DELETE'],
		['name' => 'playlist#addItem',    'url' => '/api/v1/playlists/{id}/items',               'verb' => 'POST'],
		['name' => 'playlist#removeItem', 'url' => '/api/v1/playlists/{id}/items/{mediaItemId}', 'verb' => 'DELETE'],

		// ── Sharing ────────────────────────────────────────────────────────────
		['name' => 'share#searchUsers',     'url' => '/api/v1/users/search',             'verb' => 'GET'],
		['name' => 'share#shareAlbum',      'url' => '/api/v1/share/album/{id}',         'verb' => 'POST'],
		['name' => 'share#sharesForAlbum',  'url' => '/api/v1/share/album/{id}',         'verb' => 'GET'],
		['name' => 'share#sharePlaylist',   'url' => '/api/v1/share/playlist/{id}',      'verb' => 'POST'],
		['name' => 'share#sharesForPlaylist', 'url' => '/api/v1/share/playlist/{id}',    'verb' => 'GET'],
		['name' => 'share#sharedWithMe',    'url' => '/api/v1/share/with-me',            'verb' => 'GET'],
		['name' => 'share#unshare',         'url' => '/api/v1/share/{id}',               'verb' => 'DELETE'],

		// ── Android / mobile API ───────────────────────────────────────────────
		['name' => 'settings#me',                  'url' => '/api/v1/me',                       'verb' => 'GET'],
		['name' => 'settings#setCurrency',         'url' => '/api/v1/settings/currency',        'verb' => 'PUT'],
		['name' => 'home#home',                    'url' => '/api/v1/home',                     'verb' => 'GET'],
		['name' => 'media#refreshAllMarketValues', 'url' => '/api/v1/market-value/refresh-all', 'verb' => 'POST'],
	],
];
