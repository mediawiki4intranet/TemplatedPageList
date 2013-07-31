<?php

/**
 * Класс валидатора запроса
 * 
 * Реализует хук десериализации OutpuParser (т.к. самого хука в ядре нет).
 * 
 * Хук необходим для проверки валидации содержимого страницы:
 * если произошли изменения в страницах, которые попадают под запрос,
 * то кэш страницы инвалидируется и при парсинге страницы происходит выборка нового контента
 * 
 */
class QueryCacheValidator
{
    /**
     * @var ParserOutput
     */
    protected $mOutput;
    
    /**
     * @var array
     */
    protected $options;

    public function __construct($mOutput, $options)
    {
        $this->mOutput = $mOutput;
        $this->options = $options;
    }
    
    /**
     * Достаём запрос из кэша страницы и сравниваем результаты в кэше с результатами запроса
     */
    public function __wakeup()
    {
        $dbr = wfGetDB(DB_SLAVE);
        $res = $dbr->select(
            $this->options['tables'],
            $this->options['select'],
            $this->options['where'],
            __METHOD__,
            $this->options['opt'],
            $this->options['joins']
        );
        $result = true;
        foreach ($res as $row)
        {
            $title = Title::newFromRow($row);
            if (!is_object($title))
            {
                continue;
            }
            // проверяем, что в кэше такие же страницы, что и в выборке
            if ($title->userCanRead())
            {
                $article = new Article($title);
                if (
                    !isset($this->options['results'][$title->getArticleID()]) ||
                    ($article->getRevIdFetched() != $this->options['results'][$title->getArticleID()])
                )
                {
                    // Новая страница или ревизия...
                    $result = false;
                }
            }
            elseif (isset($this->options['results'][$title->getArticleID()]))
            {
                // изменились права к странице и теперь юзер её не увидит
                $result = false;
            }
            if (!$result)
            {
                break;
            }
        }
        if (!$result)
        {
            $this->mOutput->mCacheExpiry = 0;
        }
    }
}
