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
	var busyElements = [];
	var pageBusy = false;

	/*
	 * How long a save is given before the page gives up on it. A save locks the
	 * whole screen, so a server which answers nothing at all would otherwise
	 * leave it locked until somebody thought to reload it.
	 */
	var SAVE_TIMEOUT_MS = 15000;

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
	 * A timeout is only worth having where the page is waiting on the answer with
	 * everything locked, so only the save asks for one; the revert runs for as
	 * long as its batches take and is given none. The clock is stopped whichever
	 * way the request ends, success or failure, so that a request which answered
	 * in time can never be aborted afterwards.
	 *
	 * A browser with no AbortController simply gets no timeout. That is the older
	 * behaviour and it is a safe one: the page still locks and still unlocks on
	 * every answer, it just has no way of giving up on silence.
	 *
	 * @param {string} method  Request method.
	 * @param {string} route   Route path, relative to the plugin's namespace.
	 * @param {Object} body    Optional request body.
	 * @param {number} timeout Optional milliseconds to wait before giving up.
	 *
	 * @return {Promise<Object>} The decoded response body.
	 */
	function request( method, route, body, timeout ) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				Accept: 'application/json',
			},
		};
		var controller = null;
		var timer = null;
		var expired = false;
		var pending;

		if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}

		if ( timeout && window.AbortController ) {
			controller = new window.AbortController();
			options.signal = controller.signal;

			timer = window.setTimeout( function () {
				expired = true;

				controller.abort();
			}, timeout );
		}

		/**
		 * Stops the clock, however the request ended.
		 */
		function stopClock() {
			if ( null !== timer ) {
				window.clearTimeout( timer );

				timer = null;
			}
		}

		/*
		 * A fetch which throws on its way out rather than returning a rejected
		 * promise would never reach the caller's .catch(), and the page would stay
		 * locked with nothing left to unlock it.
		 */
		try {
			pending = window.fetch( config.restUrl + route, options );
		} catch ( error ) {
			stopClock();

			return window.Promise.reject( error );
		}

		return pending
			.then( function ( response ) {
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
			} )
			.then(
				function ( payload ) {
					stopClock();

					return payload;
				},
				function ( error ) {
					stopClock();

					/*
					 * An abort this asked for is handed on as the timeout it was, and
					 * not as whatever the browser called it. The browser reports it as a
					 * DOMException named AbortError, but a caller which has to recognise
					 * it by that name is reading someone else's wording, and it cannot
					 * tell an abort of ours from one anything else on the page asked for.
					 * The message on it is never shown: a caller which times out words
					 * that for itself.
					 */
					if ( expired ) {
						var timedOut = new Error( 'timeout' );

						timedOut.isTimeout = true;

						throw timedOut;
					}

					throw error;
				}
			);
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
	 * Locks or unlocks the whole page for the length of a request.
	 *
	 * The screen saves one setting per request and all seven settings live in one
	 * stored array, so two saves in quick succession are two overlapping requests:
	 * the second re-reads that array out of the copy its own PHP process cached
	 * when it started, writes its own idea of it back, and silently undoes the
	 * first while both report success. Locking every control for the length of a
	 * save is what makes a second request impossible rather than merely unlikely.
	 *
	 * Unlocking puts back exactly the elements this disabled, which is why they
	 * are remembered rather than looked up again: a control already disabled for
	 * some reason of its own was never ours to switch on, and a blanket pass over
	 * the page would switch it on anyway.
	 *
	 * @param {boolean} isBusy Whether the page is working.
	 */
	function setBusy( isBusy ) {
		if ( ! isBusy ) {
			busyElements.forEach( function ( element ) {
				element.disabled = false;
			} );

			busyElements = [];
			pageBusy = false;

			return;
		}

		if ( pageBusy ) {
			return;
		}

		pageBusy = true;

		var elements = Array.prototype.slice.call( document.querySelectorAll( '[data-igsh-option]' ) );
		var button = document.getElementById( 'igsh-revert-blocks' );

		if ( button ) {
			elements.push( button );
		}

		elements.forEach( function ( element ) {
			if ( element.disabled ) {
				return;
			}

			element.disabled = true;

			busyElements.push( element );
		} );
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

		setBusy( true );

		notify( strings.saving, false, true );

		request( 'POST', 'option', { name: name, value: value }, SAVE_TIMEOUT_MS )
			.then( function ( payload ) {
				control.dataset.igshPrevious = payload && payload.value ? payload.value : value;

				writeControl( control, control.dataset.igshPrevious );

				notify( ( payload && payload.message ) || strings.saved, false );
			} )
			.catch( function ( error ) {
				var message = strings.saveFailed + ' ' + error.message;

				/*
				 * A save which timed out may still have been saved: the request was
				 * abandoned, not cancelled, and the site may well have written it after
				 * the page stopped listening. So the control goes back to what it showed
				 * before, as it does for any other failure, and the message says that
				 * what is on screen may no longer be what is stored.
				 */
				if ( error.isTimeout ) {
					message = strings.saveTimedOut;
				} else if ( 403 === error.status && 'rest_cookie_invalid_nonce' === error.code ) {
					message = strings.reloadNeeded;
				}

				writeControl( control, previous );

				notify( message, true );
			} )
			.finally( function () {
				setBusy( false );
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
			clause.className = 'igsh-revert__warning';
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
	 * This takes the same lock a save takes — it rewrites content, and a setting
	 * saved halfway through it has no business landing in the middle of that — but
	 * it is deliberately given no timeout. A run legitimately lasts as long as the
	 * site has posts to walk, and the button it disables is disabled by the lock,
	 * so it keeps no idea of its own about what is switched off.
	 *
	 * @param {HTMLElement} progress Wrapper holding the progress meter.
	 * @param {HTMLElement} meter    The progress meter itself.
	 * @param {HTMLElement} status   Element the running total is written to.
	 */
	function runRevert( progress, meter, status ) {
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

		setBusy( true );

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
				setBusy( false );
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
				runRevert( progress, meter, status );
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
