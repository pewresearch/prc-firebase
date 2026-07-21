/* eslint-disable import/prefer-default-export */
/* eslint-disable import/no-extraneous-dependencies */
import { initializeApp } from 'firebase/app';
import {
	getAuth,
	signInWithEmailAndPassword,
	onAuthStateChanged,
	signOut,
	signInAnonymously,
	sendSignInLinkToEmail,
	isSignInWithEmailLink,
	signInWithEmailLink,
	signInWithRedirect,
	getRedirectResult,
	linkWithCredential,
	EmailAuthProvider,
	GoogleAuthProvider,
	OAuthProvider,
	GithubAuthProvider,
	fetchSignInMethodsForEmail,
} from 'firebase/auth';
import {
	getDatabase,
	ref,
	push,
	set,
	update,
	remove,
	get,
	child,
	query,
	orderByChild,
	orderByKey,
	orderByValue,
	limitToFirst,
	limitToLast,
	startAt,
	endAt,
	equalTo,
	onValue,
	off,
	serverTimestamp,
	onChildAdded,
	onChildRemoved,
} from 'firebase/database';

/**
 * Read Firebase client config from the WP script-module data element.
 * Returns {} when the element is missing or JSON is invalid (common on
 * alpha/staging when Firebase constants are not defined).
 *
 * @return {Object} Firebase config object.
 */
function loadFirebaseConfig() {
	const el = document.getElementById('wp-script-module-data-@prc/firebase');
	if (!el?.textContent) {
		return {};
	}
	try {
		return JSON.parse(el.textContent);
	} catch (err) {
		console.error('loadFirebaseConfig error:', err);
		return {};
	}
}

/**
 * Whether config has a usable apiKey so initializeApp / getAuth won't throw
 * auth/invalid-api-key.
 *
 * @param {Object} config Firebase config from script-module data.
 * @return {boolean} True when apiKey is a non-empty string.
 */
function isUsableFirebaseConfig(config) {
	return (
		config && typeof config.apiKey === 'string' && config.apiKey.length > 0
	);
}

const firebaseConfig = loadFirebaseConfig();
let _app = null;
let _auth = null;

if (isUsableFirebaseConfig(firebaseConfig)) {
	_app = initializeApp(firebaseConfig);
	_auth = getAuth(_app);
}

const _signInWithEmailAndPassword = signInWithEmailAndPassword;
const _onAuthStateChanged = onAuthStateChanged;
const _signOut = signOut;
const _signInAnonymously = signInAnonymously;
const _sendSignInLinkToEmail = sendSignInLinkToEmail;
const _isSignInWithEmailLink = isSignInWithEmailLink;
const _signInWithEmailLink = signInWithEmailLink;
const _signInWithRedirect = signInWithRedirect;
const _getRedirectResult = getRedirectResult;
const _linkWithCredential = linkWithCredential;
const _EmailAuthProvider = EmailAuthProvider;
const _GoogleAuthProvider = GoogleAuthProvider;
const _OAuthProvider = OAuthProvider;
const _GithubAuthProvider = GithubAuthProvider;
const _fetchSignInMethodsForEmail = fetchSignInMethodsForEmail;
const _db = getDatabase;
const _ref = ref;
const _push = push;
const _set = set;
const _update = update;
const _remove = remove;
const _get = get;
const _child = child;
const _query = query;
const _orderByChild = orderByChild;
const _orderByKey = orderByKey;
const _orderByValue = orderByValue;
const _limitToFirst = limitToFirst;
const _limitToLast = limitToLast;
const _startAt = startAt;
const _endAt = endAt;
const _equalTo = equalTo;
const _onValue = onValue;
const _off = off;
const _serverTimestamp = serverTimestamp;
const _onChildAdded = onChildAdded;
const _onChildRemoved = onChildRemoved;
export {
	_app as app,
	_auth as auth,
	_signInWithEmailAndPassword as signInWithEmailAndPassword,
	_onAuthStateChanged as onAuthStateChanged,
	_signOut as signOut,
	_signInAnonymously as signInAnonymously,
	_sendSignInLinkToEmail as sendSignInLinkToEmail,
	_isSignInWithEmailLink as isSignInWithEmailLink,
	_signInWithEmailLink as signInWithEmailLink,
	_signInWithRedirect as signInWithRedirect,
	_getRedirectResult as getRedirectResult,
	_linkWithCredential as linkWithCredential,
	_EmailAuthProvider as EmailAuthProvider,
	_GoogleAuthProvider as GoogleAuthProvider,
	_OAuthProvider as OAuthProvider,
	_GithubAuthProvider as GithubAuthProvider,
	_fetchSignInMethodsForEmail as fetchSignInMethodsForEmail,
	_db as getDatabase,
	_ref as ref,
	_push as push,
	_set as set,
	_update as update,
	_remove as remove,
	_get as get,
	_child as child,
	_query as query,
	_orderByChild as orderByChild,
	_orderByKey as orderByKey,
	_orderByValue as orderByValue,
	_limitToFirst as limitToFirst,
	_limitToLast as limitToLast,
	_startAt as startAt,
	_endAt as endAt,
	_equalTo as equalTo,
	_onValue as onValue,
	_off as off,
	_serverTimestamp as serverTimestamp,
	_onChildAdded as onChildAdded,
	_onChildRemoved as onChildRemoved,
};
