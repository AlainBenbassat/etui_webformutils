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

  private $willTakePartInTheMeetingRoom = 1;
  private $willTakePartOnline = 2;
  private $maxPhysicalParticipants = 0;

  private $eventId = 0;

  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $this->eventId = $this->getEventId($webform_submission);
    $max = $this->getMaxPhysicalParticipants($this->eventId);

    if ($this->maxPhysicalParticipants == 'unlimited') {
      return;
    }
    else {
      $this->maxPhysicalParticipants = (int)$max;
      $this->validateNumberOfPhysicalParticipants($form_state, $webform_submission);
    }
  }

  private function validateNumberOfPhysicalParticipants(FormStateInterface $formState, WebformSubmissionInterface $webform_submission) {
    // loop over the submitted fields
    $submittedFields = $formState->getValues();
    foreach ($submittedFields as $fieldKey => $fieldValue) {
      if ($this->isFieldYourPresence($fieldKey)) {
        $dayNumber = $this->getDayNumber($fieldValue);

        if ($this->willThePersonAttendOnDay($formState, $dayNumber) && $fieldValue == $this->willTakePartInTheMeetingRoom) {
          $this->checkAvailability($formState, $fieldKey, $dayNumber);
        }
      }
    }
  }

  private function checkAvailability(FormStateInterface $formState, string $fieldKey, int $dayNumber) {
    $customFieldName = $this->presenceOfDay[$dayNumber][1];

    $participants = \Civi\Api4\Participant::get(FALSE)
      ->selectRowCount()
      ->addWhere('event_id', '=', $this->eventId)
      ->addWhere("Participant_Presence.$customFieldName", '=', $this->willTakePartInTheMeetingRoom)
      ->addWhere('status_id', 'IN', [1, 2])
      ->execute();

    if ($participants->countMatched() >= $this->maxPhysicalParticipants) {
      $formState->setErrorByName($fieldKey, 'ERROR: Your submission is not accepted! Please note that the number of seats in the meeting room is restricted, therefore if you can no longer opt for attendance in person you can still attend the event ONLINE. If you would prefer to take part in person, you can still indicate this in the notes field. In case some other participants cancel their attendance, we will take your preference into account and will let you know if you can participate in the meeting room. Thank you for your understanding.');
    }
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

  private function isFieldYourPresence($fieldKey) {
    foreach ($this->presenceOfDay as $dayNumber => $customFieldIdAndName) {
      if (strpos($fieldKey, $customFieldIdAndName[0]) > 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function getDayNumber($fieldKey) {
    foreach ($this->presenceOfDay as $dayNumber => $customFieldIdAndName) {
      if (strpos($fieldKey, $customFieldIdAndName[0]) > 0) {
        return $dayNumber;
      }
    }

    return 0;
  }

  private function willThePersonAttendOnDay(FormStateInterface $formState, int $dayNumber) {
    // loop over the submitted fields
    $submittedFields = $formState->getValues();
    foreach ($submittedFields as $fieldKey => $fieldValue) {
      if ($this->isFieldWillYouAttendOnDay($fieldKey, $dayNumber)) {
        if ($fieldValue == 1) {
          return TRUE;
        }
        else {
          return FALSE;
        }
      }
    }

    // we don't have the field, so we assume he/she will attend
    return TRUE;
  }

  private function isFieldWillYouAttendOnDay($fieldKey, $dayNumber) {
    if (strpos($fieldKey, $this->willYouAttendDay[$dayNumber][0]) > 0) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

}
