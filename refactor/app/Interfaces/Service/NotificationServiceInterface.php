<?php

namespace DTApi\Interfaces\Service;

interface NotificationServiceInterface
{
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id);

    public function sendSMSNotificationToTranslator($job);

    public function isNeedToDelayPush($user_id);

    public function isNeedToSendPush($user_id);

    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay);

    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration);

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator);

    public function sendChangedDateNotification($job, $old_time);

    public function sendChangedLangNotification($job, $old_lang);

    public function sendExpiredNotification($job, $user);

    public function sendNotificationByAdminCancelJob($job_id);

    public function sendNotificationChangePending($user, $job, $language, $due, $duration);
}
