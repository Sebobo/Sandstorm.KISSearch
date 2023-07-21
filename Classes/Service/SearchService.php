<?php

namespace Sandstorm\KISSearch\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Gedmo\Exception\UnsupportedObjectManagerException;
use Neos\Flow\Annotations\Scope;
use Neos\Flow\Configuration\ConfigurationManager;
use Sandstorm\KISSearch\SearchResultTypes\DatabaseType;
use Sandstorm\KISSearch\SearchResultTypes\NeosContent\NeosContentMySQLDatabaseMigration;
use Sandstorm\KISSearch\SearchResultTypes\QueryBuilder\MySQLSearchQueryBuilder;
use Sandstorm\KISSearch\SearchResultTypes\SearchQueryProviderInterface;
use Sandstorm\KISSearch\SearchResultTypes\SearchResult;
use Sandstorm\KISSearch\SearchResultTypes\SearchResultFrontend;
use Sandstorm\KISSearch\SearchResultTypes\SearchResultTypeInterface;
use Sandstorm\KISSearch\SearchResultTypes\SearchResultTypesRegistry;
use Sandstorm\KISSearch\SearchResultTypes\UnsupportedDatabaseException;

#[Scope('singleton')]
class SearchService
{

    // constructor injected
    private readonly SearchResultTypesRegistry $searchResultTypesRegistry;

    private readonly ConfigurationManager $configurationManager;

    private readonly EntityManagerInterface $entityManager;


    /**
     * @param SearchResultTypesRegistry $searchResultTypesRegistry
     * @param ConfigurationManager $configurationManager
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(SearchResultTypesRegistry $searchResultTypesRegistry, ConfigurationManager $configurationManager, \Doctrine\ORM\EntityManagerInterface $entityManager)
    {
        $this->searchResultTypesRegistry = $searchResultTypesRegistry;
        $this->configurationManager = $configurationManager;
        $this->entityManager = $entityManager;
    }

    /**
     * Searches all sources from the registered search result types in one single SQL query.
     *
     * @param SearchQuery $searchQuery
     * @return SearchResult[]
     */
    public function search(SearchQuery $searchQuery): array
    {
        $databaseType = DatabaseType::detectDatabase($this->configurationManager);
        $searchResultTypes = $this->searchResultTypesRegistry->getConfiguredSearchResultTypes();

        return $this->internalSearch($databaseType, $searchQuery, $searchResultTypes);
    }

    /**
     * Searches all sources from the registered search result types in one single SQL query.
     * Also enriches the search results with their respective search result document URLs.
     *
     * @param SearchQuery $searchQuery
     * @return SearchResultFrontend[]
     */
    public function searchFrontend(SearchQuery $searchQuery): array
    {
        $databaseType = DatabaseType::detectDatabase($this->configurationManager);
        $searchResultTypes = $this->searchResultTypesRegistry->getConfiguredSearchResultTypes();

        $results = $this->internalSearch($databaseType, $searchQuery, $searchResultTypes);

        return array_map(function(SearchResult $searchResult) use ($searchResultTypes) {
            $responsibleSearchResultType = $searchResultTypes[$searchResult->getResultTypeName()->getName()];
            $resultPageUrl = $responsibleSearchResultType->buildUrlToResultPage($searchResult->getIdentifier());
            return $searchResult->withDocumentUrl($resultPageUrl);
        }, $results);
    }

    /**
     * @param DatabaseType $databaseType
     * @param SearchQuery $searchQuery
     * @param SearchResultTypeInterface[] $searchResultTypes
     * @return SearchResult[]
     */
    private function internalSearch(DatabaseType $databaseType, SearchQuery $searchQuery, array $searchResultTypes): array
    {
        // search query
        $searchQuerySql = $this->buildSearchQuerySql($databaseType, $searchResultTypes);

        // prepare search term parameter from user input
        $searchTermParameterValue = self::prepareSearchTermParameterValue($databaseType, $searchQuery->getQuery());

        // prepare query
        $resultSetMapping = self::buildResultSetMapping();
        $doctrineQuery = $this->entityManager->createNativeQuery($searchQuerySql, $resultSetMapping);
        $doctrineQuery->setParameters([
            SearchResult::SQL_QUERY_PARAM_QUERY => $searchTermParameterValue,
            SearchResult::SQL_QUERY_PARAM_LIMIT => $searchQuery->getLimit()
        ]);

        // fire query
        return $doctrineQuery->getResult();
    }

    /**
     * @param DatabaseType $databaseType
     * @param SearchResultTypeInterface[] $searchResultTypes
     * @return string
     */
    private function buildSearchQuerySql(DatabaseType $databaseType, array $searchResultTypes): string
    {
        $searchQueryProviders = array_map(function(SearchResultTypeInterface $searchResultType) use ($databaseType) {
            return $searchResultType->getSearchQueryProvider($databaseType);
        }, $searchResultTypes);

        $searchingQueryParts = array_map(function(SearchQueryProviderInterface $provider) {
            return $provider->getResultSearchingQueryPart();
        }, $searchQueryProviders);

        $mergingQueryParts = array_map(function(SearchQueryProviderInterface $provider) {
            return $provider->getResultMergingQueryPart();
        }, $searchQueryProviders);

        return match ($databaseType) {
            DatabaseType::MYSQL => MySQLSearchQueryBuilder::searchQuery($searchingQueryParts, $mergingQueryParts),
            DatabaseType::POSTGRES => throw new UnsupportedDatabaseException('Postgres will be supported soon <3', 1689933374),
            default => throw new UnsupportedDatabaseException(
                "Search service does not support database of type '$databaseType->name'",
                1689933081
            )
        };
    }

    private static function prepareSearchTermParameterValue(DatabaseType $databaseType, string $userInput): string
    {
        return match ($databaseType) {
            DatabaseType::MYSQL => MySQLSearchQueryBuilder::prepareSearchTermQueryParameter($userInput),
            DatabaseType::POSTGRES => throw new UnsupportedDatabaseException('Postgres will be supported soon <3', 1689936252),
            default => throw new UnsupportedDatabaseException(
                "Search service does not support database of type '$databaseType->name'",
                1689936258
            )
        };
    }

    private static function buildResultSetMapping(): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('result_id', 1);
        $rsm->addScalarResult('result_type', 2);
        $rsm->addScalarResult('result_title', 3);
        $rsm->addScalarResult('sum_score', 4, 'float');
        $rsm->newObjectMappings['result_id'] = [
            'className' => SearchResult::class,
            'objIndex' => 0,
            'argIndex' => 0,
        ];
        $rsm->newObjectMappings['result_type'] = [
            'className' => SearchResult::class,
            'objIndex' => 0,
            'argIndex' => 1,
        ];
        $rsm->newObjectMappings['result_title'] = [
            'className' => SearchResult::class,
            'objIndex' => 0,
            'argIndex' => 2,
        ];
        $rsm->newObjectMappings['sum_score'] = [
            'className' => SearchResult::class,
            'objIndex' => 0,
            'argIndex' => 3,
        ];

        return $rsm;
    }

}