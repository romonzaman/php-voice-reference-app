<?php

// SIP call processing using Bandwidth.com API
//
// this examples shows how to implement 
// 
// http://ap.bandwidth.com/docs/how-to-guides/use-endpoints-make-receive-calls-sip-clients/
//
//
// Helpers:
// 1. To distinguish PSTN from registrar
// use type Catapult SIP with isValid/0
//
// 2. To figure out if a number is registered
// under your account use Catapult\PhoneNumbers
// with isEmpty/1
//
//
// This can be called as follows:
//
//
// {your_server}.tld/callback/{username}
// OR 
// {your_server}.tld/users/
// with POST "username={your_username}&password={your_password}":
//
// make sure you call /users/
// before using the /callback/
require_once(__DIR__."/config.php");
require_once(__DIR__."/create.php");

try {
  // go through our creation process
  // more on this in create.php
  //
  // our username and password
  // will be found in the header
  // 
  // FIXME
  // this url is publicly accessible
  // optionally look for the auth 
  // 
  // 
  // fix: only recognize POST methods
  // on the creation of /users
  if (!isset($_REQUEST['callback'])) {
    $jsonInput = json_decode(file_get_contents("php://input"));
    if (is_object($jsonInput)) {
      if (isset($jsonInput->userName) && isset($jsonInput->password)) {
        $result = createIfNeeded($jsonInput->userName, $jsonInput->password);
        // when the result is false
        // we should exit
        // 
        // as our SIP client was not setup properly

        if (is_int($result) && $result == SIP_APPLICATION_SCRIPT_ERROR) {
          // something is not right with our script
          showError(sprintf("Something went wrong in storing your user contents, make sure they are properly encoded, for more info please view", GITHUB_URL));
        } elseif (is_int($result) && $result == SIP_APPLICATION_USER_FOUND_WRONG_PASSWORD) {
          showError(sprintf("The user %s was already registered this password is not correct", $headers['username']));

        } elseif (is_int($result) && $result == SIP_APPLICATION_PHONE_NUMBER_NOT_FOUND) {
          showError(sprintf("No phone numbers were found in area code: %s", DEFAULT_AREA_CODE));

        } else {
          // send our headers and information
          // the creation was a success
          //
          //header("location: ");
       
          // don't reencode the server response 
          if (is_array($result) || is_object($result)) {
          echo json_encode($result);
          } else {
          echo $result;
          }

        }
      } else { 
        showError("You have not set either userName or password in your JSON document");
      }
    } else {
      // userName and password
      // need to be provided
      // we will warn here
      showError("Content-type must be JSON ");
    }
  } else {
    // This segment handles
    // our callbacks these will
    // only work once the userApplication
    // has been setup
    //
    //
    // url should be as follows:
 
    // https|http://{your_server}.tld/callback/{username}
    //

    // check which user
    // is making this call from the username
    //
    $user = getUser($_REQUEST['username']);
    // todo when this is not
    // a valid user do not process
    //
    //

    // Our two state events
    // these will both be used in creating
    // this userApplication
    //
    // our answer which  listens
    // to registrar 
    
    $client = new Catapult\Client;
    $answerCallEvent = new Catapult\AnswerCallEvent;
    $incomingCallEvent = new Catapult\IncomingCallEvent;
    $hangupCallEvent = new Catapult\HangupCallEvent;
    // Standard execution:
    //
    // we need to check whether 
    // a PSTN is making the call or 
    // whether the call is being made
    // by our SIP client. The switch on
    // this will validate what's needed
    // as per our incoming and answer call
    // events

    if ($answerCallEvent->isActive()) {
       $sipOrPSTNTo = $answerCallEvent->to;
       $sipOrPSTNFrom = $answerCallEvent->from;
       // check whether this is coming 
       // from our pstn, if it is we need to 
       // bridge our calls
       if ($sipOrPSTNFrom == $user->phoneNumber) {
        // bridge
        // the sip
        // calls
        $call = new Catapult\Call($answerCallEvent->callId);
        $callCollection = new Catapult\CallCollection;
        // find our opposite direction
        //
        $lastSIPCall = $callCollection->listAll()->find(array("from" => $user->endpoint->sipUri))->first();
        
        // now bridge these
        // two
        $bridge = new Catapult\Bridge(array(
          "callIds" => array($call->id, $lastSIPCall->id),
          "bridgeAudio" => TRUE
        ));
       } 
       // our sip is being called
       if ($sipOrPSTNTo == $user->endpoint->sipUri) {

          // get our last call
          // from the PSTN 
          //
          $call = new Catapult\Call($answerCallEvent->callId);
          // get our last call
          // from the pstn and bridge
          $callCollection = new Catapult\CallCollection;
          $call = new Catapult\Call($answerCallEvent->callId);
          $lastPSTNCall = $callCollection->listAll()->find(array("to" => $user->phoneNumber))->first();
 
          // bridge our incoming and 
          // outgoing calls
          //
          $bridge = new Catapult\Bridge(array(
            "callIds" => array($call->id, $lastPSTNCall->id),
            "bridgeAudio" => TRUE
          ));
        } 
    }

    // handle incoming requests
    // NOTE:
    //
    // this userApplication will be autoAnswer by default
    // in order to activate the sequence below
    // you will need to set autoAnswer = false
    //
    // IMPORTANT
    // our callback url must be able
    // to detect subdomains
   
     if ($incomingCallEvent->isActive()) {

     $sipOrPSTNFrom = $incomingCallEvent->from;
     $sipOrPSTNTo = $incomingCallEvent->to;
     if ($sipOrPSTNFrom == $user->endpoint->sipUri) {
        $call = new Catapult\Call($incomingCallEvent->callId);
        if ($call->state == Catapult\CALL_STATES::started) {
          $call->accept();
        }
        
        // using our other PSTN number
        // we can create a call to this 'to'
        // pstn
        $newCall = new Catapult\Call(array(
          "from" => $user->phoneNumber,
          "to" => $incomingCallEvent->to,
          "callbackUrl" => $_SERVER['HTTP_HOST'] . preg_replace("/\/.*$/", "", $_SERVER['REQUEST_URI']) . "/" . sprintf("callback/%s", $user->username)
        ));
      } 

      // a PSTN has called this catapult
      // number, bridge with sip
      if ($sipOrPSTNTo == $user->phoneNumber) {
        $call = new Catapult\Call($incomingCallEvent->callId);
        if ($call->state == Catapult\CALL_STATES::started) {
          $call->accept();
        }

        $call = new Catapult\Call(array(
          "from" => $user->phoneNumber,
          "to" => $user->endpoint->sipUri,
          "callbackUrl" => $_SERVER['HTTP_HOST'] . preg_replace("/\/.*$/", "", $_SERVER['REQUEST_URI']) . "/" . sprintf("callback/%s", $user->username)
        ));
      } 
    }

    // on hangup, deal with each call from 
    // PSTN and SIP
    // make no assertions whether we're in inbound or outbound mode
    if ($hangupCallEvent->isActive()) {
      $PSTNCollection = new Catapult\CallCollection;
      $SIPCollection = new Catapult\CallCollection;
      // match both ways for
      // both
      $PSTNCollection->listAll()->find(array("from" => $user->phoneNumber));
      $SIPCollection->listAll()->find(array("from" => $user->endpoint->sipUri));
     
      $merged = array_merge($PSTNCollection->get(), $SIPCollection->get());  
      foreach ($merged as $call) {
        if ($call->state == Catapult\CALL_STATES::active) {
          $call->hangup();
        }
      }
    }

  }
} catch (CatapultApiException $e) {
  $error = $e->getResult();
  // let's log this
  // attempt
  //

}
?>
