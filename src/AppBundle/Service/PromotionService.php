<?php
namespace AppBundle\Service;

use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Document\Policy;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Repository\Invitation\InvitationRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Checks out promotion participation and sees what users are ripe for getting a reward.
 */
class PromotionService
{
    /** @var DocumentManager */
    protected $dm;
    /** @var MailerService */
    protected $mailerService;
    /** @var LoggerInterface */
    protected $logger;

    /**
     * Builds service and injects dependencies.
     * @param DocumentManager $dm            is the document manager.
     * @param MailerService   $mailerService is the mail sender.
     * @param LoggerInterface $logger        is the logger.
     */
    public function __construct(DocumentManager $dm, MailerService $mailerService, LoggerInterface $logger)
    {
        $this->dm = $dm;
        $this->mailerService = $mailerService;
        $this->logger = $logger;
    }

    /**
     * Loops through a bunch of promotions and checks if the users in them are conforming to the promotion conditions,
     * and invalidates promotion participations that have failed or become impossible.
     * @param array|null $promotions is the list of promotions or null to load in those promotions.
     * @param \DateTime  $date       is the date that is to be considered as current for the evaluation of conditions.
     * @return int number of promotions that have been completed.
     */
    public function generate($promotions = null, \DateTime $date = null)
    {
        if ($promotions === null) {
            $promotions = $this->dm->getRepository(Promotion::class)->findBy([]);
        }
        if (!$date) {
            $date = new \DateTime();
        }
        foreach ($promotions as $promotion) {
            $participations = $promotion->getParticipating();
            foreach ($participations as $participation) {
                if ($participation->getStatus() != Participation::STATUS_ACTIVE) {
                    // TODO: this will turn into a waste of time when there are a heap of completed participations.
                    //       I could make a query to get only the active ones.
                    continue;
                }
                $policy = $participation->getPolicy();
                // check for existing tastecard to invalidate.
                // TODO: maybe there is another case like this if reward pot is too full or something but I will have to
                //       check that with someone later. If so I will add a function.
                if ($promotion->getReward() == Promotion::REWARD_TASTE_CARD && $policy->getTasteCard()) {
                    $this->endParticipation($participation, Participation::STATUS_INVALID);
                    continue;
                }
                $this->checkConditions($participation, $date);
            }
        }
    }

    /**
     * Checks if the given participation fulfills the conditions of it's promotion and if so it fires the reward and
     * sets the status accordingly.
     * @param Participation $participation is the participation which we are checking.
     * @param \DateTime     $date          is the date considered as current for the purposes of the conditions.
     */
    public function checkConditions($participation, \DateTime $date)
    {
        $promotion = $participation->getPromotion();
        $policy = $participation->getPolicy();
        $condition = $promotion->getCondition();
        $interval = new \DateInterval("P".$promotion->getPeriod()."D");
        $end = (clone ($participation->getStart()))->add($interval);
        $finished = $end->diff($date)->invert == 0;
        $completed = 0;
        if ($condition == Promotion::CONDITION_NONE) {
            if ($finished) {
                $this->endParticipation($participation);
            }
        } elseif ($condition == Promotion::CONDITION_NO_CLAIMS) {
            if (!empty($policy->getClaimsInPeriod($participation->getStart(), $end))) {
                $this->endParticipation($participation, Participation::STATUS_FAILED);
            } elseif ($finished) {
                $this->endParticipation($participation);
                $completed++;
            }
        } elseif ($condition == Promotion::CONDITION_INVITES) {
            /** @var InvitationRepository $invitationRepository */
            $invitationRepository = $this->dm->getRepository(Invitation::class);
            $invites = $invitationRepository->count([$policy], $participation->getStart(), $end);
            if ($invites >= $promotion->getConditionEvents()) {
                $this->endParticipation($participation);
            } elseif ($finished) {
                $this->endParticipation($participation, Participation::STATUS_FAILED);
            }
        } else {
            $this->logger->error("Invalid promotion condition: {$condition}.");
        }
    }

    /**
     * Set a participation as complete and email marketing to tell them to give the reward.
     * @param Participation $participation is the participation to complete.
     */
    public function endParticipation($participation, $status = Participation::STATUS_COMPLETED)
    {
        $participation->setStatus($status);
        $this->dm->flush();
        if ($status == Participation::STATUS_COMPLETED) {
            $this->mailerService->sendTemplate(
                "Promotion Reward Earned",
                "marketing@so-sure.com",
                "AppBundle:Email:promotion/rewardEarned.html.twig",
                ["participation" => $participation]
            );
        } elseif ($status == Participation::STATUS_INVALID) {
            $this->mailerService->sendTemplate(
                "Promotion Reward Cannot Be Awarded",
                "marketing@so-sure.com",
                "AppBundle:Email:promotion/rewardInvalid.html.twig",
                [
                    "participation" => $participation,
                    "reason" => "Promotion reward is a tastecard, but user already has a tastecard."
                ]
            );
        }
    }
}
