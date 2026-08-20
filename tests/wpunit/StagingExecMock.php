<?php

namespace NewfoldLabs\WP\Module\Staging;

/**
 * Replace exec() inside the module namespace so runCommand() can be exercised
 * without invoking the destructive staging shell script.
 *
 * @param string     $command     Command passed to exec.
 * @param array|null $output      Captured output.
 * @param int|null   $result_code Exit status.
 *
 * @return string|false
 */
function exec( $command, &$output = null, &$result_code = null ) {
	$response = array_shift( StagingWPUnitTest::$exec_responses );

	if ( isset( $response['callback'] ) ) {
		$response['callback']( $command );
	}

	StagingWPUnitTest::$executed_commands[] = $command;
	$output                                 = $response['output'];
	$result_code                            = $response['status'];

	return empty( $output ) ? false : end( $output );
}
