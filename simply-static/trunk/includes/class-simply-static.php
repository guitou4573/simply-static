<?php
/**
 * The core plugin class.
 *
 * @package Simply_Static
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Simply_Static {
	/**
	 * Plugin version
	 */
	const VERSION = '1.0.0';

	/**
	 * The slug of the plugin; used in actions, filters, i18n, etc.
	 */
	const SLUG = 'simply-static';

	/**
	 * Singleton instance
	 * @var Simply_Static
	 */
	protected static $instance = null;

	/**
	 * An instance of the options structure containing all options for this plugin
	 * @var Simply_Static_Options
	 */
	protected $options = null;

	/**
	 * View object
	 * @var Simply_Static_View
	 */
	protected $view = null;

	/**
	 * Export log (list of processed urls)
	 * @var array
	 */
	protected $export_log = array();

	/**
	 * Disable usage of "new"
	 * @return void
	 */
	protected function __construct() {}

	/**
	 * Disable cloning of the class
	 * @return void
	 */
	protected function __clone() {}

	/**
	 * Disable unserializing of the class
	 * @return void
	 */
	public function __wakeup() {}

	/**
	 * Return an instance of the Simply Static plugin
	 * @return Simply_Static
	 */
	public static function instance()
	{
		if ( null === self::$instance )
		{
			self::$instance = new self();
			self::$instance->includes();
			self::$instance->options = new Simply_Static_Options( self::SLUG );
			self::$instance->view = new Simply_Static_View();

			// Load the text domain for i18n
			add_action( 'plugins_loaded', array( self::$instance, 'load_textdomain' ) );
			// Enqueue admin styles and scripts
			add_action( 'admin_enqueue_scripts', array( self::$instance, 'enqueue_admin_styles' ) );
			// Add the options page and menu item.
			add_action( 'admin_menu', array( self::$instance, 'add_plugin_admin_menu' ), 2 );
		}

		return self::$instance;
	}

	/**
	 * Initialize singleton instance
	 * @param string $bootstrap_file
	 * @return Simply_Static
	 */
	public static function init( $bootstrap_file )
	{
		$instance = self::instance();

		// Activation
		register_activation_hook( $bootstrap_file, array( $instance, 'activate' ) );

		return $instance;
	}

	/**
	 * Performs activation
	 * @return void
	 */
	public function activate()
	{
		// Not installed?
		//if (null === $this->options->get_option('version'))
		//{
			$this->options
				->set( 'version', self::VERSION )
				->set( 'origin_scheme', is_ssl() ? 'https' : 'http' )
				->set( 'origin_host', preg_replace( "(^https?://)", "", home_url() ) )
				->set( 'destination_scheme', '' )
				->set( 'destination_host', '' )
				->set( 'additional_urls', '' )
				->set( 'generate_zip', '0' )
				->set( 'retain_static_files', '0' )
				->save();
		//}
	}


	/**
	 * Include required files
	 * @return void
	 */
	private function includes() {
		require plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-simply-static-options.php';
		require plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-simply-static-view.php';
		require plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-simply-static-url-request.php';
	}

	/**
	 * Enqueue admin-specific style sheets for this plugin's admin pages only
	 * @return    null    Return early if no settings page is registered.
	 */
	public function enqueue_admin_styles() {
		// Plugin admin CSS. Tack on plugin version.
		wp_enqueue_style( self::SLUG . '-admin-styles', plugins_url( 'css/admin.css', __FILE__ ), array(), self::VERSION );
	}

	/**
	 * Register the administration menu for this plugin into the WordPress Dashboard menu.
	 * @return void
	 */
	public function add_plugin_admin_menu() {
		// Add main menu item
		add_menu_page(
			__( 'Simply Static Settings', self::SLUG ),
			__( 'Simply Static', self::SLUG ),
			'generate_static_site',
			self::SLUG,
			array( self::$instance, 'display_generate_page' ),
			plugins_url( 'images/icon-16x16.png', __FILE__ )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Generate Static Site', self::SLUG ),
			__( 'Generate', self::SLUG ),
			'generate_static_site',
			self::SLUG,
			array( self::$instance, 'display_generate_page' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Simply Static Settings', self::SLUG ),
			__( 'Settings', self::SLUG ),
			'manage_options',
			self::SLUG,
			array( self::$instance, 'display_options_page' )
		);
	}

	/**
	 * Render the page for generating a static site.
	 * @return void
	 */
	public function display_generate_page() {
		if ( isset($_POST['generate']) ) {
			$archive_dir = $this->generate_archive();
		} else {
			$archive_dir = null;
		}

		$this->view
			->set_template( 'generate' )
			->assign( 'export_log', $this->export_log )
			->assign( 'archive_dir', $archive_dir )
			->render();
	}

	/**
	 * Render the options page.
	 * @return void
	 */
	public function display_options_page() {
		$this->save_options();

		$this->view
			->set_template( 'options' )
			->assign( 'slug', self::SLUG )
			->assign( 'origin_scheme', $this->options->get( 'origin_scheme' ) )
			->assign( 'origin_host', $this->options->get( 'origin_host' ) )
			->assign( 'destination_scheme', $this->options->get( 'destination_scheme' ) )
			->assign( 'destination_host', $this->options->get( 'destination_host' ) )
			->assign( 'additional_urls', $this->options->get( 'additional_urls' ) )
			->assign( 'generate_zip', $this->options->get( 'generate_zip' ) )
			->assign( 'retain_static_files', $this->options->get( 'retain_static_files' ) )
			->render();
	}

	/**
	 * Save the options from the options page.
	 * @return void
	 */
	public function save_options() {
		$this->options
			->set( 'origin_scheme', filter_input( INPUT_POST, 'origin_scheme' ) )
			->set( 'origin_host', untrailingslashit( filter_input( INPUT_POST, 'origin_host', FILTER_SANITIZE_URL ) ) )
			->set( 'destination_scheme', filter_input( INPUT_POST, 'destination_scheme' ) )
			->set( 'destination_host', untrailingslashit( filter_input( INPUT_POST, 'destination_host', FILTER_SANITIZE_URL ) ) )
			->set( 'additional_urls', filter_input( INPUT_POST, 'additional_urls' ) )
			->set( 'generate_zip', filter_input( INPUT_POST, 'generate_zip' ) )
			->set( 'retain_static_files', filter_input( INPUT_POST, 'retain_static_files' ) )
			->save();
	}

	/**
	 * Loads the plugin language files
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			self::SLUG,
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}

	/**
	 * Create a static version of the site
	 * @return string $archive_dir The directory of the static files
	 */
	public function generate_archive() {
		global $blog_id;
		// TODO: Do ajax calls instead of just running forever and ever
		set_time_limit(0);

		// Create archive directory
		$upload_dir = wp_upload_dir();
		$current_user = wp_get_current_user();

		$archive_name = $upload_dir['path'] . '/' . self::SLUG . $blog_id . '-' . time() . $current_user->user_login;
		$archive_dir = $archive_name . '/';

		if ( ! file_exists( $archive_dir ) ) {
			wp_mkdir_p( $archive_dir );
		}

		// Add URLs to queue
		$origin_url = $this->options->get('origin_scheme') . '://' . $this->options->get('origin_host');
		$destination_url = $this->options->get('destination_scheme') . '://' . $this->options->get('destination_host');
		$urls_queue = array_unique( array_merge(
			array( trailingslashit( $origin_url ) ),
			$this->get_list_of_local_files_by_url( array( get_template_directory_uri() ) ),
			$this->get_list_of_local_files_by_url( explode( "\n", $this->options->get("additional_urls" ) ) )
		) );

		while ( count( $urls_queue ) ) {
			$current_url = array_shift( $urls_queue );

			// TODO: Just for testing
			echo "Processing: " . $current_url;

			$request = new Simply_Static_Url_Request( $current_url );

			$this->export_log[] = $current_url;

			// Fetch all URLs from the page and add them to the queue...
			$urls = $request->extract_all_urls( $origin_url );
			foreach ( $urls as $url ) {
				// ...assuming they're not a URL we've already processed
				// and they're not the same as the URL we got them from,
				// and they're not already in the queue to be processed
				if ( ! in_array( $url, $this->export_log ) && $url != $current_url && ! in_array( $url, $urls_queue ) ) {
					$urls_queue[] = $url;
				}
			}

			// Replace the origin URL with the destination URL
			$request->replace_url( $origin_url, $destination_url );

			// Save the page to our archive
			$url_parts = parse_url( $request->get_url() );
			$path = $url_parts['path'];
			$content = $url->get_response_body();
			$is_html = $request->is_html();
			$this->save_url_to_file( $path, $content, $is_html, $archive_dir );

			// TODO:
			// - Generate ZIP
			// - Delete file contents unless requested to keep

			return $archive_dir;
		}
	}

	/**
	 * Save the contents of a page to a file in our archive directory
	 * @param string $path The relative path for the URL to save
	 * @param string $content The contents of the page we want to save
	 * @param boolean $is_html Is this an html page?
	 * @param string The path to the archive directory
	 * @return void
	 */
	public function save_url_to_file( $path, $content, $is_html, $archive_dir ) {
		$path_info = pathinfo( $path && $path != '/' ? $path : 'index.html' )

		// Create file directory if it doesn't exist
		$file_dir = $archive_dir . ( $path_info['dirname'] ? $path_info['dirname'] : '' );
		if ( empty( $path_info['extension'] ) && $path_info['basename'] == $path_info['filename'] ) {
			$file_dir .= '/' . $path_info['basename'];
			$path_info['filename'] = 'index';
		}
		if ( ! file_exists( $file_dir ) ) {
			wp_mkdir_p( $file_dir );
		}

		// Save file contents
		$file_extension = ( $is_html || ! isset( $path_info['extension'] ) ) ? 'html' : $path_info['extension'];
		$file_name = $file_dir . '/' . $path_info['filename'] . '.' . $file_extension;
		file_put_contents( $file_name, $content );
	}
}