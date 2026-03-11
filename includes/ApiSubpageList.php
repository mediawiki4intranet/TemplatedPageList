<?php
namespace MediaWiki\Extension\TemplatedPageList;

use MediaWiki\Api\ApiBase;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;

class ApiSubpageList extends ApiBase {
    public function execute() {
        $pagename = $this->getMain()->getVal( 'page' );
        $title = Title::newFromText( $pagename );
        if ( !$title ) {
            $this->getResult()->addValue( null, $this->getModuleName(), [ 'html' => '' ] );
            return;
        }

        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $res = $dbr->select(
            'page', '*',
            [
                'page_namespace' => $title->getNamespace(),
                'page_title ' . $dbr->buildLike( $title->getDBkey() . '/', $dbr->anyString() )
            ],
            __METHOD__,
            [ 'ORDER BY' => 'page_title' ]
        );

        $html = "<ul>";
        foreach ( $res as $row ) {
            $subTitle = Title::newFromRow( $row );
            // ИСПОЛЬЗУЕМ СОВРЕМЕННУЮ ПРОВЕРКУ ПРАВ ЧЕРЕЗ AUTHORITY
            if ( $subTitle && $this->getAuthority()->definitelyCan( 'read', $subTitle ) ) {
                $linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
                $html .= "<li>" . $linkRenderer->makeKnownLink( $subTitle, $subTitle->getSubpageText() ) . "</li>";
            }
        }
        $html .= "</ul>";
        $html = '<a href="javascript:void(0)" onclick="document.getElementById(\'subpagelist_ajax\').innerHTML = \'\';">' . wfMessage('subpagelist-close', $res->numRows())->plain() . '</a>' . $html;

        $this->getResult()->addValue( null, $this->getModuleName(), [ 'html' => $html ] );
    }

    public function getAllowedParams() {
        return [
            'page' => [
                ApiBase::PARAM_TYPE => 'string',
                ApiBase::PARAM_REQUIRED => true
            ]
        ];
    }
}