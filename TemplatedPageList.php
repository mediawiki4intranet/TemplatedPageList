<?php

/**
 * Extension is similar to DynamicPageList & company, but the code is simpler
 * and the functionality is more advanced.
 *
 * Features:
 * - <subpagelist> tag produces a simple or templated list of pages selected by dynamic conditions
 * - Special page with form interface to <subpagelist> (Special:TemplatedPageList)
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
 * @TODO Caching: templatelinks are now saved, but we still need to save references to
 * @TODO category and subpage parents to the DB, and flush the cache when page is
 * @TODO added to the category or when a new subpage of referenced parent is created.
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
 *       title|fullpagename
 *       titlewithoutnamespace|pagename
 *       lastedit
 *       user
 *       created|firstedit
 *       length|size
 *       popularity|pagecounter
 *   order = ASC or DESC             ascending or descending sort order for all Yi (compatibility)
 *   count|limit = N                 show at most N pages
 *   offset = M                      skip first M pages
 *
 *   showtotal = yes|no              show total count of found pages
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
$wgAutoloadClasses['SpecialTemplatedPageList'] = dirname(__FILE__).'/TemplatedPageList.class.php';
$wgAutoloadClasses['TemplatedPageList'] = dirname(__FILE__).'/TemplatedPageList.class.php';
$wgHooks['ParserFirstCallInit'][] = 'efTemplatedPageListParserFirstCallInit';
$wgHooks['LanguageGetMagic'][] = 'efTemplatedPageListLanguageGetMagic';
$wgExtensionCredits['parserhook'][] = array(
    'name'    => 'Templated Page List',
    'author'  => 'Vitaliy Filippov',
    'url'     => 'http://wiki.4intra.net/TemplatedPageList',
    'version' => '2011-10-11',
);
$wgAjaxExportList[] = 'efAjaxSubpageList';
if (!isset($egSubpagelistAjaxNamespaces))
    $egSubpagelistAjaxNamespaces = $wgNamespacesWithSubpages;
$wgSpecialPages['TemplatedPageList'] = 'SpecialTemplatedPageList';
$wgSpecialPageGroups['TemplatedPageList'] = 'changes';

function efTemplatedPageList()
{
    global $wgParser, $wgHooks, $egSubpagelistAjaxNamespaces;
    if ($egSubpagelistAjaxNamespaces)
        $wgHooks['ArticleViewHeader'][] = 'efSubpageListAddLister';
}

// Clear floats for ArticleViewHeader {
if (!function_exists('articleHeaderClearFloats'))
{
    global $wgHooks;
    $wgHooks['ParserFirstCallInit'][] = 'checkHeaderClearFloats';
    function checkHeaderClearFloats($parser)
    {
        global $wgHooks;
        if (!in_array('articleHeaderClearFloats', $wgHooks['ArticleViewHeader']))
            $wgHooks['ArticleViewHeader'][] = 'articleHeaderClearFloats';
        return true;
    }
    function articleHeaderClearFloats($article, &$outputDone, &$useParserCache)
    {
        global $wgOut;
        $wgOut->addHTML('<div style="clear:both;height:1px"></div>');
        return true;
    }
}
// }

/**
 * Parser initialisation code
 */
function efTemplatedPageListParserFirstCallInit($parser)
{
    $parser->setHook('subpages', 'efRenderTemplatedPageList');
    $parser->setHook('subpagelist', 'efRenderTemplatedPageList');
    $parser->setHook('dynamicpagelist', 'efRenderTemplatedPageList');
    $parser->setHook('templatedpagelist', 'efRenderTemplatedPageList');
    $parser->setFunctionHook('getsection', 'efFunctionHookGetSection');
    $parser->setFunctionHook('templatedpagelist', 'efFunctionHookTemplatedPageList');
    return true;
}

/**
 * Add magic words
 */
function efTemplatedPageListLanguageGetMagic(&$magicWords, $langCode = "en")
{
    $magicWords['getsection'] = array(0, 'getsection');
    $magicWords['templatedpagelist'] = array(0, 'templatedpagelist');
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
 * Page list tag hook callback, returns HTML code
 */
function efRenderTemplatedPageList($input, $args, $parser)
{
    $list = new TemplatedPageList($input, $args, $parser);
    return $list->render('html');
}

/**
 * Page list parser function callback, returns HTML code
 */
function efFunctionHookTemplatedPageList($parser, $args)
{
    $list = new TemplatedPageList($args, array(), $parser);
    return array(
        $list->render('wiki'),
        'noparse' => false,
        'title' => $parser->mTitle,
    );
}

/**
 * JavaScript code for re-opening sub page list after collapsing it
 */
function efAjaxSubpageReopenText($subpagecount)
{
    return '<a href="javascript:void(0)"'.
        ' onclick="sajax_do_call(\'efAjaxSubpageList\', [wgPageName], function(request){'.
        ' if (request.status != 200) return; var s = document.getElementById(\'subpagelist_ajax\');'.
        ' s.innerHTML = request.responseText; })">'.
        wfMsgNoTrans('subpagelist-view', $subpagecount).'</a>';
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
    $subpagecount = 0;
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
            if (empty($rows[$s]) && $i > $pagelevel)
                $rows[$s] = (object)array('page_title' => $s, 'level' => $i);
        }
        $rows[$row->page_title] = $row;
        $subpagecount++;
    }
    $res = NULL;
    if (!$rows)
        return '';
    $reopen = efAjaxSubpageReopenText($subpagecount);
    $reopen = "document.getElementById('subpagelist_ajax').innerHTML = '".addslashes($reopen)."'";
    $html = '<a href="javascript:void(0)" onclick="'.htmlspecialchars($reopen).'">'.
        wfMsgNoTrans('subpagelist-close', $subpagecount).'</a>';
    $stack = array($pagelevel);
    foreach ($rows as $row)
    {
        if (!empty($row->title))
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
    if (empty($egSubpagelistAjaxNamespaces) ||
        empty($egSubpagelistAjaxNamespaces[$title->getNamespace()]) ||
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
            '<div id="subpagelist_ajax" class="catlinks" style="line-height: 1.35em; margin: 0 0 0 2px; padding: 0.3em; clear: none; float: left">'.
            efAjaxSubpageReopenText($subpagecount).'</div>'
        );
    }
    return true;
}

/**
 * Toolbox hook
 */
function efTemplatedPageListToolboxLink($tpl)
{
    print '<li id="t-tplist"><a href="'.Title::makeTitle(NS_SPECIAL, 'TemplatedPageList')->getLocalUrl().
        '" title="'.wfMsg('tpl-toolbox-tooltip').'">'.wfMsg('tpl-toolbox-link').'</a></li>';
    return true;
}
