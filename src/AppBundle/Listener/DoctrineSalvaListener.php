<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Event\SalvaPolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineSalvaListener
{
    /** @var EventDispatcher */
    protected $dispatcher;

    /** @var string */
    protected $environment;

    public function __construct($dispatcher, $environment)
    {
        $this->dispatcher = $dispatcher;
        $this->environment = $environment;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof PhonePolicy) {
            if (!$document->isValidPolicy()) {
                // The change in state from pending to active corresponds with the change in state
                // from invalid to valid policy
                if ($eventArgs->hasChangedField('status') &&
                    $eventArgs->getOldValue('status') == PhonePolicy::STATUS_PENDING &&
                    $eventArgs->getNewValue('status') == PhonePolicy::STATUS_ACTIVE) {
                    return $this->triggerEvent($document, SalvaPolicyEvent::EVENT_CREATED);
                }

                return;
            }

            // Status changes need to be done prior to any updates and make those update irrelivent anyway
            if ($eventArgs->hasChangedField('status')) {
                // Cancellation is more imporant then anything else
                if ($eventArgs->getNewValue('status') == PhonePolicy::STATUS_CANCELLED) {
                    return $this->triggerEvent($document, SalvaPolicyEvent::EVENT_CANCELLED);
                }
            }

            $fields = [
                'phone.make',
                'phone.model',
                'phone.memory',
                'phone.imei',
                'phone.initialPrice',
                'premium.gwp',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field)) {
                    return $this->triggerEvent($document, SalvaPolicyEvent::EVENT_UPDATED);
                }
            }
        }

        if ($document instanceof User) {
            if (!$document->hasValidPolicy()) {
                return;
            }
            $fields = [
                'firstname',
                'lastname',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field)) {
                    foreach ($document->getPolicies() as $policy) {
                        if ($policy instanceof PhonePolicy && $policy->isValidPolicy()) {
                            return $this->triggerEvent($policy, SalvaPolicyEvent::EVENT_UPDATED);
                        }
                    }
                }
            }
        }

    }

    private function triggerEvent(PhonePolicy $phonePolicy, $eventType)
    {
        $event = new SalvaPolicyEvent($phonePolicy);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
