const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	'crate-main': path.join(__dirname, 'src', 'main.js'),
}

module.exports = webpackConfig
