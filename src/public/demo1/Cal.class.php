<?php

class Cal
{
    const DEFAULT_TZ = 'UTC';

    /**
     * @var Google_Client
     */
    private $client;

    /**
     * @var Google_Service_Calendar
     */
    private $calendar_service;

    public function getGoogleCalendarConfig(): array
    {
        return [
            'application_name' => 'DaritelskaPlatforma',
            'account_id' => '',
            'credentials_file' => '',
        ];
    }

    public function getCalendarService(): Google_Service_Calendar
    {
        if (!$this->calendar_service) {
            $this->calendar_service = new Google_Service_Calendar($this->getClient());
        }
        return $this->calendar_service;
    }

    protected function getClientInstance()
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

    private function syncGoogleCalendarEventsInternal(VMemberGoogleCalendar $calendar)
    {
        $cal_title = $calendar->getFormattedTitle();

        $this->info('Syncing google calendar events: ' . $cal_title);

        $cal_id = $calendar->getGoogleCalId();

        if (!$cal_id) {
            throw new lcInvalidArgumentException('Google Calendar ID is invalid');
        }

        $sync_token = $calendar->getSyncToken();

        $opt_params = [];

        if ($sync_token) {
            $opt_params['syncToken'] = $sync_token;
        }

        $service = $this->getCalendarService();

        $page_token = null;
        $events = null;

        do {
            try {
                $events = $service->events->listEvents($cal_id, $opt_params);
            } /** @noinspection PhpRedundantCatchClauseInspection */ catch (Google_Service_Exception $e) {
                // A 410 status code, "Gone", indicates that the sync token is invalid.
                if ($e->getCode() == 410) {
                    $this->warn('Got a 410 while syncing - restarting syncing all events');
                    $calendar->resetSyncToken();

                    $this->sync_google_cal_events_err_counter[$cal_id] =
                        isset($this->sync_google_cal_events_err_counter[$cal_id]) ?
                            $this->sync_google_cal_events_err_counter[$cal_id]++ : 1;

                    if ($this->sync_google_cal_events_err_counter[$cal_id] >= self::MAX_GOOGLE_CAL_EVENTS_ERR_COUNT) {
                        throw new lcLogicException('Reached the maximum sync retry times. Cancelling the sync.');
                    }

                    // if max not reached loop back in
                    return $this->syncGoogleCalendarEvents($calendar);
                }
            }

            if ($events) {
                foreach ($events as $event) {
                    $log = [];
                    $success = $this->syncGoogleCalendarEvent($calendar, $event, $log);

                    if (!$success) {
                        $this->warn('Could not sync google calendar event: ' . print_r($log, true));
                    }

                    unset($event);
                }

                $page_token = $events->getNextPageToken();
            } else {
                $page_token = null;
            }

        } while ($page_token);

        $calendar->updateSyncToken($events ? $events->getNextSyncToken() : null);

        unset($this->sync_google_cal_events_err_counter[$cal_id]);

        return $this;
    }

    protected function syncLocalApointmentToRemoteGoogleCalendar(VMemberGoogleCalendar $calendar,
                                                                 $change_type,
                                                                 DateTime $change_created_on,
                                                                 VAppointment $appointment,
                                                                 VAppointmentMember $participant = null
    )
    {
        $appointment_id = $appointment->getId();
        $cal_id = $calendar->getGoogleCalId();

        // custom case for create - already created
        if ($change_type == VAppointmentPendingGcalChangePeer::CHANGE_TYPE_CREATED &&
            $appointment->getGoogleCalendarEventId()) {
            return false;
        }

        $t = lcDateTime::utcDateTime();

        $service = $this->getCalendarService();

        $current_gevent = $appointment->getVMemberGoogleCalendarEvent();

        if (in_array($change_type, [
            VAppointmentPendingGcalChangePeer::CHANGE_TYPE_UPDATED,
            VAppointmentPendingGcalChangePeer::CHANGE_TYPE_DELETED,
            VAppointmentPendingGcalChangePeer::CHANGE_TYPE_CANCELLED,
            VAppointmentPendingGcalChangePeer::CHANGE_TYPE_MCREATE,
            VAppointmentPendingGcalChangePeer::CHANGE_TYPE_MUPDATE,
        ])) {
            if (!$current_gevent) {
                $this->warn('Local google calendar item not found');
                return false;
            }
        }

        switch ($change_type) {
            case VAppointmentPendingGcalChangePeer::CHANGE_TYPE_CREATED :
            {
                $event = new Google_Service_Calendar_Event();

                $conf_extra_data = [];

                if ($appointment->getOnlineAccessEnabled() == 'yes') {
                    $conference = new Google_Service_Calendar_ConferenceData();
                    $conferenceRequest = new Google_Service_Calendar_CreateConferenceRequest();
                    $conferenceRequest->setRequestId(lcStrings::randomString(64, true));
                    $conference->setCreateRequest($conferenceRequest);
                    $event->setConferenceData($conference);

                    $conf_extra_data = ['conferenceDataVersion' => 1];
                }

                $start = new Google_Service_Calendar_EventDateTime();
                $start->dateTime = $appointment->getStartTime(null)->format(DateTime::ATOM);
                $event->setStart($start);

                $end_time = $appointment->getEndTime(null);

                if ($end_time) {
                    $end = new Google_Service_Calendar_EventDateTime();
                    $end->dateTime = $end_time->format(DateTime::ATOM);
                    $event->setEnd($end);
                }

                $event->setSummary($appointment->getTitle() ?: null);

                $location = $appointment->getPrimaryLocation();
                $location = $location ? $location->getTitle() : null;

                $event->setLocation($location ?: null);
                $event->setDescription($appointment->getNotes());

                // store the assigned appointment so we can link them when remote -> local sync runs
                $extendedProperties = new Google_Service_Calendar_EventExtendedProperties();
                $extendedProperties->setShared([self::AID_KEY => $appointment_id]);
                $event->setExtendedProperties($extendedProperties);

                $evret = null;

                try {
                    $evret = $service->events->insert($cal_id, $event, $conf_extra_data);
                } catch (Exception $e) {
                    $this->err('Could not insert: ' . $e);

                    ee('cal id: ' . $cal_id);
                    ee(print_r($event, true));

//                    if (DO_DEBUG) {
//                        throw $e;
//                    }
                }

                if ($evret) {
                    $local_gevent = $this->createUpdateLocalGoogleCalendarEvent($calendar, $evret, false);

                    if ($local_gevent) {
                        $event_conference_data = $local_gevent->getVMemberGoogleCalendarEventConferenceData();
                        $unique_code = $event_conference_data ? $event_conference_data->getGId() : null;

                        $appointment->setUniqueCode($unique_code);

                        $appointment->setAdditionalAddressDetails($local_gevent->getGLocation());
                        $appointment->setOnlineMeetingUrl($local_gevent->getGHangoutLink());
                        $appointment->setVMemberGoogleCalendarEvent($local_gevent);
                        $appointment->setGoogleCalendarEventUpdatedOn($t);
                        $appointment->save();
                    }
                }

                break;
            }
            case VAppointmentPendingGcalChangePeer::CHANGE_TYPE_UPDATED:
            {
                $event = new Google_Service_Calendar_Event();

                // online access

                //$conference_data_item = $current_gevent->getVMemberGoogleCalendarEventConferenceData();
                $conf_extra_data = [];

                if ($appointment->getOnlineAccessEnabled() == 'yes') {
//                    if (!$conference_data_item) {
                    $conference = new Google_Service_Calendar_ConferenceData();
                    $conferenceRequest = new Google_Service_Calendar_CreateConferenceRequest();
                    $conferenceRequest->setRequestId(lcStrings::randomString(64, true));
                    $conference->setCreateRequest($conferenceRequest);
                    $event->setConferenceData($conference);

                    $conf_extra_data = ['conferenceDataVersion' => 1];
//                    }
                } else {
                    // TODO: how do we 'unset' conference data?
                    $event->setConferenceData(new Google_Service_Calendar_ConferenceData());
                }

                // start time

                $current_gevent_start = $current_gevent->getGStart();
                $current_gevent_start = $current_gevent_start ? new DateTime($current_gevent_start) : null;

                if (!$current_gevent_start || $current_gevent_start->format('Y-m-d H:i:s') !=
                    $appointment->getStartTime('Y-m-d H:i:s')) {
                    $start = new Google_Service_Calendar_EventDateTime();
                    $start->dateTime = $appointment->getStartTime(null)->format(DateTime::ATOM);
                    $event->setStart($start);
                }

                // end time

                $current_gevent_end = $current_gevent->getGEnd();
                $current_gevent_end = $current_gevent_end ? new DateTime($current_gevent_end) : null;

                if (!$current_gevent_end || $current_gevent_end->format('Y-m-d H:i:s') !=
                    $appointment->getEndTime('Y-m-d H:i:s')) {
                    $end_time = $appointment->getEndTime(null);

                    $end = new Google_Service_Calendar_EventDateTime();
                    $end->dateTime = $end_time ? $end_time->format(DateTime::ATOM) : null;
                    $event->setEnd($end);
                }

                // summary
                $summary = $current_gevent->getGSummary();
                $new_summary = $appointment->getTitle();

                if ($summary != $new_summary) {
                    $event->setSummary($new_summary ?: null);
                }

                // location
                $location = $current_gevent->getGLocation();

                $new_location = $appointment->getPrimaryLocation();
                $new_location = $new_location ? $new_location->getTitle() : null;

                if ($location != $new_location) {
                    $event->setLocation($new_location ?: null);
                }

                // description
                $description = $current_gevent->getGDescription();
                $new_description = $appointment->getNotes();

                if ($description != $new_description) {
                    $event->setDescription($new_description ?: null);
                }

                $evret = null;

                try {
                    $evret = $service->events->patch($cal_id, $current_gevent->getGId(), $event, $conf_extra_data);
                } catch (Exception $e) {
                    $this->err('Could not patch: ' . $e);

                    ee('cal id: ' . $cal_id);
                    ee('gid: ' . $current_gevent->getGId());
                    ee(print_r($event, true));

//                    if (DO_DEBUG) {
//                        throw $e;
//                    }
                }

                if ($evret) {
                    $local_gevent = $this->createUpdateLocalGoogleCalendarEvent($calendar, $evret, false);

                    if ($local_gevent) {
                        $event_conference_data = $local_gevent->getVMemberGoogleCalendarEventConferenceData();
                        $unique_code = $event_conference_data ? $event_conference_data->getGId() : null;

                        $appointment->setUniqueCode($unique_code);
                        $appointment->setOnlineMeetingUrl($local_gevent->getGHangoutLink());
                        $appointment->save();
                    }
                }

                break;
            }
            case VAppointmentPendingGcalChangePeer::CHANGE_TYPE_DELETED:
            case VAppointmentPendingGcalChangePeer::CHANGE_TYPE_CANCELLED:
            {
                $event = new Google_Service_Calendar_Event();
                $event->setStatus('cancelled');

                $evret = null;

                try {
                    $evret = $service->events->patch($cal_id, $current_gevent->getGId(), $event);
                } catch (Exception $e) {
                    $this->err('Could not patch: ' . $e);

                    ee('cal id: ' . $cal_id);
                    ee('gid: ' . $current_gevent->getGId());
                    ee(print_r($event, true));

//                    if (DO_DEBUG) {
//                        throw $e;
//                    }
                }

                if (!$evret) {
                    throw new lcIOException('Could not cancel Google Calendar Event');
                }

                $this->createUpdateLocalGoogleCalendarEvent($calendar, $evret, false);

                break;
            }
            case VAppointmentPendingGcalChangePeer::CHANGE_TYPE_MCREATE :
            case VAppointmentPendingGcalChangePeer::CHANGE_TYPE_MUPDATE :
            {
                if (!$participant) {
                    throw new lcInvalidArgumentException('Invalid participant');
                }

                $attendees = $appointment->getParticipantsAsGoogleCalendarParticipants();

//                $participant_member = $participant->getMember();

                $event = new Google_Service_Calendar_Event();

//                $p = new Google_Service_Calendar_EventAttendee();
//                $p->displayName = $participant_member->getFullName();
//                $p->email = $participant_member->getEmail();
//                $p->responseStatus = $participant->getGoogleCalendarApprovalState();

                $event->setAttendees($attendees);

                $evret = null;

                try {
                    $evret = $service->events->patch($cal_id, $current_gevent->getGId(), $event);
                } catch (Exception $e) {
                    $this->err('Could not patch: ' . $e);

                    ee('cal id: ' . $cal_id);
                    ee('gid: ' . $current_gevent->getGId());
                    ee(print_r($event, true));

//                    if (DO_DEBUG) {
//                        throw $e;
//                    }
                }

                if ($evret) {
                    $this->createUpdateLocalGoogleCalendarEvent($calendar, $evret, false);
                }

                break;
            }
        }
    }

    public function updateCalendar(VMemberGoogleCalendar $calendar, array $options = null)
    {
        $service = $this->getCalendarService();

        $gcalendar = new Google_Service_Calendar_Calendar();

        $title = isset($options['title']) && $options['title'] ? $options['title'] :
            $calendar->getTitle();
        $timezone = isset($options['timezone']) && $options['timezone'] ?
            $options['timezone'] : $calendar->getTimezone();

        $gcalendar->setSummary($title);
        $gcalendar->setTimeZone($timezone);

        $gcal_data = $service->calendars->update($calendar->getGoogleCalId(), $gcalendar);

        $t = lcDateTime::utcDateTime();

        $calendar->setDataFromGoogleCalendar($gcal_data);
        $calendar->setUpdatedOn($t);
        $calendar->save();

        return $this;
    }

    public function createGoogleCalendar(Member $member, array $options = null)
    {
        $service = $this->getCalendarService();

        $calendar = new Google_Service_Calendar_Calendar();

        $title = isset($options['title']) && $options['title'] ? $options['title'] :
            $this->getDefaultMemberCalendarTitle($member);
        $timezone = isset($options['timezone']) && $options['timezone'] ?
            $options['timezone'] : self::DEFAULT_TZ;

        $calendar->setSummary($title);
        $calendar->setTimeZone($timezone);

        return $service->calendars->insert($calendar);
    }

    public function createGoogleCalendarEventsWatch(VMemberGoogleCalendar $calendar, array $options = null)
    {
        $address = $this->getConfig()['google_calendar.notifications.url'];

        if (!$address) {
            throw new lcConfigException('No web hook url configured');
        }

        $channel_id = lcStrings::randomString(50);

        $client = $this->getClient();
        $cal = $this->getCalendarService();
        $calendar_google_id = $calendar->getGoogleCalId();

        $address .= '/' . $calendar_google_id;

        $channel = new Google_Service_Calendar_Channel($client);
        $channel->setId($channel_id);
        $channel->setType('web_hook');
        $channel->setAddress($address);
        $channel->setExpiration((time() +
                (self::DEFAULT_EVENTS_WATCH_EXPIRATION_TIME_DAYS * 86400)) * 1000);

        $response = null;

        try {
            $response = $cal->events->watch($calendar_google_id, $channel);
        } catch (Exception $e) {
            throw new lcIOException('Could not create watch: ' . $e->getMessage(),
                $e->getCode(), $e);
        }

        $resource_id = $response->getResourceId();
        $resource_uri = $response->getResourceUri();

        $expires_on = $response->getExpiration();
        $expires_on = $expires_on ? new DateTime('@' . $expires_on / 1000) : null;

        $t = lcDateTime::utcDateTime();

        $watch = new VMemberGoogleCalendarEventWatch();
        $watch->setVMemberGoogleCalendar($calendar);
        $watch->setCreatedOn($t);
        $watch->setChannelId($channel_id);
        $watch->setResourceId($resource_id);
        $watch->setWebhookUrl($address);
        $watch->setResourceUrl($resource_uri);
        $watch->setExpiresOn($expires_on);
        $watch->save();

        return $watch;
    }
}