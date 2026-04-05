/**
 * Flickr Justified Gallery - Centralized Config Reader
 *
 * Reads server-provided data from the WP Script Modules data tag
 * (<script type="application/json" id="wp-script-module-data-...">).
 * Replaces the old wp_localize_script globals (flickrJustifiedConfig, flickrJustifiedRest).
 */

let _config = null;

function loadConfig() {
	if ( _config ) return _config;

	const el = document.getElementById(
		'wp-script-module-data-flickr-justified-block-view-script-module'
	);
	if ( el ) {
		try {
			_config = JSON.parse( el.textContent );
		} catch ( e ) {
			console.error(
				'Flickr Justified Block: Failed to parse config data',
				e
			);
			_config = {};
		}
	} else {
		_config = {};
	}
	return _config;
}

export function getConfig() {
	return loadConfig();
}

export function getPluginUrl() {
	return getConfig().pluginUrl || '';
}

export function getAjaxUrl() {
	return getConfig().ajaxurl || '';
}

export function getRestUrl() {
	return getConfig().restUrl || '';
}

export function getRestNonce() {
	return getConfig().restNonce || '';
}

export function getFallbackNonce() {
	return getConfig().fallbackNonce || '';
}

export function getAsyncNonce() {
	return getConfig().asyncNonce || '';
}
