<?php

namespace DTApi\Interfaces\Repository;

use Illuminate\Http\Request;
use DTApi\Models\User;

interface JobRepositoryInterface
{
    public function getJobsForUser($user, $userType);

    public function getCustomerJobHistory($user);

    public function getTranslatorJobHistory($user, $page);

    public function createJob($user, $data);

    public function storeJobEmail($data);

    public function endJob($post_data);

    public function acceptJob($data, $user);

    public function acceptJobWithId($job_id, $cuser);

    public function cancelJobAjax($data, $user);

    public function getPotentialJobIdsWithUserId($user_id);

    public function jobToData($job);

    public function getAll(Request $request, $limit=null);

    public function alerts(Request $request);
    
    public function reopenJob(array $request);

    public function updateJob(int $id, array $data, User $cuser): array;

    public function getPotentialJobs($cuser);

    public function updateDistance(?int $jobId, string $distance, string $time): void;

    public function updateJobDetails(?int $jobId, array $data): void;

   


}
