<?php

/**
 * Extension is similar to DynamicPageList & company, but the code is simpler
 * and the functionality is more advanced.
 * Main classes.
 *
 * @package MediaWiki
 * @subpackage Extensions
 * @author Vitaliy Filippov <vitalif@mail.ru>, 2009+
 * @author based on SubPageList by Martin Schallnahs <myself@schaelle.de>, Rob Church <robchur@gmail.com>
 * @license GNU General Public Licence 2.0 or later
 * @link http://wiki.4intra.net/TemplatedPageList
 */

class SpecialTemplatedPageList extends SpecialPage
{
    function __construct()
    {
        parent::__construct('TemplatedPageList');
    }
    public static function input($name, $size = false, $value = false, $attribs = array())
    {
        return Xml::element('input', array(
            'name' => $name,
            'size' => $size,
            'value' => $value) + $attribs + array('id' => $name));
    }
    public static function checkLabel($label, $name, $checked = false, $attribs = array())
    {
        $attribs = $attribs + array('id' => $name, 'value' => '1');
        return Xml::check($name, $checked, $attribs) .
            '&nbsp;' .
            Xml::label($label, $attribs['id']);
    }
    public static function inputLabel($sep, $label, $name, $size, $value, $attribs = array())
    {
        return Xml::label($label, $name) . $sep .
            self::input($name, $size, $value, $attribs);
    }
    public function execute($parameters)
    {
        global $wgRequest, $wgOut, $wgParser, $wgUser, $wgContLang, $wgScriptPath;
        $wgOut->addStyle($wgScriptPath.'/extensions/TemplatedPageList/tpl.css');
        $wgOut->addScript('<script type="text/javascript" language="JavaScript" src="'.$wgScriptPath.'/extensions/TemplatedPageList/tpl.js"></script>');
        if (!$wgParser->mOptions)
            $wgParser->mOptions = ParserOptions::newFromUser($wgUser);
        // Default parameters:
        $params = $_GET + $_POST + array(
            'tpl_namespace'      => '',
            'tpl_category'       => array(),
            'tpl_subcategory'    => array(),
            'tpl_notcategory'    => '',
            'tpl_notsubcategory' => false,
            'tpl_parent'         => '',
            'tpl_isprefix'       => false,
            'tpl_level_min'      => '0',
            'tpl_level_max'      => '∞',
            'tpl_level_relative' => false,
            'tpl_ignore'         => '',
            'tpl_redirect'       => false,
            'tpl_ordermethod'    => 'title',
            'tpl_orderdir'       => 'asc',
            'tpl_limit'          => 100,
            'tpl_offset'         => 0,
            'tpl_output'         => 'simple',
            'tpl_template'       => '',
            'tpl_silent'         => false,
            'tpl_exec'           => false,
        );
        // Remove empty categories
        $category = array();
        $subcat = array();
        foreach ($params['tpl_category'] as $i => $cat)
        {
            if (trim($cat) !== '')
            {
                $category[] = $cat;
                $subcat[] = !empty($params['tpl_subcategory'][$i]);
            }
        }
        $params['tpl_category'] = $category;
        $params['tpl_subcategory'] = $subcat;
        // Build code
        $code = '';
        if ($params['tpl_namespace'] !== '')
            $code .= 'namespace = ' . $params['tpl_namespace'] . "\n";
        foreach ($params['tpl_category'] as $i => $cat)
            $code .= ($params['tpl_subcategory'][$i] ? 'subcategory = ' : 'category = ') . $cat . "\n";
        if ($params['tpl_notcategory'] !== '')
            $code .= ($params['tpl_notsubcategory'] ? 'notsubcategory = ' : 'notcategory = ') . $params['tpl_notcategory'] . "\n";
        if ($params['tpl_parent'] !== '')
            $code .= ($params['tpl_isprefix'] ? 'prefix = ' : 'parent = ') . $params['tpl_parent'] . "\n";
        if ($params['tpl_level_min'] || $params['tpl_level_max'] !== '' && $params['tpl_level_max'] != '∞')
        {
            $code .= ($params['tpl_level_relative'] ? 'depth = ' : 'level = ');
            if ($params['tpl_level_min'])
                $code .= $params['tpl_level_min'];
            if ($params['tpl_level_min'] !== $params['tpl_level_max'])
            {
                $code .= '..';
                if ($params['tpl_level_max'] !== '' && $params['tpl_level_max'] != '∞')
                    $code .= $params['tpl_level_max'];
            }
            $code .= "\n";
        }
        if ($params['tpl_ignore'] !== '')
            $code .= 'ignore = ' . $params['tpl_ignore'] . "\n";
        if ($params['tpl_redirect'])
            $code .= "redirect = yes\n";
        $code .= 'order = '.$params['tpl_ordermethod'].' '.strtoupper($params['tpl_orderdir'])."\n";
        if ($params['tpl_limit'])
            $code .= 'limit = ' . $params['tpl_limit'] . "\n";
        if ($params['tpl_offset'])
            $code .= 'offset = ' . $params['tpl_offset'] . "\n";
        if ($params['tpl_output'] != 'simple')
            $code .= 'output = ' . $params['tpl_output'] . "\n";
        if ($params['tpl_template'] !== '')
            $code .= 'template = ' . $params['tpl_template'] . "\n";
        if ($params['tpl_silent'])
            $code .= "silent = yes\n";
        // Create lister
        $lister = new TemplatedPageList($code, '', $wgParser);
        $code = "<subpagelist>\n$code</subpagelist>";
        // Add an empty category
        $params['tpl_category'][] = '';
        $params['tpl_subcategory'][] = false;
        ob_start();
?><form action="?" method="POST" class="tpl_form">
<table>
<tr><th colspan="3"><?= wfMsg('tpl-page-selection') ?></th></tr>
<tr><td>
    <?= self::inputLabel('</td><td></td><td>', wfMsg('tpl-namespace'), 'tpl_namespace', 60, $params['tpl_namespace']) ?>
    <?= wfMsg('tpl-namespace-expl') ?>
</td></tr>
<tr id="category-row-0">
    <td><?= wfMsg('tpl-category') ?></td><?php
        foreach ($params['tpl_category'] as $i => $and) {
            if ($i) {
                ?><tr id="category-row-<?= $i ?>"><td class="tpl_and"><?= wfMsg('tpl-and') ?></td><?php
            } ?>
    <td><span class="tpl_brace">(</span></td>
    <td><?= Xml::input("tpl_category[$i]", '60', $and) ?>
        <?= self::checkLabel(wfMsg('tpl-subcategory'), "tpl_subcategory[$i]", $params['tpl_subcategory'][$i], array('id' => "tpl_subcategory$i")) ?>
        <span class="tpl_brace">)</span><?php
            if (!$i) { ?>
                <a href="javascript:void(0)" onclick="add_category_row(this)"><?= wfMsg('tpl-js-and') ?></a> <?php
                echo wfMsg('tpl-category-expl');
            } ?>
    </td>
</tr><?php
        } ?>
<tr><td>
    <?= self::inputLabel('</td><td></td><td>', wfMsg('tpl-notcategory'), 'tpl_notcategory', 60, $params['tpl_notcategory']) ?>
    <?= self::checkLabel(wfMsg('tpl-notsubcategory'), 'tpl_notsubcategory', $params['tpl_notsubcategory']) ?>
</td></tr>
<tr><td>
    <?= self::inputLabel('</td><td></td><td>', wfMsg('tpl-parent'), 'tpl_parent', 60, $params['tpl_parent']) ?>
    <?= self::checkLabel(wfMsg('tpl-isprefix'), 'tpl_isprefix', $params['tpl_isprefix']) ?>
</td></tr>
<tr><td>
    <?= self::inputLabel('</td><td></td><td>', wfMsg('tpl-level-min'), 'tpl_level_min', 5, $params['tpl_level_min']) ?>
    <?= wfMsg('tpl-level-between') ?>
    <?= self::input('tpl_level_max', 5, $params['tpl_level_max']) ?>
    <?= wfMsg('tpl-level-expl') ?>
    <?= self::checkLabel(wfMsg('tpl-level-relative'), 'tpl_level_relative', $params['tpl_level_relative']) ?>
</td></tr>
<tr><td>
    <?= self::inputLabel('</td><td></td><td>', wfMsg('tpl-ignore'), 'tpl_ignore', 60, $params['tpl_ignore']) ?>
    <?= wfMsg('tpl-ignore-expl') ?>
</td></tr>
<tr><td></td><td></td><td><?= self::checkLabel(wfMsg('tpl-redirect'), 'tpl_redirect', $params['tpl_redirect']) ?></td></tr>
<tr><th colspan="3"><?= wfMsg('tpl-page-display') ?></th></tr>
<tr><td colspan="2"><?= Xml::label(wfMsg('tpl-ordermethod'), 'tpl_ordermethod') ?></td><td>
    <select name="tpl_ordermethod" id="tpl_ordermethod"><?php
        foreach (array('fullpagename', 'pagename', 'lastedit', 'user', 'firstedit', 'size', 'popularity') as $order) { ?>
            <option value="<?= $order ?>" <?= $params['tpl_ordermethod'] == $order ? ' selected="selected"' : '' ?>><?=
                wfMsg("tpl-order-$order") ?></option><?php
        } ?>
    </select>
    <select name="tpl_orderdir" id="tpl_orderdir">
        <option value="asc" <?= $params['tpl_orderdir'] == 'asc' ? ' selected="selected"' : '' ?>><?=
            wfMsg('tpl-order-asc') ?></option>
        <option value="desc" <?= $params['tpl_orderdir'] == 'desc' ? ' selected="selected"' : '' ?>><?=
            wfMsg('tpl-order-desc') ?></option>
    </select>
</td></tr>
<tr><td>
    <?= self::inputLabel('</td><td></td><td>', wfMsg('tpl-limit'), 'tpl_limit', 5, $params['tpl_limit']) ?>
    <?= self::inputLabel('&nbsp;', wfMsg('tpl-offset'), 'tpl_offset', 5, $params['tpl_offset']) ?>
</td></tr>
<tr><td colspan="2"><?= Xml::label(wfMsg('tpl-output'), 'tpl_output') ?></td><td>
    <select name="tpl_output" id="tpl_output"><?php
        foreach (array('simple', 'column', 'template') as $output) { ?>
            <option value="<?= $output ?>" <?= $params['tpl_output'] == $output ? ' selected="selected"' : '' ?>><?=
                wfMsg("tpl-output-$output") ?></option><?php
        } ?>
    </select>
</td></tr>
<tr><td>
    <?= self::inputLabel('</td><td></td><td>', wfMsg('tpl-template'), 'tpl_template', 60, $params['tpl_template']) ?>
</td></tr>
<tr><td></td><td></td><td><?= self::checkLabel(wfMsg('tpl-silent'), 'tpl_silent', $params['tpl_silent']) ?></td></tr>
<tr><td></td><td></td><td>
    <input type="submit" name="tpl_exec" value="<?= wfMsg('tpl-submit') ?>" />
</td></tr>
</table><?php
        if ($params['tpl_exec']) { ?>
<h3 class="tpl_head"><?= wfMsg('tpl-code') ?></h3>
<textarea rows="8" cols="80"><?= htmlspecialchars($code) ?></textarea>
<h3 class="tpl_head"><?= wfMsg('tpl-results') ?></h3><?php
            $html = $lister->render();
            if ($lister->total)
                echo wfMsg('tpl-total-results', $lister->total);
            echo $html;
        } ?>
</form><?php
        $html = ob_get_contents();
        ob_end_clean();
        $wgOut->setPageTitle(wfMsg('tpl-special'));
        $wgOut->addHTML($html);
    }
}

class TemplatedPageList
{
    var $oldParser, $parser, $parserOptions, $outputType, $title;

    var $options = array();
    var $total = 0;
    var $errors = array();

    static $order = array(
        'title' => 'page_namespace, UPPER(page_title)',
        'fullpagename' => 'page_namespace, UPPER(page_title)',
        'titlewithoutnamespace' => 'UPPER(page_title)',
        'pagename' => 'UPPER(page_title)',
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
        global $wgTitle;
        wfLoadExtensionMessages('TemplatedPageList');
        $this->oldParser = $parser;
        $this->parser = clone $parser;
        $this->title = $parser->mTitle ? $parser->mTitle : $wgTitle;
        $this->input = $input;
        $this->options = $this->parseOptions($input);
    }

    function error()
    {
        $args = func_get_args();
        $msg = array_shift($args);
        $this->errors[] = wfMsgNoTrans($msg, $args);
    }

    function getErrors($outputType)
    {
        if (!$this->errors)
            return '';
        $text = wfMsg('spl-errors')."\n* ".implode("\n* ", $this->errors)."\n\n";
        if ($outputType == 'html')
            $text = $this->parse($text);
        return $text;
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
            'prefix' => NULL,
            'level_min' => NULL,
            'level_max' => NULL,
            'level_relative' => false,
        );

        foreach (explode("\n", $text) as $line)
        {
            if (trim($line) === '')
                continue;
            list($key, $value) = explode("=", $line, 2);
            $key = mb_strtolower(trim($key));
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
                foreach (preg_split('/[\|\s]*\|[\|\s]*/u', $value) as $ign)
                    $options['ignore'][] = $ign;
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
                if ($tpl && $tpl->exists() && $tpl->userCanRead())
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

        if (!$options['output'] || $options['output'] == 'template' &&
            empty($options['template']))
            $options['output'] = 'simple';
        if (!$options['order'])
            $options['order'] = array(array('title', $options['defaultorder']));

        return $options;
    }

    /**
     * Render page list
     * @return string html output
     */
    function render($outputType = 'html')
    {
        global $egInSubpageList;
        if (!isset($egInSubpageList))
            $egInSubpageList = array();
        /* An ugly hack for diff display: does it hook Article::getContent() ?!!! */
        if (!empty($egInSubpageList[$this->input]))
            return '';
        $egInSubpageList[$this->input] = 1;
        wfProfileIn(__METHOD__);
        $this->outputType = $outputType;
        $this->oldParser->disableCache();
        $pages = $this->getPages();
        if (count($pages) > 0)
        {
            if ($this->options['output'] == 'template')
            {
                $text = $this->makeTemplatedList($pages);
                if ($outputType == 'html')
                {
                    $text = $this->parse($text);
                    $text = preg_replace('#^<p>(.*)</p>$#is', '\1', $text);
                }
            }
            elseif ($this->options['output'] == 'column' && $outputType == 'html')
                $text = $this->makeColumnList($pages);
            else
                $text = $this->makeSimpleList($pages, $outputType);
        }
        else
            $text = '';
        if (empty($this->options['silent']))
            $text = $this->getErrors($outputType) . $text;
        wfProfileOut(__METHOD__);
        unset($egInSubpageList[$this->input]);
        return $text;
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
        $opt = array('SQL_CALC_FOUND_ROWS'); // query options
        $tables = array('page'); // query tables
        $joins = array(); // join conditions

        if (!empty($O['limit']))
            $opt['LIMIT'] = $O['limit'];
        if (!empty($O['offset']))
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
            if (!empty(self::$order_join[$o[0]]))
            {
                $j = self::$order_join[$o[0]];
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

        // FOUND_ROWS() is MySQL-specific
        global $wgDBtype;
        if (strpos(strtolower($wgDBtype), 'mysql') !== false)
        {
            $res1 = $dbr->query('SELECT FOUND_ROWS()');
            $res1 = $dbr->fetchRow($res1);
            $this->total = $res1[0];
        }
        else
            $this->total = count($content);

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
     * Adds a templatelinks dependency
     */
    function addDep($title)
    {
        $rev = Revision::newFromTitle($title);
        $id = $rev ? $rev->getPage() : 0;
        $this->oldParser->mOutput->addTemplate($title, $id, $rev ? $rev->getId() : 0);
    }

    /**
     * Process $template using each article in $pages as params
     * and return concatenated output.
     * @param Array $pages Article objects
     * @return string the parsed output
     */
    function makeTemplatedList($pages)
    {
        $text = '';
        $tpl = $this->options['template']->getPrefixedText();
        $this->addDep($this->options['template']);
        foreach ($pages as $i => $article)
        {
            $args = array();
            $t = $article->getTitle();
            $this->addDep($t);
            $t = $t->getPrefixedText();
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
        if ($this->outputType == 'html')
        {
            global $wgUser;
            $skin = $wgUser->getSkin();
            $text = '<ul>';
            foreach ($pages as $i => $article)
                $text .= '<li>'.$skin->link($article->getTitle()).'</li>';
            $text .= '</ul>';
        }
        else
        {
            $text = '';
            foreach ($pages as $i => $article)
            {
                $t = $article->getTitle()->getPrefixedText();
                $text .= "* [[$t]]\n";
            }
        }
        return $text;
    }

    /**
     * Wrapper function parse, calls parser function parse
     * @param string $text the content
     * @return string the parsed output
     */
    function parse($text)
    {
        wfProfileIn(__METHOD__);
        if (!$this->parserOptions)
        {
            $this->parserOptions = clone $this->oldParser->mOptions;
            $this->parserOptions->setEditSection(false);
        }
        $text = "__NOTOC__$text";
        $output = $this->parser->parse($text, $this->title, $this->parserOptions, true, false);
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
