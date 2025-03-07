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
webpackConfig.module.rules.push({
	test: /\.(ts|tsx)$/,
	exclude: /node_modules/,
	use: {
		loader: 'babel-loader',
		options: {
			presets: [
				'@babel/preset-env',
				'@babel/preset-typescript'
			],
			plugins: [
				'@babel/plugin-transform-typescript'
			]
		}
	}
})

// Add .ts and .tsx to resolve extensions
webpackConfig.resolve = {
	...webpackConfig.resolve,
	extensions: ['.ts', '.tsx', '.js', '.jsx', '.vue', '.json']
}

const appId = 'openregister'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
}

module.exports = webpackConfig
