<?php

namespace Dtc\QueueBundle\Model;

use Dtc\QueueBundle\EventDispatcher\Event;
use Dtc\QueueBundle\EventDispatcher\EventDispatcher;
use Dtc\QueueBundle\Exception\DuplicateWorkerException;
use Psr\Log\LoggerInterface;

class WorkerManager
{
    protected $workers;
    protected $jobManager;

    /** @var LoggerInterface */
    protected $logger;
    protected $eventDispatcher;
    protected $logFunc;

    public function __construct(JobManagerInterface $jobManager, EventDispatcher $eventDispatcher)
    {
        $this->workers = array();
        $this->jobManager = $jobManager;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function addWorker(Worker $worker)
    {
        if ($this->logger) {
            $this->logger->debug(__METHOD__." - Added worker: {$worker->getName()}");
        }

        if (isset($this->workers[$worker->getName()])) {
            throw new DuplicateWorkerException("{$worker->getName()} already exists in worker manager");
        }

        $this->workers[$worker->getName()] = $worker;
    }

    public function getWorker($name)
    {
        if (isset($this->workers[$name])) {
            return $this->workers[$name];
        }

        return null;
    }

    public function getWorkers()
    {
        return $this->workers;
    }

    public function setLoggingFunc(callable $callable)
    {
        $this->logFunc = $callable;
    }

    public function log($level, $msg, array $context = [])
    {
        if ($this->logFunc) {
            call_user_func_array($this->logFunc, [$level, $msg, $context]);

            return;
        }

        if ($this->logger) {
            $this->logger->$level($msg, $context);

            return;
        }
    }

    /**
     * @param null $workerName
     * @param null $methodName
     * @param bool $prioritize
     *
     * @return null|Job
     */
    public function run($workerName = null, $methodName = null, $prioritize = true, $runId = null)
    {
        $job = $this->jobManager->getJob($workerName, $methodName, $prioritize, $runId);
        if (!$job) {
            return null; // no job to run
        }

        return $this->runJob($job);
    }

    /**
     * @param array $payload
     * @param Job   $job
     */
    protected function handleException(array $payload, Job $job)
    {
        $exception = $payload[0];
        $exceptionMessage = get_class($exception)."\n".$exception->getCode().' - '.$exception->getMessage()."\n".$exception->getTraceAsString();
        $this->log('debug', "Failed: {$job->getClassName()}->{$job->getMethod()}");
        $job->setStatus(BaseJob::STATUS_ERROR);
        if ($job instanceof RetryableJob) {
            $job->setErrorCount($job->getErrorCount() + 1);
            if (null !== ($maxError = $job->getMaxError()) && $job->getErrorCount() >= $maxError) {
                $job->setStatus(RetryableJob::STATUS_MAX_ERROR);
            }
        }
        $job->setMessage($exceptionMessage);
    }

    public function runJob(Job $job)
    {
        $event = new Event($job);
        $this->eventDispatcher->dispatch(Event::PRE_JOB, $event);

        $start = microtime(true);
        try {
            $worker = $this->getWorker($job->getWorkerName());
            $this->log('debug', "Start: {$job->getClassName()}->{$job->getMethod()}", $job->getArgs());
            $job->setStartedAt(new \DateTime());
            call_user_func_array(array($worker, $job->getMethod()), $job->getArgs());

            // Job finshed successfuly... do we remove the job from database?
            $job->setStatus(BaseJob::STATUS_SUCCESS);
            $job->setMessage(null);
        } catch (\Throwable $exception) {
            $this->handleException([$exception], $job);
        } catch (\Exception $exception) {
            $this->handleException([$exception], $job);
        }

        // save Job history
        $elapsed = microtime(true) - $start;
        $job->setFinishedAt(new \DateTime());
        $job->setElapsed($elapsed);

        $this->log('debug', "Finished: {$job->getClassName()}->{$job->getMethod()} in {$elapsed} seconds");
        $this->log('debug', "Save job history: {$job->getId()}");

        $this->jobManager->saveHistory($job);
        $this->eventDispatcher->dispatch(Event::POST_JOB, $event);

        return $job;
    }
}
