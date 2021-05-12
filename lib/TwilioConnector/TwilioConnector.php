<?php

namespace Inbenta\TwilioConnector;

use Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use Inbenta\TwilioConnector\ExternalAPI\TwilioAPIClient;
use Inbenta\TwilioConnector\ExternalDigester\TwilioDigester;
use Inbenta\TwilioConnector\HyperChatAPI\TwilioHyperChatClient;
use Inbenta\TwilioConnector\Helpers\Helper;

use Inbenta\TwilioConnector\MessengerAPI\MessengerAPI;

use Inbenta\TwilioConnector\ContinuaChatbotAPIClient;

class TwilioConnector extends ChatbotConnector
{
    public function __construct($appPath)
    {
        // Initialize and configure specific components for Twilio
        try {
            parent::__construct($appPath);

            // Initialize base components
            parse_str(file_get_contents('php://input'), $request);

            $conversationConf = [
                'configuration' => $this->conf->get('conversation.default'),
                'userType' => $this->conf->get('conversation.user_type'),
                'environment' => $this->environment,
                'source' => $this->conf->get('conversation.source')
            ];

            $this->session      = new SessionManager($this->getExternalIdFromRequest());
            $this->botClient    = new ContinuaChatbotAPIClient($this->conf->get('api.key'), $this->conf->get('api.secret'), $this->session, $conversationConf);

            // Try to get the translations from ExtraInfo and update the language manager
            $this->getTranslationsFromExtraInfo('twilio', 'translations');

            // Initialize Hyperchat events handler
            if ($this->conf->get('chat.chat.enabled') && ($this->session->get('chatOnGoing', false) || isset($_SERVER['HTTP_X_HOOK_SECRET']))) {
                $chatEventsHandler = new TwilioHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $this->externalClient);
                $chatEventsHandler->handleChatEvent();
            } else if (isset($_SERVER['HTTP_X_HOOK_SIGNATURE']) && $_SERVER['HTTP_X_HOOK_SIGNATURE'] == $this->conf->get('chat.messenger.webhook_secret')) {
                $messengerAPI = new MessengerAPI($this->conf, $this->lang, $this->session);
                $request = json_decode(file_get_contents('php://input'));
                $messengerAPI->handleMessageFromClosedTicket($request);
            }
            // Instance application components
            $externalClient        = new TwilioAPIClient($this->conf->get('twilio.credentials'), $request); // Instance Twilio client
            $chatClient            = new TwilioHyperChatClient($this->conf->get('chat.chat'), $this->lang, $this->session, $this->conf, $externalClient);  // Instance HyperchatClient for Twilio
            $externalDigester      = new TwilioDigester($this->lang, $this->conf->get('conversation.digester'), $this->session); // Instance Twilio digester

            $this->initComponents($externalClient, $chatClient, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }


    /**
     * Return external id from request (Hyperchat of Twilio)
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a Twilio message request
        $externalId = TwilioAPIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            // Try to get user_id from a Hyperchat event request
            $externalId = TwilioHyperChatClient::buildExternalIdFromRequest($this->conf->get('chat.chat'));
        }
        if (empty($externalId)) {
            $api_key = $this->conf->get('api.key');
            if (isset($_SERVER['HTTP_X_HOOK_SECRET'])) {
                // Create a temporary session_id from a HyperChat webhook linking request
                $externalId = "hc-challenge-" . preg_replace("/[^A-Za-z0-9 ]/", '', $api_key);
            } elseif (isset($_SERVER['HTTP_X_HOOK_SIGNATURE'])) {
                $externalId = "response-from-ticket";
            } else {
                throw new Exception("Invalid request");
                die();
            }
        }
        return $externalId;
    }

    /**
     * Return if only chat mode is active
     *
     * @return boolean
     */
    protected function isOnlyChat()
    {
        $onlyChatMode = false;
        $validateCustom = true;
        $extraInfoData = $this->botClient->getExtraInfo('twilio');
        if (isset($extraInfoData->results)) {
            // Get the settings data from extra info
            foreach ($extraInfoData->results as $element) {
                if ($element->name == 'settings') {
                    $onlyChatMode = isset($element->value->only_chat_mode) && $element->value->only_chat_mode === 'true' ? true : false;
                    $validateCustom = false;
                    break;
                }
            }
        }
        // Get data from configuration file if it has not been set on ExtraInfo
        if (!$onlyChatMode && $validateCustom) {
            $onlyChatMode = $this->conf->get('custom.onlyHyperChatMode', false);
        }
        return $onlyChatMode;
    }

    /**
     *	Override useless facebook function from parent
     */
    protected function returnOkResponse()
    {
        return true;
    }

    /**
     * Overwritten
     * 	Display content rating message and its options
     */
    protected function displayContentRatings($rateCode)
    {
        $ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
        $ratingMessage = $this->digester->buildContentRatingsMessage($ratingOptions, $rateCode);
        $this->session->set('askingRating', true);
        $this->session->set('rateCode', $rateCode);
        usleep(1300000); //Delay response, sleeps 1.3 seconds
        $this->externalClient->sendMessage($ratingMessage);
    }


    /**
     * Overwritten
     *	Check if it's needed to perform any action other than a standard user-bot interaction
     */
    protected function handleNonBotActions($digestedRequest)
    {
        // If there is a active chat, send messages to the agent
        if ($this->chatOnGoing()) {
            if ($this->isCloseChatCommand($digestedRequest)) {
                $chatData = [
                    'roomId' => $this->conf->get('chat.chat.roomId'),
                    'user' => [
                        'name' => $this->externalClient->getFullName(),
                        'contact' => $this->externalClient->getEmail(),
                        'externalId' => $this->externalClient->getExternalId(),
                        'extraInfo' => []
                    ]
                ];
                define('APP_SECRET', $this->conf->get('chat.chat.secret'));
                $this->chatClient->closeChat($chatData);
                $this->externalClient->sendTextMessage($this->lang->translate('chat_closed'));
                $this->session->set('chatOnGoing', false);
            } else {
                $this->sendMessagesToChat($digestedRequest);
            }
            die();
        }
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            $this->handleEscalation($digestedRequest);
        }

        // CUSTOM If user answered to a rating question, handle it
        if ($this->session->get('askingRating', false)) {
            $this->handleRating($digestedRequest);
        }

        // If the bot offered Federated Bot options, handle its request
        if ($this->session->get('federatedSubanswers') && count($digestedRequest) && isset($digestedRequest[0]['message'])) {
            $selectedAnswer = $digestedRequest[0]['message'];
            $federatedSubanswers = $this->session->get('federatedSubanswers');
            $this->session->delete('federatedSubanswers');
            foreach ($federatedSubanswers as $key => $answer) {
                if ($selectedAnswer === $answer->attributes->title || ((int) $selectedAnswer - 1) == $key) {
                    $this->displayFederatedBotAnswer($answer);
                    die();
                }
            }
        }
    }

    /**
     * 	Ask the user if wants to talk with a human and handle the answer
     * @param array $userAnswer = null
     */
    protected function handleRating($userAnswer = null)
    {
        // Ask the user if wants to escalate
        // Handle user response to an rating question
        $this->session->set('askingRating', false);
        $ratingOptions = $this->conf->get('conversation.content_ratings.ratings');
        $ratingCode = $this->session->get('rateCode', false);
        $event = null;

        if (count($userAnswer) && isset($userAnswer[0]['message']) && $ratingCode) {
            foreach ($ratingOptions as $index => $option) {
                if ($index + 1 == (int) $userAnswer[0]['message'] || Helper::removeAccentsToLower($userAnswer[0]['message']) === Helper::removeAccentsToLower($this->lang->translate($option['label']))) {
                    $event = $this->formatRatingEvent($ratingCode, $option['id']);
                    if (isset($option["comment"]) && $option["comment"]) {
                        $this->session->set('askingRatingComment', $event);
                    }
                    break;
                }
            }
            if ($event) {
                // Rate if the answer was correct
                $this->sendMessagesToExternal($this->sendEventToBot($event));
                die;
            } else { //If no rating given, show a message and continue
                $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_rating_given')));
            }
        }
    }

    /**
     * Return formated rate event
     *
     * @param string $ratingCode
     * @param integer $ratingValue
     * @return array
     */
    private function formatRatingEvent($ratingCode, $ratingValue, $comment = '')
    {
        return [
            'type' => 'rate',
            'data' => [
                'code' => $ratingCode,
                'value' => $ratingValue,
                'comment' => $comment
            ]
        ];
    }

    /**
     * Validate if the message has the close command (/close)
     */
    private function isCloseChatCommand($userMessage)
    {
        if (isset($userMessage[0]) && isset($userMessage[0]['message'])) {
            return $userMessage[0]['message'] === $this->lang->translate('close_chat_key_word');
        }
        return false;
    }

    /**
     * Overwritten
     * Direct call to sys-welcome message to force escalation
     *
     * @param [type] $externalRequest
     * @return void
     */
    public function handleBotActions($externalRequest)
    {
        $needEscalation = false;
        $needContentRating = false;
        $hasFormData = false;

        foreach ($externalRequest as $message) {
            // if the session just started throw sys-welcome message
            if ($this->isOnlyChat()) {
                if ($this->checkAgents()) {
                    $this->escalateToAgent();
                } else {
                    // throw no agents message
                    $this->sendMessagesToExternal($this->buildTextMessage($this->lang->translate('no_agents')));
                    $this->session->clear();
                    return false;
                }
            }
            // Check if is needed to execute any preset 'command'
            $this->handleCommands($message);
            // Store the last user text message to session
            $this->saveLastTextMessage($message);
            // Check if is needed to ask for a rating comment
            $message = $this->checkContentRatingsComment($message);
            // Send the messages received from the external service to the ChatbotAPI
            $botResponse = $this->sendMessageToBot($message);
            // Check if escalation to agent is needed
            $needEscalation = $this->checkEscalation($botResponse) ? true : $needEscalation;
            if ($needEscalation) {
                $this->deleteLastMessage($botResponse);
            }
            // Check if it has attached an escalation form
            $hasFormData = $this->checkEscalationForm($botResponse);

            // Check if is needed to display content ratings
            $hasRating = $this->checkContentRatings($botResponse);
            $needContentRating = $hasRating ? $hasRating : $needContentRating;

            // Send the messages received from ChatbotApi back to the external service
            $this->sendMessagesToExternal($botResponse);
        }

        //FORCE START MENU
        if($this->session->get('conversationStarted') === TRUE){
    			$this->session->set('conversationStarted', FALSE);

    			//FIXME enviar a archivo de configuracion
    			$showWelcomeMenu = 'Quiero más información de ContinuaPro';

    			$startMessage = ['message' => $showWelcomeMenu];

    			$botResponse = $this->sendMessageToBot($startMessage);

    			$this->sendMessagesToExternal($botResponse);
    		}

        if ($needEscalation || $hasFormData) {
            $this->handleEscalation();
        }
        // Display content rating if needed and not in chat nor asking to: escalate, related content, options, etc
        if ($needContentRating && !$this->chatOnGoing() && !$this->session->get('askingForEscalation', false) && !$this->session->get('hasRelatedContent', false) && !$this->session->get('options', false)) {
            $this->displayContentRatings($needContentRating);
        }
    }
}
