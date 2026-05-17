( function ( pluginCheck ) {
	const checkItButton = document.getElementById( 'plugin-check__submit' );
	const resultsContainer = document.getElementById( 'plugin-check__results' );
	const exportContainer = document.getElementById(
		'plugin-check__export-controls'
	);
	const spinner = document.getElementById( 'plugin-check__spinner' );
	const pluginsList = document.getElementById(
		'plugin-check__plugins-dropdown'
	);
	const categoriesList = document.querySelectorAll(
		'input[name=categories]'
	);
	const typesList = document.querySelectorAll( 'input[name=types]' );
	const templates = {};

	// Return early if the elements cannot be found on the page.
	if (
		! checkItButton ||
		! pluginsList ||
		! resultsContainer ||
		! exportContainer ||
		! spinner ||
		! categoriesList.length ||
		! typesList.length
	) {
		console.error( 'Missing form elements on page' );
		return;
	}

	let aggregatedResults = createEmptyAggregatedResults();
	let checksCompleted = false;
	exportContainer.classList.add( 'is-hidden' );
	exportContainer.addEventListener( 'click', onExportContainerClick );

	const includeExperimental = document.getElementById(
		'plugin-check__include-experimental'
	);
	const useAi = document.getElementById( 'plugin-check__use-ai' );

	// Handle disabling the Check it button when a plugin is not selected.
	function canRunChecks() {
		if ( '' === pluginsList.value ) {
			checkItButton.disabled = true;
		} else {
			checkItButton.disabled = false;
		}
	}

	// Run on page load to test if dropdown is auto populated.
	canRunChecks();
	pluginsList.addEventListener( 'change', canRunChecks );

	function saveUserSettings() {
		const selectedCategories = [];

		// Assuming you have a list of category checkboxes, find the selected ones.
		categoriesList.forEach( function ( checkbox ) {
			if ( checkbox.checked ) {
				selectedCategories.push( checkbox.value );
			}
		} );

		// Join the selected category slugs with '__' and save it as a user setting.
		const settingValue = selectedCategories.join( '__' );
		window.setUserSetting(
			'plugin_check_category_preferences',
			settingValue
		);
	}

	// Attach the saveUserSettings function when a category checkbox is clicked.
	categoriesList.forEach( function ( checkbox ) {
		checkbox.addEventListener( 'change', saveUserSettings );
	} );

	// When the Check it button is clicked.
	checkItButton.addEventListener( 'click', ( e ) => {
		e.preventDefault();

		resetResults();
		checkItButton.disabled = true;
		pluginsList.disabled = true;
		spinner.classList.add( 'is-active' );
		for ( let i = 0; i < categoriesList.length; i++ ) {
			categoriesList[ i ].disabled = true;
		}
		for ( let i = 0; i < typesList.length; i++ ) {
			typesList[ i ].disabled = true;
		}
		if ( useAi ) {
			useAi.disabled = true;
		}
		if ( includeExperimental ) {
			includeExperimental.disabled = true;
		}

		getChecksToRun()
			.then( setUpEnvironment )
			.then( runChecks )
			.then( cleanUpEnvironment )
			.then( ( data ) => {
				console.log( data.message );

				resetForm();
			} )
			.catch( ( error ) => {
				console.error( error );

				resetForm();
			} );
	} );

	/**
	 * Reset the results container.
	 *
	 * @since 1.0.0
	 */
	function resetResults() {
		// Empty the results container.
		resultsContainer.innerText = '';
		exportContainer.innerHTML = '';
		exportContainer.classList.add( 'is-hidden' );
		resetAggregatedResults();
		checksCompleted = false;
	}

	/**
	 * Resets the form controls once checks have completed or failed.
	 *
	 * @since 1.0.0
	 */
	function resetForm() {
		spinner.classList.remove( 'is-active' );
		checkItButton.disabled = false;
		pluginsList.disabled = false;
		for ( let i = 0; i < categoriesList.length; i++ ) {
			categoriesList[ i ].disabled = false;
		}
		for ( let i = 0; i < typesList.length; i++ ) {
			typesList[ i ].disabled = false;
		}
		if ( useAi ) {
			useAi.disabled = false;
		}
		if ( includeExperimental ) {
			includeExperimental.disabled = false;
		}
	}

	function createEmptyAggregatedResults() {
		return {
			errors: {},
			warnings: {},
		};
	}

	function resetAggregatedResults() {
		aggregatedResults = createEmptyAggregatedResults();
	}

	function mergeAggregatedResults( results ) {
		if ( results.errors ) {
			mergeResultTree( aggregatedResults.errors, results.errors );
		}
		if ( results.warnings ) {
			mergeResultTree( aggregatedResults.warnings, results.warnings );
		}
	}

	function hasOwn( object, key ) {
		return Object.prototype.hasOwnProperty.call( object, key );
	}

	function mergeResultTree( target, source ) {
		for ( const file of Object.keys( source ) ) {
			if ( ! hasOwn( target, file ) ) {
				target[ file ] = {};
			}

			const sourceFile = source[ file ];
			const targetFile = target[ file ];

			for ( const line of Object.keys( sourceFile ) ) {
				if ( ! hasOwn( targetFile, line ) ) {
					targetFile[ line ] = {};
				}

				const sourceLine = sourceFile[ line ];
				const targetLine = targetFile[ line ];

				for ( const column of Object.keys( sourceLine ) ) {
					if ( ! hasOwn( targetLine, column ) ) {
						targetLine[ column ] = [];
					}

					for ( const entry of sourceLine[ column ] ) {
						targetLine[ column ].push( cloneResultEntry( entry ) );
					}
				}
			}
		}
	}

	function cloneResultEntry( entry ) {
		return { ...entry };
	}

	function hasAggregatedResults() {
		return (
			hasEntries( aggregatedResults.errors ) ||
			hasEntries( aggregatedResults.warnings )
		);
	}

	function hasEntries( tree ) {
		for ( const file of Object.keys( tree ) ) {
			const lines = tree[ file ] || {};

			for ( const line of Object.keys( lines ) ) {
				const columns = lines[ line ] || {};

				for ( const column of Object.keys( columns ) ) {
					if ( ( columns[ column ] || [] ).length > 0 ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Counts the total number of errors and warnings in the aggregated results.
	 *
	 * @since 1.9.0
	 *
	 * @return {Object} Object with errorCount and warningCount properties.
	 */
	function countResults() {
		let errorCount = 0;
		let warningCount = 0;

		// Count errors.
		for ( const file of Object.keys( aggregatedResults.errors ) ) {
			const lines = aggregatedResults.errors[ file ] || {};

			for ( const line of Object.keys( lines ) ) {
				const columns = lines[ line ] || {};

				for ( const column of Object.keys( columns ) ) {
					errorCount += ( columns[ column ] || [] ).length;
				}
			}
		}

		// Count warnings.
		for ( const file of Object.keys( aggregatedResults.warnings ) ) {
			const lines = aggregatedResults.warnings[ file ] || {};

			for ( const line of Object.keys( lines ) ) {
				const columns = lines[ line ] || {};

				for ( const column of Object.keys( columns ) ) {
					warningCount += ( columns[ column ] || [] ).length;
				}
			}
		}

		return { errorCount, warningCount };
	}

	function defaultString( key ) {
		if (
			pluginCheck.strings &&
			Object.prototype.hasOwnProperty.call( pluginCheck.strings, key )
		) {
			return pluginCheck.strings[ key ];
		}
		// Return empty string if localized string is missing.
		return '';
	}

	function renderExportButtons() {
		exportContainer.innerHTML = '';
		if ( ! checksCompleted ) {
			exportContainer.classList.add( 'is-hidden' );
			return;
		}

		exportContainer.classList.remove( 'is-hidden' );

		const exportButtonConfigs = [
			{
				format: 'csv',
				label: defaultString( 'exportCsv' ),
			},
			{
				format: 'json',
				label: defaultString( 'exportJson' ),
			},
			{
				format: 'ctrf',
				label: defaultString( 'exportCtrf' ),
			},
			{
				format: 'markdown',
				label: defaultString( 'exportMarkdown' ),
			},
		];

		exportButtonConfigs.forEach( ( item ) => {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.classList.add(
				'button',
				'button-secondary',
				'plugin-check__export-button'
			);
			button.textContent = item.label;
			button.setAttribute( 'data-export-format', item.format );
			exportContainer.appendChild( button );
		} );
	}

	function announce( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message );
			return;
		}

		console.warn( message );
	}

	function onExportContainerClick( event ) {
		const button = event.target.closest( '[data-export-format]' );
		if ( ! button || button.disabled ) {
			return;
		}

		event.preventDefault();
		handleExport( button );
	}

	function handleExport( button ) {
		if ( ! hasAggregatedResults() ) {
			announce( defaultString( 'noResults' ) );
			return;
		}

		const format = button.getAttribute( 'data-export-format' );
		if ( ! format ) {
			return;
		}

		const originalText = button.textContent;
		button.disabled = true;
		button.textContent = defaultString( 'exporting' );

		requestExport( format )
			.then( ( payload ) => {
				downloadExport( payload );
			} )
			.catch( ( error ) => {
				console.error( error );
				const failureMessage = defaultString( 'exportError' );
				announce( failureMessage );
			} )
			.finally( () => {
				button.disabled = false;
				button.textContent = originalText;
			} );
	}

	function requestExport( format ) {
		const payload = new FormData();
		payload.append( 'nonce', pluginCheck.nonce );
		payload.append( 'action', pluginCheck.actionExportResults );
		payload.append( 'format', format );
		if ( pluginsList.value ) {
			payload.append( 'plugin', pluginsList.value );
		}
		payload.append( 'plugin_label', getSelectedPluginLabel() );
		payload.append( 'results', JSON.stringify( aggregatedResults ) );

		return fetch( ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: payload,
		} )
			.then( ( response ) => response.json() )
			.then( ( responseData ) => {
				if ( ! responseData ) {
					throw new Error( 'Response contains no data' );
				}

				if ( ! responseData.success ) {
					const defaultExportErrorMessage =
						defaultString( 'exportError' );
					let message = defaultExportErrorMessage;
					if ( responseData.data && responseData.data.message ) {
						message = responseData.data.message;
					}
					throw new Error( message );
				}

				if (
					! responseData.data ||
					! responseData.data.content ||
					! responseData.data.filename
				) {
					throw new Error( 'Export payload is incomplete' );
				}

				return responseData.data;
			} );
	}

	function downloadExport( exportPayload ) {
		const blob = new Blob( [ exportPayload.content ], {
			type: exportPayload.mime_type || 'text/plain',
		} );
		const downloadLink = document.createElement( 'a' );
		downloadLink.href = window.URL.createObjectURL( blob );
		downloadLink.download = exportPayload.filename;
		document.body.appendChild( downloadLink );
		downloadLink.click();
		document.body.removeChild( downloadLink );
		window.URL.revokeObjectURL( downloadLink.href );
	}

	function getSelectedPluginLabel() {
		const selectedIndex = pluginsList.selectedIndex;
		if ( selectedIndex < 0 ) {
			return '';
		}
		return pluginsList.options[ selectedIndex ].text;
	}

	/**
	 * Setup the runtime environment if needed.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} data Data object with props passed to form data.
	 */
	function setUpEnvironment( data ) {
		const pluginCheckData = new FormData();
		pluginCheckData.append( 'nonce', pluginCheck.nonce );
		pluginCheckData.append( 'plugin', data.plugin );
		pluginCheckData.append(
			'action',
			pluginCheck.actionSetUpRuntimeEnvironment
		);
		pluginCheckData.append(
			'include-experimental',
			includeExperimental && includeExperimental.checked ? 1 : 0
		);
		pluginCheckData.append(
			'use-ai',
			useAi && useAi.checked ? 1 : 0
		);

		for ( let i = 0; i < data.checks.length; i++ ) {
			pluginCheckData.append( 'checks[]', data.checks[ i ] );
		}

		return fetch( ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: pluginCheckData,
		} )
			.then( ( response ) => {
				return response.json();
			} )
			.then( handleDataErrors )
			.then( ( responseData ) => {
				if ( ! responseData.data || ! responseData.data.message ) {
					throw new Error( 'Response contains no data.' );
				}

				console.log( responseData.data.message );

				return responseData.data;
			} );
	}

	/**
	 * Cleanup the runtime environment.
	 *
	 * @since 1.0.0
	 *
	 * @return {Object} The response data.
	 */
	function cleanUpEnvironment() {
		const pluginCheckData = new FormData();
		pluginCheckData.append( 'nonce', pluginCheck.nonce );
		pluginCheckData.append(
			'action',
			pluginCheck.actionCleanUpRuntimeEnvironment
		);

		return fetch( ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: pluginCheckData,
		} )
			.then( ( response ) => {
				return response.json();
			} )
			.then( handleDataErrors )
			.then( ( responseData ) => {
				if ( ! responseData.data || ! responseData.data.message ) {
					throw new Error( 'Response contains no data.' );
				}

				return responseData.data;
			} );
	}

	/**
	 * Get the Checks to run.
	 *
	 * @since 1.0.0
	 */
	function getChecksToRun() {
		const pluginCheckData = new FormData();
		pluginCheckData.append( 'nonce', pluginCheck.nonce );
		pluginCheckData.append( 'plugin', pluginsList.value );
		pluginCheckData.append( 'action', pluginCheck.actionGetChecksToRun );
		pluginCheckData.append(
			'include-experimental',
			includeExperimental && includeExperimental.checked ? 1 : 0
		);
		pluginCheckData.append(
			'use-ai',
			useAi && useAi.checked ? 1 : 0
		);

		for ( let i = 0; i < categoriesList.length; i++ ) {
			if ( categoriesList[ i ].checked ) {
				pluginCheckData.append(
					'categories[]',
					categoriesList[ i ].value
				);
			}
		}

		return fetch( ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: pluginCheckData,
		} )
			.then( ( response ) => {
				return response.json();
			} )
			.then( handleDataErrors )
			.then( ( responseData ) => {
				if (
					! responseData.data ||
					! responseData.data.plugin ||
					! responseData.data.checks
				) {
					throw new Error(
						'Plugin and Checks are missing from the response.'
					);
				}

				return responseData.data;
			} );
	}

	/**
	 * Run Checks.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} data The response data.
	 */
	async function runChecks( data ) {
		let isSuccessMessage = true;
		let aiStats = null;
		for ( let i = 0; i < data.checks.length; i++ ) {
			try {
				const results = await runCheck( data.plugin, data.checks[ i ] );
				const errorsLength = Object.values( results.errors ).length;
				const warningsLength = Object.values( results.warnings ).length;
				if (
					isSuccessMessage &&
					( errorsLength > 0 || warningsLength > 0 )
				) {
					isSuccessMessage = false;
				}
				mergeAggregatedResults( results );
				renderResults( results );

				// Collect AI stats across checks.
				if ( results.ai_stats ) {
					if ( ! aiStats ) {
						aiStats = {
							tokens_spent: 0,
							input_tokens: 0,
							output_tokens: 0,
							false_positives: 0,
							issues_analyzed: 0,
							models_used: [],
							providers_used: [],
						};
					}
					aiStats.tokens_spent += results.ai_stats.tokens_spent || 0;
					aiStats.input_tokens += results.ai_stats.input_tokens || 0;
					aiStats.output_tokens +=
						results.ai_stats.output_tokens || 0;
					aiStats.false_positives +=
						results.ai_stats.false_positives || 0;
					aiStats.issues_analyzed +=
						results.ai_stats.issues_analyzed || 0;
					if ( results.ai_stats.model_used ) {
						aiStats.models_used.push( results.ai_stats.model_used );
					}
					if ( results.ai_stats.provider_used ) {
						aiStats.providers_used.push(
							results.ai_stats.provider_used
						);
					}
				}
			} catch {
				// Ignore for now.
			}
		}

		renderResultsMessage( isSuccessMessage, aiStats );
	}

	/**
	 * Renders result message.
	 *
	 * @since 1.0.0
	 *
	 * @param {boolean} isSuccessMessage Whether the message is a success message.
	 * @param {Object}  aiStats          AI statistics.
	 */
	function renderResultsMessage( isSuccessMessage, aiStats ) {
		// Count errors and warnings to determine notice severity and compose the message.
		const { errorCount, warningCount } = isSuccessMessage
			? { errorCount: 0, warningCount: 0 }
			: countResults();

		// Derive notice type from actual counts: errors → error, warnings-only → warning, none → success.
		let messageType;
		if ( errorCount > 0 ) {
			messageType = 'error';
		} else if ( warningCount > 0 ) {
			messageType = 'warning';
		} else {
			messageType = 'success';
		}

		let messageText;

		if ( isSuccessMessage ) {
			messageText = pluginCheck.successMessage;
		} else {
			/**
			 * Substitutes printf-style placeholders in a translated string.
			 * Handles both simple (%d, %s) and positional (%1$d, %2$s) placeholders.
			 *
			 * @param {string}    template The translated format string.
			 * @param {...string} args     Replacement values.
			 * @return {string} Formatted string with placeholders replaced.
			 */
			function sprintfReplace( template, ...args ) {
				let i = 0;
				return template.replace(
					/%(\d+\$)?[ds]/g,
					function ( _match, pos ) {
						const index = pos ? parseInt( pos, 10 ) - 1 : i++;
						return args[ index ] !== undefined
							? args[ index ]
							: _match;
					}
				);
			}

			// Build the individual count parts with proper plural/singular forms.
			let errorPart = '';
			if ( errorCount > 0 ) {
				errorPart = sprintfReplace(
					errorCount === 1
						? pluginCheck.errorString
						: pluginCheck.errorsString,
					errorCount
				);
			}

			let warningPart = '';
			if ( warningCount > 0 ) {
				warningPart = sprintfReplace(
					warningCount === 1
						? pluginCheck.warningString
						: pluginCheck.warningsString,
					warningCount
				);
			}

			// Assemble the final sentence from fully translatable PHP-provided templates
			// so that word order and connector phrases can be adapted for all languages.
			if ( errorPart && warningPart ) {
				messageText = sprintfReplace(
					pluginCheck.summaryBothTemplate,
					errorPart,
					warningPart
				);
			} else if ( errorPart ) {
				messageText = sprintfReplace(
					pluginCheck.summarySingleTemplate,
					errorPart
				);
			} else if ( warningPart ) {
				messageText = sprintfReplace(
					pluginCheck.summarySingleTemplate,
					warningPart
				);
			} else {
				// Fallback to default message if somehow no errors/warnings.
				messageText = pluginCheck.errorMessage;
			}
		}

		if ( aiStats ) {
			const aiParts = [];
			const modelsUsed = [
				...new Set( aiStats.models_used.filter( Boolean ) ),
			];
			const providersUsed = [
				...new Set( aiStats.providers_used.filter( Boolean ) ),
			];

			if ( aiStats.false_positives > 0 ) {
				aiParts.push(
					'AI detected ' +
						aiStats.false_positives +
						' ' +
						( 1 === aiStats.false_positives
							? 'false positive'
							: 'false positives' )
				);
			}
			if ( aiStats.input_tokens > 0 ) {
				aiParts.push(
					'Input tokens: ' + aiStats.input_tokens.toLocaleString()
				);
			}
			if ( aiStats.output_tokens > 0 ) {
				aiParts.push(
					'Output tokens: ' + aiStats.output_tokens.toLocaleString()
				);
			}
			if ( aiStats.tokens_spent > 0 ) {
				aiParts.push(
					'Tokens spent: ' + aiStats.tokens_spent.toLocaleString()
				);
			}
			if ( modelsUsed.length > 0 || providersUsed.length > 0 ) {
				if ( 1 === modelsUsed.length && 1 === providersUsed.length ) {
					aiParts.push(
						'Model: ' + providersUsed[ 0 ] + ' ' + modelsUsed[ 0 ]
					);
				} else if (
					modelsUsed.length > 0 &&
					providersUsed.length > 0
				) {
					aiParts.push(
						'Model: ' +
							providersUsed.join( ', ' ) +
							' ' +
							modelsUsed.join( ', ' )
					);
				} else if ( modelsUsed.length > 0 ) {
					aiParts.push( 'Model: ' + modelsUsed.join( ', ' ) );
				} else {
					aiParts.push( 'Model: ' + providersUsed.join( ', ' ) );
				}
			}
			if ( aiParts.length > 0 ) {
				messageText += /[.!?]\s*$/.test( messageText ) ? ' ' : '. ';
				messageText += aiParts.join( '. ' );
			}
		}

		resultsContainer.innerHTML =
			renderTemplate( 'plugin-check-results-complete', {
				type: messageType,
				message: messageText,
			} ) + resultsContainer.innerHTML;

		checksCompleted = true;
		renderExportButtons();
	}

	/**
	 * Run a single check.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} plugin The plugin to check.
	 * @param {string} check  The check to run.
	 * @return {Object} The check results.
	 */
	function runCheck( plugin, check ) {
		const pluginCheckData = new FormData();
		pluginCheckData.append( 'nonce', pluginCheck.nonce );
		pluginCheckData.append( 'plugin', plugin );
		pluginCheckData.append( 'checks[]', check );
		pluginCheckData.append( 'action', pluginCheck.actionRunChecks );
		pluginCheckData.append(
			'include-experimental',
			includeExperimental && includeExperimental.checked ? 1 : 0
		);
		pluginCheckData.append(
			'use-ai',
			useAi && useAi.checked ? 1 : 0
		);

		for ( let i = 0; i < typesList.length; i++ ) {
			if ( typesList[ i ].checked ) {
				pluginCheckData.append( 'types[]', typesList[ i ].value );
			}
		}

		return fetch( ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: pluginCheckData,
		} )
			.then( ( response ) => {
				return response.json();
			} )
			.then( handleDataErrors )
			.then( ( responseData ) => {
				// If the response is successful and there is no message in the response.
				if ( ! responseData.data || ! responseData.data.message ) {
					throw new Error( 'Response contains no data' );
				}

				// Debug: Log AI data if present.
				if ( responseData.data.ai_analysis ) {
					console.log( 'AI Analysis received:', responseData.data.ai_analysis );
				}
				if ( responseData.data.ai_stats ) {
					console.log( 'AI Stats received:', responseData.data.ai_stats );
				}

				return responseData.data;
			} );
	}

	/**
	 * Handles any errors in the data returned from the response.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} data The response data.
	 * @return {Object} The response data.
	 */
	function handleDataErrors( data ) {
		if ( ! data ) {
			throw new Error( 'Response contains no data' );
		}

		if ( ! data.success ) {
			// If not successful and no message in the response.
			if ( ! data.data || ! data.data[ 0 ].message ) {
				throw new Error( 'Response contains no data' );
			}

			// If not successful and there is a message in the response.
			throw new Error( data.data[ 0 ].message );
		}

		return data;
	}

	/**
	 * Renders results for each check on the page.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} results The results object.
	 */
	function renderResults( results ) {
		const { errors, warnings, ai_analysis } = results || {};

		// Debug: Log AI analysis data if available.
		if ( ai_analysis && typeof ai_analysis === 'object' && Object.keys( ai_analysis ).length > 0 ) {
			console.log( 'AI Analysis data in renderResults:', ai_analysis );
		}
		// Render errors and warnings for files.
		for ( const file in errors ) {
			if ( warnings[ file ] ) {
				renderFileResults( file, errors[ file ], warnings[ file ], ai_analysis );
				delete warnings[ file ];
			} else {
				renderFileResults( file, errors[ file ], [], ai_analysis );
			}
		}

		// Render remaining files with only warnings.
		for ( const file in warnings ) {
			renderFileResults( file, [], warnings[ file ], ai_analysis );
		}
	}

	/**
	 * Renders the file results table.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} file        The file name for the results.
	 * @param {Object} errors      The file errors.
	 * @param {Object} warnings    The file warnings.
	 * @param {Object} ai_analysis AI analysis results.
	 */
	function renderFileResults( file, errors, warnings, ai_analysis ) {
		const index =
			Date.now().toString( 36 ) +
			Math.random().toString( 36 ).substr( 2 );

		// Check if any errors or warnings have links.
		const hasLinks =
			hasLinksInResults( errors ) || hasLinksInResults( warnings );

		// Render the file table.
		resultsContainer.innerHTML += renderTemplate(
			'plugin-check-results-table',
			{ file, index, hasLinks }
		);
		const resultsTable = document.getElementById(
			'plugin-check__results-body-' + index
		);

		// Render results to the table.
		renderResultRows( 'ERROR', errors, resultsTable, hasLinks, ai_analysis, file );
		renderResultRows( 'WARNING', warnings, resultsTable, hasLinks, ai_analysis, file );
	}

	/**
	 * Checks if there are any links in the results object.
	 *
	 * @since 1.0.0
	 *
	 * @param {Object} results The results object.
	 * @return {boolean} True if there are links, false otherwise.
	 */
	function hasLinksInResults( results ) {
		for ( const line in results ) {
			for ( const column in results[ line ] ) {
				for ( let i = 0; i < results[ line ][ column ].length; i++ ) {
					if ( results[ line ][ column ][ i ].link ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Renders a result row onto the file table.
	 *
	 * @since 1.0.0
	 *
	 * @param {string}  type        The result type. Either ERROR or WARNING.
	 * @param {Object}  results     The results object.
	 * @param {Object}  table       The HTML table to append a result row to.
	 * @param {boolean} hasLinks    Whether any result has links.
	 * @param {Object}  ai_analysis AI analysis results.
	 * @param {string}  file        The file path.
	 */
	function renderResultRows( type, results, table, hasLinks, ai_analysis, file ) {
		// Loop over each result by the line, column and messages.
		for ( const line in results ) {
			for ( const column in results[ line ] ) {
				for ( let i = 0; i < results[ line ][ column ].length; i++ ) {
					const message = results[ line ][ column ][ i ].message;
					const docs = results[ line ][ column ][ i ].docs;
					const code = results[ line ][ column ][ i ].code;
					const link = results[ line ][ column ][ i ].link;

					// Find AI analysis for this issue.
					let aiData = null;
					if ( ai_analysis && typeof ai_analysis === 'object' ) {
						// Try to find by file, line, column, and code match.
						// ai_analysis is an object where keys are MD5 hashes and values are analysis data.
						const analysisEntries = Object.values( ai_analysis );
						aiData = analysisEntries.find( function( analysis ) {
							if ( ! analysis || typeof analysis !== 'object' ) {
								return false;
							}
							// Normalize values for comparison.
							const analysisFile = String( analysis.file || '' );
							const currentFile = String( file || '' );
							const analysisLine = parseInt( analysis.line, 10 );
							const currentLine = parseInt( line, 10 );
							const analysisColumn = parseInt( analysis.column, 10 );
							const currentColumn = parseInt( column, 10 );
							const analysisCode = String( analysis.code || '' );
							const currentCode = String( code || '' );

							const fileMatch = analysisFile === currentFile;
							const lineMatch = analysisLine === currentLine;
							const columnMatch = analysisColumn === currentColumn;
							const codeMatch = analysisCode === currentCode;

							if ( fileMatch && lineMatch && columnMatch && codeMatch ) {
								console.log( 'AI match found:', {
									file: currentFile,
									line: currentLine,
									column: currentColumn,
									code: currentCode,
									analysis: analysis,
								} );
								return true;
							}

							return false;
						} ) || null;
					}

					const rowData = {
						line,
						column,
						type,
						message,
						docs,
						code,
						link,
						hasLinks,
					};

					// Add AI analysis data if available.
					if ( aiData ) {
						rowData.ai_analysis = aiData;
					}

					table.innerHTML += renderTemplate(
						'plugin-check-results-row',
						rowData
					);
				}
			}
		}
	}

	/**
	 * Generates a unique key for an issue.
	 *
	 * @since 1.8.0
	 *
	 * @param {string} file   File path.
	 * @param {number} line   Line number.
	 * @param {number} column Column number.
	 * @param {string} code   Issue code.
	 * @return {string} Unique key.
	 */
	function getIssueKey( file, line, column, code ) {
		const str = file + ':' + line + ':' + column + ':' + code;
		// Simple MD5-like hash (using built-in hash if available, otherwise a simple hash).
		let hash = 0;
		for ( let i = 0; i < str.length; i++ ) {
			const char = str.charCodeAt( i );
			hash = ( hash << 5 ) - hash + char;
			hash = hash & hash; // Convert to 32bit integer.
		}
		return hash.toString( 36 );
	}

	/**
	 * Renders the template with data.
	 *
	 * @since 1.0.0
	 *
	 * @param {string} templateSlug The template slug
	 * @param {Object} data         Template data.
	 * @return {string} Template HTML.
	 */
	function renderTemplate( templateSlug, data ) {
		if ( ! templates[ templateSlug ] ) {
			templates[ templateSlug ] = wp.template( templateSlug );
		}
		const template = templates[ templateSlug ];
		return template( data );
	}
} )( PLUGIN_CHECK );
