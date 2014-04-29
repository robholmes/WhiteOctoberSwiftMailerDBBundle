<?php

namespace WhiteOctober\SwiftMailerDBBundle\Spool;

use Doctrine\ODM\MongoDB\DocumentManager;
use WhiteOctober\SwiftMailerDBBundle\EmailInterface;

class MongoDBSpool extends \Swift_ConfigurableSpool
{
    /**
     * @var DocumentManager
     */
    protected $dm;

    /**
     * @var string
     */
    protected $modelClass;

    public function __construct(DocumentManager $dm, $modelClass)
    {
        $this->dm = $dm;

        $obj = new $modelClass;
        if (!$obj instanceof EmailInterface) {
            throw new \InvalidArgumentException("The document class '{$modelClass}'' does not extend from EmailInterface");
        }

        $this->modelClass = $modelClass;
    }

    /**
     * Starts this Spool mechanism.
     */
    public function start()
    {
    }

    /**
     * Stops this Spool mechanism.
     */
    public function stop()
    {
    }

    /**
     * Tests if this Spool mechanism has started.
     *
     * @return boolean
     */
    public function isStarted()
    {
        return true;
    }

    /**
     * Queues a message.
     *
     * @param \Swift_Mime_Message $message The message to store
     * @return boolean Whether the operation has succeeded
     * @throws \Swift_IoException if the persist fails
     */
    public function queueMessage(\Swift_Mime_Message $message)
    {
        $mailObject = new $this->modelClass;
        $mailObject->setMessage(serialize($message));
        $mailObject->setStatus(EmailInterface::STATUS_READY);
        try {
            $this->dm->persist($mailObject);
            $this->dm->flush();
        } catch (\Exception $e) {
            throw new \Swift_IoException("Unable to persist object for enqueuing message");
        }

        return true;
    }

    /**
     * Sends messages using the given transport instance.
     *
     * @param \Swift_Transport $transport         A transport instance
     * @param string[]        &$failedRecipients An array of failures by-reference
     *
     * @return int The number of sent emails
     */
    public function flushQueue(\Swift_Transport $transport, &$failedRecipients = null)
    {
        if (!$transport->isStarted())
        {
            $transport->start();
        }

        $repoClass = $this->dm->getRepository($this->modelClass);
        $limit = $this->getMessageLimit();
        $limit = $limit > 0 ? $limit : null;
        $emails = $repoClass->findBy(array("status" => EmailInterface::STATUS_READY), null, $limit);
        if (!count($emails)) {
            return 0;
        }

        $failedRecipients = (array) $failedRecipients;
        $count = 0;
        $time = time();
        foreach ($emails as $email) {
            $email->setStatus(EmailInterface::STATUS_PROCESSING);
            $this->dm->flush();

            $message = unserialize($email->getMessage());
            $count += $transport->send($message, $failedRecipients);
            $this->dm->remove($email);
            $this->dm->flush();

            if ($this->getTimeLimit() && (time() - $time) >= $this->getTimeLimit()) {
                break;
            }
        }

        return $count;
    }
}
