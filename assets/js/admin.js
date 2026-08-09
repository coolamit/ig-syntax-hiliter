/**
 * Settings page behaviour for the iG:Syntax Hiliter plugin.
 *
 * No jQuery, no libraries: a fetch to the plugin's REST routes, a small live
 * region for feedback, and the browser's own tooltips and confirmation dialog.
 *
 * @package iG_Syntax_Hiliter
 */

( function () {
	'use strict';

	var config = window.igSyntaxHiliterAdmin;

	if ( ! config || ! config.restUrl ) {
		return;
	}

	var strings = config.i18n || {};
	var toast = null;
	var toastTimer = null;

	/**
	 * Shows a short message in the page's live region.
	 *
	 * @param {string}  message Message to show.
	 * @param {boolean} isError Whether the message reports a failure.
	 * @param {boolean} sticky  Whether the message stays until it is replaced.
	 */
	function notify( message, isError, sticky ) {
		if ( ! toast ) {
			toast = document.createElement( 'div' );
			toast.className = 'igsh-toast';
			toast.setAttribute( 'role', 'status' );
			toast.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toast );
		}

		window.clearTimeout( toastTimer );

		toast.textContent = message;
		toast.classList.toggle( 'igsh-toast--error', !! isError );
		toast.classList.add( 'igsh-toast--visible' );

		if ( ! sticky ) {
			toastTimer = window.setTimeout( function () {
				toast.classList.remove( 'igsh-toast--visible' );
			}, isError ? 6000 : 2500 );
		}
	}

	/**
	 * Calls one of the plugin's REST routes.
	 *
	 * @param {string} method Request method.
	 * @param {string} route  Route path, relative to the plugin's namespace.
	 * @param {Object} body   Optional request body.
	 *
	 * @return {Promise<Object>} The decoded response body.
	 */
	function request( method, route, body ) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				Accept: 'application/json',
			},
		};

		if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}

		return window.fetch( config.restUrl + route, options ).then( function ( response ) {
			return response.json().then(
				function ( payload ) {
					if ( response.ok ) {
						return payload;
					}

					var error = new Error( ( payload && payload.message ) || response.statusText );

					error.status = response.status;
					error.code = payload && payload.code;

					throw error;
				},
				function () {
					var error = new Error( response.statusText );

					error.status = response.status;

					throw error;
				}
			);
		} );
	}

	/**
	 * Reads the value a control currently stands for.
	 *
	 * @param {HTMLElement} control Control to read.
	 *
	 * @return {string} Value to store for the setting.
	 */
	function readControl( control ) {
		if ( control.dataset.igshToggle ) {
			return control.checked ? 'yes' : 'no';
		}

		return control.value;
	}

	/**
	 * Puts a control back to a value it held earlier.
	 *
	 * @param {HTMLElement} control Control to set.
	 * @param {string}      value   Value to set it to.
	 */
	function writeControl( control, value ) {
		if ( control.dataset.igshToggle ) {
			control.checked = 'yes' === value;

			return;
		}

		control.value = value;
	}

	/**
	 * Sends one setting, and puts the control back if it does not save.
	 *
	 * @param {HTMLElement} control Control which changed.
	 */
	function saveSetting( control ) {
		var name = control.dataset.igshOption;
		var value = readControl( control );
		var previous = control.dataset.igshPrevious;

		control.disabled = true;

		notify( strings.saving, false, true );

		request( 'POST', 'option', { name: name, value: value } )
			.then( function ( payload ) {
				control.dataset.igshPrevious = payload && payload.value ? payload.value : value;

				writeControl( control, control.dataset.igshPrevious );

				notify( ( payload && payload.message ) || strings.saved, false );
			} )
			.catch( function ( error ) {
				writeControl( control, previous );

				notify(
					403 === error.status && 'rest_cookie_invalid_nonce' === error.code
						? strings.reloadNeeded
						: strings.saveFailed + ' ' + error.message,
					true
				);
			} )
			.finally( function () {
				control.disabled = false;
			} );
	}

	/**
	 * Runs the block to shortcode conversion, one batch at a time.
	 *
	 * @param {HTMLElement} button   Button which started it.
	 * @param {HTMLElement} progress Wrapper holding the progress meter.
	 * @param {HTMLElement} meter    The progress meter itself.
	 * @param {HTMLElement} status   Element the running total is written to.
	 */
	function runRevert( button, progress, meter, status ) {
		if ( ! window.confirm( strings.revertConfirm ) ) {
			return;
		}

		var totals = { processed: 0, converted: 0, skipped: 0, failed: 0 };

		button.disabled = true;
		status.textContent = strings.revertRunning;

		request( 'GET', 'revert' )
			.then( function ( state ) {
				var total = state && state.total ? state.total : 0;

				if ( ! total ) {
					status.textContent = strings.revertNone;

					return null;
				}

				meter.max = total;
				meter.value = 0;
				progress.hidden = false;

				return nextBatch( 0 );
			} )
			.catch( function ( error ) {
				status.textContent = strings.revertFailed + ' ' + error.message;
			} )
			.finally( function () {
				button.disabled = false;
			} );

		/**
		 * Fetches and applies one batch, then the next.
		 *
		 * @param {number} cursor Id of the last post already handled.
		 *
		 * @return {Promise} Resolved once there is nothing left to do.
		 */
		function nextBatch( cursor ) {
			return request( 'POST', 'revert', { cursor: cursor } ).then( function ( batch ) {
				totals.processed += batch.processed;
				totals.converted += batch.converted;
				totals.skipped += batch.skipped;
				totals.failed += batch.failed;

				meter.value = Math.min( totals.processed, meter.max );

				status.textContent = strings.revertRunning;

				if ( batch.done || 0 === batch.processed ) {
					status.textContent = ( strings.revertDone || '' )
						.replace( '%1$d', totals.converted )
						.replace( '%2$d', totals.skipped + totals.failed );

					meter.value = meter.max;

					return null;
				}

				return nextBatch( batch.cursor );
			} );
		}
	}

	/**
	 * Wires the page up.
	 */
	function init() {
		var controls = document.querySelectorAll( '[data-igsh-option]' );

		Array.prototype.forEach.call( controls, function ( control ) {
			control.dataset.igshPrevious = readControl( control );

			control.addEventListener( 'change', function () {
				saveSetting( control );
			} );
		} );

		var button = document.getElementById( 'igsh-revert-blocks' );
		var progress = document.getElementById( 'igsh-revert-progress' );
		var meter = document.getElementById( 'igsh-revert-meter' );
		var status = document.getElementById( 'igsh-revert-status' );

		if ( button && progress && meter && status ) {
			button.addEventListener( 'click', function () {
				runRevert( button, progress, meter, status );
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();


//EOF
