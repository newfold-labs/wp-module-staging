<?php
/**
 * A staging-lock double whose liveness probe can never decide.
 *
 * @package WPModuleStaging
 */

namespace NewfoldLabs\WP\Module\Staging;

/**
 * A StagingCommandLock on a host where liveness cannot be determined: no POSIX extension and no
 * /proc. Only the probe is replaced, so every other rule still applies.
 *
 * The branch this reaches is unreachable otherwise, and it is the one where the two callers take
 * opposite defaults, so it is worth a double rather than leaving it untested.
 */
class StagingCommandLockProbeStub extends StagingCommandLock {

	/**
	 * Always undecidable.
	 *
	 * @param int $pid Recorded pid.
	 *
	 * @return bool|null
	 */
	protected function pidIsRunning( $pid ) {
		return null;
	}
}
