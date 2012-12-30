<?php defined('BASEPATH') OR exit('No direct script access allowed');

require APPPATH."Library/MX/Controller.php";

/**
 * Code here is run before ALL controllers
 * 
 * @package     PyroCMS\Core\Controllers
 * @author      PyroCMS Dev Team
 * @copyright   Copyright (c) 2012, PyroCMS LLC
 */
class MY_Controller extends MX_Controller
{
    /**
     * The name of the module that this controller instance actually belongs to.
     * @TODO Store the actual module class here instead of a string
     * @var string 
     */
    public $module;

    /**
     * The name of the controller class for the current class instance.
     *
     * @var string
     */
    public $controller;

    /**
     * The name of the method for the current request.
     *
     * @var string 
     */
    public $method;

    /**
     * Load and set data for some common used libraries.
     */
    public function __construct()
    {
        parent::__construct();

        $this->benchmark->mark('my_controller_start');

        // No record? Probably DNS'ed but not added to multisite
        if ( ! defined('SITE_REF')) {
            show_error('This domain is not set up correctly. Please go to '.anchor('sites').' and log in to add this site.');
        }

        // Set up the Illuminate\Database layer
        ci()->pdb = self::setupDatabase();

        // the Quick\Cache package is instantiated to $this->cache in the config file
        $this->load->config('cache');

        // Add the site specific theme folder
        $this->template->add_theme_location(ADDONPATH.'themes/');

        // Migration logic helps to make sure PyroCMS is running the latest changes
        $this->load->library('migration');
        
        if ( ! ($schema_version = $this->migration->current())) {
            show_error($this->migration->error_string());
        }

        // Result of schema version migration
        elseif (is_numeric($schema_version)) {
            log_message('debug', 'PyroCMS was migrated to version: ' . $schema_version);
        }

        // @TODO Switch to use L4's alias system
        class_exists('Settings') or class_alias('\\Module\\Settings\\Library\\Settings', 'Settings');

        // With that done, load settings
        $this->settings = new Settings;
        $this->settings->getAll();

        // And session stuff too
        $this->load->driver('session');

        // Lock front-end language
        if ( ! ($this instanceof Admin_Controller and ($site_lang = AUTO_LANGUAGE)))
        {
            $site_public_lang = explode(',', Settings::get('site_public_lang'));

            $site_lang = in_array(AUTO_LANGUAGE, $site_public_lang) ? AUTO_LANGUAGE : Settings::get('site_lang');
        }

        // What language us being used
        defined('CURRENT_LANGUAGE') or define('CURRENT_LANGUAGE', $site_lang);

        $langs = $this->config->item('supported_languages');

        $pyro['lang'] = $langs[CURRENT_LANGUAGE];
        $pyro['lang']['code'] = CURRENT_LANGUAGE;

        $this->load->vars($pyro);

        // Set php locale time
        if (isset($langs[CURRENT_LANGUAGE]['codes']) and sizeof($locale = (array) $langs[CURRENT_LANGUAGE]['codes']) > 1)
        {
            array_unshift($locale, LC_TIME);
            call_user_func_array('setlocale', $locale);
            unset($locale);
        }

        // Reload languages
        if (AUTO_LANGUAGE !== CURRENT_LANGUAGE)
        {
            $this->config->set_item('language', $langs[CURRENT_LANGUAGE]['folder']);
            $this->lang->is_loaded = array();
            $this->lang->load(array('errors', 'global', 'users/user', 'settings/settings', 'files/files'));
        }
        else
        {
            $this->lang->load(array('global', 'users/user', 'files/files'));
        }

        $this->load->library('users/ion_auth');

        // Get user data
        $this->template->current_user = ci()->current_user = $this->current_user = $this->ion_auth->get_user();

        // Work out module, controller and method and make them accessable throught the CI instance
        ci()->module = $this->module = $this->router->fetch_module();
        ci()->controller = $this->controller = $this->router->fetch_class();
        ci()->method = $this->method = $this->router->fetch_method();

        // Loaded after $this->current_user is set so that data can be used everywhere
        $this->load->model(array(
            'permissions/permission_m',
            'addons/module_m',
            'addons/theme_m',
        ));

        // List available module permissions for this user
        ci()->permissions = $this->permissions = $this->current_user ? $this->permission_m->get_group($this->current_user->group_id) : array();

        // load all modules (the Events library uses them all) and make their details widely available
        $enabled_modules = $this->module_m->getAll();

        // Set the default module details, to avoid breakofdoom on dashboard
        $this->module_details = array(
            'name' => null,
            'slug' => null,
            'version' => null,
            'description' => null,
            'skip_xss' => null,
            'is_frontend' => null,
            'is_backend' => null,
            'menu' => false,
            'enabled' => true,
            'sections' => array(),
            'shortcuts' => array(),
            'is_core' => null,
            'is_current' => null,
            'current_version' => null,
            'updated_on' => null
        );

        // now pick our current module out of the enabled modules array
        foreach ($enabled_modules as $module)
        {
            // @TODO Make a migration to upper case the first letters in the DB then remove this
            if (ucfirst($module['slug']) === $this->module)
            {
                // Set meta data for the module to be accessible system wide
                $this->module_details = $module;
                continue;
            }
        }

        // Assign to the template and HMVC too
        $this->template->module_details = ci()->module_details = $this->module_details;

        // Now we know the enabled modules, go and find all events and register them
        new \Library\Events($enabled_modules);

        // If the module is disabled then show a 404.
        if (empty($this->module_details['enabled'])) {
            show_404();
        }

        if ( ! $this->module_details['skip_xss']) {
            $_POST = $this->security->xss_clean($_POST);
        }

        // Assign "This" module as its own namespace
        if ($this->module and isset($this->module_details['path'])) {
            Asset::add_path('module', $this->module_details['path'].'/');
        }

        $this->load->vars($pyro);
        
        $this->benchmark->mark('my_controller_end');
        
        // Enable profiler on local box
        $group = $this->current_user->group;
        if ((isset($group) and $group === 'admin') and is_array($_GET) and array_key_exists('_debug', $_GET)) {
            unset($_GET['_debug']);
            $this->output->enable_profiler(true);
        }
    }

    public function setupDatabase()
    {
        // @TODO Get rid of this for 3.0
        if ( ! class_exists('CI_Model'))
        {
            load_class('Model', 'core');
        }

        $prefix = SITE_REF.'_';

        // By changing the prefix we are essentially "namespacing" each site
        $this->db->set_dbprefix($prefix);

        // Assign 
        $pdo = $this->db->get_connection();

        include APPPATH.'config/database.php';

        $config = $db[ENVIRONMENT];
        $subdriver = current(explode(':', $config['dsn']));

        // Is this a PDO connection?
        if ($pdo instanceof PDO) {

            $drivers = array(
                'mysql' => '\Illuminate\Database\MySqlConnection',
                'pgsql' => '\Illuminate\Database\PostgresConnection',
                'sqlite' => '\Illuminate\Database\SQLiteConnection',
            );

            // Make a connection instance with the existing PDO connection
            $conn = new $drivers[$subdriver]($pdo, $prefix);

            \Capsule\Database\Connection::setupResolverAndFactory();

            $resolver = \Capsule\Database\Connection::getResolver();
            $resolver->addConnection('default', $conn);
            $resolver->setDefaultConnection('default');

            \Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);

        // Not using the new PDO driver
        } else {

            $conn = \Capsule\Database\Connection::make('default', array(
                'driver' => $subdriver,
                'dsn' => $config["dsn"],
                'username' => $config["username"],
                'password' => $config["password"],
                'charset' => $config["char_set"],
                'collation' => $config["dbcollat"],
            ), true);
        }

        $conn->setFetchMode(PDO::FETCH_OBJ);

        return $conn;
    }
}

/**
 * CI
 *
 * Example: ci()->db->get('table');
 * 
 * @deprecated All of CodeIgniter is being deprecated, so stop using this ASAP
 * @return \CI_Controller
 */
function ci()
{
    return get_instance();
}
