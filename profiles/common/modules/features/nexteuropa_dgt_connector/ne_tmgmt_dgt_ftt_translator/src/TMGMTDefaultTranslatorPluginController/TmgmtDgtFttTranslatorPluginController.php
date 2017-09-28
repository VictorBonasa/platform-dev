<?php
/**
 * @file
 * Provides Next Europa TMGMT DGT FTT translator plugin controller.
 */

namespace Drupal\ne_tmgmt_dgt_ftt_translator\TMGMTDefaultTranslatorPluginController;

use Drupal\ne_tmgmt_dgt_ftt_translator\Entity\DgtFttTranslatorMapping;
use \EC\Poetry;
use \EntityFieldQuery;
use \TMGMTDefaultTranslatorPluginController;
use \TMGMTTranslator;
use \TMGMTJob;

/**
 * TMGMT DGT FTT translator plugin controller.
 */
class TmgmtDgtFttTranslatorPluginController extends TMGMTDefaultTranslatorPluginController {
  /**
   * Translator mapping entity type.
   */
  const DGT_FTT_TRANSLATOR_MAPPING_ENTITY_TYPE = 'ne_tmgmt_dgt_ftt_map';

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::isAvailable().
   */
  public function isAvailable(TMGMTTranslator $translator) {
    // @todo: check if it's not easier to provide those variables in this
    // module. Variables are provided for the whole environment.
    // Checking if the common global configuration variables are available.
    // if ($this->checkPoetryServiceSettings()) {
    //  return FALSE;
    // }.
    $dgt_ftt_settings = array('settings', 'organization', 'contacts');
    $all_settings = array();

    // Get setting value for each setting.
    foreach ($dgt_ftt_settings as $setting) {
      $all_settings[$setting] = $translator->getSetting($setting);
    }

    // If any of these are empty, the translator is not properly configured.
    foreach ($all_settings as $value) {
      if (empty($value)) {
        return FALSE;
      }
    };

    return TRUE;
  }

  /**
   * Checks if the global Poetry Service settings are available.
   *
   * @return bool
   *   TRUE/FALSE depending on the check result.
   */
  private function checkPoetryServiceSettings() {
    $poetry_service = array(
      'address',
      'method',
    );
    $poetry_hard_settings = variable_get('poetry_service');

    // If the configuration in the settings.php is missing don't check further.
    if (empty($poetry_hard_settings)) {

      return FALSE;
    }

    // If one of the arg is not set, don't check further.
    foreach ($poetry_service as $service_arg) {
      if (!isset($poetry_hard_settings[$service_arg])) {

        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function canTranslate(TMGMTTranslator $translator, TMGMTJob $job) {
    // Anything can be exported.
    return TRUE;
  }

  /**
   * Custom method which sends the review request to the DGT Service.
   *
   * @param \TMGMTJob $job
   *   TMGMT Job object.
   * @param string $entity_id
   *   Entity ID (for some edge cases the string type can appear).
   * @param string $entity_type
   *   Entity type.
   *
   * @return bool
   */
  public function requestReview(TMGMTJob $job, $entity_id, $entity_type = 'node') {
    if ($this->checkRequestReviewConditions($job, $entity_id, $entity_type)) {
      $identifier = $this->getRequestIdentifier($job, $entity_id);
      // If the 'number' key is set we are going to send the review request.
      if (isset($identifier['identifier.number'])) {
        // Sending the 'review' request.
        $dgt_response = $this->sendReviewRequest($identifier);
        // Put there the reference ID
        $this->updateTmgmtJobAndJobItem();

      }

      // If the 'sequence' key is set there are no entries in the mapping table
      // or the 'part' counter value reached 99.
      if (isset($identifier['identifier.sequence'])) {
        $dgt_response = $this->sendNewNumberRequest($identifier);
        // Checking the DGT services response status.
        if($dgt_response->getStatuses[0]['code'] === 0) {
          // Creating a new mapping entity and performing the review request.
          $this->createDgtFttTranslatorMappingEntity($dgt_response, $identifier);
          $this->requestReview($job, $entity_id);

        }
        else {
          // Log the error or other details from the response to the watchdog.
        }
      }

    }

    return FALSE;
  }

  /**
   * Sends the 'review' request to the DGT Service.
   *
   * @param $identifier
   */
  private function sendReviewRequest($identifier) {
    // Instantiate the Poetry Client object.
    $poetry = new Poetry\Poetry($identifier);

    $data = [
      'details' => [

      ],
      'return_address' => [

      ],
      'source' => [

      ],
      'contact' => [

      ],
      'target' => [

      ],
    ];

    $message = $poetry->get('request.send_review_request');
    $message->withArray($data);

    $response = $poetry->getClient()->send($message);

  }

  /**
   * Sends the 'new number' request to the DGT Service.
   *
   * @param array $identifier
   *   An array with values which are required to instantiate OE Poetry client.
   * @return \EC\Poetry\Messages\Responses\Status
   */
  private function sendNewNumberRequest($identifier) {
    $poetry = new Poetry\Poetry($identifier);
    $message = $poetry->get('request.request_new_number');

    return $poetry->getClient()->send($message);
  }

  /**
   * Creates the DGT FTT Translator Mapping entity.
   *
   */
  private function createDgtFttTranslatorMappingEntity(Poetry\Messages\Responses\Status $response, $node) {

  }

  /**
   * Updating the TMGMT Job and TMGMT Job Item with data from the DGT response.
   */
  private function updateTmgmtJobAndJobItem() {

  }

  /**
   * Helper function to check if the job and content met request requirement.
   *
   * @param \TMGMTJob $job
   *   TMGMT Job object.
   * @param string $entity_id
   *   Entity ID (for some edge cases the string type can appear).
   * @param string $entity_type
   *   Entity type.
   *
   * @return bool
   *   TRUE/FALSE depending on conditions checks.
   */
  private function checkRequestReviewConditions(TMGMTJob $job, $entity_id, $entity_type = 'node') {
    // @todo: to be removed, check if it fits to canTranslate.
    return TRUE;
    // Checking if there aren't any translation processes for a given job and
    // content.
    if ($job->getState() === TMGMT_JOB_STATE_UNPROCESSED && !$this->getActiveTmgmtJobItemsIds($entity_id)) {

      return TRUE;
    }

    // Informing user that the review request can not be send.
    $error_message = t("Content type with following ID '@entity_id' and type
      '@entity_type' is currently included in one of the translation processes.
      You can not request the review for the content which is currently under
      translation process. Please finish ongoing processes for a given content
      and try again.",
      array('@entity_id' => $entity_id, '@entity_type' => $entity_type)
    );

    drupal_set_message($error_message, 'error');

    // Logging an error to the watchdog.
    watchdog('ne_tmgmt_dgt_ftt_translator',
      "Content type with following ID: '$entity_id' and type: '$entity_type' is
       currently included in one of the translation processes. The review
       request for the content which is under ongoing translation processes can
       not be send.",
      array(),
      WATCHDOG_ERROR
    );

    return FALSE;
  }

  /**
   * Provides an identifier array in order to send a request.
   *
   * @param \TMGMTJob $job
   *   TMGMT Job object.
   * @param $node_id
   *   Node id
   * @return array|bool
   */
  protected function getRequestIdentifier(TMGMTJob $job, $node_id) {
    // Getting the default values based on the configuration.
    $identifier = $this->getRequestIdentifierDefaults($job);
    // Setting up helper default values.
    $identifier['identifier.part'] = 0;
    $identifier['identifier.version'] = 0;
    $unset_key = 'identifier.number';

    // @todo: test the logic of this part.
    // Getting the latest mapping entity in order to set the part and number.
    if ($mapping_entity = $this->getLatestDgtFttTranslatorMappingEntity()) {
      // Checking if the 'part' counter value reached 99.
      if ($mapping_entity->part < 99) {
        $identifier['identifier.number'] = $mapping_entity->number;
        $identifier['identifier.part'] = $mapping_entity->part + 1;
        $unset_key = 'identifier.sequence';
      }
    }

    unset($identifier[$unset_key]);

    // Checking if there are mappings for the given content and setting version.
    if ($mapping_entity = $this->getDgtFttTranslatorMappingByProperty('entity_id', $node_id)) {
      $identifier['identifier.version'] = $mapping_entity->version + 1;
    }

    return $identifier;
  }

  /**
   * Provides the request identifier default values.
   *
   * @param \TMGMTJob $job
   *   TMGMT Job object.
   *
   * @return array
   *   An array with the identifier default values.
   */
  private function getRequestIdentifierDefaults(TMGMTJob $job) {
    // Getting a translator from the job.
    $translator = $job->getTranslator();
    // Getting translator settings.
    $settings = $translator->getSetting('settings');

    return array(
      'identifier.code' => $settings['dgt_code'],
      'identifier.year' => date("Y"),
      'identifier.sequence' => $settings['dgt_counter'],
      'client.wsdl' => _ne_tmgmt_dgt_ftt_translator_get_client_wsdl(),
      'service.wsdl' => 'http://intragate.test.ec.europa.eu/DGT/poetry_services/components/poetry.cfc?wsdl',
      'service.username' => $settings['dgt_ftt_username'],
      'service.password' => $settings['dgt_ftt_password'],
    );
  }

  /**
   * Provides the latest DGT FTT Translator Mapping entity.
   *
   * @return DgtFttTranslatorMapping/bool
   *   Entity or FALSE if there are no entries in the entity table.
   */
  private function getLatestDgtFttTranslatorMappingEntity() {
    // Querying for the latest entry based on the max id.
    $latest_entity_id = db_query("SELECT MAX(id) FROM {ne_tmgmt_dgt_ftt_map}")->fetchField();

    // Checking if we have any entries in the table.
    if (is_null($latest_entity_id)) {

      return FALSE;
    }

    return entity_load_single(self::DGT_FTT_TRANSLATOR_MAPPING_ENTITY_TYPE, $latest_entity_id);;
  }

  /**
   * Provides the latest 'ne_tmgmt_dgt_ftt_map' entity by passing a property
   * and its value.
   *
   * @param string $property_name
   *   Property name.
   * @param string $property_value
   *   Property value.
   *
   * @return bool|mixed
   */
  protected function getDgtFttTranslatorMappingByProperty($property_name, $property_value) {
    $query = new EntityFieldQuery();
    $query->entityCondition('entity_type', self::DGT_FTT_TRANSLATOR_MAPPING_ENTITY_TYPE)
      ->propertyCondition($property_name, $property_value);
    $result = $query->execute();

    if (isset($result[self::DGT_FTT_TRANSLATOR_MAPPING_ENTITY_TYPE])) {
      $mapping_ids = array_keys($result[self::DGT_FTT_TRANSLATOR_MAPPING_ENTITY_TYPE]);
      $mapping_entity_id = max($mapping_ids);
      $mapping_entity = entity_load_single(self::DGT_FTT_TRANSLATOR_MAPPING_ENTITY_TYPE, $mapping_entity_id);

      return $mapping_entity;
    }

    return FALSE;
  }

  /**
   * Provides an array with IDs of the TMGMT Job Items which are under
   * translation processes.
   *
   * @param string $entity_id
   *   Entity ID (for some edge cases the string type can appear).
   *
   * @return array|bool
   *   An array with IDs or FALSE if no items where found.
   */
  protected function getActiveTmgmtJobItemsIds($entity_id) {
    $query = new EntityFieldQuery();
    $query->entityCondition('entity_type', 'tmgmt_job_item')
      ->propertyCondition('item_id', $entity_id)
      ->propertyCondition('state', array(TMGMT_JOB_ITEM_STATE_ACTIVE, TMGMT_JOB_ITEM_STATE_REVIEW));

    $results = $query->execute();

    if (isset($results['tmgmt_job_item'])) {

      return array_keys($results['tmgmt_job_item']);
    }

    return FALSE;
  }

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::requestTranslation().
   */
  public function requestTranslation(TMGMTJob $job) {
    // TODO: Implement requestTranslation() method.
  }

}
