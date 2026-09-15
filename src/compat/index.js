/**
 * External Dependencies
 */
import firebase from 'firebase/compat/app';
import 'firebase/compat/database';
import 'firebase/compat/auth';

function loadScript(slug, script) {
	if (!window[slug]) {
		window[slug] = script;
	}
}

function isUsableFirebaseConfig(config) {
	return (
		config && typeof config.apiKey === 'string' && config.apiKey.length > 0
	);
}

const { prcFirebaseConfig, prcFirebaseInteractivesConfig } = window;

if (isUsableFirebaseConfig(prcFirebaseConfig)) {
	loadScript('firebase', firebase.initializeApp(prcFirebaseConfig));
	loadScript('firebaseDb', firebase.database());
	loadScript('firebaseAuth', firebase.auth());
}

if (isUsableFirebaseConfig(prcFirebaseInteractivesConfig)) {
	loadScript(
		'interactivesDb',
		firebase.initializeApp(prcFirebaseInteractivesConfig, 'interactivesDb')
	);
}

window.interactivesDB = window.interactivesDb;
