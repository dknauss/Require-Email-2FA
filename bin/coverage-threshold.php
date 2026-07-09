<?php
/**
 * Coverage ratchet: fail if combined line coverage drops below a floor.
 *
 * The unit tests run under two PHPUnit configs with different bootstraps (the
 * default one and phpunit-no-two-factor.xml.dist), so coverage is emitted as two
 * Clover reports. This unions the per-line hit counts across every report — a line
 * covered by either run counts as covered — then compares the combined line
 * coverage against the floor and exits non-zero when it is lower.
 *
 * Lines carrying @codeCoverageIgnore are already excluded by PHPUnit, so the
 * denominator is only the code that is meant to be covered.
 *
 * Usage:
 *   php bin/coverage-threshold.php <min-percent> <clover.xml> [<clover2.xml> ...]
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: php bin/coverage-threshold.php <min-percent> <clover.xml> [more.xml ...]\n" );
	exit( 2 );
}

$min    = (float) $argv[1];
$files  = array_slice( $argv, 2 );
$hits   = array(); // "file\0line" => covered (bool), unioned across reports

foreach ( $files as $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "coverage report not found: {$path}\n" );
		exit( 2 );
	}

	$xml = @simplexml_load_file( $path );
	if ( false === $xml ) {
		fwrite( STDERR, "could not parse clover XML: {$path}\n" );
		exit( 2 );
	}

	foreach ( $xml->xpath( '//file' ) as $file ) {
		$name = (string) $file['name'];
		foreach ( $file->line as $line ) {
			// Count executable lines that Clover tracks (statements and methods);
			// conditionals are reported per-branch and would distort a line ratio.
			$type = (string) $line['type'];
			if ( 'stmt' !== $type && 'method' !== $type ) {
				continue;
			}
			$key = $name . "\0" . (string) $line['num'];
			$covered = (int) $line['count'] > 0;
			$hits[ $key ] = ( $hits[ $key ] ?? false ) || $covered;
		}
	}
}

$total   = count( $hits );
$covered = count( array_filter( $hits ) );

if ( 0 === $total ) {
	fwrite( STDERR, "no coverable lines found in the provided reports\n" );
	exit( 2 );
}

$percent = ( $covered / $total ) * 100;
printf( "Combined line coverage: %.2f%% (%d/%d) — floor %.2f%%\n", $percent, $covered, $total, $min );

// Round to two decimals before comparing so a report of exactly the floor passes and
// floating-point noise just under it does not spuriously fail.
if ( round( $percent, 2 ) + 1e-9 < $min ) {
	fwrite( STDERR, sprintf( "FAIL: coverage %.2f%% is below the %.2f%% floor.\n", $percent, $min ) );
	exit( 1 );
}

echo "Coverage floor satisfied.\n";
