const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

// When WP_EXPERIMENTAL_MODULES is set, defaultConfig is an array
// [scriptConfig, moduleConfig]. Otherwise it's a single config object.
const configs = Array.isArray( defaultConfig )
	? defaultConfig
	: [ defaultConfig ];

const scriptConfig = configs[ 0 ];

// Classic script entries (editor bundle only — frontend moves to module)
scriptConfig.entry = {
	editor: path.resolve( __dirname, 'src/editor/index.js' ),
};
scriptConfig.output = {
	...scriptConfig.output,
	path: path.resolve( __dirname, 'build' ),
};

if ( configs.length > 1 ) {
	const moduleConfig = configs[ 1 ];

	// ES module entry for the frontend view script
	moduleConfig.entry = {
		'view.module': path.resolve( __dirname, 'src/view.module.js' ),
	};
	moduleConfig.output = {
		...moduleConfig.output,
		path: path.resolve( __dirname, 'build' ),
	};
}

module.exports = configs.length > 1 ? configs : scriptConfig;
