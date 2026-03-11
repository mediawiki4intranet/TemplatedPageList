<?php
namespace MediaWiki\Extension\TemplatedPageList;

use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use MediaWiki\Page\WikiPage;
use MediaWiki\MediaWikiServices;
use RequestContext;

class TemplatedPageList {
    public Parser $parser;
    public \PPFrame $frame;
    public string $outputType;
    public array $options = [];
    public int $total = 0;
    public array $errors = [];
    public string $input = "";

    public static function renderTag( $input, array $args, Parser $parser, \PPFrame $frame ) {
        $list = new self( $input, $args, $parser, $frame );
        return $list->render( 'html' ); // Тег возвращает готовый HTML
    }

    public static function renderFunction( Parser $parser, ...$argsList ) {
        $input = implode( "\n", $argsList ); // В парсер-функции аргументы идут через |, заменяем на \n для парсера опций
        $list = new self( $input, [], $parser );
        // Парсер-функция возвращает вики-текст, который MediaWiki распарсит сама
        return [ $list->render( 'wiki' ), 'noparse' => false, 'title' => $parser->getTitle() ];
    }

    public function __construct( $input, $args, Parser $parser, \PPFrame $frame = null ) {
        $this->parser = $parser;
        $this->frame = $frame ?? $parser->getPreprocessor()->newFrame();
        $this->input = (string)$input;
        $this->options = $this->parseOptions( $this->input );
    }

    public function parseOptions( $text ) {
        $options = [
            'namespace' => [],
            'category' => [],
            'notcategory' => [],
            'ignore' => [],
            'redirect' => null,
            'order' => [],
            'limit' => 100,
            'offset' => 0,
            'showtotal' => false,
            'output' => 'simple',
            'template' => '',
        ];

        $lines = explode( "\n", str_replace('|', "\n", $text) ); // Поддержка разделителя | для парсер-функций
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            
            $parts = explode( '=', $line, 2 );
            if ( count( $parts ) < 2 ) continue;
            
            $key = mb_strtolower( trim( $parts[0] ) );
            $value = trim( $parts[1] );
            if ( $key === '' || $value === '' ) continue;
            
            switch ( $key ) {
                case 'namespace':
                    $nsList = preg_split('/[,\s]+/', $value);
                    foreach ($nsList as $ns) {
                        if (strtolower($ns) === 'main' || $ns === '(main)') {
                            $options['namespace'][] = 0;
                        } else {
                            $options['namespace'][] = (int)$ns;
                        }
                    }
                    break;
                case 'category':
                    $options['category'] = array_merge($options['category'], preg_split('/[,\s]+/', $value));
                    break;
                case 'notcategory':
                    $options['notcategory'] = array_merge($options['notcategory'], preg_split('/[,\s]+/', $value));
                    break;
                case 'ignore':
                    $options['ignore'] = array_merge($options['ignore'], preg_split('/[,\s]+/', $value));
                    break;
                case 'redirect':
                    $options['redirect'] = in_array( strtolower($value), ['yes', 'true', '1'] );
                    break;
                case 'showtotal':
                    $options['showtotal'] = in_array( strtolower($value), ['yes', 'true', '1'] );
                    break;
                case 'limit':
                case 'count':
                    $options['limit'] = (int)$value;
                    break;
                case 'order':
                case 'ordermethod':
                    $options['order'][] = $value;
                    break;
                case 'output':
                    if ( in_array( strtolower($value), ['simple', 'column', 'template'] ) ) {
                        $options['output'] = strtolower($value);
                    }
                    break;
                case 'template':
                    $options['template'] = $value;
                    break;
            }
        }
        return $options;
    }

    public function getPages() {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        
        $qb = $dbr->newSelectQueryBuilder()
            ->select( ['page_id', 'page_namespace', 'page_title', 'page_is_redirect'] )
            ->from( 'page' )
            ->caller( __METHOD__ );

        if ( !empty( $this->options['namespace'] ) ) {
            $qb->where( [ 'page_namespace' => $this->options['namespace'] ] );
        }

        if ( $this->options['redirect'] !== null ) {
            $qb->where( [ 'page_is_redirect' => $this->options['redirect'] ? 1 : 0 ] );
        }

        if ( !empty( $this->options['limit'] ) ) {
            $qb->limit( $this->options['limit'] );
        }

        if ( !empty( $this->options['category'] ) ) {
            foreach ( $this->options['category'] as $i => $cat ) {
                $alias = "cl$i";
                $qb->join( 'categorylinks', $alias, [
                    "page_id = $alias.cl_from",
                    "$alias.cl_to" => str_replace(' ', '_', $cat)
                ] );
            }
        }

        if ( !empty( $this->options['notcategory'] ) ) {
            foreach ( $this->options['notcategory'] as $i => $cat ) {
                $alias = "notcl$i";
                $qb->leftJoin( 'categorylinks', $alias, [
                    "page_id = $alias.cl_from",
                    "$alias.cl_to" => str_replace(' ', '_', $cat)
                ] );
                $qb->andWhere( "$alias.cl_from IS NULL" );
            }
        }

        if ( !empty( $this->options['ignore'] ) ) {
            foreach ( $this->options['ignore'] as $ign ) {
                $qb->andWhere( 'page_title NOT LIKE ' . $dbr->addQuotes( str_replace(' ', '\_', $ign) ) );
            }
        }

        if ( !empty( $this->options['order'] ) ) {
            foreach ( $this->options['order'] as $ord ) {
                $parts = explode( ' ', strtolower( $ord ) );
                $field = $parts[0];
                $dir = (isset($parts[1]) && $parts[1] === 'desc') ? 'DESC' : 'ASC';
                
                if ( $field === 'creation' ) {
                    $qb->orderBy( 'page_id', $dir ); 
                } else {
                    $qb->orderBy( 'page_title', $dir );
                }
            }
        } else {
            $qb->orderBy( 'page_title', 'ASC' );
        }

        $res = $qb->fetchResultSet();
        $this->total = $res->numRows();

        $content = [];
        $authority = RequestContext::getMain()->getAuthority();
        $wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
        
        foreach ( $res as $row ) {
            $title = Title::newFromRow( $row );
            if ( $title && $authority->definitelyCan( 'read', $title ) ) {
                $content[] = $wikiPageFactory->newFromTitle( $title );
            }
        }

        if ( $this->parser->getOutput() ) {
            $this->parser->getOutput()->updateCacheExpiry( 0 );
        }

        return $content;
    }

    public function render( $outputType = 'html' ) {
        $this->outputType = $outputType;
        $pages = $this->getPages();
        
        $text = '';
        if ( $this->options['showtotal'] ) {
            $msg = wfMessage('tpl-total-results', $this->total)->text();
            if ( $this->outputType === 'html' ) {
                $text .= "<p><strong>" . htmlspecialchars($msg) . "</strong></p>\n";
            } else {
                $text .= "'''" . $msg . "'''\n\n";
            }
        }

        if ( $this->options['output'] === 'template' && !empty($this->options['template']) ) {
            $text .= $this->makeTemplatedList( $pages );
        } else {
            $text .= $this->makeSimpleList( $pages );
        }
        
        return $text;
    }

    public function makeTemplatedList( $pages ) {
        $tpl = $this->options['template'];
        $text = '';

        foreach ( $pages as $i => $page ) {
            $t = $page->getTitle();
            $args = [
                'index' => $i,
                'number' => $i + 1,
                'odd' => ($i % 2 === 0) ? 0 : 1,
                'ns_' . $t->getNamespace() => 1,
                'title' => $t->getPrefixedText()
            ];

            $wikitext = '{{' . $tpl;
            foreach ( $args as $k => $v ) {
                $wikitext .= '|' . $k . '=' . $v;
            }
            $wikitext .= '}}';

            // Если запрашивают HTML (тег), парсим сразу. Иначе (функция) отдаём вики-разметку.
            if ( $this->outputType === 'html' ) {
                $text .= $this->parser->recursiveTagParse( $wikitext, $this->frame ) . "\n";
            } else {
                $text .= $wikitext . "\n";
            }
        }

        return $text;
    }

    public function makeSimpleList( $pages ) {
        $text = "";
        if ( $this->outputType === 'html' ) {
            $text .= "<ul>\n";
            $linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
            foreach ( $pages as $page ) {
                $text .= "<li>" . $linkRenderer->makeKnownLink( $page->getTitle() ) . "</li>\n";
            }
            $text .= "</ul>\n";
        } else {
            // Возвращаем обычный вики-список
            foreach ( $pages as $page ) {
                $t = $page->getTitle()->getPrefixedText();
                $text .= "* [[:$t]]\n";
            }
        }
        return $text;
    }
}