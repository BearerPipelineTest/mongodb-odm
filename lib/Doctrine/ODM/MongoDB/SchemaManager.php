<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadataFactory;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Model\IndexInfo;
use function array_filter;
use function array_unique;
use function iterator_to_array;
use function ksort;
use function strpos;

class SchemaManager
{
    /** @var DocumentManager */
    protected $dm;

    /** @var ClassMetadataFactory */
    protected $metadataFactory;

    public function __construct(DocumentManager $dm, ClassMetadataFactory $cmf)
    {
        $this->dm = $dm;
        $this->metadataFactory = $cmf;
    }

    /**
     * Ensure indexes are created for all documents that can be loaded with the
     * metadata factory.
     *
     * @param int $timeout Timeout (ms) for acknowledged index creation
     */
    public function ensureIndexes($timeout = null)
    {
        foreach ($this->metadataFactory->getAllMetadata() as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
                continue;
            }
            $this->ensureDocumentIndexes($class->name, $timeout);
        }
    }

    /**
     * Ensure indexes exist for all mapped document classes.
     *
     * Indexes that exist in MongoDB but not the document metadata will be
     * deleted.
     *
     * @param int $timeout Timeout (ms) for acknowledged index creation
     */
    public function updateIndexes($timeout = null)
    {
        foreach ($this->metadataFactory->getAllMetadata() as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
                continue;
            }
            $this->updateDocumentIndexes($class->name, $timeout);
        }
    }

    /**
     * Ensure indexes exist for the mapped document class.
     *
     * Indexes that exist in MongoDB but not the document metadata will be
     * deleted.
     *
     * @param string $documentName
     * @param int    $timeout      Timeout (ms) for acknowledged index creation
     * @throws \InvalidArgumentException
     */
    public function updateDocumentIndexes($documentName, $timeout = null)
    {
        $class = $this->dm->getClassMetadata($documentName);

        if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
            throw new \InvalidArgumentException('Cannot update document indexes for mapped super classes, embedded documents or aggregation result documents.');
        }

        $documentIndexes = $this->getDocumentIndexes($documentName);
        $collection = $this->dm->getDocumentCollection($documentName);
        $mongoIndexes = iterator_to_array($collection->listIndexes());

        /* Determine which Mongo indexes should be deleted. Exclude the ID index
         * and those that are equivalent to any in the class metadata.
         */
        $self = $this;
        $mongoIndexes = array_filter($mongoIndexes, function (IndexInfo $mongoIndex) use ($documentIndexes, $self) {
            if ($mongoIndex['name'] === '_id_') {
                return false;
            }

            foreach ($documentIndexes as $documentIndex) {
                if ($self->isMongoIndexEquivalentToDocumentIndex($mongoIndex, $documentIndex)) {
                    return false;
                }
            }

            return true;
        });

        // Delete indexes that do not exist in class metadata
        foreach ($mongoIndexes as $mongoIndex) {
            if (! isset($mongoIndex['name'])) {
                continue;
            }

            $collection->dropIndex($mongoIndex['name']);
        }

        $this->ensureDocumentIndexes($documentName, $timeout);
    }

    /**
     * @param string $documentName
     * @return array
     */
    public function getDocumentIndexes($documentName)
    {
        $visited = [];
        return $this->doGetDocumentIndexes($documentName, $visited);
    }

    /**
     * @param string $documentName
     * @param array  $visited
     * @return array
     */
    private function doGetDocumentIndexes($documentName, array &$visited)
    {
        if (isset($visited[$documentName])) {
            return [];
        }

        $visited[$documentName] = true;

        $class = $this->dm->getClassMetadata($documentName);
        $indexes = $this->prepareIndexes($class);
        $embeddedDocumentIndexes = [];

        // Add indexes from embedded & referenced documents
        foreach ($class->fieldMappings as $fieldMapping) {
            if (isset($fieldMapping['embedded'])) {
                if (isset($fieldMapping['targetDocument'])) {
                    $possibleEmbeds = [$fieldMapping['targetDocument']];
                } elseif (isset($fieldMapping['discriminatorMap'])) {
                    $possibleEmbeds = array_unique($fieldMapping['discriminatorMap']);
                } else {
                    continue;
                }
                foreach ($possibleEmbeds as $embed) {
                    if (isset($embeddedDocumentIndexes[$embed])) {
                        $embeddedIndexes = $embeddedDocumentIndexes[$embed];
                    } else {
                        $embeddedIndexes = $this->doGetDocumentIndexes($embed, $visited);
                        $embeddedDocumentIndexes[$embed] = $embeddedIndexes;
                    }
                    foreach ($embeddedIndexes as $embeddedIndex) {
                        foreach ($embeddedIndex['keys'] as $key => $value) {
                            $embeddedIndex['keys'][$fieldMapping['name'] . '.' . $key] = $value;
                            unset($embeddedIndex['keys'][$key]);
                        }
                        $indexes[] = $embeddedIndex;
                    }
                }
            } elseif (isset($fieldMapping['reference']) && isset($fieldMapping['targetDocument'])) {
                foreach ($indexes as $idx => $index) {
                    $newKeys = [];
                    foreach ($index['keys'] as $key => $v) {
                        if ($key === $fieldMapping['name']) {
                            $key = ClassMetadata::getReferenceFieldName($fieldMapping['storeAs'], $key);
                        }
                        $newKeys[$key] = $v;
                    }
                    $indexes[$idx]['keys'] = $newKeys;
                }
            }
        }
        return $indexes;
    }

    /**
     * @return array
     */
    private function prepareIndexes(ClassMetadata $class)
    {
        $persister = $this->dm->getUnitOfWork()->getDocumentPersister($class->name);
        $indexes = $class->getIndexes();
        $newIndexes = [];

        foreach ($indexes as $index) {
            $newIndex = [
                'keys' => [],
                'options' => $index['options'],
            ];
            foreach ($index['keys'] as $key => $value) {
                $key = $persister->prepareFieldName($key);
                if ($class->hasField($key)) {
                    $mapping = $class->getFieldMapping($key);
                    $newIndex['keys'][$mapping['name']] = $value;
                } else {
                    $newIndex['keys'][$key] = $value;
                }
            }

            $newIndexes[] = $newIndex;
        }

        return $newIndexes;
    }

    /**
     * Ensure the given document's indexes are created.
     *
     * @param string $documentName
     * @param int    $timeout      Timeout (ms) for acknowledged index creation
     * @throws \InvalidArgumentException
     */
    public function ensureDocumentIndexes($documentName, $timeout = null)
    {
        $class = $this->dm->getClassMetadata($documentName);
        if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
            throw new \InvalidArgumentException('Cannot create document indexes for mapped super classes, embedded documents or query result documents.');
        }

        $indexes = $this->getDocumentIndexes($documentName);
        if (! $indexes) {
            return;
        }

        $collection = $this->dm->getDocumentCollection($class->name);
        foreach ($indexes as $index) {
            $keys = $index['keys'];
            $options = $index['options'];

            if (! isset($options['timeout']) && isset($timeout)) {
                $options['timeout'] = $timeout;
            }

            $collection->createIndex($keys, $options);
        }
    }

    /**
     * Delete indexes for all documents that can be loaded with the
     * metadata factory.
     */
    public function deleteIndexes()
    {
        foreach ($this->metadataFactory->getAllMetadata() as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
                continue;
            }
            $this->deleteDocumentIndexes($class->name);
        }
    }

    /**
     * Delete the given document's indexes.
     *
     * @param string $documentName
     * @throws \InvalidArgumentException
     */
    public function deleteDocumentIndexes($documentName)
    {
        $class = $this->dm->getClassMetadata($documentName);
        if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
            throw new \InvalidArgumentException('Cannot delete document indexes for mapped super classes, embedded documents or query result documents.');
        }
        $this->dm->getDocumentCollection($documentName)->dropIndexes();
    }

    /**
     * Create all the mapped document collections in the metadata factory.
     */
    public function createCollections()
    {
        foreach ($this->metadataFactory->getAllMetadata() as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
                continue;
            }
            $this->createDocumentCollection($class->name);
        }
    }

    /**
     * Create the document collection for a mapped class.
     *
     * @param string $documentName
     * @throws \InvalidArgumentException
     */
    public function createDocumentCollection($documentName)
    {
        $class = $this->dm->getClassMetadata($documentName);

        if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
            throw new \InvalidArgumentException('Cannot create document collection for mapped super classes, embedded documents or query result documents.');
        }

        $this->dm->getDocumentDatabase($documentName)->createCollection(
            $class->getCollection(),
            [
                'capped' => $class->getCollectionCapped(),
                'size' => $class->getCollectionSize(),
                'max' => $class->getCollectionMax(),
            ]
        );
    }

    /**
     * Drop all the mapped document collections in the metadata factory.
     */
    public function dropCollections()
    {
        foreach ($this->metadataFactory->getAllMetadata() as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
                continue;
            }
            $this->dropDocumentCollection($class->name);
        }
    }

    /**
     * Drop the document collection for a mapped class.
     *
     * @param string $documentName
     * @throws \InvalidArgumentException
     */
    public function dropDocumentCollection($documentName)
    {
        $class = $this->dm->getClassMetadata($documentName);
        if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
            throw new \InvalidArgumentException('Cannot delete document indexes for mapped super classes, embedded documents or query result documents.');
        }
        $this->dm->getDocumentCollection($documentName)->drop();
    }

    /**
     * Drop all the mapped document databases in the metadata factory.
     */
    public function dropDatabases()
    {
        foreach ($this->metadataFactory->getAllMetadata() as $class) {
            if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
                continue;
            }
            $this->dropDocumentDatabase($class->name);
        }
    }

    /**
     * Drop the document database for a mapped class.
     *
     * @param string $documentName
     * @throws \InvalidArgumentException
     */
    public function dropDocumentDatabase($documentName)
    {
        $class = $this->dm->getClassMetadata($documentName);
        if ($class->isMappedSuperclass || $class->isEmbeddedDocument || $class->isQueryResultDocument) {
            throw new \InvalidArgumentException('Cannot drop document database for mapped super classes, embedded documents or query result documents.');
        }
        $this->dm->getDocumentDatabase($documentName)->drop();
    }

    /**
     * Determine if an index returned by MongoCollection::getIndexInfo() can be
     * considered equivalent to an index in class metadata.
     *
     * Indexes are considered different if:
     *
     *   (a) Key/direction pairs differ or are not in the same order
     *   (b) Sparse or unique options differ
     *   (c) Mongo index is unique without dropDups and mapped index is unique
     *       with dropDups
     *   (d) Geospatial options differ (bits, max, min)
     *   (e) The partialFilterExpression differs
     *
     * Regarding (c), the inverse case is not a reason to delete and
     * recreate the index, since dropDups only affects creation of
     * the unique index. Additionally, the background option is only
     * relevant to index creation and is not considered.
     *
     * @param array|IndexInfo $mongoIndex    Mongo index data.
     * @param array           $documentIndex Document index data.
     * @return bool True if the indexes are equivalent, otherwise false.
     */
    public function isMongoIndexEquivalentToDocumentIndex($mongoIndex, $documentIndex)
    {
        $documentIndexOptions = $documentIndex['options'];

        if (! $this->isEquivalentIndexKeys($mongoIndex, $documentIndex)) {
            return false;
        }

        if (empty($mongoIndex['sparse']) xor empty($documentIndexOptions['sparse'])) {
            return false;
        }

        if (empty($mongoIndex['unique']) xor empty($documentIndexOptions['unique'])) {
            return false;
        }

        if (! empty($mongoIndex['unique']) && empty($mongoIndex['dropDups']) &&
            ! empty($documentIndexOptions['unique']) && ! empty($documentIndexOptions['dropDups'])) {
            return false;
        }

        foreach (['bits', 'max', 'min'] as $option) {
            if (isset($mongoIndex[$option]) xor isset($documentIndexOptions[$option])) {
                return false;
            }

            if (isset($mongoIndex[$option], $documentIndexOptions[$option]) &&
                $mongoIndex[$option] !== $documentIndexOptions[$option]) {
                return false;
            }
        }

        if (empty($mongoIndex['partialFilterExpression']) xor empty($documentIndexOptions['partialFilterExpression'])) {
            return false;
        }

        if (isset($mongoIndex['partialFilterExpression'], $documentIndexOptions['partialFilterExpression']) &&
            $mongoIndex['partialFilterExpression'] !== $documentIndexOptions['partialFilterExpression']) {
            return false;
        }

        if (isset($mongoIndex['weights']) && ! $this->isEquivalentTextIndexWeights($mongoIndex, $documentIndex)) {
            return false;
        }

        foreach (['default_language', 'language_override', 'textIndexVersion'] as $option) {
            /* Text indexes will always report defaults for these options, so
             * only compare if we have explicit values in the document index. */
            if (isset($mongoIndex[$option], $documentIndexOptions[$option]) &&
                $mongoIndex[$option] !== $documentIndexOptions[$option]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the keys for a MongoDB index can be considered equivalent to
     * those for an index in class metadata.
     *
     * @param array|IndexInfo $mongoIndex    Mongo index data.
     * @param array           $documentIndex Document index data.
     * @return bool True if the indexes have equivalent keys, otherwise false.
     */
    private function isEquivalentIndexKeys($mongoIndex, array $documentIndex)
    {
        $mongoIndexKeys    = $mongoIndex['key'];
        $documentIndexKeys = $documentIndex['keys'];

        /* If we are dealing with text indexes, we need to unset internal fields
         * from the MongoDB index and filter out text fields from the document
         * index. This will leave only non-text fields, which we can compare as
         * normal. Any text fields in the document index will be compared later
         * with isEquivalentTextIndexWeights(). */
        if (isset($mongoIndexKeys['_fts']) && $mongoIndexKeys['_fts'] === 'text') {
            unset($mongoIndexKeys['_fts'], $mongoIndexKeys['_ftsx']);

            $documentIndexKeys = array_filter($documentIndexKeys, function ($type) {
                return $type !== 'text';
            });
        }

        /* Avoid a strict equality check here. The numeric type returned by
         * MongoDB may differ from the document index without implying that the
         * indexes themselves are inequivalent. */
        // phpcs:disable SlevomatCodingStandard.ControlStructures.DisallowEqualOperators.DisallowedEqualOperator
        return $mongoIndexKeys == $documentIndexKeys;
    }

    /**
     * Determine if the text index weights for a MongoDB index can be considered
     * equivalent to those for an index in class metadata.
     *
     * @param array|IndexInfo $mongoIndex    Mongo index data.
     * @param array           $documentIndex Document index data.
     * @return bool True if the indexes have equivalent weights, otherwise false.
     */
    private function isEquivalentTextIndexWeights($mongoIndex, array $documentIndex)
    {
        $mongoIndexWeights    = $mongoIndex['weights'];
        $documentIndexWeights = $documentIndex['options']['weights'] ?? [];

        // If not specified, assign a default weight for text fields
        foreach ($documentIndex['keys'] as $key => $type) {
            if ($type !== 'text' || isset($documentIndexWeights[$key])) {
                continue;
            }

            $documentIndexWeights[$key] = 1;
        }

        /* MongoDB returns the weights sorted by field name, but we'll sort both
         * arrays in case that is internal behavior not to be relied upon. */
        ksort($mongoIndexWeights);
        ksort($documentIndexWeights);

        /* Avoid a strict equality check here. The numeric type returned by
         * MongoDB may differ from the document index without implying that the
         * indexes themselves are inequivalent. */
        // phpcs:disable SlevomatCodingStandard.ControlStructures.DisallowEqualOperators.DisallowedEqualOperator
        return $mongoIndexWeights == $documentIndexWeights;
    }

    /**
     * Ensure collections are sharded for all documents that can be loaded with the
     * metadata factory.
     *
     * @param array $indexOptions Options for `ensureIndex` command. It's performed on an existing collections
     *
     * @throws MongoDBException
     */
    public function ensureSharding(array $indexOptions = [])
    {
        foreach ($this->metadataFactory->getAllMetadata() as $class) {
            if ($class->isMappedSuperclass || ! $class->isSharded()) {
                continue;
            }

            $this->ensureDocumentSharding($class->name, $indexOptions);
        }
    }

    /**
     * Ensure sharding for collection by document name.
     *
     * @param string $documentName
     * @param array  $indexOptions Options for `ensureIndex` command. It's performed on an existing collections.
     *
     * @throws MongoDBException
     */
    public function ensureDocumentSharding($documentName, array $indexOptions = [])
    {
        $class = $this->dm->getClassMetadata($documentName);
        if (! $class->isSharded()) {
            return;
        }

        $this->enableShardingForDbByDocumentName($documentName);

        $try = 0;
        do {
            try {
                $result = $this->runShardCollectionCommand($documentName);
                $done = true;

                // Need to check error message because MongoDB 3.0 does not return a code for this error
                if (! (bool) $result['ok'] && strpos($result['errmsg'], 'please create an index that starts') !== false) {
                    // The proposed key is not returned when using mongo-php-adapter with ext-mongodb.
                    // See https://github.com/mongodb/mongo-php-driver/issues/296 for details
                    $key = $result['proposedKey'] ?? $this->dm->getClassMetadata($documentName)->getShardKey()['keys'];

                    $this->dm->getDocumentCollection($documentName)->ensureIndex($key, $indexOptions);
                    $done = false;
                }
            } catch (RuntimeException $e) {
                if ($e->getCode() === 20 || $e->getCode() === 23 || $e->getMessage() === 'already sharded') {
                    return;
                }

                throw $e;
            }
        } while (! $done && $try < 2);

        // Starting with MongoDB 3.2, this command returns code 20 when a collection is already sharded.
        // For older MongoDB versions, check the error message
        if ((bool) $result['ok'] || (isset($result['code']) && $result['code'] === 20) || $result['errmsg'] === 'already sharded') {
            return;
        }

        throw MongoDBException::failedToEnsureDocumentSharding($documentName, $result['errmsg']);
    }

    /**
     * Enable sharding for database which contains documents with given name.
     *
     * @param string $documentName
     *
     * @throws MongoDBException
     */
    public function enableShardingForDbByDocumentName($documentName)
    {
        $dbName = $this->dm->getDocumentDatabase($documentName)->getDatabaseName();
        $adminDb = $this->dm->getClient()->selectDatabase('admin');

        try {
            $adminDb->command(['enableSharding' => $dbName]);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 23 || $e->getMessage() === 'already enabled') {
                throw MongoDBException::failedToEnableSharding($dbName, $e->getMessage());
            }
        }
    }

    /**
     * @param string $documentName
     *
     * @return array
     */
    private function runShardCollectionCommand($documentName)
    {
        $class = $this->dm->getClassMetadata($documentName);
        $dbName = $this->dm->getDocumentDatabase($documentName)->getDatabaseName();
        $shardKey = $class->getShardKey();
        $adminDb = $this->dm->getClient()->selectDatabase('admin');

        $result = $adminDb->command(
            [
                'shardCollection' => $dbName . '.' . $class->getCollection(),
                'key'             => $shardKey['keys'],
            ]
        )->toArray()[0];

        return $result;
    }
}
