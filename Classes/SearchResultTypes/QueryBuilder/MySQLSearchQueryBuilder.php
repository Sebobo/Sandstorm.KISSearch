<?php

namespace Sandstorm\KISSearch\SearchResultTypes\QueryBuilder;

use Sandstorm\KISSearch\SearchResultTypes\SearchResult;
use Sandstorm\KISSearch\SearchResultTypes\SearchResultTypeName;

class MySQLSearchQueryBuilder
{

    public static function createFulltextIndex(SearchResultTypeName $searchResultTypeName, string $tableName, ColumnNamesByBucket $columnNames): string
    {
        // combined index
        $sqlQueries = [
            self::createSingleFulltextIndex($searchResultTypeName, $tableName, $columnNames->getAllColumnNames(), 'all')
        ];
        // single bucket index for weighting scores on individual columns
        $sqlQueries[] = self::createSingleFulltextIndex($searchResultTypeName, $tableName, $columnNames->getCritical(), 'critical');
        $sqlQueries[] = self::createSingleFulltextIndex($searchResultTypeName, $tableName, $columnNames->getMajor(), 'major');
        $sqlQueries[] = self::createSingleFulltextIndex($searchResultTypeName, $tableName, $columnNames->getNormal(), 'normal');
        $sqlQueries[] = self::createSingleFulltextIndex($searchResultTypeName, $tableName, $columnNames->getMinor(), 'minor');

        return implode("\n", array_filter($sqlQueries));
    }

    private static function createSingleFulltextIndex(SearchResultTypeName $searchResultTypeName, string $tableName, ?array $columnNames, string $indexSuffix): ?string
    {
        if (empty($columnNames)) {
            return null;
        }
        $columnNamesCommaSeparated = implode(', ', $columnNames);
        $indexSuffix = self::buildFulltextIndexName($searchResultTypeName, $indexSuffix);
        return <<<SQL
            create fulltext index $indexSuffix on $tableName ($columnNamesCommaSeparated);
        SQL;
    }

    public static function dropFulltextIndex(SearchResultTypeName $searchResultTypeName, string $tableName): string
    {
        $sqlQueries = [
            // combined index
            self::dropSingleFulltextIndex($searchResultTypeName, $tableName, 'all'),
            // single bucket index for weighting scores on individual columns
            $sqlQueries[] = self::dropSingleFulltextIndex($searchResultTypeName, $tableName, 'critical'),
            $sqlQueries[] = self::dropSingleFulltextIndex($searchResultTypeName, $tableName, 'major'),
            $sqlQueries[] = self::dropSingleFulltextIndex($searchResultTypeName, $tableName, 'normal'),
            $sqlQueries[] = self::dropSingleFulltextIndex($searchResultTypeName, $tableName, 'minor')
        ];

        return implode("\n", $sqlQueries);
    }

    private static function dropSingleFulltextIndex(SearchResultTypeName $searchResultTypeName, string $tableName, string $indexSuffix): string
    {
        $indexName = self::buildFulltextIndexName($searchResultTypeName, $indexSuffix);
        return <<<SQL
            drop index if exists $indexName on $tableName;
        SQL;
    }

    /**
     * @param SearchResultTypeName $searchResultTypeName
     * @param string $indexSuffix
     * @return string
     */
    private static function buildFulltextIndexName(SearchResultTypeName $searchResultTypeName, string $indexSuffix): string
    {
        return sprintf("idx_%s_%s", $searchResultTypeName->getName(), $indexSuffix);
    }

    public static function extractNormalizedFulltextFromJson(string $valueSql, string $jsonKey): string
    {
        return <<<SQL
            lower(json_value($valueSql, '$.$jsonKey'))
        SQL;
    }

    public static function extractAllText(string $valueSql): string
    {
        return self::sanitizeFulltextExtractionResult($valueSql);
    }

    public static function fulltextExtractHtmlTagContents(string $valueSql, string ...$tagNames): string
    {
        $tagNamesPattern = sprintf('(%s)', implode('|', $tagNames));
        $pattern = "(^.*?<$tagNamesPattern>)|(</$tagNamesPattern>.*?<$tagNamesPattern>)|(</$tagNamesPattern>.*?$)";

        return self::sanitizeFulltextExtractionResult(<<<SQL
            -- remove everything between closing and opening tags
            if(regexp_instr($valueSql, '$pattern'), regexp_replace($valueSql, '$pattern', ' '), null)
        SQL);
    }

    public static function fulltextExtractHtmlTextContent(string $valueSql, string ...$excludedTags): string
    {
        $tagNamesPattern = sprintf('(%s)', implode('|', $excludedTags));
        $tagContentPattern = sprintf('<%s.*?>.*?</%s>', $tagNamesPattern, $tagNamesPattern);
        return self::sanitizeFulltextExtractionResult(<<<SQL
            -- remove all excluded tags
            regexp_replace($valueSql, '$tagContentPattern', '')
        SQL);
    }

    private static function sanitizeFulltextExtractionResult($valueSql): string
    {
        return <<<SQL
            -- remove all duplicate whitespaces
            regexp_replace(
                -- remove all non word characters
                regexp_replace(
                    -- remove all HTML entities
                    regexp_replace(
                        -- remove all HTML tags
                        regexp_replace(
                            $valueSql,
                        '<.*?>', ' '),
                    '&.*?;', ' '),
                '[^\\\.a-zA-Z0-9]', ' '),
            '\\\s+', ' ')
        SQL;
    }

    public static function searchQuery(array $searchingQueryParts, array $mergingQueryParts): string
    {
        $searchingQueryPartsSql = implode(",\n", $searchingQueryParts);
        $mergingQueryPartsSql = implode(" union \n", $mergingQueryParts);
        $limitParamName = SearchResult::SQL_QUERY_PARAM_LIMIT;

        return <<<SQL
            -- searching query part
            with $searchingQueryPartsSql,
                 all_results as (
                    -- union of all search types
                    $mergingQueryPartsSql
                 )
            select
                -- select all search results
                a.result_id as result_id,
                a.result_type as result_type,
                a.result_title as result_title,
                -- sum the score
                sum(score) as sum_score,
                a.meta_data as meta_data
            from all_results a
            -- group by result id and type in case multiple merging query parts return the same result
            group by result_id, result_type
            order by sum_score desc
            limit :$limitParamName;
        SQL;
    }

    public static function prepareSearchTermQueryParameter(string $userInput): string
    {
        $sanitized = trim($userInput);
        $sanitized = strtolower($sanitized);

        $searchWords = explode(
            ' ',
            $sanitized
        );

        $searchWordsFuzzy = array_map(function(string $searchWord) {
            return $searchWord . '*';
        }, $searchWords);

        return implode(' ', $searchWordsFuzzy);
    }

}
