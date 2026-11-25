/**
 * External Dependencies
 */
const { join } = require('path');

/**
 * WordPress Dependencies
 */
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');

/**
 * Internal Dependencies
 */
const { baseConfig, plugins } = require('./webpack.shared');
const {
	requestToExternal,
	requestToExternalModule,
	requestToHandle,
} = require('../../dependency-extraction');

const babelRule = {
	test: /\.(j|t)sx?$/,
	exclude: /node_modules/,
	use: [
		{
			loader: require.resolve('babel-loader'),
			options: {
				cacheDirectory: process.env.BABEL_CACHE_DIRECTORY || true,
				babelrc: false,
				configFile: false,
				presets: ['@babel/preset-typescript', '@babel/preset-react'],
			},
		},
	],
};

const moduleOutput = {
	filename: './[name].min.js',
	library: {
		type: 'module',
	},
	path: join(__dirname, 'build'),
	environment: { module: true },
	module: true,
	chunkFormat: 'module',
	asyncChunks: false,
	clean: false,
};

const coreModuleConfig = {
	...baseConfig,
	name: 'module',
	entry: {
		module: './src/index.js',
	},
	experiments: {
		outputModule: true,
	},
	output: moduleOutput,
	resolve: {
		extensions: ['.js', '.ts', '.tsx'],
		mainFields: ['module'],
	},
	module: {
		rules: [babelRule],
	},
	plugins: [
		...plugins,
		new DependencyExtractionWebpackPlugin({
			requestToExternalModule: () => null,
		}),
	],
	watchOptions: {
		ignored: ['**/node_modules'],
		aggregateTimeout: 500,
	},
};

const browserMessagingConfig = {
	...baseConfig,
	name: 'browser-messaging',
	entry: {
		'browser-messaging': './src/browser-messaging/index.js',
	},
	experiments: {
		outputModule: true,
	},
	output: moduleOutput,
	resolve: {
		extensions: ['.js', '.ts', '.tsx'],
		mainFields: ['module'],
	},
	module: {
		rules: [babelRule],
	},
	plugins: [
		...plugins,
		new DependencyExtractionWebpackPlugin({
			requestToExternal,
			requestToExternalModule,
			requestToHandle,
		}),
	],
	watchOptions: {
		ignored: ['**/node_modules'],
		aggregateTimeout: 500,
	},
};

const firebaseMessagingSwConfig = {
	...baseConfig,
	name: 'firebase-messaging-sw',
	target: 'webworker',
	entry: {
		'firebase-messaging-sw':
			'./src/browser-messaging/firebase-messaging-sw.js',
	},
	output: {
		path: join(__dirname, 'build'),
		filename: 'firebase-messaging-sw.js',
		clean: false,
	},
	module: {
		rules: [babelRule],
	},
	plugins: [
		...plugins,
		new DependencyExtractionWebpackPlugin({
			requestToExternal: () => null,
			requestToExternalModule: () => null,
		}),
	],
	watchOptions: {
		ignored: ['**/node_modules'],
		aggregateTimeout: 500,
	},
};

module.exports = [
	coreModuleConfig,
	browserMessagingConfig,
	firebaseMessagingSwConfig,
];
