<?php
/**
 * Verified search engine crawlers.
 *
 * Googlebot renders pages with a headless Chromium, so it carries real
 * "headless" signals (webdriver flag, software WebGL, Permissions API...) and
 * Headless Browser Detection would otherwise withhold the decrypted content
 * from it. The User-Agent cannot be trusted (anyone can send "Googlebot"), so a
 * crawler is only recognised the way Google and Bing document it: the reverse
 * DNS name of the request IP must belong to the operator's domain, and the
 * forward DNS of that name must resolve back to the same IP.
 *
 * - DNS is only queried when the User-Agent claims to be a known crawler, so
 *   ordinary visitors never cost a lookup.
 * - googleusercontent.com is deliberately NOT accepted: those are Google Cloud
 *   / App Engine addresses that anyone can rent.
 * - Only positive results are stored in the database (one short-lived
 *   transient per verified crawler IP, a small and bounded set). Negative
 *   results go to the object cache. Without a persistent object cache they
 *   are kept in one single, size-capped transient (1.10), so a bot sending a
 *   fake crawler User-Agent cannot make the server repeat slow DNS lookups
 *   on every request, nor bloat wp_options from thousands of IPs.
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Supported crawlers.
 *
 * Each entry: 'name' (label), 'ua' (regex matched against the User-Agent) and
 * 'hosts' (reverse DNS suffixes, with the leading dot).
 *
 * @return array<string, array<string, mixed>>
 */
function init_plugin_suite_content_protector_verified_crawlers() {
	$list = array(
		'google' => array(
			'name'  => 'Google',
			'ua'    => '/Googlebot|Google-InspectionTool|GoogleOther|Storebot-Google|AdsBot-Google|Mediapartners-Google|APIs-Google|FeedFetcher-Google|Google-Read-Aloud|Google-Site-Verification/i',
			'hosts' => array( '.googlebot.com', '.google.com' ),
		),
		'bing'   => array(
			'name'  => 'Bing',
			'ua'    => '/bingbot|BingPreview|msnbot|adidxbot/i',
			'hosts' => array( '.search.msn.com' ),
		),
		'apple'  => array(
			'name'  => 'Apple',
			'ua'    => '/Applebot/i',
			'hosts' => array( '.applebot.apple.com' ),
		),
		'yandex' => array(
			'name'  => 'Yandex',
			'ua'    => '/YandexBot|YandexImages|YandexMobileBot|YandexRenderResourcesBot/i',
			'hosts' => array( '.yandex.ru', '.yandex.net', '.yandex.com' ),
		),
		'baidu'  => array(
			'name'  => 'Baidu',
			'ua'    => '/Baiduspider/i',
			'hosts' => array( '.baidu.com', '.baidu.jp' ),
		),
	);

	/**
	 * Filters the crawlers that can be verified.
	 *
	 * @since 1.9
	 *
	 * @param array $list Crawlers: key => array( 'name', 'ua' (regex), 'hosts' (reverse DNS suffixes) ).
	 */
	return (array) apply_filters( 'init_plugin_suite_content_protector_verified_crawlers', $list );
}

/**
 * The crawler the User-Agent claims to be (not yet verified).
 *
 * @param string|null $ua User-Agent. Defaults to the current request's.
 * @return string Crawler key, or '' when the User-Agent is not a known crawler.
 */
function init_plugin_suite_content_protector_crawler_claim( $ua = null ) {
	if ( null === $ua ) {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}

	$ua = (string) $ua;
	if ( '' === $ua || strlen( $ua ) > 512 ) {
		return '';
	}

	foreach ( init_plugin_suite_content_protector_verified_crawlers() as $key => $crawler ) {
		if ( ! empty( $crawler['ua'] ) && 1 === @preg_match( $crawler['ua'], $ua ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A bad regex from the filter must not break the request.
			return (string) $key;
		}
	}

	return '';
}

/**
 * Ways to find the real visitor IP, as value => label.
 *
 * @return array<string, string>
 */
function init_plugin_suite_content_protector_client_ip_sources() {
	return array(
		'auto'             => __( 'Automatic (recommended): Cloudflare and private-network proxies', 'init-content-protector' ),
		'remote_addr'      => __( 'Direct connection (REMOTE_ADDR)', 'init-content-protector' ),
		'cloudflare'       => __( 'Cloudflare (CF-Connecting-IP)', 'init-content-protector' ),
		'x-forwarded-for'  => __( 'Proxy header: X-Forwarded-For', 'init-content-protector' ),
		'x-real-ip'        => __( 'Proxy header: X-Real-IP', 'init-content-protector' ),
		'true-client-ip'   => __( 'Proxy header: True-Client-IP', 'init-content-protector' ),
		'fastly-client-ip' => __( 'Proxy header: Fastly-Client-IP', 'init-content-protector' ),
	);
}

/**
 * Cloudflare's published IP ranges (https://www.cloudflare.com/ips/).
 *
 * @return string[] CIDR ranges.
 */
function init_plugin_suite_content_protector_cloudflare_ranges() {
	$ranges = array(
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'108.162.192.0/18',
		'131.0.72.0/22',
		'141.101.64.0/18',
		'162.158.0.0/15',
		'172.64.0.0/13',
		'173.245.48.0/20',
		'188.114.96.0/20',
		'190.93.240.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Filters the IP ranges treated as Cloudflare, e.g. when Cloudflare adds one.
	 *
	 * @since 1.9
	 *
	 * @param string[] $ranges CIDR ranges (IPv4 and IPv6).
	 */
	return (array) apply_filters( 'init_plugin_suite_content_protector_cloudflare_ranges', $ranges );
}

/**
 * Whether an IP address is inside a CIDR range (IPv4 or IPv6).
 *
 * @param string $ip   IP address.
 * @param string $cidr Range such as 173.245.48.0/20.
 * @return bool
 */
function init_plugin_suite_content_protector_ip_in_cidr( $ip, $cidr ) {
	$parts = explode( '/', (string) $cidr, 2 );
	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) {
		return false;
	}

	// Validate before inet_pton(), which emits a warning on bad input (a
	// typo in a range added through the filter would otherwise print PHP
	// warnings on every request).
	if ( false === filter_var( $parts[0], FILTER_VALIDATE_IP ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	$subnet = inet_pton( $parts[0] );
	$addr   = inet_pton( $ip );
	if ( false === $subnet || false === $addr || strlen( $subnet ) !== strlen( $addr ) ) {
		return false;
	}

	$bits = (int) $parts[1];
	if ( $bits > strlen( $subnet ) * 8 ) {
		return false;
	}

	$bytes = intdiv( $bits, 8 );
	if ( $bytes && substr( $subnet, 0, $bytes ) !== substr( $addr, 0, $bytes ) ) {
		return false;
	}

	$rest = $bits % 8;
	if ( ! $rest ) {
		return true;
	}

	$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

	return ( ord( $subnet[ $bytes ] ) & $mask ) === ( ord( $addr[ $bytes ] ) & $mask );
}

/**
 * Whether an IP address belongs to Cloudflare.
 *
 * @param string $ip IP address.
 * @return bool
 */
function init_plugin_suite_content_protector_is_cloudflare_ip( $ip ) {
	foreach ( init_plugin_suite_content_protector_cloudflare_ranges() as $cidr ) {
		if ( init_plugin_suite_content_protector_ip_in_cidr( $ip, $cidr ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether an IP address is public (not private, loopback, link-local or reserved).
 *
 * @param string $ip IP address.
 * @return bool
 */
function init_plugin_suite_content_protector_is_public_ip( $ip ) {
	return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Read one request header as a visitor IP.
 *
 * A comma separated list (X-Forwarded-For) is read from the right, skipping
 * private / reserved hops, and the first public address is used: the left side
 * of the list is whatever the visitor chose to send, the right side is what the
 * proxies in front of this server appended.
 *
 * @param string $header Header name, e.g. X-Forwarded-For.
 * @return string Valid IP address, or '' when the header is missing or has none.
 */
function init_plugin_suite_content_protector_header_ip( $header ) {
	$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
	if ( empty( $_SERVER[ $key ] ) ) {
		return '';
	}

	$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
	if ( '' === $value || strlen( $value ) > 512 ) {
		return '';
	}

	foreach ( array_reverse( explode( ',', $value ) ) as $candidate ) {
		$candidate = trim( $candidate );

		// "1.2.3.4:5678" and "[2001:db8::1]:5678" forms.
		if ( preg_match( '/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $candidate, $match ) || preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $candidate, $match ) ) {
			$candidate = $match[1];
		}

		if ( init_plugin_suite_content_protector_is_public_ip( $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * IP address of the current visitor.
 *
 * The method comes from the "Visitor IP Detection" setting. Forwarding headers
 * (CF-Connecting-IP, X-Forwarded-For...) are sent by the client whenever the
 * site can be reached directly, so in Automatic mode they are only believed
 * when the connection itself comes from Cloudflare's published ranges or from
 * a proxy on a private network. A visitor cannot fake either of those. The
 * explicit header options trust the header without that check: use them only
 * when the server accepts traffic from your proxy alone.
 *
 * @return string Valid IP address, or '' when unknown.
 */
function init_plugin_suite_content_protector_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
		$remote = '';
	}

	$settings = init_plugin_suite_content_protector_get_settings();
	$source   = isset( $settings['client_ip_source'] ) ? (string) $settings['client_ip_source'] : 'auto';
	if ( ! isset( init_plugin_suite_content_protector_client_ip_sources()[ $source ] ) ) {
		$source = 'auto';
	}

	$ip = $remote;

	if ( 'auto' === $source || 'cloudflare' === $source ) {
		// Cloudflare: believe CF-Connecting-IP only when the connection really is from Cloudflare.
		$cf_ip = '' !== $remote && init_plugin_suite_content_protector_is_cloudflare_ip( $remote )
			? init_plugin_suite_content_protector_header_ip( 'CF-Connecting-IP' )
			: '';

		if ( '' !== $cf_ip ) {
			$ip = $cf_ip;
		} elseif ( 'auto' === $source && '' !== $remote && ! init_plugin_suite_content_protector_is_public_ip( $remote ) ) {
			// A proxy or load balancer on a private network in front of the server.
			foreach ( array( 'X-Forwarded-For', 'X-Real-IP' ) as $header ) {
				$header_ip = init_plugin_suite_content_protector_header_ip( $header );
				if ( '' !== $header_ip ) {
					$ip = $header_ip;
					break;
				}
			}
		}
	} elseif ( 'remote_addr' !== $source ) {
		$header_ip = init_plugin_suite_content_protector_header_ip( $source );
		if ( '' !== $header_ip ) {
			$ip = $header_ip;
		}
	}

	/**
	 * Filters the visitor IP address used to verify search engine crawlers.
	 *
	 * Runs after the "Visitor IP Detection" setting, so it can still override it.
	 *
	 * @since 1.9
	 *
	 * @param string $ip     IP address found by the setting.
	 * @param string $remote The direct connection address (REMOTE_ADDR).
	 */
	$ip = (string) apply_filters( 'init_plugin_suite_content_protector_client_ip', $ip, $remote );

	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * IP addresses (IPv4 and IPv6) a host name resolves to.
 *
 * @param string $host Host name.
 * @return string[]
 */
function init_plugin_suite_content_protector_crawler_forward_ips( $host ) {
	$ips = array();

	$v4 = gethostbynamel( $host );
	if ( is_array( $v4 ) ) {
		$ips = $v4;
	}

	if ( function_exists( 'dns_get_record' ) ) {
		$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Emits a warning when the lookup fails; that must never reach the response.
		foreach ( (array) $v6 as $record ) {
			if ( ! empty( $record['ipv6'] ) ) {
				$ips[] = $record['ipv6'];
			}
		}
	}

	return $ips;
}

/**
 * Whether two IP addresses are the same once normalised (IPv6 has many spellings).
 *
 * @param string $a First IP.
 * @param string $b Second IP.
 * @return bool
 */
function init_plugin_suite_content_protector_crawler_same_ip( $a, $b ) {
	if ( false === filter_var( $a, FILTER_VALIDATE_IP ) || false === filter_var( $b, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	$pa = inet_pton( $a );
	$pb = inet_pton( $b );

	return false !== $pa && false !== $pb && $pa === $pb;
}

/**
 * Forward-confirmed reverse DNS check of one IP against host name suffixes.
 * Not cached.
 *
 * @param string   $ip       IP address.
 * @param string[] $suffixes Allowed reverse DNS suffixes (with the leading dot).
 * @return bool
 */
function init_plugin_suite_content_protector_crawler_dns_check( $ip, array $suffixes ) {
	if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return false;
	}

	/**
	 * Short-circuits the DNS check, e.g. to compare against the IP ranges a
	 * search engine publishes instead. Return true / false to decide, or null
	 * to run the default reverse and forward DNS check.
	 *
	 * @since 1.9
	 *
	 * @param bool|null $result   Null to continue with the DNS check.
	 * @param string    $ip       IP address.
	 * @param string[]  $suffixes Reverse DNS suffixes of the claimed crawler.
	 */
	$pre = apply_filters( 'init_plugin_suite_content_protector_pre_crawler_dns_check', null, $ip, $suffixes );
	if ( null !== $pre ) {
		return (bool) $pre;
	}

	if ( ! function_exists( 'gethostbyaddr' ) ) {
		return false;
	}

	$host = gethostbyaddr( $ip );
	if ( ! is_string( $host ) || '' === $host || $host === $ip ) {
		return false;
	}
	$host = strtolower( rtrim( $host, '.' ) );

	$matched = false;
	foreach ( $suffixes as $suffix ) {
		$suffix = strtolower( (string) $suffix );
		if ( '' !== $suffix && substr( $host, -strlen( $suffix ) ) === $suffix ) {
			$matched = true;
			break;
		}
	}
	if ( ! $matched ) {
		return false;
	}

	foreach ( init_plugin_suite_content_protector_crawler_forward_ips( $host ) as $forward_ip ) {
		if ( init_plugin_suite_content_protector_crawler_same_ip( $forward_ip, $ip ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Read or record a failed crawler verification when there is no persistent
 * object cache.
 *
 * All failures share one transient holding at most 500 entries (oldest
 * dropped first, each kept 10 minutes), so the database cost is one row no
 * matter how many IP addresses send a fake crawler User-Agent. Concurrent
 * writes may lose an entry, which only means one more DNS lookup.
 *
 * @since 1.10
 *
 * @param string $key    Cache key of the IP + crawler pair.
 * @param bool   $record Optional. Record a failure instead of reading. Default false.
 * @return bool Whether a recent failure is on record (always true when recording).
 */
function init_plugin_suite_content_protector_crawler_negative( $key, $record = false ) {
	if ( wp_using_ext_object_cache() ) {
		// The object cache already holds negative results.
		return $record;
	}

	$transient = 'icp_bot_negative';
	$now       = time();
	$list      = get_transient( $transient );
	$list      = is_array( $list ) ? $list : array();

	if ( ! $record ) {
		return isset( $list[ $key ] ) && (int) $list[ $key ] > $now;
	}

	foreach ( $list as $entry => $expires ) {
		if ( (int) $expires <= $now ) {
			unset( $list[ $entry ] );
		}
	}

	unset( $list[ $key ] );
	$list[ $key ] = $now + 10 * MINUTE_IN_SECONDS;

	if ( count( $list ) > 500 ) {
		$list = array_slice( $list, -500, null, true );
	}

	set_transient( $transient, $list, 10 * MINUTE_IN_SECONDS );

	return true;
}

/**
 * Whether the current request comes from a verified search engine crawler.
 *
 * @param string|null $ip IP address. Defaults to the visitor's.
 * @param string|null $ua User-Agent. Defaults to the current request's.
 * @return string Crawler key ('google', 'bing'...), or '' when not verified.
 */
function init_plugin_suite_content_protector_verified_crawler( $ip = null, $ua = null ) {
	static $memo = array();

	$bot = init_plugin_suite_content_protector_crawler_claim( $ua );
	if ( '' === $bot ) {
		return '';
	}

	$ip = null === $ip ? init_plugin_suite_content_protector_client_ip() : (string) $ip;
	if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	$key = 'icp_bot_' . md5( $bot . '|' . $ip );

	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ] ? $bot : '';
	}

	$cached = wp_cache_get( $key, 'init_content_protector' );
	if ( false === $cached && init_plugin_suite_content_protector_crawler_negative( $key ) ) {
		$cached = 'no';
	}
	if ( false === $cached ) {
		$cached = get_transient( $key );
	}

	if ( false === $cached ) {
		$crawlers = init_plugin_suite_content_protector_verified_crawlers();
		$verified = init_plugin_suite_content_protector_crawler_dns_check( $ip, (array) ( $crawlers[ $bot ]['hosts'] ?? array() ) );
		$cached   = $verified ? 'yes' : 'no';

		// Negative results never get a transient of their own (see file header).
		wp_cache_set( $key, $cached, 'init_content_protector', $verified ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		if ( $verified ) {
			set_transient( $key, $cached, DAY_IN_SECONDS );
		} else {
			init_plugin_suite_content_protector_crawler_negative( $key, true );
		}
	}

	/**
	 * Filters whether the request is a verified search engine crawler.
	 *
	 * @since 1.9
	 *
	 * @param bool   $verified True when the reverse and forward DNS check passed.
	 * @param string $bot      Crawler key.
	 * @param string $ip       IP address.
	 */
	$verified = (bool) apply_filters( 'init_plugin_suite_content_protector_is_verified_crawler', 'yes' === $cached, $bot, $ip );

	$memo[ $key ] = $verified;

	return $verified ? $bot : '';
}
