<?php
namespace MediaWiki\Extension\TemplatedPageList;

use SpecialPage;
use MediaWiki\Html\Html;

class SpecialTemplatedPageList extends SpecialPage {
    public function __construct() {
        parent::__construct( 'TemplatedPageList' );
    }

    public function execute( $parameters ) {
        $out = $this->getOutput();
        $request = $this->getRequest();
        $out->addModules( 'ext.TPL.special' );
        $out->setPageTitle( $this->msg( 'tpl-special' ) );
        
        $html = Html::openElement('form', ['action' => $this->getPageTitle()->getLocalUrl(), 'method' => 'POST']);
        $html .= Html::element('p', [], 'Конструктор формы временно упрощен в рамках обновления. Используйте парсер-функции напрямую.');
        $html .= Html::closeElement('form');

        $out->addHTML( $html );
    }
}