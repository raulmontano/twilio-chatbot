<?php

namespace Inbenta\TwilioConnector;

use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;

class ContinuaChatbotAPIClient extends ChatbotAPIClient
{

	public function startConversation($conf = array(), $userType = 0, $environment = 'development', $source = null)
  {

      $file = __DIR__ . '/../../logs/conv' . '.' . date('Ymd') .'.log';
      @file_put_contents($file, "Iniciando conversacion" . PHP_EOL , FILE_APPEND | LOCK_EX);

  		$return = parent::startConversation($conf,$userType,$environment,$source);

  		if($return){
        @file_put_contents($file, "Conversacion iniciada" . PHP_EOL , FILE_APPEND | LOCK_EX);

  			$this->session->set('conversationStarted', TRUE);
  		} else {
        @file_put_contents($file, "Conversacion NO iniciada" . PHP_EOL , FILE_APPEND | LOCK_EX);
      }

  		return $return;

  }

}
