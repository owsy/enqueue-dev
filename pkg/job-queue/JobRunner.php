<?php

namespace Enqueue\JobQueue;

class JobRunner
{
    /**
     * @var JobProcessor
     */
    private $jobProcessor;

    /**
     * @var Job
     */
    private $rootJob;

    /**
     * @param JobProcessor $jobProcessor
     * @param Job          $rootJob
     */
    public function __construct(JobProcessor $jobProcessor, Job $rootJob = null)
    {
        $this->jobProcessor = $jobProcessor;
        $this->rootJob = $rootJob;
    }

    /**
     * @param string   $ownerId
     * @param string   $name
     * @param \Closure $runCallback
     *
     * @return mixed
     */
    public function runUnique($ownerId, $name, \Closure $runCallback)
    {
        $rootJob = $this->jobProcessor->findOrCreateRootJob($ownerId, $name, true);
        if (!$rootJob) {
            return;
        }

        $childJob = $this->jobProcessor->findOrCreateChildJob($name, $rootJob);

        if (!$childJob->getStartedAt()) {
            $this->jobProcessor->startChildJob($childJob);
        }

        $jobRunner = new self($this->jobProcessor, $rootJob);

        $result = call_user_func($runCallback, $jobRunner, $childJob);

        if (!$childJob->getStoppedAt()) {
            $result
                ? $this->jobProcessor->successChildJob($childJob)
                : $this->jobProcessor->failChildJob($childJob);
        }

        return $result;
    }

    /**
     * @param string   $name
     * @param \Closure $startCallback
     *
     * @return mixed
     */
    public function createDelayed($name, \Closure $startCallback)
    {
        $childJob = $this->jobProcessor->findOrCreateChildJob($name, $this->rootJob);

        $jobRunner = new self($this->jobProcessor, $this->rootJob);

        return call_user_func($startCallback, $jobRunner, $childJob);
    }

    /**
     * @param string   $jobId
     * @param \Closure $runCallback
     *
     * @return mixed
     */
    public function runDelayed($jobId, \Closure $runCallback)
    {
        $job = $this->jobProcessor->findJobById($jobId);
        if (!$job) {
            throw new \LogicException(sprintf('Job was not found. id: "%s"', $jobId));
        }

        if ($job->getRootJob()->isInterrupted()) {
            if (!$job->getStoppedAt()) {
                $this->jobProcessor->cancelChildJob($job);
            }

            return;
        }

        if (!$job->getStartedAt()) {
            $this->jobProcessor->startChildJob($job);
        }

        $jobRunner = new self($this->jobProcessor, $job->getRootJob());
        $result = call_user_func($runCallback, $jobRunner, $job);

        if (!$job->getStoppedAt()) {
            $result
                ? $this->jobProcessor->successChildJob($job)
                : $this->jobProcessor->failChildJob($job);
        }

        return $result;
    }
}
