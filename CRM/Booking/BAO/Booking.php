<?php

class CRM_Booking_BAO_Booking extends CRM_Booking_DAO_Booking {

   /**
   * static field for all the booking information that we can potentially export
   *
   * @var array
   * @static
   */
  static $_exportableFields = NULL;


  static function add(&$params){
    $bookingDAO = new CRM_Booking_DAO_Booking();
    $bookingDAO->copyValues($params);
    return $bookingDAO->save();
  }


    /**
   * takes an associative array and creates a booking object
   *
   * the function extract all the params it needs to initialize the create a
   * booking object. the params array could contain additional unused name/value
   * pairs
   *
   * @param array $params (reference ) an assoc array of name/value pairs
   *
   * @return object CRM_Booking_BAO_Booking object
   * @access public
   * @static
   */
  static function create(&$params) {


    $resources = $params['resources'];
    $adhocCharges = $params['adhoc_charges'];

    if($params['validate']){
      //TODO:: Validate resource
      //$result = array();
      //$isValid = CRM_Booking_BAO_Slot::validate($resources, $result);
      //if(!$result['isValid']){
        //return list of object that invalid
        ///return error message
      //}
    }
    unset($params['resources']);
    unset($params['version']);
    unset($params['adhoc_charges']);
    unset($params['validate']);
    $transaction = new CRM_Core_Transaction();
    $lineItem = array(
        'version' => 3,
        'sequential' => 1,
    );
    try{
      $booking = self::add($params);
      $bookingID = $booking->id;

      foreach ($resources as $key => $resource) {
        $slot = array(
          'version' => 3,
          'booking_id' => $bookingID,
          'config_id' => CRM_Utils_Array::value('configuration_id', $resource),
          'start' => CRM_Utils_Array::value('start_date', $resource),
          'end' => CRM_Utils_Array::value('end_date', $resource),
          'resource_id' =>  CRM_Utils_Array::value('resource_id', $resource),
          'quantity' => CRM_Utils_Array::value('quantity', $resource),
          'note' => CRM_Utils_Array::value('note', $resource),
        );
        $slotResult = civicrm_api('Slot', 'Create', $slot);
        $slotID =  CRM_Utils_Array::value('id', $slotResult);

        $subResources = $resource['sub_resources'];
        foreach($subResources as $subKey => $subResource){
          $subSlot = array(
            'version' => 3,
            'resource_id' =>  CRM_Utils_Array::value('resource_id', $subResource),
            'slot_id' => $slotID,
            'config_id' => CRM_Utils_Array::value('configuration_id', $subResource),
            'time_required' =>  CRM_Utils_Array::value('time_required', $subResource),
            'quantity' => CRM_Utils_Array::value('quantity', $subResource),
            'note' => CRM_Utils_Array::value('note', $subResource),
          );
          $subSlotResult = civicrm_api('SubSlot', 'Create', $subSlot);
        }
      }
      if($adhocCharges){
        $items = CRM_Utils_Array::value('items', $adhocCharges);
        foreach ($items as $key => $item) {
          $params = array(
            'version' => 3,
            'booking_id' =>  $bookingID,
            'item_id' => CRM_Utils_Array::value('id', $item),
            'quantity' => CRM_Utils_Array::value('quantity', $item),
          );
          civicrm_api('AdhocCharges', 'create', $params);
        }
      }
      return $booking;
    }catch (Exception $e) {
      $transaction->rollback();
      CRM_Core_Error::fatal($e->getMessage());
    }

  }

  static function recordContribution($values){
    $bookingID = CRM_Utils_Array::value('booking_id', $values);
    if(!CRM_Utils_Array::value('booking_id', $values)){
      return;
    }else{
      try{
       $transaction = new CRM_Core_Transaction();
       $params = array(
          'version' => 3,
          'sequential' => 1,
          'contact_id' => CRM_Utils_Array::value('payment_contact', $values),
          'financial_type_id' => CRM_Utils_Array::value('financial_type_id', $values),
          'total_amount' =>  CRM_Utils_Array::value('total_amount', $values),
          'payment_instrument_id' =>  CRM_Utils_Array::value('payment_instrument_id', $values),
          'receive_date' =>  CRM_Utils_Array::value('receive_date', $values),
          'contribution_status_id' =>  CRM_Utils_Array::value('contribution_status_id', $values),
          'source' => CRM_Utils_Array::value('booking_title', $values),
          'trxn_id' =>  CRM_Utils_Array::value('trxn_id', $values),
        );
        $contribution = civicrm_api('Contribution', 'create', $params);
        $contributionId = CRM_Utils_Array::value('id', $contribution);
        if($contributionId){
          $payment = array('booking_id' => $bookingID, 'contribution_id' => $contributionId);
          CRM_Booking_BAO_Payment::create($payment);
        }

        $result = civicrm_api('Slot', 'get', array('version' => 3, 'booking_id' => $bookingID));
        $slots = CRM_Utils_Array::value('values', $result);
        $lineItem = array('version' => 3, 'financial_type_id' => CRM_Utils_Array::value('financial_type_id', $values));
        foreach ($slots as $slot) {
          $slotID = $slot['id'];

          $lineItem['entity_table'] = "civicrm_booking_slot";
          $lineItem['entity_id'] = $slotID;

          $configId =  CRM_Utils_Array::value('config_id', $slot);
          $configResult = civicrm_api('ResourceConfigOption', 'get', array('version' => 3, 'id' => $configId));
          $config = CRM_Utils_Array::value('values', $configResult);
          $lineItem['label'] = CRM_Utils_Array::value('label', $config[$configId]);

          $unitPrice = CRM_Utils_Array::value('price', $config[$configId]);
          $lineItem['unit_price'] = $unitPrice;
          $qty = CRM_Utils_Array::value('quantity', $slot);

          $lineItem['qty'] = $qty;
          $lineItem['line_total'] =  $unitPrice * $qty;
          $lineItemResult = civicrm_api('LineItem', 'create', $lineItem);
          $result = civicrm_api('SubSlot', 'get', array('version' => 3 ,'slot_id' => $slotID));
          $subSlots = CRM_Utils_Array::value('values', $result);
          foreach ($subSlots as $subSlot) {

            $subSlotID = $subSlot['id'];

            $lineItem['entity_table'] = "civicrm_booking_sub_slot";
            $lineItem['entity_id'] = $subSlotID;
            $configId =  CRM_Utils_Array::value('config_id', $slot);
            $configResult = civicrm_api('ResourceConfigOption', 'get', array('version' => 3, 'id' => $configId));
            $config = CRM_Utils_Array::value('values', $configResult);
            $lineItem['label'] = CRM_Utils_Array::value('label', $config[$configId]);

            $unitPrice = CRM_Utils_Array::value('price', $config[$configId]);
            $lineItem['unit_price'] = $unitPrice;
            $qty = CRM_Utils_Array::value('quantity', $slot);

            $lineItem['qty'] = $qty;
            $lineItem['line_total'] =  $unitPrice * $qty;
            $lineItemResult = civicrm_api('LineItem', 'create', $lineItem);
          }
        }

        $adhocChargesResult = civicrm_api('AdhocCharges', 'get', array('version' => 3, 'booking_id' => $bookingID));
        $adhocChargesValues = CRM_Utils_Array::value('values', $adhocChargesResult);
        foreach ($adhocChargesValues as $id => $adhocCharges) {

          $lineItem['entity_table'] = "civicrm_booking_adhoc_charges";
          $lineItem['entity_id'] = $id;
          $itemId =  CRM_Utils_Array::value('item_id', $adhocCharges);
          $itemResult = civicrm_api('AdhocChargesItem', 'get', array('version' => 3, 'id' => $itemId));
          $itemValue = CRM_Utils_Array::value('values', $itemResult);
          $lineItem['label'] = CRM_Utils_Array::value('label', $itemValue[$itemId]);

          $unitPrice = CRM_Utils_Array::value('price', $itemValue[$itemId]);
          $lineItem['unit_price'] = $unitPrice;
          $qty = CRM_Utils_Array::value('quantity', $adhocCharges);

          $lineItem['qty'] = $qty;
          $lineItem['line_total'] =  $unitPrice * $qty;
          $lineItemResult = civicrm_api('LineItem', 'create', $lineItem);
        }

      }catch (Exception $e) {
          $transaction->rollback();
          CRM_Core_Error::fatal($e->getMessage());
      }
    }

  }

    /**
   * Takes a bunch of params that are needed to match certain criteria and
   * retrieves the relevant objects. It also stores all the retrieved
   * values in the default array
   *
   * @param array $params   (reference ) an assoc array of name/value pairs
   * @param array $defaults (reference ) an assoc array to hold the flattened values
   *
     * @return object CRM_Booking_DAO_Booking object on success, null otherwise
   * @access public
   * @static
   */
  static function retrieve(&$params, &$defaults) {
    $dao = new CRM_Booking_DAO_Booking();
    $dao->copyValues($params);
    if ($dao->find(TRUE)) {
      CRM_Core_DAO::storeValues($dao, $defaults);
      return $dao;
    }
    return NULL;
  }

  /**
   * Function to delete Booking
   *
   * @param  int  $id     Id of the Resoruce to be deleted.
   *
   * @return boolean
   *
   * @access public
   * @static
   */
  static function del($id) {
    $dao = new CRM_Booking_DAO_Booking();
    $dao->id = $id;
    $dao->is_deleted = 1;
    return $dao->save();
  }


  /**
   * Given the list of params in the params array, fetch the object
   * and store the values in the values array
   *
   * @param array $params input parameters to find object
   * @param array $values output values of the object
   *
   * @return CRM_Event_BAO_ฺBooking|null the found object or null
   * @access public
   * @static
   */
  static function getValues(&$params, &$values, &$ids) {
    if (empty($params)) {
      return NULL;
    }
    $booking = new CRM_Booking_DAO_Booking();
    $booking->copyValues($params);
    $booking->find();
    $bookings = array();
    while ($booking->fetch()) {
      $ids['booking'] = $booking->id;
      CRM_Core_DAO::storeValues($booking, $values[$booking->id]);
      $bookings[$booking->id] = $booking;
    }
    return $bookings;
  }

  static function getBookingContactCount($contactId){
    $params = array(1 => array( $contactId, 'Integer'));
    $query = "SELECT COUNT(DISTINCT(id)) AS count  FROM civicrm_booking WHERE primary_contact_id = %1";
    return CRM_Core_DAO::singleValueQuery($query, $params);
  }

  static function getPaymentStatus($id){
    $params = array(1 => array( $id, 'Integer'));
    $query = "SELECT civicrm_option_value.label as status
              FROM civicrm_booking
              LEFT JOIN civicrm_booking_payment ON civicrm_booking_payment.booking_id = civicrm_booking.id
              LEFT JOIN civicrm_contribution ON civicrm_contribution.id = civicrm_booking_payment.contribution_id
              LEFT JOIN civicrm_option_value ON civicrm_option_value.value = civicrm_contribution.contribution_status_id
              LEFT JOIN civicrm_option_group ON civicrm_option_group.name = 'contribution_status'
                                             AND civicrm_option_group.id = civicrm_option_value.option_group_id
              WHERE civicrm_booking.id = %1";
    return CRM_Core_DAO::singleValueQuery($query, $params);

  }


   /**
   * Get the values for pseudoconstants for name->value and reverse.
   *
   * @param array   $defaults (reference) the default values, some of which need to be resolved.
   * @param boolean $reverse  true if we want to resolve the values in the reverse direction (value -> name)
   *
   * @return void
   * @access public
   * @static
   */
  static function resolveDefaults(&$defaults, $reverse = FALSE) {
    self::lookupValue($defaults, 'status', CRM_Booking_BAO_Booking::buildOptions('status_id', 'create'), $reverse);
  }

  /**
   * This function is used to convert associative array names to values
   * and vice-versa.
   *
   * This function is used by both the web form layer and the api. Note that
   * the api needs the name => value conversion, also the view layer typically
   * requires value => name conversion
   */
  static function lookupValue(&$defaults, $property, &$lookup, $reverse) {
    $id = $property . '_id';

    $src = $reverse ? $property : $id;
    $dst = $reverse ? $id : $property;

    if (!array_key_exists($src, $defaults)) {
      return FALSE;
    }

    $look = $reverse ? array_flip($lookup) : $lookup;

    if (is_array($look)) {
      if (!array_key_exists($defaults[$src], $look)) {
        return FALSE;
      }
    }
    $defaults[$dst] = $look[$defaults[$src]];
    return TRUE;
  }


  /**
   * Get the exportable fields for Booking
   *
   *
   * @return array array of exportable Fields
   * @access public
   * @static
   */
  static function &exportableFields() {
    if (!isset(self::$_exportableFields["booking"])) {
      self::$_exportableFields["booking"] = array();

      $exportableFields = CRM_Booking_DAO_Booking::export();

      $bookingFields = array(
        'booking_title' => array('title' => ts('Title'), 'type' => CRM_Utils_Type::T_STRING),
        'booking_po_no' => array('title' => ts('PO Number'), 'type' => CRM_Utils_Type::T_STRING),
        'booking_status' => array('title' => ts('Booking Status'), 'type' => CRM_Utils_Type::T_STRING),
        'booking_payment_status' => array('title' => ts('Booking Status'), 'type' => CRM_Utils_Type::T_STRING),
      );

      $fields = array_merge($bookingFields, $exportableFields);

      self::$_exportableFields["booking"] = $fields;
    }
    return self::$_exportableFields["booking"];
  }


  static function getBookingAmount($id){
    if(!$id){
      return;
    }
    $bookingAmount = array(
      'resource_fees' => 0,
      'sub_resource_fees' => 0,
      'adhoc_charges_fees' => 0,
      'discount_amount' => 0,
      'total_amount' => 0,
    );
    self::retrieve($params = array('id' => $id), $booking);
    $bookingAmount['discount_amount'] = CRM_Utils_Array::value('discount_amount', $booking);
    $bookingAmount['total_amount'] = CRM_Utils_Array::value('total_amount', $booking);
    $slots = CRM_Booking_BAO_Slot::getBookingSlot($id);
    $subSlots = array();
    foreach ($slots as $key => $slot) {
      $subSlotResult = CRM_Booking_BAO_SubSlot::getSubSlotSlot($slot['id']);
      foreach ($subSlotResult as $key => $subSlot) {
        $subSlots[$key] = $subSlot;
      }
    }
    $adhocCharges = CRM_Booking_BAO_AdhocCharges::getBookingAdhocCharges($id);
    CRM_Booking_BAO_Payment::retrieve($params = array('booking_id' => $id), $payment);
    if(!empty($payment) && isset($payment['contribution_id'])){ // contribution exit so get all price from line item
      /*
      $params = array(
        'version' => 3,
        'id' => $payment['contribution_id'],
        );
      $result = civicrm_api('Contribution', 'get', $params);
      $contribution = CRM_Utils_Array::value($payment['contribution_id'], $result['values'] );
      $bookingAmount['total_amount']  = CRM_Utils_Array::value('total_amount', $contribution);
      */
      foreach ($slots as $slotId => $slot) {
        $params = array(
          'version' => 3,
          'entity_id' => $slotId,
          'entity_table' => 'civicrm_booking_slot',
        );
        $result = civicrm_api('LineItem', 'get', $params);
        $lineItem = CRM_Utils_Array::value($result['id'], $result['values']);
        $bookingAmount['resource_fees']  += CRM_Utils_Array::value('line_total', $lineItem);
      }
      foreach ($subSlots as $subSlotId => $subSlots) {
        $params = array(
          'version' => 3,
          'entity_id' => $subSlotId,
          'entity_table' => 'civicrm_booking_sub_slot',
        );
        $result = civicrm_api('LineItem', 'get', $params);
        $lineItem = CRM_Utils_Array::value($result['id'], $result['values']);
        $bookingAmount['sub_resource_fees']  += CRM_Utils_Array::value('line_total', $lineItem);
      }
      foreach ($adhocCharges as $charges) {
        $params = array(
          'version' => 3,
          'entity_id' => CRM_Utils_Array::value('id', $charges),
          'entity_table' => 'civicrm_booking_adhoc_charges',
        );
        $result = civicrm_api('LineItem', 'get', $params);
        $lineItem = CRM_Utils_Array::value($result['id'], $result['values']);
        $bookingAmount['adhoc_charges_fees']  += CRM_Utils_Array::value('line_total', $lineItem);
      }
    }else{
      foreach ($slots as $id => $slot) {
        $bookingAmount['resource_fees'] += self::_calulateSlotPrice(CRM_Utils_Array::value('config_id', $slot) ,CRM_Utils_Array::value('quantity', $slot));
      }
      foreach ($subSlots as $id => $subSlot) {
        $bookingAmount['sub_resource_fees'] += self::_calulateSlotPrice(CRM_Utils_Array::value('config_id', $subSlot) ,CRM_Utils_Array::value('quantity', $subSlot));
      }
      foreach ($adhocCharges as $charges) {
        $price = CRM_Core_DAO::getFieldValue('CRM_Booking_DAO_AdhocChargesItem',
          CRM_Utils_Array::value('item_id', $charges) ,
          'price',
          'id'
        );
        $bookingAmount['adhoc_charges_fees'] += ($price * CRM_Utils_Array::value('quantity', $charges));
      }
    }
    return $bookingAmount;
  }

  static function _calulateSlotPrice($configId, $qty){
    if(!$configId & !$qty){
      return NULL;
    }
    $price = CRM_Core_DAO::getFieldValue('CRM_Booking_DAO_ResourceConfigOption',
      $configId,
      'price',
      'id'
    );
    return $price * $qty;
  }

  static function createActivity($params){
    //$session =& CRM_Core_Session::singleton( );
    //$userId = $session->get( 'userID' ); // which is contact id of the user
    //self::retrieve($params = array('id' => $bookingID), $booking);
    $params = array(
      'version' => 3,
      'option_group_name' => 'activity_type',
      'name' => 'booking_acivity_booking',
    );
    $optionValue = civicrm_api('OptionValue', 'get', $params);
    $activityTypeId = $optionValue['values'][$optionValue['id']]['value'];
    $params = array(
      'version' => 3,
      /*'source_contact_id' => $userId,*/ //api should pick the loggin id automatically
      'activity_type_id' => $activityTypeId,
      'subject' =>  CRM_Utils_Array::value('title', $params),
      'activity_date_time' => date('YmdHis'),
      'target_contact_id' => CRM_Utils_Array::value('target_contact_id', $params),
      'status_id' => 2,
      'priority_id' => 1,
    );
    $result = civicrm_api('Activity', 'create', $params);
  }


  /**
   * Process that send e-mails
   *
   * @return void
   * @access public
   */
  static function sendMail($contactID, &$values, $isTest = FALSE, $returnMessageText = FALSE) {
    //TODO:: check if from email address is entered
    $config = CRM_Booking_BAO_BookingConfig::getConfig();

    $template = CRM_Core_Smarty::singleton();

    list($displayName, $email) = CRM_Contact_BAO_Contact_Location::getEmailDetails($contactID);

    //send email only when email is present
    if ($email) {

      $tplParams = array(
        'email' => $email,
        //TODO:: build the booking tpl
      );

      $sendTemplateParams = array(
        'groupName' => 'msg_tpl_workflow_booking',
        'valueName' => 'booking_offline_receipt',
        'contactId' => $contactID,
        'isTest' => $isTest,
        'tplParams' => $tplParams,
        'PDFFilename' => 'bookingReceipt.pdf',
      );

      if(CRM_Utils_Array::value('include_payment_info', $values)){
        //TODO: add contribution detail
        $sendTemplateParams['tplParams']['contribution'] = NULL;
      }

      // address required during receipt processing (pdf and email receipt)
      //TODO:: add addresss
      if ($displayAddress = CRM_Utils_Array::value('address', $values)) {
        $sendTemplateParams['tplParams']['address'] = $displayAddress;
      }

      //TODO:: add line titem tpl params
      if ($lineItem = CRM_Utils_Array::value('lineItem', $values)) {
        $sendTemplateParams['tplParams']['lineItem'] = $lineItem;
      }

      $sendTemplateParams['from'] =  $values['from_email_address'];
      $sendTemplateParams['toName'] = $displayName;
      $sendTemplateParams['toEmail'] = $email;
      //$sendTemplateParams['autoSubmitted'] = TRUE;
      $cc = CRM_Utils_Array::value('cc_email_address', $config);
      if($cc){
        $sendTemplateParams['cc'] = $cc;
      }
      $bcc = CRM_Utils_Array::value('bcc_email_address', $config);
      if($bcc){
        $sendTemplateParams['bcc'] = $bcc;
      }
      list($sent, $subject, $message, $html)  = CRM_Core_BAO_MessageTemplate::sendTemplate($sendTemplateParams);
      if($sent & CRM_Utils_Array::value('log_confirmation_email', $config)){
          $params = array(
            'version' => 3,
            'option_group_name' => 'activity_type',
            'name' => 'Email',
          );
          $optionValue = civicrm_api('OptionValue', 'get', $params);
          $activityTypeId = $optionValue['values'][$optionValue['id']]['value'];
          $params = array(
            'version' => 3,
            /*'source_contact_id' => $values['source_contact_id'],*/
            'activity_type_id' => $activityTypeId,
            'subject' => ts('Booking Confirmation Email'),
            'activity_date_time' => date('YmdHis'),
            'target_contact_id' => $contactID,
            'status_id' => 2,
            'priority_id' => 1,
          );
          $result = civicrm_api('Activity', 'create', $params);
       }
      if ($returnMessageText) {
        return array(
          'subject' => $subject,
          'body' => $message,
          'to' => $displayName,
          'html' => $html,
        );
      }
    }
  }
}
