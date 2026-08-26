const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

// The shared config hardcodes `/apps/<app>/js/`, which is only correct when the
// app lives under the `apps` root. Nextcloud also serves apps from additional
// `apps_paths` entries — the official Docker image puts installed apps in
// `custom_apps`, exposed at `/custom_apps` — and the webroot for those does not
// contain `/apps/`. The entry bundle is unaffected because Nextcloud generates
// its script tag from the real webroot, but webpack's own chunk loader builds
// lazy-chunk URLs from this prefix, so every dynamically imported component
// 404s on such an install.
//
// `auto` resolves the prefix at runtime from the URL of the executing script,
// which Nextcloud emitted, so it is correct for any apps root and for
// installs served from a subdirectory.
webpackConfig.output.publicPath = 'auto'

module.exports = webpackConfig
