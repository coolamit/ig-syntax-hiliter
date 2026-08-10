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
	 * Fills a translated string's count into it.
	 *
	 * Every %d and %1$d in the string is replaced, and a string carrying neither
	 * gets the count put on the end, so a mistranslated string costs a clumsy
	 * sentence rather than a placeholder on screen or a count nobody is shown.
	 *
	 * @param {string} template String the count goes into.
	 * @param {number} count    Number to put in it.
	 *
	 * @return {string} The string, with the count in it.
	 */
	function withCount( template, count ) {
		var text = String( template || '' );
		var filled = text.replace( /%(?:\d+\$)?d/g, String( count ) );

		if ( filled !== text ) {
			return filled;
		}

		return '' === text ? String( count ) : text + ' ' + String( count );
	}

	/**
	 * Adds one clause to the report.
	 *
	 * Built as a node rather than as markup, so that no translated string can
	 * carry any.
	 *
	 * @param {HTMLElement} status    Element the report is written to.
	 * @param {string}      text      Clause to add.
	 * @param {boolean}     isWarning Whether the clause names code which will be lost.
	 */
	function appendClause( status, text, isWarning ) {
		var clause = document.createElement( 'span' );

		if ( isWarning ) {
			clause.className = 'igsh-uninstall__warning';
		}

		clause.textContent = ' ' + String( text || '' );

		status.appendChild( clause );
	}

	/**
	 * Writes the closing report of a conversion run.
	 *
	 * @param {HTMLElement} status Element the report is written to.
	 * @param {Object}      totals Running totals from every batch.
	 */
	function reportRevert( status, totals ) {
		status.textContent = withCount( strings.revertDone, totals.converted );

		if ( totals.skipped ) {
			appendClause( status, withCount( strings.revertDoneLeft, totals.skipped ), false );
		}

		if ( totals.blocksLeftAlone ) {
			appendClause( status, withCount( strings.revertDoneBlocks, totals.blocksLeftAlone ), true );
		}

		if ( totals.failed ) {
			appendClause( status, withCount( strings.revertDoneFailed, totals.failed ), true );
		}

		if ( totals.partial ) {
			appendClause( status, strings.revertDonePartial, false );
		}
	}

	/**
	 * Reads one count out of a batch's answer.
	 *
	 * @param {*} value Value the answer carried.
	 *
	 * @return {number|null} The count, or NULL when the answer carried no number.
	 */
	function readCount( value ) {
		var count = Number( value );

		if ( null === value || '' === value || ! isFinite( count ) ) {
			return null;
		}

		return count;
	}

	/**
	 * Adds one of a batch's counts to the running totals.
	 *
	 * A count the answer did not carry leaves the total where it was and marks the
	 * totals short, so the report can say that it is missing something instead of
	 * quietly leaving a clause out.
	 *
	 * @param {Object} totals Running totals to add to.
	 * @param {string} name   Total to add to.
	 * @param {*}      value  Value the answer carried.
	 */
	function addCount( totals, name, value ) {
		var count = readCount( value );

		if ( null === count ) {
			totals.partial = true;

			return;
		}

		totals[ name ] += count;
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

		var totals = {
			processed: 0,
			converted: 0,
			skipped: 0,
			failed: 0,
			blocksLeftAlone: 0,
			partial: false,
		};

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
				var processed = readCount( batch.processed );

				addCount( totals, 'processed', batch.processed );
				addCount( totals, 'converted', batch.converted );
				addCount( totals, 'skipped', batch.skipped );
				addCount( totals, 'failed', batch.failed );
				addCount( totals, 'blocksLeftAlone', batch.blocks_left_alone );

				meter.value = Math.min( totals.processed, meter.max );

				status.textContent = strings.revertRunning;

				// An answer which does not say how much it did is the end of the run: there is nothing to carry on from.
				if ( batch.done || ! processed ) {
					reportRevert( status, totals );

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
