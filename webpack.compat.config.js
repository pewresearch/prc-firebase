/**
 * Webpack config for the legacy `firebase` compat script.
 *
 * Builds `src/compat/index.js` -> `build/compat/index.js` as a classic
 * script bundle (NOT a script module). Bundles the Firebase JS SDK
 * (`firebase/compat/*`) into the output so consumers can do
 * `wp_enqueue_script( 'firebase' )` and rely on the resulting
 * `window.firebase`, `window.firebaseDb`, `window.firebaseAuth`, and
 * `window.interactivesDb` globals.
 */

const { join } = require('path');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	entry: {
		index: './src/compat/index.js',
	},
	output: {
		...defaultConfig.output,
		path: join(__dirname, 'build', 'compat'),
		filename: '[name].js',
	},
	plugins: [
		// Drop the default DependencyExtractionWebpackPlugin and replace it
		// with one that does not externalize anything: the legacy compat
		// bundle must include the Firebase SDK so older `wp_enqueue_script(
		// 'firebase' )` consumers get window globals.
		...defaultConfig.plugins.filter(
			(plugin) =>
				'DependencyExtractionWebpackPlugin' !== plugin.constructor.name
		),
		new DependencyExtractionWebpackPlugin({
			requestToExternal: () => null,
			requestToExternalModule: () => null,
			requestToHandle: () => undefined,
		}),
	],
};
