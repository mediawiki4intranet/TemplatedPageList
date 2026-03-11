<?php
namespace MediaWiki\Extension\TemplatedPageList;

use MediaWiki\MediaWikiServices;

class Hooks {
    public static function onParserFirstCallInit( $parser ) {
        $parser->setHook( 'subpages', [ TemplatedPageList::class, 'renderTag' ] );
        $parser->setHook( 'subpagelist', [ TemplatedPageList::class, 'renderTag' ] );
        $parser->setHook( 'dynamicpagelist', [ TemplatedPageList::class, 'renderTag' ] );
        $parser->setHook( 'templatedpagelist', [ TemplatedPageList::class, 'renderTag' ] );
        $parser->setFunctionHook( 'getsection', [ self::class, 'getSectionHook' ] );
        $parser->setFunctionHook( 'templatedpagelist', [ TemplatedPageList::class, 'renderFunction' ] );
        return true;
    }

    public static function getSectionHook( $parser, $num ) {
        $args = func_get_args();
        array_shift( $args );
        array_shift( $args );
        $args = implode( '|', $args );
        $st = $parser->mStripState;
        $text = $parser->getSection( $args, $num );
        $parser->mStripState = $st;
        return $text;
    }

    public static function onSkinAfterContent( &$data, $skin ) {
        $title = $skin->getTitle();
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $namespaces = $config->get( 'SubpagelistAjaxNamespaces' );
        $disableRE = $config->get( 'SubpagelistAjaxDisableRE' );

        if ( empty( $namespaces[$title->getNamespace()] ) || 
             ( $disableRE && preg_match( $disableRE, $title->getPrefixedText() ) ) ) {
            return true;
        }

        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $subpagecount = $dbr->newSelectQueryBuilder()
            ->select( 'COUNT(*)' )
            ->from( 'page' )
            ->where( [ 'page_namespace' => $title->getNamespace() ] )
            ->andWhere( 'page_title ' . $dbr->buildLike( $title->getDBkey() . '/', $dbr->anyString() ) )
            ->caller( __METHOD__ )
            ->fetchField();

        if ( $subpagecount > 0 ) {
            $out = $skin->getOutput();
            $out->addModuleStyles( 'ext.TPL.LikeCatlinks' );
            $out->addModules( 'ext.TPL.popup' );
            
            $msg = wfMessage( 'subpagelist-view', $subpagecount )->plain();
            $html = '<div id="subpagelist_ajax" class="like-cl like-cl-outer">' .
                    '<a href="javascript:void(0)" onclick="tplLoadSubpageList()">' . htmlspecialchars($msg) . '</a></div>';
            $data .= $html;
        }
        return true;
    }
}