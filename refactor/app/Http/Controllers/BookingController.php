<?php

namespace DTApi\Http\Controllers;

use DTApi\Interfaces\Repository\BookingRepositoryInterface;
use DTApi\Interfaces\Repository\JobRepositoryInterface;
use DTApi\Interfaces\Service\JobServiceInterface;
use DTApi\Interfaces\Service\NotificationServiceInterface;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $bookingRepository;
    protected $jobRepository;
    protected $notificationService;
    protected $jobService;

    /**
     * BookingController constructor.
     * @param BookingRepositoryInterface $bookingRepository
     * @param JobRepositoryInterface $jobRepository
     * @param NotificationServiceInterface $notificationService
     * @param JobServiceInterface $jobService
     */
    public function __construct(BookingRepositoryInterface $bookingRepository, JobRepositoryInterface $jobRepository, NotificationServiceInterface $notificationService, JobServiceInterface $jobService)
    {
        $this->bookingRepository = $bookingRepository;
        $this->jobRepository = $jobRepository;
        $this->notificationService = $notificationService;
        $this->jobService = $jobService;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $authenticatedUser = $request->__authenticatedUser;
        if($user_id = $request->get('user_id')) {

            $response = $this->bookingRepository->getUsersJobs($user_id);

        }
        elseif($authenticatedUser->user_type == env('ADMIN_ROLE_ID') || $authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID'))
        {
            $response = $this->jobRepository->getAll($request);
        }

        return response($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->bookingRepository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();

        $response = $this->bookingRepository->store($request->__authenticatedUser, $data);

        return response($response);

    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
        $response = $this->jobRepository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        // $adminSenderEmail = config('app.adminemail');
        $data = $request->all();

        $response = $this->jobRepository->storeJobEmail($data);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        if($user_id = $request->get('user_id')) {

            $response = $this->bookingRepository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->jobRepository->acceptJob($data, $user);

        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

        $response = $this->jobRepository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->jobRepository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        $response = $this->jobRepository->endJob($data);

        return response($response);

    }

    public function customerNotCall(Request $request)
    {
        $data = $request->all();

        $response = $this->bookingRepository->customerNotCall($data);

        return response($response);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->jobRepository->getPotentialJobs($user);

        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        $data = $request->all();

        $response = $this->jobService->distanceFeed($data);

        if (isset($response['error'])) {
            return response($response['error'], 400);
        }

        return response($response['success'], 200);

    }

    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->bookingRepository->reopen($data);

        return response($response);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);
        $job_data = $this->jobRepository->jobToData($job);
        $this->notificationService->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);
        $job_data = $this->jobRepository->jobToData($job);

        try {
            $this->notificationService->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}
