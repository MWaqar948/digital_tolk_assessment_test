<?php
namespace DTApi\Service;

use DTApi\Interfaces\Repository\JobRepositoryInterface;
use DTApi\Interfaces\Service\JobServiceInterface;

/**
 * Class JobService
 * @package DTApi\Interfaces\Service
 */
class JobService implements JobServiceInterface
{

    protected $jobRepository;

    /**
     * @param JobRepositoryInterface $jobRepository
     */
    function __construct(JobRepositoryInterface $jobRepository)
    {
        $this->jobRepository = $jobRepository;
    }

    /** 
     * Update distance and time
     * @param array $data
    */
    public function distanceFeed(array $data) {
        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'] ?? null;
        $session = $data['session_time'] ?? '';
        $admincomment = $data['admincomment'] ?? '';

        $flagged = ($data['flagged'] === 'true') ? 'yes' : 'no';
        $manually_handled = ($data['manually_handled'] === 'true') ? 'yes' : 'no';
        $by_admin = ($data['by_admin'] === 'true') ? 'yes' : 'no';

        // Validate flagged comments
        if ($flagged === 'yes' && empty($admincomment)) {
            return ['error' => 'Please, add comment'];
        }

        // Update distance and time
        if ($time || $distance) {
            $this->jobRepository->updateDistance($jobid, $distance, $time);
        }

        // Update job-related fields
        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            $this->jobRepository->updateJobDetails($jobid, [
                'admin_comments' => $admincomment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manually_handled,
                'by_admin' => $by_admin,
            ]);
        }

        return ['success' => 'Record updated!'];

    }
}
