( function ( pluginCheck ) {
	const checkItButton = document.getElementById( 'plugin-check__submit' );
	const resultsContainer = document.getElementById( 'plugin-check__results' );
	const spinner = document.getElementById( 'plugin-check__spinner' );
	const pluginsList = document.getElementById(
		'plugin-check__plugins-dropdown'
	);
	const categoriesList = document.querySelectorAll(
		'input[name=categories]'
	);
	const templates = {};

	// Return early if the elements cannot be found on the page.
	if (
		! checkItButton ||
		! pluginsList ||
		! resultsContainer ||
		! spinner ||
		! categoriesList.length
	) {
		console.error( 'Missing form elements on page' );
		return;
	}

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
				renderResults( results );

				// Collect AI stats from the last check.
				if ( results.ai_stats ) {
					// Merge stats if multiple checks.
					if ( ! aiStats ) {
						aiStats = {
							tokens_spent: 0,
							false_positives: 0,
							issues_analyzed: 0,
						};
					}
					aiStats.tokens_spent += results.ai_stats.tokens_spent || 0;
					aiStats.false_positives += results.ai_stats.false_positives || 0;
					aiStats.issues_analyzed += results.ai_stats.issues_analyzed || 0;
				}
			} catch ( e ) {
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
		const messageType = isSuccessMessage ? 'success' : 'error';
		let messageText = isSuccessMessage
			? pluginCheck.successMessage
			: pluginCheck.errorMessage;

		// Add AI statistics to the message if available.
		if ( aiStats && aiStats.false_positives > 0 ) {
			let aiInfo = ' AI detected ' + aiStats.false_positives + ' ';
			aiInfo += ( 1 === aiStats.false_positives ) ? 'false positive' : 'false positives';
			if ( aiStats.tokens_spent > 0 ) {
				aiInfo += ' (Tokens spent: ' + aiStats.tokens_spent.toLocaleString() + ')';
			}
			messageText += '.' + aiInfo;
		}

		resultsContainer.innerHTML =
			renderTemplate( 'plugin-check-results-complete', {
				type: messageType,
				message: messageText,
			} ) + resultsContainer.innerHTML;
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
} )( PLUGIN_CHECK ); /* global PLUGIN_CHECK */
