<?php

declare(strict_types=1);

namespace PhpMyAdmin\Controllers\Database;

use PhpMyAdmin\Config\PageSettings;
use PhpMyAdmin\Controllers\AbstractController;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\SqlQueryForm;
use PhpMyAdmin\Template;
use PhpMyAdmin\Url;
use PhpMyAdmin\Util;

use function htmlspecialchars;

/**
 * Database SQL executor
 */
class SqlController extends AbstractController
{
    /** @var SqlQueryForm */
    private $sqlQueryForm;

    public function __construct(ResponseRenderer $response, Template $template, SqlQueryForm $sqlQueryForm)
    {
        parent::__construct($response, $template);
        $this->sqlQueryForm = $sqlQueryForm;
    }

    public function __invoke(): void
    {
        $this->addScriptFiles([
            'makegrid.js',
            'vendor/jquery/jquery.uitablefilter.js',
            'vendor/stickyfill.min.js',
            'sql.js',
        ]);

        $pageSettings = new PageSettings('Sql');
        $this->response->addHTML($pageSettings->getErrorHTML());
        $this->response->addHTML($pageSettings->getHTML());

        Util::checkParameters(['db']);

        $GLOBALS['errorUrl'] = Util::getScriptNameForOption($GLOBALS['cfg']['DefaultTabDatabase'], 'database');
        $GLOBALS['errorUrl'] .= Url::getCommon(['db' => $GLOBALS['db']], '&');

        if (! $this->hasDatabase()) {
            return;
        }

        /**
         * After a syntax error, we return to this script
         * with the typed query in the textarea.
         */
        $GLOBALS['goto'] = Url::getFromRoute('/database/sql');
        $GLOBALS['back'] = $GLOBALS['goto'];

        $this->response->addHTML($this->sqlQueryForm->getHtml(
            $GLOBALS['db'],
            '',
            true,
            false,
            isset($_POST['delimiter'])
                ? htmlspecialchars($_POST['delimiter'])
                : ';'
        ));
    }
}
