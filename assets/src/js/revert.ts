/**
 * The revert tool on the settings page: this plugin's blocks back to
 * shortcodes, one batch at a time.
 */

( function () {
	'use strict';

	/**
	 * What the revert route answers when asked how much there is to do.
	 */
	interface RevertState {
		total?: number | undefined;
	}

	/**
	 * What one converted batch answers with.
	 */
	interface RevertBatch {
		processed?: unknown;
		converted?: unknown;
		skipped?: unknown;
		failed?: unknown;
		blocks_left_alone?: unknown;
		done?: boolean | undefined;
		cursor?: number | undefined;
	}

	/**
	 * Running totals across every batch of a conversion run.
	 */
	interface RevertTotals {
		processed: number;
		converted: number;
		skipped: number;
		failed: number;
		blocksLeftAlone: number;
		partial: boolean;
	}

	/**
	 * The totals a count can be added to.
	 */
	type RevertCountName = Exclude< keyof RevertTotals, 'partial' >;

	const publishedApi = window.igshAdminApi;

	if ( ! publishedApi ) {
		return;
	}

	// Read once after the guard; the narrowing does not reach hoisted functions.
	const api = publishedApi;

	const config = window.igSyntaxHiliterAdmin;

	if ( ! config || ! config.restUrl ) {
		return;
	}

	const strings: IgshAdminStrings = config.i18n || ( {} as IgshAdminStrings );

	// Longer: a batch rewrites up to 200 posts.
	const REVERT_TIMEOUT_MS = 60000;

	/**
	 * Adds one clause to the report.
	 *
	 * Built as a node so no translated string can carry markup.
	 *
	 * @param status    Element the report is written to.
	 * @param text      Clause to add.
	 * @param isWarning Whether the clause names code which will be lost.
	 */
	function appendClause(
		status: HTMLElement,
		text: string,
		isWarning: boolean
	): void {
		const clause = document.createElement( 'span' );

		if ( isWarning ) {
			clause.className = 'igsh-revert__warning';
		}

		clause.textContent = ' ' + String( text || '' );

		status.appendChild( clause );
	}

	/**
	 * Writes the closing report of a conversion run.
	 *
	 * @param status Element the report is written to.
	 * @param totals Running totals from every batch.
	 */
	function reportRevert( status: HTMLElement, totals: RevertTotals ): void {
		status.textContent = api.withCount(
			strings.revertDone,
			totals.converted
		);

		if ( totals.skipped ) {
			appendClause(
				status,
				api.withCount( strings.revertDoneLeft, totals.skipped ),
				false
			);
		}

		if ( totals.blocksLeftAlone ) {
			appendClause(
				status,
				api.withCount(
					strings.revertDoneBlocks,
					totals.blocksLeftAlone
				),
				true
			);
		}

		if ( totals.failed ) {
			appendClause(
				status,
				api.withCount( strings.revertDoneFailed, totals.failed ),
				true
			);
		}

		if ( totals.partial ) {
			appendClause( status, strings.revertDonePartial, false );
		}
	}

	/**
	 * Reads one count out of a batch's answer.
	 *
	 * @param value Value the answer carried.
	 *
	 * @return The count, or NULL when the answer carried no number.
	 */
	function readCount( value: unknown ): number | null {
		const count = Number( value );

		if ( null === value || '' === value || ! isFinite( count ) ) {
			return null;
		}

		return count;
	}

	/**
	 * Adds one of a batch's counts to the running totals.
	 *
	 * A count the answer did not carry marks the totals short, so the report can
	 * say something is missing.
	 *
	 * @param totals Running totals to add to.
	 * @param name   Total to add to.
	 * @param value  Value the answer carried.
	 */
	function addCount(
		totals: RevertTotals,
		name: RevertCountName,
		value: unknown
	): void {
		const count = readCount( value );

		if ( null === count ) {
			totals.partial = true;

			return;
		}

		totals[ name ] += count;
	}

	/**
	 * Runs the block to shortcode conversion, one batch at a time.
	 *
	 * One lock over the GET and every POST.
	 *
	 * @param progress Wrapper holding the progress meter.
	 * @param meter    The progress meter itself.
	 * @param status   Element the running total is written to.
	 */
	function runRevert(
		progress: HTMLElement,
		meter: HTMLProgressElement,
		status: HTMLElement
	): void {
		// eslint-disable-next-line no-alert -- rewrites post_content site-wide and cannot be undone; a custom dialog would be one more thing to get wrong on a page that loads no libraries.
		if ( ! window.confirm( strings.revertConfirm ) ) {
			return;
		}

		const totals: RevertTotals = {
			processed: 0,
			converted: 0,
			skipped: 0,
			failed: 0,
			blocksLeftAlone: 0,
			partial: false,
		};

		status.textContent = strings.revertRunning;

		api.locked( function () {
			return api
				.request< RevertState >(
					'GET',
					'revert',
					api.REQUEST_TIMEOUT_MS
				)
				.then( function ( state ) {
					const total = state && state.total ? state.total : 0;

					if ( ! total ) {
						status.textContent = strings.revertNone;

						return null;
					}

					meter.max = total;
					meter.value = 0;
					progress.hidden = false;

					return nextBatch( 0 );
				} );
		} ).catch( function ( error: IgshRequestError ) {
			status.textContent = api.describeError(
				error,
				strings.revertFailed
			);
		} );

		/**
		 * Fetches and applies one batch, then the next.
		 *
		 * @param cursor Id of the last post already handled.
		 *
		 * @return Resolved once there is nothing left to do.
		 */
		function nextBatch( cursor: number ): Promise< null > {
			return api
				.request< RevertBatch >( 'POST', 'revert', REVERT_TIMEOUT_MS, {
					cursor,
				} )
				.then( function ( batch ) {
					const processed = readCount( batch.processed );

					addCount( totals, 'processed', batch.processed );
					addCount( totals, 'converted', batch.converted );
					addCount( totals, 'skipped', batch.skipped );
					addCount( totals, 'failed', batch.failed );
					addCount(
						totals,
						'blocksLeftAlone',
						batch.blocks_left_alone
					);

					meter.value = Math.min( totals.processed, meter.max );

					status.textContent = strings.revertRunning;

					// A batch answering no count ends the run.
					if ( batch.done || ! processed ) {
						reportRevert( status, totals );

						meter.value = meter.max;

						return null;
					}

					return nextBatch( batch.cursor ?? 0 );
				} );
		}
	}

	/**
	 * Wires the page up.
	 */
	function init(): void {
		const button = document.getElementById( 'igsh-revert-blocks' );
		const progress = document.getElementById( 'igsh-revert-progress' );
		const meter = document.getElementById(
			'igsh-revert-meter'
		) as HTMLProgressElement | null;
		const status = document.getElementById( 'igsh-revert-status' );

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

// EOF
