<?php
/**
 * Extension-local PHPUnit bootstrap.
 *
 * Loads only what MediaWikiUnitTestCase subclasses need and registers a small
 * autoloader for the extension's namespaces, sidestepping MediaWiki's full
 * unit-test runner — its extension-discovery step (getPHPUnitExtensionsAndSkins)
 * trips on Canasta's deferred $wgSettings initialization.
 *
 * Run with:
 *   cd <MW-root>
 *   vendor/bin/phpunit -c extensions/TimeTracker/tests/phpunit/phpunit.xml.dist
 */

$mwRoot = realpath( __DIR__ . '/../../../..' );
if ( $mwRoot === false || !file_exists( "$mwRoot/tests/phpunit/MediaWikiUnitTestCase.php" ) ) {
	fwrite( STDERR,
		"TimeTracker test bootstrap: could not locate MediaWiki at "
		. ( $mwRoot ?: '(realpath failed)' ) . "\n"
	);
	exit( 1 );
}

require_once "$mwRoot/tests/phpunit/bootstrap.common.php";

// Register MediaWiki's autoloader + a few core files, then apply MainConfigSchema
// defaults — the minimum for unit tests, skipping the extension-discovery step.
$GLOBALS['wgAutoloadClasses'] = [];
$GLOBALS['wgBaseDirectory'] = MW_INSTALL_PATH;
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/includes/AutoLoader.php' );
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/tests/common/TestsAutoLoader.php' );
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/includes/Defines.php' );
TestSetup::requireOnceInGlobalScope( MW_INSTALL_PATH . '/includes/GlobalFunctions.php' );
foreach ( MediaWiki\MainConfigSchema::listDefaultValues( 'wg' ) as $var => $value ) {
	$GLOBALS[$var] = $value;
}

require_once "$mwRoot/tests/phpunit/MediaWikiCoversValidator.php";
require_once "$mwRoot/tests/phpunit/MediaWikiTestCaseTrait.php";
require_once "$mwRoot/tests/phpunit/MediaWikiUnitTestCase.php";

// Tiny autoloader for the extension's classes and unit-test classes, since the
// MW extension-discovery step (which applies extension.json's namespaces) is skipped.
$extensionRoot = realpath( __DIR__ . '/../..' );
spl_autoload_register( static function ( $class ) use ( $extensionRoot ) {
	$prefixes = [
		'MediaWiki\\Extension\\TimeTracker\\Tests\\Unit\\' => $extensionRoot . '/tests/phpunit/unit/',
		'MediaWiki\\Extension\\TimeTracker\\' => $extensionRoot . '/includes/',
	];
	foreach ( $prefixes as $prefix => $dir ) {
		if ( str_starts_with( $class, $prefix ) ) {
			$rel = substr( $class, strlen( $prefix ) );
			$file = $dir . str_replace( '\\', '/', $rel ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}
	}
} );
