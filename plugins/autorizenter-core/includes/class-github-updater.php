<?php
/**
 * Self-hosted plugin updates from GitHub Releases.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a plugin update itself from a GitHub repository's releases — the WordPress
 * Plugins screen shows an update when a newer release tag exists, and installs the
 * release ZIP asset.
 *
 * Releases must attach a built plugin ZIP asset named like the slug
 * (e.g. `autorizenter-core.zip`) that extracts to a single `<slug>/` folder. The
 * bundled GitHub Actions release workflow produces these automatically. If no
 * matching asset is found, the source zipball is used as a fallback (works only if
 * the repo root *is* the plugin).
 *
 * Reusable for any plugin: construct one instance per plugin file.
 */
class Github_Updater {

	/**
	 * Absolute plugin main file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin slug / folder name (e.g. "autorizenter-core").
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * GitHub "owner/repo".
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Current installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Preferred release asset filename.
	 *
	 * @var string
	 */
	private $asset;

	/**
	 * Constructor.
	 *
	 * @param string $file    Absolute plugin main file.
	 * @param string $slug    Plugin slug / folder name.
	 * @param string $repo    GitHub "owner/repo".
	 * @param string $version Installed version.
	 * @param string $asset   Preferred release asset filename (e.g. slug.zip).
	 */
	public function __construct( $file, $slug, $repo, $version, $asset = '' ) {
		$this->file    = $file;
		$this->slug    = $slug;
		$this->repo    = trim( (string) $repo, '/ ' );
		$this->version = $version;
		$this->asset   = '' !== $asset ? $asset : $slug . '.zip';
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		if ( '' === $this->repo || false === strpos( $this->repo, '/' ) ) {
			return; // Not configured with a valid owner/repo.
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * Plugin basename, e.g. "autorizenter-core/autorizenter-core.php".
	 *
	 * @return string
	 */
	private function basename() {
		return plugin_basename( $this->file );
	}

	/**
	 * Fetch (and cache) the latest GitHub release for this repo.
	 *
	 * @return array|null Decoded release, or null on failure.
	 */
	private function latest_release() {
		$cache_key = 'autorizenter_gh_' . md5( $this->repo );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			// An empty array is the negative cache written after a failed/!200
			// request; treat it as "no release" rather than a malformed one.
			return ! empty( $cached['tag_name'] ) ? $cached : null;
		}

		$url     = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'Autorizenter-Updater',
		);

		/**
		 * Filter the GitHub API request args (e.g. add an auth token for higher
		 * rate limits or private repos).
		 *
		 * @param array  $args Request args.
		 * @param string $repo owner/repo.
		 */
		$args = apply_filters(
			'autorizenter_github_request_args',
			array(
				'timeout' => 15,
				'headers' => $headers,
			),
			$this->repo
		);

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS ); // brief negative cache.
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Normalize a tag/version string (strips a leading "v").
	 *
	 * @param string $v Version.
	 * @return string
	 */
	private function normalize( $v ) {
		return ltrim( (string) $v, 'vV' );
	}

	/**
	 * Resolve the download URL for the release (preferred asset, else zipball).
	 *
	 * @param array $release Release data.
	 * @return string
	 */
	private function package_url( array $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && $asset['name'] === $this->asset && ! empty( $asset['browser_download_url'] ) ) {
					return $asset['browser_download_url'];
				}
			}
		}
		return isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
	}

	/**
	 * Read the plugin's bundled readme.txt (sits next to the main file).
	 *
	 * @return string Raw contents, or '' if absent/unreadable.
	 */
	private function read_readme() {
		$path = dirname( $this->file ) . '/readme.txt';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file, not a remote resource.
	}

	/**
	 * Parse a WordPress-format readme.txt into its header fields and sections.
	 *
	 * Header keys and section names are lowercased so callers can read them
	 * case-insensitively (e.g. $parsed['headers']['tested up to'],
	 * $parsed['sections']['changelog']).
	 *
	 * @param string $raw Raw readme.txt contents.
	 * @return array{headers:array<string,string>,sections:array<string,string>}
	 */
	private function parse_readme( $raw ) {
		$raw      = str_replace( "\r\n", "\n", (string) $raw );
		$headers  = array();
		$sections = array();

		// Split off the header block (everything before the first "== Section ==").
		// The space requirement keeps the "=== Title ===" line out of the matches.
		$parts = preg_split( '/^==[ \t]+(.+?)[ \t]+==[ \t]*$/m', $raw, -1, PREG_SPLIT_DELIM_CAPTURE );
		$head  = (string) array_shift( $parts );

		foreach ( explode( "\n", $head ) as $line ) {
			if ( preg_match( '/^([A-Za-z][A-Za-z .]+?):\s*(.*)$/', trim( $line ), $m ) ) {
				$headers[ strtolower( $m[1] ) ] = trim( $m[2] );
			}
		}

		$count = count( $parts );
		for ( $i = 0; $i + 1 < $count; $i += 2 ) {
			$sections[ strtolower( trim( $parts[ $i ] ) ) ] = trim( $parts[ $i + 1 ] );
		}

		return array(
			'headers'  => $headers,
			'sections' => $sections,
		);
	}

	/**
	 * Convert a readme section's WordPress markup to HTML for the details modal:
	 * "= Subheading =" becomes an <h4>, runs of "* item" lines become a <ul>, and
	 * remaining prose is wrapped into paragraphs.
	 *
	 * @param string $text Section body.
	 * @return string Sanitized HTML.
	 */
	private function readme_html( $text ) {
		$lines = explode( "\n", str_replace( "\r\n", "\n", (string) $text ) );
		$html  = '';
		$items = array();

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( preg_match( '/^\*\s+(.*)$/', $trimmed, $m ) ) {
				$items[] = $m[1]; // Start of a new bullet.
				continue;
			}
			if ( $items && '' !== $trimmed && ! preg_match( '/^=.*=$/', $trimmed ) ) {
				$items[ count( $items ) - 1 ] .= ' ' . $trimmed; // Wrapped continuation of the current bullet.
				continue;
			}
			if ( $items ) { // Any other line closes an open list.
				$html .= '<ul><li>' . implode( '</li><li>', $items ) . "</li></ul>\n";
				$items = array();
			}
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $trimmed, $m ) ) {
				$html .= '<h4>' . $m[1] . "</h4>\n";
				continue;
			}
			$html .= $line . "\n";
		}
		if ( $items ) {
			$html .= '<ul><li>' . implode( '</li><li>', $items ) . "</li></ul>\n";
		}

		return wp_kses_post( wpautop( $html ) );
	}

	/**
	 * Turn a comma-separated header value into a key=>value array, as the modal
	 * expects for contributors and tags.
	 *
	 * @param string $csv Comma-separated list.
	 * @return array<string,string>
	 */
	private function csv_list( $csv ) {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) ) as $item ) {
			$out[ $item ] = $item;
		}
		return $out;
	}

	/**
	 * Inject an available update into the update_plugins transient.
	 *
	 * @param mixed $transient Update transient (object) or empty.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( null === $release || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$new_version = $this->normalize( $release['tag_name'] );
		if ( '' === $new_version || version_compare( $new_version, $this->normalize( $this->version ), '<=' ) ) {
			// Up to date — record as such so WP doesn't keep asking.
			if ( isset( $transient->no_update ) ) {
				$transient->no_update[ $this->basename() ] = $this->build_item( $new_version, '' );
			}
			return $transient;
		}

		$package = $this->package_url( $release );
		if ( '' === $package ) {
			return $transient;
		}

		$transient->response[ $this->basename() ] = $this->build_item( $new_version, $package );
		return $transient;
	}

	/**
	 * Build the update item object WordPress expects.
	 *
	 * @param string $new_version New version.
	 * @param string $package     Download URL ('' if none).
	 * @return object
	 */
	private function build_item( $new_version, $package ) {
		return (object) array(
			'slug'         => $this->slug,
			'plugin'       => $this->basename(),
			'new_version'  => $new_version,
			'url'          => 'https://github.com/' . $this->repo,
			'package'      => $package,
			'icons'        => array(),
			'banners'      => array(),
			'tested'       => '',
			'requires_php' => '8.0',
		);
	}

	/**
	 * Provide the "View details" modal content.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action API action.
	 * @param object             $args   Args (expects ->slug).
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$data     = get_plugin_data( $this->file, false, false );
		$readme   = $this->parse_readme( $this->read_readme() );
		$headers  = $readme['headers'];
		$sections = $readme['sections'];

		// A GitHub release is optional — it only enriches version/date/download.
		$release = $this->latest_release();

		$version = ! empty( $headers['stable tag'] ) ? $headers['stable tag'] : $this->normalize( $this->version );
		if ( null !== $release && ! empty( $release['tag_name'] ) ) {
			$version = $this->normalize( $release['tag_name'] );
		}

		$description = ! empty( $sections['description'] )
			? $this->readme_html( $sections['description'] )
			: ( isset( $data['Description'] ) ? $data['Description'] : '' );

		$changelog = ! empty( $sections['changelog'] )
			? $this->readme_html( $sections['changelog'] )
			: ( ( null !== $release && ! empty( $release['body'] ) ) ? wp_kses_post( wpautop( $release['body'] ) ) : '' );

		$info = array(
			'name'          => isset( $data['Name'] ) ? $data['Name'] : $this->slug,
			'slug'          => $this->slug,
			'version'       => $version,
			'author'        => isset( $data['Author'] ) ? $data['Author'] : '',
			'homepage'      => 'https://github.com/' . $this->repo,
			'download_link' => null !== $release ? $this->package_url( $release ) : '',
			'requires'      => ! empty( $headers['requires at least'] ) ? $headers['requires at least'] : '6.0',
			'requires_php'  => ! empty( $headers['requires php'] ) ? $headers['requires php'] : '8.0',
			'tested'        => ! empty( $headers['tested up to'] ) ? $headers['tested up to'] : '',
			'sections'      => array(
				'description' => $description,
				'changelog'   => $changelog,
			),
		);

		if ( ! empty( $headers['contributors'] ) ) {
			$info['contributors'] = $this->csv_list( $headers['contributors'] );
		}
		if ( ! empty( $headers['tags'] ) ) {
			$info['tags'] = $this->csv_list( $headers['tags'] );
		}
		if ( null !== $release && ! empty( $release['published_at'] ) ) {
			$info['last_updated'] = $release['published_at'];
		}

		return (object) $info;
	}

	/**
	 * Ensure the extracted source folder is named after the slug so WordPress
	 * installs the update over the existing plugin folder.
	 *
	 * @param string $source        Extracted source directory.
	 * @param string $remote_source Remote source directory.
	 * @param object $upgrader      WP_Upgrader instance.
	 * @param array  $hook_extra    Extra args.
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename() ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $desired, true ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}
}
