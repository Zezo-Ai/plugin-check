<?php

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Driver\Xdebug;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP as PHPReport;

$root_folder = realpath( dirname( __DIR__, 3 ) );

$feature   = getenv( 'BEHAT_FEATURE_TITLE' );
$scenario  = getenv( 'BEHAT_SCENARIO_TITLE' );
$step_line = (int) getenv( 'BEHAT_STEP_LINE' );
$name      = "{$feature} - {$scenario} - {$step_line}";

if ( empty( $feature ) || empty( $scenario ) ) {
	return;
}

if ( ! class_exists( 'SebastianBergmann\CodeCoverage\Filter' ) ) {
	require "{$root_folder}/vendor/autoload.php";
}

$filtered_items = new CallbackFilterIterator(
	new DirectoryIterator( $root_folder ),
	function ( $file ) {
		if ( $file->isDir() && in_array( $file->getFilename(), [ 'php', 'src' ], true ) ) {
			return true;
		}

		if ( $file->isFile() && false !== strpos( $file->getFilename(), '-command.php' ) ) {
			return true;
		}

		return false;
	}
);

$files = [];

foreach ( $filtered_items as $item ) {
	if ( $item->isDir() ) {
		foreach (
			new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $item->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS )
			) as $file
		) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$files[] = $file->getPathname();
			}
		}
	} else {
		$files[] = $item->getPathname();
	}
}

$filter = new Filter();

if ( method_exists( $filter, 'includeFiles' ) ) {
	$filter->includeFiles( $files );
} else {
	$filter->addFilesToWhitelist( $files );
}

$coverage = new CodeCoverage(
	class_exists( Selector::class ) ? ( new Selector() )->forLineCoverage( $filter ) : ( new Xdebug() ),
	$filter
);

$coverage->start( $name );

register_shutdown_function(
	static function () use ( $coverage, $feature, $scenario, $step_line, $name, $root_folder ) {
		$coverage->stop();

		$feature_suffix  = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $feature ) );
		$scenario_suffix = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $scenario ) );
		$db_type         = strtolower( getenv( 'WP_CLI_TEST_DBTYPE' ) );
		$destination     = "$root_folder/build/logs/$feature_suffix-$scenario_suffix-$step_line-$db_type.cov";

		$dir = dirname( $destination );
		if ( ! file_exists( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		( new PHPReport() )->process( $coverage, $destination );
	}
);
