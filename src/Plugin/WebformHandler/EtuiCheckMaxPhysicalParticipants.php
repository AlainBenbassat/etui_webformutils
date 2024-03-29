<?php

namespace Drupal\etui_webformutils\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\Component\Utility\Html;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Form submission handler.
 *
 * @WebformHandler(
 *   id = "etui_webform_max_physical_participants",
 *   label = @Translation("ETUI check max. number of physical participants"),
 *   category = @Translation("ETUI"),
 *   description = @Translation("ETUI check max. number of physical participants"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class EtuiCheckMaxPhysicalParticipants extends WebformHandlerBase {

  use StringTranslationTrait;

  private $willYouAttendDay = [
    1 => ['custom_499', 'Will_you_attend_the_meeting_on_Date_1_'],
    2 => ['custom_500', 'Will_you_attend_the_meeting_on_Day_2_'],
    3 => ['custom_501', 'Will_you_attend_the_meeting_on_Date_3_'],
    4 => ['custom_502', 'Will_you_attend_the_meeting_on_Date_4_'],
    5 => ['custom_503', 'Will_you_attend_the_meeting_on_Date_5_'],
  ];

  private $presenceOfDay = [
    1 => ['custom_575', 'Presence'],
    2 => ['custom_590', 'Presence_day2'],
    3 => ['custom_591', 'Presence_day_3'],
    4 => ['custom_638', 'Presence_day4'],
    5 => ['custom_639', 'Presence_day5'],
  ];

  /**
   * Webform validate handler.
   *
   * @WebformHandler(
   *   id = "etui_webformutils_custom_validator",
   *   label = @Translation("Alter form to validate it"),
   *   category = @Translation("Settings"),
   *   description = @Translation("Check number of physical participants."),
   *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
   *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
   *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
   * )
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $invalidFields = $this->validateNumberOfPhysicalParticipants($form_state, $webform_submission);
    if (count($invalidFields) > 0) {
      $this->markInvalidFields($invalidFields, $form_state);
    }
  }

  private function validateNumberOfPhysicalParticipants(FormStateInterface $formState, WebformSubmissionInterface $webform_submission) {
    $invalidFields = [];
    $eventId = $this->getEventId($webform_submission);

    $maxPhysicalParticipants = $this->getMaxPhysicalParticipants($eventId);
    if ($maxPhysicalParticipants == 'unlimited') {
      return [];
    }

    $submittedFields = $formState->getValues();
    foreach ($this->willYouAttendDay as $dayNumber => $customFieldWillYouAttend) {
      foreach ($submittedFields as $fieldKey => $fieldValue) {
        if ($this->isWillYouAttendfield($fieldKey, $customFieldWillYouAttend)) {
          if (!$this->isPhysicalPresenceAllowed($dayNumber, $eventId, $maxPhysicalParticipants)) {
            $fieldKeyPresence = $this->getPresenceKeyOfDay($dayNumber, $submittedFields);
            $invalidFields[$fieldKeyPresence] = 'ERROR: Your submission is not accepted! Please note that the number of seats in the meeting room is restricted, therefore if you can no longer opt for attendance in person you can still attend the event ONLINE. If you would prefer to take part in person, you can still indicate this in the notes field. In case some other participants cancel their attendance, we will take your preference into account and will let you know if you can participate in the meeting room. Thank you for your understanding.';
          }
        }
      }
    }
    return $invalidFields;
  }

  private function getEventId(WebformSubmissionInterface $webform_submission) {
    // some magic code copied from webform_civicrm/src/WebformCivicrmPostProcess.php
    $node = $webform_submission->getWebform();
    $handler_collection = $node->getHandlers('webform_civicrm');
    $instance_ids = $handler_collection->getInstanceIds();
    $handler = $handler_collection->get(reset($instance_ids));
    $settings = $handler->getConfiguration()['settings'];
    $data = $settings['data'];
    foreach ($data['participant'] as $c => $par) {
      foreach (wf_crm_aval($par, 'participant', []) as $n => $p) {
        foreach (array_filter(wf_crm_aval($p, 'event_id', [])) as $id_and_type) {
          [$eid] = explode('-', $id_and_type);
          if (is_numeric($eid)) {
            // yes, we found an event id!!!!!!!!!!
            return $eid;
          }
        }
      }
    }

    return 0;
  }

  private function getMaxPhysicalParticipants($eventId) {
    $events = \Civi\Api4\Event::get(FALSE)
      ->addSelect('Event_topic.Maximum_participants_in_meeting_room')
      ->addWhere('id', '=', $eventId)
      ->setLimit(1)
      ->execute();
    if (empty($events[0]['Event_topic.Maximum_participants_in_meeting_room'])) {
      return 'unlimited';
    }
    else {
      return $events[0]['Event_topic.Maximum_participants_in_meeting_room'];
    }
  }

  private function markInvalidFields($fieldErrors, FormStateInterface $form_state) {
    foreach ($fieldErrors as $fieldKey => $errorMessage) {
      $form_state->setErrorByName($fieldKey, $errorMessage);
    }
  }

  private function isWillYouAttendfield($fieldKey, $customFieldWillYouAttend) {
    if (strpos($fieldKey, $customFieldWillYouAttend[0]) > 0) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  private function isPhysicalPresenceAllowed($dayNumber, $eventId, $maxPhysicalParticipants) {
    $customFieldName = $this->presenceOfDay[$dayNumber][1];
    $willAttendInMeetingRoom = 1;

    $participants = \Civi\Api4\Participant::get(FALSE)
      ->addSelect("Participant_Presence.$customFieldName")
      ->addWhere('event_id', '=', $eventId)
      ->addWhere("Participant_Presence.$customFieldName", '=', $willAttendInMeetingRoom)
      ->addWhere('status_id', 'IN', [1, 2])
      ->execute();

    if (count($participants) >= $maxPhysicalParticipants) {
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  private function getPresenceKeyOfDay($dayNumber, $submittedFields) {
    $fieldToFind = $this->presenceOfDay[$dayNumber][0];
    foreach ($submittedFields as $fieldKey => $fieldValue) {
      if (strpos($fieldKey, $fieldToFind) > 0) {
        return $fieldKey;
      }
    }

    return '';
  }
}
