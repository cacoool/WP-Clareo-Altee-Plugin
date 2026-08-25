<?php
/**
 * Plugin Name: Clareo Altee Job Sync
 * Description: Fetches https://career.altee.com/xml/clareo hourly and syncs HireZoot job listings. Apply buttons go to Altee.
 * Version: 1.2.5
 * Author: Clareo
 */

defined( 'ABSPATH' ) || exit;

define( 'CLAREO_ALTEE_FEED_URL', 'https://career.altee.com/xml/clareo' );
define( 'CLAREO_ALTEE_META_REF', '_altee_referencenumber' );
define( 'CLAREO_ALTEE_META_URL', '_altee_apply_url' );
define( 'CLAREO_ALTEE_CRON', 'clareo_altee_job_sync' );
define( 'CLAREO_ALTEE_OPTION', 'clareo_altee_last_sync' );
define( 'CLAREO_ALTEE_TEST_FIRST_ONLY', true );

register_activation_hook( __FILE__, 'clareo_altee_activate' );
register_deactivation_hook( __FILE__, 'clareo_altee_deactivate' );

function clareo_altee_activate() {
	if ( ! wp_next_scheduled( CLAREO_ALTEE_CRON ) ) {
		wp_schedule_event( time(), 'hourly', CLAREO_ALTEE_CRON );
	}
}

function clareo_altee_deactivate() {
	wp_clear_scheduled_hook( CLAREO_ALTEE_CRON );
}

add_action( CLAREO_ALTEE_CRON, 'clareo_altee_run_sync' );
add_action( 'admin_menu', 'clareo_altee_admin_menu', 20 );
add_action( 'admin_init', 'clareo_altee_handle_manual_sync' );
add_action( 'awsm_application_form_init', 'clareo_altee_apply_button', -999 );

function clareo_altee_admin_menu() {
	$parent = post_type_exists( 'awsm_job_openings' ) ? 'edit.php?post_type=awsm_job_openings' : 'options-general.php';
	add_submenu_page(
		$parent,
		'Altee Sync',
		'Altee Sync',
		'manage_options',
		'clareo-altee-sync',
		'clareo_altee_admin_page'
	);
}

function clareo_altee_handle_manual_sync() {
	if ( ! isset( $_POST['clareo_altee_sync_now'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'clareo_altee_sync' );
	clareo_altee_run_sync();
	wp_safe_redirect( add_query_arg( array( 'page' => 'clareo-altee-sync', 'synced' => '1' ), admin_url( post_type_exists( 'awsm_job_openings' ) ? 'edit.php?post_type=awsm_job_openings' : 'options-general.php' ) ) );
	exit;
}

function clareo_altee_admin_page() {
	$last  = get_option( CLAREO_ALTEE_OPTION, array() );
	$time  = isset( $last['time'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) : 'Jamais';
	$error = isset( $last['error'] ) ? $last['error'] : '';
	?>
	<div class="wrap">
		<h1>Altee Sync</h1>
		<?php if ( isset( $_GET['synced'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Synchronisation terminée.</p></div>
		<?php endif; ?>
		<p>Flux : <code><?php echo esc_html( CLAREO_ALTEE_FEED_URL ); ?></code></p>
		<p>Dernière synchro : <strong><?php echo esc_html( $time ); ?></strong></p>
		<?php if ( $error !== '' ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<?php if ( $last ) : ?>
			<ul>
				<li>Créées : <?php echo isset( $last['created'] ) ? (int) $last['created'] : 0; ?></li>
				<li>Mises à jour : <?php echo isset( $last['updated'] ) ? (int) $last['updated'] : 0; ?></li>
				<li>Expirées : <?php echo isset( $last['expired'] ) ? (int) $last['expired'] : 0; ?></li>
				<li>Dans le flux : <?php echo isset( $last['total'] ) ? (int) $last['total'] : 0; ?></li>
			</ul>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'clareo_altee_sync' ); ?>
			<?php submit_button( 'Synchroniser maintenant', 'primary', 'clareo_altee_sync_now', false ); ?>
		</form>
		<p>Les offres déjà publiées à la main ne sont pas touchées. Seules les offres importées depuis Altee sont mises à jour ou expirées.</p>
	</div>
	<?php
}

function clareo_altee_apply_button( $form_attrs ) {
	$job_id = 0;
	if ( is_array( $form_attrs ) && isset( $form_attrs['job_id'] ) ) {
		$job_id = (int) $form_attrs['job_id'];
	}
	if ( $job_id < 1 ) {
		$job_id = get_the_ID();
	}
	$url = get_post_meta( $job_id, CLAREO_ALTEE_META_URL, true );
	if ( ! is_string( $url ) || $url === '' ) {
		return;
	}
	remove_all_actions( 'awsm_application_form_init' );
	printf(
		'<div class="awsm-job-form"><div class="awsm-job-form-inner"><a class="awsm-application-submit-btn" href="%s" target="_blank" rel="noopener noreferrer">%s</a></div></div>',
		esc_attr( $url ),
		esc_html__( 'Postuler', 'clareo-altee-job-sync' )
	);
}

function clareo_altee_run_sync() {
	if ( ! post_type_exists( 'awsm_job_openings' ) ) {
		update_option(
			CLAREO_ALTEE_OPTION,
			array(
				'time'    => time(),
				'error'   => 'HireZoot n’est pas actif (type de contenu awsm_job_openings introuvable).',
				'created' => 0,
				'updated' => 0,
				'expired' => 0,
				'total'   => 0,
			)
		);
		return;
	}

	$response = wp_remote_get(
		CLAREO_ALTEE_FEED_URL,
		array(
			'timeout' => 60,
			'headers' => array(
				'Accept' => 'application/xml, text/xml, */*',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		update_option(
			CLAREO_ALTEE_OPTION,
			array(
				'time'    => time(),
				'error'   => $response->get_error_message(),
				'created' => 0,
				'updated' => 0,
				'expired' => 0,
				'total'   => 0,
			)
		);
		return;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	if ( $code !== 200 || $body === '' ) {
		update_option(
			CLAREO_ALTEE_OPTION,
			array(
				'time'    => time(),
				'error'   => 'Le flux Altee a renvoyé HTTP ' . $code,
				'created' => 0,
				'updated' => 0,
				'expired' => 0,
				'total'   => 0,
			)
		);
		return;
	}

	libxml_use_internal_errors( true );
	$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
	if ( $xml === false ) {
		update_option(
			CLAREO_ALTEE_OPTION,
			array(
				'time'    => time(),
				'error'   => 'XML Altee invalide.',
				'created' => 0,
				'updated' => 0,
				'expired' => 0,
				'total'   => 0,
			)
		);
		return;
	}

	$created = 0;
	$updated = 0;
	$seen    = array();

	$jobs = $xml->job;
	if ( CLAREO_ALTEE_TEST_FIRST_ONLY ) {
		$jobs = array( $jobs[0] );
	}

	foreach ( $jobs as $job ) {
		$ref = trim( (string) $job->referencenumber );
		if ( $ref === '' ) {
			continue;
		}
		$seen[] = $ref;
		if ( clareo_altee_upsert_job( $job ) ) {
			++$created;
		} else {
			++$updated;
		}
	}

	$expired = ( $seen && ! CLAREO_ALTEE_TEST_FIRST_ONLY ) ? clareo_altee_expire_missing( $seen ) : 0;

	update_option(
		CLAREO_ALTEE_OPTION,
		array(
			'time'    => time(),
			'error'   => '',
			'created' => $created,
			'updated' => $updated,
			'expired' => $expired,
			'total'   => count( $seen ),
		)
	);
}

function clareo_altee_find_job_id( $ref ) {
	$found = get_posts(
		array(
			'post_type'      => 'awsm_job_openings',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => CLAREO_ALTEE_META_REF,
			'meta_value'     => $ref,
		)
	);
	if ( $found ) {
		return (int) $found[0];
	}
	return 0;
}

function clareo_altee_upsert_job( $job ) {
	$ref   = trim( (string) $job->referencenumber );
	$title = trim( (string) $job->title );
	$html  = clareo_altee_format_html( (string) $job->html_description, (string) $job->description );
	$url  = clareo_altee_apply_url( trim( (string) $job->url ), $title );
	$city = trim( (string) $job->location->city );
	$team = trim( (string) $job->team );
	$type = trim( (string) $job->jobtype );
	$display_title = clareo_altee_display_title( $title, $team );

	$posted = strtotime( (string) $job->dateposted );
	$date   = $posted ? gmdate( 'Y-m-d H:i:s', $posted ) : current_time( 'mysql', true );

	$post_id = clareo_altee_find_job_id( $ref );
	$is_new  = $post_id < 1;
	$postarr = array(
		'post_type'    => 'awsm_job_openings',
		'post_status'  => 'publish',
		'post_title'   => $display_title !== '' ? $display_title : $ref,
		'post_content' => wp_kses_post( $html ),
		'post_name'    => sanitize_title( $display_title !== '' ? $display_title : $ref ),
	);

	if ( $is_new ) {
		$postarr['post_date']     = get_date_from_gmt( $date );
		$postarr['post_date_gmt'] = $date;
		$post_id                  = wp_insert_post( $postarr, true );
	} else {
		$postarr['ID'] = $post_id;
		$result        = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return false;
		}
	}

	if ( is_wp_error( $post_id ) || (int) $post_id < 1 ) {
		return false;
	}

	$post_id = (int) $post_id;

	update_post_meta( $post_id, CLAREO_ALTEE_META_REF, $ref );
	update_post_meta( $post_id, CLAREO_ALTEE_META_URL, $url );
	update_post_meta( $post_id, 'awsm_job_form', 'custom_btn' );
	update_post_meta( $post_id, 'awsm_job_custom_btn_url', $url );
	update_post_meta( $post_id, 'awsm_job_custom_btn_text', 'Postuler' );

	clareo_altee_set_spec( $post_id, 'job-category', clareo_altee_match_term( $title, 'job-category', false ) );
	clareo_altee_set_spec( $post_id, 'job-location', clareo_altee_match_term( $city, 'job-location', true ) );
	clareo_altee_set_spec( $post_id, 'clinique', clareo_altee_match_term( $team, 'clinique', true ) );
	clareo_altee_set_spec( $post_id, 'job-type', clareo_altee_match_term( clareo_altee_map_job_type( $type ), 'job-type', false ) );

	return $is_new;
}

function clareo_altee_display_title( $title, $team ) {
	if ( $title === '' ) {
		return $team;
	}
	if ( $team === '' ) {
		return $title;
	}
	return $title . ' – ' . $team;
}

function clareo_altee_apply_url( $url, $title ) {
	$url = str_replace( '/emplois/details/', '/emplois/apply/', $url );
	$url = preg_replace( '/#.*$/', '', $url );
	$url = esc_url_raw( $url );
	if ( $title !== '' ) {
		$url .= '#' . str_replace( ' ', '%20', $title );
	}
	return $url;
}

function clareo_altee_format_html( $html, $plain ) {
	$raw = $html !== '' ? $html : $plain;
	$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
	$raw = preg_replace( '#</?br\s*/?>#i', "\n", $raw );
	$raw = preg_replace( '#</p>#i', "\n", $raw );
	$raw = preg_replace( '#<p[^>]*>#i', "\n", $raw );
	$raw = wp_strip_all_tags( $raw );
	$raw = preg_replace( "/[ \t]+/", ' ', $raw );
	$lines = preg_split( '/\r\n|\r|\n/', $raw );
	$blocks     = array();
	$list_open  = false;
	$first_done = false;
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( $line === '' || preg_match( '/^#clareo/i', $line ) ) {
			continue;
		}
		$kind = clareo_altee_line_kind( $line );
		if ( ( $kind === 'complice' || $kind === 'heading' ) && $list_open ) {
			$blocks[]  = '</ul>';
			$list_open = false;
		}
		if ( $kind === 'complice' ) {
			$blocks[]  = '<p><u>' . esc_html( $line ) . '</u></p>';
			$blocks[]  = '<ul>';
			$list_open = true;
			continue;
		}
		if ( $kind === 'heading' ) {
			$blocks[] = '<p><strong>' . esc_html( $line ) . '</strong></p>';
			continue;
		}
		if ( $list_open ) {
			$blocks[] = '<li>' . esc_html( $line ) . '</li>';
			continue;
		}
		if ( ! $first_done ) {
			$blocks[]   = '<p><strong>' . esc_html( $line ) . '</strong></p>';
			$first_done = true;
			continue;
		}
		$blocks[] = '<p>' . esc_html( $line ) . '</p>';
	}
	if ( $list_open ) {
		$blocks[] = '</ul>';
	}
	return implode( "\n", $blocks );
}

function clareo_altee_line_kind( $line ) {
	$n = clareo_altee_normalize( $line );
	if ( strpos( $n, 'complice de ton mieux etre' ) === 0 || $n === 'complice de ton mieux etre' ) {
		return 'complice';
	}
	if ( strpos( $n, 'complice de ton succes' ) === 0 || $n === 'complice de ton succes' ) {
		return 'complice';
	}
	if ( clareo_altee_is_heading( $line ) ) {
		return 'heading';
	}
	return 'body';
}

function clareo_altee_is_heading( $line ) {
	if ( strlen( $line ) > 90 ) {
		return false;
	}
	if ( preg_match( '/[:?]\s*$/u', $line ) ) {
		return true;
	}
	$n = clareo_altee_normalize( $line );
	$heads = array(
		'tes avantages au quotidien',
		'ton role',
		'ton milieu',
		'tes competences',
		'qui sommes nous',
	);
	foreach ( $heads as $head ) {
		if ( $n === $head || strpos( $n, $head ) === 0 ) {
			return true;
		}
	}
	return false;
}

function clareo_altee_normalize( $value ) {
	$value = remove_accents( $value );
	$value = str_ireplace( array( '(trice)', '(e)', '·' ), array( '', 'e', '' ), $value );
	$value = strtolower( $value );
	$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
	return trim( preg_replace( '/\s+/', ' ', $value ) );
}

function clareo_altee_map_job_type( $type ) {
	$key = strtolower( trim( $type ) );
	$key = preg_replace( '/[\s_]+/', '-', $key );
	if ( $key === '' ) {
		return 'Permanent';
	}
	$map = array(
		'temporary'  => 'Temporaire',
		'full-time'  => 'Temps plein',
		'part-time'  => 'Temps partiel',
		'contractor' => 'Contrateur',
	);
	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}
	return $type;
}

function clareo_altee_match_term( $value, $taxonomy, $create ) {
	$value = sanitize_text_field( $value );
	if ( $value === '' || ! taxonomy_exists( $taxonomy ) ) {
		return 0;
	}
	$needle = clareo_altee_normalize( $value );
	if ( $needle === '' ) {
		return 0;
	}
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);
	$best_id  = 0;
	$best_len = 0;
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$hay = clareo_altee_normalize( $term->name );
			if ( $hay === '' ) {
				continue;
			}
			if ( $hay === $needle ) {
				return (int) $term->term_id;
			}
			if ( strpos( $needle, $hay ) !== false || strpos( $hay, $needle ) !== false ) {
				$len = strlen( $hay );
				if ( $len > $best_len ) {
					$best_len = $len;
					$best_id  = (int) $term->term_id;
				}
			}
		}
	}
	if ( $best_id ) {
		return $best_id;
	}
	if ( ! $create ) {
		return 0;
	}
	$created = wp_insert_term( $value, $taxonomy );
	if ( is_wp_error( $created ) ) {
		return 0;
	}
	return (int) $created['term_id'];
}

function clareo_altee_set_spec( $post_id, $taxonomy, $term_id ) {
	if ( $term_id < 1 || ! taxonomy_exists( $taxonomy ) ) {
		return;
	}
	wp_set_object_terms( $post_id, array( (int) $term_id ), $taxonomy );
}

function clareo_altee_expire_missing( $seen ) {
	$owned = get_posts(
		array(
			'post_type'      => 'awsm_job_openings',
			'post_status'    => array( 'publish', 'expired', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => CLAREO_ALTEE_META_REF,
		)
	);
	$count = 0;
	foreach ( $owned as $post_id ) {
		$ref = get_post_meta( $post_id, CLAREO_ALTEE_META_REF, true );
		if ( in_array( $ref, $seen, true ) ) {
			continue;
		}
		if ( get_post_status( $post_id ) === 'expired' ) {
			continue;
		}
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'expired',
			)
		);
		++$count;
	}
	return $count;
}
