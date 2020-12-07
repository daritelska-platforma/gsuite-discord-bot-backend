<?php

class Cal
{
    const DEFAULT_TZ = 'UTC';
    const DEFAULT_ENV = 'prod';

    /**
     * @var Google_Client
     */
    private $client;

    /**
     * @var Google_Service_Calendar
     */
    private $calendar_service;

    /**
     * @var string
     */
    private string $env;

    public function __construct(string $env = self::DEFAULT_ENV)
    {
        $this->env = $env;
    }

    public function getGoogleCalendarConfig(): array
    {
        return [
            'application_name' => 'DaritelskaPlatforma',
            'account_id' => 'discordsync@probnata.com',
            'calendar_id' => 'c_lni854tglkj2m1h2m4a7pab3os@group.calendar.google.com',
            'credentials_file' => '../config/' . $this->env . '/service_account/gcloud_service_account.json',
        ];
    }

    public function getCalendarService(): Google_Service_Calendar
    {
        if (!$this->calendar_service) {
            $this->calendar_service = new Google_Service_Calendar($this->getClient());
        }
        return $this->calendar_service;
    }

    protected function getClientInstance(): Google_Client
    {
        $cal_cfg = $this->getGoogleCalendarConfig();

        if (!$cal_cfg || !isset($cal_cfg['application_name']) ||
            !isset($cal_cfg['account_id']) || !isset($cal_cfg['credentials_file'])) {
            throw new Exception('Google Calendar not configured');
        }

        $cred_file = $cal_cfg['credentials_file'];
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $cred_file);

        $client = new Google_Client();
        $client->useApplicationDefaultCredentials();
        $client->setApplicationName($cal_cfg['application_name']);
        $client->setScopes([
            Google_Service_Calendar::CALENDAR,
        ]);
        $client->setSubject($cal_cfg['account_id']);
        $client->setAccessType("offline");
        return $client;
    }

    public function getClient(): Google_Client
    {
        if (!$this->client) {
            $this->client = $this->getClientInstance();
        }
        return $this->client;
    }

    public function getGoogleCalendar($google_cal_id): ?Google_Service_Calendar_Calendar
    {
        $service = $this->getCalendarService();
        $calendar_g = null;

        try {
            $calendar_g = $service->calendars->get($google_cal_id);
        } /** @noinspection PhpRedundantCatchClauseInspection */
        catch (Google_Service_Exception $ge) {
            if ($ge->getCode() == 404) {
                throw new Exception('Calendar with the provided Google ID not found on Google');
            }
        }

        return $calendar_g;
    }

    public function createTestEvent()
    {
        $cal_id = $this->getGoogleCalendarConfig()['calendar_id'];

        $rand_p = rand(5000, 50000000);

        $t = new DateTime();
        $t->modify('+' . rand(0, 5) . ' hour');

        $service = $this->getCalendarService();

        $event = new Google_Service_Calendar_Event();

        $conference = new Google_Service_Calendar_ConferenceData();
        $conferenceRequest = new Google_Service_Calendar_CreateConferenceRequest();
        $conferenceRequest->setRequestId('reqid_' . $rand_p);
        $conference->setCreateRequest($conferenceRequest);
        $event->setConferenceData($conference);

        $conf_extra_data = ['conferenceDataVersion' => 1];

        $start = new Google_Service_Calendar_EventDateTime();
        $start->dateTime = $t->format(DateTime::ATOM);
        $event->setStart($start);

        $end_time = clone $t;
        $end_time->modify('+' . rand(1, 5) . ' hour');

        if ($end_time) {
            $end = new Google_Service_Calendar_EventDateTime();
            $end->dateTime = $end_time->format(DateTime::ATOM);
            $event->setEnd($end);
        }

        $event->setSummary('A summary :: ' . $rand_p);
        $event->setLocation('Some location :: ' . $rand_p);
        $event->setDescription('Description :: ' . $rand_p);

        // store the assigned appointment so we can link them when remote -> local sync runs
        $extendedProperties = new Google_Service_Calendar_EventExtendedProperties();
        $extendedProperties->setShared(['my_special_unique_id' => $rand_p]);
        $event->setExtendedProperties($extendedProperties);

        return $service->events->insert($cal_id, $event, $conf_extra_data);
    }

//    public function updateCalendar(VMemberGoogleCalendar $calendar, array $options = null)
//    {
//        $service = $this->getCalendarService();
//
//        $gcalendar = new Google_Service_Calendar_Calendar();
//
//        $title = isset($options['title']) && $options['title'] ? $options['title'] :
//            $calendar->getTitle();
//        $timezone = isset($options['timezone']) && $options['timezone'] ?
//            $options['timezone'] : $calendar->getTimezone();
//
//        $gcalendar->setSummary($title);
//        $gcalendar->setTimeZone($timezone);
//
//        $gcal_data = $service->calendars->update($calendar->getGoogleCalId(), $gcalendar);
//
//        $t = lcDateTime::utcDateTime();
//
//        $calendar->setDataFromGoogleCalendar($gcal_data);
//        $calendar->setUpdatedOn($t);
//        $calendar->save();
//
//        return $this;
//    }
//
//    public function createGoogleCalendar(Member $member, array $options = null)
//    {
//        $service = $this->getCalendarService();
//
//        $calendar = new Google_Service_Calendar_Calendar();
//
//        $title = isset($options['title']) && $options['title'] ? $options['title'] :
//            $this->getDefaultMemberCalendarTitle($member);
//        $timezone = isset($options['timezone']) && $options['timezone'] ?
//            $options['timezone'] : self::DEFAULT_TZ;
//
//        $calendar->setSummary($title);
//        $calendar->setTimeZone($timezone);
//
//        return $service->calendars->insert($calendar);
//    }
//
//    public function createGoogleCalendarEventsWatch(VMemberGoogleCalendar $calendar, array $options = null)
//    {
//        $address = $this->getConfig()['google_calendar.notifications.url'];
//
//        if (!$address) {
//            throw new lcConfigException('No web hook url configured');
//        }
//
//        $channel_id = lcStrings::randomString(50);
//
//        $client = $this->getClient();
//        $cal = $this->getCalendarService();
//        $calendar_google_id = $calendar->getGoogleCalId();
//
//        $address .= '/' . $calendar_google_id;
//
//        $channel = new Google_Service_Calendar_Channel($client);
//        $channel->setId($channel_id);
//        $channel->setType('web_hook');
//        $channel->setAddress($address);
//        $channel->setExpiration((time() +
//                (self::DEFAULT_EVENTS_WATCH_EXPIRATION_TIME_DAYS * 86400)) * 1000);
//
//        $response = null;
//
//        try {
//            $response = $cal->events->watch($calendar_google_id, $channel);
//        } catch (Exception $e) {
//            throw new lcIOException('Could not create watch: ' . $e->getMessage(),
//                $e->getCode(), $e);
//        }
//
//        $resource_id = $response->getResourceId();
//        $resource_uri = $response->getResourceUri();
//
//        $expires_on = $response->getExpiration();
//        $expires_on = $expires_on ? new DateTime('@' . $expires_on / 1000) : null;
//
//        $t = lcDateTime::utcDateTime();
//
//        $watch = new VMemberGoogleCalendarEventWatch();
//        $watch->setVMemberGoogleCalendar($calendar);
//        $watch->setCreatedOn($t);
//        $watch->setChannelId($channel_id);
//        $watch->setResourceId($resource_id);
//        $watch->setWebhookUrl($address);
//        $watch->setResourceUrl($resource_uri);
//        $watch->setExpiresOn($expires_on);
//        $watch->save();
//
//        return $watch;
//    }
}