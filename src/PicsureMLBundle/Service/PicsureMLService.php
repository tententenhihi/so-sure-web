<?php

namespace PicsureMLBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\File\S3File;
use PicsureMLBundle\Document\TrainingData;

class PicsureMLService
{
    /** @var DocumentManager */
    protected $appDm;

    /** @var DocumentManager */
    protected $picsureMLDm;

    /** @var Filesystem */
    protected $mountManager;

    /** @var S3Client */
    protected $s3;

    /**
     * @param DocumentManager $appDm
     * @param DocumentManager $picsureMLDm
     * @param Filesystem      $mountManager
     * @param S3Client        $s3
     */
    public function __construct(
        DocumentManager $appDm,
        DocumentManager $picsureMLDm,
        $mountManager,
        S3Client $s3
    ) {
        $this->appDm = $appDm;
        $this->picsureMLDm = $picsureMLDm;
        $this->mountManager = $mountManager;
        $this->s3 = $s3;
    }

    public function addFileForTraining($file, $status)
    {
        $repo = $this->picsureMLDm->getRepository(TrainingData::class);
        if ($file->getFileType() == 'PicSureFile' && !$repo->imageExists($file->getKey())) {
            $image = new TrainingData();
            $image->setImagePath($file->getKey());
            $image->setLabel($status);
            $this->picsureMLDm->persist($image);
        }
        $this->picsureMLDm->flush();
    }

    public function predict($file)
    {
        try {
            $result = $this->s3->getObject(array(
                'Bucket' => $file->getBucket(),
                'Key'    => $file->getKey(),
                'SaveAs' => '/tmp/'.$file->getFilename()
            ));
        } catch (S3Exception $e) {
            throw new \Exception('Error downloading S3 file '.$file->getKey());
        }

        $process = new Process('python /var/ops/scripts/image/deep-learning/predict.py /tmp/'.$file->getFilename());
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $json = json_decode($process->getOutput(), true);

        if (isset($json['error'])) {
            throw new \Exception('Error: '.$json['error']['message']);
        } else {
            $file->addMetadata('picsure-ml-score', $json['scores']);
            $this->appDm->flush();
        }
    }

    public function sync()
    {
        $s3Repo = $this->appDm->getRepository(S3File::class);
        $picsureFiles = $s3Repo->findBy(['fileType' => 'picsure']);

        $imageRepo = $this->picsureMLDm->getRepository(TrainingData::class);
        $images = $imageRepo->createQueryBuilder()
                        ->select('imagePath')
                        ->getQuery()->execute();
        $paths = [];
        foreach ($images as $image) {
            $paths[] = $image->getImagePath();
        }

        foreach ($picsureFiles as $file) {
            if (!in_array($file->getKey(), $paths)) {
                $image = new TrainingData();
                $image->setImagePath($file->getKey());
                $metadata = $file->getMetadata();
                $status = null;
                if (isset($metadata['picsure-status'])) {
                    $status = $metadata['picsure-status'];
                }
                if (!empty($status)) {
                    if ($status == PhonePolicy::PICSURE_STATUS_APPROVED) {
                        $image->setLabel(TrainingData::LABEL_UNDAMAGED);
                    } elseif ($status == PhonePolicy::PICSURE_STATUS_INVALID) {
                        $image->setLabel(TrainingData::LABEL_INVALID);
                    } elseif ($status == PhonePolicy::PICSURE_STATUS_REJECTED) {
                        $image->setLabel(TrainingData::LABEL_DAMAGED);
                    }
                }
                $this->picsureMLDm->persist($image);
            }
        }

        $this->picsureMLDm->flush();
    }

    public function output(OutputInterface $output)
    {
        $filesystem = $this->mountManager->getFilesystem('s3picsure_fs');

        $repo = $this->picsureMLDm->getRepository(TrainingData::class);
        $qb = $repo->createQueryBuilder();
        $qb->sort('id', 'desc');
        $results = $qb->getQuery()->execute();

        $csv = [];

        foreach ($results as $result) {
            if ($result->hasLabel()) {
                $csv[] = sprintf(
                    "%s,%s",
                    $result->getImagePath(),
                    $result->getLabel()
                );
            }
        }

        $fs = new Filesystem();
        try {
            $file = sprintf("%s/training-data.csv", sys_get_temp_dir());
            $fs->dumpFile($file, implode(PHP_EOL, $csv));
            $stream = fopen($file, 'r+');
            if ($stream != false) {
                $filesystem->putStream('training-data.csv', $stream);
                fclose($stream);
            }
        } catch (IOExceptionInterface $e) {
            $output->writeln(sprintf('Error writing csv: %s', $e->getPath()));
        }
    }
/*
    public function sync($filesystem)
    {
        $repo = $this->dm->getRepository(Image::class);

        $contents = $filesystem->listContents('ml', true);
        foreach ($contents as $object) {
            if ($object['type'] == "file" && !$repo->imageExists($object['path'])) {
                $image = new Image();
                $image->setPath($object['path']);
                $this->dm->persist($image);
            }
        }

        $this->dm->flush();
    }

    public function annotate($filesystem)
    {
        $repo = $this->dm->getRepository(Image::class);

        $annotations = [];

        $qb = $repo->createQueryBuilder();
        $qb->sort('id', 'desc');
        $results = $qb->getQuery()->execute();

        foreach ($results as $result) {
            if ($result->hasAnnotation()) {
                $annotations[] = sprintf(
                    "%s 1 %d %d %d %d",
                    $result->getPath(),
                    $result->getX(),
                    $result->getY(),
                    $result->getWidth(),
                    $result->getHeight()
                );
            }
        }

        $fs = new Filesystem();
        try {
            $file = sprintf("%s/annotations.txt", sys_get_temp_dir());
            $fs->dumpFile($file, implode(PHP_EOL, $annotations));
            $stream = fopen($file, 'r+');
            if ($stream != false) {
                $filesystem->putStream('annotations.txt', $stream);
                fclose($stream);
            }
        } catch (IOExceptionInterface $e) {
            $this->logger->warning(
                sprintf("An error occurred while writting the annotations to %s", $e->getPath()),
                ['exception' => $e]
            );
        }
    }
    */
}
