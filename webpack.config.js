const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

// Add TypeScript handling to module rules
// Use ts-loader for TypeScript files (already in dependencies)
webpackConfig.module.rules.push({
	test: /\.(ts|tsx)$/,
	exclude: /node_modules/,
	use: {
		loader: 'ts-loader',
		options: {
			transpileOnly: true,
			appendTsSuffixTo: [/\.vue$/],
		},
	},
})

// Add .ts and .tsx to resolve extensions and '@' alias
webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.extensions = [
	'.ts',
	'.tsx',
	'.js',
	'.jsx',
	'.vue',
	'.json',
	...(webpackConfig.resolve.extensions || []),
]
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
}

const appId = 'openregister'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
}

module.exports = webpackConfig
