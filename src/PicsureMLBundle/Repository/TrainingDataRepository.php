<?php

namespace PicsureMLBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use PicsureMLBundle\Document\TrainingData;

class TrainingDataRepository extends DocumentRepository
{

    public function imageExists($path)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('imagePath')->equals($path);

        return $qb
            ->getQuery()
            ->execute()
            ->count() > 0;
    }

    public function getTotalCount($version, $label)
    {
        $qb = $this->createQueryBuilder();

        if ($version != null) {
            $qb->field('versions')->equals($version);
        }

        if ($label != null) {
            if ($label == 'none') {
                $qb->field('label')->equals(null);
            } else {
                $qb->field('label')->equals($label);
            }
        }

        return $qb
                ->getQuery()
                ->execute()
                ->count();
    }

    public function getPreviousImage($id)
    {
        $qb = $this->createQueryBuilder();
        $qb->sort('id', 'desc');

        $results = $qb->getQuery()->execute();

        $prevId = null;
        $previousId = '0';
        foreach ($results as $result) {
            if ($result->getId() == $id) {
                $prevId = $previousId;
                break;
            } else {
                $previousId = $result->getId();
            }
        }

        return $prevId;
    }

    public function getNextImage($id)
    {
        $qb = $this->createQueryBuilder();
        $qb->sort('id', 'desc');

        $results = $qb->getQuery()->execute();

        $nextId = null;
        $getNext = false;
        foreach ($results as $result) {
            if (!$getNext) {
                if ($result->getId() == $id) {
                    $getNext = true;
                }
            } else {
                $nextId = $result->getId();
                break;
            }
        }

        return $nextId;
    }
}
