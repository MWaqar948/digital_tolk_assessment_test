<?php

namespace DTApi\Interfaces\Repository;

use Illuminate\Http\Request;
use DTApi\Models\Job;
interface BookingRepositoryInterface
{
    public function getUsersJobs($user_id);

    public function getUsersJobsHistory($user_id, Request $request);
    
    public function store($user, $data);
    
    public function getPotentialTranslators(Job $job);

    public function changeTranslator($currentTranslator, $data, $job);
    
    public function changeDue($oldDue, $newDue);
    
    public function customerNotCall($post_data);    
    
    public function userLoginFailed();
    
    public function bookingExpireNoAccepted(Request $request);
    
    public function ignoreExpiring($id);
    public function ignoreExpired($id);
    public function ignoreThrottle($id);
    
    public function reopen($request);

    public function convertToHoursMins($time, $format = '%02dh %02dmin');

    public function getUserTagsStringFromArray($users);

    public function changeStatus($job, $data, $changedTranslator);
}
