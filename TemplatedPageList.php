<?php

/**
 * Extension is similar to DynamicPageList & company, but the code is simpler
 * and the functionality is more advanced.
 *
 * Features:
 * - <subpagelist> tag produces a simple or templated list of pages selected by dynamic conditions
 * - {{#getsection|Title|section number}} parser function for extracting page sections
 * - Automatic AJAX display of subpages everywhere:
 *   $egSubpagelistAjaxNamespaces = array(NS_MAIN => true) setting enables this on namespaces specified.
 *   $egSubpagelistAjaxDisableRE is a regexp disables this on pages whose title match it.
 *
 * @package MediaWiki
 * @subpackage Extensions
 * @author Vitaliy Filippov <vitalif@mail.ru>, 2009+
 * @author based on SubPageList by Martin Schallnahs <myself@schaelle.de>, Rob Church <robchur@gmail.com>
 * @license GNU General Public Licence 2.0 or later
 * @link http://wiki.4intra.net/TemplatedPageList
 *
 * @TODO caching: save all <subpages> occurrences into the DB, save templatelinks, flush pages on page edits
 */

/**
 * Syntax is backwards compatible with Wikimedia's DynamicPageList syntax,
 * except for 'firstcategorydate' related stuff. The text inside <subpagelist>
 * is preprocessed, so you can use templates, magic words and parser functions
 * inside it. Options are specified one per line.
 *
 * <subpagelist>
 *
 *   namespace = Main|Talk|...       restrict list to some namespaces
 *   category = A|B|C
 *   category = D|E
 *     Restrict page list to pages which are in one of these categories.
 *     this option may be specified multiple times, following occurrences
 *     will be appended as a conjunction, i.e., the resulting expression will be
 *     (A or B or C) & (D or E). This is compatible with Wikimedia's DynamicPageList
 *     syntax, but allows more complex queries.
 *   subcategory = F|G
 *     Like previous, but recursively including all subcategories of F and G.
 *   notcategory = A                 exclude pages which are in category A
 *   notsubcategory = B              exclude pages from category B and all its subcategories
 *   parent = P                      restrict listing to subpages of P
 *   prefix = P                      restrict listing to pages whose title starts with P
 *                                   i.e. "parent=P" is equivalent to "prefix=P/"
 *   level = L or MIN..MAX           set wanted subpage nesting levels (i.e. number of '/' in title)
 *                                   it must be equal to L or be within MIN..MAX
 *   deepness = D or MIN..MAX        set wanted subpage nesting levels, relative to parent
 *   ignore = L1|L2|...              ignore pages which match L1 or L2 or ... LIKE patterns:
 *                                   '\_' and ' ' match single space
 *                                   '_' matches any single character
 *                                   '%' matches any substring
 *                                   '\%' matches single '%' character
 *   redirect = yes|no               restring listing to redirect or non-redirect pages
 *
 *   order|ordermethod = Y1 [asc|desc],Y2 [asc|desc],...
 *     Sort pages by Y1,Y2,..., asc|desc can be specified right after Yi, each Yi is one of:
 *       title
 *       titlewithoutnamespace
 *       lastedit
 *       user
 *       created|firstedit
 *       length|size
 *       popularity|pagecounter
 *   order = ASC or DESC             ascending or descending sort order for all Yi (compatibility)
 *   count|limit = N                 show at most N pages
 *   offset = M                      skip first M pages
 *
 *   output = simple|column|template
 *     Select output method. Simple is just a bullet-list with page titles and links.
 *     Column is a 3-column grouped view, just as on MediaWiki category pages.
 *     Templated view uses template for display. See 'template' option.
 *   template = X
 *     Use template:X for output. The template will be preprocessed just like when included
 *     into listed article. I.e. all standard MediaWiki magic variables ({{PAGENAME}} {{REVISIONDAY}} etc)
 *     will generate values corresponding to listed articles.
 *     Additionally, the following parameters are passed to this template:
 *       {{{index}}}                 list index, beginning at 0
 *       {{{number}}}                list index, beginning at 1
 *       {{{odd}}}                   is {{{number}}} odd? (1 or 0)
 *       {{{ns_N}}}                  N is namespace index, value is 1
 *       {{{title}}}                 full title
 *       {{{title_rel}}}             title relative to parent specified in options
 *
 *   suppresserrors|noerrors|silent = true
 *     suppress errors
 *
 * </subpagelist>
 */

if (!defined('MEDIAWIKI'))
{
    echo "This file is an extension to the MediaWiki software and cannot be used standalone.\n";
    die();
}

$wgExtensionFunctions[] = 'efTemplatedPageList';
$wgExtensionMessagesFiles['TemplatedPageList'] = dirname(__FILE__).'/TemplatedPageList.i18n.php';
$wgHooks['LanguageGetMagic'][] = 'efTemplatedPageListLanguageGetMagic';
$wgExtensionCredits['parserhook'][] = array(
    'name'    => 'Templated Page List',
    'author'  => 'Vitaliy Filippov',
    'url'     => 'http://wiki.4intra.net/TemplatedPageList',
    'version' => '2011-06-28',
);
$wgAjaxExportList[] = 'efAjaxSubpageList';

function efTemplatedPageList()
{
    global $wgParser, $wgHooks, $egSubpagelistAjaxNamespaces;
    $wgParser->setHook('pagelist', 'efRenderTemplatedPageList');
    $wgParser->setHook('subpages', 'efRenderTemplatedPageList');
    $wgParser->setHook('subpagelist', 'efRenderTemplatedPageList');
    $wgParser->setHook('dynamicpagelist', 'efRenderTemplatedPageList');
    $wgParser->setFunctionHook('getsection', 'efFunctionHookGetSection');
    if ($egSubpagelistAjaxNamespaces)
        $wgHooks['ArticleViewHeader'][] = 'efSubpageListAddLister';
}

function efTemplatedPageListLanguageGetMagic(&$magicWords, $langCode = "en")
{
    $magicWords['getsection'] = array(0, 'getsection');
    return true;
}

/**
 * Parser function returning numbered section of article text
 */
function efFunctionHookGetSection($parser, $num)
{
    $args = func_get_args();
    array_shift($args);
    array_shift($args);
    $args = implode('|', $args);
    $st = $parser->mStripState;
    $text = $parser->getSection($args, $num);
    $parser->mStripState = $st;
    return $text;
}

/**
 * This function outputs nested html list with all subpages of a specific page
 */
function efAjaxSubpageList($pagename)
{
    global $wgUser;
    $title = Title::newFromText($pagename);
    if (!$title)
        return '';
    $dbr = wfGetDB(DB_SLAVE);
    $res = $dbr->select(
        'page', '*', array(
            'page_namespace' => $title->getNamespace(),
            'page_title '.$dbr->buildLike($title->getDBkey().'/', $dbr->anyString()),
        ), __METHOD__,
        array('ORDER BY' => 'page_title')
    );
    $pagelevel = substr_count($title->getText(), '/');
    $rows = array();
    foreach ($res as $row)
    {
        $row->title = Title::newFromRow($row);
        // TODO IntraACL: batch right checking (probably LinkBatch)
        if (!$row->title->userCanRead())
            continue;
        $parts = explode('/', $row->page_title);
        $row->level = count($parts)-1;
        $row->last_part = $parts[$row->level];
        $s = $sp = '';
        for ($i = 0; $i < count($parts)-1; $i++)
        {
            $s .= $sp.$parts[$i];
            $sp = '/';
            if (!$rows[$s] && $i > $pagelevel)
                $rows[$s] = (object)array('page_title' => $s, 'level' => $i);
        }
        $rows[$row->page_title] = $row;
    }
    $res = NULL;
    if (!$rows)
        return '';
    $html = '';
    $stack = array($pagelevel);
    foreach ($rows as $row)
    {
        if ($row->title)
            $link = $wgUser->getSkin()->link($row->title, $row->title->getSubpageText());
        else
        {
            $link = str_replace('_', ' ', $row->page_title);
            if (($p = strrpos($link, '/')) !== false)
                $link = substr($link, $p+1);
        }
        if ($row->level > $stack[0])
        {
            $html .= '<ul><li>'.$link;
            array_unshift($stack, $row->level);
        }
        else
        {
            while ($row->level < $stack[0])
            {
                $html .= '</li></ul>';
                array_shift($stack);
            }
            $html .= '</li><li>'.$link;
        }
    }
    while ($stack[0] > $pagelevel)
    {
        $html .= '</li></ul>';
        array_shift($stack);
    }
    return $html;
}

/**
 * Set to ArticleViewHeader hook, outputs JS subpage lister, if there are any subpages available.
 */
function efSubpageListAddLister($article, &$outputDone, &$useParserCache)
{
    global $egSubpagelistAjaxNamespaces, $egSubpagelistAjaxDisableRE;
    $title = $article->getTitle();
    // Filter pages based on namespace and title regexp
    if (!$egSubpagelistAjaxNamespaces ||
        !array_key_exists($title->getNamespace(), $egSubpagelistAjaxNamespaces) ||
        $egSubpagelistAjaxDisableRE && preg_match($egSubpagelistAjaxDisableRE, $title->getPrefixedText()))
        return true;
    $dbr = wfGetDB(DB_SLAVE);
    $subpagecount = $dbr->selectField('page', 'COUNT(*)', array(
        'page_namespace' => $title->getNamespace(),
        'page_title '.$dbr->buildLike($title->getDBkey().'/', $dbr->anyString()),
    ), __METHOD__);
    if ($subpagecount > 0)
    {
        // Add AJAX lister
        global $wgOut;
        wfLoadExtensionMessages('TemplatedPageList');
        $wgOut->addHTML(
            '<div id="subpagelist_ajax" class="catlinks" style="margin-top: 0"><a href="javascript:void(0)"'.
            ' onclick="sajax_do_call(\'efAjaxSubpageList\', [wgPageName], function(request){'.
            ' if (request.status != 200) return; var s = document.getElementById(\'subpagelist_ajax\');'.
            ' s.innerHTML = s.childNodes[0].innerHTML+request.responseText; })">'.
            wfMsgNoTrans('subpagelist-view', $subpagecount).
            '</a></div>'
        );
    }
    return true;
}

/**
 * Function called by the Hook, returns the wiki text
 */
function efRenderTemplatedPageList($input, $args, $parser)
{
    global $egInSubpageList;
    if (!$egInSubpageList)
        $egInSubpageList = array();
    /* An ugly hack for diff display: does it hook Article::getContent() ?!!! */
    if ($egInSubpageList[$input])
        return '';
    $egInSubpageList[$input] = 1;
    $list = new TemplatedPageList($input, $args, $parser);
    $r = $list->render();
    unset($egInSubpageList[$input]);
    return $r;
}

class TemplatedPageList
{
    var $oldParser, $parser, $title;

    var $options = array();
    var $errors = array();

    static $order = array(
        'title' => 'page_namespace, UPPER(page_title)',
        'titlewithoutnamespace' => 'UPPER(page_title)',
        'lastedit' => 'lastedit.rev_timestamp',
        'user' => 'lastedit.rev_user_text',
        'firstedit' => 'creation.rev_timestamp',
        'creation' => 'creation.rev_timestamp',
        'pagecounter' => 'page_counter',
        'popularity'  => 'page_counter',
        'length' => 'page_len',
        'size' => 'page_len',
    );
    static $order_join = array(
        'user'      => array('revision', 'lastedit', 'lastedit.rev_id=page_latest'),
        'lastedit'  => array('revision', 'lastedit', 'lastedit.rev_id=page_latest'),
        'firstedit' => array('revision', 'creation', 'creation.rev_page=page_id AND creation.rev_timestamp=(SELECT MIN(rev_timestamp) FROM revision WHERE rev_page=page_id)'),
        'creation'  => array('revision', 'creation', 'creation.rev_page=page_id AND creation.rev_timestamp=(SELECT MIN(rev_timestamp) FROM revision WHERE rev_page=page_id)'),
    );

    /* Constructor. $input is tag text, $args is tag arguments, $parser is parser object */
    function __construct($input, $args, $parser)
    {
        wfLoadExtensionMessages('TemplatedPageList');
        $this->oldParser = $parser;
        $this->parser = clone $parser;
        $this->title = $parser->mTitle;
        $this->options = $this->parseOptions($input);
    }

    function error()
    {
        $args = func_get_args();
        $msg = array_shift($args);
        $this->errors[] = htmlspecialchars(wfMsg($msg, $args));
    }

    function getErrors()
    {
        if (!$this->errors)
            return '';
        $html = "<p><strong>".wfMsg('spl-errors')."</strong></p><ul>";
        foreach ($this->errors as $e)
            $html .= "<li>$e</li>";
        $html .= "</ul>";
        return $html;
    }

    /**
     * check if there is any link to this cat, this is a check if there is a cat.
     * @param string $category the category title
     * @return boolean if there is a cat with this title
     */
    static function checkCat($category)
    {
        $dbr = wfGetDB(DB_SLAVE);
        $exists = $dbr->selectField('categorylinks', '1', array('cl_to' => $category), __METHOD__, array('LIMIT' => 1));
        return intval($exists) > 0;
    }

    /**
     * check category $value, push it to $array, if it is correct,
     * and remember an error, if not
     */
    function pushCat(&$array, $cats)
    {
        if (!is_array($cats))
            $cats = array($cats);
        foreach ($cats as $value)
        {
            $title = Title::makeTitleSafe(NS_CATEGORY, $value);
            if ($title && $title->userCanRead() && self::checkCat($title->getDBkey()))
                $array[] = $title->getDBkey();
            else
                $this->error('spl-invalid-category', $value);
        }
    }

    /**
     * Get all subcategories of category/categories $categories
     * This function is so much duplicated in different extensions... :-(
     */
    function getSubcategories($categories)
    {
        if (!$categories)
            return array();
        if (!is_array($categories))
            $categories = array($categories);
        $dbr = wfGetDB(DB_SLAVE);
        $cats = array();
        foreach ($categories as $c)
        {
            if (!is_object($c))
                $c = Title::makeTitleSafe(NS_CATEGORY, $c);
            if ($c)
                $cats[$c->getDBkey()] = true;
        }
        $categories = array_keys($cats);
        // Get subcategories
        while ($categories)
        {
            $res = $dbr->select(array('page', 'categorylinks'), 'page.*',
                array('cl_from=page_id', 'cl_to' => $categories, 'page_namespace' => NS_CATEGORY),
                __METHOD__);
            $categories = array();
            foreach ($res as $row)
            {
                if (!$cats[$row->page_title])
                {
                    $categories[] = $row->page_title;
                    $cats[$row->page_title] = true;
                }
            }
        }
        return array_keys($cats);
    }

    /**
     * Extract options from text $text
     */
    function parseOptions($text)
    {
        global $wgTitle, $wgContLang;
        $text = $this->preprocess($wgTitle, $text);

        $options = array(
            'namespace' => array(),
            'category' => array(),
            'notcategory' => array(),
            'ignore' => array(),
            'redirect' => NULL,
            'defaultorder' => 'asc',
            'order' => array(),
            'output' => 'list',
        );

        foreach (explode("\n", $text) as $line)
        {
            list($key, $value) = explode("=", $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || $value === '')
                continue;
            switch ($key)
            {
            case 'namespace':
                foreach (preg_split('/[\|\s]*\|[\|\s]*/u', $value) as $ns)
                {
                    if ($i = $wgContLang->getNsIndex($ns))
                        $options['namespace'][] = $i;
                    elseif ($ns == 'Main' || $ns == '(Main)' || $ns == wfMsg('blanknamespace'))
                        $options['namespace'][] = 0;
                    else
                        $this->error('spl-invalid-ns', $ns);
                }
                break;
            case 'category':
            case 'subcategory':
                $or = array();
                foreach (preg_split('/[\|\s]*\|[\|\s]*/u', $value) as $cat)
                    $this->pushCat($or, $cat);
                if ($or)
                {
                    if ($key == 'subcategory')
                        $or = $this->getSubcategories($or);
                    $options['category'][] = $or;
                }
                break;
            case 'notsubcategory':
                $options['notcategory'] = array_merge($options['notcategory'], $this->getSubcategories($value));
                break;
            case 'notcategory':
                $this->pushCat($options['notcategory'], $value);
                break;
            case 'parent':
                $value .= '/';
            case 'prefix':
                $t = Title::newFromText($value);
                if ($t && $t->userCanRead())
                    $options['prefix'] = $t;
                break;
            case 'ignore':
                $options['ignore'] = array_merge($options['ignore'], $value);
                break;
            case 'redirect':
                $options['redirect'] = $value == 'yes' || $value == 'true' || $value === '1';
                break;
            case 'deepness':
                $options['level_relative'] = true;
            case 'level':
                $l = preg_split('/\.\.+/', $value, 2);
                foreach ($l as &$x)
                    $x = $x === '' ? NULL : intval($x);
                unset($x);
                $options['level_min'] = $l[0];
                $options['level_max'] = count($l) > 1 ? $l[1] : $l[0];
                break;
            case 'order':
                $value = strtolower($value);
                if ($value == 'asc' || $value == 'desc')
                {
                    $options['defaultorder'] = $value;
                    foreach ($options['order'] as &$o)
                        $o[1] = $value;
                    unset($o);
                    break;
                }
            case 'ordermethod':
                $value = strtolower($value);
                $options['orderdir'] = array();
                $options['ordermethod'] = array();
                foreach (preg_split('/,+/', $value) as $o)
                {
                    $d = $options['defaultorder'];
                    if (preg_match('/\s+(asc|desc)$/', $o, $m))
                    {
                        $d = $m[1];
                        $o = substr($o, 0, -strlen($m[0]));
                    }
                    if (self::$order[$o])
                        $options['order'][] = array($o, $d);
                    else
                        $this->error('spl-unknown-order', $value, implode(', ', array_keys(self::$order)));
                }
                break;
            case 'count':
                $key = 'limit';
            case 'limit':
                if (intval($value) <= 0)
                {
                    $this->error('spl-invalid-limit', $value);
                    break;
                }
            case 'offset':
                $options[$key] = intval($value);
                break;
            case 'output':
                if ($value == 'simple' || $value == 'column' || $value == 'template')
                    $options['output'] = $value;
                break;
            case 'template':
                $tpl = Title::newFromText($value, NS_TEMPLATE);
                if ($tpl->exists() && $tpl->userCanRead())
                {
                    if (!$options['output'])
                        $options['output'] = 'template';
                    $options['template'] = $tpl;
                }
                else
                    $this->error('spl-invalid-template', $value);
                break;
            case 'silent':
            case 'noerrors':
            case 'suppresserrors':
                $options['silent'] = true;
                break;
            default:
                $this->error('spl-unknown-option', $key, $value);
            }
        }

        if (!$options['output'] || $options['output'] == 'template' && !$options['template'])
            $options['output'] = 'simple';
        if (!$options['order'])
            $options['order'] = array(array('title', $options['defaultorder']));

        return $options;
    }

    /**
     * Render page list
     * @return string html output
     */
    function render()
    {
        wfProfileIn(__METHOD__);
        $this->oldParser->disableCache();
        $pages = $this->getPages();
        if (count($pages) > 0)
        {
            if ($this->options['output'] == 'template')
            {
                $list = $this->makeTemplatedList($pages);
                $html = $this->parse($list);
                $html = preg_replace('#^<p>(.*)</p>$#is', '\1', $html);
            }
            elseif ($this->options['output'] == 'column')
                $html = $this->makeColumnList($pages);
            else
                $html = $this->makeSimpleList($pages);
        }
        else
            $html = '';
        if (!$this->options['silent'])
            $html = $this->getErrors() . $html;
        wfProfileOut(__METHOD__);
        return $html;
    }

    /**
     * Get article objects from the DB
     * @return array of Article objects
     */
    function getPages()
    {
        wfProfileIn(__METHOD__);
        $dbr = wfGetDB(DB_SLAVE);
        $O = $this->options; // input options

        $where = array(); // query conditions
        $opt = array(); // query options
        $tables = array('page'); // query tables
        $joins = array(); // join conditions

        if ($O['limit'])
            $opt['LIMIT'] = $O['limit'];
        if ($O['offset'])
            $opt['OFFSET'] = $O['offset'];
        $i = $O['level_min'];
        $a = $O['level_max'];
        if ($i !== NULL || $a !== NULL)
        {
            if ($O['level_relative'] && $O['prefix'])
            {
                $r = substr_count($O['prefix']->getText(), '/');
                if ($i !== NULL) $i += $r;
                if ($a !== NULL) $a += $r;
            }
            $where[] = 'page_title REGEXP '.$dbr->addQuotes('^([^/]+(/|$)){' . ($i === NULL ? 0 : $i) . ',' . $a . '}[^/]+$');
        }
        if ($O['namespace'])
            $where['page_namespace'] = $O['namespace'];
        if ($O['prefix'])
            $where[] = 'page_title LIKE '.$dbr->addQuotes(str_replace(array('_', '%'), array('\_', '\%'), $O['prefix']->getDBkey()).'%');
        if ($O['ignore'])
            foreach ($O['ignore'] as $a)
                $where[] = 'page_title NOT LIKE '.$dbr->addQuotes(str_replace(' ', '\_', $a));
        if (($r = $O['redirect']) !== NULL)
            $where['page_is_redirect'] = $r;

        if ($O['category'])
        {
            $group = false;
            foreach ($O['category'] as $i => $or)
            {
                $t = $dbr->tableName('categorylinks')." cl$i";
                $tables[] = $t;
                $joins[$t] = array('INNER JOIN', array("page_id=cl$i.cl_from", "cl$i.cl_to" => $or));
                if (count($or) > 1)
                    $group = true;
            }
            if ($group)
                $opt['GROUP BY'] = 'page_id';
        }

        if ($O['notcategory'])
        {
            $t = $dbr->tableName('categorylinks')." notcl";
            $tables[] = $t;
            $joins[$t] = array('LEFT JOIN', array('page_id=notcl.cl_from', 'notcl.cl_to' => $O['notcategory']));
            $where[] = 'notcl.cl_to IS NULL';
        }

        foreach ($O['order'] as $o)
        {
            $opt['ORDER BY'][] = self::$order[$o[0]] . ' ' . $o[1];
            if ($j = self::$order_join[$o[0]])
            {
                $t = $dbr->tableName($j[0]).' '.$j[1];
                if (!$joins[$t])
                {
                    $tables[] = $t;
                    $joins[$t] = array('INNER JOIN', $j[2]);
                }
            }
        }
        $opt['ORDER BY'] = implode(', ', $opt['ORDER BY']);

        if (!$where && !$joins)
        {
            $this->error('spl-no-restrictions');
            return array();
        }

        $content = array();
        $res = $dbr->select($tables, 'page.*', $where, __METHOD__, $opt, $joins);
        foreach ($res as $row)
        {
            $title = Title::newFromRow($row);
            // TODO IntraACL: batch right checking (probably LinkBatch)
            if (is_object($title) && $title->userCanRead())
            {
                $article = new Article($title);
                $content[] = $article;
            }
        }

        wfProfileOut(__METHOD__);
        return $content;
    }

    /**
     * Process $template using each article in $pages as params
     * and return concatenated output.
     * @param Array $pages Article objects
     * @param string $template Standard MediaWiki template-like source
     * @return string the parsed output
     */
    function makeTemplatedList($pages)
    {
        $text = '';
        $tpl = $this->options['template']->getPrefixedText();
        foreach ($pages as $i => $article)
        {
            $args = array();
            $t = $article->getTitle()->getPrefixedText();
            $args['index']         = $i;
            $args['number']        = $i+1;
            $args['odd']           = $i&1 ? 0 : 1;
            $args['ns_'.$article->getTitle()->getNamespace()] = 1;
            $args['title']         = $t;
            if ($this->options['prefix'])
                $args['title_rel']     = substr($t, strlen($this->options['prefix']->getText()));
            $xml = '<root>';
            $xml .= '<template><title>'.$tpl.'</title>';
            foreach ($args as $k => $v)
                $xml .= '<part><name>'.htmlspecialchars($k).'</name>=<value>'.htmlspecialchars($v).'</value></part>';
            $xml .= '</template>';
            $xml .= '</root>';
            $dom = new DOMDocument;
            $result = $dom->loadXML($xml);
            if (!$result)
            {
                $this->error('spl-preprocess-error');
                return '';
            }
            $text .= trim($this->preprocess($article, $dom)) . "\n";
        }
        return $text;
    }

    /**
     * Build 3-column list, using CategoryViewer::columnList()
     */
    function makeColumnList($pages)
    {
        global $wgUser;
        $skin = $wgUser->getSkin();
        $start_char = array();
        sort($pages);
        foreach ($pages as &$p)
        {
            $p = $p->getTitle();
            $start_char[] = mb_substr($p->getText(), 0, 1);
            $p = $skin->link($p);
        }
        $html = CategoryViewer::columnList($pages, $start_char);
        return $html;
    }

    /**
     * This function builds very simple bullet list of pages, without using any templates.
     */
    function makeSimpleList($pages)
    {
        global $wgUser;
        $skin = $wgUser->getSkin();
        $html = '<ul>';
        foreach ($pages as $i => $article)
            $html .= '<li>'.$skin->link($article->getTitle()).'</li>';
        $html .= '</ul>';
        return $html;
    }

    /**
     * Wrapper function parse, calls parser function parse
     * @param string $text the content
     * @return string the parsed output
     */
    function parse($text)
    {
        wfProfileIn(__METHOD__);
        $options = $this->oldParser->mOptions;
        $output = $this->parser->parse($text, $this->title, $options, true, false);
        wfProfileOut(__METHOD__);
        return $output->getText();
    }

    /**
     * Copy-pasted from Parser::preprocess and Parser::replaceVariables,
     * except that it also sets mRevisionTimestamp and passes PTD_FOR_INCLUSION.
     * @param string $article the article
     * @return string preprocessed article text
     */
    function preprocess($article, $dom = NULL)
    {
        wfProfileIn(__METHOD__);
        $this->parser->clearState();
        $this->parser->setOutputType(Parser::OT_PREPROCESS);
        if ($article instanceof Title)
        {
            $title = $article;
            $article = new Article($title);
        }
        else
            $title = $article->getTitle();
        $this->parser->setTitle($title);
        if ($article)
        {
            $this->parser->mRevisionId = $article->getRevIdFetched();
            $this->parser->mRevisionTimestamp = $article->getTimestamp();
            if ($dom === NULL)
                $dom = $article->getContent();
        }
        if ($dom === NULL)
            return '';
        if (!is_object($dom))
        {
            wfRunHooks('ParserBeforeStrip', array(&$this->parser, &$dom, &$this->parser->mStripState));
            wfRunHooks('ParserAfterStrip', array(&$this->parser, &$dom, &$this->parser->mStripState));
            $dom = $this->parser->preprocessToDom($dom, Parser::PTD_FOR_INCLUSION);
        }
        $frame = $this->parser->getPreprocessor()->newFrame();
        $text = $frame->expand($dom);
        $text = $this->parser->mStripState->unstripBoth($text);
        wfProfileOut(__METHOD__);
        return $text;
    }
}
