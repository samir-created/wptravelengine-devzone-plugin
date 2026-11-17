<?php
/**
 * DevZone Logs — WordPress subtab.
 *
 * Toggle WP debug constants and view debug.log.
 */

defined( 'ABSPATH' ) || exit;

$log_path    = defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG )
	? WP_DEBUG_LOG
	: WP_CONTENT_DIR . '/debug.log';

$saved_flags = (array) get_option( 'wpte_devzone_debug_flags', [] );

$defines = [
	'WP_DEBUG'         => [ 'label' => 'WP_DEBUG',         'desc' => 'Master debug switch',          'value' => ! empty( $saved_flags['WP_DEBUG'] ) ],
	'WP_DEBUG_LOG'     => [ 'label' => 'WP_DEBUG_LOG',     'desc' => 'Write errors to debug.log',    'value' => ! empty( $saved_flags['WP_DEBUG_LOG'] ) ],
	'WP_DEBUG_DISPLAY' => [ 'label' => 'WP_DEBUG_DISPLAY', 'desc' => 'Show errors on screen',        'value' => ! empty( $saved_flags['WP_DEBUG_DISPLAY'] ) ],
	'SCRIPT_DEBUG'     => [ 'label' => 'SCRIPT_DEBUG',     'desc' => 'Load non-minified JS/CSS',     'value' => ! empty( $saved_flags['SCRIPT_DEBUG'] ) ],
	'WP_DEVZONE_DEBUG' => [ 'label' => 'WP_DEVZONE_DEBUG', 'desc' => 'Force-enable the Tinker code editor outside production', 'value' => ! empty( $saved_flags['WP_DEVZONE_DEBUG'] ) ],
];

// shortcut: only the newest 512 KB of debug.log are read and shown — rendering a whole 60 MB log made
// the tab's AJAX response ~150 MB and the tab never loaded. Add paging if older entries are ever needed.
$log_tail_bytes = 512 * KB_IN_BYTES;
$log_size       = file_exists( $log_path ) ? (int) filesize( $log_path ) : 0;
$log_truncated  = $log_size > $log_tail_bytes;
$log_contents   = '';
// phpcs:disable WordPress.WP.AlternativeFunctions
$log_handle = $log_size ? fopen( $log_path, 'rb' ) : false;
if ( $log_handle ) {
	if ( $log_truncated ) {
		fseek( $log_handle, -$log_tail_bytes, SEEK_END );
		fgets( $log_handle ); // drop the partial line the seek landed in
	}
	$log_contents = (string) stream_get_contents( $log_handle );
	fclose( $log_handle );
}
// phpcs:enable WordPress.WP.AlternativeFunctions
// Start at the first timestamped entry, so stack-trace lines cut from their error don't show alone.
if ( $log_truncated && '' !== $log_contents && '[' !== $log_contents[0] ) {
	$first_entry  = strpos( $log_contents, "\n[" );
	$log_contents = false === $first_entry ? $log_contents : substr( $log_contents, $first_entry + 1 );
}

if ( ! function_exists( 'wpte_devzone_log_line_type' ) ) {
	/**
	 * Classify a log line into a severity key.
	 */
	function wpte_devzone_log_line_type( string $line ): string {
		if ( stripos( $line, 'PHP Fatal' ) !== false )       { return 'fatal'; }
		if ( stripos( $line, 'PHP Parse error' ) !== false ) { return 'fatal'; }
		if ( stripos( $line, 'PHP Warning' ) !== false )     { return 'warning'; }
		if ( stripos( $line, 'PHP Deprecated' ) !== false )  { return 'deprecated'; }
		if ( stripos( $line, 'PHP Notice' ) !== false )      { return 'notice'; }
		return 'default';
	}
}

if ( ! function_exists( 'wpte_devzone_parse_log_entries' ) ) {
	/**
	 * Groups raw debug.log lines into entries, attaching stack-trace /
	 * continuation lines (e.g. "#0 /path/file.php(12): ...") to the entry
	 * they belong to instead of treating every physical line as its own row.
	 *
	 * Grouping happens in file order (oldest first), then only the resulting
	 * entries are reversed for newest-first display — so a trace's frames
	 * stay attached to their parent error and in their original order,
	 * instead of the previous line-by-line reversal scattering them.
	 *
	 * @param string[] $lines Raw lines in file order.
	 * @return array<int,array{type:string,timestamp:string,message:string,file:string,line:string,raw:string,extra:string[]}>
	 */
	function wpte_devzone_parse_log_entries( array $lines ): array {
		$entries = [];
		$current = null;

		foreach ( $lines as $line ) {
			// Xdebug writes each stack frame as its own timestamped line ("PHP Stack trace:",
			// "PHP  12. Foo->bar() …"): those belong to the error above, not a new entry.
			if ( null !== $current && preg_match( '/^\[[^\]]+\]\s*PHP\s+(?:Stack trace:|\d+\.\s)/', $line ) ) {
				$current['extra'][] = preg_replace( '/^\[[^\]]+\]\s*PHP\s+/', '', $line );
				continue;
			}
			if ( preg_match( '/^\[([^\]]+)\]\s*(.*)$/', $line, $m ) ) {
				if ( null !== $current ) {
					$entries[] = $current;
				}

				$message = $m[2];
				$file    = '';
				$lineno  = '';

				if ( preg_match( '/^(.*?)\s+in\s+(\S+)\s+on\s+line\s+(\d+)\s*$/', $message, $fm ) ) {
					$message = $fm[1];
					$file    = $fm[2];
					$lineno  = $fm[3];
				}

				$current = [
					'type'      => wpte_devzone_log_line_type( $line ),
					'timestamp' => $m[1],
					'message'   => $message,
					'file'      => $file,
					'line'      => $lineno,
					'raw'       => $line,
					'extra'     => [],
				];
			} elseif ( null !== $current ) {
				$current['extra'][] = $line;
			} else {
				// A line before any timestamped entry — surface it standalone.
				$entries[] = [
					'type'      => wpte_devzone_log_line_type( $line ),
					'timestamp' => '',
					'message'   => $line,
					'file'      => '',
					'line'      => '',
					'raw'       => $line,
					'extra'     => [],
				];
			}
		}
		if ( null !== $current ) {
			$entries[] = $current;
		}

		return array_reverse( $entries );
	}
}

$log_entries = $log_contents ? wpte_devzone_parse_log_entries( array_filter( explode( "\n", $log_contents ) ) ) : [];

// Severity labels in display order; 'default' is any line PHP did not tag with a level.
$log_types  = [
	'fatal'      => __( 'Fatal', 'wptravelengine-devzone' ),
	'warning'    => __( 'Warning', 'wptravelengine-devzone' ),
	'deprecated' => __( 'Deprecated', 'wptravelengine-devzone' ),
	'notice'     => __( 'Notice', 'wptravelengine-devzone' ),
	'default'    => __( 'Other', 'wptravelengine-devzone' ),
];
$log_counts = array_count_values( array_column( $log_entries, 'type' ) );
?>
<div class="wte-dbg-wp-wrap">

	<section class="wte-dbg-perf-section wte-dbg-wp-section" aria-labelledby="wte-dbg-wp-constants-title">
		<div class="wte-dbg-perf-section-header">
			<h2 class="wte-dbg-perf-section-title" id="wte-dbg-wp-constants-title"><?php esc_html_e( 'Debug Constants', 'wptravelengine-devzone' ); ?></h2>
			<span class="wte-dbg-perf-section-note"><?php esc_html_e( 'Applied by DevZone while it is active — the page reloads after a change', 'wptravelengine-devzone' ); ?></span>
		</div>
		<div class="wte-dbg-wp-debug-grid">
			<?php foreach ( $defines as $constant => $info ) : ?>
			<div class="wte-dbg-wp-debug-row" data-constant="<?php echo esc_attr( $constant ); ?>">
				<div class="wte-dbg-wp-debug-text">
					<code class="wte-dbg-wp-code" id="wte-dbg-wp-c-<?php echo esc_attr( $constant ); ?>"><?php echo esc_html( $info['label'] ); ?></code>
					<span class="wte-dbg-wp-desc"><?php echo esc_html( $info['desc'] ); ?></span>
				</div>
				<label class="wte-dbg-wp-debug-toggle">
					<input type="checkbox" role="switch"
					       data-constant="<?php echo esc_attr( $constant ); ?>"
					       aria-labelledby="wte-dbg-wp-c-<?php echo esc_attr( $constant ); ?>"
					       <?php checked( $info['value'] ); ?>>
					<span class="wte-dbg-wp-debug-slider" aria-hidden="true"></span>
				</label>
			</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="wte-dbg-perf-section wte-dbg-wp-section" aria-labelledby="wte-dbg-wp-log-title">
		<div class="wte-dbg-perf-section-header wte-dbg-wp-log-header">
			<h2 class="wte-dbg-perf-section-title" id="wte-dbg-wp-log-title"><?php esc_html_e( 'Debug Log', 'wptravelengine-devzone' ); ?></h2>
			<?php if ( $log_entries ) : ?>
			<span class="wte-dbg-wp-log-total">
				<?php
				/* translators: %s: number of log entries */
				echo esc_html( sprintf( _n( '%s entry', '%s entries', count( $log_entries ), 'wptravelengine-devzone' ), number_format_i18n( count( $log_entries ) ) ) );
				?>
			</span>
			<?php endif; ?>
			<?php if ( $log_truncated ) : ?>
			<span class="wte-dbg-wp-log-truncated">
				<?php
				/* translators: 1: size shown, e.g. 512 KB, 2: full log size, e.g. 58 MB */
				echo esc_html( sprintf( __( 'Newest %1$s of %2$s', 'wptravelengine-devzone' ), size_format( $log_tail_bytes ), size_format( $log_size, 1 ) ) );
				?>
			</span>
			<?php endif; ?>
			<span class="wte-dbg-wp-log-path" title="<?php echo esc_attr( $log_path ); ?>"><?php echo esc_html( $log_path ); ?></span>
			<?php if ( file_exists( $log_path ) && $log_contents ) : ?>
			<button type="button" id="wte-dbg-clear-log" class="button button-small wte-dbg-wp-clear">
				<?php esc_html_e( 'Clear Log', 'wptravelengine-devzone' ); ?>
			</button>
			<?php endif; ?>
		</div>
		<div id="wte-dbg-log-body">
			<?php if ( ! file_exists( $log_path ) ) : ?>
				<div class="wte-dbg-wp-empty">
					<strong><?php esc_html_e( 'No debug.log yet', 'wptravelengine-devzone' ); ?></strong>
					<span><?php esc_html_e( 'Turn on WP_DEBUG and WP_DEBUG_LOG above to start capturing errors.', 'wptravelengine-devzone' ); ?></span>
				</div>
			<?php elseif ( empty( $log_entries ) ) : ?>
				<div class="wte-dbg-wp-empty">
					<strong><?php esc_html_e( 'The debug log is empty', 'wptravelengine-devzone' ); ?></strong>
					<span><?php esc_html_e( 'New PHP errors, warnings and notices will appear here.', 'wptravelengine-devzone' ); ?></span>
				</div>
			<?php else : ?>
				<div class="wte-dbg-wp-log-toolbar">
					<div class="wte-dbg-wp-log-filters" role="group" aria-label="<?php esc_attr_e( 'Filter by severity', 'wptravelengine-devzone' ); ?>">
						<button type="button" class="wte-dbg-wp-filter is-all" data-type="" aria-pressed="true">
							<?php esc_html_e( 'All', 'wptravelengine-devzone' ); ?>
							<span class="wte-dbg-wp-filter-count"><?php echo esc_html( number_format_i18n( count( $log_entries ) ) ); ?></span>
						</button>
						<?php foreach ( $log_types as $type_key => $type_label ) : ?>
							<?php if ( empty( $log_counts[ $type_key ] ) ) { continue; } ?>
						<button type="button" class="wte-dbg-wp-filter is-<?php echo esc_attr( $type_key ); ?>" data-type="<?php echo esc_attr( $type_key ); ?>" aria-pressed="false">
							<span class="wte-dbg-wp-dot" aria-hidden="true"></span>
							<?php echo esc_html( $type_label ); ?>
							<span class="wte-dbg-wp-filter-count"><?php echo esc_html( number_format_i18n( $log_counts[ $type_key ] ) ); ?></span>
						</button>
						<?php endforeach; ?>
					</div>
					<input type="search" class="wte-dbg-wp-log-search" placeholder="<?php esc_attr_e( 'Filter messages, files…', 'wptravelengine-devzone' ); ?>" aria-label="<?php esc_attr_e( 'Filter log entries', 'wptravelengine-devzone' ); ?>">
				</div>
				<div class="wte-dbg-wp-log-list"><?php
					foreach ( $log_entries as $entry ) {
						$type = isset( $log_types[ $entry['type'] ] ) ? $entry['type'] : 'default';
						?>
						<article class="wte-dbg-wp-log-entry is-<?php echo esc_attr( $type ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
							<div class="wte-dbg-wp-log-entry-header">
								<span class="wte-dbg-wp-log-badge is-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $log_types[ $type ] ); ?></span>
								<?php if ( $entry['timestamp'] ) : ?>
								<time class="wte-dbg-wp-log-ts"><?php echo esc_html( $entry['timestamp'] ); ?></time>
								<?php endif; ?>
								<?php if ( $entry['file'] ) : ?>
								<span class="wte-dbg-wp-log-loc" title="<?php echo esc_attr( $entry['file'] . ( $entry['line'] ? ':' . $entry['line'] : '' ) ); ?>">
									<?php echo esc_html( basename( $entry['file'] ) . ( $entry['line'] ? ':' . $entry['line'] : '' ) ); ?>
								</span>
								<?php endif; ?>
							</div>
							<?php // The badge already names the level, so drop PHP's own "PHP Deprecated:" prefix. ?>
							<div class="wte-dbg-wp-log-message"><?php echo esc_html( preg_replace( '/^PHP (?:Fatal error|Parse error|Warning|Deprecated|Notice):\s*/', '', $entry['message'] ) ); ?></div>
							<?php if ( ! empty( $entry['extra'] ) ) : ?>
							<details class="wte-dbg-wp-log-trace">
								<summary>
									<?php
									echo esc_html( sprintf(
										/* translators: %d: number of stack trace lines */
										_n( 'Stack trace · %d line', 'Stack trace · %d lines', count( $entry['extra'] ), 'wptravelengine-devzone' ),
										count( $entry['extra'] )
									) );
									?>
								</summary>
								<pre><?php echo esc_html( implode( "\n", $entry['extra'] ) ); ?></pre>
							</details>
							<?php endif; ?>
						</article>
						<?php
					}
				?></div>
				<p class="wte-dbg-wp-log-nomatch" hidden><?php esc_html_e( 'No entries match this filter.', 'wptravelengine-devzone' ); ?></p>
			<?php endif; ?>
		</div>
	</section>

</div>
