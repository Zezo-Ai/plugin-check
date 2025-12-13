/**
 * Plugin Check Namer tool.
 */

/* global pluginCheckNamer */

( function() {
	'use strict';

	function setText( el, text ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text;
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		const form = document.getElementById( 'plugin-check-namer-form' );
		const input = document.getElementById( 'plugin_check_namer_input' );

		if ( ! form || ! input || ! window.pluginCheckNamer ) {
			return;
		}

		const submitBtn = document.getElementById(
			'plugin-check-namer-submit'
		);
		const spinner = document.getElementById( 'plugin-check-namer-spinner' );

		const resultWrap = document.getElementById(
			'plugin-check-namer-result'
		);
		const verdictEl = document.getElementById(
			'plugin-check-namer-verdict'
		);
		const explainEl = document.getElementById(
			'plugin-check-namer-explanation'
		);
		const rawEl = document.getElementById( 'plugin-check-namer-raw' );
		const errorDiv = document.getElementById( 'plugin-check-namer-error' );
		const errorEl = errorDiv ? errorDiv.querySelector( 'p' ) : null;

		function setLoading( isLoading ) {
			if ( spinner ) {
				spinner.classList.toggle( 'is-active', isLoading );
			}
			submitBtn.disabled = isLoading;
		}

		form.addEventListener( 'submit', function( event ) {
			event.preventDefault();

			const name = ( input.value || '' ).trim();
			if ( ! name ) {
				setText( errorEl, pluginCheckNamer.messages.missingName );
				if ( errorEl ) {
					errorEl.style.display = 'block';
				}
				return;
			}

			if ( errorEl ) {
				errorEl.style.display = 'none';
				setText( errorEl, '' );
			}

			setLoading( true );

			const formData = new FormData();
			formData.append( 'action', 'plugin_check_namer_analyze' );
			formData.append( 'nonce', pluginCheckNamer.nonce );
			formData.append( 'plugin_name', name );

			fetch( pluginCheckNamer.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: formData,
			} )
				.then( function( response ) {
					return response.json();
				} )
				.then( function( payload ) {
					if ( ! payload || ! payload.success ) {
						throw new Error(
							payload &&
								payload.data &&
								payload.data.message
								? payload.data.message
								: pluginCheckNamer.messages.genericError
						);
					}

					setText( verdictEl, payload.data.verdict || '' );
					setText( explainEl, payload.data.explanation || '' );
					setText( rawEl, payload.data.raw || '' );

					if ( resultWrap ) {
						resultWrap.style.display = 'block';
					}
				} )
				.catch( function( err ) {
					setText(
						errorEl,
						err && err.message
							? err.message
							: pluginCheckNamer.messages.genericError
					);
					if ( errorEl ) {
						errorEl.style.display = 'block';
					}
				} )
				.finally( function() {
					setLoading( false );
				} );
		} );
	} );
} )();
