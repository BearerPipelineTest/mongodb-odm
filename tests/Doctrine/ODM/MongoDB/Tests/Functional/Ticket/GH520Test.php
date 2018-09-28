<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Tests;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Proxy\Proxy;

class GH520Test extends BaseTest
{
    public function testPrimeWithGetSingleResult()
    {
        $document      = new GH520Document();
        $document->ref = new GH520Document();

        $this->dm->persist($document);
        $this->dm->flush();
        $this->dm->clear();

        $query = $this->dm->createQueryBuilder(GH520Document::class)
            ->field('id')->equals($document->id)
            ->field('ref')->prime(true)
            ->getQuery();

        $document = $query->getSingleResult();

        $this->assertInstanceOf(Proxy::class, $document->ref);
        $this->assertTrue($document->ref->__isInitialized());
    }

    public function testPrimeWithGetSingleResultWillNotPrimeEntireResultSet()
    {
        $document1 = new GH520Document();
        $document2 = new GH520Document();
        $document3 = new GH520Document();
        $document4 = new GH520Document();

        $document1->ref = $document2;
        $document3->ref = $document4;

        $this->dm->persist($document1);
        $this->dm->persist($document3);
        $this->dm->flush();
        $this->dm->clear();

        $primedIds = null;
        $primer    = static function (DocumentManager $dm, ClassMetadata $class, array $ids, array $hints) use (&$primedIds) {
            $primedIds = $ids;
        };

        $query = $this->dm->createQueryBuilder(GH520Document::class)
            ->field('ref')->exists(true)->prime($primer)
            ->getQuery();

        $query->getSingleResult();

        $this->assertContains($document2->id, $primedIds);
        $this->assertNotContains($document4->id, $primedIds, 'getSingleResult() does not prime the entire dataset');
    }
}

/** @ODM\Document */
class GH520Document
{
    /** @ODM\Id */
    public $id;

    /** @ODM\ReferenceOne(targetDocument=GH520Document::class, cascade={"persist"}) */
    public $ref;
}
