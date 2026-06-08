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
			return $cached;
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
		if ( null === $release ) {
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

		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}

		$data = get_plugin_data( $this->file, false, false );

		return (object) array(
			'name'          => isset( $data['Name'] ) ? $data['Name'] : $this->slug,
			'slug'          => $this->slug,
			'version'       => $this->normalize( $release['tag_name'] ),
			'author'        => isset( $data['Author'] ) ? $data['Author'] : '',
			'homepage'      => 'https://github.com/' . $this->repo,
			'download_link' => $this->package_url( $release ),
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'sections'      => array(
				'description' => isset( $data['Description'] ) ? $data['Description'] : '',
				'changelog'   => isset( $release['body'] ) ? wp_kses_post( wpautop( $release['body'] ) ) : '',
			),
		);
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
