<?php

namespace DTApi\Repository;


use Event;
use DTApi\Repository\BaseRepository;
use DTApi\Models\Job;
use DTApi\Models\Translator;
use DTApi\Models\Language;
use DTApi\Models\User;
use DTApi\Models\Distance;
use DTApi\Mailers\MailerInterface;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Events\SessionEnded;
use DTApi\Mailers\AppMailer;
use Carbon\Carbon;
use DTApi\Interfaces\Repository\JobRepositoryInterface;
use DTApi\Helpers\TeHelper;
use DTApi\Interfaces\Repository\BookingRepositoryInterface;
use DTApi\Interfaces\Service\NotificationServiceInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

/**
 * Class JobRepository
 * @package DTApi\Repository
 */
class JobRepository extends BaseRepository implements JobRepositoryInterface {

    protected $mailer;
    protected $logger;
    protected $notificationService;
    protected $bookingRepository;

    /**
     * @param Job $model
     * @param MailerInterface $mailer
     * @param NotificationServiceInterface $notificationService
     * @param BookingRepository $bookingRepository
     */
    function __construct(Job $model, MailerInterface $mailer, NotificationServiceInterface $notificationService, BookingRepositoryInterface $bookingRepository)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        
        $this->notificationService = $notificationService;
        $this->bookingRepository = $bookingRepository;
    }


    /**
     * Get jobs for a user based on user type
     *
     * @param $user
     * @param $usertype
     * @return array
     */
    public function getJobsForUser($user, $userType)
    {
        /**
         * If user is a customer, get all the jobs that
         * are in status 'pending', 'assigned', 'started'
         * and sort them by due date ascending.
         */
        if ($userType === 'customer') {
            return $user->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
        } /**
         * If user is a translator, get all the jobs that
         * are in status 'new' and sort them by due date ascending.
         */
        elseif ($userType === 'translator') {
            return Job::getTranslatorJobs($user->id, 'new')->pluck('jobs');
        }
    }

    public function getCustomerJobHistory($user)
    {
        return $user->jobs()
            ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
            ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
            ->orderBy('due', 'desc')
            ->paginate(15);
    }

    public function getTranslatorJobHistory($user, $page)
    {
        return Job::getTranslatorJobsHistoric($user->id, 'historic', $page);
    }

    public function createJob($user, $data)
    {
        return $user->jobs()->create($data);
    }

    /** 
     * @param $data
     * @return mixed
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     */
    public function storeJobEmail($data)
    {
        $job = Job::findOrFail(@$data['user_email_job_id']);
        $user = $job->user()->first();
        $job->update([
            'user_email'   => $data['user_email'] ?? null,
            'reference'    => $data['reference'] ?? '',
            'address'      => $data['address'] ?: ($user->userMeta->address ?? null),
            'instructions' => $data['instructions'] ?: ($user->userMeta->instructions ?? null),
            'town'         => $data['town'] ?: ($user->userMeta->city ?? null),
        ]);

        // Determine email and recipient name
        $email = $job->user_email ?: $user->email;
        $name = $user->name;

        // Email sending
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job,
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        // Push job data to an event
        $jobData = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $jobData, '*'));

        return [
            'type'   => $data['user_type'] ?? null,
            'job'    => $job,
            'status' => 'success',
        ];
    }

    /**
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;

    }
    
    /**
     * @param array $post_data
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     */
    public function endJob($post_data = [])
    {
        $completedDate = now();
        $jobId = $post_data['job_id'];
        $job = Job::with('translatorJobRel')->findOrFail($jobId);

        if($job->status != 'started')
            return ['status' => 'success'];
        // Calculate session time
        $dueDate = $job->due;
        $interval = $this->calculateSessionTime($dueDate, $completedDate);

        // Update job details
        $job->update([
            'end_at'      => $completedDate,
            'status'      => 'completed',
            'session_time'=> $interval,
        ]);

        // Notify the user about session completion
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $sessionTime = $this->formatSessionTime($job->session_time);

        $this->sendJobCompletionEmail($email, $name, $job, $sessionTime, 'faktura');

        // Update translator relation
        $translator = $job->translatorJobRel
            ->whereNull('completed_at')
            ->whereNull('cancel_at')
            ->first();

        if ($translator) {
            $translator->update([
                'completed_at' => $completedDate,
                'completed_by' => $post_data['userid'],
            ]);

            // Notify the translator
            $translatorUser = $translator->user()->first();
            $this->sendJobCompletionEmail(
                $translatorUser->email,
                $translatorUser->name,
                $job,
                $sessionTime,
                'lön'
            );

            // Fire session-ended event
            Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) 
                ? $translator->user_id 
                : $job->user_id));
        }
    }

    private function calculateSessionTime($dueDate, $completedDate)
    {
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        return sprintf('%02d:%02d:%02d', $diff->h, $diff->i, $diff->s);
    }

    private function formatSessionTime($sessionTime)
    {
        [$hours, $minutes] = explode(':', $sessionTime);
        return "{$hours} tim {$minutes} min";
    }

    private function sendJobCompletionEmail($email, $name, $job, $sessionTime, $forText)
    {
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $job->user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => $forText,
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $job = Job::findOrFail($data['job_id']);

        if ($this->canAcceptJob($job, $user)) {
            $job->update(['status' => 'assigned']);

            $this->sendJobAcceptedEmail($job);

            return [
                'list' => json_encode([
                    'jobs' => $this->getPotentialJobs($user),
                    'job'  => $job,
                ]),
                'status' => 'success',
            ];
        }

        return [
            'status' => 'fail',
            'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.',
        ];
    }

    private function canAcceptJob($job, $user)
    {
        return $job->status === 'pending' &&
            !Job::isTranslatorAlreadyBooked($job->id, $user->id, $job->due) &&
            Job::insertTranslatorJobRel($user->id, $job->id);
    }


    private function sendJobAcceptedEmail($job)
    {
        $jobUser = $job->user()->first();
        $email = $job->user_email ?? $jobUser->email;
        $name = $jobUser->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $data = [
            'user' => $jobUser,
            'job'  => $job,
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }

     /**
     * Function to get the potential jobs for paid,rws,unpaid translators.
     *
     * @param User $cuser
     * @return array
     */
    public function getPotentialJobs($cuser)
    {
        $cuserMeta = $cuser->userMeta;
        $jobType = match ($cuserMeta->translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            'volunteer' => 'unpaid',
            default => 'unpaid',
        };

        $userLanguages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id');
        $gender = $cuserMeta->gender;
        $translatorLevel = $cuserMeta->translator_level;

        // Retrieve job IDs based on user attributes
        $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $userLanguages, $gender, $translatorLevel);

        // Filter jobs based on additional criteria
        foreach ($jobIds as $key => $job) {
            $jobUserId = $job->user_id;

            // Specific job and acceptance checks
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);

            // Check town-based restrictions for physical jobs
            $checkTown = Job::checkTowns($jobUserId, $cuser->id);

            if (
                $job->specific_job === 'SpecificJob' &&
                $job->check_particular_job === 'userCanNotAcceptJob'
            ) {
                unset($jobIds[$key]);
            }

            if (
                ($job->customer_phone_type === 'no' || $job->customer_phone_type === '') &&
                $job->customer_physical_type === 'yes' &&
                !$checkTown
            ) {
                unset($jobIds[$key]);
            }
        }

        return $jobIds;
    }


    /**
     * Accept a job by its ID for the given user.
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     * 
     * @param int $job_id
     * @param User $cuser
     * @return array
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        // $adminEmail = config('app.admin_email');
        // $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = [];

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status === 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();

                $user = $job->user()->first();
                $mailer = new AppMailer();

                $email = $job->user_email ?: $user->email;
                $name = $user->name;

                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job,
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                // Send push notification
                $data = ['notification_type' => 'job_accepted'];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.',
                ];

                if ($this->notificationService->isNeedToSendPush($user->id)) {
                    $usersArray = [$user];
                    $this->notificationService->sendPushNotificationToSpecificUsers($usersArray, $job_id, $data, $msg_text, $this->notificationService->isNeedToDelayPush($user->id));
                }

                // Response for successful booking
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Job already accepted by another translator
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning.';
            }
        } else {
            // User has conflicting booking
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning.';
        }

        return $response;
    }


    /**
     * Cancel a job via Ajax based on user role and timing.
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     * 
     * @param array $data
     * @param User $user
     * @return array
     */
    public function cancelJobAjax($data, $user)
    {
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $response = [];

        if ($user->is('customer')) {
            $response = $this->handleCustomerCancellation($job, $user);
        } else {
            $response = $this->handleTranslatorCancellation($job, $user);
        }

        return $response;
    }

    /**
     * Handle job cancellation by a customer.
     */
    private function handleCustomerCancellation($job, $user)
    {
        $response = [];
        $job->withdraw_at = Carbon::now();
        $hoursBeforeDue = $job->withdraw_at->diffInHours($job->due);

        if ($hoursBeforeDue >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }

        $job->save();
        Event::fire(new JobWasCanceled($job));

        $response['status'] = 'success';
        $response['jobstatus'] = 'success';

        // Notify the translator if assigned
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($translator) {
            $this->notifyTranslatorCancellation($job, $translator);
        }

        return $response;
    }

    /**
     * Handle job cancellation by a translator.
     */
    private function handleTranslatorCancellation($job, $user)
    {
        $response = [];
        $hoursUntilDue = $job->due->diffInHours(Carbon::now());

        if ($hoursUntilDue > 24) {
            $this->notifyCustomerOfTranslatorCancellation($job);
            $this->reassignJob($job);

            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
        }

        return $response;
    }

    /**
     * Notify the translator about the cancellation by the customer.
     */
    private function notifyTranslatorCancellation($job, $translator)
    {
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText = [
            "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
        ];

        if ($this->notificationService->isNeedToSendPush($translator->id)) {
            $this->notificationService->sendPushNotificationToSpecificUsers(
                [$translator],
                $job->id,
                ['notification_type' => 'job_cancelled'],
                $msgText,
                $this->notificationService->isNeedToDelayPush($translator->id)
            );
        }
    }

    /**
     * Notify the customer about the cancellation by the translator.
     */
    private function notifyCustomerOfTranslatorCancellation($job)
    {
        $customer = $job->user()->first();
        if (!$customer) {
            return;
        }

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msgText = [
            "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
        ];

        if ($this->notificationService->isNeedToSendPush($customer->id)) {
            $this->notificationService->sendPushNotificationToSpecificUsers(
                [$customer],
                $job->id,
                ['notification_type' => 'job_cancelled'],
                $msgText,
                $this->notificationService->isNeedToDelayPush($customer->id)
            );
        }
    }

    /**
     * Reassign the job after translator cancellation.
     */
    private function reassignJob($job)
    {
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($translator) {
            Job::deleteTranslatorJobRel($translator->id, $job->id);
        }

        $job->status = 'pending';
        $job->created_at = Carbon::now();
        $job->will_expire_at = TeHelper::willExpireAt($job->due, Carbon::now());
        $job->save();

        $data = $this->jobToData($job);
        $this->notificationService->sendNotificationTranslator($job, $data, $translator->id ?? null);
    }

    /**
     * Function to get all potential jobs of a user with his ID
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     * @param int $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        // Fetch user meta to determine translator type
        $userMeta = $this->getUserMeta($user_id);
        $translatorType = $userMeta->translator_type;

        // Determine the job type based on translator type
        $job_type = $this->determineJobType($translatorType);

        // Get the user's languages, gender, and translator level
        $languages = $this->getUserLanguages($user_id);
        $userLanguage = collect($languages)->pluck('lang_id');
        $gender = $userMeta->gender;
        $translator_level = $userMeta->translator_level;

        // Fetch job IDs based on user criteria
        $job_ids = $this->getJobs($user_id, $job_type, 'pending', $userLanguage, $gender, $translator_level);

        // Filter jobs based on location (town) and other criteria
        $job_ids = $this->filterJobsBasedOnTownAndCriteria($job_ids, $user_id);

        return $this->convertJobIdsInObjs($job_ids);
    }

    /**
     * Determine the job type based on translator type
     * @param string $translatorType
     * @return string
     */
    private function determineJobType($translatorType)
    {
        switch ($translatorType) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
                return 'unpaid';
            default:
                return 'unpaid'; // Default to 'unpaid' if the type is unknown
        }
    }

    /**
     * Fetch user meta data
     * @param int $user_id
     * @return UserMeta
     */
    private function getUserMeta($user_id)
    {
        return UserMeta::where('user_id', $user_id)->first();
    }

    /**
     * Get user's languages
     * @param int $user_id
     * @return Collection
     */
    private function getUserLanguages($user_id)
    {
        return UserLanguages::where('user_id', '=', $user_id)->get();
    }

    /**
     * Filter jobs based on location (town) and other job-specific criteria
     * @param array $job_ids
     * @param int $user_id
     * @return array
     */
    private function filterJobsBasedOnTownAndCriteria($job_ids, $user_id)
    {
        foreach ($job_ids as $k => $v) {
            $job = Job::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);

            // Remove jobs based on location or other specific conditions
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        return $job_ids;
    }

    /**
     * Convert job IDs into job objects
     * @param array $job_ids
     * @return array
     */
    private function convertJobIdsInObjs($job_ids)
    {
        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    /**
     * Fetch jobs based on user and job-related criteria
     * @param int $user_id
     * @param string $job_type
     * @param string $status
     * @param array $userLanguage
     * @param string $gender
     * @param string $translator_level
     * @return array
     */
    private function getJobs($user_id, $job_type, $status, $userLanguage, $gender, $translator_level)
    {
        return Job::getJobs($user_id, $job_type, $status, $userLanguage, $gender, $translator_level);
    }

    /**
     * Fetch all jobs based on request filters
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     * 
     * @param Request $request
     * @param string|null $limit
     * @return mixed
     */
    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $authenticatedUser = $request->__authenticatedUser;

        $allJobs = Job::query();

        // Super Admin specific filters
        if ($authenticatedUser && $authenticatedUser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $response = $this->applySuperAdminFilters($allJobs, $requestData);
            if(isset($response['count'])) return $response;

        } else {
            // Regular User Filters
            $this->applyUserFilters($allJobs, $requestData, $authenticatedUser->consumer_type);
        }

        $allJobs->with(['user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance']);
        $allJobs->orderBy('created_at', 'desc');

        return $limit === 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    /**
     * Apply filters specific to super admin
     * @param Builder $query
     * @param array $requestData
     */
    private function applySuperAdminFilters($query, &$requestData)
    {
        $response = $this->applyFeedbackFilter($query, $requestData);
        if(isset($response['count'])) return $response;

        if (!empty($requestData['id'])) {
            is_array($requestData['id'])
                ? $query->whereIn('id', $requestData['id'])
                : $query->where('id', $requestData['id']);
            $requestData = Arr::only($requestData, ['id']);
        }

        $this->applyLanguageFilter($query, $requestData);
        $this->applyStatusFilter($query, $requestData);

        if (!empty($requestData['expired_at'])) {
            $query->where('expired_at', '>=', $requestData['expired_at']);
        }
        if (!empty($requestData['will_expire_at'])) {
            $query->where('will_expire_at', '>=', $requestData['will_expire_at']);
        }

        $this->applyCustomerEmailFilter($query, $requestData);
        $this->applyTranslatorEmailFilter($query, $requestData);

        $this->applyTimeFilters($query, $requestData);
        $this->applyAdditionalFilters($query, $requestData);

        return $this->applyAdminSpecificFilters($query, $requestData);
    }

    /** 
     * Apply filters specific to regular users
     * @param Builder $query
     * @param array $requestData
     * @param string $consumerType
     */
    private function applyUserFilters($query, &$requestData, $consumerType)
    {
        if (!empty($requestData['id'])) {
            $query->where('id', $requestData['id']);
            $requestData = Arr::only($requestData, ['id']);
        }

        $query->where('job_type', '=', $consumerType === 'RWS' ? 'rws' : 'unpaid');

        $response = $this->applyFeedbackFilter($query, $requestData);
        if(isset($response['count'])) return $response;

        $this->applyLanguageFilter($query, $requestData);
        $this->applyStatusFilter($query, $requestData);   
        
        $this->applyJobTypeFilter($query, $requestData);

        $this->applyCustomerEmailFilter($query, $requestData);

        $this->applyTimeFilters($query, $requestData);

    }

    /**
     * Apply language filter
     * @param Builder $query
     * @param array $requestData
     */
    private function applyLanguageFilter($query, $requestData, $fieldPrefix = '')
    {
        if (!empty($requestData['lang'])) {
            $field = $fieldPrefix !== '' ? "$fieldPrefix.from_language_id" : 'from_language_id';
            $query->whereIn($field, $requestData['lang']);
        }
    }

    /**
     * Apply status filter
     * @param Builder $query
     * @param array $requestData
     */
    private function applyStatusFilter($query, $requestData, $fieldPrefix = '')
    {
        if (!empty($requestData['status'])) {
            $field = $fieldPrefix !== '' ? "$fieldPrefix.status" : 'status';
            $query->whereIn($field, $requestData['status']);
        }
    }

    /**
     * Apply customer email filter
     * @param Builder $query
     * @param array $requestData
     */
    private function applyCustomerEmailFilter($query, $requestData, $fieldPrefix = '')
    {
        if (!empty($requestData['customer_email'])) {
            $userIds = $this->getUserIdsByEmail($requestData['customer_email'],  $fieldPrefix !== '');
            if (!empty($userIds)) {
                $field = $fieldPrefix !== '' ? "$fieldPrefix.user_id" : 'user_id';
                $query->whereIn($field, $userIds);
            }
        }
    }

    /**
     * Apply translator email filter
     * @param Builder $query
     * @param array $requestData
     */
    private function applyTranslatorEmailFilter($query, $requestData, $fieldPrefix = '')
    {
        if (!empty($requestData['translator_email'])) {
            $jobIds = $this->getJobIdsByTranslatorEmail($requestData['translator_email'],  $fieldPrefix !== '');
            if (!empty($jobIds)) {
                $field = $fieldPrefix !== '' ? "$fieldPrefix.id" : 'id';
                $query->whereIn($field, $jobIds);
            }
        }
    }

    /**
     * Apply feedback filter
     * @param Builder $query
     * @param array $requestData
     */
    private function applyFeedbackFilter($query, $requestData)
    {
        if (!empty($requestData['feedback']) && $requestData['feedback'] != 'false') {
            $query->where('ignore_feedback', '0');
            $query->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (!empty($requestData['count']) && $requestData['count'] != 'false') {
                return ['count' => $query->count()];
            }
        }
    }

    /**
     * Apply job type filter
     * @param Builder $query
     * @param array $requestData
     */
    private function applyJobTypeFilter($query, $requestData, $fieldPrefix = '')
    {
        if (!empty($requestData['job_type'])) {
            $field = $fieldPrefix !== '' ? "$fieldPrefix.job_type" : 'job_type';
            $query->whereIn($field, $requestData['job_type']);
        }
    }



    /**
     * Apply admin-specific filters
     * @param Builder $query
     * @param array $requestData
     */
    private function applyAdminSpecificFilters($query, $requestData)
    {
        if (!empty($requestData['distance']) && $requestData['distance'] === 'empty') {
            $query->whereDoesntHave('distance');
        }

        if (!empty($requestData['salary']) && $requestData['salary'] === 'yes') {
            $query->whereDoesntHave('user.salaries');
        }

        if (!empty($requestData['count']) && $requestData['count'] === 'true') {
            return ['count' => $query->count()];
        }

        if (!empty($requestData['consumer_type'])) {
            $query->whereHas('user.userMeta', function ($q) use ($requestData) {
                $q->where('consumer_type', $requestData['consumer_type']);
            });
        }

        if (!empty($requestData['booking_type'])) {
            if ($requestData['booking_type'] === 'physical') {
                $query->where('customer_physical_type', 'yes');
            } elseif ($requestData['booking_type'] === 'phone') {
                $query->where('customer_phone_type', 'yes');
            }
        }
    }

    /**
     * Apply time filters
     * @param Builder $query
     * @param array $requestData
     */
    private function applyTimeFilters($query, $requestData, $fieldPrefix = '')
    {
        $filterTimeType = $requestData['filter_timetype'] ?? null;

        if ($filterTimeType === 'created') {
            $field = $fieldPrefix !== '' ? "$fieldPrefix.created_at" : 'created_at';
            $this->applyDateFilter($query, $field, $requestData);
        } elseif ($filterTimeType === 'due') {
            $field = $fieldPrefix !== '' ? "$fieldPrefix.due" : 'due';
            $this->applyDateFilter($query, $field, $requestData);
        }
    }

    /**
     * Apply date range filter
     * @param Builder $query
     * @param string $field
     * @param array $requestData
     */
    private function applyDateFilter($query, $field, $requestData)
    {
        if (!empty($requestData['from'])) {
            $query->where($field, '>=', $requestData['from']);
        }
        if (!empty($requestData['to'])) {
            $query->where($field, '<=', $requestData['to'] . ' 23:59:00');
        }
        $query->orderBy($field, 'desc');
    }

    /**
     * Apply additional filters
     * @param Builder $query
     * @param array $requestData
     */
    private function applyAdditionalFilters($query, $requestData)
    {
        $this->applyJobTypeFilter($query, $requestData);

        if (!empty($requestData['physical'])) {
            $query->where('customer_physical_type', $requestData['physical'])
                  ->where('ignore_physical', 0);
        }

        if (!empty($requestData['phone'])) {
            $query->where('customer_phone_type', $requestData['phone']);
            if (!empty($requestData['physical'])) {
                $query->where('ignore_physical_phone', 0);
            }
        }

        if (!empty($requestData['flagged'])) {
            $query->where('flagged', $requestData['flagged'])
                  ->where('ignore_flagged', 0);
        }
    }

    /**
     * Get user IDs by email
     * @param array|string $emails
     * @return array
     */
    private function getUserIdsByEmail($emails, $first = false)
    {
        $query = DB::table('users')->whereIn('email', (array)$emails);
        
        if($first){
            $user = $query->first();
            return [ $user->id ];
        }

        return $query->pluck('id');
    }

    /**
     * Get job IDs by translator email
     * @param array|string $emails
     * @return array
     */
    private function getJobIdsByTranslatorEmail($emails, $first = false)
    {
        $query = DB::table('users')->whereIn('email', (array)$emails);

        if($first){
            $user = $query->first();
            $userIds = [ $user->id ];
        }else {
            $userIds = $query->pluck('id');
        }

        if (!empty($userIds)) { 
            return DB::table('translator_job_rel')
                     ->whereNull('cancel_at')
                     ->whereIn('user_id', $userIds)
                     ->pluck('job_id');
        }
        return [];
    }

    /**
     * Get filtered job alerts based on provided criteria.
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     * 
     * @param Builder $query
     * @param array $requestData
     */
    public function alerts(Request $request) {
        $jobs = Job::all();
        $sesJobs = [];
        $jobIds = [];

        // Identify jobs with exceeded session duration
        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $timeInMinutes = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
                if ($timeInMinutes >= $job->duration * 2) {
                    $sesJobs[] = $job;
                }
            }
        }

        $jobIds = collect($sesJobs)->pluck('id');

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestData = $request->all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $authenticatedUser = $request->__authenticatedUser;

        $allJobs = [];
         // Super Admin-specific filters
         if ($authenticatedUser && $authenticatedUser->is('superadmin')) {
            // Start with the basic query using the DB facade
            $query = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobIds)
                ->where('jobs.ignore', 0);
            
            // Apply filters using the provided helper functions
            $this->applyLanguageFilter($query, $requestData, 'jobs');
            $this->applyStatusFilter($query, $requestData, 'jobs');
            $this->applyCustomerEmailFilter($query, $requestData, 'jobs');
            $this->applyTranslatorEmailFilter($query, $requestData, 'jobs');
            $this->applyTimeFilters($query, $requestData, 'jobs');
            $this->applyJobTypeFilter($query, $requestData, 'jobs');

            $query->select('jobs.*', 'languages.language')
            ->orderBy('jobs.created_at', 'desc');

            // Getting the paginated results
            $allJobs = $query->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestData];

    }

    /**
     * Fetch expired pending jobs with applied filters using DB facade.
     * @param array $requestData
     * @param User $authenticatedUser
     * @return mixed
     */
    public function getExpiredPendingJobs($requestData, $authenticatedUser)
    {
        $query = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0)
            ->where('jobs.status', 'pending')
            ->where('jobs.due', '>=', Carbon::now());

        if ($authenticatedUser->is('superadmin') || $authenticatedUser->is('admin')) {
            $this->applyLanguageFilter($query, $requestData, 'jobs');
            $this->applyStatusFilter($query, $requestData, 'jobs');

            $this->applyCustomerEmailFilter($query, $requestData, 'jobs');
            $this->applyTranslatorEmailFilter($query, $requestData, 'jobs');

            $this->applyTimeFilters($query, $requestData, 'jobs');

            $this->applyJobTypeFilter($query, $requestData, 'jobs');

            $query->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc');

            return $query->paginate(15);
        }

        return [];
    }


    /**
     * Reopen a job for a user.
     * 
     * @param array $request
     * @return array
     */
    public function reopenJob(array $request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = Job::find($jobId);

        if (!$job) {
            return ["Job not found!"];
        }

        $jobData = $job->toArray();

        // Data for translator cancellation
        $translatorCreatedAt = now();
        $translatorData = [
            'created_at' => $translatorCreatedAt,
            'will_expire_at' => TeHelper::willExpireAt($job['due'], $translatorCreatedAt),
            'updated_at' => now(),
            'cancel_at' => now(),
            'user_id' => $userId,
            'job_id' => $jobId,
        ];

        if ($jobData['status'] !== 'timedout') {
            $jobCreatedAt = now();
            // Update the job to pending
            $affectedRows = $job->update([
                'status' => 'pending',
                'created_at' => $jobCreatedAt,
                'will_expire_at' => TeHelper::willExpireAt($jobData['due'], $jobCreatedAt),
            ]);

            $newJobId = $jobId;
        } else {
            // Create a new job entry
            $jobData['status'] = 'pending';
            $jobData['created_at'] = now();
            $jobData['updated_at'] = now();
            $jobData['will_expire_at'] = TeHelper::willExpireAt($jobData['due'], now());
            $jobData['cust_16_hour_email'] = 0;
            $jobData['cust_48_hour_email'] = 0;
            $jobData['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;

            $affectedRows = $newJob = Job::create($jobData);
            $newJobId = $newJob->id;
        }

        // Cancel the existing translator job relations
        Translator::where('job_id', $jobId)->whereNull('cancel_at')->update(['cancel_at' => $translatorData['cancel_at']]);

        // Create a new translator-job relation
        Translator::create($translatorData);

        // Send notification
        if (isset($affectedRows)) {
            $this->notificationService->sendNotificationByAdminCancelJob($newJobId);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Update a job with the given data.
     * TODO: Update these calls to reference JobRepository instead of BookingRepository.
     *
     * @param int $id
     * @param array $data
     * @param User $cuser
     * @return array
     */
    public function updateJob(int $id, array $data, User $cuser): array
    {
        $job = Job::find($id);

        if (!$job) {
            return ['error' => 'Job not found'];
        }

        $currentTranslator = $job->translatorJobRel->where('cancel_at', null)->first()
            ?? $job->translatorJobRel->where('completed_at', '!=', null)->first();

        $logData = [];
        $langChanged = false;

        // Handle translator changes
        $changeTranslator = $this->bookingRepository->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $logData[] = $changeTranslator['log_data'];
        }

        // Handle due date changes
        $changeDue = $this->bookingRepository->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        // Handle language changes
        if ($job->from_language_id !== $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id']),
            ];
            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        // Handle status changes
        $changeStatus = $this->bookingRepository->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $logData[] = $changeStatus['log_data'];
        }

        // Update admin comments and reference
        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        // Log the update
        $this->logger->addInfo(
            'USER #' . $cuser->id . ' (' . $cuser->name . ') updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ',
            $logData
        );

        // Save changes
        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        }else {
            // Send notifications
            if ($changeDue['dateChanged']) {
                $this->notificationService->sendChangedDateNotification($job, $oldTime);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->notificationService->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->notificationService->sendChangedLangNotification($job, $oldLang);
            }
        }

    }


    /**
     * Update distance and time for a job.
     *
     * @param int|null $jobId
     * @param string $distance
     * @param string $time
     * @return void
     */
    public function updateDistance(?int $jobId, string $distance, string $time): void
    {
        if ($jobId) {
            Distance::where('job_id', '=', $jobId)->update([
                'distance' => $distance,
                'time' => $time,
            ]);
        }
    }

    /**
     * Update job-related details.
     *
     * @param int|null $jobId
     * @param array $data
     * @return void
     */
    public function updateJobDetails(?int $jobId, array $data): void
    {
        if ($jobId) {
            Job::where('id', '=', $jobId)->update($data);
        }
    }
}