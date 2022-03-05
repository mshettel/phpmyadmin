<?php

declare(strict_types=1);

namespace PhpMyAdmin;

use PhpMyAdmin\ConfigStorage\Relation;
use PhpMyAdmin\Dbal\DatabaseName;
use PhpMyAdmin\Dbal\TableName;
use PhpMyAdmin\Http\Factory\ServerRequestFactory;
use PhpMyAdmin\Http\ServerRequest;
use PhpMyAdmin\Plugins\AuthenticationPlugin;
use PhpMyAdmin\SqlParser\Lexer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webmozart\Assert\Assert;
use Webmozart\Assert\InvalidArgumentException;

use function __;
use function array_pop;
use function count;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function define;
use function defined;
use function explode;
use function extension_loaded;
use function function_exists;
use function hash_equals;
use function htmlspecialchars;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function is_scalar;
use function is_string;
use function mb_internal_encoding;
use function mb_strlen;
use function mb_strpos;
use function mb_strrpos;
use function mb_substr;
use function register_shutdown_function;
use function session_id;
use function strlen;
use function trigger_error;
use function urldecode;

use const E_USER_ERROR;

final class Common
{
    /** @var ServerRequest|null */
    private static $request = null;

    /**
     * Misc stuff and REQUIRED by ALL the scripts.
     * MUST be included by every script
     *
     * Among other things, it contains the advanced authentication work.
     *
     * Order of sections:
     *
     * the authentication libraries must be before the connection to db
     *
     * ... so the required order is:
     *
     * LABEL_variables_init
     *  - initialize some variables always needed
     * LABEL_parsing_config_file
     *  - parsing of the configuration file
     * LABEL_loading_language_file
     *  - loading language file
     * LABEL_setup_servers
     *  - check and setup configured servers
     * LABEL_theme_setup
     *  - setting up themes
     *
     * - load of MySQL extension (if necessary)
     * - loading of an authentication library
     * - db connection
     * - authentication work
     */
    public static function run(): void
    {
        $request = self::getRequest();

        $route = Routing::getCurrentRoute();

        if ($route === '/import-status') {
            $GLOBALS['isMinimumCommon'] = true;
        }

        $GLOBALS['containerBuilder'] = Core::getContainerBuilder();

        $GLOBALS['errorHandler'] = $GLOBALS['containerBuilder']->get('error_handler');

        self::checkRequiredPhpExtensions();
        self::configurePhpSettings();
        self::cleanupPathInfo();

        /* parsing configuration file                  LABEL_parsing_config_file      */

        /** Indication for the error handler */
        $GLOBALS['isConfigLoading'] = false;

        register_shutdown_function([Config::class, 'fatalErrorHandler']);

        /**
         * Force reading of config file, because we removed sensitive values
         * in the previous iteration.
         */
        $GLOBALS['config'] = $GLOBALS['containerBuilder']->get('config');

        /**
         * include session handling after the globals, to prevent overwriting
         */
        if (! defined('PMA_NO_SESSION')) {
            Session::setUp($GLOBALS['config'], $GLOBALS['errorHandler']);
        }

        $request = Core::populateRequestWithEncryptedQueryParams($request);

        /**
         * init some variables LABEL_variables_init
         */

        /**
         * holds parameters to be passed to next page
         *
         * @global array $urlParams
         */
        $GLOBALS['urlParams'] = [];
        $GLOBALS['containerBuilder']->setParameter('url_params', $GLOBALS['urlParams']);

        self::setGotoAndBackGlobals($GLOBALS['containerBuilder'], $GLOBALS['config']);
        self::checkTokenRequestParam();
        self::setDatabaseAndTableFromRequest($GLOBALS['containerBuilder'], $request);

        /**
         * SQL query to be executed
         *
         * @global string $sql_query
         */
        $GLOBALS['sql_query'] = '';
        if ($request->isPost()) {
            $GLOBALS['sql_query'] = $request->getParsedBodyParam('sql_query');
            if (! is_string($GLOBALS['sql_query'])) {
                $GLOBALS['sql_query'] = '';
            }
        }

        $GLOBALS['containerBuilder']->setParameter('sql_query', $GLOBALS['sql_query']);

        //$_REQUEST['set_theme'] // checked later in this file LABEL_theme_setup
        //$_REQUEST['server']; // checked later in this file
        //$_REQUEST['lang'];   // checked by LABEL_loading_language_file

        /* loading language file                       LABEL_loading_language_file    */

        /**
         * lang detection is done here
         */
        $language = LanguageManager::getInstance()->selectLanguage();
        $language->activate();

        /**
         * check for errors occurred while loading configuration
         * this check is done here after loading language files to present errors in locale
         */
        $GLOBALS['config']->checkPermissions();
        $GLOBALS['config']->checkErrors();

        self::checkServerConfiguration();
        self::checkRequest();

        /* setup servers                                       LABEL_setup_servers    */

        $GLOBALS['config']->checkServers();

        /**
         * current server
         *
         * @global integer $server
         */
        $GLOBALS['server'] = $GLOBALS['config']->selectServer();
        $GLOBALS['urlParams']['server'] = $GLOBALS['server'];
        $GLOBALS['containerBuilder']->setParameter('server', $GLOBALS['server']);
        $GLOBALS['containerBuilder']->setParameter('url_params', $GLOBALS['urlParams']);

        $GLOBALS['cfg'] = $GLOBALS['config']->settings;

        /* setup themes                                          LABEL_theme_setup    */

        $GLOBALS['theme'] = ThemeManager::initializeTheme();

        $GLOBALS['dbi'] = null;

        if (isset($GLOBALS['isMinimumCommon'])) {
            $GLOBALS['config']->loadUserPreferences();
            $GLOBALS['containerBuilder']->set('theme_manager', ThemeManager::getInstance());
            Tracker::enable();

            return;
        }

        /**
         * save some settings in cookies
         *
         * @todo should be done in PhpMyAdmin\Config
         */
        $GLOBALS['config']->setCookie('pma_lang', (string) $GLOBALS['lang']);

        ThemeManager::getInstance()->setThemeCookie();

        $GLOBALS['dbi'] = DatabaseInterface::load();
        $GLOBALS['containerBuilder']->set(DatabaseInterface::class, $GLOBALS['dbi']);
        $GLOBALS['containerBuilder']->setAlias('dbi', DatabaseInterface::class);

        if (! empty($GLOBALS['cfg']['Server'])) {
            $GLOBALS['config']->getLoginCookieValidityFromCache($GLOBALS['server']);

            $GLOBALS['auth_plugin'] = Plugins::getAuthPlugin();
            $GLOBALS['auth_plugin']->authenticate();

            /* Enable LOAD DATA LOCAL INFILE for LDI plugin */
            if ($route === '/import' && ($_POST['format'] ?? '') === 'ldi') {
                // Switch this before the DB connection is done
                // phpcs:disable PSR1.Files.SideEffects
                define('PMA_ENABLE_LDI', 1);
                // phpcs:enable
            }

            self::connectToDatabaseServer($GLOBALS['dbi'], $GLOBALS['auth_plugin']);

            $GLOBALS['auth_plugin']->rememberCredentials();

            $GLOBALS['auth_plugin']->checkTwoFactor();

            /* Log success */
            Logging::logUser($GLOBALS['cfg']['Server']['user']);

            if ($GLOBALS['dbi']->getVersion() < $GLOBALS['cfg']['MysqlMinVersion']['internal']) {
                Core::fatalError(
                    __('You should upgrade to %s %s or later.'),
                    [
                        'MySQL',
                        $GLOBALS['cfg']['MysqlMinVersion']['human'],
                    ]
                );
            }

            // Sets the default delimiter (if specified).
            $sqlDelimiter = $request->getParam('sql_delimiter', '');
            if (strlen($sqlDelimiter) > 0) {
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps
                Lexer::$DEFAULT_DELIMITER = $sqlDelimiter;
            }

            // TODO: Set SQL modes too.
        } else { // end server connecting
            $response = ResponseRenderer::getInstance();
            $response->getHeader()->disableMenuAndConsole();
            $response->getFooter()->setMinimal();
        }

        $response = ResponseRenderer::getInstance();

        /**
         * There is no point in even attempting to process
         * an ajax request if there is a token mismatch
         */
        if ($response->isAjax() && $request->isPost() && $GLOBALS['token_mismatch']) {
            $response->setRequestStatus(false);
            $response->addJSON(
                'message',
                Message::error(__('Error: Token mismatch'))
            );
            exit;
        }

        Profiling::check($GLOBALS['dbi'], $response);

        $GLOBALS['containerBuilder']->set('response', ResponseRenderer::getInstance());

        // load user preferences
        $GLOBALS['config']->loadUserPreferences();

        $GLOBALS['containerBuilder']->set('theme_manager', ThemeManager::getInstance());

        /* Tell tracker that it can actually work */
        Tracker::enable();

        if (empty($GLOBALS['server']) || ! isset($GLOBALS['cfg']['ZeroConf']) || $GLOBALS['cfg']['ZeroConf'] !== true) {
            return;
        }

        /** @var Relation $relation */
        $relation = $GLOBALS['containerBuilder']->get('relation');
        $GLOBALS['dbi']->postConnectControl($relation);
    }

    /**
     * Checks that required PHP extensions are there.
     */
    private static function checkRequiredPhpExtensions(): void
    {
        /**
         * Warning about mbstring.
         */
        if (! function_exists('mb_detect_encoding')) {
            Core::warnMissingExtension('mbstring');
        }

        /**
         * We really need this one!
         */
        if (! function_exists('preg_replace')) {
            Core::warnMissingExtension('pcre', true);
        }

        /**
         * JSON is required in several places.
         */
        if (! function_exists('json_encode')) {
            Core::warnMissingExtension('json', true);
        }

        /**
         * ctype is required for Twig.
         */
        if (! function_exists('ctype_alpha')) {
            Core::warnMissingExtension('ctype', true);
        }

        /**
         * hash is required for cookie authentication.
         */
        if (function_exists('hash_hmac')) {
            return;
        }

        Core::warnMissingExtension('hash', true);
    }

    /**
     * Applies changes to PHP configuration.
     */
    private static function configurePhpSettings(): void
    {
        /**
         * Set utf-8 encoding for PHP
         */
        ini_set('default_charset', 'utf-8');
        mb_internal_encoding('utf-8');

        /**
         * Set precision to sane value, with higher values
         * things behave slightly unexpectedly, for example
         * round(1.2, 2) returns 1.199999999999999956.
         */
        ini_set('precision', '14');

        /**
         * check timezone setting
         * this could produce an E_WARNING - but only once,
         * if not done here it will produce E_WARNING on every date/time function
         */
        date_default_timezone_set(@date_default_timezone_get());
    }

    /**
     * PATH_INFO could be compromised if set, so remove it from PHP_SELF
     * and provide a clean PHP_SELF here
     */
    public static function cleanupPathInfo(): void
    {
        $GLOBALS['PMA_PHP_SELF'] = Core::getenv('PHP_SELF');
        if (empty($GLOBALS['PMA_PHP_SELF'])) {
            $GLOBALS['PMA_PHP_SELF'] = urldecode(Core::getenv('REQUEST_URI'));
        }

        $_PATH_INFO = Core::getenv('PATH_INFO');
        if (! empty($_PATH_INFO) && ! empty($GLOBALS['PMA_PHP_SELF'])) {
            $question_pos = mb_strpos($GLOBALS['PMA_PHP_SELF'], '?');
            if ($question_pos != false) {
                $GLOBALS['PMA_PHP_SELF'] = mb_substr($GLOBALS['PMA_PHP_SELF'], 0, $question_pos);
            }

            $path_info_pos = mb_strrpos($GLOBALS['PMA_PHP_SELF'], $_PATH_INFO);
            if ($path_info_pos !== false) {
                $path_info_part = mb_substr($GLOBALS['PMA_PHP_SELF'], $path_info_pos, mb_strlen($_PATH_INFO));
                if ($path_info_part == $_PATH_INFO) {
                    $GLOBALS['PMA_PHP_SELF'] = mb_substr($GLOBALS['PMA_PHP_SELF'], 0, $path_info_pos);
                }
            }
        }

        $path = [];
        foreach (explode('/', $GLOBALS['PMA_PHP_SELF']) as $part) {
            // ignore parts that have no value
            if (empty($part) || $part === '.') {
                continue;
            }

            if ($part !== '..') {
                // cool, we found a new part
                $path[] = $part;
            } elseif (count($path) > 0) {
                // going back up? sure
                array_pop($path);
            }

            // Here we intentionall ignore case where we go too up
            // as there is nothing sane to do
        }

        $GLOBALS['PMA_PHP_SELF'] = htmlspecialchars('/' . implode('/', $path));
    }

    private static function setGotoAndBackGlobals(ContainerInterface $container, Config $config): void
    {
        // Holds page that should be displayed.
        $GLOBALS['goto'] = '';
        $container->setParameter('goto', $GLOBALS['goto']);

        if (isset($_REQUEST['goto']) && Core::checkPageValidity($_REQUEST['goto'])) {
            $GLOBALS['goto'] = $_REQUEST['goto'];
            $GLOBALS['urlParams']['goto'] = $GLOBALS['goto'];
            $container->setParameter('goto', $GLOBALS['goto']);
            $container->setParameter('url_params', $GLOBALS['urlParams']);
        } else {
            if ($config->issetCookie('goto')) {
                $config->removeCookie('goto');
            }

            unset($_REQUEST['goto'], $_GET['goto'], $_POST['goto']);
        }

        if (isset($_REQUEST['back']) && Core::checkPageValidity($_REQUEST['back'])) {
            // Returning page.
            $GLOBALS['back'] = $_REQUEST['back'];
            $container->setParameter('back', $GLOBALS['back']);

            return;
        }

        if ($config->issetCookie('back')) {
            $config->removeCookie('back');
        }

        unset($_REQUEST['back'], $_GET['back'], $_POST['back']);
    }

    /**
     * Check whether user supplied token is valid, if not remove any possibly
     * dangerous stuff from request.
     *
     * Check for token mismatch only if the Request method is POST.
     * GET Requests would never have token and therefore checking
     * mis-match does not make sense.
     */
    public static function checkTokenRequestParam(): void
    {
        $GLOBALS['token_mismatch'] = true;
        $GLOBALS['token_provided'] = false;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        if (isset($_POST['token']) && is_scalar($_POST['token']) && strlen((string) $_POST['token']) > 0) {
            $GLOBALS['token_provided'] = true;
            $GLOBALS['token_mismatch'] = ! @hash_equals($_SESSION[' PMA_token '], (string) $_POST['token']);
        }

        if (! $GLOBALS['token_mismatch']) {
            return;
        }

        // Warn in case the mismatch is result of failed setting of session cookie
        if (isset($_POST['set_session']) && $_POST['set_session'] !== session_id()) {
            trigger_error(
                __(
                    'Failed to set session cookie. Maybe you are using HTTP instead of HTTPS to access phpMyAdmin.'
                ),
                E_USER_ERROR
            );
        }

        /**
         * We don't allow any POST operation parameters if the token is mismatched
         * or is not provided.
         */
        $allowList = ['ajax_request'];
        Sanitize::removeRequestVars($allowList);
    }

    private static function setDatabaseAndTableFromRequest(
        ContainerInterface $containerBuilder,
        ServerRequest $request
    ): void {
        try {
            $GLOBALS['db'] = DatabaseName::fromValue($request->getParam('db'))->getName();
        } catch (InvalidArgumentException $exception) {
            $GLOBALS['db'] = '';
        }

        try {
            Assert::stringNotEmpty($GLOBALS['db']);
            $GLOBALS['table'] = TableName::fromValue($request->getParam('table'))->getName();
        } catch (InvalidArgumentException $exception) {
            $GLOBALS['table'] = '';
        }

        if (! is_array($GLOBALS['urlParams'])) {
            $GLOBALS['urlParams'] = [];
        }

        $GLOBALS['urlParams']['db'] = $GLOBALS['db'];
        $GLOBALS['urlParams']['table'] = $GLOBALS['table'];
        $containerBuilder->setParameter('url_params', $GLOBALS['urlParams']);
    }

    /**
     * Check whether PHP configuration matches our needs.
     */
    private static function checkServerConfiguration(): void
    {
        /**
         * As we try to handle charsets by ourself, mbstring overloads just
         * break it, see bug 1063821.
         *
         * We specifically use empty here as we are looking for anything else than
         * empty value or 0.
         */
        if (extension_loaded('mbstring') && ! empty(ini_get('mbstring.func_overload'))) {
            Core::fatalError(
                __(
                    'You have enabled mbstring.func_overload in your PHP '
                    . 'configuration. This option is incompatible with phpMyAdmin '
                    . 'and might cause some data to be corrupted!'
                )
            );
        }

        /**
         * The ini_set and ini_get functions can be disabled using
         * disable_functions but we're relying quite a lot of them.
         */
        if (function_exists('ini_get') && function_exists('ini_set')) {
            return;
        }

        Core::fatalError(
            __(
                'The ini_get and/or ini_set functions are disabled in php.ini. phpMyAdmin requires these functions!'
            )
        );
    }

    /**
     * Checks request and fails with fatal error if something problematic is found
     */
    private static function checkRequest(): void
    {
        if (isset($_REQUEST['GLOBALS']) || isset($_FILES['GLOBALS'])) {
            Core::fatalError(__('GLOBALS overwrite attempt'));
        }

        /**
         * protect against possible exploits - there is no need to have so much variables
         */
        if (count($_REQUEST) <= 1000) {
            return;
        }

        Core::fatalError(__('possible exploit'));
    }

    private static function connectToDatabaseServer(DatabaseInterface $dbi, AuthenticationPlugin $auth): void
    {
        /**
         * Try to connect MySQL with the control user profile (will be used to get the privileges list for the current
         * user but the true user link must be open after this one so it would be default one for all the scripts).
         */
        $controlLink = false;
        if ($GLOBALS['cfg']['Server']['controluser'] !== '') {
            $controlLink = $dbi->connect(DatabaseInterface::CONNECT_CONTROL);
        }

        // Connects to the server (validates user's login)
        $userLink = $dbi->connect(DatabaseInterface::CONNECT_USER);

        if ($userLink === false) {
            $auth->showFailure('mysql-denied');
        }

        if ($controlLink) {
            return;
        }

        /**
         * Open separate connection for control queries, this is needed to avoid problems with table locking used in
         * main connection and phpMyAdmin issuing queries to configuration storage, which is not locked by that time.
         */
        $dbi->connect(DatabaseInterface::CONNECT_USER, null, DatabaseInterface::CONNECT_CONTROL);
    }

    public static function getRequest(): ServerRequest
    {
        if (self::$request === null) {
            self::$request = ServerRequestFactory::createFromGlobals();
        }

        return self::$request;
    }
}
