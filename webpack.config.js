/**
 * Webpack configuration.
 *
 * Extends @wordpress/scripts. WordPress packages (react, @wordpress/*) are
 * externalised by the dependency-extraction plugin as usual. The free
 * plugin's shared runtime is externalised here (ADR-015): `@rtbp/ui` and
 * `@rtbp/lib` resolve to `window.rtbp.ui` / `window.rtbp.lib`, published by
 * the free admin bundle — so Pro never bundles its own copy of the
 * components. The free admin handle is added as a script dependency in
 * includes/Assets/Assets.php, which guarantees the runtime exists first.
 */
const path = require( 'path' );
const defaults = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaults,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
	},
	output: {
		...defaults.output,
		path: path.resolve( __dirname, 'build' ),
	},
	externals: {
		...( defaults.externals || {} ),
		'@rtbp/ui': [ 'rtbp', 'ui' ],
		'@rtbp/lib': [ 'rtbp', 'lib' ],
		// One React Query instance and cache, the free app's.
		'@tanstack/react-query': [ 'rtbp', 'ReactQuery' ],
	},
	resolve: {
		...defaults.resolve,
		extensions: [ '.js', '.jsx', '.json', ...( defaults.resolve?.extensions || [] ) ],
	},
};
