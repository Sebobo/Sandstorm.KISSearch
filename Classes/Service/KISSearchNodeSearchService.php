<?php

declare(strict_types=1);

namespace Sandstorm\KISSearch\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Validation\Validator\NodeIdentifierValidator;
use Neos\Flow\Configuration\Exception\InvalidConfigurationTypeException;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\NodeSearchServiceInterface;
use Sandstorm\KISSearch\SearchResultTypes\NeosContent\NeosContentAdditionalParameters;
use Sandstorm\KISSearch\SearchResultTypes\NeosContent\NeosContentSearchResultType;
use Sandstorm\KISSearch\SearchResultTypes\SearchResult;

class KISSearchNodeSearchService implements NodeSearchServiceInterface
{

    private readonly SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * @throws InvalidConfigurationTypeException
     */
    public function findByProperties($term, array $searchNodeTypes, Context $context): array
    {
        /**
         * @var $contentContext ContentContext
         */
        $contentContext = $context;
        $searchResults = [];

        // Add results for node identifiers in the search term directly
        if (preg_match(NodeIdentifierValidator::PATTERN_MATCH_NODE_IDENTIFIER, $term) !== 0) {
            $nodeByIdentifier = $context->getNodeByIdentifier($term);
            if ($nodeByIdentifier !== null && $this->nodeSatisfiesSearchNodeTypes($nodeByIdentifier, $searchNodeTypes)) {
                $searchResults[$nodeByIdentifier->getPath()] = $nodeByIdentifier;
            }
        }

        // TODO handle this better: new API in KISSearch that looks inside language content dimension of a node and returns the PG TS language
        try {
            $language = $this->searchService->getDefaultLanguage();
        } catch (\Throwable) {
            $language = 'simple';
        }

        $siteNodeName = $contentContext->getCurrentSite()->getNodeName();
        $query = new SearchQueryInput(
            $term,
            [
                NeosContentAdditionalParameters::SITE_NODE_NAME => $siteNodeName,
                NeosContentAdditionalParameters::DOCUMENT_NODE_TYPES => $searchNodeTypes,
                //NeosContentAdditionalParameters::ADDITIONAL_QUERY_PARAM_NAME_DIMENSION_VALUES => TODO dimension values
                // TODO new parameter: workspace
            ],
            $language
        );
        $searchResultsFromQuery = $this->searchService->search($query, 1000, true);

        // Filter out results with a score lower than 1.0
        // TODO: Make this configurable in the future
        $searchResultsFromQuery = array_filter($searchResultsFromQuery, static function (SearchResult $searchResult) {
            return $searchResult->getScore() >= 1.0;
        });

        return array_merge($searchResults, $this->searchResultsToNodes($contentContext, $searchResultsFromQuery));
    }

    /**
     * @param SearchResult[] $searchResults
     * @return NodeInterface[]
     */
    protected function searchResultsToNodes(ContentContext $contentContext, array $searchResults): array
    {
        $searchResultNodes = [];
        foreach ($searchResults as $searchResult) {
            if ($searchResult->getResultTypeName()->getName() !== NeosContentSearchResultType::TYPE_NAME) {
                continue;
            }
            $searchResultNodes[] = $contentContext->getNodeByIdentifier($searchResult->getIdentifier()->getIdentifier());
        }
        return $searchResultNodes;
    }

    protected function nodeSatisfiesSearchNodeTypes(NodeInterface $node, array $searchNodeTypes): bool
    {
        foreach ($searchNodeTypes as $nodeTypeName) {
            if ($node->getNodeType()->isOfType($nodeTypeName)) {
                return true;
            }
        }
        return false;
    }
}
