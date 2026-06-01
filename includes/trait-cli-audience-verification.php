<?php
/**
 * Shared WP-CLI helpers for Firebase audience verification flags.
 *
 * @package PRC\Platform
 */

declare(strict_types=1);

namespace PRC\Platform;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves mutually exclusive --only-verified / --only-unverified / --include-unverified flags.
 */
trait CLI_Audience_Verification {

	/**
	 * Resolve verification mode from mutually exclusive CLI flags.
	 *
	 * @param array $assoc_args WP-CLI associative arguments.
	 * @return string verified|unverified|all
	 */
	protected static function resolve_verification_mode( array $assoc_args ): string {
		$only_verified      = (bool) Utils\get_flag_value( $assoc_args, 'only-verified', false );
		$only_unverified    = (bool) Utils\get_flag_value( $assoc_args, 'only-unverified', false );
		$include_unverified = (bool) Utils\get_flag_value( $assoc_args, 'include-unverified', false );

		$flag_count = (int) $only_verified + (int) $only_unverified + (int) $include_unverified;

		if ( $flag_count > 1 ) {
			WP_CLI::error(
				'Use only one of: --only-verified, --only-unverified, --include-unverified.'
			);
		}

		if ( $only_unverified ) {
			return 'unverified';
		}
		if ( $include_unverified ) {
			return 'all';
		}

		return 'verified';
	}

	/**
	 * Parenthetical suffix for default audience labels.
	 *
	 * @param string $verification verified|unverified|all
	 * @return string
	 */
	protected static function verification_label_suffix( string $verification ): string {
		return match ( $verification ) {
			'unverified' => ' (unverified)',
			'all'        => ' (all recipients)',
			default      => ' (verified)',
		};
	}

	/**
	 * Fragment for draft newsletter titles/subjects.
	 *
	 * @param string $verification verified|unverified|all
	 * @return string
	 */
	protected static function verification_title_fragment( string $verification ): string {
		return match ( $verification ) {
			'unverified' => ' (unverified)',
			'all'        => ' (all)',
			default      => ' (verified)',
		};
	}

	/**
	 * Build the JSON request body for an audience Cloud Function call.
	 *
	 * Always sends the canonical `verification` string. The legacy
	 * `require_verified` boolean is only included when it can faithfully
	 * express the requested cohort:
	 *
	 *   - verified => require_verified: true
	 *   - all      => require_verified: false
	 *   - unverified => (omitted)
	 *
	 * No boolean can represent "unverified-only". A legacy handler that ignores
	 * `verification` reads `require_verified=false` as "all users with an email",
	 * which would silently widen an --only-unverified build into a bulk send to
	 * verified recipients outside the operator's intended cohort. Omitting the
	 * field for the unverified mode prevents that cohort expansion; the response
	 * guard below then aborts if the function did not honor the request.
	 *
	 * @param array  $base         Mode-specific fields (e.g. dataset_id / quiz_id).
	 * @param string $verification verified|unverified|all
	 * @return array
	 */
	protected static function build_audience_request_body( array $base, string $verification ): array {
		$base['verification'] = $verification;

		if ( 'unverified' !== $verification ) {
			$base['require_verified'] = ( 'verified' === $verification );
		}

		return $base;
	}

	/**
	 * Abort unless the Cloud Function confirmed the exact verification mode that
	 * was requested.
	 *
	 * Older audience function deployments that predate the `verification`
	 * contract either omit the field from their response or apply a different
	 * filter than requested. Trusting the locally-resolved mode in that case
	 * would persist and label an audience (e.g. as "unverified") whose actual
	 * membership does not match — risking a newsletter send to recipients
	 * outside the operator's intended cohort. Fail closed before any audience is
	 * written or a draft is created.
	 *
	 * @param mixed  $body      Decoded JSON response body.
	 * @param string $requested verified|unverified|all
	 * @return string The server-confirmed verification mode (equal to $requested).
	 */
	protected static function assert_response_verification( $body, string $requested ): string {
		$reported = is_array( $body ) && isset( $body['verification'] )
			? (string) $body['verification']
			: '';

		if ( $reported !== $requested ) {
			WP_CLI::error( sprintf(
				'Verification contract mismatch: requested "%s" but the Cloud Function %s. ' .
				'This usually means an outdated audience function is deployed that does not ' .
				'honor the verification contract. Aborting before any audience is saved or a ' .
				'draft is created to avoid targeting recipients outside the intended cohort. ' .
				'Redeploy the latest audience Cloud Functions and retry.',
				$requested,
				'' === $reported ? 'did not report one' : sprintf( 'applied "%s"', $reported )
			) );
		}

		return $requested;
	}
}
