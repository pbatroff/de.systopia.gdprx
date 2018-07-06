<?php
/*-------------------------------------------------------+
| SYSTOPIA GDPR Compliance Extension                     |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: B. Endres (endres@systopia.de)                 |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

/**
 * Generic functions regarding the consent records
 */
class CRM_Gdprx_Consent {

  private static $category_list = NULL;
  private static $sources_list  = NULL;
  private static $types_list  = NULL;

  /**
   * Get a list id -> label for the categories
   */
  public static function getCategoryList() {
    if (self::$category_list === NULL) {
      self::$category_list = array();
      $query = civicrm_api3('OptionValue', 'get', array(
        'option_group_id' => 'consent_category',
        'option.limit'    => 0,
        'sequential'      => 1,
        'is_active'       => 1,
        'return'          => 'value,label'));
      foreach ($query['values'] as $option_value) {
        self::$category_list[$option_value['value']] = $option_value['label'];
      }
    }
    return self::$category_list;
  }

  /**
   * Get a list id -> label for the sources
   */
  public static function getSourceList() {
    if (self::$sources_list === NULL) {
      self::$sources_list = array();
      $query = civicrm_api3('OptionValue', 'get', array(
        'option_group_id' => 'consent_source',
        'option.limit'    => 0,
        'sequential'      => 1,
        'is_active'       => 1,
        'return'          => 'value,label'));
      foreach ($query['values'] as $option_value) {
        self::$sources_list[$option_value['value']] = $option_value['label'];
      }
    }
    return self::$sources_list;
  }

  /**
   * Get a list id -> label for the sources
   */
  public static function getTypeList() {
    if (self::$types_list === NULL) {
      self::$types_list = array();
      $query = civicrm_api3('OptionValue', 'get', array(
        'option_group_id' => 'consent_type',
        'option.limit'    => 0,
        'sequential'      => 1,
        'is_active'       => 1,
        'return'          => 'value,label'));
      foreach ($query['values'] as $option_value) {
        self::$types_list[$option_value['value']] = $option_value['label'];
      }
    }
    return self::$types_list;
  }

  /**
   * add a new user consent entry for the contact
   */
  public static function createConsentRecord($contact_id, $category, $source, $date = 'now', $note = '', $type = NULL, $terms_id = NULL, $expiry_date = NULL) {
    return self::updateConsentRecord('-1', $contact_id, $category, $source, $date, $note, $type, $terms_id, $expiry_date);
  }

  /**
   * update existing consent record
   */
  public static function updateConsentRecord($record_id, $contact_id, $category, $source, $date = 'now', $note = '', $type = NULL, $terms_id = NULL, $expiry_date = NULL) {
    CRM_Core_Error::debug_log_message("create/update consent record: {$contact_id}, {$category}, {$source}, {$date}, {$note} {$type} {$terms_id} {$expiry_date}");

    // look up SOURCE
    $original_source = $source;
    if (!is_numeric($source)) {
      $source = CRM_Core_OptionGroup::getValue('consent_source', $source, 'label');
    }
    if (empty($source)) {
      CRM_Core_Error::debug_log_message("Couldn't map source '{$original_source}'");
      return;
    }

    // look up CATEGORY
    $original_category = $category;
    if (!is_numeric($category)) {
      $category = CRM_Core_OptionGroup::getValue('consent_category', $category, 'label');
    }
    if (empty($category)) {
      CRM_Core_Error::debug_log_message("Couldn't map category '{$original_category}'");
      return;
    }

    // create record
    $data = array(
      'consent.consent_date'     => date('YmdHis', strtotime($date)),
      'consent.consent_category' => $category,
      'consent.consent_source'   => $source,
      'consent.consent_note'     => $note,
    );

    if (!empty($expiry_date)) {
      $data['consent.consent_expiry_date'] = date('YmdHis', strtotime($expiry_date));
    } else {
      $data['consent.consent_expiry_date'] = '';
    }

    if (!empty($type)) {
      $data['consent.consent_type'] = $type;
    } else {
      $data['consent.consent_type'] = '';
    }

    if (!empty($terms_id)) {
      $data['consent.consent_terms'] = $terms_id;
    } else {
      $data['consent.consent_terms'] = '';
    }

    // resolve custom fields
    CRM_Gdprx_CustomData::resolveCustomFields($data, array('consent'));

    // since this is a multi-entry group, we need to clarify the index (-1 = new entry)
    $request = array('entity_id' => $contact_id);
    foreach ($data as $key => $value) {
      $request[$key . ':'. $record_id] = $value;
    }

    return civicrm_api3('CustomValue', 'create', $request);
  }

  /**
   * Get a valid consent record.
   *
   * @param $contact_id
   * @param $category
   * @param $date
   * @param array $positive_types
   * @param array $negative_types
   *
   * @return the date of the given consent, or NULL if no currently valid consent recorded
   */
  public static function hasConsent($contact_id, $category, $positive = TRUE, $date = 'now', $positive_types = array(2,4,5), $negative_types = array(3)) {
    // if we're looking for negative consent, swap the search patterns
    if (!$positive) {
      $tmp = $positive_types;
      $positive_types = $negative_types;
      $negative_types = $tmp;
    }
    $contact_id = (int) $contact_id;
    $category = (int) $category;
    $date = date('YmdHis', strtotime($date));

    // build query
    $positive_types_list = implode(',', $positive_types);
    $negative_types_list = implode(',', $negative_types);
    $query_sql = "
    SELECT
      MAX(positive.date) last_positive_consent, 
      MAX(negative.date) last_negative_consent 
    FROM civicrm_contact contact
    LEFT JOIN civicrm_value_gdpr_consent positive ON positive.entity_id = contact.id 
                                                  AND positive.type IN ({$positive_types_list})
                                                  AND positive.category = {$category}
                                                  AND positive.date <= '{$date}'
    LEFT JOIN civicrm_value_gdpr_consent negative ON negative.entity_id = contact.id 
                                                  AND negative.type IN ({$negative_types_list})
                                                  AND negative.category = {$category}
                                                  AND negative.date <= '{$date}'
    WHERE contact.id = {$contact_id}
    GROUP BY contact.id;";
    $query = CRM_Core_DAO::executeQuery($query_sql);
    $query->fetch();
    if ($query->last_positive_consent) {
      if ($query->last_negative_consent) {
        // negative wins if the date is the same
        if ($query->last_positive_consent > $query->last_negative_consent
            || (!$positive && $query->last_positive_consent == $query->last_negative_consent)) {
          return $query->last_positive_consent;
        } else {
          return NULL;
        }
      } else {
        return $query->last_positive_consent;
      }
    } else {
      return NULL;
    }
  }

  /**
   * get a consent record by ID
   */
  public static function getRecord($id) {
    $data = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_value_gdpr_consent WHERE id = %1",
      array(1 => array($id, 'Integer')));
    if ($data->fetch()) {
      return array(
        'entity_id'           => $data->entity_id,
        'consent_date'        => $data->date,
        'consent_expiry_date' => $data->expiry,
        'consent_category'    => $data->category,
        'consent_source'      => $data->source,
        'consent_type'        => $data->type,
        'consent_terms'       => $data->terms_id,
        'consent_note'        => $data->note,
      );
    } else {
      return NULL;
    }
  }

  /**
   * get a consent record by contact_id
   */
  public static function getContactRecords($contact_id) {
    $data = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_value_gdpr_consent WHERE entity_id = %1",
      array(1 => array($contact_id, 'Integer')));
    $result = array();
    while ($data->fetch()) {
      $result[] = array(
        'entity_id'           => $data->entity_id,
        'consent_date'        => $data->date,
        'consent_expiry_date' => $data->expiry,
        'consent_category'    => $data->category,
        'consent_source'      => $data->source,
        'consent_type'        => $data->type,
        'consent_terms'       => $data->terms_id,
        'consent_note'        => $data->note,
      );
    }
    return $result;
  }
}
