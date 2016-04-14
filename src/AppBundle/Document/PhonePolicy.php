<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class PhonePolicy extends Policy
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /** @MongoDB\ReferenceOne(targetDocument="Phone") */
    protected $phone;

    /** @MongoDB\Field(type="string", name="phone_data") */
    protected $phoneData;

    /** @MongoDB\Field(type="string", nullable=false) */
    protected $imei;

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    public function getPhoneData()
    {
        return $this->phoneData;
    }

    public function setPhoneData($phoneData)
    {
        $this->phoneData = $phoneData;
    }

    public function toApiArray()
    {
        $connections = [];
        foreach ($this->getConnections() as $connection) {
            $connections[] = $connection->toApiArray();
        }
        return [
            'id' => $this->getId(),
            'status' => $this->getStatus(),
            'type' => 'phone',
            'start_date' => $this->getStart() ? $this->getStart(\DateTime::ISO8601)->format('') : null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ISO8601) : null,
            'policy_number' => $this->getPolicyNumber(),
            'phone_policy' => [
                'imei' => $this->getImei(),
                'phone' => $this->getPhone() ? $this->getPhone()->toApiArray() : null,
            ],
            'pot' => [
                'connections' => count($this->getConnections()),
                'max_connections' => $this->getPhone()->getMaxConnections(),
                'value' => $this->getPotValue(),
                'max_value' => $this->getPhone()->getMaxPot(),
            ],
            'connections' => $connections,
        ];
    }
}
