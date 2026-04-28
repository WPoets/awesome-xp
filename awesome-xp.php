<?php
/*
Plugin Name: Awesome XP
Plugin URI: http://www.awxdocs.com
Description: Awesome XP is a shortcode-based low code/No Code execution platform with useful services and apps enabling us to easily create custom WordPress workflows. 
Version: 1.0.0
Author: WPoets Team
Author URI: http://www.wpoets.com
License: GPLv3 or Later
*/

if (!defined('IS_WP'))define('IS_WP', true);
if (!defined('AWESOME_DEBUG'))define('AWESOME_DEBUG', false);

$site_id = get_current_blog_id();
if (!defined('ENV_CACHE_KEY'))define('ENV_CACHE_KEY', 'env_cache-'.$site_id.$table_prefix.DB_NAME);


if (!defined('AWESOME_CORE_POST_TYPE'))define('AWESOME_CORE_POST_TYPE', 'awesome_core');
if (!defined('AWESOME_APPS_POST_TYPE'))define('AWESOME_APPS_POST_TYPE', 'aw2_app');

if (!defined('REQUEST_START_POINT'))define('REQUEST_START_POINT', '');

if (!defined('AWESOME_PATH'))define('AWESOME_PATH', __DIR__.'/core');

define('AWESOME_APP_BASE_PATH', SITE_URL . REQUEST_START_POINT);

define('HANDLERS_PATH', __DIR__.'/core/core-handlers');
define('WP_HANDLERS_PATH',  __DIR__ .'/wp/handlers');
define('EXTRA_HANDLERS_PATH', __DIR__.'/core/extra-handlers');

require  __DIR__ .'/core/vendor/autoload.php';


$plugin_data = get_file_data(__FILE__, array('Version' => 'Version'), false);
define('AWE_VERSION',$plugin_data['Version']);



$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
  'https://github.com/WPoets/awesome-no-code-platform',
  __FILE__,
  'awesome-no-code-platform'
);

//Optional: Check for automatical release
$myUpdateChecker->getVcsApi()->enableReleaseAssets();
/*********************** plugin-update-checker code end ***************************/
\register_activation_hook( __FILE__, 'aw2\db_delta\create_awesome_tables' );



require_once __DIR__.'/core/includes/util.php';
require_once __DIR__.'/core/includes/aw2_library.php';
require_once __DIR__.'/core/includes/error_log.php';

require_once __DIR__.'/core/includes/awesome_flow.php';
require_once __DIR__.'/core/includes/awesome_app.php';
require_once __DIR__.'/core/includes/awesome_auth.php';
require_once __DIR__.'/core/includes/awesome-controllers.php';

require_once __DIR__ .'/wp/includes/awesome-wp-util.php';
require_once __DIR__ .'/wp/includes/apps_setup_wp.php';
require_once __DIR__ .'/wp/includes/app_seo.php';
require_once __DIR__ .'/wp/includes/awesome-menus.php';
require_once __DIR__ .'/wp/includes/app-rights.php';


require_once __DIR__ .'/wp/includes/monoframe.php';
require_once __DIR__ .'/wp/includes/wordpress-hooks.php';
require_once __DIR__ .'/wp/libraries/db-delta.php';


aw2_library::load_handlers_from_path(HANDLERS_PATH,'structure','lang','cache','session');
aw2_library::load_handlers_from_path(HANDLERS_PATH,'utils');
aw2_library::load_handlers_from_path(HANDLERS_PATH,'database');
aw2_library::load_handlers_from_path(HANDLERS_PATH,'front-end');
aw2_library::load_handlers_from_path(HANDLERS_PATH,'controllers','connectors');

aw2_library::load_handlers_from_path(WP_HANDLERS_PATH,'wp');
aw2_library::load_all_extra_handlers();

add_action( 'init', function() {
  do_action( 'awesome_is_ready');
},1);
